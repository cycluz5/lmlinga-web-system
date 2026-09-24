<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Resident;
use Illuminate\Support\Facades\Schema;

/**
 * Staff production household/member resolution is database-only.
 * Missing rows return null (controllers render not-found / 404).
 * DemoCatalog is never substituted for a missing persisted household or member.
 * Member resolution is always household-scoped.
 */
final class HouseholdMemberResolver
{
    public function __construct(
        private readonly DatabaseSchemaGuard $schemaGuard,
    ) {}

    /**
     * @return array{source: 'db', household: Household, presentation: array<string, mixed>}|null
     */
    public function resolveHousehold(string $householdNo): ?array
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);

        if (! $this->householdsTableReady()) {
            return null;
        }

        $household = $this->activeHouseholdQuery()
            ->where('household_no', $key)
            ->with(['residents' => fn ($q) => Resident::eagerLoadForProfiling($q)])
            ->first();

        if ($household === null) {
            return null;
        }

        return [
            'source' => 'db',
            'household' => $household,
            'presentation' => HouseholdProfilingPresenter::fromModel($household),
        ];
    }

    /**
     * Active DB household only (writes). No DemoCatalog materialization.
     */
    public function resolveDbHouseholdOrFail(string $householdNo): Household
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);

        return $this->activeHouseholdQuery()
            ->where('household_no', $key)
            ->firstOrFail();
    }

    /**
     * @return array{
     *     source: 'db',
     *     household: Household,
     *     resident: Resident,
     *     householdPresentation: array<string, mixed>,
     *     memberPresentation: array<string, mixed>
     * }|null
     */
    public function resolveMember(string $householdNo, string $memberId): ?array
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);

        $resolved = $this->resolveHousehold($hh);
        if ($resolved === null) {
            return null;
        }

        /** @var Household $household */
        $household = $resolved['household'];

        $resident = Resident::query()
            ->where('household_id', $household->id)
            ->when(
                ResidentMemberIdentity::hasMemberNoColumn(),
                fn ($query) => $query->where('member_no', $mb),
                function ($query) use ($mb): void {
                    $residentId = ResidentMemberIdentity::parseResidentIdFromMemberId($mb);
                    if ($residentId === null) {
                        $query->whereRaw('1 = 0');

                        return;
                    }

                    $query->where((new Resident)->getKeyName(), $residentId);
                }
            )
            ->first();

        if ($resident === null) {
            return null;
        }

        return [
            'source' => 'db',
            'household' => $household,
            'resident' => $resident,
            'householdPresentation' => $resolved['presentation'],
            'memberPresentation' => HouseholdProfilingPresenter::memberFromModel($resident),
        ];
    }

    /**
     * Resolve a resident directly by its primary key, scoped to the household.
     * Used by the canonical residents/{residentId} destinations — no member_no
     * or synthetic MB-xxx identifier involved.
     *
     * @return array{
     *     source: 'db',
     *     household: Household,
     *     resident: Resident,
     *     householdPresentation: array<string, mixed>,
     *     memberPresentation: array<string, mixed>
     * }|null
     */
    public function resolveResidentById(string $householdNo, int $residentId): ?array
    {
        $resolved = $this->resolveHousehold($householdNo);
        if ($resolved === null) {
            return null;
        }

        /** @var Household $household */
        $household = $resolved['household'];

        $resident = Resident::query()
            ->where('household_id', $household->id)
            ->where((new Resident)->getKeyName(), $residentId)
            ->first();

        if ($resident === null) {
            return null;
        }

        return [
            'source' => 'db',
            'household' => $household,
            'resident' => $resident,
            'householdPresentation' => $resolved['presentation'],
            'memberPresentation' => HouseholdProfilingPresenter::memberFromModel($resident),
        ];
    }

    /**
     * Active DB resident scoped to household (writes / edit).
     *
     * @return array{household: Household, resident: Resident}
     */
    public function resolveDbMemberOrFail(string $householdNo, string $memberId): array
    {
        $household = $this->resolveDbHouseholdOrFail($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);

        $resident = Resident::query()
            ->where('household_id', $household->id)
            ->when(
                ResidentMemberIdentity::hasMemberNoColumn(),
                fn ($query) => $query->where('member_no', $mb),
                function ($query) use ($mb): void {
                    $residentId = ResidentMemberIdentity::parseResidentIdFromMemberId($mb);
                    if ($residentId === null) {
                        $query->whereRaw('1 = 0');

                        return;
                    }

                    $query->where((new Resident)->getKeyName(), $residentId);
                }
            )
            ->firstOrFail();

        return [
            'household' => $household,
            'resident' => $resident,
        ];
    }

    private function householdsTableReady(): bool
    {
        // Missing households table → not found. Never fall back to DemoCatalog.
        return $this->schemaGuard->tableExists('households');
    }

    /**
     * Active operational households only. Archived rows (deleted_at) never
     * fall through to DemoCatalog.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Household>
     */
    private function activeHouseholdQuery()
    {
        $query = Household::query()->excludingNonResidentSentinel();

        if (Schema::hasColumn('households', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query;
    }
}
