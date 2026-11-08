<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\User;
use App\Support\ResidentAccountUiCatalog;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminResidentAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdminSession(): static
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        return $this;
    }

    public function test_index_lists_database_resident_accounts_without_demo_bleed(): void
    {
        $account = ResidentAccount::factory()->create([
            'first_name' => 'Portal',
            'middle_name' => 'A',
            'last_name' => 'User',
            'email' => 'portal.user@example.test',
            'zone' => 'Zone 4',
        ]);

        $html = $this->actingAsAdminSession()
            ->get(route('user-management.index', ['tab' => 'residents']))
            ->assertOk()
            ->getContent();

        $publicId = ResidentAccountUiCatalog::publicId($account->id);
        $this->assertStringContainsString($publicId, $html);
        $this->assertStringContainsString('Portal A User', $html);
        $this->assertStringContainsString('portal.user@example.test', $html);
        $this->assertStringContainsString('Zone 4', $html);
        $this->assertStringNotContainsString('ra-001', $html);
        $this->assertStringNotContainsString('kristine.reyes@email.com', $html);
    }

    public function test_zone_prefers_linked_resident_household_zone(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 5']);
        $resident = Resident::factory()->create(['household_id' => $household->id]);
        $account = ResidentAccount::factory()->linkedTo($resident)->create([
            'zone' => 'Zone 1',
            'email' => 'linked.zone@example.test',
            'first_name' => 'Linked',
            'middle_name' => 'Z',
            'last_name' => 'Zone',
        ]);

        $html = $this->actingAsAdminSession()
            ->get(route('user-management.index', ['tab' => 'residents']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(ResidentAccountUiCatalog::publicId($account->id), $html);
        $this->assertStringContainsString('Zone 5', $html);
    }

    public function test_view_and_edit_are_database_backed_and_never_expose_password(): void
    {
        $account = ResidentAccount::factory()->create([
            'first_name' => 'View',
            'middle_name' => 'Me',
            'last_name' => 'Please',
            'email' => 'view.me@example.test',
            'zone' => 'Zone 2',
            'password' => 'SecretPass!123',
        ]);
        $publicId = ResidentAccountUiCatalog::publicId($account->id);
        $hash = (string) DB::table('resident_accounts')->where('id', $account->id)->value('password');

        $view = $this->actingAsAdminSession()
            ->get(route('user-management.residents.view', ['id' => $publicId]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('View Me Please', $view);
        $this->assertStringContainsString('view.me@example.test', $view);
        $this->assertStringNotContainsString($hash, $view);
        $this->assertStringNotContainsString('SecretPass!123', $view);
        $this->assertStringNotContainsString('name="password"', $view);

        $edit = $this->actingAsAdminSession()
            ->get(route('user-management.residents.edit', ['id' => $publicId]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="first_name"', $edit);
        $this->assertStringNotContainsString($hash, $edit);
        $this->assertStringNotContainsString('name="password"', $edit);
        $this->assertStringNotContainsString('name="resident_id"', $edit);
    }

    public function test_admin_can_update_safe_fields_without_relinking_or_password(): void
    {
        $household = Household::factory()->create();
        $resident = Resident::factory()->create(['household_id' => $household->id]);
        $account = ResidentAccount::factory()->linkedTo($resident)->create([
            'email' => 'edit.me@example.test',
            'zone' => 'Zone 1',
            'password' => 'KeepHash!123',
        ]);
        $publicId = ResidentAccountUiCatalog::publicId($account->id);
        $originalHash = (string) DB::table('resident_accounts')->where('id', $account->id)->value('password');
        $otherResident = Resident::factory()->create(['household_id' => $household->id]);

        $this->actingAsAdminSession()
            ->put(route('user-management.residents.update', ['id' => $publicId]), [
                'first_name' => 'Edited',
                'middle_name' => 'Name',
                'last_name' => 'Account',
                'zone' => 'Zone 3',
                'email' => 'edited.account@example.test',
                'resident_id' => $otherResident->id,
                'password' => 'ShouldIgnore!999',
            ])
            ->assertRedirect(route('user-management.residents.view', ['id' => $publicId]));

        $account->refresh();
        $this->assertSame('Edited', $account->first_name);
        $this->assertSame('Name', $account->middle_name);
        $this->assertSame('Account', $account->last_name);
        $this->assertSame('Zone 3', $account->zone);
        $this->assertSame('edited.account@example.test', $account->email);
        $this->assertSame($resident->id, $account->resident_id);
        $this->assertSame($originalHash, (string) DB::table('resident_accounts')->where('id', $account->id)->value('password'));
        $this->assertTrue(Hash::check('KeepHash!123', $originalHash));
        $this->assertFalse(Hash::check('ShouldIgnore!999', $originalHash));
    }

    public function test_duplicate_email_is_rejected(): void
    {
        ResidentAccount::factory()->create(['email' => 'taken@example.test']);
        $account = ResidentAccount::factory()->create(['email' => 'mine@example.test']);
        $publicId = ResidentAccountUiCatalog::publicId($account->id);

        $this->actingAsAdminSession()
            ->from(route('user-management.residents.edit', ['id' => $publicId]))
            ->put(route('user-management.residents.update', ['id' => $publicId]), [
                'first_name' => 'Mine',
                'middle_name' => 'A',
                'last_name' => 'Account',
                'zone' => 'Zone 1',
                'email' => 'taken@example.test',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_delete_removes_portal_account_only_and_blocks_login(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 2']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Official',
            'last_name' => 'Resident',
        ]);
        $account = ResidentAccount::factory()->linkedTo($resident)->create([
            'email' => 'delete.me@example.test',
            'password' => 'DeletePass!123',
        ]);
        $publicId = ResidentAccountUiCatalog::publicId($account->id);
        $accountId = $account->id;

        $this->actingAsAdminSession()
            ->delete(route('user-management.residents.destroy', ['id' => $publicId]))
            ->assertRedirect(route('user-management.index', ['tab' => 'residents']));

        $this->assertNull(ResidentAccount::query()->find($accountId));
        $this->assertDatabaseMissing('resident_accounts', ['id' => $accountId]);
        $this->assertDatabaseHas('residents', ['id' => $resident->id]);
        $this->assertDatabaseHas('households', ['id' => $household->id]);
        $this->assertNotNull(Resident::query()->find($resident->id));
        $this->assertNotNull(Household::query()->find($household->id));
    }

    public function test_authorization_boundaries(): void
    {
        $account = ResidentAccount::factory()->create(['email' => 'authz@example.test']);
        $publicId = ResidentAccountUiCatalog::publicId($account->id);
        $payload = [
            'first_name' => 'X',
            'middle_name' => 'Y',
            'last_name' => 'Z',
            'zone' => 'Zone 1',
            'email' => 'authz@example.test',
        ];

        $this->put(route('user-management.residents.update', ['id' => $publicId]), $payload)
            ->assertRedirect(route('login'));

        $this->actingAsStaff(StaffRole::BHW);
        $this->put(route('user-management.residents.update', ['id' => $publicId]), $payload)
            ->assertForbidden();

        $this->actingAsStaff(StaffRole::BHW);
        $this->delete(route('user-management.residents.destroy', ['id' => $publicId]))
            ->assertForbidden();

        Auth::guard('web')->logout();
        $this->get(route('user-management.index'))
            ->assertRedirect(route('login'));
    }

    public function test_staff_and_resident_domains_remain_separate_with_same_email(): void
    {
        $email = 'shared.identity@example.test';

        $staff = User::factory()->create([
            'email' => $email,
            'password' => 'StaffPass!123',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => false,
        ]);
        $staff->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);

        $portal = ResidentAccount::factory()->create([
            'email' => $email,
            'password' => 'ResidentPass!123',
        ]);

        $this->assertInstanceOf(User::class, $staff);
        $this->assertInstanceOf(ResidentAccount::class, $portal);
        $this->assertDatabaseHas('users', ['email' => $email, 'id' => $staff->id]);
        $this->assertDatabaseHas('resident_accounts', ['email' => $email, 'id' => $portal->id]);
        $this->assertDatabaseMissing('users', ['id' => $portal->id, 'email' => $email, 'password' => $portal->password]);
        $this->assertNotEquals(
            (string) DB::table('users')->where('id', $staff->id)->value('password'),
            (string) DB::table('resident_accounts')->where('id', $portal->id)->value('password')
        );

        $this->post(route('login.store'), [
            'email' => $email,
            'password' => 'StaffPass!123',
        ])->assertRedirect(route('dashboard'));
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($staff->id, Auth::id());
        Auth::guard('web')->logout();
        $this->assertGuest();
    }
}
