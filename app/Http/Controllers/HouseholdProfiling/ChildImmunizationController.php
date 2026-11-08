<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChildImmunizationRequest;
use App\Support\ChildImmunizationService;
use App\Support\HealthMemberIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * DB-08 Phase 2/3 — Child Immunization read + write for persisted residents.
 */
class ChildImmunizationController extends Controller
{
    public function __construct(
        private readonly HealthMemberIdentity $identity,
        private readonly ChildImmunizationService $service,
    ) {}

    public function show(string $householdNo, string $memberId): View
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);
        $persistenceSource = HealthMemberIdentity::persistenceSource($ctx);
        $immunizationState = null;

        if ($ctx['resident'] !== null) {
            $immunizationState = $this->service->forResident($ctx['resident']);
        }

        return view('pages.household-profiling.child-immunization', [
            'active' => 'household-profiling',
            'pageTitle' => 'Child Immunization',
            'pageSubtitle' => $ctx['member']
                ? 'Vaccination records for '.$ctx['member']['name'].' in '.$ctx['householdNo'].'.'
                : 'No record found.',
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
            'demoHousehold' => $ctx['household'],
            'demoMember' => $ctx['member'],
            'persistenceSource' => $persistenceSource,
            'immunizationState' => $immunizationState,
        ]);
    }

    public function store(
        StoreChildImmunizationRequest $request,
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
            ->with('status', 'Child immunization saved.');
    }
}
