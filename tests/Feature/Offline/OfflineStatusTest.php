<?php

namespace Tests\Feature\Offline;

use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class OfflineStatusTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    public function test_guest_status_returns_401_json(): void
    {
        $response = $this->getJson(route('offline.status'));

        $response->assertStatus(401);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('code', 'SESSION_EXPIRED');
        $this->assertStringNotContainsString('<html', strtolower($response->getContent()));
    }

    public function test_authenticated_status_returns_actor_role_and_flags(): void
    {
        $user = $this->actingAsFieldStaff(StaffRole::BHW);

        $response = $this->getJson(route('offline.status'));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('user_id', $user->getKey());
        $response->assertJsonPath('username', (string) $user->username);
        $response->assertJsonPath('role', StaffRole::BHW);
        $response->assertJsonPath('must_change_password', false);
        $response->assertJsonPath('is_active', true);
        $this->assertNotEmpty($response->json('csrf_token'));
        $this->assertNotEmpty($response->json('server_time'));
        $this->assertNotEmpty($response->json('login_session_id'));
        $this->assertSame(64, strlen((string) $response->json('login_session_id')));
        $response->assertJsonMissingPath('password');
        $response->assertJsonMissingPath('email');
    }

    public function test_login_session_id_changes_after_session_regenerate(): void
    {
        $this->actingAsFieldStaff(StaffRole::BHW);

        $first = $this->getJson(route('offline.status'))->json('login_session_id');
        $this->assertIsString($first);
        $this->assertNotSame('', $first);

        $this->withSession([])->flushSession();
        // Simulate a fresh authenticated login: new session identity after regenerate.
        $this->actingAsFieldStaff(StaffRole::BHW);
        session()->regenerate();

        $second = $this->getJson(route('offline.status'))->json('login_session_id');
        $this->assertIsString($second);
        $this->assertNotSame('', $second);
        $this->assertNotSame($first, $second);
    }

    public function test_must_change_password_is_visible(): void
    {
        $this->actingAsFieldStaff(StaffRole::BHW, ['must_change_password' => true]);

        $this->getJson(route('offline.status'))
            ->assertOk()
            ->assertJsonPath('must_change_password', true)
            ->assertJsonPath('ok', true);
    }

    public function test_inactive_account_is_visible(): void
    {
        $this->actingAsFieldStaff(StaffRole::BHW, [
            'status' => StaffAccountStatus::INACTIVE,
        ]);

        $this->getJson(route('offline.status'))
            ->assertOk()
            ->assertJsonPath('is_active', false);
    }

    public function test_csrf_token_is_returned(): void
    {
        $this->actingAsFieldStaff();

        $token = $this->getJson(route('offline.status'))->json('csrf_token');

        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $this->assertSame(csrf_token(), $token);
    }
}
