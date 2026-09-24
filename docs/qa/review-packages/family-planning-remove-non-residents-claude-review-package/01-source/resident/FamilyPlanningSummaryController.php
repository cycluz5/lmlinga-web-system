<?php

namespace App\Http\Controllers\HealthRecords;

use App\Http\Controllers\Controller;
use App\Support\HealthRecordsFamilyPlanning;
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
            'pageSubtitle' => 'Record and management of family planning details for monitoring and tracking reproductive health services.',
            'rows' => $rows,
            'summary' => $summary,
            'zones' => HealthRecordsFamilyPlanning::zones(),
            'years' => HealthRecordsFamilyPlanning::years($rows),
            'totalUnfiltered' => count($rows),
        ]);
    }
}
