<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Models\DeathRequest;
use App\Support\DemoDeath;
use App\Support\HealthMemberIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeathController extends Controller
{
    public function __construct(
        private readonly HealthMemberIdentity $identity,
    ) {}

    public function index(string $householdNo, string $memberId): View
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);

        if ($this->isPersistedResident($ctx)) {
            $record = DeathRequest::latestForMember($ctx['householdNo'], $ctx['memberId']);

            return $this->page($ctx, $record === null ? 'empty' : 'view', null, $record);
        }

        $session = $ctx['member']
            ? DemoDeath::record($ctx['householdNo'], $ctx['memberId'])
            : null;

        return $this->page($ctx, $session ? 'view' : 'empty', $session, null);
    }

    public function create(string $householdNo, string $memberId): View|RedirectResponse
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);
        if (! $ctx['member']) {
            return $this->page($ctx, 'empty', null, null);
        }

        if ($this->isPersistedResident($ctx)) {
            return $this->redirectToHealthRecords($ctx);
        }

        if (DemoDeath::hasRecord($ctx['householdNo'], $ctx['memberId'])) {
            return redirect()->route('household-profiling.members.death.edit', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ]);
        }

        return $this->page($ctx, 'create', null, null);
    }

    public function store(Request $request, string $householdNo, string $memberId): RedirectResponse
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);
        if (! $ctx['member']) {
            return redirect()->route('household-profiling.members.death.index', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ]);
        }

        if ($this->isPersistedResident($ctx)) {
            return $this->redirectToHealthRecords($ctx);
        }

        if (DemoDeath::hasRecord($ctx['householdNo'], $ctx['memberId'])) {
            return $this->update($request, $ctx['householdNo'], $ctx['memberId']);
        }

        $validated = $this->validateDeath($request);
        DemoDeath::save(
            $ctx['householdNo'],
            $ctx['memberId'],
            $validated,
            $request->file('death_certificate')
        );

        return redirect()
            ->route('household-profiling.members.death.index', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ])
            ->with(
                'status',
                'Preview only: Death information was kept in this browser session and was not permanently saved.'
            );
    }

    public function edit(string $householdNo, string $memberId): View|RedirectResponse
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);
        if (! $ctx['member']) {
            return $this->page($ctx, 'empty', null, null);
        }

        if ($this->isPersistedResident($ctx)) {
            return $this->redirectToHealthRecords($ctx);
        }

        $record = DemoDeath::record($ctx['householdNo'], $ctx['memberId']);
        if (! $record) {
            return redirect()->route('household-profiling.members.death.create', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ]);
        }

        return $this->page($ctx, 'edit', $record, null);
    }

    public function update(Request $request, string $householdNo, string $memberId): RedirectResponse
    {
        $ctx = $this->identity->resolve($householdNo, $memberId);
        if (! $ctx['member']) {
            return redirect()->route('household-profiling.members.death.index', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ]);
        }

        if ($this->isPersistedResident($ctx)) {
            return $this->redirectToHealthRecords($ctx);
        }

        if (! DemoDeath::hasRecord($ctx['householdNo'], $ctx['memberId'])) {
            return redirect()->route('household-profiling.members.death.create', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ]);
        }

        $validated = $this->validateDeath($request);
        DemoDeath::save(
            $ctx['householdNo'],
            $ctx['memberId'],
            $validated,
            $request->file('death_certificate')
        );

        return redirect()
            ->route('household-profiling.members.death.index', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ])
            ->with(
                'status',
                'Preview only: Death information was kept in this browser session and was not permanently saved.'
            );
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
     */
    private function isPersistedResident(array $ctx): bool
    {
        return $ctx['source'] === 'db' && $ctx['resident'] !== null && $ctx['householdModel'] !== null;
    }

    /**
     * @param  array{householdNo: string, memberId: string}  $ctx
     */
    private function redirectToHealthRecords(array $ctx): RedirectResponse
    {
        return redirect()->route('health-records.death.show', [
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
        ]);
    }

    /**
     * @param  array{
     *     source: 'db'|'demo'|null,
     *     household: array<string, mixed>|null,
     *     member: array<string, mixed>|null,
     *     householdNo: string,
     *     memberId: string
     * }  $ctx
     * @param  array<string, mixed>|null  $sessionRecord
     */
    private function page(array $ctx, string $mode, ?array $sessionRecord, ?DeathRequest $deathRequest): View
    {
        $source = $ctx['source'] ?? null;

        return view('pages.household-profiling.death', [
            'active' => 'household-profiling',
            'pageTitle' => 'Death Information',
            'pageSubtitle' => $ctx['member']
                ? 'Death information for '.$ctx['member']['name'].' in '.$ctx['householdNo'].'.'
                : 'Member was not found.',
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
            'demoHousehold' => $ctx['household'],
            'demoMember' => $ctx['member'],
            'deathMode' => $mode,
            'deathRecord' => $sessionRecord,
            'deathRequest' => $deathRequest,
            'deathSource' => $source,
        ]);
    }

    /**
     * @return array{cause_of_death: string|null, date_of_death: string|null}
     */
    private function validateDeath(Request $request): array
    {
        /** @var array{cause_of_death: string|null, date_of_death: string|null} $validated */
        $validated = $request->validate([
            'cause_of_death' => ['nullable', 'string', 'max:500'],
            'date_of_death' => ['nullable', 'date', 'date_format:Y-m-d'],
            'death_certificate' => [
                'nullable',
                'file',
                'max:5120',
                'mimes:png,jpg,jpeg,pdf',
            ],
        ], [
            'death_certificate.mimes' => 'Death certificate must be a PNG, JPG, or PDF file.',
            'death_certificate.max' => 'Death certificate must be 5 MB or smaller.',
        ]);

        return $validated;
    }
}
