<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSchoolImmunizationRequest;
use App\Support\HealthMemberIdentity;
use App\Support\SchoolImmunizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * DB-09 Phase 2/3 — School-Based Immunization read + write for persisted residents.
 *
 * Phase 3 hydrates the frozen Blade for persisted residents and posts saves.
 * Demo/preview members remain presentation-compatible without DB writes.
 */
class SchoolBasedImmunizationController extends Controller
{
    public function __construct(
        private readonly HealthMemberIdentity $identity,
        private readonly SchoolImmunizationService $service,
    ) {}

    public function show(string $householdNo, string $memberId): View
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);
        $persistenceSource = HealthMemberIdentity::persistenceSource($ctx);
        $immunizationState = null;
        $sbiEligible = true;
        $sbiIneligibleDetail = SchoolImmunizationService::INELIGIBLE_DETAIL;

        if ($ctx['resident'] !== null) {
            $sbiEligible = SchoolImmunizationService::isEligibleForSchoolImmunization($ctx['resident']);
            $sbiIneligibleDetail = SchoolImmunizationService::ineligibleDetailFor($ctx['resident']);
            $immunizationState = $this->service->forResident($ctx['resident']);
        }

        return view('pages.household-profiling.school-based-immunization', [
            'active' => 'household-profiling',
            'pageTitle' => 'School-Based Immunization',
            'pageSubtitle' => $ctx['member']
                ? 'Vaccination records for '.$ctx['member']['name'].' in '.$ctx['householdNo'].'.'
                : 'No record found.',
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
            'demoHousehold' => $ctx['household'],
            'demoMember' => $ctx['member'],
            'persistenceSource' => $persistenceSource,
            'immunizationState' => $immunizationState,
            'sbiEligible' => $sbiEligible,
            'sbiIneligibleDetail' => $sbiIneligibleDetail,
        ]);
    }

    public function store(
        StoreSchoolImmunizationRequest $request,
        string $householdNo,
        string $memberId,
    ): RedirectResponse {
        $ctx = $this->identity->resolvePersistedOrFail($householdNo, $memberId);

        $this->service->saveForResident($ctx['resident'], $request->validated());

        return redirect()
            ->route('household-profiling.members.school-based-immunization', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ])
            ->with('status', 'School-based immunization saved.');
    }
}
