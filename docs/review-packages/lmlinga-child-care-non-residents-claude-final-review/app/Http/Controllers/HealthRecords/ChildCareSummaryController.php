<?php

namespace App\Http\Controllers\HealthRecords;

use App\Http\Controllers\Controller;
use App\Support\HealthRecordsChildCare;
use App\Support\HealthRecordsDeworming;
use App\Support\HealthRecordsOperationTimbang;
use App\Support\HealthRecordsVitaminA;
use Illuminate\View\View;

class ChildCareSummaryController extends Controller
{
    public function index(): View
    {
        $rows = HealthRecordsChildCare::rows();
        $summary = HealthRecordsChildCare::summaryCounts($rows);

        return view('pages.health-records.child-care', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care',
            'pageSubtitle' => 'Barangay-wide child care summary from household profiling records.',
            'rows' => $rows,
            'summary' => $summary,
            'zones' => HealthRecordsChildCare::zonesFromRows($rows),
            'ageFilterOptions' => HealthRecordsChildCare::ageFilterOptions(),
            'totalUnfiltered' => count($rows),
        ]);
    }

    public function vitaminA(): View
    {
        return view('pages.health-records.child-care-vitamin-a', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care',
            'pageSubtitle' => 'Vitamin A supplementation monitoring summary.',
            'rows' => HealthRecordsVitaminA::monitoringRows(),
            'zones' => HealthRecordsVitaminA::zones(),
        ]);
    }

    public function deworming(): View
    {
        return view('pages.health-records.child-care-deworming', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care',
            'pageSubtitle' => 'Deworming monitoring summary.',
            'rows' => HealthRecordsDeworming::monitoringRows(),
            'summary' => HealthRecordsDeworming::summaryCards(),
            'zones' => HealthRecordsDeworming::zones(),
            'statusFilterOptions' => HealthRecordsDeworming::statusFilterOptions(),
        ]);
    }

    public function operationTimbang(): View
    {
        $selectedYear = HealthRecordsOperationTimbang::DEFAULT_YEAR;
        $selectedMonth = HealthRecordsOperationTimbang::DEFAULT_MONTH;

        return view('pages.health-records.child-care-operation-timbang', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care',
            'pageSubtitle' => 'Operation Timbang weigh-in monitoring summary.',
            'rows' => HealthRecordsOperationTimbang::monitoringRows(),
            'summary' => HealthRecordsOperationTimbang::summaryCards(),
            'zones' => HealthRecordsOperationTimbang::zones(),
            'statusFilterOptions' => HealthRecordsOperationTimbang::statusFilterOptions(),
            'monthSessions' => HealthRecordsOperationTimbang::monthSessions($selectedYear),
            'years' => HealthRecordsOperationTimbang::years(),
            'selectedYear' => $selectedYear,
            'selectedMonth' => $selectedMonth,
        ]);
    }
}
