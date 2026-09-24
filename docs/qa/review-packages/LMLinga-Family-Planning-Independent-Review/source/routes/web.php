<?php

use App\Support\DemoCatalog;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HouseholdProfiling\DeathController;
use App\Http\Controllers\HouseholdProfiling\HouseholdAmenitiesController;
use App\Http\Controllers\HouseholdProfiling\MaternalCareController;
use App\Http\Controllers\HouseholdProfiling\RiskAssessmentHistoryController;
use App\Support\DemoRiskAssessment;
use App\Support\DemoFamilyPlanning;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/landing', 'pages.auth.landing')->name('landing');
Route::view('/login', 'pages.auth.login')->name('login');
Route::view('/register', 'pages.auth.register')->name('register');

/*
 | Resident AI Chatbot — public landing (residents only; not staff).
 */
Route::view('/chatbot', 'pages.chatbot.landing')->name('chatbot.landing');

/*
 | Resident AI Chatbot auth screens (residents only; not staff).
 | Registration / login are UI-only (no validation/persistence yet).
 | Do NOT point chatbot CTAs at staff /login or /register (BHW/BNS/BSPO).
 */
Route::view('/chatbot/register', 'pages.chatbot.register')->name('chatbot.register');
Route::view('/chatbot/login', 'pages.chatbot.login')->name('chatbot.login');
Route::view('/chatbot/forgot-password', 'pages.chatbot.forgot-password')->name('chatbot.password.request');
Route::view('/chatbot/reset-password', 'pages.chatbot.reset-password')->name('chatbot.password.reset');

/*
 | Resident AI Chatbot main interface (UI-only; no AI/auth/persistence yet).
 */
Route::view('/chatbot/main', 'pages.chatbot.main')->name('chatbot.main');

/*
 | Resident household verification lifecycle (UI-only).
 | No forms persistence, approval workflow, auth checks, or database writes.
 */
Route::view('/chatbot/household/verification', 'pages.chatbot.household-request')
    ->name('chatbot.household.verification');

Route::view('/chatbot/household/verification/sms', 'pages.chatbot.household-sms-verification')
    ->name('chatbot.household.verification.sms');

Route::view('/chatbot/household/verification/email', 'pages.chatbot.household-email-verification')
    ->name('chatbot.household.verification.email');

Route::view('/chatbot/household/verification/status', 'pages.chatbot.household-placeholder', [
    'mode' => 'status',
])->name('chatbot.household.verification.status');

Route::view('/chatbot/household', 'pages.chatbot.household-information')
    ->name('chatbot.household.information');

/*
 | Temporary UI preview routes for password recovery screens.
 | Development-only placeholders — no email delivery, tokens, or reset logic.
 | /forgot-password does NOT redirect to /reset-password (email step not bypassed).
 | /reset-password is preview-only until a secure reset link is implemented later.
 */
Route::view('/forgot-password', 'pages.auth.forgot-password')->name('password.request');
Route::view('/reset-password', 'pages.auth.reset-password')->name('password.reset');

/*
 | Authenticated dashboard shell modules (UI preview; no real auth stack yet).
 | PersistUiRole applies only here — not on public/auth/chatbot pages.
 | Optional one-time ?role=admin|bhw|bns|bspo seeds session on GET/HEAD, then redirects.
 */
Route::middleware('ui.role')->group(function () {
    Route::view('/dashboard', 'pages.dashboard.index')->name('dashboard');

    /*
     | Admin-only modules — route layer matches sidebar visibility.
     */
    Route::middleware('ui.admin')->group(function () {
        Route::get('/user-management', function () {
            $isResidents = request()->query('tab') === 'residents';

            return view('pages.user-management.index', [
                'active' => 'user-management',
                'pageTitle' => 'User Management',
                'pageSubtitle' => $isResidents
                    ? 'Manage user accounts and access permissions.'
                    : 'Manage accounts of the Barangay Health Workers',
            ]);
        })->name('user-management.index');

        Route::get('/user-management/health-workers/{id}/edit', function (string $id) {
            $worker = DemoCatalog::findHealthWorker($id);

            return view('pages.user-management.health-worker-edit', [
                'active' => 'user-management',
                'pageTitle' => 'Edit Personal Information',
                'pageSubtitle' => "Update the selected health worker's profile and account information.",
                'workerId' => $id,
                'demoWorker' => $worker,
            ]);
        })->where('id', 'hw-[0-9]+')->name('user-management.health-workers.edit');

        Route::get('/user-management/health-workers/{id}/view', function (string $id) {
            $worker = DemoCatalog::findHealthWorker($id);

            return view('pages.user-management.health-worker-view', [
                'active' => 'user-management',
                'pageTitle' => 'View Health Worker Information',
                'pageSubtitle' => "Review the selected health worker's personal, contact, address, employment, and account information.",
                'workerId' => $id,
                'demoWorker' => $worker,
            ]);
        })->where('id', 'hw-[0-9]+')->name('user-management.health-workers.view');

        /*
         | Resident accounts (User Management → Residents) — ra-* IDs.
         | Compatibility: res-* IDs still redirect to Household Requests details.
         */
        Route::get('/user-management/residents/{id}/view', function (string $id) {
            if (preg_match('/^res-\d+$/', $id) === 1) {
                return redirect()->route('household-requests.view', ['id' => $id], 301);
            }

            $resident = DemoCatalog::findResidentAccount($id);

            return view('pages.user-management.residents.view', [
                'active' => 'user-management',
                'pageTitle' => 'Resident Information',
                'pageSubtitle' => 'Manage user accounts and access permissions.',
                'residentId' => $id,
                'demoResident' => $resident,
            ]);
        })->where('id', '(ra|res)-\d+')->name('user-management.residents.view');

        Route::get('/user-management/residents/{id}/edit', function (string $id) {
            $resident = DemoCatalog::findResidentAccount($id);

            return view('pages.user-management.residents.edit', [
                'active' => 'user-management',
                'pageTitle' => 'Edit Resident Information',
                'pageSubtitle' => 'Manage user accounts and access permissions.',
                'residentId' => $id,
                'demoResident' => $resident,
            ]);
        })->where('id', 'ra-\d+')->name('user-management.residents.edit');

        Route::put('/user-management/residents/{id}', function (string $id) {
            if (DemoCatalog::findResidentAccount($id) === null) {
                abort(404, 'Resident account not found.');
            }

            $validated = request()->validate([
                'first_name' => ['required', 'string', 'max:100'],
                'middle_name' => ['nullable', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'zone' => ['required', 'string', 'in:'.implode(',', \App\Support\DemoResidentAccounts::ALLOWED_ZONES)],
                'email' => ['required', 'email', 'max:255'],
            ]);

            $updated = \App\Support\DemoResidentAccounts::update($id, $validated);

            if ($updated === null) {
                abort(404, 'Resident account not found.');
            }

            return redirect()
                ->route('user-management.residents.view', ['id' => $id])
                ->with('status', 'Resident account updated successfully.');
        })->where('id', 'ra-\d+')->name('user-management.residents.update');

        Route::delete('/user-management/residents/{id}', function (string $id) {
            $resident = DemoCatalog::findResidentAccount($id);

            if ($resident === null) {
                abort(404, 'Resident account not found.');
            }

            \App\Support\DemoResidentAccounts::delete($id);

            return redirect()
                ->route('user-management.index', ['tab' => 'residents'])
                ->with('status', 'Resident account deleted successfully.');
        })->where('id', 'ra-\d+')->name('user-management.residents.destroy');

        Route::view('/household-requests', 'pages.household-requests.index', [
            'active' => 'household-requests',
            'pageTitle' => 'Household Requests',
            'pageSubtitle' => 'Review household record access requests submitted by barangay residents.',
        ])->name('household-requests.index');

        Route::get('/household-requests/{id}/view', function (string $id) {
            $request = DemoCatalog::findHouseholdRequest($id);

            return view('pages.household-requests.view', [
                'active' => 'household-requests',
                'pageTitle' => 'Household Request Details',
                'pageSubtitle' => 'Review the selected household record access request and its evaluation result.',
                'requestId' => $id,
                'demoRequest' => $request,
            ]);
        })->where('id', 'res-\d+')->name('household-requests.view');
    });

    Route::view('/spot-mapping', 'pages.spot-mapping.index', [
        'active' => 'spot-mapping',
        'pageTitle' => 'Spot Mapping',
        'pageSubtitle' => 'Real-Time Visualization and Status Tracking for Households in the Barangay.',
    ])->name('spot-mapping.index');

    Route::post(
        '/spot-mapping/plot-handoff',
        [\App\Http\Controllers\EnvironmentalHealth\HouseholdWaterSupplyController::class, 'issueHandoff']
    )->name('spot-mapping.plot-handoff');

    Route::view('/household-profiling', 'pages.household-profiling.index', [
        'active' => 'household-profiling',
        'pageTitle' => 'Household Profiling',
        'pageSubtitle' => 'Manage and View All Registered Household in the Barangay',
    ])->name('household-profiling.index');

    Route::get('/household-profiling/{householdNo}', function (string $householdNo) {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $household = DemoCatalog::findHousehold($key);

        return view('pages.household-profiling.view', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => $household
                ? 'View household details and members in Barangay La Medalla.'
                : 'Demo household was not found.',
            'householdNo' => $key,
            'demoHousehold' => $household,
        ]);
    })->where('householdNo', 'HH-[0-9]+')->name('household-profiling.view');

    Route::get('/household-profiling/{householdNo}/amenities', [HouseholdAmenitiesController::class, 'show'])
        ->where('householdNo', 'HH-[0-9]+')
        ->name('household-profiling.amenities.show');

    Route::get('/household-profiling/{householdNo}/amenities/edit', [HouseholdAmenitiesController::class, 'edit'])
        ->where('householdNo', 'HH-[0-9]+')
        ->name('household-profiling.amenities.edit');

    Route::put('/household-profiling/{householdNo}/amenities', [HouseholdAmenitiesController::class, 'update'])
        ->where('householdNo', 'HH-[0-9]+')
        ->name('household-profiling.amenities.update');

    Route::get('/household-profiling/{householdNo}/members/create', function (string $householdNo) {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $household = DemoCatalog::findHousehold($key);

        return view('pages.household-profiling.member-create', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => $household
                ? 'Add a new member to '.$key.' (demo form only).'
                : 'Demo household was not found.',
            'householdNo' => $key,
            'demoHousehold' => $household,
            'formMode' => 'create',
            'memberValues' => [],
        ]);
    })->where('householdNo', 'HH-[0-9]+')->name('household-profiling.members.create');

    Route::get('/household-profiling/{householdNo}/members/{memberId}', function (string $householdNo, string $memberId) {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = DemoCatalog::normalizeMemberId($memberId);
        $household = DemoCatalog::findHousehold($key);
        $member = $household ? lml_demo_find_member($household, $memberKey) : null;

        return view('pages.household-profiling.member-view', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => $member
                ? 'View member information for '.$key.'.'
                : 'Demo member was not found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => $household,
            'demoMember' => $member,
        ]);
    })->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.show');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/edit', function (string $householdNo, string $memberId) {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = DemoCatalog::normalizeMemberId($memberId);
        $household = DemoCatalog::findHousehold($key);
        $member = $household ? lml_demo_find_member($household, $memberKey) : null;

        return view('pages.household-profiling.member-edit', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => $member
                ? 'Edit member '.$memberKey.' in '.$key.' (demo form only).'
                : 'Demo member was not found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => $household,
            'demoMember' => $member,
            'formMode' => 'edit',
            'memberValues' => $member ?? [],
        ]);
    })->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.edit');

    /*
     | Child Care health-module destinations.
     | Child Immunization, School-Based Immunization, and Child Nutrition
     | are real UI destinations (preview-safe; no persistence endpoints yet).
     */
    Route::get('/household-profiling/{householdNo}/members/{memberId}/child-immunization', function (string $householdNo, string $memberId) {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = DemoCatalog::normalizeMemberId($memberId);
        $household = DemoCatalog::findHousehold($key);
        $member = $household ? lml_demo_find_member($household, $memberKey) : null;

        return view('pages.household-profiling.child-immunization', [
            'active' => 'household-profiling',
            'pageTitle' => 'Child Immunization',
            'pageSubtitle' => $member
                ? 'Vaccination records for '.$member['name'].' in '.$key.'.'
                : 'Demo member was not found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => $household,
            'demoMember' => $member,
        ]);
    })->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.child-immunization');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/child-immunization/birth-history/edit', function (string $householdNo, string $memberId) {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = DemoCatalog::normalizeMemberId($memberId);
        $household = DemoCatalog::findHousehold($key);
        $member = $household ? lml_demo_find_member($household, $memberKey) : null;

        return view('pages.household-profiling.child-immunization-birth-history-edit', [
            'active' => 'household-profiling',
            'pageTitle' => 'Birth History',
            'pageSubtitle' => $member
                ? 'Birth history information for '.$member['name'].' in '.$key.'.'
                : 'Demo member was not found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => $household,
            'demoMember' => $member,
        ]);
    })->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.child-immunization.birth-history.edit');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/school-based-immunization', function (string $householdNo, string $memberId) {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = DemoCatalog::normalizeMemberId($memberId);
        $household = DemoCatalog::findHousehold($key);
        $member = $household ? lml_demo_find_member($household, $memberKey) : null;

        return view('pages.household-profiling.school-based-immunization', [
            'active' => 'household-profiling',
            'pageTitle' => 'School-Based Immunization',
            'pageSubtitle' => $member
                ? 'Vaccination records for '.$member['name'].' in '.$key.'.'
                : 'Demo member was not found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => $household,
            'demoMember' => $member,
        ]);
    })->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.school-based-immunization');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/child-nutrition', function (string $householdNo, string $memberId) {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = DemoCatalog::normalizeMemberId($memberId);
        $household = DemoCatalog::findHousehold($key);
        $member = $household ? lml_demo_find_member($household, $memberKey) : null;

        return view('pages.household-profiling.child-nutrition', [
            'active' => 'household-profiling',
            'pageTitle' => 'Child Nutrition',
            'pageSubtitle' => $member
                ? 'Monitor child growth and nutrition for '.$member['name'].' in '.$key.'.'
                : 'Demo member was not found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => $household,
            'demoMember' => $member,
        ]);
    })->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.child-nutrition');

    /*
     | Resident-specific Risk Assessment (Household Profiling member workflow).
     | Optional health-worker assessment — empty history is valid.
     | Distinct from barangay-wide Health Records modules.
     */
    Route::get('/household-profiling/{householdNo}/members/{memberId}/risk-assessment', function (string $householdNo, string $memberId) {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = DemoCatalog::normalizeMemberId($memberId);
        $household = DemoCatalog::findHousehold($key);
        $member = $household ? lml_demo_find_member($household, $memberKey) : null;

        return view('pages.household-profiling.risk-assessment-history', [
            'active' => 'household-profiling',
            'pageTitle' => 'Risk Assessment',
            'pageSubtitle' => $member
                ? 'Risk assessment history for '.$member['name'].' in '.$key.'.'
                : 'Demo member was not found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => $household,
            'demoMember' => $member,
            'assessments' => $member
                ? DemoRiskAssessment::forMember($key, $memberKey)
                : [],
        ]);
    })->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.risk-assessment');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/risk-assessment/create', function (string $householdNo, string $memberId) {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = DemoCatalog::normalizeMemberId($memberId);
        $household = DemoCatalog::findHousehold($key);
        $member = $household ? lml_demo_find_member($household, $memberKey) : null;

        return view('pages.household-profiling.risk-assessment-form', [
            'active' => 'household-profiling',
            'pageTitle' => 'Risk Assessment',
            'pageSubtitle' => $member
                ? 'Add risk assessment for '.$member['name'].' in '.$key.'.'
                : 'Demo member was not found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => $household,
            'demoMember' => $member,
            'mode' => 'create',
            'assessment' => [],
        ]);
    })->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.risk-assessment.create');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/risk-assessment/{assessmentId}', [
        RiskAssessmentHistoryController::class,
        'show',
    ])->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
        'assessmentId' => 'RA-[0-9]+',
    ])->name('household-profiling.members.risk-assessment.show');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/risk-assessment/{assessmentId}/{section}', [
        RiskAssessmentHistoryController::class,
        'section',
    ])->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
        'assessmentId' => 'RA-[0-9]+',
        'section' => 'red-flags|past-medical|family-history|lifestyle|physical',
    ])->name('household-profiling.members.risk-assessment.section');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/risk-assessment/{assessmentId}/{section}/edit', [
        RiskAssessmentHistoryController::class,
        'section',
    ])->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
        'assessmentId' => 'RA-[0-9]+',
        'section' => 'red-flags|past-medical|family-history|lifestyle|physical',
    ])->name('household-profiling.members.risk-assessment.section.edit');

    Route::put('/household-profiling/{householdNo}/members/{memberId}/risk-assessment/{assessmentId}/{section}', [
        RiskAssessmentHistoryController::class,
        'updateSection',
    ])->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
        'assessmentId' => 'RA-[0-9]+',
        'section' => 'red-flags|past-medical|family-history|lifestyle|physical',
    ])->name('household-profiling.members.risk-assessment.section.update');

    /*
     | Resident-specific Family Planning visits (Household Profiling member workflow).
     | Demo catalog only — distinct from barangay-wide Health Records modules
     | and from demographic member field fp_user.
     */
    Route::get('/household-profiling/{householdNo}/members/{memberId}/family-planning', function (string $householdNo, string $memberId) {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = DemoCatalog::normalizeMemberId($memberId);
        $household = DemoCatalog::findHousehold($key);
        $member = $household ? lml_demo_find_member($household, $memberKey) : null;

        return view('pages.household-profiling.family-planning-history', [
            'active' => 'household-profiling',
            'pageTitle' => 'Family Planning',
            'pageSubtitle' => $member
                ? 'Family planning visit records for '.$member['name'].' in '.$key.'.'
                : 'Demo member was not found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => $household,
            'demoMember' => $member,
            'visits' => $member
                ? DemoFamilyPlanning::forMember($key, $memberKey)
                : [],
        ]);
    })->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.family-planning.index');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/family-planning/create', function (string $householdNo, string $memberId) {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = DemoCatalog::normalizeMemberId($memberId);
        $household = DemoCatalog::findHousehold($key);
        $member = $household ? lml_demo_find_member($household, $memberKey) : null;

        return view('pages.household-profiling.family-planning-form', [
            'active' => 'household-profiling',
            'pageTitle' => 'Family Planning',
            'pageSubtitle' => $member
                ? 'Add family planning visit for '.$member['name'].' in '.$key.'.'
                : 'Demo member was not found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => $household,
            'demoMember' => $member,
            'mode' => 'create',
            'visit' => [],
        ]);
    })->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.family-planning.create');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/family-planning/{visitId}', function (string $householdNo, string $memberId, string $visitId) {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = DemoCatalog::normalizeMemberId($memberId);
        $household = DemoCatalog::findHousehold($key);
        $member = $household ? lml_demo_find_member($household, $memberKey) : null;
        $visit = $member
            ? DemoFamilyPlanning::find($key, $memberKey, $visitId)
            : null;

        return view('pages.household-profiling.family-planning-show', [
            'active' => 'household-profiling',
            'pageTitle' => 'Family Planning',
            'pageSubtitle' => $member
                ? 'Family planning visit for '.$member['name'].' in '.$key.'.'
                : 'Demo member was not found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'visitId' => strtoupper(trim($visitId)),
            'demoHousehold' => $household,
            'demoMember' => $member,
            'visit' => $visit ?? [],
        ]);
    })->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
        'visitId' => 'FP-[0-9]+',
    ])->name('household-profiling.members.family-planning.show');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/family-planning/{visitId}/edit', function (string $householdNo, string $memberId, string $visitId) {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = DemoCatalog::normalizeMemberId($memberId);
        $household = DemoCatalog::findHousehold($key);
        $member = $household ? lml_demo_find_member($household, $memberKey) : null;
        $visit = $member
            ? DemoFamilyPlanning::find($key, $memberKey, $visitId)
            : null;

        return view('pages.household-profiling.family-planning-form', [
            'active' => 'household-profiling',
            'pageTitle' => 'Family Planning',
            'pageSubtitle' => $member
                ? 'Edit family planning visit for '.$member['name'].' in '.$key.'.'
                : 'Demo member was not found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'visitId' => strtoupper(trim($visitId)),
            'demoHousehold' => $household,
            'demoMember' => $member,
            'mode' => 'edit',
            'visit' => $visit ?? [],
        ]);
    })->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
        'visitId' => 'FP-[0-9]+',
    ])->name('household-profiling.members.family-planning.edit');

    /*
     | Resident-specific Maternal Care (Household Profiling member workflow).
     | Phase 1: session/preview state only — no database persistence.
     */
    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care',
        [MaternalCareController::class, 'index']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.index');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/register',
        [MaternalCareController::class, 'register']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.register');

    Route::post(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/register',
        [MaternalCareController::class, 'store']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.store');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/history',
        [MaternalCareController::class, 'history']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.history');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/trans-out',
        [MaternalCareController::class, 'transOut']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.trans-out');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/prenatal',
        [MaternalCareController::class, 'prenatal']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.prenatal');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/immunizations',
        [MaternalCareController::class, 'immunizations']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.immunizations');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/supplementations',
        [MaternalCareController::class, 'supplementations']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.supplementations');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/laboratory',
        [MaternalCareController::class, 'laboratory']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.laboratory');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/delivery',
        [MaternalCareController::class, 'delivery']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.delivery');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/postnatal',
        [MaternalCareController::class, 'postnatal']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.postnatal');

    Route::put(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/{section}',
        [MaternalCareController::class, 'updateSection']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
        'section' => 'prenatal|immunizations|supplementations|laboratory|delivery|postnatal|trans-out',
    ])->name('household-profiling.members.maternal-care.update');

    /*
     | Resident-specific Death Information (Household Profiling member workflow).
     | Phase 1: session/preview state only — no database persistence / permanent uploads.
     */
    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/death',
        [DeathController::class, 'index']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.death.index');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/death/create',
        [DeathController::class, 'create']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.death.create');

    Route::post(
        '/household-profiling/{householdNo}/members/{memberId}/death',
        [DeathController::class, 'store']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.death.store');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/death/edit',
        [DeathController::class, 'edit']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.death.edit');

    Route::put(
        '/household-profiling/{householdNo}/members/{memberId}/death',
        [DeathController::class, 'update']
    )->where([
        'householdNo' => 'HH-[0-9]+',
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.death.update');

    Route::get('/environmental-health', [
        \App\Http\Controllers\EnvironmentalHealth\EnvironmentalHealthDashboardController::class,
        'index',
    ])->name('environmental-health.index');

    Route::get('/environmental-health/export', [
        \App\Http\Controllers\EnvironmentalHealth\EnvironmentalHealthDashboardController::class,
        'export',
    ])->name('environmental-health.export');

    Route::get(
        '/environmental-health/household-water-supply',
        [\App\Http\Controllers\EnvironmentalHealth\HouseholdWaterSupplyController::class, 'show']
    )->name('environmental-health.household-water-supply');

    Route::post(
        '/environmental-health/household-water-supply',
        [\App\Http\Controllers\EnvironmentalHealth\HouseholdWaterSupplyController::class, 'store']
    )->name('environmental-health.household-water-supply.store');

    Route::get(
        '/environmental-health/household-water-supply/{householdNo}/step-2',
        [\App\Http\Controllers\EnvironmentalHealth\HouseholdWaterSupplyController::class, 'showStep2']
    )->where('householdNo', '[A-Za-z0-9\-]+')->name('environmental-health.household-water-supply.step2');

    Route::post(
        '/environmental-health/household-water-supply/{householdNo}/step-2',
        [\App\Http\Controllers\EnvironmentalHealth\HouseholdWaterSupplyController::class, 'storeStep2']
    )->where('householdNo', '[A-Za-z0-9\-]+')->name('environmental-health.household-water-supply.step2.store');

    Route::get(
        '/environmental-health/household-water-supply/{householdNo}/step-3',
        [\App\Http\Controllers\EnvironmentalHealth\HouseholdWaterSupplyController::class, 'showStep3']
    )->where('householdNo', '[A-Za-z0-9\-]+')->name('environmental-health.household-water-supply.step3');

    Route::post(
        '/environmental-health/household-water-supply/{householdNo}/step-3',
        [\App\Http\Controllers\EnvironmentalHealth\HouseholdWaterSupplyController::class, 'storeStep3']
    )->where('householdNo', '[A-Za-z0-9\-]+')->name('environmental-health.household-water-supply.step3.store');

    Route::get(
        '/environmental-health/household-water-supply/{householdNo}/step-4',
        [\App\Http\Controllers\EnvironmentalHealth\HouseholdWaterSupplyController::class, 'showStep4']
    )->where('householdNo', '[A-Za-z0-9\-]+')->name('environmental-health.household-water-supply.step4');

    Route::post(
        '/environmental-health/household-water-supply/{householdNo}/step-4',
        [\App\Http\Controllers\EnvironmentalHealth\HouseholdWaterSupplyController::class, 'storeStep4']
    )->where('householdNo', '[A-Za-z0-9\-]+')->name('environmental-health.household-water-supply.step4.store');

    /*
     | Health Records — Child Care barangay-wide summary (demo catalog aggregate).
     | Vitamin A / Deworming / Operation Timbang monitoring summaries reuse
     | named child-care routes.
     */
    Route::get('/health-records/child-care', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'index',
    ])->name('health-records.child-care.index');

    Route::get('/health-records/child-care/vitamin-a', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'vitaminA',
    ])->name('health-records.child-care.vitamin-a');

    Route::get('/health-records/child-care/deworming', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'deworming',
    ])->name('health-records.child-care.deworming');

    Route::get('/health-records/child-care/operation-timbang', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'operationTimbang',
    ])->name('health-records.child-care.operation-timbang');

    /*
     | Health Records — Risk Assessment barangay-wide summary (UI-phase fixture).
     | Independent of Household Profiling → member Risk Assessment (frozen).
     */
    Route::get('/health-records/risk-assessment', [
        \App\Http\Controllers\HealthRecords\RiskAssessmentSummaryController::class,
        'index',
    ])->name('health-records.risk-assessment.index');

    /*
     | Health Records — Family Planning barangay-wide summary (UI-phase fixture).
     | Independent of Household Profiling → member Family Planning.
     */
    Route::get('/health-records/family-planning', [
        \App\Http\Controllers\HealthRecords\FamilyPlanningSummaryController::class,
        'index',
    ])->name('health-records.family-planning.index');
});
