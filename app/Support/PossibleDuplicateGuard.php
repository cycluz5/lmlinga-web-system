<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Validator;

/**
 * Shared save-time handling of possible-duplicate matches (members and Spot Mapping heads).
 *
 * Matches come from App\Services\DuplicateFinder. Without staff confirmation the validator gets a
 * `possible_duplicate` error; offline replays (marked server-side by OfflineInnerRequestValidator) are never blocked.
 */
final class PossibleDuplicateGuard
{
    public const ERROR_KEY = 'possible_duplicate';

    public const CONFIRM_FIELD = 'confirm_possible_duplicate';

    /** Request attribute set by OfflineInnerRequestValidator on the inner request of an /offline/sync replay. */
    public const REPLAY_ATTRIBUTE = 'offline.replay';

    public const FLASH_KEY = 'possible_duplicate_matches';

    public const MEMBER_MESSAGE = 'Possible duplicate: a record with the same name and birthday already exists. Confirm it is a different person to continue.';

    /**
     * True when the identity fields already failed their own rules (then the duplicate check is skipped).
     */
    public static function identityHasErrors(Validator $validator): bool
    {
        $errors = $validator->errors();

        return $errors->has('first_name') || $errors->has('last_name') || $errors->has('birthday');
    }

    /**
     * @param  'member'|'head'  $kind
     * @param  list<array{name: string, relation: string, household_no: string, url: string|null}>  $matches
     */
    public static function enforce(Validator $validator, Request $request, string $kind, array $matches, ?string $householdNo = null): void
    {
        if ($matches === []) {
            return;
        }

        if ($request->attributes->get(self::REPLAY_ATTRIBUTE) === true) {
            Log::warning('possible duplicate bypassed for offline replay', [
                'kind' => $kind,
                'household_no' => $householdNo,
                'match_household_nos' => array_values(array_unique(array_column($matches, 'household_no'))),
            ]);

            return;
        }

        if ((string) $request->input(self::CONFIRM_FIELD) === '1') {
            return;
        }

        $message = $kind === 'head'
            ? 'Possible duplicate: this head is already registered in household '.($matches[0]['household_no'] ?? '').'. Confirm it is a different household to continue.'
            : self::MEMBER_MESSAGE;

        $validator->errors()->add(self::ERROR_KEY, $message);

        // Server-derived matches for the re-displayed form (never taken from user input).
        if (! $request->expectsJson() && $request->hasSession()) {
            $request->session()->flash(self::FLASH_KEY, ['kind' => $kind, 'items' => $matches]);
        }
    }
}
