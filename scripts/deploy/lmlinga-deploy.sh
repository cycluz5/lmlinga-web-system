#!/bin/bash
# LMLinga production deploy for the EC2 setup in docs/DEPLOYMENT_AWS_EC2.md.
#
# Installed on the server as /opt/lmlinga-deploy.sh (owner root, mode 700) and started by
# GitHub Actions over SSH after a merge to main (see docs/DEPLOYMENT_CICD.md):
#
#   sudo /opt/lmlinga-deploy.sh <40-character commit SHA on origin/main>
#
# Steps (same as "Deploying updates" in the EC2 guide):
#   backup -> maintenance mode -> fast-forward to the commit -> composer/npm install + build
#   -> run each NEW migration file one at a time with --path -> rebuild caches
#   -> reload PHP-FPM -> back online.
#
# If anything fails before migrations start, the code is rolled back to the previous commit
# and the site comes back online. If a migration or a later step fails, the site stays in
# maintenance mode so the database can be checked by hand.
set -Eeuo pipefail

APP_DIR=/var/www/lmlinga
APP_USER=lmlinga
BRANCH=main
BACKUP_SCRIPT=/opt/lmlinga-backup.sh
LOCK_FILE=/var/lock/lmlinga-deploy.lock
PHP_FPM_SERVICE=php-fpm

TARGET="${1:-}"
OLD=""
STAGE="prepare"

log() { printf '\n==> %s\n' "$*"; }
die() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

# Run a command as the app user inside the app directory (never as root; see the EC2 guide).
as_app() { sudo -u "$APP_USER" -H bash -lc "cd '$APP_DIR' && $1"; }

[[ $EUID -eq 0 ]] || die "Run as root: sudo /opt/lmlinga-deploy.sh <commit-sha>"
[[ "$TARGET" =~ ^[0-9a-f]{40}$ ]] || die "Expected a 40-character commit SHA, got: '${TARGET}'"
[[ -d "$APP_DIR/.git" ]] || die "$APP_DIR is not a git checkout."

exec 9>"$LOCK_FILE"
flock -n 9 || die "Another deploy is already running."

rebuild_app() {
    as_app "composer install --no-dev --optimize-autoloader --no-interaction --no-progress"
    as_app "npm ci --no-audit --no-fund"
    as_app "npm run build"
}

cache_and_reload() {
    as_app "php artisan config:cache && php artisan route:cache && php artisan view:cache"
    systemctl reload "$PHP_FPM_SERVICE"
}

on_error() {
    local code=$?
    trap - ERR
    set +e

    if [[ "$STAGE" == "code" && -n "$OLD" ]]; then
        printf '\nDeploy failed while updating code. Rolling back to %s...\n' "$OLD" >&2
        as_app "git reset --hard --quiet $OLD"
        rebuild_app
        as_app "php artisan config:clear"
        cache_and_reload
        as_app "php artisan up"
        printf 'Rolled back to %s. The site is back online on the previous version.\n' "$OLD" >&2
    elif [[ "$STAGE" == "migrate" || "$STAGE" == "finish" ]]; then
        printf '\nDeploy failed during the "%s" step. The site is LEFT IN MAINTENANCE MODE.\n' "$STAGE" >&2
        printf 'Check the database and the Laravel log, fix the problem, then run as %s:\n' "$APP_USER" >&2
        printf '  cd %s && php artisan config:cache && php artisan up && sudo systemctl reload %s\n' "$APP_DIR" "$PHP_FPM_SERVICE" >&2
    fi

    exit "$code"
}
trap on_error ERR

log "Fetching origin/$BRANCH"
as_app "git fetch --quiet origin $BRANCH"
as_app "git merge-base --is-ancestor $TARGET origin/$BRANCH" \
    || die "Commit $TARGET is not on origin/$BRANCH. Only commits merged to $BRANCH are deployed."

OLD="$(as_app "git rev-parse HEAD")"
if [[ "$OLD" == "$TARGET" ]]; then
    log "Already at $TARGET. Nothing to deploy."
    exit 0
fi

as_app "git diff --quiet && git diff --cached --quiet" \
    || die "The server checkout has local changes. Commit or discard them in $APP_DIR first."
as_app "git merge-base --is-ancestor $OLD $TARGET" \
    || die "$TARGET is not ahead of the deployed commit $OLD (not a fast-forward). Deploy it by hand."

# Migration files ADDED between the deployed commit and the target, oldest first.
# Captured first so a failing git diff stops the deploy instead of looking like "no migrations".
MIGRATION_LIST="$(as_app "git diff --name-only --diff-filter=A $OLD $TARGET -- database/migrations")"
mapfile -t MIGRATIONS < <(printf '%s\n' "$MIGRATION_LIST" | sed '/^$/d' | sort)
for migration in "${MIGRATIONS[@]}"; do
    [[ "$migration" =~ ^database/migrations/[A-Za-z0-9_.-]+\.php$ ]] || die "Unexpected migration path: $migration"
done

log "Deploying $OLD -> $TARGET"
if [[ -x "$BACKUP_SCRIPT" ]]; then
    log "Backing up (database + storage)"
    "$BACKUP_SCRIPT"
else
    log "Backup script $BACKUP_SCRIPT not found; continuing WITHOUT a backup."
fi

log "Maintenance mode on"
as_app "php artisan down --retry=60"

STAGE="code"
log "Updating code"
as_app "git merge --ff-only --quiet $TARGET"
rebuild_app
# Migrations must run with a fresh (uncached) config; see the EC2 guide.
as_app "php artisan config:clear"

STAGE="migrate"
if [[ ${#MIGRATIONS[@]} -eq 0 ]]; then
    log "No new migrations"
else
    for migration in "${MIGRATIONS[@]}"; do
        log "Migrating $migration"
        as_app "php artisan migrate --path=$migration --force"
    done
fi

STAGE="finish"
log "Rebuilding caches and reloading PHP-FPM"
cache_and_reload

log "Maintenance mode off"
as_app "php artisan up"

STAGE="done"
log "Deployed $TARGET"
