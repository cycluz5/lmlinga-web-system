<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHouseholdRequest;
use App\Http\Requests\UpdateHouseholdRequest;
use App\Services\HouseholdService;
use App\Services\SpotMappingHandoffService;
use App\Support\DatabaseSchemaGuard;
use App\Support\DemoCatalog;
use App\Support\EnvironmentalHealthReturnContext;
use App\Support\HouseholdMemberResolver;
use App\Support\HouseholdProfilingMemberPdf;
use App\Support\HouseholdProfilingPresenter;
use App\Support\HouseholdZoneResolver;
use App\Support\Offline\OfflineFieldHasher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class HouseholdProfilingController extends Controller
{
    public function __construct(
        private readonly HouseholdService $households,
        private readonly HouseholdMemberResolver $resolver,
        private readonly SpotMappingHandoffService $handoff,
    ) {}

    public function index(): View
    {
        $demoHouseholds = $this->households->profilingListRows();

        return view('pages.household-profiling.index', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => 'Manage and View All Registered Household in the Barangay',
            'demoHouseholds' => $demoHouseholds,
            'demoTotal' => count($demoHouseholds),
            'profilingSummary' => $this->households->profilingSummary(),
            'householdDeletionEnabled' => $this->households->householdDeletionSupported(),
        ]);
    }

    public function reportBuilder(Request $request): View
    {
        $rows = $this->households->profilingMemberExportRows();

        // Zone options come from every household (listRows), not just ones
        // with member rows already — a zone with households but no
        // residents yet should still be selectable.
        $householdRows = $this->households->profilingListRows();
        $zones = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['zone'] ?? '')),
            $householdRows
        ))));
        sort($zones, SORT_NATURAL | SORT_FLAG_CASE);

        return view('pages.household-profiling.report-builder', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling Report',
            'pageSubtitle' => 'Filter by zone, preview the report, then export PDF.',
            'zones' => $zones,
            'reportRows' => $rows,
            'initialOptions' => [
                'zone' => trim((string) $request->query('zone', 'all')),
            ],
            'exportBase' => route('household-profiling.export'),
            'dashboardUrl' => route('household-profiling.index'),
        ]);
    }

    public function export(Request $request): Response
    {
        $zone = trim((string) $request->query('zone', 'all'));

        $rows = $this->households->profilingMemberExportRows(
            null,
            $zone !== 'all' ? $zone : null,
        );

        $scopeParts = [];
        $scopeParts[] = $zone !== '' && $zone !== 'all' ? 'Zone: '.$zone : 'All Zones';

        $filename = 'household-profiling-members-'.now()->format('Ymd-His');

        return HouseholdProfilingMemberPdf::response($rows, $filename, implode(' · ', $scopeParts));
    }

    public function create(Request $request): View
    {
        $fromSpotMapping = $request->query('from') === 'spot-mapping';
        $householdValues = $this->createFormValuesFromQuery($request);

        return view('pages.household-profiling.household-create', [
            'active' => $fromSpotMapping ? 'spot-mapping' : 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => 'Register a new household in Barangay La Medalla.',
            'formMode' => 'create',
            'householdValues' => $householdValues,
            'from' => $fromSpotMapping ? 'spot-mapping' : null,
        ]);
    }

    public function store(StoreHouseholdRequest $request): RedirectResponse
    {
        $household = $this->households->create($request->validated());
        $fromSpotMapping = $request->input('from') === 'spot-mapping';

        if ($fromSpotMapping) {
            $latitude = $household->latitude;
            $longitude = $household->longitude;
            $hasPlottedCoordinates = $latitude !== null
                && $longitude !== null
                && ! (((float) $latitude === 0.0) && ((float) $longitude === 0.0));

            if ($hasPlottedCoordinates) {
                return redirect()
                    ->route('spot-mapping.index')
                    ->with('status', 'Household registered successfully. Location is plotted.');
            }

            return redirect()
                ->route('spot-mapping.index', [
                    'plot_household' => $household->household_no,
                ])
                ->with('status', 'Household registered successfully. Plot its location on the map.');
        }

        $token = $this->handoff->issueForHousehold($household);
        EnvironmentalHealthReturnContext::rememberForHouseholdProfilingCreate(
            (string) $household->household_no
        );

        return redirect()
            ->route('environmental-health.household-water-supply', [
                'handoff' => $token,
            ])
            ->with('status', 'Household registered successfully.');
    }

    public function show(string $householdNo): View
    {
        $resolved = $this->resolver->resolveHousehold($householdNo);
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);

        return view('pages.household-profiling.view', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => $resolved
                ? 'View household details and members in Barangay La Medalla.'
                : 'Household was not found.',
            'householdNo' => $key,
            'demoHousehold' => $resolved['presentation'] ?? null,
            'householdSource' => $resolved['source'] ?? null,
        ]);
    }

    public function edit(string $householdNo): View
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);

        try {
            $household = $this->resolver->resolveDbHouseholdOrFail($key);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return view('pages.household-profiling.household-edit', [
                'active' => 'household-profiling',
                'pageTitle' => 'Household Profiling',
                'pageSubtitle' => 'Household was not found.',
                'householdNo' => $key,
                'demoHousehold' => null,
                'householdSource' => null,
                'persistable' => false,
                'formMode' => 'edit',
                'householdValues' => [],
            ]);
        }

        $presentation = HouseholdProfilingPresenter::fromModel($household->load('residents'));

        return view('pages.household-profiling.household-edit', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => 'Edit household '.$key.'.',
            'householdNo' => $key,
            'demoHousehold' => $presentation,
            'householdSource' => 'db',
            'persistable' => true,
            'formMode' => 'edit',
            'householdValues' => $this->formValuesFromModel($household),
            'offlineHouseholdPk' => (int) $household->getKey(),
            'offlineFieldHash' => OfflineFieldHasher::household($household),
        ]);
    }

    public function update(UpdateHouseholdRequest $request, string $householdNo): RedirectResponse
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $household = $this->resolver->resolveDbHouseholdOrFail($key);

        $this->households->update($household, $request->validated());

        return redirect()
            ->route('household-profiling.view', [
                'householdNo' => $key,
            ])
            ->with('status', 'Household updated successfully.');
    }

    /**
     * Remove an active MySQL household when schema supports archival delete (R02-A).
     * Disabled on authoritative ERD schemas without deleted_at. Never hard-deletes.
     */
    public function destroy(string $householdNo): RedirectResponse
    {
        if (! $this->households->householdDeletionSupported()) {
            return redirect()
                ->route('household-profiling.index')
                ->with('error', 'Household deletion is not supported for the current database.');
        }

        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $household = $this->resolver->resolveDbHouseholdOrFail($key);

        $this->households->softDelete($household);

        return redirect()
            ->route('household-profiling.index')
            ->with('status', 'Household '.$key.' was removed from active records.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formValuesFromModel(\App\Models\Household $household): array
    {
        $guard = app(DatabaseSchemaGuard::class);
        $locationColumn = HouseholdZoneResolver::locationColumn();
        $rawLocation = $locationColumn !== null
            ? trim((string) ($household->getAttributes()[$locationColumn] ?? ''))
            : '';
        $streetColumn = $guard->householdStreetColumn();
        $street = $streetColumn !== null
            ? trim((string) ($household->getAttributes()[$streetColumn] ?? ''))
            : '';
        $address = $guard->columnExists('households', 'address')
            ? trim((string) ($household->getAttributes()['address'] ?? ''))
            : '';
        $accomplishedBy = $guard->columnExists('households', 'accomplished_by')
            ? trim((string) ($household->getAttributes()['accomplished_by'] ?? ''))
            : '';

        return [
            'household_no' => (string) $household->household_no,
            'zone' => $rawLocation === '' ? '' : HouseholdZoneResolver::displayLabelFromStoredValue($rawLocation),
            'street' => $street,
            'date_registered' => $household->date_registered?->format('Y-m-d') ?? '',
            'address' => $address,
            'latitude' => $household->latitude !== null ? (string) $household->latitude : '',
            'longitude' => $household->longitude !== null ? (string) $household->longitude : '',
            'accomplished_by' => $accomplishedBy,
        ];
    }

    /**
     * Optional Spot Mapping handoff query values for the shared create form.
     *
     * @return array<string, string>
     */
    private function createFormValuesFromQuery(Request $request): array
    {
        $values = [];

        $zone = trim((string) $request->query('zone', ''));
        if (preg_match('/^([1-5])$/', $zone, $matches) === 1) {
            $values['zone'] = 'Zone '.$matches[1];
        } elseif (preg_match('/^Zone\s*([1-5])$/i', $zone, $matches) === 1) {
            $values['zone'] = 'Zone '.$matches[1];
        }

        $latitude = trim((string) $request->query('latitude', ''));
        if ($latitude !== '' && is_numeric($latitude)) {
            $values['latitude'] = $latitude;
        }

        $longitude = trim((string) $request->query('longitude', ''));
        if ($longitude !== '' && is_numeric($longitude)) {
            $values['longitude'] = $longitude;
        }

        return $values;
    }
}
