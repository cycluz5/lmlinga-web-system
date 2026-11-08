<?php

namespace App\Services;

use App\Models\Household;
use App\Models\Resident;
use App\Support\DatabaseSchemaGuard;
use App\Support\DemoCatalog;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\HouseholdProfilingPresenter;
use App\Support\HouseholdZoneResolver;
use App\Support\OpaqueId;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * DB18-C/E — Spot Mapping MySQL read + coordinate update on existing households.
 *
 * New-household creation is delegated to HouseholdService::createWithHead
 * (Phase 2A shell adapter + Phase 2B resident adapter). This service does not
 * duplicate household/resident SQL.
 */
final class SpotMappingService
{
    public const GENERIC_PLOT_FAILURE = 'Unable to plot this household. Confirm the household number and try again.';

    public const ALREADY_PLOTTED_MESSAGE = 'This household is already plotted. Confirm replot to replace its coordinates.';

    /**
     * @return array{stats: array{total: int, plotted: int, pending: int}, markers: list<array<string, mixed>>}
     */
    public function indexPayload(): array
    {
        $stats = $this->stats();

        return [
            'stats' => $stats,
            'markers' => $this->mappedMarkers(),
            'pendingCandidates' => $this->pendingPlotCandidates(),
        ];
    }

    /**
     * @return array{total: int, plotted: int, pending: int}
     */
    public function stats(): array
    {
        $total = $this->operationalHouseholdQuery()->count();
        $plottedQuery = $this->operationalHouseholdQuery();
        $this->applyPlottedCoordinateScope($plottedQuery);
        $plotted = $plottedQuery->count();
        $pending = $total - $plotted;

        return [
            'total' => $total,
            'plotted' => $plotted,
            'pending' => $pending,
        ];
    }

    /**
     * Active households with both coordinates present.
     *
     * @return list<array<string, mixed>>
     */
    public function mappedMarkers(): array
    {
        $residentKey = (new Resident)->getKeyName();

        $markerQuery = $this->operationalHouseholdQuery();
        $this->applyPlottedCoordinateScope($markerQuery);

        /** @var Collection<int, Household> $households */
        $households = $markerQuery
            ->with(['residents' => fn ($q) => $q->orderBy($residentKey)])
            ->orderBy('household_no')
            ->get();

        return $households
            ->map(fn (Household $household): array => $this->markerFromHousehold($household))
            ->values()
            ->all();
    }

    /**
     * Resolve a household by business key. Returns null when not found.
     */
    public function findActiveByHouseholdNo(string $householdNo): ?Household
    {
        $normalized = DemoCatalog::normalizeHouseholdNo($householdNo);
        if ($normalized === '') {
            return null;
        }

        return $this->operationalHouseholdQuery()
            ->where('household_no', $normalized)
            ->first();
    }

    /**
     * Registered households that still need coordinates (Spot Mapping plot candidates).
     *
     * @return list<array{
     *     householdNo: string,
     *     houseHead: string,
     *     householdType: string,
     *     zone: int|null,
     *     zoneLabel: string,
     *     members: int
     * }>
     */
    public function pendingPlotCandidates(): array
    {
        $residentKey = (new Resident)->getKeyName();

        $query = $this->operationalHouseholdQuery();
        $this->applyUnplottedCoordinateScope($query);

        /** @var Collection<int, Household> $households */
        $households = $query
            ->with(['residents' => fn ($q) => $q->orderBy($residentKey)])
            ->orderBy('household_no')
            ->get();

        return $households
            ->map(fn (Household $household): array => $this->candidateFromHousehold($household))
            ->values()
            ->all();
    }

    /**
     * Update coordinates for an existing active household.
     * Returns null when the household cannot be mapped (missing).
     *
     * Already-plotted households require confirm_replot=true (future intentional replot).
     * Unplotted / (0,0) first plots remain allowed without the flag.
     *
     * @return array{household: Household, marker: array<string, mixed>, stats: array{total: int, plotted: int, pending: int}}|null
     */
    public function updateCoordinates(
        string $householdNo,
        float $latitude,
        float $longitude,
        bool $confirmReplot = false,
    ): ?array {
        $household = $this->findActiveByHouseholdNo($householdNo);
        if ($household === null) {
            return null;
        }

        $keyName = $household->getKeyName();
        $householdKey = $household->getKey();
        if ($householdKey === null) {
            return null;
        }

        if ($this->householdHasPlottedCoordinates($household) && ! $confirmReplot) {
            throw ValidationException::withMessages([
                'household_no' => self::ALREADY_PLOTTED_MESSAGE,
            ]);
        }

        $updated = Household::query()
            ->where($keyName, $householdKey)
            ->update([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return null;
        }

        $household->refresh();

        $residentKey = (new Resident)->getKeyName();
        $household->load(['residents' => fn ($q) => $q->orderBy($residentKey)]);

        return [
            'household' => $household,
            'marker' => $this->markerFromHousehold($household),
            'stats' => $this->stats(),
        ];
    }

    /**
     * Same semantic as applyPlottedCoordinateScope for a loaded row:
     * both coordinates present and not both equal to 0.
     */
    public function householdHasPlottedCoordinates(Household $household): bool
    {
        $latitude = $household->latitude;
        $longitude = $household->longitude;

        if ($latitude === null || $longitude === null) {
            return false;
        }

        return ! (((float) $latitude === 0.0) && ((float) $longitude === 0.0));
    }

    /**
     * Public marker payload for a persisted household (create or plot response).
     *
     * houseHead is derived from resident first/middle/last after persistence.
     * It is presentation-only — never a write source.
     *
     * @return array<string, mixed>
     */
    public function presentMarker(Household $household): array
    {
        return $this->markerFromHousehold($household);
    }

    /**
     * @return array<string, mixed>
     */
    private function markerFromHousehold(Household $household): array
    {
        $residentKey = (new Resident)->getKeyName();
        $household->loadMissing(['residents' => fn ($q) => $q->orderBy($residentKey)]);
        $residents = $household->residents;
        $head = $this->resolveHouseholdHead($residents);

        $householdNo = (string) $household->household_no;
        $zoneFields = $this->resolveMarkerZoneFields($household);
        $headFirst = $head !== null ? trim((string) $head->first_name) : '';
        $headMiddle = $head !== null ? trim((string) ($head->middle_name ?? '')) : '';
        $headLast = $head !== null ? trim((string) $head->last_name) : '';
        $composedHead = $head !== null ? HouseholdProfilingPresenter::fullName($head) : '';

        return [
            'id' => 'hh-'.$householdNo,
            'householdNo' => $householdNo,
            'householdKey' => OpaqueId::forUrl('h', $householdNo),
            'houseHead' => $composedHead !== '' ? $composedHead : '—',
            'headFirstName' => $headFirst,
            'headMiddleName' => $headMiddle,
            'headLastName' => $headLast,
            'householdType' => $this->persistedHouseholdType($household),
            'zone' => $zoneFields['zone'],
            'zoneLabel' => $zoneFields['zoneLabel'],
            'members' => $residents->count(),
            'lat' => (float) $household->latitude,
            'lng' => (float) $household->longitude,
            'status' => 'plotted',
        ];
    }

    /**
     * Same head identification Household Profiling uses (`relation` accessor === Head),
     * plus Resident::isHouseholdHead() for ERD relation_to_household_head / flag.
     *
     * @param  \Illuminate\Support\Collection<int, Resident>  $residents
     */
    private function resolveHouseholdHead(Collection $residents): ?Resident
    {
        return $residents->first(
            static fn (Resident $r): bool => $r->isHouseholdHead()
        ) ?? $residents->first(
            static fn (Resident $r): bool => strcasecmp((string) $r->relation, 'Head') === 0
        );
    }

    /**
     * Canonical persisted type: NHTS / Non-NHTS. Empty when none is stored.
     */
    private function persistedHouseholdType(Household $household): string
    {
        $raw = trim((string) ($household->getAttributes()['household_type'] ?? ''));
        $canonical = DemoHouseholdWaterSupply::normalizeCanonicalHouseholdType($raw);
        if ($canonical !== null) {
            return $canonical;
        }

        $guard = app(DatabaseSchemaGuard::class);
        if (! $guard->householdTypeUsesEnvironmentalProfile()) {
            return '';
        }

        $household->loadMissing('environmentalProfile');
        $profileType = trim((string) ($household->environmentalProfile?->household_type ?? ''));

        return DemoHouseholdWaterSupply::normalizeCanonicalHouseholdType($profileType) ?? '';
    }

    /**
     * Zone number for JS + a single display label ("Zone 2"). Never a Purok label.
     *
     * @return array{zone: string, zoneLabel: string}
     */
    private function resolveMarkerZoneFields(Household $household): array
    {
        $raw = HouseholdZoneResolver::storedValueFromHousehold($household);
        $number = HouseholdZoneResolver::zoneNumberFromStoredValue($raw);
        $label = $number !== null ? 'Zone '.$number : '';

        return [
            'zone' => $number !== null ? (string) $number : '',
            'zoneLabel' => $label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateFromHousehold(Household $household): array
    {
        $residentKey = (new Resident)->getKeyName();
        $household->loadMissing(['residents' => fn ($q) => $q->orderBy($residentKey)]);
        $residents = $household->residents;
        $head = $this->resolveHouseholdHead($residents);
        $zoneFields = $this->resolveMarkerZoneFields($household);

        return [
            'householdNo' => (string) $household->household_no,
            'householdKey' => OpaqueId::forUrl('h', (string) $household->household_no),
            'houseHead' => $head !== null ? HouseholdProfilingPresenter::fullName($head) : '—',
            'headFirstName' => $head !== null ? trim((string) $head->first_name) : '',
            'headMiddleName' => $head !== null ? trim((string) ($head->middle_name ?? '')) : '',
            'headLastName' => $head !== null ? trim((string) $head->last_name) : '',
            'householdType' => $this->persistedHouseholdType($household),
            'zone' => $zoneFields['zone'] !== '' ? (int) $zoneFields['zone'] : null,
            'zoneLabel' => $zoneFields['zoneLabel'],
            'members' => $residents->count(),
        ];
    }

    /**
     * Barangay household population for Spot Mapping (excludes NR-FP sentinel).
     *
     * @return \Illuminate\Database\Eloquent\Builder<Household>
     */
    private function operationalHouseholdQuery()
    {
        return Household::query()->excludingNonResidentSentinel();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Household>  $query
     */
    private function applyUnplottedCoordinateScope($query): void
    {
        $query->where(function ($inner): void {
            $inner->whereNull('latitude')
                ->orWhereNull('longitude')
                ->orWhere(function ($zero): void {
                    $zero->where('latitude', 0)
                        ->where('longitude', 0);
                });
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Household>  $query
     */
    private function applyPlottedCoordinateScope($query): void
    {
        $query
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where(function ($inner): void {
                $inner->where('latitude', '!=', 0)
                    ->orWhere('longitude', '!=', 0);
            });
    }
}
