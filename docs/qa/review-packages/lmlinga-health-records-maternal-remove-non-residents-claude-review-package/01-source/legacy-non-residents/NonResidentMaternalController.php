<?php

namespace App\Http\Controllers\HealthRecords;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNonResidentMaternalClientRequest;
use App\Support\HealthRecordsMaternal;
use App\Support\HealthRecordsNonResidentMaternal;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class NonResidentMaternalController extends Controller
{
    public function index(): View
    {
        $rows = HealthRecordsNonResidentMaternal::rows();
        $summary = HealthRecordsMaternal::summaryCounts($rows);

        return view('pages.health-records.maternal-non-residents', [
            'active' => 'maternal',
            'pageTitle' => 'Maternal Care | Non Residents',
            'pageSubtitle' => 'Record and management of maternal care details for monitoring and tracking maternal health status.',
            'rows' => $rows,
            'summary' => $summary,
            'barangays' => HealthRecordsNonResidentMaternal::barangays($rows),
            'years' => HealthRecordsNonResidentMaternal::years($rows),
            'totalUnfiltered' => count($rows),
        ]);
    }

    public function create(): View
    {
        return view('pages.health-records.maternal-non-residents-create', [
            'active' => 'maternal',
            'pageTitle' => 'Add Non-Resident Maternal Client',
            'pageSubtitle' => 'Register a new non-resident maternal client.',
            'statusOptions' => HealthRecordsNonResidentMaternal::statusOptions(),
        ]);
    }

    public function store(StoreNonResidentMaternalClientRequest $request): RedirectResponse
    {
        HealthRecordsNonResidentMaternal::createFromRegistration($request->validated());

        return redirect()
            ->route('health-records.maternal.non-residents.index')
            ->with('status', 'Non-resident maternal client saved.');
    }

    public function show(string $clientKey): View
    {
        $client = HealthRecordsNonResidentMaternal::findEligible($clientKey);
        abort_if($client === null, 404);

        return view('pages.health-records.maternal-non-residents-show', [
            'active' => 'maternal',
            'pageTitle' => 'Maternal Care | Non Residents',
            'pageSubtitle' => 'Record and management of maternal care details for monitoring and tracking maternal health status.',
            'client' => $client,
            'pregnancySummary' => HealthRecordsNonResidentMaternal::pregnancySummary($client),
        ]);
    }
}
