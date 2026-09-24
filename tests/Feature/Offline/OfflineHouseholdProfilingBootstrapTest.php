<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Models\Resident;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineHouseholdProfilingBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_household_profiling_bootstrap(): void
    {
        $this->getJson(route('offline.household-profiling-bootstrap'))
            ->assertStatus(401);
    }

    public function test_staff_bootstrap_includes_all_listed_households_and_members(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $first = Household::factory()->create(['household_no' => 'HH-200']);
        $second = Household::factory()->create(['household_no' => 'HH-201']);
        Resident::factory()->head()->create([
            'household_id' => $first->getKey(),
            'member_no' => 'MB-200',
            'first_name' => 'Ana',
            'last_name' => 'Rivera',
        ]);
        Resident::factory()->create([
            'household_id' => $second->getKey(),
            'member_no' => 'MB-201',
            'first_name' => 'Ben',
            'last_name' => 'Cruz',
            'relation' => 'Son',
        ]);

        $response = $this->getJson(route('offline.household-profiling-bootstrap'));
        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $householdNos = collect($response->json('payload.households'))->pluck('household_no')->all();
        $this->assertContains('HH-200', $householdNos);
        $this->assertContains('HH-201', $householdNos);

        $members = collect($response->json('payload.members'));
        $this->assertTrue($members->contains(
            fn (array $row): bool => $row['household_no'] === 'HH-200' && $row['member_no'] === 'MB-200'
        ));
        $this->assertTrue($members->contains(
            fn (array $row): bool => $row['household_no'] === 'HH-201' && $row['member_no'] === 'MB-201'
        ));
        $this->assertNotEmpty($response->json('payload.catalogs.relations'));
        $this->assertNotEmpty($response->json('payload.catalogs.occupations'));
        $this->assertNotEmpty($response->json('payload.catalogs.religions'));
        $this->assertNotEmpty($response->json('payload.catalogs.education'));
        $this->assertNotContains('N/A', $response->json('payload.catalogs.education'));
        $this->assertContains('Not Applicable', $response->json('payload.catalogs.education'));
        $this->assertContains('College Graduate', $response->json('payload.catalogs.education'));
        $this->assertContains('Post-Graduate', $response->json('payload.catalogs.education'));
        $this->assertContains('N/A', $response->json('payload.catalogs.fp_user'));
        $this->assertNotEmpty($response->json('payload.catalogs.disabilities'));
        $this->assertNotEmpty($response->json('payload.catalogs.medical_history'));
        $this->assertArrayHasKey('household_id', $response->json('payload.households.0'));
        $this->assertArrayHasKey('water', $response->json('payload.households.0'));
        $this->assertArrayNotHasKey('password', $response->json('payload.households.0'));
        $member = collect($response->json('payload.members'))->firstWhere('member_no', 'MB-200');
        $this->assertIsArray($member);
        $this->assertArrayHasKey('health', $member);
        $this->assertArrayHasKey('eligible', $member['health']);
        $this->assertArrayHasKey('has_records', $member['health']);
        $this->assertArrayHasKey('nutrition_card', $member['health']);
        $this->assertArrayHasKey('warm_modules', $member['health']);
        $resident = \App\Models\Resident::query()->where('member_no', 'MB-200')->firstOrFail();
        $this->assertSame(\App\Support\Offline\OfflineFieldHasher::resident($resident), $member['field_hash']);
        $other = collect($response->json('payload.members'))->firstWhere('member_no', 'MB-201');
        $this->assertNotSame($member['field_hash'], $other['field_hash']);
    }

    public function test_index_exposes_bootstrap_url_and_readiness_status(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        Household::factory()->create(['household_no' => 'HH-200']);

        $html = $this->get(route('household-profiling.index'))->assertOk()->getContent();
        $this->assertStringContainsString(
            'data-offline-hp-bootstrap-url="'.e(route('offline.household-profiling-bootstrap')).'"',
            $html
        );
        $this->assertStringContainsString('data-hp-offline-ready', $html);
        $this->assertStringContainsString('data-hh-row', $html);
        $this->assertStringContainsString('HH-200', $html);
    }
}
