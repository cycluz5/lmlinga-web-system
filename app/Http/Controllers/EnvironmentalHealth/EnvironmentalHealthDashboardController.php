<?php

namespace App\Http\Controllers\EnvironmentalHealth;

use App\Http\Controllers\Controller;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\EnvironmentalHealthDashboard;
use App\Support\EnvironmentalHealthPdf;
use App\Support\EnvironmentalHealthReport;
use App\Support\EnvironmentalHealthReportHeader;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EnvironmentalHealthDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $filters = EnvironmentalHealthDashboard::normalizeFilters($request->query());
        $allRows = EnvironmentalHealthDashboard::rows();
        $filteredRows = EnvironmentalHealthDashboard::filterRows($allRows, $filters);
        $statistics = EnvironmentalHealthDashboard::statistics($filteredRows);

        return view('pages.environmental-health.index', [
            'active' => 'environmental-health',
            'pageTitle' => 'Environmental Health',
            'pageSubtitle' => 'Track household water supply, sanitation and waste management status.',
            'rows' => $allRows,
            'filteredCount' => count($filteredRows),
            'statistics' => $statistics,
            'filters' => $filters,
            'zones' => EnvironmentalHealthDashboard::zonesFromRows($allRows),
            'streets' => EnvironmentalHealthDashboard::streetsFromRows($allRows),
            'waterLevels' => [
                DemoHouseholdWaterSupply::WATER_LEVEL_I => 'Level I',
                DemoHouseholdWaterSupply::WATER_LEVEL_II => 'Level II',
                DemoHouseholdWaterSupply::WATER_LEVEL_III => 'Level III',
                DemoHouseholdWaterSupply::WATER_LEVEL_OTHERS => 'Others',
            ],
            'sanitationOptions' => [
                'with_toilet' => 'With Toilet',
                'without_toilet' => 'Without Toilet',
            ],
            'totalUnfiltered' => count($allRows),
            'reportBuilderUrl' => route('environmental-health.report-builder'),
        ]);
    }

    public function reportBuilder(Request $request): View
    {
        $allRows = EnvironmentalHealthDashboard::rows();
        $options = EnvironmentalHealthReport::normalizeOptions($request->query());
        $periods = EnvironmentalHealthReport::availablePeriods($allRows);
        $filtered = EnvironmentalHealthReport::filteredRows($options, $allRows);

        return view('pages.environmental-health.report-builder', [
            'active' => 'environmental-health',
            'pageTitle' => 'Environmental Health Report',
            'pageSubtitle' => 'Filter by zone and survey period, preview the report, then export PDF.',
            'zones' => EnvironmentalHealthDashboard::zonesFromRows($allRows),
            'reportOptions' => $options,
            'availablePeriods' => $periods,
            'reportColumns' => EnvironmentalHealthReport::columnDefinitions(),
            'reportGroupLabels' => EnvironmentalHealthReport::groupLabels(),
            'reportPreviewRows' => EnvironmentalHealthReport::previewPayload($allRows),
            'filteredCount' => count($filtered),
            'exportBase' => route('environmental-health.export'),
            'dashboardUrl' => route('environmental-health.index'),
        ]);
    }

    public function export(Request $request): StreamedResponse|Response
    {
        $options = EnvironmentalHealthReport::normalizeOptions($request->query());
        $format = strtolower(trim((string) $request->query('format', 'pdf')));
        if (! in_array($format, ['pdf', 'csv'], true)) {
            $format = 'pdf';
        }

        $rows = EnvironmentalHealthReport::filteredRows($options);
        $columns = EnvironmentalHealthReport::activeColumns($options);
        $generatedAt = now();
        $reportHeader = EnvironmentalHealthReportHeader::make([
            'program_banner' => $options['program_banner'],
            'period' => $options['period'],
        ], $generatedAt);
        $filenameBase = 'environmental-health-'.now()->format('Ymd-His');

        return match ($format) {
            'csv' => $this->exportCsv($rows, $columns, $filenameBase, $reportHeader),
            default => EnvironmentalHealthPdf::response($rows, $reportHeader, $options, $generatedAt, $filenameBase),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{key: string, label: string, group: string, value_key: string}>  $columns
     * @param  array<string, mixed>  $reportHeader
     */
    private function exportCsv(array $rows, array $columns, string $filenameBase, array $reportHeader): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filenameBase.'.csv"',
        ];

        return response()->streamDownload(function () use ($rows, $columns, $reportHeader): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");

            foreach (EnvironmentalHealthReportHeader::csvLeadingRows($reportHeader) as $leadingRow) {
                fputcsv($handle, $leadingRow);
            }

            fputcsv($handle, EnvironmentalHealthReport::csvHeaders($columns));

            foreach ($rows as $row) {
                fputcsv($handle, EnvironmentalHealthReport::csvRowValues($row, $columns));
            }

            fclose($handle);
        }, $filenameBase.'.csv', $headers);
    }
}
