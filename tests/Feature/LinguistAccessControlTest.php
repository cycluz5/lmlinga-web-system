<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureLinguistScope;
use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use App\Models\WorkerAppointment;
use App\Support\DemoStaffLogin;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * SR-06 Linguist role: deny-by-default access to every staff route except Language Review, own profile,
 * password change and logout; Language Review for Linguists only (Admin excluded, D-7/LQ-2).
 */
class LinguistAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private const PRIVATE_NAME = 'Zyxwvut';

    private function seedResidentData(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-777', 'zone' => 'Zone 1']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-777',
            'first_name' => self::PRIVATE_NAME,
            'last_name' => 'Privaterecord',
            'relation' => 'Head',
            'birthday' => '1990-01-01',
            'sex' => 'Female',
        ]);
    }

    /**
     * A dummy value satisfying the route's parameter constraint (so the request really reaches the route).
     */
    private function parameterValue(RoutingRoute $route, string $name): string
    {
        $pattern = $route->wheres[$name] ?? null;
        foreach (['1', 'HH-777', 'MB-777', 'req-1', 'abc', '2024', 'child-care', 'x'] as $candidate) {
            if ($pattern === null || preg_match('#^(?:'.$pattern.')$#', $candidate) === 1) {
                return $candidate;
            }
        }

        return '1';
    }

    /**
     * @return list<array{method: string, uri: string, name: string}>
     */
    private function staffRouteRequests(): array
    {
        $requests = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('auth', $route->gatherMiddleware(), true)) {
                continue;
            }
            $name = (string) $route->getName();
            if ($name === '') {
                continue;
            }
            $parameters = [];
            foreach ($route->parameterNames() as $parameter) {
                $parameters[$parameter] = $this->parameterValue($route, $parameter);
            }
            // route() applies opaque URL encoding, so the request reaches the route like a real link.
            $uri = route($name, $parameters, false);
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $requests[] = ['method' => $method, 'uri' => $uri, 'name' => $name];
            }
        }

        return $requests;
    }

    public function test_every_staff_route_is_refused_for_a_linguist_except_the_allowlist(): void
    {
        $this->seedResidentData();
        $this->actingAsStaff(StaffRole::LINGUIST);

        $checked = 0;
        $failures = [];
        foreach ($this->staffRouteRequests() as $request) {
            if (in_array($request['name'], EnsureLinguistScope::ALLOWED_ROUTES, true) || $request['name'] === 'dashboard'
                || str_starts_with($request['name'], 'language-review.')) {
                continue;
            }
            $response = $this->call($request['method'], $request['uri']);
            $checked++;
            if ($response->getStatusCode() !== 403 || str_contains((string) $response->getContent(), self::PRIVATE_NAME)) {
                $failures[] = $request['method'].' '.$request['uri'].' ('.$request['name'].') → '.$response->getStatusCode();
            }
        }

        // A 404 is acceptable only when the dummy URL never reaches the route for anyone (routing-level
        // 404 before any controller runs); confirm by replaying it as an Admin.
        $notFound = array_filter($failures, static fn (string $line): bool => str_ends_with($line, '→ 404'));
        if ($notFound !== []) {
            $this->actingAsStaff(StaffRole::ADMIN, ['email' => 'matrix.admin@example.test', 'username' => 'matrix.admin']);
            foreach ($notFound as $key => $line) {
                [$method, $uri] = explode(' ', $line, 3);
                if ($this->call($method, $uri)->getStatusCode() === 404) {
                    unset($failures[$key]);
                }
            }
            $failures = array_values($failures);
        }

        $this->assertGreaterThan(150, $checked, 'the matrix must cover the staff routes');
        $this->assertSame([], $failures, "Linguist reached staff routes:\n".implode("\n", $failures));
    }

    public function test_resident_records_and_offline_endpoints_are_refused_with_no_data(): void
    {
        $this->seedResidentData();
        $this->actingAsStaff(StaffRole::LINGUIST);

        foreach ([
            route('household-profiling.index'),
            route('household-profiling.view', ['householdNo' => 'HH-777']),
            route('household-profiling.members.show', ['householdNo' => 'HH-777', 'memberId' => 'MB-777']),
            route('spot-mapping.index'),
            route('user-management.index'),
            route('household-requests.index'),
            route('announcements.index'),
        ] as $url) {
            $response = $this->get($url);
            $response->assertForbidden();
            $this->assertStringNotContainsString(self::PRIVATE_NAME, (string) $response->getContent(), $url);
        }

        $this->getJson(route('offline.status'))->assertForbidden()->assertJson(['message' => EnsureLinguistScope::DENIED_MESSAGE]);
        $this->postJson(route('offline.sync'), ['operations' => []])->assertForbidden();
        $this->getJson(route('offline.household-profiling-bootstrap'))->assertForbidden();
    }

    public function test_linguist_sees_only_language_review_with_empty_queues(): void
    {
        $this->actingAsStaff(StaffRole::LINGUIST);

        $html = $this->get(route('language-review.index'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'No items are waiting for review.'));
        $this->assertStringContainsString('Bikol-Iriga terms', $html);
        $this->assertStringContainsString('Chatbot datasets', $html);
        $this->assertMatchesRegularExpression('/data-lml-language-review-count>\s*0\s*</', $html);
        $this->assertStringContainsString('Language Review', $html);
        // Sidebar shows no health-worker destinations; no offline machinery for Linguists.
        foreach (['Household Profiling', 'Health Records', 'Spot Mapping', 'User Management'] as $label) {
            $this->assertStringNotContainsString('>'.$label.'<', $html, $label);
        }
        $this->assertStringNotContainsString('data-lml-offline-root', $html);
    }

    public function test_linguist_allowlist_profile_password_dashboard_redirect_and_logout(): void
    {
        $this->actingAsStaff(StaffRole::LINGUIST);

        $this->get(route('dashboard'))->assertRedirect(route('language-review.index'));
        $this->get(route('profile.show'))->assertOk()->assertSee('Linguist (Language Review)')->assertDontSee('data-lml-offline-root', false);
        $this->get(route('password.change.required'))->assertOk();
        $this->post(route('logout'))->assertRedirect();
    }

    public function test_admin_and_health_workers_cannot_open_language_review_but_keep_their_modules(): void
    {
        foreach ([StaffRole::ADMIN, StaffRole::BHW, StaffRole::BNS, StaffRole::BSPO] as $role) {
            $this->actingAsStaff($role, ['email' => $role.'.sr06@example.test', 'username' => $role.'.sr06']);
            $this->get(route('language-review.index'))->assertForbidden();
            $this->get(route('dashboard'))->assertOk();
            $this->get(route('household-profiling.index'))->assertOk();
            $this->get(route('spot-mapping.index'))->assertOk();
        }

        $this->actingAsStaff(StaffRole::ADMIN, ['email' => 'admin2.sr06@example.test', 'username' => 'admin2.sr06']);
        $this->get(route('user-management.index'))->assertOk();
        $this->get(route('household-requests.index'))->assertOk();
    }

    public function test_review_gate_allows_only_an_active_linguist(): void
    {
        $linguist = $this->actingAsStaff(StaffRole::LINGUIST);
        $this->assertTrue(Gate::forUser($linguist)->allows('review-language-content'));

        $admin = $this->actingAsStaff(StaffRole::ADMIN, ['email' => 'gate.admin@example.test', 'username' => 'gate.admin']);
        $this->assertFalse(Gate::forUser($admin)->allows('review-language-content'));

        $inactive = $this->actingAsStaff(StaffRole::LINGUIST, ['email' => 'gate.inactive@example.test', 'username' => 'gate.inactive', 'status' => StaffAccountStatus::INACTIVE]);
        $this->assertFalse(Gate::forUser($inactive)->allows('review-language-content'));
    }

    public function test_inactive_linguist_cannot_use_language_review(): void
    {
        $this->actingAsStaff(StaffRole::LINGUIST, ['status' => StaffAccountStatus::INACTIVE]);

        $response = $this->get(route('language-review.index'));
        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function test_role_is_read_from_the_account_not_a_stale_session_value(): void
    {
        $user = $this->actingAsStaff(StaffRole::BHW, ['email' => 'switch.role@example.test', 'username' => 'switch.role']);
        $this->get(route('household-profiling.index'))->assertOk();

        // Admin changes the account to Linguist; a stale "bhw" session value must not keep access.
        WorkerAppointment::query()->where('user_id', $user->id)->update(['role' => StaffRole::LINGUIST]);
        $fresh = $user->fresh(['currentAppointment']);
        $this->actingAs($fresh);
        $this->withSession([UiRole::SESSION_KEY => StaffRole::BHW]);

        $this->get(route('household-profiling.index'))->assertForbidden();
        $this->get(route('language-review.index'))->assertOk();
    }

    public function test_real_login_sends_a_linguist_to_language_review(): void
    {
        $user = User::factory()->create([
            'email' => 'login.linguist@example.test', 'username' => 'login.linguist',
            'password' => 'Linguist!Pass1', 'status' => StaffAccountStatus::ACTIVE, 'must_change_password' => false,
        ]);
        $user->assignCurrentAppointment(['role' => StaffRole::LINGUIST, 'assigned_barangay' => 'La Medalla', 'date_appointed' => '2026-10-01']);

        $this->post(route('login.store'), ['email' => 'login.linguist@example.test', 'password' => 'Linguist!Pass1'])
            ->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('language-review.index'));
        $this->get(route('language-review.index'))->assertOk();
    }

    public function test_ui_role_never_falls_back_to_a_health_worker_shell_for_a_linguist(): void
    {
        $this->actingAsStaff(StaffRole::LINGUIST);

        $this->assertSame('linguist', UiRole::shellRole());
        $this->assertSame('Linguist', UiRole::label());
        $this->assertFalse(UiRole::isAdmin());
        $this->assertNotContains('linguist', UiRole::HEALTH_WORKER_ROLES);
        $this->assertNotContains(StaffRole::LINGUIST, StaffRole::HEALTH_WORKERS);
    }

    private function actingAsAdminSession(): User
    {
        $admin = $this->actingAsStaff(StaffRole::ADMIN, ['email' => 'um.admin@example.test', 'username' => 'um.admin']);
        $this->withSession([UiRole::SESSION_KEY => StaffRole::ADMIN, DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true]);

        return $admin;
    }

    public function test_admin_creates_a_linguist_account_without_zones(): void
    {
        $this->actingAsAdminSession();

        $this->post(route('user-management.health-workers.store'), [
            'first_name' => 'Lina', 'last_name' => 'Wikaan', 'middle_name' => 'B',
            'email' => 'new.linguist@example.test', 'mobile' => '09171113333',
            'role' => 'Linguist', 'status' => StaffAccountStatus::ACTIVE,
            'password' => 'TempPass!123', 'password_confirmation' => 'TempPass!123',
        ])->assertRedirect(route('user-management.index', ['tab' => 'workers']));

        $user = User::query()->where('email', 'new.linguist@example.test')->firstOrFail();
        $this->assertSame(StaffRole::LINGUIST, $user->fresh()->role);
        $this->assertTrue((bool) $user->must_change_password);

        $this->get(route('user-management.health-workers.view', ['id' => (string) $user->id]))
            ->assertOk()
            ->assertSee('Linguist');
    }

    public function test_admin_edits_a_linguist_without_zones_but_bhw_zone_rules_are_unchanged(): void
    {
        $this->actingAsAdminSession();
        $payload = [
            'sex' => 'Female', 'hw_first_name' => 'Lina', 'hw_last_name' => 'Wikaan', 'hw_middle_name' => 'B', 'hw_suffix' => 'N/A',
            'hw_dob' => '1990-02-02', 'hw_civil_status' => 'Single', 'hw_nationality' => 'Filipino', 'hw_mobile' => '09179876543',
            'hw_house_no' => '9', 'hw_street' => 'Sampaguita St.', 'hw_purok_zone' => 'Zone 2', 'hw_barangay' => 'La Medalla',
            'hw_municipality' => 'Iriga City', 'hw_province' => 'Camarines Sur', 'hw_zip' => '4431',
            'hw_assigned_barangay' => 'La Medalla', 'hw_date_appointed' => '2026-10-01', 'hw_end_appointment' => '',
            'hw_status' => StaffAccountStatus::ACTIVE,
        ];

        $linguist = User::factory()->create(['email' => 'edit.linguist@example.test', 'username' => 'edit.linguist', 'status' => StaffAccountStatus::ACTIVE]);
        $linguist->assignCurrentAppointment(['role' => StaffRole::LINGUIST, 'assigned_barangay' => 'La Medalla', 'date_appointed' => '2026-10-01']);
        $id = (string) $linguist->id;

        $this->put(route('user-management.health-workers.update', ['id' => $id]), $payload + [
            'hw_role' => 'Linguist', 'hw_email' => 'edit.linguist@example.test', 'hw_username' => 'edit.linguist',
            'hw_assigned_zone' => ['Zone 3'], // ignored for Linguist
        ])->assertSessionHasNoErrors();
        $this->assertSame(StaffRole::LINGUIST, $linguist->fresh()->role);
        $this->assertSame([], $linguist->fresh(['currentAppointment'])->currentAppointment?->assignedZoneLabels() ?? []);

        $bhw = User::factory()->create(['email' => 'edit.bhw@example.test', 'username' => 'edit.bhw', 'status' => StaffAccountStatus::ACTIVE]);
        $bhw->assignCurrentAppointment(['role' => StaffRole::BHW, 'assigned_barangay' => 'La Medalla', 'assigned_zone' => 'Zone 1', 'date_appointed' => '2020-01-01']);
        $this->put(route('user-management.health-workers.update', ['id' => (string) $bhw->id]), $payload + [
            'hw_role' => 'BHW', 'hw_email' => 'edit.bhw@example.test', 'hw_username' => 'edit.bhw',
        ])->assertSessionHasErrors('hw_assigned_zone');
    }
}
