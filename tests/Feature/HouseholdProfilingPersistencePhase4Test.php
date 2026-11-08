<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\DeathRequest;
use App\Models\Household;
use App\Models\Resident;
use App\Support\HouseholdMemberResolver;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DB-05 Phase 4 — Household Profiling live DB cutover (list/summary/writes UX).
 */
class HouseholdProfilingPersistencePhase4Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsStaff(StaffRole::BHW);
    }

    /**
     * @return array<string, mixed>
     */
    private function validMemberPayload(array $overrides = []): array
    {
        return array_merge([
            'last_name' => 'Santos',
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'relation' => 'Spouse',
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

    public function test_schema_contract_unchanged(): void
    {
        $this->assertTrue(Schema::hasTable('households'));
        $this->assertTrue(Schema::hasTable('residents'));
        $this->assertFalse(Schema::hasColumn('residents', 'age'));
        $this->assertFalse(Schema::hasColumn('households', 'houseHead'));
        $this->assertFalse(Schema::hasColumn('households', 'is_household_head'));
        $this->assertFalse(Schema::hasColumn('residents', 'is_household_head'));
    }

    public function test_list_returns_db_household_with_derived_head_and_member_count(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-900',
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-900',
            'first_name' => 'Maria',
            'last_name' => 'Cruz',
            'relation' => 'Head',
            'sex' => 'Female',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-901',
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'relation' => 'Son',
            'sex' => 'Male',
        ]);

        $html = $this->get(route('household-profiling.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('HH-900', $html);
        $this->assertStringContainsString('Maria Cruz', $html);
        $this->assertMatchesRegularExpression(
            '/data-household-no="HH-900"[\s\S]*?data-members="2"[\s\S]*?data-male="1"[\s\S]*?data-female="1"/u',
            $html
        );
        $this->assertStringContainsString('data-stat="households">1</', $html);
        $this->assertStringContainsString('data-stat="respondents">2</', $html);
        $this->assertStringContainsString('data-stat="male">1</', $html);
        $this->assertStringContainsString('data-stat="female">1</', $html);
        $this->assertMatchesRegularExpression(
            '/data-household-no="HH-900"[\s\S]*?data-source="db"/u',
            $html
        );
        $this->assertStringContainsString(
            route('household-profiling.members.create', ['householdNo' => 'HH-900']),
            $html
        );
        $this->assertStringNotContainsString(
            'Add member to HH-900 — demo preview only. Nothing is saved.',
            $html
        );
        $this->assertStringNotContainsString('data-household-no="HH-151"', $html);
        $this->assertStringContainsString('data-total="1"', $html);
        $this->assertStringContainsString('Showing 1 of 1 households', $html);
        $this->assertSame(1, substr_count($html, 'data-hh-row'));
    }

    public function test_db_household_takes_precedence_without_duplicate_row(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-151',
            'zone' => 'Zone 5',
            'street' => 'Cateel Bay St.',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-880',
            'first_name' => 'DbHead',
            'last_name' => 'Only',
            'relation' => 'Head',
        ]);

        $html = $this->get(route('household-profiling.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-id="db-hh-151"'));
        $this->assertSame(0, substr_count($html, 'data-id="demo-hh-151"'));
        $this->assertStringContainsString('DbHead Only', $html);
        $this->assertStringNotContainsString('Kristine Reyes', $html);
    }

    public function test_soft_deleted_household_excluded_from_list(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-905']);
        $household->delete();

        $html = $this->get(route('household-profiling.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-household-no="HH-905"', $html);
    }

    public function test_soft_deleted_resident_excluded_from_member_count(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-906']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-906',
            'first_name' => 'Active',
            'last_name' => 'Member',
            'relation' => 'Head',
        ]);
        $gone = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-907',
            'first_name' => 'Gone',
            'last_name' => 'Member',
            'relation' => 'Son',
        ]);
        $gone->delete();

        $html = $this->get(route('household-profiling.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-household-no="HH-906"[\s\S]*?data-members="1"/u',
            $html
        );
        $this->assertStringNotContainsString('Gone Member', $html);
    }

    public function test_summary_cards_count_db_registered_records_only(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-910']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-910',
            'relation' => 'Head',
            'sex' => 'Male',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-911',
            'relation' => 'Spouse',
            'sex' => 'Female',
        ]);

        $html = $this->get(route('household-profiling.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-stat="households">\s*1\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-stat="respondents">\s*2\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-stat="male">\s*1\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-stat="female">\s*1\s*</u',
            $html
        );
        $this->assertStringNotContainsString('data-stat="households">60</', $html);
        $this->assertStringContainsString('data-household-no="HH-910"', $html);
        $this->assertStringNotContainsString('data-household-no="HH-151"', $html);
        $this->assertStringContainsString('data-total="1"', $html);
        $this->assertStringContainsString('Showing 1 of 1 households', $html);
        $this->assertSame(1, substr_count($html, 'data-hh-row'));
    }

    public function test_view_household_uses_db_and_hides_demo_preview_save_warning(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-912',
            'zone' => 'Zone 2',
            'street' => 'Dalipay St.',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-912',
            'first_name' => 'Liza',
            'last_name' => 'Gomez',
            'relation' => 'Head',
            'occupation' => 'Teacher',
            'sex' => 'Female',
            'birthday' => '1990-01-01',
        ]);

        $html = $this->get(route('household-profiling.view', ['householdNo' => 'HH-912']))
            ->assertOk()
            ->assertSee('Liza Gomez', false)
            ->assertSee('Teacher', false)
            ->getContent();

        $this->assertStringContainsString('data-source="db"', $html);
        $this->assertStringContainsString('Member add and edit save to the database', $html);
        $this->assertStringNotContainsString('Records are placeholders and are not saved', $html);
        $this->assertStringNotContainsString('Call to undefined function', $html);
    }

    public function test_unknown_household_does_not_keep_read_only_demo_messaging(): void
    {
        $html = $this->get(route('household-profiling.view', ['householdNo' => 'HH-151']))
            ->assertOk()
            ->assertSee('Household not found', false)
            ->assertDontSee('Kristine Reyes', false)
            ->getContent();

        $this->assertStringNotContainsString('data-source="demo"', $html);
        $this->assertStringNotContainsString('read-only compatibility fallback', $html);
    }

    public function test_add_member_persists_and_ignores_forged_identity_fields(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-920']);
        $other = Household::factory()->create(['household_no' => 'HH-921']);
        Resident::factory()->create([
            'household_id' => $other->id,
            'member_no' => 'MB-001',
            'relation' => 'Head',
        ]);

        $response = $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-920']),
            $this->validMemberPayload([
                'relation' => 'Head',
                'household_id' => $other->id,
                'member_no' => 'MB-001',
                'resident_id' => 999,
                'id' => 42,
                'age' => 99,
                'is_household_head' => true,
            ])
        );

        $resident = Resident::query()->where('household_id', $household->id)->first();
        $this->assertNotNull($resident);
        $this->assertSame($household->id, $resident->household_id);
        $this->assertMatchesRegularExpression('/^MB-\d{3,}$/', $resident->member_no);
        $this->assertNotSame('MB-001', $resident->member_no);
        $this->assertTrue($resident->birthday->equalTo('1990-03-15'));
        $this->assertFalse(Schema::hasColumn('residents', 'age'));

        $response->assertRedirect(route('household-profiling.members.show', [
            'householdNo' => 'HH-920',
            'memberId' => $resident->member_no,
        ]));
        $response->assertSessionHas('status', 'Household member added successfully.');
        $this->assertSame(1, Resident::query()->where('household_id', $other->id)->count());
        $this->assertSame('MB-001', Resident::query()->where('household_id', $other->id)->value('member_no'));
    }

    public function test_member_no_is_unique_across_creates(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-922']);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-922']),
            $this->validMemberPayload(['relation' => 'Head', 'first_name' => 'One'])
        )->assertRedirect();

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-922']),
            $this->validMemberPayload(['relation' => 'Spouse', 'first_name' => 'Two'])
        )->assertRedirect();

        $numbers = Resident::query()
            ->where('household_id', $household->id)
            ->pluck('member_no')
            ->all();

        $this->assertCount(2, $numbers);
        $this->assertCount(2, array_unique($numbers));
    }

    public function test_invalid_add_member_returns_validation_errors(): void
    {
        Household::factory()->create(['household_no' => 'HH-923']);

        $this->from(route('household-profiling.members.create', ['householdNo' => 'HH-923']))
            ->post(route('household-profiling.members.store', ['householdNo' => 'HH-923']), [])
            ->assertRedirect(route('household-profiling.members.create', ['householdNo' => 'HH-923']))
            ->assertSessionHasErrors(['last_name', 'birthday', 'disability', 'medical_history']);
    }

    public function test_json_fields_normalize_on_create(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-924']);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-924']),
            $this->validMemberPayload([
                'relation' => 'Head',
                'disability' => ['Physical Disability (PD)', 'others'],
                'disability_others' => 'Custom disability',
                'medical_history' => ['Hypertension', 'Diabetes Mellitus'],
            ])
        )->assertRedirect();

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();
        $this->assertSame(['Physical Disability (PD)', 'others'], $resident->disability);
        $this->assertSame('Custom disability', $resident->disability_others);
        $this->assertSame(['Hypertension', 'Diabetes Mellitus'], $resident->medical_history);
    }

    public function test_second_active_head_rejected_and_demote_allowed(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-930']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-930',
            'relation' => 'Head',
            'first_name' => 'Active',
            'last_name' => 'Head',
        ]);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-930']),
            $this->validMemberPayload(['relation' => 'Head'])
        )->assertSessionHasErrors('relation');

        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-931',
            'relation' => 'Spouse',
            'first_name' => 'Other',
            'last_name' => 'Person',
        ]);

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-930',
                'memberId' => 'MB-931',
            ]),
            $this->validMemberPayload([
                'relation' => 'Head',
                'first_name' => 'Other',
                'last_name' => 'Person',
            ])
        )->assertSessionHasErrors('relation');

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-930',
                'memberId' => 'MB-930',
            ]),
            $this->validMemberPayload([
                'relation' => 'Parent',
                'first_name' => 'Active',
                'last_name' => 'Head',
                'sex' => 'Male',
            ])
        )->assertRedirect();

        $this->assertSame('Parent', Resident::query()->where('member_no', 'MB-930')->value('relation'));
        $this->assertSame(0, Resident::query()
            ->where('household_id', $household->id)
            ->where('relation', 'Head')
            ->count());
    }

    public function test_edit_member_persists_and_blocks_reparent_and_cross_household(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-940']);
        $other = Household::factory()->create(['household_no' => 'HH-941']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-940',
            'relation' => 'Head',
            'first_name' => 'Before',
            'last_name' => 'Name',
            'birthday' => '1980-05-05',
        ]);

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-940',
                'memberId' => 'MB-940',
            ]),
            $this->validMemberPayload([
                'first_name' => 'After',
                'last_name' => 'Name',
                'relation' => 'Head',
                'birthday' => '1981-06-06',
                'sex' => 'Male',
                'member_no' => 'MB-999',
                'household_id' => $other->id,
            ])
        )->assertRedirect(route('household-profiling.members.show', [
            'householdNo' => 'HH-940',
            'memberId' => 'MB-940',
        ]))->assertSessionHas('status', 'Household member updated successfully.');

        $fresh = $resident->fresh();
        $this->assertSame('MB-940', $fresh->member_no);
        $this->assertSame($household->id, $fresh->household_id);
        $this->assertSame('After', $fresh->first_name);
        $this->assertTrue($fresh->birthday->equalTo('1981-06-06'));

        $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-941',
            'memberId' => 'MB-940',
        ]))->assertOk()->assertSee('Member was not found', false);
    }

    public function test_unknown_household_add_member_does_not_create_db_rows(): void
    {
        $this->assertNull(Household::query()->where('household_no', 'HH-151')->first());

        $html = $this->get(route('household-profiling.members.create', ['householdNo' => 'HH-151']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Household not found', $html);
        $this->assertStringNotContainsString('Demo preview only', $html);
        $this->assertStringNotContainsString('data-hh-member-form-el', $html);
        $this->assertStringNotContainsString('Kristine Reyes', $html);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-151']),
            $this->validMemberPayload(['relation' => 'Head'])
        )->assertNotFound();

        $this->assertSame(0, Household::query()->count());
        $this->assertSame(0, Resident::query()->count());
    }

    public function test_db_create_form_has_no_preview_only_banner(): void
    {
        Household::factory()->create(['household_no' => 'HH-950']);

        $html = $this->get(route('household-profiling.members.create', ['householdNo' => 'HH-950']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Add New Member', $html);
        $this->assertStringContainsString('data-persistable="1"', $html);
        $this->assertStringNotContainsString('Demo preview only', $html);
        $this->assertStringNotContainsString('Nothing is saved', $html);
    }

    public function test_db_resident_resolves_through_household_member_resolver(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-960']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-960',
            'first_name' => 'Resolved',
            'last_name' => 'Person',
            'relation' => 'Head',
        ]);

        $resolver = app(HouseholdMemberResolver::class);
        $resolved = $resolver->resolveMember('HH-960', 'MB-960');

        $this->assertNotNull($resolved);
        $this->assertSame('db', $resolved['source']);
        $this->assertTrue($resolved['resident']->is($resident));
        $this->assertSame('Resolved Person', $resolved['memberPresentation']['name']);
    }

    public function test_phase3_death_identity_still_works_for_persisted_resident(): void
    {
        Storage::fake('death_certificates');

        $household = Household::factory()->create(['household_no' => 'HH-970']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-970',
            'first_name' => 'Deceased',
            'last_name' => 'Person',
            'relation' => 'Head',
            'sex' => 'Male',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('health-records.death.store', [
                'householdNo' => 'HH-970',
                'memberId' => 'MB-970',
            ]), [
                'cause_of_death' => 'Cardiac arrest',
                'date_of_death' => '2026-07-12',
                'registry_no' => '2026-00970',
                'certificate_no' => 'DC-2026-00970',
                'death_certificate' => UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $request = DeathRequest::query()->firstOrFail();
        $this->assertSame($resident->id, $request->resident_id);
        $this->assertSame('HH-970', $request->household_no);
        $this->assertSame('MB-970', $request->member_id);
    }
}
