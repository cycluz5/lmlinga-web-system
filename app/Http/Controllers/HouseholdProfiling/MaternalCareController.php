<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaternalPregnancyRequest;
use App\Http\Requests\UpdateMaternalCareSectionRequest;
use App\Support\DemoMaternalCare;
use App\Support\HealthMemberIdentity;
use App\Support\MaternalCareEligibility;
use App\Support\MaternalPregnancyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MaternalCareController extends Controller
{
    public function __construct(
        private readonly HealthMemberIdentity $identity,
        private readonly MaternalPregnancyService $service,
    ) {}

    public function index(string $householdNo, string $memberId): View
    {
        $ctx = $this->resolveEligible($householdNo, $memberId);
        $canPersist = $this->canPersist($ctx);
        [, $history] = $this->loadActiveAndHistory($ctx, $canPersist);
        $active = $this->loadContinuingEpisode($ctx, $canPersist);

        $mode = $active ? 'overview' : 'landing';

        return view('pages.household-profiling.maternal-care', [
            'active' => 'household-profiling',
            'pageTitle' => 'Maternal Care',
            'pageSubtitle' => $ctx['member']
                ? 'Maternal care for '.$ctx['member']['name'].' in '.$ctx['householdNo'].'.'
                : 'No record found.',
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
            'demoHousehold' => $ctx['household'],
            'demoMember' => $ctx['member'],
            'mcMode' => $mode,
            'pregnancy' => $active,
            'history' => $history,
            'hasClosedEpisode' => $this->hasClosedEpisode($ctx, $canPersist),
            'section' => null,
            'canPersist' => $canPersist,
            'mcWriteEligible' => MaternalCareEligibility::allowsWorkflowMemberContext($ctx),
        ]);
    }

    public function register(string $householdNo, string $memberId): View|RedirectResponse
    {
        $ctx = $this->resolveEligible($householdNo, $memberId);
        $this->rejectIfWorkflowIneligible($ctx);
        $canPersist = $this->canPersist($ctx);
        [$active, $history] = $this->loadActiveAndHistory($ctx, $canPersist);

        if ($ctx['member'] && $active) {
            return redirect()->route('household-profiling.members.maternal-care.index', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ]);
        }

        return view('pages.household-profiling.maternal-care', [
            'active' => 'household-profiling',
            'pageTitle' => 'Maternal Care',
            'pageSubtitle' => $ctx['member']
                ? 'Register maternal record for '.$ctx['member']['name'].'.'
                : 'No record found.',
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
            'demoHousehold' => $ctx['household'],
            'demoMember' => $ctx['member'],
            'mcMode' => 'register',
            'pregnancy' => null,
            'history' => $history,
            'section' => null,
            'canPersist' => $canPersist,
            'mcWriteEligible' => true,
        ]);
    }

    public function store(
        StoreMaternalPregnancyRequest $request,
        string $householdNo,
        string $memberId
    ): RedirectResponse {
        $ctx = $this->resolveEligible($householdNo, $memberId);
        $this->rejectIfWorkflowIneligible($ctx);
        if (! $ctx['member']) {
            return redirect()->route('household-profiling.members.maternal-care.index', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ]);
        }

        $canPersist = $this->canPersist($ctx);
        [$active] = $this->loadActiveAndHistory($ctx, $canPersist);
        if ($active) {
            return redirect()->route('household-profiling.members.maternal-care.index', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ]);
        }

        if ($canPersist) {
            $persisted = $this->identity->resolvePersistedOrFail($householdNo, $memberId);
            $this->service->createForResident($persisted['resident'], $request->pregnancyPayload());

            return redirect()
                ->route('household-profiling.members.maternal-care.index', [
                    'householdNo' => $persisted['householdNo'],
                    'memberId' => $persisted['memberId'],
                ])
                ->with('status', 'Maternal record saved.');
        }

        abort(404, 'Resident was not found.');
    }

    public function history(string $householdNo, string $memberId): View
    {
        return $this->sectionView($householdNo, $memberId, 'history');
    }

    public function historyShow(string $householdNo, string $memberId, string $pregnancyId): View|RedirectResponse
    {
        $ctx = $this->resolveEligible($householdNo, $memberId);
        $canPersist = $this->canPersist($ctx);
        [$active, $history] = $this->loadActiveAndHistory($ctx, $canPersist);
        $record = $this->historicalRecord($ctx, $canPersist, $pregnancyId);

        if ($record === null) {
            return redirect()->route('household-profiling.members.maternal-care.history', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ]);
        }

        return view('pages.household-profiling.maternal-care', [
            'active' => 'household-profiling',
            'pageTitle' => 'Maternal Care',
            'pageSubtitle' => $ctx['member']
                ? 'Past maternal record for '.$ctx['member']['name'].' in '.$ctx['householdNo'].'.'
                : 'No record found.',
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
            'demoHousehold' => $ctx['household'],
            'demoMember' => $ctx['member'],
            'mcMode' => 'history-show',
            'pregnancy' => $record,
            'history' => $history,
            'section' => 'history-show',
            'canPersist' => $canPersist,
            'readOnly' => true,
            'mcWriteEligible' => MaternalCareEligibility::allowsWorkflowMemberContext($ctx),
        ]);
    }

    public function transOut(string $householdNo, string $memberId): View|RedirectResponse
    {
        return $this->requireActiveSection($householdNo, $memberId, 'trans-out');
    }

    public function prenatal(string $householdNo, string $memberId): View|RedirectResponse
    {
        return $this->requireActiveSection($householdNo, $memberId, 'prenatal');
    }

    public function immunizations(string $householdNo, string $memberId): View|RedirectResponse
    {
        return $this->requireActiveSection($householdNo, $memberId, 'immunizations');
    }

    public function supplementations(string $householdNo, string $memberId): View|RedirectResponse
    {
        return $this->requireActiveSection($householdNo, $memberId, 'supplementations');
    }

    public function laboratory(string $householdNo, string $memberId): View|RedirectResponse
    {
        return $this->requireActiveSection($householdNo, $memberId, 'laboratory');
    }

    public function delivery(string $householdNo, string $memberId): View|RedirectResponse
    {
        return $this->requireActiveSection($householdNo, $memberId, 'delivery');
    }

    public function postnatal(string $householdNo, string $memberId): View|RedirectResponse
    {
        return $this->requireActiveSection($householdNo, $memberId, 'postnatal');
    }

    public function updateSection(
        UpdateMaternalCareSectionRequest $request,
        string $householdNo,
        string $memberId,
        string $section
    ): RedirectResponse {
        $ctx = $this->resolveEligible($householdNo, $memberId);
        $this->rejectIfWorkflowIneligible($ctx);
        $sectionKey = strtolower(trim($section));
        $allowed = [
            'prenatal',
            'immunizations',
            'supplementations',
            'laboratory',
            'delivery',
            'postnatal',
            'trans-out',
        ];

        if (! in_array($sectionKey, $allowed, true) || ! $ctx['member']) {
            return redirect()->route('household-profiling.members.maternal-care.index', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ]);
        }

        $canPersist = $this->canPersist($ctx);
        $payload = $request->sectionPayloadForMerge();

        if ($canPersist) {
            $persisted = $this->identity->resolvePersistedOrFail($householdNo, $memberId);
            $updated = $this->service->updateSectionForResident(
                $persisted['resident'],
                $sectionKey,
                $payload
            );

            if ($updated === null) {
                return redirect()->route('household-profiling.members.maternal-care.index', [
                    'householdNo' => $persisted['householdNo'],
                    'memberId' => $persisted['memberId'],
                ]);
            }

            if ($sectionKey === 'trans-out') {
                return redirect()
                    ->route('household-profiling.members.maternal-care.history', [
                        'householdNo' => $persisted['householdNo'],
                        'memberId' => $persisted['memberId'],
                    ])
                    ->with('status', 'Maternal Care Trans-Out saved.');
            }

            return redirect()
                ->route($this->sectionRouteName($sectionKey), [
                    'householdNo' => $persisted['householdNo'],
                    'memberId' => $persisted['memberId'],
                ])
                ->with('status', 'Maternal Care section saved.');
        }

        abort(404, 'Resident was not found.');
    }

    private function requireActiveSection(
        string $householdNo,
        string $memberId,
        string $section
    ): View|RedirectResponse {
        $ctx = $this->resolveEligible($householdNo, $memberId);
        $canPersist = $this->canPersist($ctx);
        $writeEligible = MaternalCareEligibility::allowsWorkflowMemberContext($ctx);
        $episode = $this->loadSectionEpisode($ctx, $canPersist, $section);

        if (! $episode) {
            return redirect()->route('household-profiling.members.maternal-care.index', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ]);
        }

        return $this->sectionView($householdNo, $memberId, $section, $episode, ! $writeEligible);
    }

    /**
     * @param  array{
     *     source: 'db'|'demo'|null,
     *     household: array<string, mixed>|null,
     *     member: array<string, mixed>|null,
     *     householdNo: string,
     *     memberId: string,
     *     resident: \App\Models\Resident|null,
     *     householdModel: \App\Models\Household|null
     * }  $ctx
     * @return array<string, mixed>|null
     */
    private function loadSectionEpisode(array $ctx, bool $canPersist, string $section): ?array
    {
        if (! $ctx['member']) {
            return null;
        }

        if ($canPersist && $ctx['resident'] !== null) {
            return $this->service->writableSectionPresentationForResident($ctx['resident'], $section);
        }

        return DemoMaternalCare::writableSectionPregnancy($ctx['householdNo'], $ctx['memberId'], $section);
    }

    private function sectionView(
        string $householdNo,
        string $memberId,
        string $section,
        ?array $active = null,
        bool $forceReadOnly = false,
    ): View {
        $ctx = $this->resolveEligible($householdNo, $memberId);
        $canPersist = $this->canPersist($ctx);
        $writeEligible = MaternalCareEligibility::allowsWorkflowMemberContext($ctx);
        [$loadedActive, $history] = $this->loadActiveAndHistory($ctx, $canPersist);
        $pregnancy = $active ?? $loadedActive;

        return view('pages.household-profiling.maternal-care', [
            'active' => 'household-profiling',
            'pageTitle' => 'Maternal Care',
            'pageSubtitle' => $ctx['member']
                ? 'Maternal care for '.$ctx['member']['name'].' in '.$ctx['householdNo'].'.'
                : 'No record found.',
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
            'demoHousehold' => $ctx['household'],
            'demoMember' => $ctx['member'],
            'mcMode' => $section,
            'pregnancy' => $pregnancy,
            'history' => $history,
            'section' => $section,
            'canPersist' => $canPersist,
            'readOnly' => $forceReadOnly || ! $writeEligible,
            'mcWriteEligible' => $writeEligible,
        ]);
    }

    /**
     * @param  array{
     *     source: 'db'|'demo'|null,
     *     household: array<string, mixed>|null,
     *     member: array<string, mixed>|null,
     *     householdNo: string,
     *     memberId: string,
     *     resident: \App\Models\Resident|null,
     *     householdModel: \App\Models\Household|null
     * }  $ctx
     * @return array{0: array<string, mixed>|null, 1: list<array<string, mixed>>}
     */
    private function loadActiveAndHistory(array $ctx, bool $canPersist): array
    {
        if (! $ctx['member']) {
            return [null, []];
        }

        if ($canPersist && $ctx['resident'] !== null) {
            return [
                $this->service->activePresentationForResident($ctx['resident']),
                $this->service->historyRowsForResident($ctx['resident']),
            ];
        }

        return [
            DemoMaternalCare::activePregnancy($ctx['householdNo'], $ctx['memberId']),
            DemoMaternalCare::history($ctx['householdNo'], $ctx['memberId']),
        ];
    }

    /**
     * Active pregnancy, or a just-completed one still inside the Pregnancy
     * History gate window — keeps the journey (and Postnatal Care) reachable
     * from the main entry point instead of dropping straight to "landing".
     *
     * @param  array{
     *     member: array<string, mixed>|null,
     *     resident: \App\Models\Resident|null,
     *     householdNo: string,
     *     memberId: string
     * }  $ctx
     * @return array<string, mixed>|null
     */
    private function loadContinuingEpisode(array $ctx, bool $canPersist): ?array
    {
        if (! $ctx['member']) {
            return null;
        }

        if ($canPersist && $ctx['resident'] !== null) {
            return $this->service->activeOrContinuingPresentationForResident($ctx['resident']);
        }

        return DemoMaternalCare::activeOrContinuingPregnancy($ctx['householdNo'], $ctx['memberId']);
    }

    /**
     * @param  array{
     *     source: 'db'|'demo'|null,
     *     householdNo: string,
     *     memberId: string,
     *     resident: \App\Models\Resident|null
     * }  $ctx
     * @return array<string, mixed>|null
     */
    private function historicalRecord(array $ctx, bool $canPersist, string $pregnancyId): ?array
    {
        if (! $ctx['member']) {
            return null;
        }

        if ($canPersist && $ctx['resident'] !== null) {
            return $this->service->historicalPresentationForResident($ctx['resident'], $pregnancyId);
        }

        return DemoMaternalCare::historicalPregnancy($ctx['householdNo'], $ctx['memberId'], $pregnancyId);
    }

    /**
     * @return array{
     *     source: 'db'|'demo'|null,
     *     household: array<string, mixed>|null,
     *     member: array<string, mixed>|null,
     *     householdNo: string,
     *     memberId: string,
     *     resident: \App\Models\Resident|null,
     *     householdModel: \App\Models\Household|null
     * }
     */
    private function resolveEligible(string $householdNo, string $memberId): array
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);

        if (! MaternalCareEligibility::allowsMemberContext($ctx)) {
            abort(403, 'Maternal Care is only available for female residents.');
        }

        return $ctx;
    }

    /**
     * @param  array{
     *     member: array<string, mixed>|null,
     *     resident: \App\Models\Resident|null
     * }  $ctx
     */
    private function rejectIfWorkflowIneligible(array $ctx): void
    {
        if ($ctx['member'] === null && ($ctx['resident'] ?? null) === null) {
            return;
        }

        if (! MaternalCareEligibility::allowsWorkflowMemberContext($ctx)) {
            abort(403, MaternalCareEligibility::WORKFLOW_INELIGIBLE_MESSAGE);
        }
    }

    /**
     * @param  array{source: 'db'|'demo'|null, resident: \App\Models\Resident|null}  $ctx
     */
    private function canPersist(array $ctx): bool
    {
        return HealthMemberIdentity::canQueueWrites($ctx);
    }

    private function sectionRouteName(string $section): string
    {
        return match ($section) {
            'prenatal' => 'household-profiling.members.maternal-care.prenatal',
            'immunizations' => 'household-profiling.members.maternal-care.immunizations',
            'supplementations' => 'household-profiling.members.maternal-care.supplementations',
            'laboratory' => 'household-profiling.members.maternal-care.laboratory',
            'delivery' => 'household-profiling.members.maternal-care.delivery',
            'postnatal' => 'household-profiling.members.maternal-care.postnatal',
            default => 'household-profiling.members.maternal-care.index',
        };
    }

    /**
     * Closed episode existence is independent of the 42-day history visibility gate.
     *
     * @param  array{
     *     member: array<string, mixed>|null,
     *     resident: \App\Models\Resident|null,
     *     householdNo: string,
     *     memberId: string
     * }  $ctx
     */
    private function hasClosedEpisode(array $ctx, bool $canPersist): bool
    {
        if (! $ctx['member']) {
            return false;
        }

        if ($canPersist && $ctx['resident'] !== null) {
            return $this->service->hasClosedEpisodeForResident($ctx['resident']);
        }

        return DemoMaternalCare::hasClosedPregnancy($ctx['householdNo'], $ctx['memberId']);
    }
}
