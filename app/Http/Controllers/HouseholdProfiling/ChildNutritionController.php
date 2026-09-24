<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChildNutritionRequest;
use App\Support\ChildNutritionService;
use App\Support\HealthMemberIdentity;
use App\Support\HealthRecordsDeworming;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * DB-10 Phase 2 — Child Nutrition read + write for persisted residents.
 *
 * Demo/preview members remain presentation-compatible without DB writes.
 */
class ChildNutritionController extends Controller
{
    public function __construct(
        private readonly HealthMemberIdentity $identity,
        private readonly ChildNutritionService $service,
    ) {}

    public function show(string $householdNo, string $memberId): View
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);
        $persistenceSource = HealthMemberIdentity::persistenceSource($ctx);
        $nutritionState = null;
        $dewormingChild = null;
        $dewormingRecords = [];
        $dewormingCanAdd = false;

        if ($ctx['resident'] !== null) {
            $nutritionState = $this->service->forResident($ctx['resident']);
        }

        if ($ctx['member'] !== null) {
            $dewormingChild = HealthRecordsDeworming::findChildForMember($ctx['householdNo'], $ctx['memberId']);
            $dewormingCanAdd = HealthRecordsDeworming::memberCanManageRecords($ctx['member']);
            $dewormingRecords = HealthRecordsDeworming::recordsForMember($ctx['householdNo'], $ctx['memberId']);
        }

        return view('pages.household-profiling.child-nutrition', [
            'active' => 'household-profiling',
            'pageTitle' => 'Child Nutrition',
            'pageSubtitle' => $ctx['member']
                ? 'Monitor child growth and nutrition for '.$ctx['member']['name'].' in '.$ctx['householdNo'].'.'
                : 'No record found.',
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
            'demoHousehold' => $ctx['household'],
            'demoMember' => $ctx['member'],
            'persistenceSource' => $persistenceSource,
            'nutritionState' => $nutritionState,
            'dewormingChild' => $dewormingChild,
            'dewormingRecords' => $dewormingRecords,
            'dewormingCanAdd' => $dewormingCanAdd,
            'dewormingRoundOptions' => HealthRecordsDeworming::roundOptions(),
            'dewormingSeStatusOptions' => HealthRecordsDeworming::seStatusOptions(),
        ]);
    }

    public function store(
        StoreChildNutritionRequest $request,
        string $householdNo,
        string $memberId,
    ): RedirectResponse {
        $ctx = $this->identity->resolvePersistedOrFail($householdNo, $memberId);

        $this->service->saveForResident($ctx['resident'], $request->validated());

        return redirect()
            ->route('household-profiling.members.child-nutrition', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ])
            ->with('status', 'Child nutrition saved.');
    }
}
