<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureNotInDemoLocation;
use App\Models\Household;
use App\Models\User;
use App\Support\DemoStaffLogin;
use App\Support\Geo\BarangayBoundary;
use App\Support\Geo\DemoLocation;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SR-10 Phase A — Demo Location Mode foundation. Uses a FICTIONAL test polygon and FICTIONAL test points
 * in temporary files only (far from the Philippines: lng 100..101, lat 10..12). No real boundary or
 * La Medalla coordinate is created.
 */
class SpotMappingDemoLocationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    /** @var array<string, User> */
    private array $staff = [];

    private const RECT = [[100.0, 10.0], [101.0, 10.0], [101.0, 12.0], [100.0, 12.0], [100.0, 10.0]];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        BarangayBoundary::flushCache();
        parent::tearDown();
    }

    private function tempFile(string $contents, string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** Gate on + fictional boundary + fictional points (one inside, one outside). */
    private function configureDemo(bool $gate = true, bool $boundary = true, ?array $points = null): void
    {
        $points ??= [
            ['key' => 'test-inside', 'label' => 'Test point inside (fictional)', 'lat' => 11.0, 'lng' => 100.5, 'source' => 'Test fixture (fictional)'],
            ['key' => 'test-outside', 'label' => 'Test point outside (fictional)', 'lat' => 13.5, 'lng' => 102.0, 'source' => 'Test fixture (fictional)'],
        ];
        config([
            'lmlinga.demo_location.enabled' => $gate,
            'lmlinga.geofence.boundary_path' => $boundary
                ? $this->tempFile(json_encode(['type' => 'Feature', 'properties' => ['source' => 'Test fixture (fictional)'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [self::RECT]]]), 'lml-demo-boundary-')
                : sys_get_temp_dir().DIRECTORY_SEPARATOR.'lml-no-boundary-'.bin2hex(random_bytes(4)).'.geojson',
            'lmlinga.demo_location.points_path' => $this->tempFile(json_encode(['points' => $points]), 'lml-demo-points-'),
        ]);
        BarangayBoundary::flushCache();
    }

    private function as(string $role, string $username, array $extra = []): User
    {
        if (! isset($this->staff[$username])) {
            return $this->staff[$username] = $this->actingAsStaff($role, $extra + ['email' => $username.'@example.test', 'username' => $username]);
        }
        $user = $this->staff[$username];
        $this->actingAs($user);
        $user->syncUiRoleSession();
        UiRole::set($role);
        session([
            Auth::guard()->getName() => $user->getAuthIdentifier(),
            DemoStaffLogin::SESSION_DISPLAY_NAME => $user->composeDisplayName(),
            DemoStaffLogin::SESSION_EMAIL => (string) $user->email,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        return $user;
    }

    private function admin(): User
    {
        return $this->as(StaffRole::ADMIN, 'demo.admin');
    }

    public function test_off_by_default_with_no_toggle_and_real_gps_page(): void
    {
        $this->assertFalse(config('lmlinga.demo_location.enabled'));
        $this->assertFalse(DemoLocation::availability()['available'], 'gate off: unavailable even with the shipped files');

        $this->admin();
        $html = $this->get(route('spot-mapping.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-spot-map-demo-panel', $html);
        $this->assertStringNotContainsString('data-demo-location', $html);
        $this->assertStringNotContainsString('DEMO / SIMULATED LOCATION', $html);

        $this->post(route('spot-mapping.demo-location.enable'), ['point' => 'test-inside'])->assertNotFound();
        $this->assertNull(session(DemoLocation::SESSION_KEY));
    }

    public function test_fails_closed_without_the_official_boundary_or_verified_points(): void
    {
        // Gate on but no boundary file (today's real situation).
        $this->configureDemo(boundary: false);
        $this->assertStringContainsString('boundary is not available', (string) DemoLocation::availability()['reason']);
        $this->admin();
        $this->get(route('spot-mapping.index'))->assertOk()->assertSee('Unavailable:')->assertDontSee('data-demo-location', false);
        $this->post(route('spot-mapping.demo-location.enable'), ['point' => 'test-inside'])->assertSessionHasErrors('demo_location');
        $this->assertNull(session(DemoLocation::SESSION_KEY));

        // Invalid boundary JSON.
        config(['lmlinga.geofence.boundary_path' => $this->tempFile('{not json', 'lml-demo-bad-')]);
        BarangayBoundary::flushCache();
        $this->assertFalse(DemoLocation::availability()['available']);

        // Valid boundary, but points missing / invalid / all outside.
        $this->configureDemo();
        config(['lmlinga.demo_location.points_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'lml-no-points-'.bin2hex(random_bytes(4)).'.json']);
        $this->assertStringContainsString('No verified demo coordinates', (string) DemoLocation::availability()['reason']);
        foreach ([
            '{"points":[{"key":"Bad Key","label":"x","lat":11,"lng":100.5,"source":"t"}]}',
            '{"points":[{"key":"no-source","label":"x","lat":11,"lng":100.5}]}',
            '{"points":[{"key":"bad-lat","label":"x","lat":95,"lng":100.5,"source":"t"}]}',
            '{"points":[]}',
        ] as $json) {
            config(['lmlinga.demo_location.points_path' => $this->tempFile($json, 'lml-demo-pts-')]);
            $this->assertFalse(DemoLocation::availability()['available'], $json);
        }
        $this->configureDemo(points: [['key' => 'only-outside', 'label' => 'Outside (fictional)', 'lat' => 13.5, 'lng' => 102.0, 'source' => 't']]);
        $availability = DemoLocation::availability();
        $this->assertFalse($availability['available']);
        $this->assertSame(1, $availability['excluded']);
    }

    public function test_admin_enables_inside_point_sees_banner_and_simulated_payload(): void
    {
        $this->configureDemo();
        $availability = DemoLocation::availability();
        $this->assertSame(['test-inside'], array_column($availability['points'], 'key'), 'outside point excluded');

        $this->admin();
        $html = $this->get(route('spot-mapping.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Turn on Demo Mode', $html);
        $this->assertStringNotContainsString('test-outside', $html);

        $this->post(route('spot-mapping.demo-location.enable'), ['point' => 'test-inside'])->assertRedirect(route('spot-mapping.index'));
        $html = $this->get(route('spot-mapping.index'))->assertOk()->getContent();
        $this->assertStringContainsString('DEMO / SIMULATED LOCATION', $html);
        $this->assertStringContainsString('Turn off Demo Mode', $html);
        $this->assertMatchesRegularExpression('/data-demo-location=\'\{"key":"test-inside".*"lat":11.*"lng":100.5/', $html);

        // Outside or unknown point refused.
        $this->delete(route('spot-mapping.demo-location.disable'));
        $this->post(route('spot-mapping.demo-location.enable'), ['point' => 'test-outside'])->assertSessionHasErrors('demo_location');
        $this->post(route('spot-mapping.demo-location.enable'), ['point' => 'not-configured'])->assertSessionHasErrors('demo_location');
        $this->assertNull(session(DemoLocation::SESSION_KEY));
    }

    public function test_only_admins_can_enable_and_other_users_never_see_demo_state(): void
    {
        $this->configureDemo();

        foreach ([StaffRole::BHW, StaffRole::BNS, StaffRole::BSPO, StaffRole::LINGUIST] as $role) {
            $this->as($role, $role.'.demo');
            $this->post(route('spot-mapping.demo-location.enable'), ['point' => 'test-inside'])->assertForbidden();
            $this->delete(route('spot-mapping.demo-location.disable'))->assertForbidden();
            $this->assertNull(session(DemoLocation::SESSION_KEY), $role);
        }

        $this->as(StaffRole::BHW, StaffRole::BHW.'.demo');
        $html = $this->get(route('spot-mapping.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-spot-map-demo-panel', $html);

        $this->as(StaffRole::ADMIN, 'inactive.admin', ['status' => StaffAccountStatus::INACTIVE]);
        $this->assertFalse(DemoLocation::isActiveAdmin(User::query()->where('username', 'inactive.admin')->first()));

        auth()->logout();
        $this->flushSession();
        $this->post(route('spot-mapping.demo-location.enable'), ['point' => 'test-inside'])->assertRedirect(route('login'));
    }

    public function test_session_scope_expiry_disable_and_logout_return_to_real_gps(): void
    {
        $this->configureDemo();
        $admin = $this->admin();
        $this->post(route('spot-mapping.demo-location.enable'), ['point' => 'test-inside']);
        $state = session(DemoLocation::SESSION_KEY);
        $this->assertSame((string) $admin->getKey(), $state['user_id']);
        $this->assertLessThanOrEqual(now()->addMinutes(120)->getTimestamp(), $state['expires_at']);

        // Disable → Real GPS.
        $this->delete(route('spot-mapping.demo-location.disable'))->assertRedirect(route('spot-mapping.index'));
        $this->get(route('spot-mapping.index'))->assertDontSee('data-demo-location', false);

        // Expiry after at most 2 hours → Real GPS on the next page load.
        $this->post(route('spot-mapping.demo-location.enable'), ['point' => 'test-inside']);
        $this->travel(121)->minutes();
        $this->get(route('spot-mapping.index'))->assertOk()->assertDontSee('data-demo-location', false);
        $this->assertNull(session(DemoLocation::SESSION_KEY));
        $this->travelBack();

        // Another Admin's session never inherits it (state bound to the enabling user).
        $this->post(route('spot-mapping.demo-location.enable'), ['point' => 'test-inside']);
        $this->as(StaffRole::ADMIN, 'other.admin');
        $this->get(route('spot-mapping.index'))->assertOk()->assertDontSee('data-demo-location', false);

        // Logout clears it.
        $this->admin();
        $this->post(route('spot-mapping.demo-location.enable'), ['point' => 'test-inside']);
        $this->post(route('logout'));
        $this->assertNull(session(DemoLocation::SESSION_KEY));

        // TTL setting can never exceed 2 hours.
        config(['lmlinga.demo_location.ttl_minutes' => 999]);
        $this->assertSame(120, DemoLocation::ttlMinutes());
    }

    public function test_simulated_coordinates_are_never_saved_and_records_stay_unchanged(): void
    {
        $this->configureDemo();
        $household = Household::factory()->create(['household_no' => 'HH-901', 'zone' => 'Zone 1', 'latitude' => 13.3801, 'longitude' => 123.4301]);
        $before = DB::table('households')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->admin();
        $this->post(route('spot-mapping.demo-location.enable'), ['point' => 'test-inside']);

        foreach (['spot-mapping.plot', 'spot-mapping.plot-handoff', 'spot-mapping.plot-new'] as $route) {
            $this->postJson(route($route), ['household_no' => 'HH-901', 'lat' => 11.0, 'lng' => 100.5, 'confirm_replot' => true])
                ->assertStatus(409)
                ->assertJson(['message' => EnsureNotInDemoLocation::MESSAGE, 'demo_location' => true]);
        }

        // Even after expiry, a stale page cannot save until Spot Mapping is reloaded (state still present).
        $this->travel(121)->minutes();
        $this->postJson(route('spot-mapping.plot'), ['household_no' => 'HH-901', 'lat' => 11.0, 'lng' => 100.5, 'confirm_replot' => true])->assertStatus(409);
        $this->travelBack();

        $after = DB::table('households')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->assertSame($before, $after, 'household rows unchanged');
        $this->assertEquals(13.3801, (float) $household->fresh()->latitude);

        // After turning Demo Mode off, the normal endpoint handles requests again (its own validation applies).
        $this->delete(route('spot-mapping.demo-location.disable'));
        $this->postJson(route('spot-mapping.plot'), [])->assertStatus(422);
    }

    /**
     * Phase B: with the shipped INDICATIVE boundary and the shipped UNVERIFIED candidate point (no fixtures).
     */
    public function test_shipped_indicative_boundary_and_unverified_point_on_a_gated_deployment(): void
    {
        config(['lmlinga.demo_location.enabled' => true]);
        BarangayBoundary::flushCache();

        $availability = DemoLocation::availability();
        $this->assertTrue($availability['available'], (string) $availability['reason']);
        $this->assertSame(['la-medalla-es-candidate'], array_column($availability['points'], 'key'));
        $this->assertFalse($availability['points'][0]['verified'], 'the school point stays UNVERIFIED');
        $this->assertSame([13.3812723, 123.4313442], [$availability['points'][0]['lat'], $availability['points'][0]['lng']]);

        $household = Household::factory()->create(['household_no' => 'HH-902', 'zone' => 'Zone 2', 'latitude' => 13.3790, 'longitude' => 123.4320]);
        $before = DB::table('households')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->admin();
        $html = $this->get(route('spot-mapping.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-spot-map-boundary-indicative', $html);
        $this->assertStringContainsString('Indicative only', $html);
        $this->assertStringContainsString('— UNVERIFIED', $html);

        $this->post(route('spot-mapping.demo-location.enable'), ['point' => 'la-medalla-es-candidate'])->assertRedirect(route('spot-mapping.index'));
        $html = $this->get(route('spot-mapping.index'))->assertOk()->getContent();
        $this->assertStringContainsString('DEMO / SIMULATED LOCATION', $html);
        $this->assertStringContainsString('data-spot-map-demo-unverified', $html);
        $this->assertStringContainsString('"verified":false', $html);

        // Nothing can be saved while it is on; records unchanged.
        foreach (['spot-mapping.plot', 'spot-mapping.plot-handoff', 'spot-mapping.plot-new'] as $route) {
            $this->postJson(route($route), ['household_no' => 'HH-902', 'lat' => 13.3812723, 'lng' => 123.4313442, 'confirm_replot' => true])->assertStatus(409);
        }
        $this->assertSame($before, DB::table('households')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertEquals(13.3790, (float) $household->fresh()->latitude);

        // Turned off → Real GPS page, and the real endpoint is reachable again (its own validation applies).
        $this->delete(route('spot-mapping.demo-location.disable'));
        $this->get(route('spot-mapping.index'))->assertOk()->assertDontSee('data-demo-location', false);
        $this->postJson(route('spot-mapping.plot'), [])->assertStatus(422);
    }

    public function test_real_plotting_is_not_restricted_by_the_indicative_boundary(): void
    {
        // SM-03 is NOT enabled: a real save outside the indicative polygon is still accepted by the existing rules.
        Household::factory()->create(['household_no' => 'HH-903', 'zone' => 'Zone 2', 'street' => 'Layuan St.', 'date_registered' => '2026-01-15', 'latitude' => null, 'longitude' => null]);
        $this->admin();

        $this->postJson(route('spot-mapping.plot'), ['household_no' => 'HH-903', 'lat' => 13.47453212, 'lng' => 123.37216972, 'consent' => true])
            ->assertOk();
        $this->assertEquals(13.47453212, (float) Household::query()->where('household_no', 'HH-903')->value('latitude'));
    }

    public function test_gate_turned_off_drops_leftover_state(): void
    {
        $this->configureDemo();
        $this->admin();
        $this->post(route('spot-mapping.demo-location.enable'), ['point' => 'test-inside']);

        config(['lmlinga.demo_location.enabled' => false]);
        $this->get(route('spot-mapping.index'))->assertOk()->assertDontSee('data-demo-location', false);
        $this->assertNull(session(DemoLocation::SESSION_KEY));
        $this->postJson(route('spot-mapping.plot'), [])->assertStatus(422);
    }
}
