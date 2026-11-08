<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdultImmunizationRequest;
use App\Support\AdultImmunizationEligibility;
use App\Support\AdultImmunizationErdMode;
use App\Support\AdultImmunizationService;
use App\Support\HealthMemberIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AdultImmunizationController extends Controller
{
    public function __construct(
        private readonly HealthMemberIdentity $identity,
        private readonly AdultImmunizationService $service,
    ) {}

    public function index(string $householdNo, string $memberId): View
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);
        $this->rejectIfIneligible($ctx);
        $canPersist = HealthMemberIdentity::canQueueWrites($ctx);
        $records = [];

        if ($canPersist && $ctx['resident'] !== null) {
            $records = $this->service->historyForResident($ctx['resident']);
        }

        $datesByType = [];
        foreach ($records as $row) {
            $datesByType[$row['vaccine_type']] = $row['date_given'];
        }

        return view('pages.household-profiling.adult-immunization-history', [
            'active' => 'household-profiling',
            'pageTitle' => 'Immunization',
            'pageSubtitle' => $ctx['member']
                ? 'Adult immunization records for '.$ctx['member']['name'].' in '.$ctx['householdNo'].'.'
                : 'No record found.',
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
            'demoHousehold' => $ctx['household'],
            'demoMember' => $ctx['member'],
            'eligible' => true,
            'canPersist' => $canPersist,
            'tableAvailable' => AdultImmunizationErdMode::isActive(),
            'datesByType' => $datesByType,
        ]);
    }

    public function store(
        StoreAdultImmunizationRequest $request,
        string $householdNo,
        string $memberId,
    ): RedirectResponse {
        $ctx = $this->identity->resolvePersistedOrFail($householdNo, $memberId);

        if (! AdultImmunizationEligibility::allowsMemberContext($ctx)) {
            abort(403, AdultImmunizationEligibility::INELIGIBLE_MESSAGE);
        }

        $dates = (array) ($request->validated('dates') ?? []);
        $this->service->syncForResident($ctx['resident'], $dates);

        return redirect()
            ->route('household-profiling.members.adult-immunization', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ])
            ->with('status', 'Adult immunization record saved.');
    }

    /**
     * @param  array{
     *     member: array<string, mixed>|null,
     *     resident: \App\Models\Resident|null
     * }  $ctx
     */
    private function rejectIfIneligible(array $ctx): void
    {
        if (($ctx['member'] ?? null) === null && ($ctx['resident'] ?? null) === null) {
            return;
        }

        if (! AdultImmunizationEligibility::allowsMemberContext($ctx)) {
            abort(403, AdultImmunizationEligibility::INELIGIBLE_MESSAGE);
        }
    }
}
