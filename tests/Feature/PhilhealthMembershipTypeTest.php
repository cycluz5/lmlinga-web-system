<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\PhilhealthMembershipType;
use App\Models\Resident;
use App\Services\ResidentService;
use App\Support\HouseholdProfilingPresenter;
use App\Support\Offline\OfflineFieldHasher;
use App\Support\Offline\OfflineOperationType;
use App\Support\PhilhealthMembershipTypes;
use App\Support\StaffRole;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientTestingErdSchema;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

/**
 * Panel refinement S-02: optional PhilHealth membership type, online and offline.
 * All data here is synthetic.
 */
class PhilhealthMembershipTypeTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'last_name' => 'Santos',
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'relation' => 'Head',
            'birthday' => '1990-03-15',
            'sex' => 'Female',
            'relationship_status' => 'Married',
            'occupation' => 'Teacher',
            'monthly_income' => '20,000 – 29,999',
            'religion' => 'Roman Catholic',
            'education' => 'College Graduate',
            'fp_user' => 'No',
            'philhealth' => '123456789012',
            'disability' => ['none'],
            'disability_others' => null,
            'medical_history' => ['none'],
            'medical_others' => null,
        ], $overrides);
    }

    private function storedType(Resident $resident): ?int
    {
        $value = DB::table($resident->getTable())
            ->where($resident->getKeyName(), $resident->getKey())
            ->value(PhilhealthMembershipTypes::COLUMN);

        return $value === null ? null : (int) $value;
    }

    private function forgetAvailability(): void
    {
        app()->forgetInstance(PhilhealthMembershipTypes::class.'.available');
    }

    private function member(string $householdNo = 'HH-801', string $memberNo = 'MB-801'): Resident
    {
        $household = Household::factory()->create(['household_no' => $householdNo]);

        return Resident::factory()->head()->create([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'philhealth' => '111122223333',
        ]);
    }

    public function test_there_are_exactly_the_22_approved_options_in_the_official_groups(): void
    {
        $grouped = PhilhealthMembershipTypes::grouped();

        $this->assertSame(['Direct Contributor', 'Indirect Contributor'], array_keys($grouped));
        $this->assertSame([
            'Employed Private', 'Employed Government', 'Professional Practitioner', 'Self-Earning Individual',
            'Kasambahay', 'Family Driver', 'Migrant Worker', 'Lifetime Member',
            'Filipinos with Dual Citizenship / Living Abroad', 'Foreign National',
        ], array_values($grouped['Direct Contributor']));
        $this->assertSame([
            'Listahanan', '4Ps/MCCT', 'Senior Citizen', 'PAMANA', 'KIA/KIPO', 'Bangsamoro/Normalization',
            'LGU-sponsored', 'NGA-sponsored', 'Private-sponsored', 'Person with Disability',
            'Solo Parent', 'Employed Person with Disability',
        ], array_values($grouped['Indirect Contributor']));
    }

    public function test_solo_parent_and_employed_pwd_are_indirect_contributors(): void
    {
        $this->assertSame('indirect', PhilhealthMembershipTypes::OPTIONS['solo_parent']['group']);
        $this->assertSame('indirect', PhilhealthMembershipTypes::OPTIONS['employed_person_with_disability']['group']);
        $this->assertSame('indirect', PhilhealthMembershipType::query()->where('type_code', 'solo_parent')->value('contributor_group'));
        $this->assertSame('indirect', PhilhealthMembershipType::query()->where('type_code', 'employed_person_with_disability')->value('contributor_group'));
    }

    public function test_status_values_are_not_options(): void
    {
        foreach (['not_a_member', 'unknown', 'active', 'inactive', 'qualified_dependent', 'not_recorded'] as $code) {
            $this->assertNotContains($code, PhilhealthMembershipTypes::codes());
        }
    }

    public function test_migration_rows_and_javascript_list_match_the_php_list(): void
    {
        $rows = PhilhealthMembershipType::query()->orderBy('sort_order')->get();
        $this->assertCount(22, $rows);
        foreach ($rows as $index => $row) {
            $code = PhilhealthMembershipTypes::codes()[$index];
            $this->assertSame($code, $row->type_code);
            $this->assertSame(PhilhealthMembershipTypes::OPTIONS[$code]['label'], $row->type_name);
            $this->assertSame(PhilhealthMembershipTypes::OPTIONS[$code]['group'], $row->contributor_group);
        }

        $js = (string) file_get_contents(resource_path('js/offline/offline-philhealth-membership-types.js'));
        preg_match_all("/\\{ code: '([^']+)', label: '([^']+)', group: '([^']+)' \\}/", $js, $m, PREG_SET_ORDER);
        $this->assertCount(22, $m);
        foreach ($m as $index => [, $code, $label, $group]) {
            $this->assertSame(PhilhealthMembershipTypes::codes()[$index], $code);
            $this->assertSame(PhilhealthMembershipTypes::OPTIONS[$code]['label'], $label);
            $this->assertSame(
                PhilhealthMembershipTypes::OPTIONS[$code]['group'] === 'direct' ? 'Direct Contributor' : 'Indirect Contributor',
                $group
            );
        }
    }

    public function test_migration_leaves_existing_residents_unchanged_and_null(): void
    {
        $migration = require database_path('migrations/2026_11_05_090000_create_philhealth_membership_type_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('philhealth_membership_type'));
        $this->assertFalse(Schema::hasColumn('residents', 'philhealth_membership_type_id'));

        $household = Household::factory()->create(['household_no' => 'HH-820']);
        Resident::factory()->head()->create(['household_id' => $household->id, 'member_no' => 'MB-820', 'philhealth' => '444455556666', 'birthday' => '1950-01-01']);
        Resident::factory()->create(['household_id' => $household->id, 'member_no' => 'MB-821', 'philhealth' => null]);
        $before = DB::table('residents')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $migration->up();
        $migration->up(); // idempotent

        $after = DB::table('residents')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->assertCount(2, $after);
        foreach ($after as $index => $row) {
            $this->assertArrayHasKey('philhealth_membership_type_id', $row);
            $this->assertNull($row['philhealth_membership_type_id']);
            unset($row['philhealth_membership_type_id']);
            $this->assertSame($before[$index], $row);
        }
        $this->assertSame(22, DB::table('philhealth_membership_type')->count());
    }

    public function test_create_form_offers_the_grouped_optional_dropdown_beside_the_unchanged_number(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        Household::factory()->create(['household_no' => 'HH-802']);

        $html = $this->get(route('household-profiling.members.create', ['householdNo' => 'HH-802']))->assertOk()->getContent();

        $this->assertStringContainsString('PhilHealth Membership Type', $html);
        $this->assertStringContainsString('<optgroup label="Direct Contributor">', $html);
        $this->assertStringContainsString('<optgroup label="Indirect Contributor">', $html);
        $this->assertStringContainsString('value="solo_parent"', $html);
        $this->assertStringContainsString('value="employed_person_with_disability"', $html);
        $this->assertMatchesRegularExpression('/<select id="lml-hh-philhealth-membership-type"(?![^>]*required)[^>]*>/', $html);
        $this->assertStringContainsString('id="lml-hh-philhealth"', $html);
    }

    public function test_create_with_type_saves_and_view_shows_the_label(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $household = Household::factory()->create(['household_no' => 'HH-803']);

        $this->post(route('household-profiling.members.store', ['householdNo' => 'HH-803']), $this->payload([
            'philhealth_membership_type' => 'solo_parent',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();
        $this->assertSame(PhilhealthMembershipTypes::idForCode('solo_parent'), $this->storedType($resident));
        $this->assertSame('123456789012', $resident->philhealth);

        $presented = HouseholdProfilingPresenter::memberFromModel($resident);
        $this->assertSame('solo_parent', $presented['philhealth_membership_type']);
        $this->assertSame('Solo Parent', $presented['philhealth_membership_type_label']);

        $view = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-803',
            'memberId' => $presented['id'] ?? $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/PhilHealth Membership Type<\/dt>\s*<dd[^>]*>Solo Parent<\/dd>/', $view);
    }

    public function test_create_without_the_field_or_blank_stays_null_and_is_never_inferred(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $household = Household::factory()->create(['household_no' => 'HH-804']);

        // Senior citizen by age with a disability: neither may assign a type automatically.
        $this->post(route('household-profiling.members.store', ['householdNo' => 'HH-804']), $this->payload([
            'birthday' => '1950-01-01',
            'disability' => ['Physical Disability (PD)'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->post(route('household-profiling.members.store', ['householdNo' => 'HH-804']), $this->payload([
            'first_name' => 'Ben',
            'relation' => 'Son',
            'philhealth' => null,
            'philhealth_membership_type' => '',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $residents = Resident::query()->where('household_id', $household->id)->get();
        $this->assertCount(2, $residents);
        foreach ($residents as $resident) {
            $this->assertNull($this->storedType($resident));
        }
    }

    public function test_null_type_displays_not_recorded(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $this->member('HH-805', 'MB-805');

        $view = $this->get(route('household-profiling.members.show', ['householdNo' => 'HH-805', 'memberId' => 'MB-805']))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/PhilHealth Membership Type<\/dt>\s*<dd[^>]*>Not recorded<\/dd>/', $view);
    }

    public function test_invalid_code_is_refused_and_nothing_changes(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $resident = $this->member();

        foreach (['not_a_member', 'Solo Parent', 'qualified_dependent', '99'] as $bad) {
            $this->put(route('household-profiling.members.update', ['householdNo' => 'HH-801', 'memberId' => 'MB-801']), $this->payload([
                'first_name' => 'Changed',
                'philhealth_membership_type' => $bad,
            ]))->assertSessionHasErrors('philhealth_membership_type');
        }

        $this->assertNull($this->storedType($resident));
        $this->assertNotSame('Changed', (string) $resident->fresh()->first_name);
    }

    public function test_edit_shows_the_saved_type_then_change_and_clear_work(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $resident = $this->member();
        $update = route('household-profiling.members.update', ['householdNo' => 'HH-801', 'memberId' => 'MB-801']);

        $this->put($update, $this->payload(['philhealth_membership_type' => 'kasambahay']))->assertSessionHasNoErrors();
        $this->assertSame(PhilhealthMembershipTypes::idForCode('kasambahay'), $this->storedType($resident));

        $edit = $this->get(route('household-profiling.members.edit', ['householdNo' => 'HH-801', 'memberId' => 'MB-801']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="kasambahay"\s+selected/', $edit);

        $this->put($update, $this->payload(['philhealth_membership_type' => 'employed_person_with_disability']))->assertSessionHasNoErrors();
        $this->assertSame(PhilhealthMembershipTypes::idForCode('employed_person_with_disability'), $this->storedType($resident));

        $this->put($update, $this->payload(['philhealth_membership_type' => '']))->assertSessionHasNoErrors();
        $this->assertNull($this->storedType($resident));
    }

    public function test_request_without_the_field_leaves_the_saved_type_unchanged(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $resident = $this->member();
        $update = route('household-profiling.members.update', ['householdNo' => 'HH-801', 'memberId' => 'MB-801']);
        $this->put($update, $this->payload(['philhealth_membership_type' => 'senior_citizen']))->assertSessionHasNoErrors();

        $this->put($update, $this->payload(['first_name' => 'Anita']))->assertSessionHasNoErrors();

        $this->assertSame('Anita', (string) $resident->fresh()->first_name);
        $this->assertSame(PhilhealthMembershipTypes::idForCode('senior_citizen'), $this->storedType($resident));
    }

    public function test_philhealth_number_rule_is_unchanged_and_independent_of_the_type(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $resident = $this->member();
        $update = route('household-profiling.members.update', ['householdNo' => 'HH-801', 'memberId' => 'MB-801']);

        foreach (['12345', 'abcdefghijkl', '1234567890123'] as $bad) {
            $this->put($update, $this->payload(['philhealth' => $bad, 'philhealth_membership_type' => 'listahanan']))
                ->assertSessionHasErrors('philhealth');
        }
        $this->assertSame('111122223333', $resident->fresh()->philhealth);
        $this->assertNull($this->storedType($resident));

        // A type never requires a number, and a number never requires a type.
        $this->put($update, $this->payload(['philhealth' => '', 'philhealth_membership_type' => 'listahanan']))->assertSessionHasNoErrors();
        $this->assertNull($resident->fresh()->philhealth);
        $this->assertSame(PhilhealthMembershipTypes::idForCode('listahanan'), $this->storedType($resident));
    }

    public function test_field_is_hidden_and_saving_still_works_before_the_migration_runs(): void
    {
        $migration = require database_path('migrations/2026_11_05_090000_create_philhealth_membership_type_table.php');
        $migration->down();
        $this->forgetAvailability();

        $this->actingAsStaff(StaffRole::BHW);
        $household = Household::factory()->create(['household_no' => 'HH-806']);

        $form = $this->get(route('household-profiling.members.create', ['householdNo' => 'HH-806']))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="philhealth_membership_type"', $form);

        $this->post(route('household-profiling.members.store', ['householdNo' => 'HH-806']), $this->payload([
            'philhealth_membership_type' => 'solo_parent',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();
        $this->assertSame('', HouseholdProfilingPresenter::memberFromModel($resident)['philhealth_membership_type']);
        $this->assertArrayNotHasKey('philhealth_membership_type', OfflineFieldHasher::residentSnapshot($resident));
    }

    public function test_linguist_cannot_set_the_type(): void
    {
        $this->actingAsStaff(StaffRole::LINGUIST);
        $resident = $this->member();

        $response = $this->put(route('household-profiling.members.update', ['householdNo' => 'HH-801', 'memberId' => 'MB-801']), $this->payload([
            'philhealth_membership_type' => 'solo_parent',
        ]));

        $this->assertContains($response->getStatusCode(), [302, 403]);
        $this->assertNull($this->storedType($resident));
    }

    public function test_blank_type_stays_out_of_the_hash_so_existing_records_keep_their_hash(): void
    {
        $resident = $this->member();

        $snapshot = OfflineFieldHasher::residentSnapshot($resident);
        $this->assertArrayNotHasKey('philhealth_membership_type', $snapshot);

        $blankHash = OfflineFieldHasher::resident($resident);
        DB::table('residents')->where('id', $resident->getKey())->update([PhilhealthMembershipTypes::COLUMN => PhilhealthMembershipTypes::idForCode('pamana')]);
        $setHash = OfflineFieldHasher::resident($resident->fresh());
        $this->assertNotSame($blankHash, $setHash);
        $this->assertSame('pamana', OfflineFieldHasher::residentSnapshot($resident->fresh())['philhealth_membership_type']);

        DB::table('residents')->where('id', $resident->getKey())->update([PhilhealthMembershipTypes::COLUMN => null]);
        $this->assertSame($blankHash, OfflineFieldHasher::resident($resident->fresh()));
    }

    public function test_offline_bootstrap_carries_the_type(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $resident = $this->member('HH-807', 'MB-807');
        DB::table('residents')->where('id', $resident->getKey())->update([PhilhealthMembershipTypes::COLUMN => PhilhealthMembershipTypes::idForCode('four_ps_mcct')]);

        $members = collect($this->getJson(route('offline.household-profiling-bootstrap'))->assertOk()->json('payload.members'));

        $this->assertSame('four_ps_mcct', $members->firstWhere('member_no', 'MB-807')['philhealth_membership_type']);
    }

    public function test_offline_create_syncs_the_type(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create(['household_no' => '121']);
        Resident::factory()->head()->create(['household_id' => $household->id, 'member_no' => 'MB-001']);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_CREATE,
            $this->residentMemberPayload(['philhealth_membership_type' => 'employed_person_with_disability']),
            ['parent_server' => ['household_id' => $household->getKey()]],
        ));

        $response->assertOk()->assertJsonPath('code', 'SYNCED');
        $resident = Resident::query()->whereKey($response->json('resident.id'))->firstOrFail();
        $this->assertSame(PhilhealthMembershipTypes::idForCode('employed_person_with_disability'), $this->storedType($resident));
    }

    public function test_offline_create_without_the_field_syncs_with_null(): void
    {
        $this->actingAsFieldStaff();
        $household = Household::factory()->create(['household_no' => '122']);
        Resident::factory()->head()->create(['household_id' => $household->id, 'member_no' => 'MB-001']);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_CREATE,
            $this->residentMemberPayload(),
            ['parent_server' => ['household_id' => $household->getKey()]],
        ));

        $response->assertOk()->assertJsonPath('code', 'SYNCED');
        $this->assertNull($this->storedType(Resident::query()->whereKey($response->json('resident.id'))->firstOrFail()));
    }

    public function test_offline_update_sets_and_clears_the_type(): void
    {
        $this->actingAsFieldStaff();
        $resident = $this->member();

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_UPDATE,
            $this->residentMemberPayload(['philhealth_membership_type' => 'solo_parent']),
            ['parent_server' => ['resident_id' => $resident->getKey()], 'base_snapshot' => ['field_hash' => OfflineFieldHasher::resident($resident)]],
        ))->assertOk()->assertJsonPath('code', 'SYNCED');
        $this->assertSame(PhilhealthMembershipTypes::idForCode('solo_parent'), $this->storedType($resident));

        $fresh = $resident->fresh();
        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_UPDATE,
            $this->residentMemberPayload(['philhealth_membership_type' => '']),
            ['parent_server' => ['resident_id' => $resident->getKey()], 'base_snapshot' => ['field_hash' => OfflineFieldHasher::resident($fresh)]],
        ))->assertOk()->assertJsonPath('code', 'SYNCED');
        $this->assertNull($this->storedType($resident));
    }

    public function test_edit_queued_before_this_field_existed_syncs_without_a_false_conflict(): void
    {
        $this->actingAsFieldStaff();
        $resident = $this->member();

        // Hash the old way: the snapshot had no membership type key, and the payload has no such field.
        $legacySnapshot = OfflineFieldHasher::residentSnapshot($resident);
        unset($legacySnapshot['philhealth_membership_type']);
        $legacyHash = \App\Support\Offline\OfflinePayloadCanonicalizer::hash($legacySnapshot);

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_UPDATE,
            $this->residentMemberPayload(['first_name' => 'Anita']),
            ['parent_server' => ['resident_id' => $resident->getKey()], 'base_snapshot' => ['field_hash' => $legacyHash]],
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $this->assertSame('Anita', (string) $resident->fresh()->first_name);
        $this->assertNull($this->storedType($resident));
    }

    public function test_older_offline_payload_without_the_field_keeps_a_saved_type(): void
    {
        $this->actingAsFieldStaff();
        $resident = $this->member();
        DB::table('residents')->where('id', $resident->getKey())->update([PhilhealthMembershipTypes::COLUMN => PhilhealthMembershipTypes::idForCode('lifetime_member')]);
        $fresh = $resident->fresh();

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_UPDATE,
            $this->residentMemberPayload(['first_name' => 'Anita']),
            ['parent_server' => ['resident_id' => $resident->getKey()], 'base_snapshot' => ['field_hash' => OfflineFieldHasher::resident($fresh)]],
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $this->assertSame(PhilhealthMembershipTypes::idForCode('lifetime_member'), $this->storedType($resident));
    }

    public function test_type_changed_on_the_server_still_raises_a_real_conflict(): void
    {
        $this->actingAsFieldStaff();
        $resident = $this->member();
        $staleHash = OfflineFieldHasher::resident($resident);
        DB::table('residents')->where('id', $resident->getKey())->update([PhilhealthMembershipTypes::COLUMN => PhilhealthMembershipTypes::idForCode('migrant_worker')]);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_UPDATE,
            $this->residentMemberPayload(['philhealth_membership_type' => 'kasambahay']),
            ['parent_server' => ['resident_id' => $resident->getKey()], 'base_snapshot' => ['field_hash' => $staleHash]],
        ));

        $response->assertStatus(409)->assertJsonPath('code', 'TARGET_CHANGED');
        $this->assertSame(PhilhealthMembershipTypes::idForCode('migrant_worker'), $this->storedType($resident));
        $this->assertNoAppliedReceipt();
    }

    public function test_offline_sync_refuses_an_invalid_code(): void
    {
        $this->actingAsFieldStaff();
        $resident = $this->member();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_UPDATE,
            $this->residentMemberPayload(['philhealth_membership_type' => 'not_a_member']),
            ['parent_server' => ['resident_id' => $resident->getKey()], 'base_snapshot' => ['field_hash' => OfflineFieldHasher::resident($resident)]],
        ));

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertNull($this->storedType($resident));
    }

    public function test_erd_schema_saves_presents_and_hashes_the_type_beside_philhealth_number(): void
    {
        ClientTestingErdSchema::ensure();
        Household::resetResolvedKeyName();
        Resident::resetResolvedKeyName();
        if (! Schema::hasColumn('residents', PhilhealthMembershipTypes::COLUMN)) {
            Schema::table('residents', function (Blueprint $table) {
                $table->unsignedBigInteger('philhealth_membership_type_id')->nullable();
            });
        }
        $this->forgetAvailability();

        DB::table('occupation')->insert(['occupation_name' => 'Teacher']);
        DB::table('religion')->insert(['religion_name' => 'Roman Catholic']);
        $householdId = (int) DB::table('households')->insertGetId([
            'household_no' => 'HH-830',
            'purok' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'household_id');
        $household = Household::query()->findOrFail($householdId);
        $service = app(ResidentService::class);
        $resident = $service->create($household, $this->payload([
            'philhealth' => '555566667777',
            'philhealth_membership_type' => 'employed_government',
        ]));

        $this->assertSame('555566667777', $resident->philhealth_number);
        $this->assertSame(PhilhealthMembershipTypes::idForCode('employed_government'), $this->storedType($resident));
        $presented = HouseholdProfilingPresenter::memberFromModel($resident->fresh());
        $this->assertSame('employed_government', $presented['philhealth_membership_type']);
        $this->assertSame('Employed Government', $presented['philhealth_membership_type_label']);
        $this->assertSame('employed_government', OfflineFieldHasher::residentSnapshot($resident->fresh())['philhealth_membership_type']);
    }
}
