<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResidentRequest;
use App\Http\Requests\UpdateResidentRequest;
use App\Services\ResidentService;
use App\Support\DemoCatalog;
use App\Support\DemoMaternalCare;
use App\Support\HouseholdMemberResolver;
use App\Support\HouseholdProfilingPresenter;
use App\Support\MaternalPregnancyService;
use App\Support\Offline\OfflineFieldHasher;
use App\Support\Offline\OfflineLocalMemberId;
use App\Support\TimbangRecordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class HouseholdMemberController extends Controller
{
    public function __construct(
        private readonly HouseholdMemberResolver $resolver,
        private readonly ResidentService $residents,
        private readonly TimbangRecordService $timbang,
        private readonly MaternalPregnancyService $maternal,
    ) {}

    public function create(string $householdNo): View
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $resolved = $this->resolver->resolveHousehold($key);

        if ($resolved === null) {
            return view('pages.household-profiling.member-create', [
                'active' => 'household-profiling',
                'pageTitle' => 'Household Profiling',
                'pageSubtitle' => 'Household was not found.',
                'householdNo' => $key,
                'demoHousehold' => null,
                'householdSource' => null,
                'persistable' => false,
                'formMode' => 'create',
                'memberValues' => [],
            ]);
        }

        $household = $resolved['household']->load('residents');
        $presentation = HouseholdProfilingPresenter::fromModel($household);

        return view('pages.household-profiling.member-create', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => 'Add a new member to '.$key.'.',
            'householdNo' => $key,
            'demoHousehold' => $presentation,
            'householdSource' => 'db',
            'persistable' => true,
            'formMode' => 'create',
            'memberValues' => [],
            'offlineHouseholdPk' => (int) $household->getKey(),
        ]);
    }

    public function store(StoreResidentRequest $request, string $householdNo): RedirectResponse
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $household = $this->resolver->resolveDbHouseholdOrFail($key);

        $resident = $this->residents->create($household, $request->validated());

        return redirect()
            ->route('household-profiling.members.show', [
                'householdNo' => $key,
                'memberId' => $resident->member_no,
            ])
            ->with('status', 'Household member added successfully.');
    }

    public function show(string $householdNo, string $memberId): View
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = OfflineLocalMemberId::normalize($memberId);

        if (OfflineLocalMemberId::isLocal($memberKey)) {
            $householdResolved = $this->resolver->resolveHousehold($key);

            return view('pages.household-profiling.member-view', [
                'active' => 'household-profiling',
                'pageTitle' => 'Household Profiling',
                'pageSubtitle' => 'View queued member information for '.$key.'.',
                'householdNo' => $key,
                'memberId' => $memberKey,
                'demoHousehold' => $householdResolved['presentation'] ?? null,
                'demoMember' => OfflineLocalMemberId::presentation($memberKey),
                'householdSource' => $householdResolved['source'] ?? null,
                'offlineLocalMember' => true,
                'nutritionCard' => $this->timbang->emptyCardState(),
                'nutritionFromCatalog' => false,
                'maternalCareHasHistory' => false,
            ]);
        }

        $resolved = $this->resolver->resolveMember($key, $memberKey);
        $isDb = is_array($resolved)
            && ($resolved['source'] ?? null) === 'db'
            && ($resolved['resident'] ?? null) !== null;

        return view('pages.household-profiling.member-view', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => $resolved
                ? 'View member information for '.$key.'.'
                : 'Member was not found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => $resolved['householdPresentation'] ?? null,
            'demoMember' => $resolved['memberPresentation'] ?? null,
            'householdSource' => $resolved['source'] ?? null,
            'offlineLocalMember' => false,
            'offlineResidentPk' => $isDb ? (int) $resolved['resident']->getKey() : null,
            'nutritionCard' => $isDb
                ? $this->timbang->cardStateForResident($resolved['resident'])
                : null,
            'nutritionFromCatalog' => ! $isDb,
            'maternalCareHasHistory' => $this->maternalHistoryFlag($resolved, $isDb, $key, $memberKey),
        ]);
    }

    public function edit(string $householdNo, string $memberId): View
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = OfflineLocalMemberId::normalize($memberId);

        if (OfflineLocalMemberId::isLocal($memberKey)) {
            $householdResolved = $this->resolver->resolveHousehold($key);
            $memberPresentation = OfflineLocalMemberId::presentation($memberKey);

            return view('pages.household-profiling.member-edit', [
                'active' => 'household-profiling',
                'pageTitle' => 'Household Profiling',
                'pageSubtitle' => 'Edit queued member in '.$key.'.',
                'householdNo' => $key,
                'memberId' => $memberKey,
                'demoHousehold' => $householdResolved['presentation'] ?? null,
                'demoMember' => $memberPresentation,
                'householdSource' => $householdResolved['source'] ?? null,
                'persistable' => $householdResolved !== null,
                'formMode' => 'edit',
                'memberValues' => $memberPresentation,
                'offlineHouseholdPk' => $householdResolved !== null
                    ? (int) $householdResolved['household']->getKey()
                    : null,
                'offlineLocalMember' => true,
            ]);
        }

        try {
            $ctx = $this->resolver->resolveDbMemberOrFail($key, $memberKey);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return view('pages.household-profiling.member-edit', [
                'active' => 'household-profiling',
                'pageTitle' => 'Household Profiling',
                'pageSubtitle' => 'Member was not found.',
                'householdNo' => $key,
                'memberId' => $memberKey,
                'demoHousehold' => null,
                'demoMember' => null,
                'householdSource' => null,
                'persistable' => false,
                'formMode' => 'edit',
                'memberValues' => [],
            ]);
        }

        $household = $ctx['household']->load('residents');
        $resident = $ctx['resident'];
        $memberPresentation = HouseholdProfilingPresenter::memberFromModel($resident);

        return view('pages.household-profiling.member-edit', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => 'Edit member '.$memberKey.' in '.$key.'.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => HouseholdProfilingPresenter::fromModel($household),
            'demoMember' => $memberPresentation,
            'householdSource' => 'db',
            'persistable' => true,
            'formMode' => 'edit',
            'memberValues' => $memberPresentation,
            'offlineHouseholdPk' => (int) $household->getKey(),
            'offlineResidentPk' => (int) $resident->getKey(),
            'offlineFieldHash' => OfflineFieldHasher::resident($resident),
        ]);
    }

    public function update(
        UpdateResidentRequest $request,
        string $householdNo,
        string $memberId,
    ): RedirectResponse {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = DemoCatalog::normalizeMemberId($memberId);
        $ctx = $this->resolver->resolveDbMemberOrFail($key, $memberKey);

        $this->residents->update($ctx['resident'], $request->validated());

        return redirect()
            ->route('household-profiling.members.show', [
                'householdNo' => $key,
                'memberId' => $memberKey,
            ])
            ->with('status', 'Household member updated successfully.');
    }

    /**
     * @param  array<string, mixed>|null  $resolved
     */
    private function maternalHistoryFlag(?array $resolved, bool $isDb, string $householdNo, string $memberId): bool
    {
        if ($isDb && ($resolved['resident'] ?? null) !== null) {
            return $this->maternal->hasAnyEpisode($resolved['resident']);
        }

        if (($resolved['source'] ?? null) === 'demo') {
            return DemoMaternalCare::hasRecord($householdNo, $memberId);
        }

        return false;
    }
}
