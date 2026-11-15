<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Services\StaffNameSuggestionService;
use App\Support\NonResidentFamilyPlanningService;
use App\Support\ResidentAuthenticator;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesStaff;
use Tests\TestCase;

/**
 * Batch 4 — scoped name suggestions: staff only, zone-scoped on the server, minimal fields, small result sets.
 */
class StaffNameSuggestionTest extends TestCase
{
    use AuthenticatesStaff;
    use RefreshDatabase;

    private function resident(string $first, string $last, string $zone, array $household = [], array $extra = []): Resident
    {
        $hh = Household::factory()->create(array_merge(['zone' => $zone], $household));

        return Resident::factory()->create(array_merge([
            'household_id' => $hh->getKey(),
            'first_name' => $first,
            'last_name' => $last,
            'middle_name' => null,
        ], $extra));
    }

    private function suggest(string $q)
    {
        return $this->getJson(route('staff.name-suggestions', ['q' => $q]));
    }

    // ------------------------------------------------------------------ matching

    public function test_admin_gets_matching_residents_from_every_zone_with_minimal_fields(): void
    {
        $this->resident('Juan', 'Delacruz', 'Zone 1');
        $this->resident('Maria', 'Delacruz', 'Zone 3');
        $this->resident('Pedro', 'Santos', 'Zone 2');
        $this->actingAsStaff(StaffRole::ADMIN);

        $response = $this->suggest('delac')->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $items = $response->json('items');
        $this->assertCount(2, $items);
        $this->assertEqualsCanonicalizing(['Zone 1', 'Zone 3'], array_column($items, 'zone'));
        foreach ($items as $item) {
            $this->assertSame(['name', 'zone', 'household_no', 'url'], array_keys($item));
        }
        $this->assertFalse($response->json('more'));
    }

    public function test_several_words_narrow_the_match(): void
    {
        $this->resident('Juan', 'Delacruz', 'Zone 1');
        $this->resident('Maria', 'Delacruz', 'Zone 1');
        $this->actingAsStaff(StaffRole::ADMIN);

        $items = $this->suggest('Juan Del')->assertOk()->json('items');

        $this->assertCount(1, $items);
        $this->assertStringStartsWith('Delacruz, Juan', $items[0]['name']);
    }

    public function test_no_match_returns_an_empty_list(): void
    {
        $this->resident('Juan', 'Delacruz', 'Zone 1');
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->suggest('Zzyzx')->assertOk()->assertExactJson(['items' => [], 'more' => false]);
    }

    public function test_same_name_residents_are_told_apart_by_household_number(): void
    {
        $this->resident('Juan', 'Delacruz', 'Zone 1', ['household_no' => 'HH-9001']);
        $this->resident('Juan', 'Delacruz', 'Zone 1', ['household_no' => 'HH-9002']);
        $this->actingAsStaff(StaffRole::ADMIN);

        $items = $this->suggest('Juan Delacruz')->json('items');

        $this->assertCount(2, $items);
        $this->assertSame(['HH-9001', 'HH-9002'], collect($items)->pluck('household_no')->sort()->values()->all());
    }

    public function test_results_are_capped_and_flag_more(): void
    {
        for ($i = 0; $i < StaffNameSuggestionService::MAX_RESULTS + 3; $i++) {
            $this->resident('Ana'.$i, 'Reyesmatch', 'Zone 2');
        }
        $this->actingAsStaff(StaffRole::ADMIN);

        $response = $this->suggest('Reyesm');

        $this->assertCount(StaffNameSuggestionService::MAX_RESULTS, $response->json('items'));
        $this->assertTrue($response->json('more'));
    }

    public function test_short_or_blank_queries_return_nothing(): void
    {
        $this->resident('Juan', 'Delacruz', 'Zone 1');
        $this->actingAsStaff(StaffRole::ADMIN);

        foreach (['', ' ', 'J', '  D  '] as $q) {
            $this->suggest($q)->assertOk()->assertExactJson(['items' => [], 'more' => false]);
        }
    }

    public function test_like_wildcards_are_treated_as_text(): void
    {
        $this->resident('Juan', 'Delacruz', 'Zone 1');
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->suggest('%%')->assertExactJson(['items' => [], 'more' => false]);
        $this->suggest('__')->assertExactJson(['items' => [], 'more' => false]);
    }

    public function test_link_opens_the_existing_member_record(): void
    {
        $resident = $this->resident('Juan', 'Delacruz', 'Zone 1', ['household_no' => 'HH-0777']);
        $this->actingAsStaff(StaffRole::ADMIN);

        $url = $this->suggest('Delacruz')->json('items.0.url');

        $this->assertSame(
            route('household-profiling.members.show', ['householdNo' => 'HH-0777', 'memberId' => $resident->member_no]),
            $url
        );
        $this->get($url)->assertOk();
    }

    // ------------------------------------------------------------------ privacy and access

    public function test_payload_never_contains_birthdate_sex_or_other_personal_details(): void
    {
        $this->resident('Juan', 'Delacruz', 'Zone 1', [], ['birthday' => '1990-04-05', 'sex' => 'Male', 'occupation' => 'Farmer']);
        $this->actingAsStaff(StaffRole::ADMIN);

        $body = $this->suggest('Delacruz')->getContent();

        foreach (['1990', 'Male', 'Farmer', 'birthday', 'sex', 'occupation'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_health_worker_only_sees_residents_in_their_assigned_zone(): void
    {
        $this->resident('Juan', 'Zonetest', 'Zone 1');
        $this->resident('Maria', 'Zonetest', 'Zone 3');
        $this->actingAsStaff(StaffRole::BHW); // helper assigns Zone 1

        $items = $this->suggest('Zonetest')->assertOk()->json('items');

        $this->assertSame(['Zone 1'], array_column($items, 'zone'));
    }

    public function test_health_worker_without_an_assigned_zone_gets_nothing(): void
    {
        $this->resident('Juan', 'Zonetest', 'Zone 1');
        $worker = $this->actingAsStaff(StaffRole::BHW);
        $worker->currentAppointment->forceFill(['assigned_zone' => null])->save();
        if (method_exists($worker->currentAppointment, 'syncAssignedZones')) {
            $worker->currentAppointment->syncAssignedZones([]);
        }

        $this->suggest('Zonetest')->assertOk()->assertExactJson(['items' => [], 'more' => false]);
    }

    public function test_linguist_is_refused(): void
    {
        $this->resident('Juan', 'Delacruz', 'Zone 1');
        $this->actingAsStaff(StaffRole::LINGUIST);

        $response = $this->suggest('Delacruz');

        $this->assertContains($response->getStatusCode(), [302, 403]);
        $this->assertStringNotContainsString('Delacruz', (string) $response->getContent());
    }

    public function test_guest_is_refused(): void
    {
        $this->resident('Juan', 'Delacruz', 'Zone 1');

        $response = $this->suggest('Delacruz');

        $this->assertContains($response->getStatusCode(), [302, 401]);
        $this->assertStringNotContainsString('Delacruz', (string) $response->getContent());
    }

    public function test_signed_in_resident_chatbot_account_is_refused(): void
    {
        $this->resident('Juan', 'Delacruz', 'Zone 1');
        $account = ResidentAccount::factory()->create();
        // Real resident (chatbot) session shape, as in HouseholdLinkingFixtures::actingAsResident().
        $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $account->getKey(),
            ResidentAuthenticator::SESSION_EMAIL => (string) $account->email,
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        $response = $this->suggest('Delacruz');

        $this->assertContains($response->getStatusCode(), [302, 401, 403]);
        $this->assertStringNotContainsString('Delacruz', (string) $response->getContent());
    }

    public function test_non_resident_family_planning_clients_are_excluded(): void
    {
        $this->resident('Clara', 'Outsider', 'Zone 1', ['household_no' => NonResidentFamilyPlanningService::SENTINEL_HOUSEHOLD_NO]);
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->suggest('Outsider')->assertExactJson(['items' => [], 'more' => false]);
    }

    public function test_suggestions_are_rate_limited(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        for ($i = 0; $i < 60; $i++) {
            $this->suggest('Delacruz')->assertOk();
        }

        $this->suggest('Delacruz')->assertStatus(429);
    }
}
