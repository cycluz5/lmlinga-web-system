<?php

namespace App\Http\Controllers\HealthRecords;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNonResidentFamilyPlanningClientRequest;
use App\Http\Requests\StoreNonResidentFamilyPlanningVisitRequest;
use App\Http\Requests\UpdateNonResidentFamilyPlanningVisitRequest;
use App\Support\HealthRecordsNonResidentFamilyPlanning;
use App\Support\MinimalTabularPdf;
use App\Support\NonResidentFamilyPlanningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

class NonResidentFamilyPlanningController extends Controller
{
    public function index(): View
    {
        $clients = HealthRecordsNonResidentFamilyPlanning::clients();

        return view('pages.health-records.non-resident-family-planning.index', [
            'active' => 'family-planning',
            'pageTitle' => 'Family Planning | Non Residents',
            'pageSubtitle' => 'List of all non-resident clients who received family planning services in this barangay.',
            'clients' => $clients,
            'barangays' => HealthRecordsNonResidentFamilyPlanning::barangays($clients),
            'years' => HealthRecordsNonResidentFamilyPlanning::years($clients),
            'totalUnfiltered' => count($clients),
        ]);
    }

    public function export(): Response
    {
        $clients = HealthRecordsNonResidentFamilyPlanning::clients();

        return MinimalTabularPdf::download(
            'Family Planning Non-Residents Export',
            'Health Records - Family Planning - Non-Residents',
            ['Full Name', 'Age', 'Method', 'Start Date', 'Last Visit', 'Barangay'],
            array_map(static fn (array $row): array => [
                (string) ($row['full_name'] ?? ''),
                (string) ($row['age'] ?? ''),
                (string) ($row['method'] ?? ''),
                (string) ($row['start_date'] ?? ''),
                (string) ($row['last_visit'] ?? ''),
                (string) ($row['barangay'] ?? ''),
            ], $clients),
            'family-planning-non-residents',
        );
    }

    public function create(): View
    {
        return view('pages.health-records.non-resident-family-planning.create-client', [
            'active' => 'family-planning',
            'pageTitle' => 'Family Planning | Non Residents',
            'pageSubtitle' => 'Add a non-resident family planning client.',
            'civilStatusOptions' => HealthRecordsNonResidentFamilyPlanning::civilStatusOptions(),
            'sexOptions' => HealthRecordsNonResidentFamilyPlanning::sexOptions(),
            'methodOptions' => HealthRecordsNonResidentFamilyPlanning::methodOptions(),
            'commodityOptions' => HealthRecordsNonResidentFamilyPlanning::commodityOptions(),
        ]);
    }

    public function store(StoreNonResidentFamilyPlanningClientRequest $request): RedirectResponse
    {
        $client = NonResidentFamilyPlanningService::createClient($request->validated());

        return redirect()
            ->route('health-records.family-planning.non-residents.show', [
                'clientKey' => $client['key'],
            ])
            ->with('status', 'Non-resident family planning client saved.');
    }

    public function show(string $clientKey): View
    {
        $client = HealthRecordsNonResidentFamilyPlanning::findClient($clientKey);

        return view('pages.health-records.non-resident-family-planning.show', [
            'active' => 'family-planning',
            'pageTitle' => 'Non Residents Client',
            'pageSubtitle' => 'Non-resident family planning client record.',
            'client' => $client,
            'clientKey' => $clientKey,
            'commoditiesLedger' => $client !== null
                ? HealthRecordsNonResidentFamilyPlanning::commoditiesLedger($client)
                : [],
        ]);
    }

    public function destroy(string $clientKey): RedirectResponse
    {
        $residentId = NonResidentFamilyPlanningService::parseClientKey($clientKey);
        abort_if($residentId === null, 404);

        NonResidentFamilyPlanningService::deleteClient($residentId);

        return redirect()
            ->route('health-records.family-planning.non-residents.index')
            ->with('status', 'Non-resident family planning client deleted.');
    }

    public function createVisit(string $clientKey): View
    {
        $client = HealthRecordsNonResidentFamilyPlanning::findClient($clientKey);

        return view('pages.health-records.non-resident-family-planning.visit-form', [
            'active' => 'family-planning',
            'pageTitle' => 'Family Planning | Non Residents',
            'pageSubtitle' => 'Add family planning visit record.',
            'client' => $client,
            'clientKey' => $clientKey,
            'mode' => 'create',
            'visit' => [],
            'visitId' => null,
            'methodOptions' => HealthRecordsNonResidentFamilyPlanning::methodOptions(),
            'commodityOptions' => HealthRecordsNonResidentFamilyPlanning::commodityOptions(),
        ]);
    }

    public function storeVisit(StoreNonResidentFamilyPlanningVisitRequest $request, string $clientKey): RedirectResponse
    {
        $residentId = NonResidentFamilyPlanningService::parseClientKey($clientKey);
        abort_if($residentId === null, 404);

        NonResidentFamilyPlanningService::createVisit($residentId, $request->validated());

        return redirect()
            ->route('health-records.family-planning.non-residents.show', [
                'clientKey' => $clientKey,
            ])
            ->with('status', 'Family planning visit saved.');
    }

    public function editVisit(string $clientKey, string $visitId): View
    {
        $client = HealthRecordsNonResidentFamilyPlanning::findClient($clientKey);
        $visit = $client !== null
            ? HealthRecordsNonResidentFamilyPlanning::findVisit($clientKey, $visitId)
            : null;

        return view('pages.health-records.non-resident-family-planning.visit-form', [
            'active' => 'family-planning',
            'pageTitle' => 'Family Planning | Non Residents',
            'pageSubtitle' => 'Edit family planning visit record.',
            'client' => $client,
            'clientKey' => $clientKey,
            'mode' => 'edit',
            'visit' => $visit ?? [],
            'visitId' => $visitId,
            'methodOptions' => HealthRecordsNonResidentFamilyPlanning::methodOptions(),
            'commodityOptions' => HealthRecordsNonResidentFamilyPlanning::commodityOptions(),
        ]);
    }

    public function updateVisit(
        UpdateNonResidentFamilyPlanningVisitRequest $request,
        string $clientKey,
        string $visitId
    ): RedirectResponse {
        $residentId = NonResidentFamilyPlanningService::parseClientKey($clientKey);
        $fpId = NonResidentFamilyPlanningService::parseVisitKey($visitId);
        abort_if($residentId === null || $fpId === null, 404);

        NonResidentFamilyPlanningService::updateVisit($residentId, $fpId, $request->validated());

        return redirect()
            ->route('health-records.family-planning.non-residents.show', [
                'clientKey' => $clientKey,
            ])
            ->with('status', 'Family planning visit updated.');
    }
}
