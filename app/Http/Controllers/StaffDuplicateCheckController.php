<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DuplicateFinder;
use App\Services\StaffNameSuggestionService;
use App\Support\HouseholdMemberResolver;
use App\Support\HouseholdZoneResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /staff/duplicate-check (JSON, staff only). Live possible-duplicate lookup for member and head forms.
 * Payload is the DuplicateFinder contract items only; the save-time check still applies.
 */
class StaffDuplicateCheckController extends Controller
{
    private const MIN_NAME_LENGTH = 2;

    public function __invoke(Request $request, DuplicateFinder $finder, HouseholdMemberResolver $resolver): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! StaffNameSuggestionService::mayUse($actor)) {
            return response()->json(['message' => 'Not available.'], 403);
        }

        $kind = $this->text($request, 'kind');
        if (! in_array($kind, ['member', 'head'], true)) {
            return response()->json(['message' => 'Invalid kind.'], 422);
        }

        $first = $this->text($request, 'first_name');
        $last = $this->text($request, 'last_name');
        $birthday = $this->text($request, 'birthday');

        $items = [];
        if (mb_strlen($first) >= self::MIN_NAME_LENGTH
            && mb_strlen($last) >= self::MIN_NAME_LENGTH
            && $this->validDate($birthday)) {
            $items = $kind === 'head'
                ? $finder->forHead($actor, $first, $last, $birthday)
                : $this->forMember($request, $actor, $finder, $resolver, $first, $last, $birthday);
        }

        return response()
            ->json(['items' => array_slice(array_values($items), 0, 3)])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return list<array{name: string, relation: string, household_no: string, url: string|null}>
     */
    private function forMember(Request $request, User $actor, DuplicateFinder $finder, HouseholdMemberResolver $resolver, string $first, string $last, string $birthday): array
    {
        $householdNo = $this->text($request, 'household_no');
        if ($householdNo === '') {
            return [];
        }

        $excludeMember = $this->text($request, 'exclude_member');

        try {
            if ($excludeMember !== '') {
                $ctx = $resolver->resolveDbMemberOrFail($householdNo, $excludeMember);
                $household = $ctx['household'];
                $excludeId = (int) $ctx['resident']->getKey();
            } else {
                $household = $resolver->resolveDbHouseholdOrFail($householdNo);
                $excludeId = null;
            }
        } catch (ModelNotFoundException) {
            return [];
        }

        if (! $this->mayAccessHousehold($actor, $household)) {
            return [];
        }

        return $finder->forMember($household, $first, $last, $birthday, $excludeId);
    }

    /**
     * Admin sees every household; other staff only households in their assigned zones.
     */
    private function mayAccessHousehold(User $actor, \App\Models\Household $household): bool
    {
        $zones = app(StaffNameSuggestionService::class)->zonesFor($actor);
        if ($zones === ['*']) {
            return true;
        }
        if ($zones === []) {
            return false;
        }

        return $household->newQuery()
            ->whereKey($household->getKey())
            ->where(function ($query) use ($zones): void {
                foreach ($zones as $zone) {
                    $query->orWhere(static fn ($z) => HouseholdZoneResolver::applyZoneLabelScope($z, $zone));
                }
            })
            ->exists();
    }

    private function text(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? trim($value) : '';
    }

    private function validDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y);
    }
}
