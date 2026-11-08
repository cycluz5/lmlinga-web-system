<?php

namespace App\Http\Controllers\HealthRecords;

use App\Http\Controllers\Controller;
use App\Support\ChildCarePdf;
use App\Support\DewormingMonitoringService;
use App\Support\DewormingPdf;
use App\Support\HealthRecordsChildCare;
use App\Support\HealthRecordsDeworming;
use App\Support\HealthRecordsOperationTimbang;
use App\Support\HealthRecordsVitaminA;
use App\Support\OperationTimbangMonitoringService;
use App\Support\OperationTimbangPdf;
use App\Support\VitaminAPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    public function reportBuilder(Request $request): View
    {
        $zone = trim((string) $request->query('zone', 'all'));
        $ageBand = trim((string) $request->query('age', 'all'));
        $ageMin = trim((string) $request->query('age_min', ''));
        $ageMax = trim((string) $request->query('age_max', ''));
        $sex = trim((string) $request->query('sex', 'all'));

        $rows = HealthRecordsChildCare::rows();

        return view('pages.health-records.child-care-report-builder', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care Report',
            'pageSubtitle' => 'Filter by zone, age, or sex, preview the child care records, then export PDF.',
            'zones' => HealthRecordsChildCare::zonesFromRows($rows),
            'ageFilterOptions' => HealthRecordsChildCare::ageFilterOptions(),
            'maxAgeYears' => HealthRecordsChildCare::MAX_AGE_YEARS,
            'reportRows' => $rows,
            'initialOptions' => [
                'zone' => $zone,
                'age' => $ageBand,
                'age_min' => $ageMin,
                'age_max' => $ageMax,
                'sex' => $sex,
            ],
            'exportBase' => route('health-records.child-care.export'),
            'dashboardUrl' => route('health-records.child-care.index'),
        ]);
    }

    public function export(Request $request): Response
    {
        $zone = trim((string) $request->query('zone', 'all'));
        $ageBand = trim((string) $request->query('age', 'all'));
        $ageMin = trim((string) $request->query('age_min', ''));
        $ageMax = trim((string) $request->query('age_max', ''));
        $sex = trim((string) $request->query('sex', 'all'));

        $rows = HealthRecordsChildCare::exportRows(
            $zone !== 'all' ? $zone : null,
            $ageBand !== '' ? $ageBand : 'all',
            $ageMin !== '' ? (int) $ageMin : null,
            $ageMax !== '' ? (int) $ageMax : null,
            $sex !== 'all' ? $sex : null,
        );

        $scopeLabel = HealthRecordsChildCare::scopeLabel(
            $ageBand !== '' ? $ageBand : 'all',
            $sex !== 'all' ? $sex : null,
            $ageMin !== '' ? (int) $ageMin : null,
            $ageMax !== '' ? (int) $ageMax : null,
        );
        $generatedAt = now()->timezone((string) config('app.timezone'));

        return ChildCarePdf::response($rows, $scopeLabel, $generatedAt);
    }

    public function vitaminA(Request $request): View
    {
        $zone = trim((string) $request->query('zone', ''));
        $zoneFilter = $zone !== '' && $zone !== 'all' ? $zone : null;
        $year = trim((string) $request->query('year', 'all'));
        $month = trim((string) $request->query('month', 'all'));
        $yearFilter = $year !== '' && $year !== 'all' ? $year : null;
        $monthFilter = $month !== '' && $month !== 'all' ? $month : null;

        return view('pages.health-records.child-care-vitamin-a', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care',
            'pageSubtitle' => 'Vitamin A supplementation monitoring summary.',
            'rows' => HealthRecordsVitaminA::monitoringRows($zoneFilter, $yearFilter, $monthFilter),
            'zones' => HealthRecordsVitaminA::zones(),
            'years' => HealthRecordsVitaminA::years(),
            'months' => HealthRecordsVitaminA::monthOptions(),
            'selectedZone' => $zoneFilter ?? 'all',
            'selectedYear' => $year !== '' ? $year : 'all',
            'selectedMonth' => $month !== '' ? $month : 'all',
        ]);
    }

    public function vitaminAReportBuilder(Request $request): View
    {
        $zone = trim((string) $request->query('zone', 'all'));
        $year = trim((string) $request->query('year', 'all'));
        $month = trim((string) $request->query('month', 'all'));
        $zoneFilter = $zone !== '' && $zone !== 'all' ? $zone : null;
        $yearFilter = $year !== '' && $year !== 'all' ? $year : null;
        $monthFilter = $month !== '' && $month !== 'all' ? $month : null;

        return view('pages.health-records.child-care-vitamin-a-report-builder', [
            'active' => 'child-care',
            'pageTitle' => 'Vitamin A Monitoring Report',
            'pageSubtitle' => 'Filter by zone, year, or month, preview the coverage table, then export PDF.',
            'zones' => HealthRecordsVitaminA::zones(),
            'years' => HealthRecordsVitaminA::years(),
            'months' => HealthRecordsVitaminA::monthOptions(),
            'rows' => HealthRecordsVitaminA::monitoringRows($zoneFilter, $yearFilter, $monthFilter),
            'initialOptions' => [
                'zone' => $zoneFilter ?? 'all',
                'year' => $year !== '' ? $year : 'all',
                'month' => $month !== '' ? $month : 'all',
            ],
            'exportBase' => route('health-records.child-care.vitamin-a.export'),
            'dashboardUrl' => route('health-records.child-care.vitamin-a'),
        ]);
    }

    public function exportVitaminA(Request $request): Response
    {
        $zone = trim((string) $request->query('zone', ''));
        $zoneFilter = $zone !== '' && $zone !== 'all' ? $zone : null;
        $year = trim((string) $request->query('year', 'all'));
        $month = trim((string) $request->query('month', 'all'));
        $yearFilter = $year !== '' && $year !== 'all' ? $year : null;
        $monthFilter = $month !== '' && $month !== 'all' ? $month : null;

        $rows = HealthRecordsVitaminA::monitoringRows($zoneFilter, $yearFilter, $monthFilter);
        $scopeLabel = ($zoneFilter !== null ? 'Zone: '.$zoneFilter : 'All Zones')
            .' · '.HealthRecordsVitaminA::periodLabel($year, $month);
        $generatedAt = now()->timezone((string) config('app.timezone'));

        return VitaminAPdf::response($rows, $scopeLabel, $generatedAt);
    }

    public function deworming(): View
    {
        $service = app(DewormingMonitoringService::class);
        $year = $service->currentMonitoringYear();
        $rows = $service->monitoringRowsForYear($year);

        return view('pages.health-records.child-care-deworming', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care',
            'pageSubtitle' => 'Deworming monitoring summary.',
            'rows' => $rows,
            'summary' => $service->summaryCardsForRows($rows),
            'zones' => $service->zonesForRows($rows),
            'statusFilterOptions' => HealthRecordsDeworming::statusFilterOptions(),
        ]);
    }

    public function dewormingReportBuilder(Request $request): View
    {
        $zone = trim((string) $request->query('zone', 'all'));
        $sex = trim((string) $request->query('sex', 'all'));
        $status = trim((string) $request->query('status', 'all'));

        return view('pages.health-records.child-care-deworming-report-builder', [
            'active' => 'child-care',
            'pageTitle' => 'Deworming Report',
            'pageSubtitle' => 'Filter by zone, sex, or status, preview the deworming records, then export PDF.',
            'zones' => HealthRecordsDeworming::zones(),
            'statusFilterOptions' => HealthRecordsDeworming::statusFilterOptions(),
            'reportRows' => HealthRecordsDeworming::monitoringRows(),
            'initialOptions' => [
                'zone' => $zone,
                'sex' => $sex,
                'status' => $status,
            ],
            'exportBase' => route('health-records.child-care.deworming.export'),
            'dashboardUrl' => route('health-records.child-care.deworming'),
        ]);
    }

    public function exportDeworming(Request $request): Response
    {
        $zone = trim((string) $request->query('zone', 'all'));
        $sex = trim((string) $request->query('sex', 'all'));
        $status = trim((string) $request->query('status', 'all'));

        $rows = HealthRecordsDeworming::exportRows(
            $zone !== 'all' ? $zone : null,
            $sex !== 'all' ? $sex : null,
            $status !== 'all' ? $status : null,
        );

        $year = app(DewormingMonitoringService::class)->currentMonitoringYear();
        $scopeLabel = ($zone !== 'all' ? 'Zone: '.$zone : 'All Zones').' · Year '.$year;
        $generatedAt = now()->timezone((string) config('app.timezone'));

        return DewormingPdf::response($rows, $scopeLabel, $generatedAt);
    }

    public function dewormingShow(string $childKey): RedirectResponse|View
    {
        $canonicalUrl = HealthRecordsDeworming::resolveCanonicalMemberDewormingUrl($childKey);
        if ($canonicalUrl !== null) {
            return redirect()->to($canonicalUrl);
        }

        return view('pages.health-records.child-care-deworming-show', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care | Deworming',
            'pageSubtitle' => 'Deworming record for the selected member.',
            'child' => null,
            'childKey' => $childKey,
            'canAddRecord' => false,
            'records' => [],
        ]);
    }

    /**
     * Legacy Health Records Deworming create URL.
     * Individual Add Record belongs on Household Profiling; this named route
     * is retained for compatibility but must not render the Add form.
     */
    public function dewormingCreate(string $childKey): RedirectResponse
    {
        return redirect()->route('health-records.child-care.deworming');
    }

    public function operationTimbang(Request $request): View
    {
        $service = app(OperationTimbangMonitoringService::class);
        $selectedYear = $service->resolveYear(
            $request->filled('year') ? (int) $request->query('year') : null
        );
        $selectedMonth = $service->resolveMonth(
            $request->filled('month') ? (int) $request->query('month') : null
        );

        $rows = HealthRecordsOperationTimbang::monitoringRows($selectedYear, $selectedMonth);

        return view('pages.health-records.child-care-operation-timbang', [
            'active' => 'child-care',
            'pageTitle' => 'Child Care',
            'pageSubtitle' => 'Operation Timbang weigh-in monitoring summary.',
            'rows' => $rows,
            'summary' => $service->summaryCardsForYearMonth($selectedYear, $selectedMonth, $rows),
            'zones' => HealthRecordsOperationTimbang::zones(),
            'statusFilterOptions' => HealthRecordsOperationTimbang::statusFilterOptions(),
            'monthSessions' => HealthRecordsOperationTimbang::monthSessions($selectedYear),
            'years' => HealthRecordsOperationTimbang::years(),
            'selectedYear' => $selectedYear,
            'selectedMonth' => $selectedMonth,
        ]);
    }

    public function operationTimbangReportBuilder(Request $request): View
    {
        $service = app(OperationTimbangMonitoringService::class);
        $selectedYear = $service->resolveYear(
            $request->filled('year') ? (int) $request->query('year') : null
        );
        $selectedMonth = $service->resolveMonth(
            $request->filled('month') ? (int) $request->query('month') : null
        );
        $zone = trim((string) $request->query('zone', 'all'));
        $sex = trim((string) $request->query('sex', 'all'));
        $status = trim((string) $request->query('status', 'all'));
        $rows = HealthRecordsOperationTimbang::monitoringRows($selectedYear, $selectedMonth);

        return view('pages.health-records.child-care-operation-timbang-report-builder', [
            'active' => 'child-care',
            'pageTitle' => 'Operation Timbang Report',
            'pageSubtitle' => 'Filter by session, zone, sex, or status, preview the weigh-in records, then export PDF.',
            'zones' => HealthRecordsOperationTimbang::zones(),
            'statusFilterOptions' => HealthRecordsOperationTimbang::statusFilterOptions(),
            'years' => HealthRecordsOperationTimbang::years(),
            'reportRows' => $rows,
            'summary' => $service->summaryCardsForYearMonth($selectedYear, $selectedMonth, $rows),
            'initialOptions' => [
                'year' => $selectedYear,
                'month' => $selectedMonth,
                'zone' => $zone,
                'sex' => $sex,
                'status' => $status,
            ],
            'exportBase' => route('health-records.child-care.operation-timbang.export'),
            'dashboardUrl' => route('health-records.child-care.operation-timbang'),
        ]);
    }

    public function exportOperationTimbang(Request $request): Response
    {
        $service = app(OperationTimbangMonitoringService::class);
        $selectedYear = $service->resolveYear(
            $request->filled('year') ? (int) $request->query('year') : null
        );
        $selectedMonth = $service->resolveMonth(
            $request->filled('month') ? (int) $request->query('month') : null
        );
        $zone = trim((string) $request->query('zone', 'all'));
        $sex = trim((string) $request->query('sex', 'all'));
        $status = trim((string) $request->query('status', 'all'));

        $rows = HealthRecordsOperationTimbang::exportRows(
            $selectedYear,
            $selectedMonth,
            $zone !== 'all' ? $zone : null,
            $sex !== 'all' ? $sex : null,
            $status !== 'all' ? $status : null,
        );

        $sessionLabel = HealthRecordsOperationTimbang::sessionLabel($selectedYear, $selectedMonth);
        $scopeLabel = ($zone !== 'all' ? 'Zone: '.$zone : 'All Zones').' · '.$sessionLabel;
        $generatedAt = now()->timezone((string) config('app.timezone'));

        // The summary cards reflect the whole session, not the zone/sex/
        // status filters applied to the data table — same behavior as the
        // on-screen page and the Report Builder preview.
        $summary = HealthRecordsOperationTimbang::summaryCards($selectedYear, $selectedMonth);

        return OperationTimbangPdf::response($rows, $scopeLabel, $generatedAt, $summary, $sessionLabel);
    }
}
