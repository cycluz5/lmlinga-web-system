<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Resident;
use App\Support\Offline\OfflineLocalMemberId;

/**
 * DB-05 Phase 3 — presentation-compatible member identity for health destinations.
 *
 * Database-only via HouseholdMemberResolver. Missing identities return source null.
 * Never materializes DemoCatalog rows onto staff production routes.
 * Returns the frozen Blade shape (demoHousehold / demoMember arrays) plus optional models.
 */
final class HealthMemberIdentity
{
    public function __construct(
        private readonly HouseholdMemberResolver $resolver,
    ) {}

    /**
     * @return array{
     *     source: 'db'|'local'|null,
     *     household: array<string, mixed>|null,
     *     member: array<string, mixed>|null,
     *     householdNo: string,
     *     memberId: string,
     *     resident: Resident|null,
     *     householdModel: Household|null
     * }
     */
    public function resolve(string $householdNo, string $memberId): array
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = OfflineLocalMemberId::normalize($memberId);

        if (OfflineLocalMemberId::isLocal($mb)) {
            $householdResolved = $this->resolver->resolveHousehold($hh);

            return [
                'source' => $householdResolved === null ? null : 'local',
                'household' => $householdResolved['presentation'] ?? null,
                'member' => $householdResolved === null ? null : OfflineLocalMemberId::presentation($mb),
                'householdNo' => $hh,
                'memberId' => $mb,
                'resident' => null,
                'householdModel' => $householdResolved['household'] ?? null,
            ];
        }

        $resolved = $this->resolver->resolveMember($hh, $mb);
        if ($resolved === null) {
            return [
                'source' => null,
                'household' => null,
                'member' => null,
                'householdNo' => $hh,
                'memberId' => $mb,
                'resident' => null,
                'householdModel' => null,
            ];
        }

        return [
            'source' => $resolved['source'],
            'household' => $resolved['householdPresentation'],
            'member' => $resolved['memberPresentation'],
            'householdNo' => $hh,
            'memberId' => $mb,
            'resident' => $resolved['resident'],
            'householdModel' => $resolved['household'],
        ];
    }

    /**
     * Canonical resident-based resolution for the residents/{residentId} destinations.
     * Database-only — the residentId is the resident's actual primary key, never a
     * synthetic MB-xxx identifier. Local/queued (non-persisted) members have no
     * resident row and are out of scope here; they stay on the members/{memberId} route.
     *
     * @return array{
     *     source: 'db'|null,
     *     household: array<string, mixed>|null,
     *     member: array<string, mixed>|null,
     *     householdNo: string,
     *     memberId: string,
     *     resident: Resident|null,
     *     householdModel: Household|null
     * }
     */
    public function resolveByResidentId(string $householdNo, int $residentId): array
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $resolved = $this->resolver->resolveResidentById($hh, $residentId);

        if ($resolved === null) {
            return [
                'source' => null,
                'household' => null,
                'member' => null,
                'householdNo' => $hh,
                'memberId' => (string) $residentId,
                'resident' => null,
                'householdModel' => null,
            ];
        }

        return [
            'source' => $resolved['source'],
            'household' => $resolved['householdPresentation'],
            'member' => $resolved['memberPresentation'],
            'householdNo' => $hh,
            'memberId' => (string) $resolved['resident']->getKey(),
            'resident' => $resolved['resident'],
            'householdModel' => $resolved['household'],
        ];
    }

    /**
     * Fail-closed resident-based identity for writes via residents/{residentId}.
     *
     * @return array{
     *     source: 'db',
     *     household: array<string, mixed>,
     *     member: array<string, mixed>,
     *     householdNo: string,
     *     memberId: string,
     *     resident: Resident,
     *     householdModel: Household
     * }
     */
    public function resolveResidentPersistedOrFail(string $householdNo, int $residentId): array
    {
        $ctx = $this->resolveByResidentId($householdNo, $residentId);

        if ($ctx['source'] !== 'db'
            || $ctx['resident'] === null
            || $ctx['householdModel'] === null
            || $ctx['household'] === null
            || $ctx['member'] === null) {
            abort(404, 'Resident was not found.');
        }

        return [
            'source' => 'db',
            'household' => $ctx['household'],
            'member' => $ctx['member'],
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
            'resident' => $ctx['resident'],
            'householdModel' => $ctx['householdModel'],
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public static function persistenceSource(array $ctx): string
    {
        return match ($ctx['source'] ?? null) {
            'db' => 'db',
            'local' => 'local',
            default => 'preview',
        };
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public static function canQueueWrites(array $ctx): bool
    {
        if (($ctx['source'] ?? null) === 'local') {
            return $ctx['member'] !== null && $ctx['household'] !== null;
        }

        return ($ctx['source'] ?? null) === 'db' && ($ctx['resident'] ?? null) !== null;
    }

    /**
     * Fail-closed identity for DB death writes. Never returns demo-only members.
     *
     * @return array{
     *     source: 'db',
     *     household: array<string, mixed>,
     *     member: array<string, mixed>,
     *     householdNo: string,
     *     memberId: string,
     *     resident: Resident,
     *     householdModel: Household
     * }
     */
    public function resolvePersistedOrFail(string $householdNo, string $memberId): array
    {
        $ctx = $this->resolve($householdNo, $memberId);

        if ($ctx['source'] !== 'db'
            || $ctx['resident'] === null
            || $ctx['householdModel'] === null
            || $ctx['household'] === null
            || $ctx['member'] === null) {
            abort(404, 'Resident was not found.');
        }

        return [
            'source' => 'db',
            'household' => $ctx['household'],
            'member' => $ctx['member'],
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
            'resident' => $ctx['resident'],
            'householdModel' => $ctx['householdModel'],
        ];
    }
}
