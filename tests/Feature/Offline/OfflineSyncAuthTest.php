<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Support\Offline\OfflineOperationType;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class OfflineSyncAuthTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    public function test_guest_sync_returns_401_json(): void
    {
        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(),
        ));

        $response->assertStatus(401);
        $response->assertJsonPath('code', 'SESSION_EXPIRED');
        $this->assertSame(0, Household::query()->count());
        $this->assertNoAppliedReceipt();
    }

    public function test_missing_csrf_returns_419(): void
    {
        $this->actingAsFieldStaff();
        $previous = $this->app['env'];
        $this->app['env'] = 'local';

        try {
            $response = $this->postOfflineSync($this->offlineEnvelope(
                OfflineOperationType::HOUSEHOLD_CREATE,
                $this->householdCreatePayload(),
            ));

            $response->assertStatus(419);
            $response->assertJsonPath('code', 'CSRF_MISMATCH');
            $this->assertSame(0, Household::query()->count());
            $this->assertNoAppliedReceipt();
        } finally {
            $this->app['env'] = $previous;
        }
    }

    public function test_valid_csrf_token_allows_the_operation(): void
    {
        $this->actingAsFieldStaff();
        $previous = $this->app['env'];
        $this->app['env'] = 'local';
        $token = 'offline-csrf-test-token';

        try {
            $response = $this->withSession(['_token' => $token])
                ->postJson(route('offline.sync'), $this->offlineEnvelope(
                    OfflineOperationType::HOUSEHOLD_CREATE,
                    $this->householdCreatePayload(['household_no' => '130']),
                ), [
                    'X-CSRF-TOKEN' => $token,
                ]);

            $response->assertOk();
            $response->assertJsonPath('code', 'SYNCED');
            $this->assertSame(1, Household::query()->count());
        } finally {
            $this->app['env'] = $previous;
        }
    }

    public function test_legacy_must_change_password_flag_does_not_block_offline_sync(): void
    {
        $this->actingAsFieldStaff(StaffRole::BHW, [
            'must_change_password' => true,
        ]);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload([
                'household_no' => '205',
            ]),
        ));

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');

        $this->assertSame(1, Household::query()->count());
    }

    public function test_inactive_account_returns_403_and_does_not_write(): void
    {
        $this->actingAsFieldStaff(StaffRole::BHW, [
            'status' => StaffAccountStatus::INACTIVE,
        ]);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(),
        ));

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'ACCOUNT_INACTIVE');
        $this->assertSame(0, Household::query()->count());
        $this->assertNoAppliedReceipt();
    }

    #[DataProvider('staffRolesProvider')]
    public function test_allowed_staff_roles_can_sync(string $role): void
    {
        $numbers = [
            StaffRole::ADMIN => '201',
            StaffRole::BHW => '202',
            StaffRole::BNS => '203',
            StaffRole::BSPO => '204',
        ];

        $this->actingAsFieldStaff($role);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HOUSEHOLD_CREATE,
            $this->householdCreatePayload(['household_no' => $numbers[$role]]),
        ));

        $response->assertOk();
        $response->assertJsonPath('code', 'SYNCED');
    }

    /**
     * @return list<list<string>>
     */
    public static function staffRolesProvider(): array
    {
        return [
            [StaffRole::ADMIN],
            [StaffRole::BHW],
            [StaffRole::BNS],
            [StaffRole::BSPO],
        ];
    }

    public function test_legacy_must_change_password_flag_does_not_force_browser_redirect(): void
    {
        $this->actingAsFieldStaff(StaffRole::BHW, [
            'must_change_password' => true,
        ]);

        $this->get(route('dashboard'))
            ->assertOk();
    }

    public function test_html_csrf_mismatch_is_not_converted_to_offline_json(): void
    {
        $request = \Illuminate\Http\Request::create(route('login.store'), 'POST');
        $request->headers->set('Accept', 'text/html');

        $response = app(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->render($request, new \Illuminate\Session\TokenMismatchException('CSRF token mismatch.'));

        $this->assertSame(419, $response->getStatusCode());
        $contentType = strtolower((string) $response->headers->get('content-type'));
        $this->assertStringNotContainsString('application/json', $contentType);
        $decoded = json_decode($response->getContent(), true);
        $this->assertTrue(! is_array($decoded) || ($decoded['code'] ?? null) !== 'CSRF_MISMATCH');
    }

    public function test_offline_token_mismatch_still_renders_json_csrf_code(): void
    {
        $request = \Illuminate\Http\Request::create(route('offline.sync'), 'POST');
        $request->headers->set('Accept', 'application/json');

        $response = app(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->render($request, new \Illuminate\Session\TokenMismatchException('CSRF token mismatch.'));

        $this->assertSame(419, $response->getStatusCode());
        $this->assertSame('CSRF_MISMATCH', $response->getData(true)['code'] ?? json_decode($response->getContent(), true)['code'] ?? null);
    }
}
