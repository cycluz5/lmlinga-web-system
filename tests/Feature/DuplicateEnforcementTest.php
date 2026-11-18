<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesStaff;
use Tests\TestCase;

/**
 * Save-time possible-duplicate enforcement for household members and Spot Mapping heads.
 */
class DuplicateEnforcementTest extends TestCase
{
    use AuthenticatesStaff;
    use RefreshDatabase;

    private const BIRTHDAY = '1990-03-15';

    private const MEMBER_MESSAGE = 'Possible duplicate: a record with the same name and birthday already exists. Confirm it is a different person to continue.';

    private function household(string $no = 'HH-6001', string $zone = 'Zone 1'): Household
    {
        return Household::factory()->create(['household_no' => $no, 'zone' => $zone]);
    }

    private function existing(Household $hh, string $first = 'Ana', string $last = 'Santos', array $extra = []): Resident
    {
        return Resident::factory()->create(array_merge([
            'household_id' => $hh->getKey(),
            'first_name' => $first,
            'last_name' => $last,
            'middle_name' => null,
            'birthday' => self::BIRTHDAY,
        ], $extra));
    }

    /**
     * @return array<string, mixed>
     */
    private function memberPayload(array $overrides = []): array
    {
        return array_merge([
            'last_name' => 'Santos',
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'relation' => 'Spouse',
            'birthday' => self::BIRTHDAY,
            'sex' => 'Female',
            'relationship_status' => 'Married',
            'occupation' => 'Teacher',
            'monthly_income' => '20,000 – 29,999',
            'religion' => 'Roman Catholic',
            'education' => 'College Graduate',
            'fp_user' => 'No',
            'philhealth' => '123456789012',
            'disability' => ['none'],
            'medical_history' => ['none'],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function headPayload(array $overrides = []): array
    {
        return array_merge([
            'household_no' => '121',
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'birthday' => self::BIRTHDAY,
            'sex' => 'Female',
            'civil_status' => 'Live-In',
            'zone' => '1',
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
            'date_registered' => now()->toDateString(),
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ], $overrides);
    }

    private function storeUrl(string $no = 'HH-6001'): string
    {
        return route('household-profiling.members.store', ['householdNo' => $no]);
    }

    public function test_duplicate_member_without_confirmation_is_rejected_and_not_saved(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $hh = $this->household();
        $this->existing($hh);

        $this->post($this->storeUrl(), $this->memberPayload())
            ->assertSessionHasErrors(['possible_duplicate' => self::MEMBER_MESSAGE]);

        $this->assertSame(1, Resident::query()->where('household_id', $hh->getKey())->count());
    }

    public function test_duplicate_member_with_confirmation_is_saved(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $hh = $this->household();
        $this->existing($hh);

        $this->post($this->storeUrl(), $this->memberPayload(['confirm_possible_duplicate' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Resident::query()->where('household_id', $hh->getKey())->count());
    }

    public function test_client_replay_header_on_a_direct_request_is_not_a_bypass(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $hh = $this->household();
        $this->existing($hh);

        $this->withHeader('X-Offline-Replay', '1')
            ->post($this->storeUrl(), $this->memberPayload())
            ->assertSessionHasErrors(['possible_duplicate']);

        $this->assertSame(1, Resident::query()->where('household_id', $hh->getKey())->count());
    }

    public function test_member_summary_is_not_shown_when_only_possible_duplicate_failed(): void
    {
        $this->withoutVite();
        $this->actingAsStaff(StaffRole::ADMIN);
        $hh = $this->household();
        $this->existing($hh);

        $html = $this->followingRedirects()
            ->from(route('household-profiling.members.create', ['householdNo' => $hh->household_no]))
            ->post($this->storeUrl(), $this->memberPayload())
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-lml-possible-duplicate-panel', $html);
        $this->assertMatchesRegularExpression('/data-hh-member-summary[^>]*hidden>/', $html);
        $this->assertStringNotContainsString('Possible duplicate: a record</li>', $html);
        $this->assertDoesNotMatchRegularExpression('/<ul[^>]*data-hh-member-summary-list>\s*<li>/', $html);
    }

    public function test_non_duplicate_member_saves_normally(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $hh = $this->household();
        $this->existing($hh);

        $this->post($this->storeUrl(), $this->memberPayload(['first_name' => 'Maria']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Resident::query()->where('household_id', $hh->getKey())->count());
    }

    public function test_duplicate_check_is_skipped_when_identity_fields_are_invalid(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $hh = $this->household();
        $this->existing($hh);

        $this->post($this->storeUrl(), $this->memberPayload(['first_name' => '']))
            ->assertSessionHasErrors(['first_name'])
            ->assertSessionDoesntHaveErrors(['possible_duplicate']);
    }

    public function test_edit_excludes_self_but_flags_collision_with_sibling(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $hh = $this->household();
        $self = $this->existing($hh, 'Ana', 'Santos');
        $sibling = $this->existing($hh, 'Maria', 'Santos', ['birthday' => '1992-01-01']);

        $update = fn (Resident $r) => route('household-profiling.members.update', [
            'householdNo' => 'HH-6001',
            'memberId' => $r->member_no,
        ]);

        // Saving the member unchanged is not a duplicate of itself.
        $this->put($update($self), $this->memberPayload())
            ->assertSessionHasNoErrors();

        // Changing the sibling to collide with the first member is flagged.
        $this->put($update($sibling), $this->memberPayload())
            ->assertSessionHasErrors(['possible_duplicate' => self::MEMBER_MESSAGE]);

        $this->put($update($sibling), $this->memberPayload(['confirm_possible_duplicate' => '1']))
            ->assertSessionHasNoErrors();
    }

    public function test_member_form_redisplays_panel_with_confirm_checkbox(): void
    {
        $this->withoutVite();
        $this->actingAsStaff(StaffRole::ADMIN);
        $hh = $this->household();
        $this->existing($hh);
        $create = route('household-profiling.members.create', ['householdNo' => 'HH-6001']);

        $html = $this->from($create)
            ->followingRedirects()
            ->post($this->storeUrl(), $this->memberPayload())
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-lml-possible-duplicate-panel', $html);
        $this->assertStringContainsString('name="confirm_possible_duplicate"', $html);
        $this->assertStringContainsString('This is a different person', $html);
        $this->assertStringContainsString('Santos, Ana', $html);
        $this->assertStringContainsString('HH-6001', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
    }

    public function test_panel_absent_on_fresh_member_form(): void
    {
        $this->withoutVite();
        $this->actingAsStaff(StaffRole::ADMIN);
        $this->household();

        $this->get(route('household-profiling.members.create', ['householdNo' => 'HH-6001']))
            ->assertOk()
            ->assertDontSee('data-lml-possible-duplicate-panel', false);
    }

    public function test_spot_mapping_head_match_in_another_household_blocks_unless_confirmed(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $this->existing($this->household('HH-6001', 'Zone 2'));

        $blocked = $this->postJson(route('spot-mapping.plot-new'), $this->headPayload());
        $blocked->assertStatus(422)->assertJsonValidationErrors(['possible_duplicate']);
        $this->assertSame(
            'Possible duplicate: this head is already registered in household HH-6001. Confirm it is a different household to continue.',
            $blocked->json('errors.possible_duplicate.0')
        );
        $this->assertSame(1, Household::query()->count());

        $this->postJson(route('spot-mapping.plot-new'), $this->headPayload(['confirm_possible_duplicate' => '1']))
            ->assertOk();
        $this->assertSame(2, Household::query()->count());
    }

    public function test_spot_mapping_bhw_is_not_flagged_for_head_outside_assigned_zone(): void
    {
        $this->actingAsStaff(StaffRole::BHW); // assigned to Zone 1
        $this->existing($this->household('HH-6001', 'Zone 3'));

        $this->postJson(route('spot-mapping.plot-new'), $this->headPayload())
            ->assertOk()
            ->assertJsonMissingValidationErrors(['possible_duplicate']);

        $this->assertSame(2, Household::query()->count());
    }
}
