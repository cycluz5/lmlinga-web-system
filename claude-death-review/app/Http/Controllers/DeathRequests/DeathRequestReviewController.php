<?php

namespace App\Http\Controllers\DeathRequests;

use App\Http\Controllers\Controller;
use App\Http\Requests\RejectDeathRequestRequest;
use App\Models\DeathRequest;
use App\Support\DeathCertificateStorage;
use App\Support\DeathRecordService;
use App\Support\HealthRecordsDeath;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeathRequestReviewController extends Controller
{
    public function index(): View
    {
        $rows = DeathRequest::query()
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END")
            ->orderByDesc('submitted_at')
            ->get();

        return view('pages.death-requests.index', [
            'active' => 'death-requests',
            'pageTitle' => 'Death Requests',
            'pageSubtitle' => 'Review submitted death records before a resident is marked deceased.',
            'requests' => $rows,
            'zones' => HealthRecordsDeath::zones(),
        ]);
    }

    public function show(DeathRequest $deathRequest): View
    {
        return view('pages.death-requests.show', [
            'active' => 'death-requests',
            'pageTitle' => 'Verify Death Record',
            'pageSubtitle' => 'Review the submitted death record and supporting certificate.',
            'deathRequest' => $deathRequest,
            'certificateExists' => DeathCertificateStorage::exists($deathRequest),
        ]);
    }

    public function approve(DeathRequest $deathRequest, DeathRecordService $service): RedirectResponse
    {
        $service->approve($deathRequest);

        return redirect()
            ->route('death-requests.show', $deathRequest)
            ->with('status', 'Death record approved. The resident is now deceased.');
    }

    public function reject(
        RejectDeathRequestRequest $request,
        DeathRequest $deathRequest,
        DeathRecordService $service
    ): RedirectResponse {
        $service->reject($deathRequest, (string) $request->validated('rejection_reason'));

        return redirect()
            ->route('death-requests.show', $deathRequest)
            ->with('status', 'Death record rejected. The resident remains not deceased.');
    }

    public function certificate(DeathRequest $deathRequest): StreamedResponse
    {
        return DeathCertificateStorage::download($deathRequest);
    }
}
