<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDewormingRecordRequest;
use App\Support\DewormingRecordService;
use App\Support\HealthMemberIdentity;
use Illuminate\Http\RedirectResponse;

class DewormingRecordController extends Controller
{
    public function __construct(
        private readonly HealthMemberIdentity $identity,
        private readonly DewormingRecordService $service,
    ) {}

    public function store(
        StoreDewormingRecordRequest $request,
        string $householdNo,
        string $memberId,
    ): RedirectResponse {
        $ctx = $this->identity->resolvePersistedOrFail($householdNo, $memberId);

        $this->service->createForResident($ctx['resident'], $request->validated());

        return redirect()
            ->route('household-profiling.members.child-nutrition', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ])
            ->with('status', 'Deworming record saved.')
            ->with('deworming_status', 'Deworming record saved.');
    }
}
