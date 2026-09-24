<?php
// Excerpt from routes/web.php — Health Records Risk Assessment only


  routes\web.php:406:    /*
  routes\web.php:407:     | Resident-specific Risk Assessment (Household Profiling member workflow).
  routes\web.php:408:     | Optional health-worker assessment — empty history is valid.
  routes\web.php:409:     | Distinct from barangay-wide Health Records modules.
  routes\web.php:410:     */
> routes\web.php:411:    Route::get('/household-profiling/{householdNo}/members/{memberId}/risk-assessment', function 
(string $householdNo, string $memberId) {
  routes\web.php:412:        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
  routes\web.php:413:        $memberKey = DemoCatalog::normalizeMemberId($memberId);
  routes\web.php:414:        $household = DemoCatalog::findHousehold($key);
  routes\web.php:415:        $member = $household ? lml_demo_find_member($household, $memberKey) : null;
  routes\web.php:416:
> routes\web.php:417:        return view('pages.household-profiling.risk-assessment-history', [
  routes\web.php:418:            'active' => 'household-profiling',
  routes\web.php:419:            'pageTitle' => 'Risk Assessment',
  routes\web.php:429:                : [],
  routes\web.php:430:        ]);
  routes\web.php:431:    })->where([
  routes\web.php:432:        'householdNo' => 'HH-[0-9]+',
  routes\web.php:433:        'memberId' => 'MB-[0-9]+',
> routes\web.php:434:    ])->name('household-profiling.members.risk-assessment');
  routes\web.php:435:
> routes\web.php:436:    Route::get('/household-profiling/{householdNo}/members/{memberId}/risk-assessment/create', 
function (string $householdNo, string $memberId) {
  routes\web.php:437:        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
  routes\web.php:438:        $memberKey = DemoCatalog::normalizeMemberId($memberId);
  routes\web.php:439:        $household = DemoCatalog::findHousehold($key);
  routes\web.php:440:        $member = $household ? lml_demo_find_member($household, $memberKey) : null;
  routes\web.php:441:
> routes\web.php:442:        return view('pages.household-profiling.risk-assessment-form', [
  routes\web.php:443:            'active' => 'household-profiling',
  routes\web.php:444:            'pageTitle' => 'Risk Assessment',
  routes\web.php:453:            'assessment' => [],
  routes\web.php:454:        ]);
  routes\web.php:455:    })->where([
  routes\web.php:456:        'householdNo' => 'HH-[0-9]+',
  routes\web.php:457:        'memberId' => 'MB-[0-9]+',
> routes\web.php:458:    ])->name('household-profiling.members.risk-assessment.create');
  routes\web.php:459:
> routes\web.php:460:    
Route::get('/household-profiling/{householdNo}/members/{memberId}/risk-assessment/{assessmentId}', [
  routes\web.php:461:        RiskAssessmentHistoryController::class,
  routes\web.php:462:        'show',
  routes\web.php:463:    ])->where([
  routes\web.php:464:        'householdNo' => 'HH-[0-9]+',
  routes\web.php:465:        'memberId' => 'MB-[0-9]+',
  routes\web.php:466:        'assessmentId' => 'RA-[0-9]+',
> routes\web.php:467:    ])->name('household-profiling.members.risk-assessment.show');
  routes\web.php:468:
> routes\web.php:469:    
Route::get('/household-profiling/{householdNo}/members/{memberId}/risk-assessment/{assessmentId}/{section}', [
  routes\web.php:470:        RiskAssessmentHistoryController::class,
  routes\web.php:471:        'section',
  routes\web.php:472:    ])->where([
  routes\web.php:473:        'householdNo' => 'HH-[0-9]+',
  routes\web.php:474:        'memberId' => 'MB-[0-9]+',
  routes\web.php:475:        'assessmentId' => 'RA-[0-9]+',
  routes\web.php:476:        'section' => 'red-flags|past-medical|family-history|lifestyle|physical',
> routes\web.php:477:    ])->name('household-profiling.members.risk-assessment.section');
  routes\web.php:478:
> routes\web.php:479:    
Route::get('/household-profiling/{householdNo}/members/{memberId}/risk-assessment/{assessmentId}/{section}/edit', [
  routes\web.php:480:        RiskAssessmentHistoryController::class,
  routes\web.php:481:        'section',
  routes\web.php:482:    ])->where([
  routes\web.php:483:        'householdNo' => 'HH-[0-9]+',
  routes\web.php:484:        'memberId' => 'MB-[0-9]+',
  routes\web.php:485:        'assessmentId' => 'RA-[0-9]+',
  routes\web.php:486:        'section' => 'red-flags|past-medical|family-history|lifestyle|physical',
> routes\web.php:487:    ])->name('household-profiling.members.risk-assessment.section.edit');
  routes\web.php:488:
> routes\web.php:489:    
Route::put('/household-profiling/{householdNo}/members/{memberId}/risk-assessment/{assessmentId}/{section}', [
  routes\web.php:490:        RiskAssessmentHistoryController::class,
  routes\web.php:491:        'updateSection',
  routes\web.php:492:    ])->where([
  routes\web.php:493:        'householdNo' => 'HH-[0-9]+',
  routes\web.php:494:        'memberId' => 'MB-[0-9]+',
  routes\web.php:495:        'assessmentId' => 'RA-[0-9]+',
  routes\web.php:496:        'section' => 'red-flags|past-medical|family-history|lifestyle|physical',
> routes\web.php:497:    ])->name('household-profiling.members.risk-assessment.section.update');
  routes\web.php:498:
  routes\web.php:499:    /*
  routes\web.php:829:
  routes\web.php:830:    /*
  routes\web.php:831:     | Health Records — Risk Assessment barangay-wide summary (UI-phase fixture).
  routes\web.php:832:     | Independent of Household Profiling → member Risk Assessment (frozen).
  routes\web.php:833:     */
> routes\web.php:834:    Route::get('/health-records/risk-assessment', [
  routes\web.php:835:        \App\Http\Controllers\HealthRecords\RiskAssessmentSummaryController::class,
  routes\web.php:836:        'index',
> routes\web.php:837:    ])->name('health-records.risk-assessment.index');
  routes\web.php:838:});



