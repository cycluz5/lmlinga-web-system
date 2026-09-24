<?php

namespace App\Http\Controllers\HouseholdRequests;

use App\Http\Controllers\Controller;
use App\Support\HouseholdRecordRequestPresenter;
use Illuminate\View\View;

class HouseholdRequestsController extends Controller
{
    public function index(): View
    {
        $requests = HouseholdRecordRequestPresenter::listingRows();

        return view('pages.household-requests.index', [
            'active' => 'household-requests',
            'pageTitle' => 'Household Requests',
            'pageSubtitle' => 'Monitor automatic household record verification history and results.',
            'requests' => $requests,
            'zoneOptions' => HouseholdRecordRequestPresenter::zoneFilterOptions(),
            'hasRecords' => count($requests) > 0,
        ]);
    }

    public function show(string $id): View
    {
        return view('pages.household-requests.view', [
            'active' => 'household-requests',
            'pageTitle' => 'Household Request Details',
            'pageSubtitle' => 'Automatic verification result for this household record access request.',
            'requestId' => $id,
            'demoRequest' => HouseholdRecordRequestPresenter::findByPublicId($id),
        ]);
    }
}
