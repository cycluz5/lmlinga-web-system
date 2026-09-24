<?php

namespace App\Http\Controllers\HealthRecords;

use App\Http\Controllers\Controller;
use App\Support\HealthRecordsNonResidentFamilyPlanning;
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
}
