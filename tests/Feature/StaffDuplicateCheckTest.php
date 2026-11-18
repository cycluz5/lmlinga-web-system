<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\User;
use App\Support\ResidentAuthenticator;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesStaff;
use Tests\TestCase;

class StaffDuplicateCheckTest extends TestCase
{
    use AuthenticatesStaff;
    use RefreshDatabase;

    private const BIRTHDAY = '1990-05-17';

    private function household(string $zone = 'Zone 1', array $extra = []): Household
    {
        return Household::factory()->create(array_merge(['zone' => $zone], $extra));
    }

    private function member(Household $hh, string $first = 'Juan', string $last = 'Delacruz', array $extra = []): Resident
    {
        return Resident::factory()->create(array_merge([
            'household_id' => $hh->getKey(),
            'first_name' => $first,
            'last_name' => $last,
            'middle_name' => null,
            'birthday' => self::BIRTHDAY,
        ], $extra));
    }

    private function check(array $query)
    {
        return $this->getJson(route('staff.duplicate-check', $query));
    }

    private function memberQuery(Household $hh, array $extra = []): array
    {
        return array_merge([
            'kind' => 'member',
            'first_name' => 'Juan',
            'last_name' => 'Delacruz',
            'birthday' => self::BIRTHDAY,
            'household_no' => $hh->household_no,
        ], $extra);
    }

    private function headQuery(array $extra = []): array
    {
        return array_merge([
            'kind' => 'head',
            'first_name' => 'Juan',
            'last_name' => 'Delacruz',
            'birthday' => self::BIRTHDAY,
        ], $extra);
    }

    public function test_admin_member_check_returns_exact_contract_items_without_birthday(): void
    {
        $hh = $this->household('Zone 1', ['household_no' => 'HH-7001']);
        $this->member($hh);
        $this->actingAsStaff(StaffRole::ADMIN);

        $response = $this->check($this->memberQuery($hh))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame(['name', 'relation', 'household_no', 'url'], array_keys($items[0]));
        $this->assertSame('HH-7001', $items[0]['household_no']);
        $this->assertStringNotContainsString(self::BIRTHDAY, (string) $response->getContent());
    }

    public function test_bhw_member_check_in_own_zone_works(): void
    {
        $hh = $this->household('Zone 1');
        $this->member($hh);
        $this->actingAsStaff(StaffRole::BHW);

        $this->assertCount(1, $this->check($this->memberQuery($hh))->assertOk()->json('items'));
    }

    public function test_bhw_member_check_for_household_outside_zone_is_empty(): void
    {
        $hh = $this->household('Zone 3');
        $this->member($hh);
        $this->actingAsStaff(StaffRole::BHW);

        $this->check($this->memberQuery($hh))->assertOk()->assertExactJson(['items' => []]);
    }

    public function test_unknown_household_is_empty(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->check([
            'kind' => 'member', 'first_name' => 'Juan', 'last_name' => 'Delacruz',
            'birthday' => self::BIRTHDAY, 'household_no' => 'HH-NOPE-1',
        ])->assertOk()->assertExactJson(['items' => []]);
    }

    public function test_exclude_member_leaves_out_the_member_being_edited(): void
    {
        $hh = $this->household('Zone 1');
        $self = $this->member($hh);
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->assertCount(1, $this->check($this->memberQuery($hh))->json('items'));

        $memberNo = (string) ($self->member_no ?? $self->getKey());
        $this->check($this->memberQuery($hh, ['exclude_member' => $memberNo]))
            ->assertOk()->assertExactJson(['items' => []]);
    }

    public function test_head_check_finds_match_in_any_household_for_admin(): void
    {
        $hh = $this->household('Zone 3', ['household_no' => 'HH-7002']);
        $this->member($hh);
        $this->actingAsStaff(StaffRole::ADMIN);

        $items = $this->check($this->headQuery())->assertOk()->json('items');

        $this->assertCount(1, $items);
        $this->assertSame('HH-7002', $items[0]['household_no']);
        $this->assertSame(['name', 'relation', 'household_no', 'url'], array_keys($items[0]));
    }

    public function test_bhw_head_check_does_not_return_out_of_zone_match(): void
    {
        $this->member($this->household('Zone 3'));
        $this->actingAsStaff(StaffRole::BHW);

        $this->check($this->headQuery())->assertOk()->assertExactJson(['items' => []]);
    }

    public function test_results_are_capped_at_three(): void
    {
        foreach (range(1, 5) as $i) {
            $this->member($this->household('Zone 1'));
        }
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->assertCount(3, $this->check($this->headQuery())->assertOk()->json('items'));
    }

    public function test_bad_kind_is_422(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->check($this->headQuery(['kind' => 'other']))->assertStatus(422);
        $this->check(['first_name' => 'Juan'])->assertStatus(422);
    }

    public function test_missing_short_or_bad_fields_return_empty_items(): void
    {
        $this->member($this->household('Zone 1'));
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->check($this->headQuery(['first_name' => '']))->assertOk()->assertExactJson(['items' => []]);
        $this->check($this->headQuery(['last_name' => 'D']))->assertOk()->assertExactJson(['items' => []]);
        $this->check($this->headQuery(['birthday' => 'not-a-date']))->assertOk()->assertExactJson(['items' => []]);
        $this->check($this->headQuery(['birthday' => '']))->assertOk()->assertExactJson(['items' => []]);
        $this->check(['kind' => 'member', 'first_name' => 'Juan', 'last_name' => 'Delacruz', 'birthday' => self::BIRTHDAY])
            ->assertOk()->assertExactJson(['items' => []]);
    }

    public function test_guest_is_refused(): void
    {
        $this->member($this->household('Zone 1'));

        $response = $this->check($this->headQuery());

        $this->assertContains($response->getStatusCode(), [302, 401]);
        $this->assertStringNotContainsString('Delacruz', (string) $response->getContent());
    }

    public function test_signed_in_resident_chatbot_account_is_refused(): void
    {
        $this->member($this->household('Zone 1'));
        $account = ResidentAccount::factory()->create();
        $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $account->getKey(),
            ResidentAuthenticator::SESSION_EMAIL => (string) $account->email,
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $response = $this->check($this->headQuery());

        $this->assertContains($response->getStatusCode(), [302, 401, 403]);
        $this->assertStringNotContainsString('Delacruz', (string) $response->getContent());
    }

    public function test_signed_in_user_without_a_staff_role_is_refused(): void
    {
        // StaffRole has no linguist role; a signed-in user with no staff appointment is the closest case.
        $this->member($this->household('Zone 1'));
        $this->actingAs(User::factory()->create());

        $response = $this->check($this->headQuery());

        $this->assertContains($response->getStatusCode(), [302, 403]);
        $this->assertStringNotContainsString('Delacruz', (string) $response->getContent());
    }

    public function test_duplicate_check_is_rate_limited(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        for ($i = 0; $i < 60; $i++) {
            $this->check($this->headQuery())->assertOk();
        }

        $this->check($this->headQuery())->assertStatus(429);
    }

    public function test_unresolvable_exclude_member_still_returns_real_matches(): void
    {
        $hh = $this->household('Zone 1');
        $this->member($hh);
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->assertCount(1, $this->check($this->memberQuery($hh, ['exclude_member' => 'MB-NOPE-9']))->json('items'));
    }

    public function test_array_valued_params_return_empty_items_without_error(): void
    {
        $this->member($this->household('Zone 1'));
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->getJson(route('staff.duplicate-check').'?kind=head&first_name[]=x&last_name=Delacruz&birthday='.self::BIRTHDAY)
            ->assertOk()->assertExactJson(['items' => []]);
        $this->getJson(route('staff.duplicate-check').'?kind[]=head')->assertStatus(422);
    }

    public function test_bhw_without_assigned_zones_gets_nothing(): void
    {
        $this->member($this->household('Zone 1'));
        $worker = $this->actingAsStaff(StaffRole::BHW);
        $worker->currentAppointment->forceFill(['assigned_zone' => null])->save();
        if (method_exists($worker->currentAppointment, 'syncAssignedZones')) {
            $worker->currentAppointment->syncAssignedZones([]);
        }

        $this->check($this->headQuery())->assertOk()->assertExactJson(['items' => []]);
    }
}
