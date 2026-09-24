<?php

namespace Tests\Feature\Offline;

use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OfflineServiceWorkerContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_worker_source_and_registration_exist(): void
    {
        $this->assertFileExists(resource_path('js/offline/offline-sw.js'));
        $this->assertFileExists(resource_path('js/offline/offline-sw-policy.js'));
        $this->assertFileExists(resource_path('js/offline/offline-sw-runtime.js'));
        $this->assertFileExists(resource_path('js/offline/offline-sw-register.js'));
        $this->assertFileExists(resource_path('js/offline/offline-sw-warmup.js'));
        $this->assertFileExists(resource_path('js/offline/offline-um-warmup.js'));

        $register = (string) file_get_contents(resource_path('js/offline/offline-sw-register.js'));
        $this->assertStringContainsString("navigator.serviceWorker.register", $register);
        $this->assertStringContainsString('/sw.js', $register);
        $this->assertStringContainsString("querySelector('[data-lml-offline-root]')", $register);
        $this->assertStringContainsString("import.meta.env.PROD", $register);
        $this->assertStringContainsString('confirmSessionAndWarmCorePages', $register);
        $this->assertStringContainsString("offline-sw-warmup", $register);
        $this->assertStringNotContainsString('location.reload', $register);
        $this->assertStringNotContainsString('indexedDB.deleteDatabase', $register);

        $appJs = (string) file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("import './offline/offline-sw-register';", $appJs);
        $this->assertStringContainsString("import './offline/offline-hp-bootstrap';", $appJs);
    }

    public function test_built_service_worker_uses_unhashed_public_path_when_present(): void
    {
        $swPath = public_path('sw.js');
        if (! is_file($swPath)) {
            $this->markTestSkipped('public/sw.js is produced by npm run build.');
        }

        $sw = (string) file_get_contents($swPath);
        $this->assertStringContainsString('offline-7-v', $sw);
        $this->assertStringContainsString('offline-7-v14', $sw);
        $this->assertStringContainsString('lmlinga-assets-', $sw);
        $this->assertStringContainsString('lmlinga-avatar-', $sw);
        $this->assertStringContainsString('/assets/images/logo/logo.png', $sw);
        $this->assertStringContainsString('/assets/images/logo/LMLogo.png', $sw);
        $this->assertStringContainsString('lmlinga:warmup-core', $sw);
        $this->assertStringContainsString('/dashboard', $sw);
        $this->assertStringContainsString('/spot-mapping', $sw);
        $this->assertStringContainsString('/announcements', $sw);
        $this->assertStringContainsString('/environmental-health', $sw);
        $this->assertStringContainsString('/health-records/child-care', $sw);
        $this->assertStringContainsString('/user-management', $sw);
        $this->assertStringContainsString('/offline/status', $sw);
        $this->assertStringContainsString('/offline/sync', $sw);
        $this->assertStringNotContainsString('indexedDB.deleteDatabase', $sw);
    }

    public function test_supported_field_routes_exist_and_excluded_families_are_real(): void
    {
        foreach ([
            'dashboard',
            'household-profiling.index',
            'household-profiling.create',
            'household-profiling.view',
            'household-profiling.edit',
            'household-profiling.members.create',
            'household-profiling.members.edit',
            'spot-mapping.index',
            'spot-mapping.plot-new',
            'announcements.index',
            'announcements.show',
            'announcements.upcoming',
            'announcements.recent',
            'environmental-health.index',
            'health-records.child-care.index',
            'health-records.risk-assessment.index',
            'health-records.maternal.index',
            'health-records.death.index',
            'health-records.family-planning.index',
            'profile.show',
            'user-management.index',
            'household-requests.index',
            'death-requests.index',
        ] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name), $name);
        }

        foreach ([
            'login',
            'login.store',
            'logout',
            'password.request',
            'password.reset',
            'password.change.required',
            'offline.status',
            'offline.sync',
            'offline.household-profiling-bootstrap',
            'offline.environmental-health-shell',
            'household-profiling.export',
            'health-records.child-care.index',
            'chatbot.login',
            'chatbot.household.verification.sms',
        ] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name), $name);
        }

        $this->assertSame('GET', Route::getRoutes()->getByName('offline.status')?->methods()[0] ?? null);
        $this->assertSame('POST', Route::getRoutes()->getByName('offline.sync')?->methods()[0] ?? null);
        $this->assertSame('POST', Route::getRoutes()->getByName('logout')?->methods()[0] ?? null);
    }

    public function test_policy_excludes_mutations_auth_csrf_and_sensitive_paths(): void
    {
        $policy = (string) file_get_contents(resource_path('js/offline/offline-sw-policy.js'));

        $this->assertStringContainsString("MUTATION_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE']", $policy);
        $this->assertStringContainsString("'/offline'", $policy);
        $this->assertStringContainsString("'/login'", $policy);
        $this->assertStringContainsString("'/logout'", $policy);
        $this->assertStringContainsString("'/change-password'", $policy);
        $this->assertStringContainsString("'/chatbot'", $policy);
        $this->assertStringContainsString('/offline/sync', $policy);
        $this->assertStringContainsString('/offline/status', $policy);
        $this->assertStringContainsString('csrf-token', $policy);
        $this->assertStringContainsString('HTML_CACHE_PREFIX', $policy);
        $this->assertStringContainsString('AVATAR_CACHE_PREFIX', $policy);
        $this->assertStringContainsString('isSameOrigin', $policy);
        $this->assertStringContainsString("UNSAFE_QUERY_KEYS", $policy);
        $this->assertStringContainsString('warmupPathsForRole', $policy);
        $this->assertStringContainsString('WARMUP_PATHS_SHARED', $policy);
        $this->assertStringContainsString('WARMUP_PATHS_ADMIN', $policy);

        $this->assertStringContainsString('household-profiling', $policy);
        $this->assertStringContainsString('spot-mapping', $policy);
        $this->assertStringContainsString('dashboard', $policy);
        $this->assertStringContainsString('/assets/images/logo/logo.png', $policy);
        $this->assertStringContainsString('/assets/images/logo/LMLogo.png', $policy);
        $this->assertStringContainsString("'/storage'", $policy);
        $this->assertStringContainsString("'/dashboard'", $policy);
        $this->assertStringContainsString("'/spot-mapping'", $policy);
        $this->assertStringContainsString("'/household-profiling'", $policy);
        $this->assertStringContainsString("'/announcements'", $policy);
        $this->assertStringContainsString("'/environmental-health'", $policy);
        $this->assertStringContainsString("'/health-records/child-care'", $policy);
        $this->assertStringContainsString("'/user-management'", $policy);
        $this->assertStringContainsString("'/household-requests'", $policy);
        $this->assertStringContainsString("'/death-requests'", $policy);
        $this->assertStringContainsString("'/announcements/create'", $policy);
        $this->assertStringContainsString('MESSAGE_WARMUP_CORE', $policy);
        $this->assertStringContainsString('MESSAGE_WARMUP_UM_WORKERS', $policy);
        $this->assertStringContainsString('MESSAGE_WARMUP_RELATED', $policy);
        $this->assertStringContainsString('offline-7-v14', $policy);
        $this->assertStringContainsString('Plot New Household', $policy);
        $this->assertStringNotContainsString('offline-7-v6', $policy);
        $this->assertStringNotContainsString('offline-7-v3', $policy);

        $warmup = (string) file_get_contents(resource_path('js/offline/offline-sw-warmup.js'));
        $this->assertStringContainsString('/offline/status', $warmup);
        $this->assertStringContainsString('MESSAGE_WARMUP_CORE', $warmup);
        $this->assertStringContainsString('warmupPathsForRole', $warmup);
        $this->assertStringContainsString('payload.role', $warmup);
        $this->assertStringNotContainsString('/login', $warmup);
        $this->assertStringNotContainsString('paths:', $warmup);

        $umWarmup = (string) file_get_contents(resource_path('js/offline/offline-um-warmup.js'));
        $this->assertStringContainsString('MESSAGE_WARMUP_UM_WORKERS', $umWarmup);
        $this->assertStringContainsString('/offline/status', $umWarmup);
        $this->assertStringNotContainsString('enqueueOperation', $umWarmup);
        $this->assertStringNotContainsString('/offline/sync', $umWarmup);
        $this->assertStringNotContainsString('HEALTH_WORKER_CREATE', $umWarmup);
    }

    public function test_plot_new_household_lives_on_spot_mapping_not_household_create(): void
    {
        $user = $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('spot-mapping.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-lml-offline-root', $html);
        $this->assertStringContainsString('data-offline-actor-id="'.$user->getKey().'"', $html);
        $this->assertStringContainsString('data-spot-map-plot', $html);
        $this->assertStringContainsString('data-plot-new-url="'.e(route('spot-mapping.plot-new')).'"', $html);
        $this->assertStringContainsString('name="first_name"', $html);
        $this->assertStringNotContainsString(route('household-profiling.create'), $html);

        $this->assertSame('POST', Route::getRoutes()->getByName('spot-mapping.plot-new')?->methods()[0] ?? null);
    }

    public function test_announcement_index_is_server_rendered_read_html(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('announcements.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-lml-offline-root', $html);
        $this->assertStringContainsString('data-lml-announce-manage', $html);
        $this->assertStringContainsString(route('announcements.create'), $html);

        $manageJs = (string) file_get_contents(resource_path('js/pages/announcement-manage.js'));
        $listJs = (string) file_get_contents(resource_path('js/pages/announcement-list.js'));
        $this->assertStringNotContainsString('fetch(', $manageJs);
        $this->assertStringNotContainsString('XMLHttpRequest', $manageJs);
        $this->assertStringNotContainsString('fetch(', $listJs);
    }

    public function test_guest_login_does_not_expose_offline_root_used_for_sw_registration(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-lml-offline-root', $html);
        $this->assertStringNotContainsString('data-offline-actor-id', $html);
    }

    public function test_authenticated_shell_exposes_actor_for_html_cache_isolation(): void
    {
        $user = $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('household-profiling.create'))->assertOk()->getContent();

        $this->assertStringContainsString('data-lml-offline-root', $html);
        $this->assertStringContainsString('data-offline-actor-id="'.$user->getKey().'"', $html);
        $this->assertStringNotContainsString('data-offline-csrf', $html);
    }

    public function test_staff_can_open_shared_warmup_pages_and_admin_only_pages_are_forbidden(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        foreach ([
            'dashboard',
            'spot-mapping.index',
            'household-profiling.index',
            'announcements.index',
            'environmental-health.index',
            'health-records.child-care.index',
            'health-records.risk-assessment.index',
            'health-records.maternal.index',
            'health-records.death.index',
            'health-records.family-planning.index',
            'profile.show',
        ] as $name) {
            $this->get(route($name))->assertOk();
        }

        $this->get(route('user-management.index'))->assertForbidden();
        $this->get(route('household-requests.index'))->assertForbidden();
        $this->get(route('death-requests.index'))->assertForbidden();

        $this->actingAsStaff(StaffRole::ADMIN);
        $this->get(route('user-management.index'))->assertOk();
        $this->get(route('household-requests.index'))->assertOk();
        $this->get(route('death-requests.index'))->assertOk();
        $this->get(route('environmental-health.index'))->assertOk();
    }

    public function test_topbar_avatar_exposes_actor_url_and_deterministic_fallback(): void
    {
        $user = $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('data-lml-offline-root', $html);

        $topbar = (string) file_get_contents(resource_path('views/components/lml/dashboard/topbar.blade.php'));
        $this->assertStringContainsString('data-lml-topbar-avatar', $topbar);
        $this->assertStringContainsString('lml-topbar__avatar-fallback', $topbar);
        $this->assertStringContainsString('onerror=', $topbar);
        $this->assertStringContainsString('bi-person-fill', $topbar);

        $layout = (string) file_get_contents(resource_path('views/layouts/dashboard.blade.php'));
        $this->assertStringContainsString('data-offline-actor-avatar', $layout);

        $register = (string) file_get_contents(resource_path('js/offline/offline-sw-register.js'));
        $this->assertStringContainsString('bindTopbarAvatarFallback', $register);
        $this->assertStringContainsString('data-lml-topbar-avatar', $register);

        $this->assertStringContainsString('data-offline-actor-id="'.$user->getKey().'"', $html);
    }

    public function test_queue_modules_are_unchanged_by_service_worker_layer(): void
    {
        $db = (string) file_get_contents(resource_path('js/offline/offline-db.js'));
        $register = (string) file_get_contents(resource_path('js/offline/offline-sw-register.js'));
        $runtime = (string) file_get_contents(resource_path('js/offline/offline-sw-runtime.js'));

        $this->assertStringContainsString("lmlinga_offline", $db);
        $this->assertStringContainsString('indexedDB', $db);
        $this->assertStringNotContainsString('indexedDB.deleteDatabase', $register);
        $this->assertStringNotContainsString('indexedDB.deleteDatabase', $runtime);
        $this->assertStringNotContainsString('localStorage.setItem', $runtime);
    }

    public function test_spot_mapping_does_not_bulk_cache_map_tiles(): void
    {
        $spot = (string) file_get_contents(resource_path('js/pages/spot-mapping.js'));
        $runtime = (string) file_get_contents(resource_path('js/offline/offline-sw-runtime.js'));
        $base = (string) file_get_contents(resource_path('js/maps/la-medalla-base.js'));

        $this->assertStringContainsString("map.on('tileerror'", $spot);
        $this->assertStringContainsString('queuePlotNewHousehold', $spot);
        $this->assertStringContainsString('tile.openstreetmap.org', $base);
        $this->assertStringNotContainsString('tile.openstreetmap.org', $runtime);
        $this->assertStringNotContainsString('openstreetmap.org/{z}/{x}/{y}', $runtime);
    }
}
