#!/usr/bin/env bash
#
# Deploys one commit of main to production. Installed on the server as
# /opt/lmlinga-deploy.sh and run by the GitHub Actions runner as the lmlinga user.
#
# Migrations: only files ADDED since the deployed commit are run, one at a time
# with --path. Never replace this with a plain `php artisan migrate`: most
# migration files are not recorded in this database, and running them would
# create legacy tables and switch modules out of ERD mode.

set -Eeuo pipefail

APP_DIR=/var/www/lmlinga
TARGET_SHA="${1:?usage: deploy-production.sh <commit-sha>}"

export PATH=/usr/local/bin:/usr/bin:/bin
export NODE_OPTIONS=--max-old-space-size=1536
export COMPOSER_NO_INTERACTION=1

exec 9>"$HOME/.lmlinga-deploy.lock"
flock -n 9 || { echo "Another deployment is running."; exit 1; }

cd "$APP_DIR"

git fetch --quiet origin main
git cat-file -e "${TARGET_SHA}^{commit}"

PREVIOUS_SHA="$(git rev-parse HEAD)"

if [ "$PREVIOUS_SHA" = "$TARGET_SHA" ]; then
    echo "Commit $TARGET_SHA is already deployed."
    exit 0
fi

if git merge-base --is-ancestor "$TARGET_SHA" "$PREVIOUS_SHA"; then
    echo "Commit $TARGET_SHA is older than the deployed commit $PREVIOUS_SHA. Skipping."
    exit 0
fi

mapfile -t NEW_MIGRATIONS < <(
    git diff --name-only --diff-filter=A "$PREVIOUS_SHA" "$TARGET_SHA" -- database/migrations | sort
)

build_release() {
    composer install --no-dev --optimize-autoloader --no-progress --quiet </dev/null
    npm ci --no-audit --no-fund --silent </dev/null
    npm run build --silent </dev/null
}

cache_release() {
    php artisan config:cache </dev/null
    php artisan route:cache </dev/null
    php artisan view:cache </dev/null
}

restart_php() {
    sudo -n /usr/bin/systemctl reload php-fpm
}

STAGE=starting

on_failure() {
    local status=$?
    trap - ERR

    case "$STAGE" in
        release)
            echo "::error::Deployment failed before the database changed. Restoring $PREVIOUS_SHA."
            git reset --hard --quiet "$PREVIOUS_SHA"
            build_release
            cache_release
            php artisan up </dev/null
            restart_php
            ;;
        migrating)
            echo "::error::A migration failed. The site stays in maintenance mode because the database may be partly changed."
            echo "::error::Fix it on the server, or restore the backup in /var/backups/lmlinga, then run: php artisan up"
            ;;
        *)
            echo "::error::Deployment failed at stage: $STAGE"
            ;;
    esac

    exit "$status"
}

trap on_failure ERR

echo "Deploying $PREVIOUS_SHA -> $TARGET_SHA"
echo "New migrations: ${#NEW_MIGRATIONS[@]}"

if [ "${#NEW_MIGRATIONS[@]}" -gt 0 ]; then
    STAGE=backup
    sudo -n /opt/lmlinga-backup.sh
fi

php artisan down --retry=15 </dev/null

STAGE=release
git reset --hard --quiet "$TARGET_SHA"
build_release

# The config cache must be cleared first: migrations read SUPER_ADMIN_* from .env.
php artisan config:clear </dev/null

STAGE=migrating
for migration in "${NEW_MIGRATIONS[@]}"; do
    echo "Running $migration"
    php artisan migrate --path="$migration" --force </dev/null
done

STAGE=activating
cache_release
php artisan up </dev/null
restart_php

STAGE=verifying
for attempt in 1 2 3 4 5 6; do
    if curl -fsS -o /dev/null -m 10 --resolve lmlinga.com:443:127.0.0.1 https://lmlinga.com/up; then
        echo "Deployed $TARGET_SHA"
        exit 0
    fi
    sleep 5
done

echo "::error::The site did not pass its health check after deploying $TARGET_SHA."
exit 1
