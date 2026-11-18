# Project instructions

## Running tests
- Run only the tests that cover the code you changed. Do NOT run the whole test suite (`php artisan test` with no filter, or all of `tests/js`).
- PHP: target the relevant file or test, for example
  `php artisan test tests/Feature/SomeFeatureTest.php` or `php artisan test --filter=SomeTestName`.
- JS: run only the affected file, for example `node --test tests/js/name-suggest.test.mjs`.
- Do not run a "baseline" full-suite pass before starting work.
- If a change touches shared code and the related tests are unclear, ask the user before widening the run.

## Worktrees
- Create worktrees with `bash scripts/new-worktree.sh <branch> [base-ref] [target-dir]`. It uses a long-path-safe checkout, copies `.env` (and anything else listed in `.worktreeinclude`) from the main checkout, then runs `composer install`, `npm ci` and `npm run build`.
- `SKIP_INSTALL=1` skips the installs; `NO_BUILD=1` skips the build.
