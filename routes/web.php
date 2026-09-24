<?php

use App\Http\Controllers\Announcements\AnnouncementController;
use App\Http\Controllers\Auth\ResidentLoginController;
use App\Http\Controllers\Chatbot\ChatbotController;
use App\Http\Controllers\Chatbot\ChatbotMainController;
use App\Http\Controllers\Chatbot\HouseholdInformationController;
use App\Http\Controllers\Chatbot\HouseholdMemberHealthController;
use App\Http\Controllers\Chatbot\HouseholdMemberInformationController;
use App\Http\Controllers\Chatbot\HouseholdRecordRequestController;
use App\Http\Controllers\Chatbot\ResidentForgotPasswordController;
use App\Http\Controllers\Chatbot\ResidentRegistrationController;
use App\Http\Controllers\Chatbot\ResidentResetPasswordController;
use App\Support\DemoCatalog;
use App\Support\HouseholdNumber;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HouseholdProfiling\ChildBirthHistoryController;
use App\Http\Controllers\HouseholdProfiling\ChildImmunizationController;
use App\Http\Controllers\HouseholdProfiling\ChildNutritionController;
use App\Http\Controllers\HouseholdProfiling\NutritionalStatusController;
use App\Http\Controllers\HouseholdProfiling\SchoolBasedImmunizationController;
use App\Http\Controllers\HouseholdProfiling\AdultImmunizationController;
use App\Http\Controllers\HouseholdProfiling\DewormingRecordController;
use App\Http\Controllers\HouseholdProfiling\DeathController;
use App\Http\Controllers\HouseholdProfiling\HouseholdAmenitiesController;
use App\Http\Controllers\HouseholdProfiling\HouseholdMemberController;
use App\Http\Controllers\HouseholdProfiling\FamilyPlanningController;
use App\Http\Controllers\HouseholdProfiling\HouseholdProfilingController;
use App\Http\Controllers\HouseholdProfiling\MaternalCareController;
use App\Http\Controllers\HouseholdProfiling\RiskAssessmentHistoryController;
use App\Support\HealthMemberIdentity;
use App\Support\HealthRecordsDeworming;

Route::redirect('/', '/landing');

Route::view('/landing', 'pages.auth.landing')->name('landing');

/*
 | Staff login — database users preferred; demo/config fallback for UI-phase.
 | POST must never put credentials in the query string.
 */
Route::get('/login', [\App\Http\Controllers\Auth\DemoLoginController::class, 'show'])->middleware('auth.nocache')->name('login');
Route::post('/login', [\App\Http\Controllers\Auth\DemoLoginController::class, 'store'])->name('login.store');
Route::post('/logout', [\App\Http\Controllers\Auth\DemoLoginController::class, 'destroy'])->name('logout');

Route::view('/register', 'pages.auth.register')->name('register');

/*
 | Resident AI Chatbot — public landing (residents only; not staff).
 | Login/logout remain owned by Auth\ResidentLoginController (target session contract).
 | Do NOT point chatbot CTAs at staff /login or /register (BHW/BNS/BSPO).
 */
Route::view('/chatbot', 'pages.chatbot.landing')->name('chatbot.landing');

Route::view('/chatbot/register', 'pages.chatbot.register')->name('chatbot.register');
Route::post('/chatbot/register', [ResidentRegistrationController::class, 'store'])->name('chatbot.register.store');
Route::view('/chatbot/login', 'pages.chatbot.login')->name('chatbot.login');
Route::post('/chatbot/login', [ResidentLoginController::class, 'store'])->name('chatbot.login.store');
Route::post('/chatbot/logout', [ResidentLoginController::class, 'destroy'])->name('chatbot.logout');
Route::view('/chatbot/forgot-password', 'pages.chatbot.forgot-password')->name('chatbot.password.request');
Route::post('/chatbot/forgot-password', [ResidentForgotPasswordController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('chatbot.password.email');
Route::get('/chatbot/reset-password/{token}', [ResidentResetPasswordController::class, 'create'])
    ->name('chatbot.password.reset');
Route::post('/chatbot/reset-password', [ResidentResetPasswordController::class, 'store'])
    ->name('chatbot.password.update');

Route::get('/chatbot/main', [ChatbotMainController::class, 'show'])->middleware(['resident.chatbot', 'auth.nocache'])->name('chatbot.main');

Route::post('/chatbot/notifications/{notificationId}/read', [ChatbotMainController::class, 'markRead'])
    ->middleware('resident.chatbot')
    ->whereNumber('notificationId')
    ->name('chatbot.notifications.read');

Route::get('/chatbot/household/verification', [HouseholdRecordRequestController::class, 'create'])
    ->middleware('resident.chatbot')
    ->name('chatbot.household.verification');
Route::post('/chatbot/household/verification', [HouseholdRecordRequestController::class, 'store'])
    ->middleware('resident.chatbot')
    ->name('chatbot.household.verification.store');
Route::get('/chatbot/household/verification/method', [HouseholdRecordRequestController::class, 'otpMethod'])
    ->middleware('resident.chatbot')
    ->name('chatbot.household.verification.otp-method');
Route::get('/chatbot/household/verification/sms', [HouseholdRecordRequestController::class, 'sms'])
    ->middleware('resident.chatbot')
    ->name('chatbot.household.verification.sms');
Route::post('/chatbot/household/verification/sms/send', [HouseholdRecordRequestController::class, 'sendSmsOtp'])
    ->middleware('resident.chatbot')
    ->name('chatbot.household.verification.sms.send');
Route::post('/chatbot/household/verification/sms/verify', [HouseholdRecordRequestController::class, 'verifySmsOtp'])
    ->middleware('resident.chatbot')
    ->name('chatbot.household.verification.sms.verify');
Route::get('/chatbot/household/verification/email', [HouseholdRecordRequestController::class, 'email'])
    ->middleware('resident.chatbot')
    ->name('chatbot.household.verification.email');
Route::post('/chatbot/household/verification/email/send', [HouseholdRecordRequestController::class, 'sendEmailOtp'])
    ->middleware('resident.chatbot')
    ->name('chatbot.household.verification.email.send');
Route::post('/chatbot/household/verification/email/verify', [HouseholdRecordRequestController::class, 'verifyEmailOtp'])
    ->middleware('resident.chatbot')
    ->name('chatbot.household.verification.email.verify');
Route::get('/chatbot/household/verification/status', [HouseholdRecordRequestController::class, 'status'])
    ->middleware('resident.chatbot')
    ->name('chatbot.household.verification.status');

Route::post('/chatbot/ask', [ChatbotController::class, 'ask'])
    ->middleware('resident.chatbot')
    ->name('chatbot.ask');
Route::get('/chatbot/conversations', [ChatbotController::class, 'listConversations'])
    ->middleware('resident.chatbot')
    ->name('chatbot.conversations');
Route::get('/chatbot/conversation/{conversationId}/history', [ChatbotController::class, 'history'])
    ->middleware('resident.chatbot')
    ->name('chatbot.conversation.history');
Route::delete('/chatbot/conversation/{conversationId}', [ChatbotController::class, 'destroy'])
    ->middleware('resident.chatbot')
    ->name('chatbot.conversation.destroy');
Route::patch('/chatbot/conversation/{conversationId}/pin', [ChatbotController::class, 'updatePin'])
    ->middleware('resident.chatbot')
    ->name('chatbot.conversation.pin');

Route::get('/chatbot/household', [HouseholdInformationController::class, 'show'])
    ->middleware('resident.chatbot')
    ->name('chatbot.household.information');
Route::get('/chatbot/household/members/{member}', [HouseholdMemberInformationController::class, 'show'])
    ->middleware('resident.chatbot')
    ->whereNumber('member')
    ->name('chatbot.household.members.show');
Route::get('/chatbot/household/members/{member}/child-care', [HouseholdMemberHealthController::class, 'childCare'])
    ->middleware('resident.chatbot')
    ->whereNumber('member')
    ->name('chatbot.household.members.child-care');
Route::get('/chatbot/household/members/{member}/risk-assessment', [HouseholdMemberHealthController::class, 'riskAssessment'])
    ->middleware('resident.chatbot')
    ->whereNumber('member')
    ->name('chatbot.household.members.risk-assessment');
Route::get('/chatbot/household/members/{member}/family-planning', [HouseholdMemberHealthController::class, 'familyPlanning'])
    ->middleware('resident.chatbot')
    ->whereNumber('member')
    ->name('chatbot.household.members.family-planning');
Route::get('/chatbot/household/members/{member}/maternal', [HouseholdMemberHealthController::class, 'maternal'])
    ->middleware('resident.chatbot')
    ->whereNumber('member')
    ->name('chatbot.household.members.maternal');

/*
 | Staff/admin password recovery — Laravel password broker + password_reset_tokens.
 | Guest-only. Does not use chatbot / ResidentAccount reset routes.
 */
Route::get('/forgot-password', [
    \App\Http\Controllers\Auth\ForgotPasswordController::class,
    'create',
])->name('password.request');

Route::post('/forgot-password', [
    \App\Http\Controllers\Auth\ForgotPasswordController::class,
    'store',
])->middleware('throttle:6,1')->name('password.email');

Route::get('/reset-password/{token}', [
    \App\Http\Controllers\Auth\ForgotPasswordController::class,
    'edit',
])->name('password.reset');

Route::post('/reset-password', [
    \App\Http\Controllers\Auth\ForgotPasswordController::class,
    'update',
])->middleware('throttle:6,1')->name('password.update');

/*
 | Voluntary staff password change — authenticated staff only.
 | REF-24: workers are not forced here after login.
 */
Route::middleware(['auth', 'staff.account-usable'])->group(function () {
    Route::get('/change-password', [
        \App\Http\Controllers\Auth\ChangePasswordController::class,
        'show',
    ])->name('password.change.required');

    Route::post('/change-password', [
        \App\Http\Controllers\Auth\ChangePasswordController::class,
        'store',
    ])->name('password.change.store');
});

/*
 | Offline sync foundation — session/CSRF JSON endpoints.
 | Status is reachable during forced password change so the client can
 | read must_change_password. Sync remains password-current protected.
 */
Route::middleware(['auth', 'ui.role'])->group(function () {
    Route::get('/offline/status', [
        \App\Http\Controllers\Offline\OfflineStatusController::class,
        'show',
    ])->name('offline.status');
});

Route::middleware(['auth', 'staff.account-usable', 'staff.password-current', 'ui.role'])->group(function () {
    Route::post('/offline/sync', [
        \App\Http\Controllers\Offline\OfflineSyncController::class,
        'store',
    ])->name('offline.sync');

    Route::get('/offline/household-profiling-bootstrap', [
        \App\Http\Controllers\Offline\OfflineHouseholdProfilingBootstrapController::class,
        'show',
    ])->name('offline.household-profiling-bootstrap');

    Route::get('/offline/environmental-health-shell/{step}', [
        \App\Http\Controllers\Offline\OfflineEnvironmentalHealthShellController::class,
        'show',
    ])->where('step', '[1-4]')->name('offline.environmental-health-shell');
});

/*
 | Authenticated dashboard shell modules.
 | PersistUiRole syncs appointment-derived role for sidebar display only.
 */
Route::middleware(['auth', 'staff.account-usable', 'staff.password-current', 'ui.role', 'auth.nocache'])->group(function () {
    Route::get('/dashboard', [
        \App\Http\Controllers\DashboardController::class,
        'index',
    ])->name('dashboard');

    Route::get('/profile', [
        \App\Http\Controllers\WorkerProfileController::class,
        'show',
    ])->name('profile.show');

    /*
     | Admin-only modules — route layer matches sidebar visibility.
     */
    Route::middleware('ui.admin')->group(function () {
        Route::get('/user-management', [
            \App\Http\Controllers\Admin\HealthWorkerAccountController::class,
            'index',
        ])->name('user-management.index');

        Route::get('/user-management/health-workers/create', [
            \App\Http\Controllers\Admin\HealthWorkerAccountController::class,
            'create',
        ])->name('user-management.health-workers.create');

        Route::post('/user-management/health-workers', [
            \App\Http\Controllers\Admin\HealthWorkerAccountController::class,
            'store',
        ])->name('user-management.health-workers.store');

        Route::get('/user-management/health-workers/{id}/edit', [
            \App\Http\Controllers\Admin\HealthWorkerAccountController::class,
            'edit',
        ])->where('id', 'hw-[0-9]+|[0-9]+')->name('user-management.health-workers.edit');

        Route::put('/user-management/health-workers/{id}', [
            \App\Http\Controllers\Admin\HealthWorkerAccountController::class,
            'update',
        ])->where('id', 'hw-[0-9]+|[0-9]+')->name('user-management.health-workers.update');

        Route::get('/user-management/health-workers/{id}/view', [
            \App\Http\Controllers\Admin\HealthWorkerAccountController::class,
            'show',
        ])->where('id', 'hw-[0-9]+|[0-9]+')->name('user-management.health-workers.view');

        Route::post('/user-management/health-workers/{id}/deactivate', [
            \App\Http\Controllers\Admin\HealthWorkerAccountController::class,
            'deactivate',
        ])->where('id', 'hw-[0-9]+|[0-9]+')->name('user-management.health-workers.deactivate');

        Route::post('/user-management/health-workers/{id}/activate', [
            \App\Http\Controllers\Admin\HealthWorkerAccountController::class,
            'activate',
        ])->where('id', 'hw-[0-9]+|[0-9]+')->name('user-management.health-workers.activate');

        Route::delete('/user-management/health-workers/{id}', [
            \App\Http\Controllers\Admin\HealthWorkerAccountController::class,
            'destroy',
        ])->where('id', 'hw-[0-9]+|[0-9]+')->name('user-management.health-workers.destroy');

        /*
         | Resident portal accounts (User Management → Residents) — ra-{id}.
         | Compatibility: res-* IDs still redirect to Household Requests details.
         | Authority: resident_accounts (Admin management only — not chatbot redesign).
         */
        Route::get('/user-management/residents/{id}/view', [
            \App\Http\Controllers\Admin\ResidentAccountController::class,
            'show',
        ])->where('id', '(ra|res)-\d+')->name('user-management.residents.view');

        Route::get('/user-management/residents/{id}/edit', [
            \App\Http\Controllers\Admin\ResidentAccountController::class,
            'edit',
        ])->where('id', 'ra-\d+')->name('user-management.residents.edit');

        Route::put('/user-management/residents/{id}', [
            \App\Http\Controllers\Admin\ResidentAccountController::class,
            'update',
        ])->where('id', 'ra-\d+')->name('user-management.residents.update');

        Route::delete('/user-management/residents/{id}', [
            \App\Http\Controllers\Admin\ResidentAccountController::class,
            'destroy',
        ])->where('id', 'ra-\d+')->name('user-management.residents.destroy');

        Route::get('/household-requests', [
            \App\Http\Controllers\HouseholdRequests\HouseholdRequestsController::class,
            'index',
        ])->name('household-requests.index');

        Route::get('/household-requests/{id}/view', [
            \App\Http\Controllers\HouseholdRequests\HouseholdRequestsController::class,
            'show',
        ])->where('id', 'req-\d+')->name('household-requests.view');

        Route::get('/death-requests', [
            \App\Http\Controllers\DeathRequests\DeathRequestReviewController::class,
            'index',
        ])->name('death-requests.index');

        Route::get('/death-requests/{deathRequest}', [
            \App\Http\Controllers\DeathRequests\DeathRequestReviewController::class,
            'show',
        ])->whereNumber('deathRequest')->name('death-requests.show');

        Route::post('/death-requests/{deathRequest}/approve', [
            \App\Http\Controllers\DeathRequests\DeathRequestReviewController::class,
            'approve',
        ])->whereNumber('deathRequest')->name('death-requests.approve');

        Route::post('/death-requests/{deathRequest}/reject', [
            \App\Http\Controllers\DeathRequests\DeathRequestReviewController::class,
            'reject',
        ])->whereNumber('deathRequest')->name('death-requests.reject');

        Route::get('/death-requests/{deathRequest}/certificate', [
            \App\Http\Controllers\DeathRequests\DeathRequestReviewController::class,
            'certificate',
        ])->whereNumber('deathRequest')->name('death-requests.certificate');
    });

    /*
     | Announcement — dashboard overview + create + view-all list pages (frontend prototype).
     | Legacy /announcement redirects to /announcements.
     */
    Route::redirect('/announcement', '/announcements', 301);

    Route::get('/announcements', [AnnouncementController::class, 'index'])
        ->name('announcements.index');

    Route::get('/announcements/create', [AnnouncementController::class, 'create'])
        ->name('announcements.create');

    Route::post('/announcements/reach-preview', [AnnouncementController::class, 'estimateReach'])
        ->name('announcements.reach-preview');

    Route::post('/announcements', [AnnouncementController::class, 'store'])
        ->name('announcements.store');

    Route::get('/announcements/upcoming', [AnnouncementController::class, 'upcoming'])
        ->name('announcements.upcoming');

    Route::get('/announcements/recent', [AnnouncementController::class, 'recent'])
        ->name('announcements.recent');

    Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show'])
        ->whereNumber('announcement')
        ->name('announcements.show');

    Route::get('/announcements/{announcement}/edit', [AnnouncementController::class, 'edit'])
        ->whereNumber('announcement')
        ->name('announcements.edit');

    Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update'])
        ->whereNumber('announcement')
        ->name('announcements.update');

    Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])
        ->whereNumber('announcement')
        ->name('announcements.destroy');

    Route::get('/spot-mapping', [\App\Http\Controllers\SpotMappingController::class, 'index'])
        ->name('spot-mapping.index');

    Route::post(
        '/spot-mapping/plot',
        [\App\Http\Controllers\SpotMappingController::class, 'updateCoordinates']
    )->name('spot-mapping.plot');

    Route::post(
        '/spot-mapping/plot-handoff',
        [\App\Http\Controllers\SpotMappingController::class, 'plotAndHandoff']
    )->name('spot-mapping.plot-handoff');

    Route::post(
        '/spot-mapping/plot-new',
        [\App\Http\Controllers\SpotMappingController::class, 'plotNewHousehold']
    )->name('spot-mapping.plot-new');

    Route::get('/household-profiling', [HouseholdProfilingController::class, 'index'])
        ->name('household-profiling.index');

    Route::get('/household-profiling/report', [HouseholdProfilingController::class, 'reportBuilder'])
        ->name('household-profiling.report-builder');

    Route::get('/household-profiling/export', [HouseholdProfilingController::class, 'export'])
        ->name('household-profiling.export');

    Route::get('/household-profiling/create', [HouseholdProfilingController::class, 'create'])
        ->name('household-profiling.create');

    Route::post('/household-profiling', [HouseholdProfilingController::class, 'store'])
        ->name('household-profiling.store');

    Route::get('/household-profiling/{householdNo}', [HouseholdProfilingController::class, 'show'])
        ->where('householdNo', HouseholdNumber::ROUTE_CONSTRAINT)
        ->name('household-profiling.view');

    Route::get('/household-profiling/{householdNo}/edit', [HouseholdProfilingController::class, 'edit'])
        ->where('householdNo', HouseholdNumber::ROUTE_CONSTRAINT)
        ->name('household-profiling.edit');

    Route::put('/household-profiling/{householdNo}', [HouseholdProfilingController::class, 'update'])
        ->where('householdNo', HouseholdNumber::ROUTE_CONSTRAINT)
        ->name('household-profiling.update');

    Route::delete('/household-profiling/{householdNo}', [HouseholdProfilingController::class, 'destroy'])
        ->where('householdNo', HouseholdNumber::ROUTE_CONSTRAINT)
        ->name('household-profiling.destroy');

    Route::get('/household-profiling/{householdNo}/amenities', [HouseholdAmenitiesController::class, 'show'])
        ->where('householdNo', HouseholdNumber::ROUTE_CONSTRAINT)
        ->name('household-profiling.amenities.show');

    Route::get('/household-profiling/{householdNo}/amenities/edit', [HouseholdAmenitiesController::class, 'edit'])
        ->where('householdNo', HouseholdNumber::ROUTE_CONSTRAINT)
        ->name('household-profiling.amenities.edit');

    Route::put('/household-profiling/{householdNo}/amenities', [HouseholdAmenitiesController::class, 'update'])
        ->where('householdNo', HouseholdNumber::ROUTE_CONSTRAINT)
        ->name('household-profiling.amenities.update');

    Route::get('/household-profiling/{householdNo}/members/create', [HouseholdMemberController::class, 'create'])
        ->where('householdNo', HouseholdNumber::ROUTE_CONSTRAINT)
        ->name('household-profiling.members.create');

    Route::post('/household-profiling/{householdNo}/members', [HouseholdMemberController::class, 'store'])
        ->where('householdNo', HouseholdNumber::ROUTE_CONSTRAINT)
        ->name('household-profiling.members.store');

    Route::get('/household-profiling/{householdNo}/members/{memberId}', [HouseholdMemberController::class, 'show'])
        ->where([
            'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
            'memberId' => 'MB-[0-9]+|MB-L-[A-Za-z0-9]+',
        ])
        ->name('household-profiling.members.show');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/edit', [HouseholdMemberController::class, 'edit'])
        ->where([
            'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
            'memberId' => 'MB-[0-9]+|MB-L-[A-Za-z0-9]+',
        ])
        ->name('household-profiling.members.edit');

    Route::put('/household-profiling/{householdNo}/members/{memberId}', [HouseholdMemberController::class, 'update'])
        ->where([
            'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
            'memberId' => 'MB-[0-9]+',
        ])
        ->name('household-profiling.members.update');

    /*
     | Child Care health-module destinations.
     | Child Immunization uses DB persistence for persisted residents (DB-08 Phase 3).
     | School-Based Immunization uses DB persistence for persisted residents (DB-09 Phase 3).
     | Child Nutrition uses DB persistence for persisted residents (DB-10 Phase 2).
     */
    Route::get('/household-profiling/{householdNo}/members/{memberId}/child-immunization', [
        ChildImmunizationController::class,
        'show',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+|MB-L-[A-Za-z0-9]+',
    ])->name('household-profiling.members.child-immunization');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/child-immunization/birth-history/edit', [
        ChildBirthHistoryController::class,
        'edit',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.child-immunization.birth-history.edit');

    Route::post(
        '/household-profiling/{householdNo}/members/{memberId}/child-immunization/birth-history',
        [ChildBirthHistoryController::class, 'store']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.child-immunization.birth-history.store');

    Route::post(
        '/household-profiling/{householdNo}/members/{memberId}/child-immunization',
        [ChildImmunizationController::class, 'store']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.child-immunization.store');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/school-based-immunization', [
        SchoolBasedImmunizationController::class,
        'show',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+|MB-L-[A-Za-z0-9]+',
    ])->name('household-profiling.members.school-based-immunization');

    Route::post(
        '/household-profiling/{householdNo}/members/{memberId}/school-based-immunization',
        [SchoolBasedImmunizationController::class, 'store']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.school-based-immunization.store');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/adult-immunization', [
        AdultImmunizationController::class,
        'index',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+|MB-L-[A-Za-z0-9]+',
    ])->name('household-profiling.members.adult-immunization');

    Route::post(
        '/household-profiling/{householdNo}/members/{memberId}/adult-immunization',
        [AdultImmunizationController::class, 'store']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.adult-immunization.store');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/child-nutrition', [
        ChildNutritionController::class,
        'show',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+|MB-L-[A-Za-z0-9]+',
    ])->name('household-profiling.members.child-nutrition');

    Route::post(
        '/household-profiling/{householdNo}/members/{memberId}/child-nutrition',
        [ChildNutritionController::class, 'store']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.child-nutrition.store');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/nutritional-status', [
        NutritionalStatusController::class,
        'index',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+|MB-L-[A-Za-z0-9]+',
    ])->name('household-profiling.members.nutritional-status');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/nutritional-status/create', [
        NutritionalStatusController::class,
        'create',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+|MB-L-[A-Za-z0-9]+',
    ])->name('household-profiling.members.nutritional-status.create');

    Route::post(
        '/household-profiling/{householdNo}/members/{memberId}/nutritional-status',
        [NutritionalStatusController::class, 'store']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.nutritional-status.store');

    /*
     | Read-only, non-persisting assessment preview for the Add Measurement
     | form — recomputes Weight-for-Age/Height-for-Age/MUAC/BMI live as the
     | operator types, from the same authoritative NutritionAssessmentService
     | used on save. Never writes a TimbangRecord row.
     */
    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/nutritional-status/preview',
        [NutritionalStatusController::class, 'previewAssessment']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+|MB-L-[A-Za-z0-9]+',
    ])->name('household-profiling.members.nutritional-status.preview');

    /*
     | Canonical resident-based Nutritional Status destinations.
     | Applies to all residents (children, adolescents, adults, elderly) — not
     | members-scoped and not tied to a synthetic MB-xxx identifier. residentId
     | is the resident's actual database primary key. The legacy
     | /household-profiling/{householdNo}/members/{memberId}/nutritional-status
     | routes above remain fully functional for backward compatibility.
     */
    Route::get('/households/{householdNo}/residents/{residentId}/nutritional-status', [
        NutritionalStatusController::class,
        'indexByResident',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'residentId' => '[0-9]+',
    ])->name('households.residents.nutritional-status');

    Route::get('/households/{householdNo}/residents/{residentId}/nutritional-status/create', [
        NutritionalStatusController::class,
        'createByResident',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'residentId' => '[0-9]+',
    ])->name('households.residents.nutritional-status.create');

    Route::post(
        '/households/{householdNo}/residents/{residentId}/nutritional-status',
        [NutritionalStatusController::class, 'storeByResident']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'residentId' => '[0-9]+',
    ])->name('households.residents.nutritional-status.store');

    Route::get(
        '/households/{householdNo}/residents/{residentId}/nutritional-status/preview',
        [NutritionalStatusController::class, 'previewAssessmentByResident']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'residentId' => '[0-9]+',
    ])->name('households.residents.nutritional-status.preview');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/deworming', function (string $householdNo, string $memberId) {
        $ctx = app(HealthMemberIdentity::class)->resolve($householdNo, $memberId);
        $key = $ctx['householdNo'];
        $memberKey = $ctx['memberId'];
        $household = $ctx['household'];
        $member = $ctx['member'];
        $child = HealthRecordsDeworming::findChildForMember($key, $memberKey);
        $canManage = $member !== null && HealthRecordsDeworming::memberCanManageRecords($member);

        return view('pages.health-records.child-care-deworming-show', [
            'active' => 'household-profiling',
            'pageTitle' => 'Child Care | Deworming',
            'pageSubtitle' => $child
                ? 'Deworming record for the selected household member.'
                : 'No record found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'child' => $child,
            'childKey' => $child !== null ? (string) $child['key'] : '',
            'canAddRecord' => $child !== null && $canManage,
            'records' => HealthRecordsDeworming::recordsForMember($key, $memberKey),
        ]);
    })->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+|MB-L-[A-Za-z0-9]+',
    ])->name('household-profiling.members.deworming');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/deworming/create', function (string $householdNo, string $memberId) {
        $ctx = app(HealthMemberIdentity::class)->resolve($householdNo, $memberId);
        $key = $ctx['householdNo'];
        $memberKey = $ctx['memberId'];
        $household = $ctx['household'];
        $member = $ctx['member'];

        if ($member === null || ! HealthRecordsDeworming::memberCanManageRecords($member)) {
            return redirect()->route('household-profiling.members.deworming', [
                'householdNo' => $key,
                'memberId' => $memberKey,
            ]);
        }

        $child = HealthRecordsDeworming::findChildForMember($key, $memberKey);
        $persistenceSource = $ctx['source'] === 'db' ? 'db' : 'preview';

        return view('pages.health-records.child-care-deworming-create', [
            'active' => 'household-profiling',
            'pageTitle' => 'Child Care | Deworming',
            'pageSubtitle' => 'Add a Deworming record for the selected household member.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'child' => $child,
            'childKey' => $child !== null ? (string) $child['key'] : '',
            'roundOptions' => HealthRecordsDeworming::roundOptions(),
            'seStatusOptions' => HealthRecordsDeworming::seStatusOptions(),
            'persistenceSource' => $persistenceSource,
        ]);
    })->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.deworming.create');

    Route::post(
        '/household-profiling/{householdNo}/members/{memberId}/deworming',
        [DewormingRecordController::class, 'store']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.deworming.store');

    /*
     | Resident-specific Risk Assessment (Household Profiling member workflow).
     | Optional health-worker assessment — empty history is valid.
     | Distinct from barangay-wide Health Records modules.
     | DB-12: persisted residents use risk_assessments; demo-only keeps catalog/session.
     */
    Route::get('/household-profiling/{householdNo}/members/{memberId}/risk-assessment', [
        RiskAssessmentHistoryController::class,
        'index',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+|MB-L-[A-Za-z0-9]+',
    ])->name('household-profiling.members.risk-assessment');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/risk-assessment/create', [
        RiskAssessmentHistoryController::class,
        'create',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.risk-assessment.create');

    Route::post('/household-profiling/{householdNo}/members/{memberId}/risk-assessment', [
        RiskAssessmentHistoryController::class,
        'store',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.risk-assessment.store');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/risk-assessment/{assessmentId}', [
        RiskAssessmentHistoryController::class,
        'show',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
        'assessmentId' => 'RA-[0-9]+',
    ])->name('household-profiling.members.risk-assessment.show');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/risk-assessment/{assessmentId}/{section}', [
        RiskAssessmentHistoryController::class,
        'section',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
        'assessmentId' => 'RA-[0-9]+',
        'section' => 'red-flags|past-medical|family-history|lifestyle|physical',
    ])->name('household-profiling.members.risk-assessment.section');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/risk-assessment/{assessmentId}/{section}/edit', [
        RiskAssessmentHistoryController::class,
        'section',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
        'assessmentId' => 'RA-[0-9]+',
        'section' => 'red-flags|past-medical|family-history|lifestyle|physical',
    ])->name('household-profiling.members.risk-assessment.section.edit');

    Route::put('/household-profiling/{householdNo}/members/{memberId}/risk-assessment/{assessmentId}/{section}', [
        RiskAssessmentHistoryController::class,
        'updateSection',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
        'assessmentId' => 'RA-[0-9]+',
        'section' => 'red-flags|past-medical|family-history|lifestyle|physical',
    ])->name('household-profiling.members.risk-assessment.section.update');

    /*
     | Resident-specific Family Planning visits (Household Profiling member workflow).
     | DB-13 Phase 2 — MySQL-backed for persisted residents; demo catalog fallback otherwise.
     | Distinct from barangay-wide Health Records modules and from demographic fp_user.
     */
    Route::get('/household-profiling/{householdNo}/members/{memberId}/family-planning', [
        FamilyPlanningController::class,
        'index',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+|MB-L-[A-Za-z0-9]+',
    ])->name('household-profiling.members.family-planning.index');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/family-planning/create', [
        FamilyPlanningController::class,
        'create',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.family-planning.create');

    Route::post('/household-profiling/{householdNo}/members/{memberId}/family-planning', [
        FamilyPlanningController::class,
        'store',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.family-planning.store');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/family-planning/{visitId}', [
        FamilyPlanningController::class,
        'show',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
        'visitId' => 'FP-[0-9]+',
    ])->name('household-profiling.members.family-planning.show');

    Route::get('/household-profiling/{householdNo}/members/{memberId}/family-planning/{visitId}/edit', [
        FamilyPlanningController::class,
        'edit',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
        'visitId' => 'FP-[0-9]+',
    ])->name('household-profiling.members.family-planning.edit');

    Route::put('/household-profiling/{householdNo}/members/{memberId}/family-planning/{visitId}', [
        FamilyPlanningController::class,
        'update',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
        'visitId' => 'FP-[0-9]+',
    ])->name('household-profiling.members.family-planning.update');

    /*
     | Resident-specific Maternal Care (Household Profiling member workflow).
     | DB-14: MySQL persistence for real residents; demo members keep session preview.
     */
    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care',
        [MaternalCareController::class, 'index']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+|MB-L-[A-Za-z0-9]+',
    ])->name('household-profiling.members.maternal-care.index');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/register',
        [MaternalCareController::class, 'register']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.register');

    Route::post(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/register',
        [MaternalCareController::class, 'store']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.store');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/history',
        [MaternalCareController::class, 'history']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.history');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/history/{pregnancyId}',
        [MaternalCareController::class, 'historyShow']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
        'pregnancyId' => 'MC-[0-9]+',
    ])->name('household-profiling.members.maternal-care.history.show');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/trans-out',
        [MaternalCareController::class, 'transOut']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.trans-out');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/prenatal',
        [MaternalCareController::class, 'prenatal']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.prenatal');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/immunizations',
        [MaternalCareController::class, 'immunizations']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.immunizations');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/supplementations',
        [MaternalCareController::class, 'supplementations']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.supplementations');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/laboratory',
        [MaternalCareController::class, 'laboratory']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.laboratory');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/delivery',
        [MaternalCareController::class, 'delivery']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.delivery');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/postnatal',
        [MaternalCareController::class, 'postnatal']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.maternal-care.postnatal');

    Route::put(
        '/household-profiling/{householdNo}/members/{memberId}/maternal-care/{section}',
        [MaternalCareController::class, 'updateSection']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
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
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.death.index');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/death/create',
        [DeathController::class, 'create']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.death.create');

    Route::post(
        '/household-profiling/{householdNo}/members/{memberId}/death',
        [DeathController::class, 'store']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.death.store');

    Route::get(
        '/household-profiling/{householdNo}/members/{memberId}/death/edit',
        [DeathController::class, 'edit']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.death.edit');

    Route::put(
        '/household-profiling/{householdNo}/members/{memberId}/death',
        [DeathController::class, 'update']
    )->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('household-profiling.members.death.update');

    Route::get('/environmental-health', [
        \App\Http\Controllers\EnvironmentalHealth\EnvironmentalHealthDashboardController::class,
        'index',
    ])->name('environmental-health.index');

    Route::get('/environmental-health/report-builder', [
        \App\Http\Controllers\EnvironmentalHealth\EnvironmentalHealthDashboardController::class,
        'reportBuilder',
    ])->name('environmental-health.report-builder');

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

    Route::get('/health-records/child-care/export', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'export',
    ])->name('health-records.child-care.export');

    Route::get('/health-records/child-care/report', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'reportBuilder',
    ])->name('health-records.child-care.report-builder');

    Route::get('/health-records/child-care/vitamin-a', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'vitaminA',
    ])->name('health-records.child-care.vitamin-a');

    Route::get('/health-records/child-care/vitamin-a/export', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'exportVitaminA',
    ])->name('health-records.child-care.vitamin-a.export');

    Route::get('/health-records/child-care/vitamin-a/report', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'vitaminAReportBuilder',
    ])->name('health-records.child-care.vitamin-a.report-builder');

    Route::get('/health-records/child-care/deworming/export', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'exportDeworming',
    ])->name('health-records.child-care.deworming.export');

    Route::get('/health-records/child-care/deworming/report', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'dewormingReportBuilder',
    ])->name('health-records.child-care.deworming.report-builder');

    Route::get('/health-records/child-care/deworming', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'deworming',
    ])->name('health-records.child-care.deworming');

    /*
     | Resident Deworming individual record + Add Record (UI-phase).
     | GET only — no store/update until a persistence layer is approved.
     | Resolves DemoCatalog household children matched by monitoring row key.
     */
    Route::get('/health-records/child-care/deworming/{childKey}', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'dewormingShow',
    ])->where('childKey', '[A-Za-z0-9\-]+')->name('health-records.child-care.deworming.show');

    Route::get('/health-records/child-care/deworming/{childKey}/create', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'dewormingCreate',
    ])->where('childKey', '[A-Za-z0-9\-]+')->name('health-records.child-care.deworming.create');

    Route::get('/health-records/child-care/operation-timbang', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'operationTimbang',
    ])->name('health-records.child-care.operation-timbang');

    Route::get('/health-records/child-care/operation-timbang/export', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'exportOperationTimbang',
    ])->name('health-records.child-care.operation-timbang.export');

    Route::get('/health-records/child-care/operation-timbang/report', [
        \App\Http\Controllers\HealthRecords\ChildCareSummaryController::class,
        'operationTimbangReportBuilder',
    ])->name('health-records.child-care.operation-timbang.report-builder');

    Route::get('/health-records/child-care/non-residents', [
        \App\Http\Controllers\HealthRecords\NonResidentChildCareController::class,
        'index',
    ])->name('health-records.child-care.non-residents.index');

    Route::get('/health-records/child-care/non-residents/create', [
        \App\Http\Controllers\HealthRecords\NonResidentChildCareController::class,
        'create',
    ])->name('health-records.child-care.non-residents.create');

    Route::get('/health-records/child-care/non-residents/{childKey}', [
        \App\Http\Controllers\HealthRecords\NonResidentChildCareController::class,
        'show',
    ])->where('childKey', '[A-Za-z0-9\-]+')->name('health-records.child-care.non-residents.show');

    Route::get('/health-records/child-care/non-residents/{childKey}/edit', [
        \App\Http\Controllers\HealthRecords\NonResidentChildCareController::class,
        'edit',
    ])->where('childKey', '[A-Za-z0-9\-]+')->name('health-records.child-care.non-residents.edit');

    Route::get('/health-records/child-care/non-residents/{childKey}/nutrition', [
        \App\Http\Controllers\HealthRecords\NonResidentChildCareController::class,
        'nutrition',
    ])->where('childKey', '[A-Za-z0-9\-]+')->name('health-records.child-care.non-residents.nutrition');

    Route::get('/health-records/child-care/non-residents/{childKey}/nutrition/create', [
        \App\Http\Controllers\HealthRecords\NonResidentChildCareController::class,
        'createMeasurement',
    ])->where('childKey', '[A-Za-z0-9\-]+')->name('health-records.child-care.non-residents.nutrition.create');

    Route::get('/health-records/child-care/non-residents/{childKey}/nutrition/{measurementId}/edit', [
        \App\Http\Controllers\HealthRecords\NonResidentChildCareController::class,
        'editMeasurement',
    ])->where([
        'childKey' => '[A-Za-z0-9\-]+',
        'measurementId' => '[A-Za-z0-9\-]+',
    ])->name('health-records.child-care.non-residents.nutrition.edit');

    Route::get('/health-records/child-care/non-residents/{childKey}/immunization', [
        \App\Http\Controllers\HealthRecords\NonResidentChildCareController::class,
        'immunization',
    ])->where('childKey', '[A-Za-z0-9\-]+')->name('health-records.child-care.non-residents.immunization');

    Route::get('/health-records/child-care/non-residents/{childKey}/immunization/birth-history', [
        \App\Http\Controllers\HealthRecords\NonResidentChildCareController::class,
        'editBirthHistory',
    ])->where('childKey', '[A-Za-z0-9\-]+')->name('health-records.child-care.non-residents.immunization.birth-history');

    Route::get('/health-records/child-care/non-residents/{childKey}/school-based-immunization', [
        \App\Http\Controllers\HealthRecords\NonResidentChildCareController::class,
        'schoolBasedImmunization',
    ])->where('childKey', '[A-Za-z0-9\-]+')->name('health-records.child-care.non-residents.school-based-immunization');

    Route::get('/health-records/child-care/non-residents/{childKey}/child-nutrition', [
        \App\Http\Controllers\HealthRecords\NonResidentChildCareController::class,
        'childNutrition',
    ])->where('childKey', '[A-Za-z0-9\-]+')->name('health-records.child-care.non-residents.child-nutrition');

    Route::get('/health-records/child-care/non-residents/{childKey}/deworming', [
        \App\Http\Controllers\HealthRecords\NonResidentChildCareController::class,
        'deworming',
    ])->where('childKey', '[A-Za-z0-9\-]+')->name('health-records.child-care.non-residents.deworming');

    Route::get('/health-records/child-care/non-residents/{childKey}/deworming/create', [
        \App\Http\Controllers\HealthRecords\NonResidentChildCareController::class,
        'createDeworming',
    ])->where('childKey', '[A-Za-z0-9\-]+')->name('health-records.child-care.non-residents.deworming.create');

    /*
     | Health Records — Risk Assessment barangay-wide summary (UI-phase fixture).
     | Independent of Household Profiling → member Risk Assessment (frozen).
     */
    Route::get('/health-records/risk-assessment', [
        \App\Http\Controllers\HealthRecords\RiskAssessmentSummaryController::class,
        'index',
    ])->name('health-records.risk-assessment.index');

    Route::get('/health-records/risk-assessment/export', [
        \App\Http\Controllers\HealthRecords\RiskAssessmentSummaryController::class,
        'export',
    ])->name('health-records.risk-assessment.export');

    Route::get('/health-records/risk-assessment/report', [
        \App\Http\Controllers\HealthRecords\RiskAssessmentSummaryController::class,
        'reportBuilder',
    ])->name('health-records.risk-assessment.report-builder');

    /*
     | Health Records — Family Planning barangay-wide summary (UI-phase fixture).
     | Independent of Household Profiling → member Family Planning.
     */
    Route::get('/health-records/family-planning', [
        \App\Http\Controllers\HealthRecords\FamilyPlanningSummaryController::class,
        'index',
    ])->name('health-records.family-planning.index');

    Route::get('/health-records/family-planning/report', [
        \App\Http\Controllers\HealthRecords\FamilyPlanningSummaryController::class,
        'reportBuilder',
    ])->name('health-records.family-planning.report-builder');

    Route::get('/health-records/family-planning/export', [
        \App\Http\Controllers\HealthRecords\FamilyPlanningSummaryController::class,
        'export',
    ])->name('health-records.family-planning.export');

    /*
     | Health Records → Family Planning → Non-Resident / unregistered clients.
     | Distinct from Household Profiling → member Family Planning.
     */
    Route::prefix('health-records/family-planning/non-residents')->group(function () {
        Route::get('/', [
            \App\Http\Controllers\HealthRecords\NonResidentFamilyPlanningController::class,
            'index',
        ])->name('health-records.family-planning.non-residents.index');

        Route::get('/export', [
            \App\Http\Controllers\HealthRecords\NonResidentFamilyPlanningController::class,
            'export',
        ])->name('health-records.family-planning.non-residents.export');

        Route::get('/create', [
            \App\Http\Controllers\HealthRecords\NonResidentFamilyPlanningController::class,
            'create',
        ])->name('health-records.family-planning.non-residents.create');

        Route::post('/', [
            \App\Http\Controllers\HealthRecords\NonResidentFamilyPlanningController::class,
            'store',
        ])->name('health-records.family-planning.non-residents.store');

        Route::get('/{clientKey}', [
            \App\Http\Controllers\HealthRecords\NonResidentFamilyPlanningController::class,
            'show',
        ])->where('clientKey', '[a-z0-9\-]+')->name('health-records.family-planning.non-residents.show');

        Route::delete('/{clientKey}', [
            \App\Http\Controllers\HealthRecords\NonResidentFamilyPlanningController::class,
            'destroy',
        ])->where('clientKey', '[a-z0-9\-]+')->name('health-records.family-planning.non-residents.destroy');

        Route::get('/{clientKey}/visits/create', [
            \App\Http\Controllers\HealthRecords\NonResidentFamilyPlanningController::class,
            'createVisit',
        ])->where('clientKey', '[a-z0-9\-]+')->name('health-records.family-planning.non-residents.visits.create');

        Route::post('/{clientKey}/visits', [
            \App\Http\Controllers\HealthRecords\NonResidentFamilyPlanningController::class,
            'storeVisit',
        ])->where('clientKey', '[a-z0-9\-]+')->name('health-records.family-planning.non-residents.visits.store');

        Route::get('/{clientKey}/visits/{visitId}/edit', [
            \App\Http\Controllers\HealthRecords\NonResidentFamilyPlanningController::class,
            'editVisit',
        ])->where(['clientKey' => '[a-z0-9\-]+', 'visitId' => '[A-Za-z0-9\-]+'])
            ->name('health-records.family-planning.non-residents.visits.edit');

        Route::put('/{clientKey}/visits/{visitId}', [
            \App\Http\Controllers\HealthRecords\NonResidentFamilyPlanningController::class,
            'updateVisit',
        ])->where(['clientKey' => '[a-z0-9\-]+', 'visitId' => '[A-Za-z0-9\-]+'])
            ->name('health-records.family-planning.non-residents.visits.update');
    });

    /*
     | Health Records — Maternal Care barangay-wide listing (UI-phase).
     | Independent of Household Profiling → member Maternal Care.
     | Female-only eligibility is enforced in HealthRecordsMaternal.
     */
    Route::get('/health-records/maternal', [
        \App\Http\Controllers\HealthRecords\MaternalSummaryController::class,
        'index',
    ])->name('health-records.maternal.index');

    Route::get('/health-records/maternal/report', [
        \App\Http\Controllers\HealthRecords\MaternalSummaryController::class,
        'reportBuilder',
    ])->name('health-records.maternal.report-builder');

    Route::get('/health-records/maternal/export', [
        \App\Http\Controllers\HealthRecords\MaternalSummaryController::class,
        'export',
    ])->name('health-records.maternal.export');

    Route::get('/health-records/maternal/non-residents', [
        \App\Http\Controllers\HealthRecords\NonResidentMaternalController::class,
        'index',
    ])->name('health-records.maternal.non-residents.index');

    Route::get('/health-records/maternal/non-residents/create', [
        \App\Http\Controllers\HealthRecords\NonResidentMaternalController::class,
        'create',
    ])->name('health-records.maternal.non-residents.create');

    Route::get('/health-records/maternal/non-residents/export', [
        \App\Http\Controllers\HealthRecords\NonResidentMaternalController::class,
        'export',
    ])->name('health-records.maternal.non-residents.export');

    Route::post('/health-records/maternal/non-residents', [
        \App\Http\Controllers\HealthRecords\NonResidentMaternalController::class,
        'store',
    ])->name('health-records.maternal.non-residents.store');

    Route::get('/health-records/maternal/non-residents/{clientKey}', [
        \App\Http\Controllers\HealthRecords\NonResidentMaternalController::class,
        'show',
    ])->where('clientKey', '[A-Za-z0-9\-]+')->name('health-records.maternal.non-residents.show');

    /*
     | Health Records — Death.
     | Listing + resident-scoped submission. Independent of Household Profiling
     | → member Death Information session preview routes.
     */
    Route::get('/health-records/death', [
        \App\Http\Controllers\HealthRecords\DeathSummaryController::class,
        'index',
    ])->name('health-records.death.index');

    Route::get('/health-records/death/residents', [
        \App\Http\Controllers\HealthRecords\DeathSummaryController::class,
        'residents',
    ])->name('health-records.death.residents');

    Route::get('/health-records/death/report', [
        \App\Http\Controllers\HealthRecords\DeathSummaryController::class,
        'reportBuilder',
    ])->name('health-records.death.report-builder');

    Route::get('/health-records/death/export', [
        \App\Http\Controllers\HealthRecords\DeathSummaryController::class,
        'export',
    ])->name('health-records.death.export');

    Route::get('/health-records/death/{householdNo}/{memberId}', [
        \App\Http\Controllers\HealthRecords\DeathRecordController::class,
        'show',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('health-records.death.show');

    Route::post('/health-records/death/{householdNo}/{memberId}', [
        \App\Http\Controllers\HealthRecords\DeathRecordController::class,
        'store',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('health-records.death.store');

    Route::get('/health-records/death/{householdNo}/{memberId}/certificate', [
        \App\Http\Controllers\HealthRecords\DeathRecordController::class,
        'certificate',
    ])->where([
        'householdNo' => HouseholdNumber::ROUTE_CONSTRAINT,
        'memberId' => 'MB-[0-9]+',
    ])->name('health-records.death.certificate');
});
