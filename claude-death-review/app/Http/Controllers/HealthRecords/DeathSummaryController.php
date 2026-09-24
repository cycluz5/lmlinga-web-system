<?php

namespace App\Http\Controllers\HealthRecords;

use App\Http\Controllers\Controller;
use App\Models\DeathRequest;
use App\Support\HealthRecordsDeath;
use Illuminate\View\View;

class DeathSummaryController extends Controller
{
    public function index(): View
    {
        $rows = HealthRecordsDeath::listingRows();
        $approvedRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['status'] ?? '') === DeathRequest::STATUS_APPROVED
        ));
        $summary = HealthRecordsDeath::summaryCounts($approvedRows);
        $residents = HealthRecordsDeath::residentCandidates();

        return view('pages.health-records.death', [
            'active' => 'death',
            'pageTitle' => 'Death',
            'pageSubtitle' => 'Submit death records for Admin verification and monitor approved mortality status.',
            'rows' => $rows,
            'summary' => $summary,
            'zones' => HealthRecordsDeath::zones(),
            'causes' => HealthRecordsDeath::causes($rows),
            'years' => HealthRecordsDeath::years($rows),
            'totalUnfiltered' => count($rows),
            'residents' => $residents,
        ]);
    }
}
