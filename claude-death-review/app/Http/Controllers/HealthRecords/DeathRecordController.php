<?php

namespace App\Http\Controllers\HealthRecords;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeathRecordRequest;
use App\Models\DeathRequest;
use App\Support\DeathCertificateStorage;
use App\Support\DeathRecordService;
use App\Support\DemoDeath;
use App\Support\ResidentVitalStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeathRecordController extends Controller
{
    public function show(string $householdNo, string $memberId): View
    {
        $ctx = DemoDeath::resolveMember($householdNo, $memberId);
        $latest = $ctx['member']
            ? DeathRequest::latestForMember($ctx['householdNo'], $ctx['memberId'])
            : null;

        return $this->page($ctx, $latest);
    }

    public function store(
        StoreDeathRecordRequest $request,
        string $householdNo,
        string $memberId,
        DeathRecordService $service
    ): RedirectResponse {
        $ctx = DemoDeath::resolveMember($householdNo, $memberId);
        if (! $ctx['household'] || ! $ctx['member']) {
            abort(404, 'Resident was not found.');
        }

        $validated = $request->validated();
        $file = $request->file('death_certificate');
        if ($file === null) {
            return redirect()
                ->route('health-records.death.show', [
                    'householdNo' => $ctx['householdNo'],
                    'memberId' => $ctx['memberId'],
                ])
                ->withErrors(['death_certificate' => 'Death certificate file is required.']);
        }

        $service->submit($ctx['household'], $ctx['member'], $validated, $file);

        return redirect()
            ->route('health-records.death.show', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ])
            ->with('status', 'Death record submitted. Pending Admin verification. The resident is not yet deceased.');
    }

    public function certificate(string $householdNo, string $memberId): StreamedResponse
    {
        $ctx = DemoDeath::resolveMember($householdNo, $memberId);
        if (! $ctx['member']) {
            abort(404, 'Resident was not found.');
        }

        $record = DeathRequest::latestForMember($ctx['householdNo'], $ctx['memberId']);
        if ($record === null) {
            abort(404, 'Death certificate was not found.');
        }

        return DeathCertificateStorage::download($record);
    }

    /**
     * @param  array{household: array<string, mixed>|null, member: array<string, mixed>|null, householdNo: string, memberId: string}  $ctx
     */
    private function page(array $ctx, ?DeathRequest $latest): View
    {
        $member = $ctx['member'];
        $mode = 'missing';
        if ($member) {
            $mode = $this->modeFor($latest);
        }

        $vitalLabel = $member
            ? ResidentVitalStatus::label(
                $ctx['householdNo'],
                $ctx['memberId'],
                is_array($member) ? (string) ($member['relationship_status'] ?? '') : null
            )
            : null;

        return view('pages.health-records.death-record', [
            'active' => 'death',
            'pageTitle' => 'Death Record',
            'pageSubtitle' => $member
                ? 'Death record submission for '.$member['name'].'.'
                : 'Resident was not found.',
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
            'demoHousehold' => $ctx['household'],
            'demoMember' => $member,
            'deathRequest' => $latest,
            'deathMode' => $mode,
            'vitalLabel' => $vitalLabel,
            'isDeceased' => $member
                ? ResidentVitalStatus::isDeceased($ctx['householdNo'], $ctx['memberId'])
                : false,
        ]);
    }

    private function modeFor(?DeathRequest $latest): string
    {
        if ($latest === null || $latest->isRejected()) {
            return 'create';
        }

        if ($latest->isPending()) {
            return 'pending';
        }

        return 'approved';
    }
}
