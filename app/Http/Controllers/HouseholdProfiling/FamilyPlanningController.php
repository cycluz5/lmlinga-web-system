<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFamilyPlanningVisitRequest;
use App\Http\Requests\UpdateFamilyPlanningVisitRequest;
use App\Support\DemoFamilyPlanning;
use App\Support\FamilyPlanningEligibility;
use App\Support\FamilyPlanningErdMode;
use App\Support\FamilyPlanningVisitService;
use App\Support\HealthMemberIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FamilyPlanningController extends Controller
{
    public function __construct(
        private readonly HealthMemberIdentity $identity,
        private readonly FamilyPlanningVisitService $service,
    ) {}

    public function index(string $householdNo, string $memberId): View
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);
        $this->rejectIfIneligible($ctx);
        $key = $ctx['householdNo'];
        $memberKey = $ctx['memberId'];
        $household = $ctx['household'];
        $member = $ctx['member'];

        $visits = [];
        $canPersist = HealthMemberIdentity::canQueueWrites($ctx);
        if ($member && $canPersist) {
            $visits = $this->service->historyRowsForResident($ctx['resident']);
        } elseif ($member) {
            $visits = DemoFamilyPlanning::forMember($key, $memberKey);
        }

        return view('pages.household-profiling.family-planning-history', [
            'active' => 'household-profiling',
            'pageTitle' => 'Family Planning',
            'pageSubtitle' => $member
                ? 'Family planning visit records for '.$member['name'].' in '.$key.'.'
                : 'No record found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => $household,
            'demoMember' => $member,
            'visits' => $visits,
            'canPersist' => $canPersist,
            'commoditiesSupported' => FamilyPlanningErdMode::commoditiesSupported($canPersist),
        ]);
    }

    public function create(string $householdNo, string $memberId): View
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);
        $this->rejectIfIneligible($ctx);
        $key = $ctx['householdNo'];
        $memberKey = $ctx['memberId'];
        $household = $ctx['household'];
        $member = $ctx['member'];
        $canPersist = HealthMemberIdentity::canQueueWrites($ctx);

        $visitsForStats = [];
        if ($member && $canPersist) {
            $visitsForStats = $this->service->historyRowsForResident($ctx['resident']);
        } elseif ($member) {
            $visitsForStats = DemoFamilyPlanning::forMember($key, $memberKey);
        }

        return view('pages.household-profiling.family-planning-form', [
            'active' => 'household-profiling',
            'pageTitle' => 'Family Planning',
            'pageSubtitle' => $member
                ? 'Add family planning visit for '.$member['name'].' in '.$key.'.'
                : 'No record found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => $household,
            'demoMember' => $member,
            'mode' => 'create',
            'visit' => [],
            'canPersist' => $canPersist,
            'visitsForStats' => $visitsForStats,
            'commoditiesSupported' => FamilyPlanningErdMode::commoditiesSupported($canPersist),
        ]);
    }

    public function store(
        StoreFamilyPlanningVisitRequest $request,
        string $householdNo,
        string $memberId
    ): RedirectResponse {
        $ctx = $this->identity->resolvePersistedOrFail($householdNo, $memberId);
        $this->rejectIfIneligible($ctx);

        $payload = $request->visitPayload();
        $this->service->createForResident($ctx['resident'], $payload);

        $redirect = redirect()
            ->route('household-profiling.members.family-planning.index', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ])
            ->with('status', 'Family planning visit saved.');

        if (! FamilyPlanningErdMode::commoditiesSupported(true) && $this->service->payloadHasCommodities($payload)) {
            $redirect->with('warning', FamilyPlanningVisitService::COMMODITIES_NOT_STORED_MESSAGE);
        }

        return $redirect;
    }

    public function show(string $householdNo, string $memberId, string $visitId): View
    {
        $context = $this->resolveVisitContext($householdNo, $memberId, $visitId);

        return view('pages.household-profiling.family-planning-show', [
            'active' => 'household-profiling',
            'pageTitle' => 'Family Planning',
            'pageSubtitle' => $context['member']
                ? 'Family planning visit for '.$context['member']['name'].' in '.$context['householdNo'].'.'
                : 'No record found.',
            'householdNo' => $context['householdNo'],
            'memberId' => $context['memberId'],
            'visitId' => $context['visitId'],
            'demoHousehold' => $context['household'],
            'demoMember' => $context['member'],
            'visit' => $context['visit'] ?? [],
            'visitsForStats' => $context['visitsForStats'],
            'canPersist' => $context['canPersist'],
            'commoditiesSupported' => FamilyPlanningErdMode::commoditiesSupported($context['canPersist']),
        ]);
    }

    public function edit(string $householdNo, string $memberId, string $visitId): View
    {
        $context = $this->resolveVisitContext($householdNo, $memberId, $visitId);

        return view('pages.household-profiling.family-planning-form', [
            'active' => 'household-profiling',
            'pageTitle' => 'Family Planning',
            'pageSubtitle' => $context['member']
                ? 'Edit family planning visit for '.$context['member']['name'].' in '.$context['householdNo'].'.'
                : 'No record found.',
            'householdNo' => $context['householdNo'],
            'memberId' => $context['memberId'],
            'visitId' => $context['visitId'],
            'demoHousehold' => $context['household'],
            'demoMember' => $context['member'],
            'mode' => 'edit',
            'visit' => $context['visit'] ?? [],
            'canPersist' => $context['canPersist'],
            'visitsForStats' => $context['visitsForStats'],
            'commoditiesSupported' => FamilyPlanningErdMode::commoditiesSupported($context['canPersist']),
        ]);
    }

    public function update(
        UpdateFamilyPlanningVisitRequest $request,
        string $householdNo,
        string $memberId,
        string $visitId
    ): RedirectResponse {
        $ctx = $this->identity->resolvePersistedOrFail($householdNo, $memberId);
        $this->rejectIfIneligible($ctx);
        $id = strtoupper(trim($visitId));
        $payload = $request->visitPayload();

        $this->service->updateForResident($ctx['resident'], $id, $payload);

        $redirect = redirect()
            ->route('household-profiling.members.family-planning.show', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
                'visitId' => $id,
            ])
            ->with('status', 'Family planning visit updated.');

        if (! FamilyPlanningErdMode::commoditiesSupported(true) && $this->service->payloadHasCommodities($payload)) {
            $redirect->with('warning', FamilyPlanningVisitService::COMMODITIES_NOT_STORED_MESSAGE);
        }

        return $redirect;
    }

    /**
     * @return array{
     *     householdNo: string,
     *     memberId: string,
     *     visitId: string,
     *     household: array<string, mixed>|null,
     *     member: array<string, mixed>|null,
     *     visit: array<string, mixed>|null,
     *     visitsForStats: list<array<string, mixed>>,
     *     canPersist: bool
     * }
     */
    private function resolveVisitContext(string $householdNo, string $memberId, string $visitId): array
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);
        $this->rejectIfIneligible($ctx);
        $hh = $ctx['householdNo'];
        $mb = $ctx['memberId'];
        $id = strtoupper(trim($visitId));
        $household = $ctx['household'];
        $member = $ctx['member'];
        $canPersist = HealthMemberIdentity::canQueueWrites($ctx);
        $visit = null;
        $visitsForStats = [];

        if ($member && $canPersist) {
            $visit = $this->service->findPresentationForResident($ctx['resident'], $id);
            if ($visit === null) {
                abort(404);
            }
            $visitsForStats = $this->service->historyRowsForResident($ctx['resident']);
        } elseif ($member) {
            $visit = DemoFamilyPlanning::find($hh, $mb, $id);
            $visitsForStats = DemoFamilyPlanning::forMember($hh, $mb);
        }

        return [
            'householdNo' => $hh,
            'memberId' => $mb,
            'visitId' => $id,
            'household' => $household,
            'member' => $member,
            'visit' => $visit,
            'visitsForStats' => $visitsForStats,
            'canPersist' => $canPersist,
        ];
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

        if (! FamilyPlanningEligibility::allowsMemberContext($ctx)) {
            abort(403, FamilyPlanningEligibility::INELIGIBLE_MESSAGE);
        }
    }
}
