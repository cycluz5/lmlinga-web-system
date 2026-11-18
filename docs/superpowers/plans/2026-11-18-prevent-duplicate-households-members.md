# Prevent duplicate households and members — implementation plan

**Spec:** the approved design "Prevent duplicate households and members when adding household information".

**Goal:** when staff add or edit a household member, or register a new household in Spot Mapping, show possible existing matches and make staff confirm before saving. Nothing is hard-blocked.

**User decisions (binding):**
- Behaviour on a match: warn, then let staff decide (explicit "this is a different person/household" confirmation).
- Member match = same first + last name (case, spacing, accents ignored) AND same birthday, within the **same household only**.
- Household duplicates are detected through the **head's identity** in Spot Mapping: a resident with the same name + birthday anywhere in the worker's zones (admin: all zones).
- Member edit is checked too, excluding the member being edited.
- Offline replays of queued creates are never blocked; they send header `X-Offline-Replay: 1`, the server skips enforcement and logs a warning.
- Out of scope: health-record duplicates, autofill of health data, non-resident forms.

## Global Constraints
- Work only in this worktree (`lmlinga-duplicates`, branch `feature/prevent-duplicate-households-members`, cut from `main`).
- **Tests: run only the tests for the code you changed** (see `CLAUDE.md`). Never the whole suite. PHP: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan test tests/Feature/<File>.php` (a copied `.env` exists, but the explicit key is harmless). JS: `node --test tests/js/<file>.test.mjs`.
- TDD: failing test first, then the code.
- **Commits: no AI attribution of any kind** (no `Co-Authored-By`, no "Generated with"). Plain imperative messages.
- Works with both `Resident` schemas (legacy `id`/`member_no` and ERD `resident_id`); use model attributes and `Schema::hasColumn` guards the way `app/Services/StaffNameSuggestionService.php` does. Soft-deleted rows are ignored.
- Known pre-existing failures to ignore: 7 in `tests/Feature/HouseholdRequestVerificationUiTest.php`, and any test failing only because `public/build/manifest.json` is missing (use `$this->withoutVite()` in new tests that render Vite pages).
- Existing guards must keep passing: `tests/Feature/NameSuggestFormsTest.php`, `StaffNameSuggestionTest.php` and the household-number duplicate tests.

## Shared contract (Tasks 1-4)
- **Match item** (everywhere): `{"name": "Last, First M.", "relation": "<string or ''>", "household_no": "<string>", "url": "<member page URL or null>"}`. Nothing else (no birthday, health or contact data).
- **Server field:** the request input `confirm_possible_duplicate` equal to `'1'` means staff confirmed. Without it, a found match adds the validation error under key `possible_duplicate` with message `Possible duplicate: a record with the same name and birthday already exists. Confirm it is a different person to continue.` (members) or `Possible duplicate: this head is already registered in household <HH>. Confirm it is a different household to continue.` (heads).
- **Offline header:** `X-Offline-Replay: 1` on replayed requests: no enforcement, `Log::warning('possible duplicate bypassed for offline replay', [...])` only.
- **Endpoint:** `GET /staff/duplicate-check`, route name `staff.duplicate-check`. Query: `kind` = `member` or `head`; `first_name`, `last_name`, `birthday` (Y-m-d); for `member` also `household_no` and optional `exclude_member` (member id/number being edited). Response `{"items": [<match item>...]}` (max 3), header `Cache-Control: no-store, private`. Staff roles only (`StaffNameSuggestionService::mayUse`, 403 otherwise), same throttle approach as `staff.name-suggestions` (`routes/web.php` ~L185-195).

### Task 1: DuplicateFinder service
Files: new `app/Services/DuplicateFinder.php`, new `tests/Feature/DuplicateFinderTest.php`.

- `forMember(Household $household, string $firstName, string $lastName, ?string $birthday, ?int $excludeResidentId = null): array` returns the contract match items for residents of that household with the same normalised first + last name and the same birthday (date equality). Returns `[]` when the birthday is null or empty (a match needs all three).
- `forHead(User $actor, string $firstName, string $lastName, ?string $birthday): array` same rule across households the actor may access: admin all; BHW/BNS/BSPO only households in their assigned zones (reuse `StaffNameSuggestionService::zonesFor()` and `HouseholdZoneResolver::applyZoneLabelScope`, see `StaffNameSuggestionService::residents()`); a worker with no zones gets `[]`. Exclude the non-resident sentinel household (`NonResidentFamilyPlanningService::SENTINEL_HOUSEHOLD_NO`) if the column exists.
- Name normalisation (a public static helper, e.g. `normalizeName()`): trim, collapse whitespace, lower-case (`mb_strtolower`), strip accents (e.g. `Normalizer`/`iconv`/`Str::ascii`), ignore middle name.
- Max 3 items, ordered by last name, first name. `relation` from the resident's relation attribute (use the model's alias); `name` formatted like `StaffNameSuggestionService::present()` ("Last, First M."); `url` is `route('household-profiling.members.show', ['householdNo' => ..., 'memberId' => member_no])`, `null` on failure (wrap like the existing service).
- Tests (helpers like `StaffNameSuggestionTest::resident()`; `actingAsStaff` from `tests/Support/AuthenticatesStaff.php`): same-household match; case/space/accent-insensitive; different birthday no match; different household no match for `forMember`; empty birthday no match; excludes the given resident id; soft-deleted ignored; `forHead` finds a match in another household for admin, only within zones for a BHW, nothing for a zone-less worker; sentinel household excluded; 3-item cap; payload has exactly the contract keys.
- Verify: `php artisan test tests/Feature/DuplicateFinderTest.php`.

### Task 2: Save-time enforcement and warning panel
Files: `app/Http/Requests/ValidatesHouseholdResidentMember.php` (`withMemberValidator`), `app/Http/Requests/StoreSpotMappingHouseholdRequest.php` (`withValidator`, ~L165), the member create/edit request classes if they do not share the trait, `resources/views/pages/household-profiling/partials/member-form-fields.blade.php`, `resources/views/pages/spot-mapping/index.blade.php` (head fields), new Blade partial e.g. `resources/views/components/lml/possible-duplicate-panel.blade.php`, tests in a new `tests/Feature/DuplicateEnforcementTest.php`.

- Members: when creating a member, or editing one (exclude that member), run `DuplicateFinder::forMember` for the route's household with the submitted first name, last name and birthday. If there is a match, no `confirm_possible_duplicate=1` and no `X-Offline-Replay: 1` header: add the `possible_duplicate` error (contract message). Run this check only after the normal field rules pass (do not stack on top of other errors if the name or birthday are invalid).
- Spot Mapping: the same, using `forHead` for the head fields it collects (see its `rules()`), with the head message and household number of the first match.
- Offline replay header: skip enforcement and `Log::warning(...)` (contract).
- Views: when `$errors->has('possible_duplicate')`, render a warning panel (new partial, reusing existing form-error styling; role="alert") with the message and a required-looking checkbox named `confirm_possible_duplicate` value `1` labelled "This is a different person" (member) / "This is a different household" (head). Matches (name, household, link) are re-derived server-side in the partial from the same finder call (pass them to the view via the validator's error bag or by recomputing in the view composer; pick the simplest that keeps the controller thin).
- The household-number duplicate rule and message are unchanged.
- Tests: duplicate without confirm is rejected with `possible_duplicate` (assertSessionHasErrors) and nothing is persisted; with `confirm_possible_duplicate=1` it saves; offline header bypasses enforcement and saves (assert the log call with `Log::spy()` or `Log::shouldReceive`); non-duplicate saves untouched; edit excludes self but flags a change that collides with a sibling; Spot Mapping head match in another household blocks unless confirmed; BHW outside the zone is not flagged; the panel renders the confirm checkbox on the re-displayed form.
- Verify: `php artisan test tests/Feature/DuplicateEnforcementTest.php` plus any existing test file for the member/spot-mapping create requests that you touched.

### Task 3: Staff duplicate-check endpoint
Files: new `app/Http/Controllers/StaffDuplicateCheckController.php`, `routes/web.php` (staff group, beside `staff.name-suggestions`), new `tests/Feature/StaffDuplicateCheckTest.php`.

- Implements the contract endpoint using `DuplicateFinder`. Input validation: unknown `kind` returns 422; missing or short fields return `{"items": []}` (not an error). `member` requires an existing household in the staff member's reach: resolve it the way existing household controllers do (`HouseholdMemberResolver`); an unknown household returns `{"items": []}`.
- Same access as `staff.name-suggestions`: `StaffNameSuggestionService::mayUse` else 403 JSON; same throttle style (look at the `lmlinga-name-suggest` limiter registration and add an equivalent `lmlinga-duplicate-check` one, or reuse it if that is how the existing code is structured). `Cache-Control: no-store, private`.
- Tests: guest refused, resident chatbot session refused, linguist refused; admin and BHW happy path (exact keys, no birthday in payload); BHW out-of-zone head not returned; 3-item cap; throttle; `no-store` header; 422 for bad kind.
- Verify: `php artisan test tests/Feature/StaffDuplicateCheckTest.php`.

### Task 4: JS live check, form wiring and replay header
Files: new `resources/js/components/duplicate-check.js`, `resources/js/app.js` (import), `resources/js/offline/offline-replay.js` (add header at the two request builders, ~L225 and ~L265), `member-form-fields.blade.php` and `spot-mapping/index.blade.php` (data attributes), CSS beside the name-suggest styles (find with Grep `lml-name-suggest`), new `tests/js/duplicate-check.test.mjs`, and a header assertion in the existing offline-replay test file if one exists (Grep `tests/js` for `offline-replay`).

- Opt-in attributes on the form root or inputs: `data-lml-duplicate-check="member|head"`, `data-lml-duplicate-check-url="{{ route('staff.duplicate-check') }}"`, and field names via `data-lml-dup-first`, `data-lml-dup-last`, `data-lml-dup-birthday` (or equivalent). Member forms also pass the household number and, on edit, `exclude_member`.
- Behaviour: after first name, last name and birthday are all filled, debounce ~300 ms and fetch (credentials same-origin, `Accept: application/json`, abort stale requests). If items come back, show a panel (role="status"/"alert", reusing the panel styling of Task 2's partial) listing each match with a link (same-origin only, `rel="noopener"`, new tab) and a checkbox `confirm_possible_duplicate` value `1` ("This is a different person/household"). While a panel with matches is visible, block form submit until the checkbox is ticked (show the message again if the user tries). Clear the panel when fields change. Fail soft when offline (`navigator.onLine === false`), on non-OK responses or errors: no panel, the server check still applies. Never mutate or send the confirm checkbox when no match is shown.
- Pure exported helpers with tests (follow `tests/js/name-suggest.test.mjs` style, fake DOM): `buildDuplicateQuery(values)`, `shouldCheck(values)`, `canSubmit(state)`, `safeMatchUrl(url, origin)`; plus tests for the render/confirm flow and fail-soft paths.
- Do not touch the offline donor shells (`offline-hp-hydrate.js`); the panel is created by JS only. The replay header `X-Offline-Replay: 1` goes on both replay request builders.
- After the JS tests pass run `npm run build` once as a compile check (output is git-ignored; do not commit it).
- Verify: `node --test tests/js/duplicate-check.test.mjs` (+ the replay test file if you changed it), and `APP_KEY=... php artisan test tests/Feature/NameSuggestFormsTest.php` since the member form partial changed.
