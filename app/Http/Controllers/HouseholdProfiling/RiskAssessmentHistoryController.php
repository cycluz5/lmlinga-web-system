<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRiskAssessmentRequest;
use App\Http\Requests\UpdateRiskAssessmentSectionRequest;
use App\Models\Resident;
use App\Support\DemoCatalog;
use App\Support\DemoRiskAssessment;
use App\Support\HealthMemberIdentity;
use App\Support\RiskAssessmentErdMode;
use App\Support\RiskAssessmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RiskAssessmentHistoryController extends Controller
{
    public function __construct(
        private readonly HealthMemberIdentity $identity,
        private readonly RiskAssessmentService $service,
    ) {}

    public function index(string $householdNo, string $memberId): View
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);
        $key = $ctx['householdNo'];
        $memberKey = $ctx['memberId'];
        $household = $ctx['household'];
        $member = $ctx['member'];

        $assessments = [];
        if ($member && $ctx['source'] === 'db' && $ctx['resident'] !== null) {
            $assessments = $this->service->historyRowsForResident($ctx['resident']);
        }

        $eligible = $this->isEligibleFromContext($ctx);

        return view('pages.household-profiling.risk-assessment-history', [
            'active' => 'household-profiling',
            'pageTitle' => 'Risk Assessment',
            'pageSubtitle' => $member
                ? 'Risk assessment history for '.$member['name'].' in '.$key.'.'
                : 'No record found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => $household,
            'demoMember' => $member,
            'assessments' => $assessments,
            'riskAssessmentEligible' => $eligible,
            'riskAssessmentErdActive' => $member && $ctx['source'] === 'db' && $ctx['resident'] !== null
                && RiskAssessmentErdMode::isActive(),
        ]);
    }

    public function create(string $householdNo, string $memberId): View
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);
        $key = $ctx['householdNo'];
        $memberKey = $ctx['memberId'];
        $household = $ctx['household'];
        $member = $ctx['member'];

        $eligible = $this->isEligibleFromContext($ctx);

        return view('pages.household-profiling.risk-assessment-form', [
            'active' => 'household-profiling',
            'pageTitle' => 'Risk Assessment',
            'pageSubtitle' => $member
                ? 'Add risk assessment for '.$member['name'].' in '.$key.'.'
                : 'No record found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => $household,
            'demoMember' => $member,
            'mode' => 'create',
            'assessment' => [],
            'canPersist' => HealthMemberIdentity::canQueueWrites($ctx) && $eligible,
            'riskAssessmentEligible' => $eligible,
            'riskAssessmentErdActive' => ($ctx['source'] === 'db' && $ctx['resident'] !== null)
                && RiskAssessmentErdMode::isActive(),
        ]);
    }

    public function store(
        StoreRiskAssessmentRequest $request,
        string $householdNo,
        string $memberId
    ): RedirectResponse {
        $ctx = $this->identity->resolvePersistedOrFail($householdNo, $memberId);

        $this->service->createForResident($ctx['resident'], $request->assessmentPayload());

        return redirect()
            ->route('household-profiling.members.risk-assessment', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ])
            ->with('status', 'Risk assessment saved.');
    }

    public function show(string $householdNo, string $memberId, string $assessmentId): View
    {
        $context = $this->resolveContext($householdNo, $memberId, $assessmentId);

        return view('pages.household-profiling.risk-assessment-show', [
            'active' => 'household-profiling',
            'pageTitle' => 'Risk Assessment',
            'pageSubtitle' => $context['member']
                ? 'View risk assessment for '.$context['member']['name'].' in '.$context['householdNo'].'.'
                : 'No record found.',
            'householdNo' => $context['householdNo'],
            'memberId' => $context['memberId'],
            'assessmentId' => $context['assessmentId'],
            'demoHousehold' => $context['household'],
            'demoMember' => $context['member'],
            'assessment' => $context['assessment'] ?? [],
            'historySections' => RiskAssessmentErdMode::visibleHistorySections(),
            'riskAssessmentErdActive' => RiskAssessmentErdMode::isActive(),
            'riskAssessmentEligible' => $context['eligible'],
        ]);
    }

    public function section(
        Request $request,
        string $householdNo,
        string $memberId,
        string $assessmentId,
        string $section
    ): View|RedirectResponse {
        $sectionKey = DemoRiskAssessment::normalizeSection($section);
        if ($sectionKey === null) {
            return redirect()->route('household-profiling.members.risk-assessment.show', [
                'householdNo' => DemoCatalog::normalizeHouseholdNo($householdNo),
                'memberId' => DemoCatalog::normalizeMemberId($memberId),
                'assessmentId' => strtoupper(trim($assessmentId)),
            ]);
        }

        $context = $this->resolveContext($householdNo, $memberId, $assessmentId);
        $editing = $request->routeIs('household-profiling.members.risk-assessment.section.edit');
        $sectionSupported = RiskAssessmentErdMode::sectionSupported($sectionKey);
        $eligible = $context['eligible'];

        return view('pages.household-profiling.risk-assessment-section', [
            'active' => 'household-profiling',
            'pageTitle' => 'Risk Assessment',
            'pageSubtitle' => $context['member']
                ? 'View risk assessment for '.$context['member']['name'].' in '.$context['householdNo'].'.'
                : 'No record found.',
            'householdNo' => $context['householdNo'],
            'memberId' => $context['memberId'],
            'assessmentId' => $context['assessmentId'],
            'demoHousehold' => $context['household'],
            'demoMember' => $context['member'],
            'assessment' => $context['assessment'] ?? [],
            'section' => $sectionKey,
            'sectionMeta' => DemoRiskAssessment::historySections()[$sectionKey],
            'isEditing' => $editing && $sectionSupported && $eligible,
            'sectionSupported' => $sectionSupported,
            'fields' => DemoRiskAssessment::fieldDefinitions(),
            'riskAssessmentErdActive' => RiskAssessmentErdMode::isActive(),
            'riskAssessmentEligible' => $eligible,
            'recordedDateLabel' => RiskAssessmentErdMode::recordedDateLabel(),
        ]);
    }

    public function updateSection(
        UpdateRiskAssessmentSectionRequest $request,
        string $householdNo,
        string $memberId,
        string $assessmentId,
        string $section
    ): RedirectResponse {
        $ctx = $this->identity->resolve($householdNo, $memberId);
        $hh = $ctx['householdNo'];
        $mb = $ctx['memberId'];
        $id = strtoupper(trim($assessmentId));
        $sectionKey = DemoRiskAssessment::normalizeSection($section);

        if (! $ctx['member'] || $sectionKey === null) {
            return redirect()
                ->route('household-profiling.members.risk-assessment', [
                    'householdNo' => $hh,
                    'memberId' => $mb,
                ])
                ->withErrors(['assessment' => 'Unable to update this risk assessment.']);
        }

        if ($ctx['source'] === 'db' && $ctx['resident'] !== null) {
            $this->service->updateSectionForResident(
                $ctx['resident'],
                $id,
                $sectionKey,
                $request->sectionPayload()
            );

            return redirect()
                ->route('household-profiling.members.risk-assessment.section', [
                    'householdNo' => $hh,
                    'memberId' => $mb,
                    'assessmentId' => $id,
                    'section' => $sectionKey,
                ])
                ->with('status', 'Risk assessment section saved.');
        }

        abort(404);
    }

    /**
     * @return array{
     *     householdNo: string,
     *     memberId: string,
     *     assessmentId: string,
     *     household: array<string, mixed>|null,
     *     member: array<string, mixed>|null,
     *     assessment: array<string, mixed>|null,
     *     eligible: bool
     * }
     */
    private function resolveContext(string $householdNo, string $memberId, string $assessmentId): array
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);
        $hh = $ctx['householdNo'];
        $mb = $ctx['memberId'];
        $id = strtoupper(trim($assessmentId));
        $household = $ctx['household'];
        $member = $ctx['member'];
        $assessment = null;

        if ($member && $ctx['source'] === 'db' && $ctx['resident'] !== null) {
            $assessment = $this->service->findPresentationForResident($ctx['resident'], $id);
            if ($assessment === null) {
                abort(404);
            }
        }

        return [
            'householdNo' => $hh,
            'memberId' => $mb,
            'assessmentId' => $id,
            'household' => $household,
            'member' => $member,
            'assessment' => $assessment,
            'eligible' => $this->isEligibleFromContext($ctx),
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function isEligibleFromContext(array $ctx): bool
    {
        if (($ctx['resident'] ?? null) instanceof Resident) {
            return RiskAssessmentService::isEligibleForRiskAssessment($ctx['resident']);
        }

        return RiskAssessmentService::isEligibleForRiskAssessment($ctx['member'] ?? null);
    }
}
