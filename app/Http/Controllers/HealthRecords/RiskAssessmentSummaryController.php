<?php

namespace App\Http\Controllers\HealthRecords;

use App\Http\Controllers\Controller;
use App\Support\HealthRecordsRiskAssessment;
use App\Support\RiskAssessmentPdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class RiskAssessmentSummaryController extends Controller
{
    public function index(): View
    {
        $rows = HealthRecordsRiskAssessment::rows();
        $summary = HealthRecordsRiskAssessment::summaryCounts($rows);

        return view('pages.health-records.risk-assessment', [
            'active' => 'risk-assessment',
            'pageTitle' => 'Risk Assessment',
            'pageSubtitle' => 'Record and management of risk assessment details for monitoring and tracking health risks.',
            'rows' => $rows,
            'summary' => $summary,
            'zones' => HealthRecordsRiskAssessment::zones(),
            'years' => HealthRecordsRiskAssessment::years($rows),
            'months' => HealthRecordsRiskAssessment::monthOptions(),
            'totalUnfiltered' => count($rows),
        ]);
    }

    public function reportBuilder(Request $request): View
    {
        $zone = trim((string) $request->query('zone', 'all'));
        $year = trim((string) $request->query('year', 'all'));
        $month = trim((string) $request->query('month', 'all'));

        $rows = HealthRecordsRiskAssessment::rows();

        return view('pages.health-records.risk-assessment-report-builder', [
            'active' => 'risk-assessment',
            'pageTitle' => 'Risk Assessment Report',
            'pageSubtitle' => 'Filter by zone, year, or month, preview the risk assessment records, then export PDF.',
            'zones' => HealthRecordsRiskAssessment::zones(),
            'years' => HealthRecordsRiskAssessment::years($rows),
            'months' => HealthRecordsRiskAssessment::monthOptions(),
            'reportRows' => HealthRecordsRiskAssessment::detailedExportRows(),
            'columnPages' => HealthRecordsRiskAssessment::columnPages(),
            'initialOptions' => [
                'zone' => $zone,
                'year' => $year,
                'month' => $month,
            ],
            'exportBase' => route('health-records.risk-assessment.export'),
            'dashboardUrl' => route('health-records.risk-assessment.index'),
        ]);
    }

    public function export(Request $request): Response
    {
        $zone = trim((string) $request->query('zone', 'all'));
        $year = trim((string) $request->query('year', 'all'));
        $month = trim((string) $request->query('month', 'all'));

        $rows = HealthRecordsRiskAssessment::detailedExportRows(
            $zone !== 'all' ? $zone : null,
            $year !== 'all' ? $year : null,
            $month !== 'all' ? $month : null,
        );

        $periodLabel = HealthRecordsRiskAssessment::periodLabel($year, $month);
        $generatedAt = now()->timezone((string) config('app.timezone'));

        return RiskAssessmentPdf::response($rows, $periodLabel, $generatedAt);
    }
}
