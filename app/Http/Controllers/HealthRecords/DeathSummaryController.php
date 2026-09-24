<?php

namespace App\Http\Controllers\HealthRecords;

use App\Http\Controllers\Controller;
use App\Support\DeathRecordsPdf;
use App\Support\HealthRecordsDeath;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class DeathSummaryController extends Controller
{
    public function index(Request $request): View
    {
        $allRows = HealthRecordsDeath::listingRows();
        $filters = HealthRecordsDeath::listingFiltersFromRequest($request);
        $filteredRows = HealthRecordsDeath::filterRows($allRows, $filters);
        $summary = HealthRecordsDeath::summaryCounts($filteredRows);
        $records = HealthRecordsDeath::paginatedListing($request);

        return view('pages.health-records.death', [
            'active' => 'death',
            'pageTitle' => 'Death',
            'pageSubtitle' => 'Submit death records for Admin verification and monitor approved mortality status.',
            'records' => $records,
            'filters' => $filters,
            'summary' => $summary,
            'zones' => HealthRecordsDeath::zones(),
            'causes' => HealthRecordsDeath::causes($allRows),
            'years' => HealthRecordsDeath::years($allRows),
            'totalUnfiltered' => count($allRows),
        ]);
    }

    public function residents(): View
    {
        return view('pages.health-records.death-residents', [
            'active' => 'death',
            'pageTitle' => 'Select a resident',
            'pageSubtitle' => 'Choose a resident to open or submit a death record for Admin verification.',
            'residents' => HealthRecordsDeath::residentCandidates(),
            'zones' => HealthRecordsDeath::zones(),
        ]);
    }

    public function reportBuilder(Request $request): View
    {
        $zone = trim((string) $request->query('zone', 'all'));
        $year = trim((string) $request->query('year', 'all'));
        $month = trim((string) $request->query('month', 'all'));

        $rows = HealthRecordsDeath::verifiedExportRows();

        return view('pages.health-records.death-report-builder', [
            'active' => 'death',
            'pageTitle' => 'Death Records Report',
            'pageSubtitle' => 'Filter by zone, year, or month, preview the verified death records, then export PDF.',
            'zones' => HealthRecordsDeath::zones(),
            'years' => HealthRecordsDeath::years(),
            'months' => HealthRecordsDeath::monthOptions(),
            'reportRows' => $rows,
            'initialOptions' => [
                'zone' => $zone,
                'year' => $year,
                'month' => $month,
            ],
            'exportBase' => route('health-records.death.export'),
            'dashboardUrl' => route('health-records.death.index'),
        ]);
    }

    public function export(Request $request): Response
    {
        $zone = trim((string) $request->query('zone', 'all'));
        $year = trim((string) $request->query('year', 'all'));
        $month = trim((string) $request->query('month', 'all'));

        $rows = HealthRecordsDeath::verifiedExportRows(
            $zone !== 'all' ? $zone : null,
            $year !== 'all' ? $year : null,
            $month !== 'all' ? $month : null,
        );

        $periodLabel = HealthRecordsDeath::periodLabel($year, $month);
        $generatedAt = now()->timezone((string) config('app.timezone'));

        return DeathRecordsPdf::response($rows, $periodLabel, $generatedAt);
    }
}
