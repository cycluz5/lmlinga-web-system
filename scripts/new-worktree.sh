#!/usr/bin/env bash
# Create a ready-to-run git worktree for this project.
#
# Usage: bash scripts/new-worktree.sh <branch> [base-ref] [target-dir]
#   branch      new branch to create, e.g. feature/my-change
#   base-ref    what to branch from (default: main)
#   target-dir  where to put the worktree (default: ../lmlinga-<branch-slug>, next to this repo)
#
# What it does (so the worktree runs and tests like the main checkout):
#   1. git worktree add with core.longpaths (the repo has long docs/ paths that break Windows checkouts)
#   2. copies the git-ignored environment files from the main checkout (.env, listed in .worktreeinclude)
#   3. composer install and npm ci
#   4. (optional) NO_BUILD=1 skips the production build; by default it runs `npm run build` once so
#      public/build exists (pages that use @vite fail without it).
#
# Set SKIP_INSTALL=1 to only create the worktree and copy the env files.
set -euo pipefail

branch=${1:-}
base=${2:-main}
if [ -z "$branch" ]; then
  echo "usage: bash scripts/new-worktree.sh <branch> [base-ref] [target-dir]" >&2
  exit 2
fi

# Main checkout = the directory that owns the shared .git, even when run from inside another worktree.
common_dir=$(cd "$(git rev-parse --git-common-dir)" && pwd -P)
main_root=$(cd "$common_dir/.." && pwd -P)

slug=$(printf '%s' "$branch" | tr '/ ' '--' | tr -cd 'A-Za-z0-9._-')
target=${3:-"$main_root/../lmlinga-$slug"}

if [ -e "$target" ]; then
  echo "target already exists: $target" >&2
  exit 1
fi

echo "==> Creating worktree $target on new branch $branch (from $base)"
git -c core.longpaths=true worktree add -b "$branch" "$target" "$base"
git -C "$target" config core.longpaths true
target=$(cd "$target" && pwd -P)

echo "==> Copying environment files from $main_root"
include_file="$target/.worktreeinclude"
[ -f "$include_file" ] || include_file="$main_root/.worktreeinclude"
copied=0
if [ -f "$include_file" ]; then
  while IFS= read -r line || [ -n "$line" ]; do
    line=${line%$'\r'}
    case "$line" in ''|'#'*) continue ;; esac
    if [ -f "$main_root/$line" ]; then
      mkdir -p "$(dirname "$target/$line")"
      cp "$main_root/$line" "$target/$line"
      echo "    copied $line"
      copied=$((copied + 1))
    else
      echo "    skipped $line (not found in main checkout)"
    fi
  done < "$include_file"
else
  echo "    no .worktreeinclude found; copying .env if present"
  if [ -f "$main_root/.env" ]; then
    cp "$main_root/.env" "$target/.env"
    copied=1
  fi
fi
[ "$copied" -gt 0 ] || echo "    WARNING: no env files copied (tests will need APP_KEY in the environment)"

if [ "${SKIP_INSTALL:-0}" != "1" ]; then
  echo "==> composer install"
  (cd "$target" && composer install --no-interaction --prefer-dist)
  echo "==> npm ci"
  (cd "$target" && npm ci)
  if [ "${NO_BUILD:-0}" != "1" ]; then
    echo "==> npm run build"
    (cd "$target" && npm run build)
  fi
fi

echo
echo "Worktree ready: $target (branch $branch)"
echo "Serve it on a free port, e.g.: cd \"$target\" && php artisan serve --host=0.0.0.0 --port=8002"
