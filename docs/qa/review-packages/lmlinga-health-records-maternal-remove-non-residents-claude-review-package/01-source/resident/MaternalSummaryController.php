<?php

namespace App\Http\Controllers\HealthRecords;

use App\Http\Controllers\Controller;
use App\Support\HealthRecordsMaternal;
use Illuminate\View\View;

class MaternalSummaryController extends Controller
{
    public function index(): View
    {
        $rows = HealthRecordsMaternal::rows();
        $summary = HealthRecordsMaternal::summaryCounts($rows);

        return view('pages.health-records.maternal', [
            'active' => 'maternal',
            'pageTitle' => 'Maternal Care',
            'pageSubtitle' => 'Record and management of maternal care details for monitoring and tracking maternal health status.',
            'rows' => $rows,
            'summary' => $summary,
            'zones' => HealthRecordsMaternal::zones(),
            'years' => HealthRecordsMaternal::years($rows),
            'totalUnfiltered' => count($rows),
        ]);
    }
}
