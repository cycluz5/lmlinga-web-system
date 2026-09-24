<?php
/**
 * Packaging excerpt — Health Records Family Planning route from routes/web.php
 * (full routes/web.php is also included in this package).
 *
 * Household Profiling member Family Planning routes remain separate under
 * household-profiling.members.family-planning.* and are NOT this module.
 */

/*
 | Health Records — Family Planning barangay-wide summary (UI-phase fixture).
 | Independent of Household Profiling → member Family Planning.
 */
Route::get('/health-records/family-planning', [
    \App\Http\Controllers\HealthRecords\FamilyPlanningSummaryController::class,
    'index',
])->name('health-records.family-planning.index');
