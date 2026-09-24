<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChildBirthHistoryRequest;
use App\Support\ChildBirthHistoryService;
use App\Support\HealthMemberIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ChildBirthHistoryController extends Controller
{
    public function __construct(
        private readonly HealthMemberIdentity $identity,
        private readonly ChildBirthHistoryService $service,
    ) {}

    public function edit(string $householdNo, string $memberId): View
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);
        $persistenceSource = HealthMemberIdentity::persistenceSource($ctx);
        $birthHistoryForm = null;

        if ($ctx['resident'] !== null && ChildBirthHistoryService::persistenceAvailable()) {
            $birthHistoryForm = ChildBirthHistoryService::formValuesForResident($ctx['resident']);
        }

        return view('pages.household-profiling.child-immunization-birth-history-edit', [
            'active' => 'household-profiling',
            'pageTitle' => 'Birth History',
            'pageSubtitle' => $ctx['member']
                ? 'Birth history information for '.$ctx['member']['name'].' in '.$ctx['householdNo'].'.'
                : 'No record found.',
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
            'demoHousehold' => $ctx['household'],
            'demoMember' => $ctx['member'],
            'persistenceSource' => $persistenceSource,
            'birthHistoryForm' => $birthHistoryForm,
        ]);
    }

    public function store(
        StoreChildBirthHistoryRequest $request,
        string $householdNo,
        string $memberId,
    ): RedirectResponse {
        $ctx = $this->identity->resolvePersistedOrFail($householdNo, $memberId);

        $this->service->saveForResident($ctx['resident'], $request->validated());

        return redirect()
            ->route('household-profiling.members.child-immunization', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ])
            ->with('status', 'Birth history saved.');
    }
}
