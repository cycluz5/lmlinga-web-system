<?php

namespace App\Http\Controllers\HealthRecords;

use App\Http\Controllers\Controller;
use App\Support\FamilyPlanningPdf;
use App\Support\HealthRecordsFamilyPlanning;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class FamilyPlanningSummaryController extends Controller
{
    public function index(): View
    {
        $rows = HealthRecordsFamilyPlanning::rows();
        $summary = HealthRecordsFamilyPlanning::summaryCounts($rows);

        return view('pages.health-records.family-planning', [
            'active' => 'family-planning',
            'pageTitle' => 'Family Planning',
            'pageSubtitle' => '',
            'rows' => $rows,
            'summary' => $summary,
            'zones' => HealthRecordsFamilyPlanning::zones(),
            'years' => HealthRecordsFamilyPlanning::years($rows),
            'totalUnfiltered' => count($rows),
        ]);
    }

    public function reportBuilder(Request $request): View
    {
        $zone = trim((string) $request->query('zone', 'all'));
        $year = trim((string) $request->query('year', 'all'));
        $month = trim((string) $request->query('month', 'all'));

        $rows = HealthRecordsFamilyPlanning::commodityExportRows();

        return view('pages.health-records.family-planning-report-builder', [
            'active' => 'family-planning',
            'pageTitle' => 'Family Planning Report',
            'pageSubtitle' => 'Filter by zone, year, or month, preview the commodities given, then export PDF.',
            'zones' => HealthRecordsFamilyPlanning::zones(),
            'years' => HealthRecordsFamilyPlanning::years($rows),
            'months' => HealthRecordsFamilyPlanning::monthOptions(),
            'reportRows' => $rows,
            'initialOptions' => [
                'zone' => $zone,
                'year' => $year,
                'month' => $month,
            ],
            'exportBase' => route('health-records.family-planning.export'),
            'dashboardUrl' => route('health-records.family-planning.index'),
        ]);
    }

    public function export(Request $request): Response
    {
        $zone = trim((string) $request->query('zone', 'all'));
        $year = trim((string) $request->query('year', 'all'));
        $month = trim((string) $request->query('month', 'all'));

        $rows = HealthRecordsFamilyPlanning::commodityExportRows(
            $zone !== 'all' ? $zone : null,
            $year !== 'all' ? $year : null,
            $month !== 'all' ? $month : null,
        );

        $periodLabel = HealthRecordsFamilyPlanning::periodLabel($year, $month);
        $generatedAt = now()->timezone((string) config('app.timezone'));

        return FamilyPlanningPdf::response($rows, $periodLabel, $generatedAt);
    }
}
