<?php

namespace App\Http\Controllers\HealthRecords;

use App\Http\Controllers\Controller;
use App\Support\HealthRecordsMaternal;
use App\Support\MaternalCarePdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    public function reportBuilder(Request $request): View
    {
        $zone = trim((string) $request->query('zone', 'all'));
        $year = trim((string) $request->query('year', 'all'));
        $month = trim((string) $request->query('month', 'all'));

        $rows = HealthRecordsMaternal::rows();

        return view('pages.health-records.maternal-report-builder', [
            'active' => 'maternal',
            'pageTitle' => 'Maternal Care Report',
            'pageSubtitle' => 'Filter by zone, year, or month, preview the maternal care records, then export PDF.',
            'zones' => HealthRecordsMaternal::zones(),
            'years' => HealthRecordsMaternal::years($rows),
            'months' => HealthRecordsMaternal::monthOptions(),
            'reportRows' => HealthRecordsMaternal::detailedExportRows(),
            'columnPages' => HealthRecordsMaternal::columnPages(),
            'initialOptions' => [
                'zone' => $zone,
                'year' => $year,
                'month' => $month,
            ],
            'exportBase' => route('health-records.maternal.export'),
            'dashboardUrl' => route('health-records.maternal.index'),
        ]);
    }

    public function export(Request $request): Response
    {
        $zone = trim((string) $request->query('zone', 'all'));
        $year = trim((string) $request->query('year', 'all'));
        $month = trim((string) $request->query('month', 'all'));

        $rows = HealthRecordsMaternal::detailedExportRows(
            $zone !== 'all' ? $zone : null,
            $year !== 'all' ? $year : null,
            $month !== 'all' ? $month : null,
        );

        $periodLabel = HealthRecordsMaternal::periodLabel($year, $month);
        $generatedAt = now()->timezone((string) config('app.timezone'));

        return MaternalCarePdf::response($rows, $periodLabel, $generatedAt);
    }
}
