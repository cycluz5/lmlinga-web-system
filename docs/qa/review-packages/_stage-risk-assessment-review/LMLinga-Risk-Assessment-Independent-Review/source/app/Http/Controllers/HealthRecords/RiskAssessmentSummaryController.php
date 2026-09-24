<?php

namespace App\Http\Controllers\HealthRecords;

use App\Http\Controllers\Controller;
use App\Support\HealthRecordsRiskAssessment;
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
            'totalUnfiltered' => count($rows),
        ]);
    }
}
