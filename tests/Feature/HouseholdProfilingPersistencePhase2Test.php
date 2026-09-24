<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Services\ResidentService;
use App\Support\DemoCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DB-05 Phase 2 — Household Profiling persistence integration.
 */
class HouseholdProfilingPersistencePhase2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsStaff(\App\Support\StaffRole::BHW);
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

    public function test_phase1_schema_contract_unchanged(): void
    {
        $this->assertTrue(Schema::hasTable('households'));
        $this->assertTrue(Schema::hasTable('residents'));
        $this->assertFalse(Schema::hasColumn('residents', 'age'));
        $this->assertFalse(Schema::hasColumn('households', 'houseHead'));
        $this->assertFalse(Schema::hasColumn('residents', 'is_household_head'));
    }

    public function test_list_includes_persisted_household_and_excludes_catalog_only(): void
    {
        Household::factory()->create([
            'household_no' => 'HH-900',
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
        ]);

        $html = $this->get(route('household-profiling.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-household-no="HH-900"', $html);
        $this->assertMatchesRegularExpression(
            '/data-household-no="HH-900"[\s\S]*?data-source="db"/u',
            $html
        );
        $this->assertStringNotContainsString('data-household-no="HH-151"', $html);
        $this->assertStringNotContainsString('data-household-no="HH-152"', $html);
        $this->assertStringNotContainsString('data-household-no="HH-153"', $html);
        $this->assertStringNotContainsString('data-household-no="HH-154"', $html);
        $this->assertStringNotContainsString('data-household-no="HH-155"', $html);
        $this->assertStringNotContainsString('data-household-no="HH-156"', $html);
        $this->assertStringContainsString('data-total="1"', $html);
        $this->assertStringContainsString('Showing 1 of 1 households', $html);
        $this->assertSame(1, substr_count($html, 'data-hh-row'));
    }

    public function test_db_household_wins_over_demo_same_household_no(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-151',
            'zone' => 'Zone 4',
            'street' => 'Cateel Bay St.',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-800',
            'first_name' => 'DbHead',
            'last_name' => 'Only',
            'relation' => 'Head',
        ]);

        $html = $this->get(route('household-profiling.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('DbHead Only', $html);
        $this->assertStringContainsString('Zone 4', $html);
        $this->assertStringNotContainsString('Cateel Bay St.', $html);
        $this->assertStringNotContainsString('Kristine Reyes', $html);
    }

    public function test_view_household_resolves_db_only_without_catalog_fallback(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-901',
            'zone' => 'Zone 2',
            'street' => 'Dalipay St.',
            'accomplished_by' => 'Test BHW',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-801',
            'first_name' => 'Maria',
            'last_name' => 'Lopez',
            'relation' => 'Head',
            'birthday' => '1985-01-01',
        ]);

        $this->get(route('household-profiling.view', ['householdNo' => 'HH-901']))
            ->assertOk()
            ->assertSee('Maria Lopez', false)
            ->assertSee('Zone 2', false)
            ->assertDontSee('Dalipay St.', false)
            ->assertDontSee('Test BHW', false);

        $this->get(route('household-profiling.view', ['householdNo' => 'HH-151']))
            ->assertOk()
            ->assertSee('Household not found', false)
            ->assertDontSee('Kristine Reyes', false);
    }

    public function test_derived_head_ignores_soft_deleted_head(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-902']);
        $head = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-802',
            'first_name' => 'Gone',
            'last_name' => 'Head',
            'relation' => 'Head',
        ]);
        $head->delete();

        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-803',
            'first_name' => 'Still',
            'last_name' => 'Here',
            'relation' => 'Spouse',
        ]);

        $html = $this->get(route('household-profiling.view', ['householdNo' => 'HH-902']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('—', $html);
        $this->assertStringNotContainsString('Gone Head', $html);
        $this->assertStringContainsString('Still Here', $html);
    }

    public function test_member_show_db_and_cross_household_404(): void
    {
        $a = Household::factory()->create(['household_no' => 'HH-910']);
        $b = Household::factory()->create(['household_no' => 'HH-911']);
        Resident::factory()->create([
            'household_id' => $a->id,
            'member_no' => 'MB-910',
            'first_name' => 'Scoped',
            'last_name' => 'Person',
            'relation' => 'Head',
        ]);

        $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-910',
            'memberId' => 'MB-910',
        ]))->assertOk()->assertSee('Scoped Person', false);

        $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-911',
            'memberId' => 'MB-910',
        ]))->assertOk()->assertSee('Member was not found', false);

        $this->assertDatabaseHas('residents', [
            'member_no' => 'MB-910',
            'household_id' => $a->id,
        ]);
        $this->assertSame(0, Resident::query()->where('household_id', $b->id)->count());
    }

    public function test_unknown_member_show_does_not_use_catalog_fallback(): void
    {
        $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))->assertOk()
            ->assertSee('Member was not found', false)
            ->assertDontSee('Kristine Reyes', false);
    }

    public function test_soft_deleted_resident_does_not_resolve(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-912']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-912',
            'first_name' => 'Deleted',
            'last_name' => 'Member',
            'relation' => 'Head',
        ]);
        $resident->delete();

        $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-912',
            'memberId' => 'MB-912',
        ]))->assertOk()->assertSee('Member was not found', false);
    }

    public function test_add_member_persists_under_route_household(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-920']);
        $other = Household::factory()->create(['household_no' => 'HH-919']);
        Resident::factory()->create([
            'household_id' => $other->id,
            'member_no' => 'MB-001',
            'relation' => 'Head',
        ]);

        $response = $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-920']),
            $this->validMemberPayload([
                'relation' => 'Head',
                'household_id' => 999999,
                'member_no' => 'MB-001',
                'id' => 42,
                'age' => 99,
            ])
        );

        $resident = Resident::query()->where('household_id', $household->id)->first();
        $this->assertNotNull($resident);
        $this->assertMatchesRegularExpression('/^MB-\d{3,}$/', $resident->member_no);
        $this->assertNotSame('MB-001', $resident->member_no);
        $this->assertSame($household->id, $resident->household_id);
        $this->assertTrue($resident->birthday->equalTo('1990-03-15'));
        $this->assertFalse(Schema::hasColumn('residents', 'age'));

        $response->assertRedirect(route('household-profiling.members.show', [
            'householdNo' => 'HH-920',
            'memberId' => $resident->member_no,
        ]));
        $response->assertSessionHas('status');
    }

    public function test_add_member_rejects_demo_only_household(): void
    {
        $this->assertNull(Household::query()->where('household_no', 'HH-151')->first());

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-151']),
            $this->validMemberPayload(['relation' => 'Head'])
        )->assertNotFound();

        $this->assertSame(0, Resident::query()->count());
    }

    public function test_add_member_validation_and_philhealth_rules(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-921']);

        $this->from(route('household-profiling.members.create', ['householdNo' => 'HH-921']))
            ->post(route('household-profiling.members.store', ['householdNo' => 'HH-921']), [])
            ->assertRedirect(route('household-profiling.members.create', ['householdNo' => 'HH-921']))
            ->assertSessionHasErrors(['last_name', 'birthday', 'disability', 'medical_history']);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-921']),
            $this->validMemberPayload(['birthday' => now()->addDay()->format('Y-m-d')])
        )->assertSessionHasErrors('birthday');

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-921']),
            $this->validMemberPayload(['philhealth' => '123'])
        )->assertSessionHasErrors('philhealth');

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-921']),
            $this->validMemberPayload(['philhealth' => 'abcdefghijkl'])
        )->assertSessionHasErrors('philhealth');

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-921']),
            $this->validMemberPayload(['philhealth' => '   ', 'relation' => 'Head'])
        )->assertRedirect();

        $this->assertNull(Resident::query()->where('household_id', $household->id)->value('philhealth'));
    }

    public function test_member_form_philhealth_input_is_not_html_required(): void
    {
        $this->actingAsStaff(\App\Support\StaffRole::BHW);
        Household::factory()->create(['household_no' => 'HH-922']);

        $html = $this->get(route('household-profiling.members.create', ['householdNo' => 'HH-922']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/id="lml-hh-philhealth"[^>]*>/',
            $html
        );
        preg_match('/id="lml-hh-philhealth"[^>]*>/', $html, $match);
        $this->assertNotEmpty($match[0] ?? null);
        $this->assertStringNotContainsString(' required', $match[0]);
        $this->assertStringNotContainsString('aria-required="true"', $match[0]);

        $memberFormJs = file_get_contents(resource_path('js/pages/household-member-form.js'));
        $this->assertIsString($memberFormJs);
        $this->assertStringNotContainsString('PhilHealth Number is required.', $memberFormJs);
        $this->assertStringContainsString("if (philhealth !== '' && !/^\\d{12}$/.test(philhealth))", $memberFormJs);
        $this->assertStringContainsString(
            'PhilHealth Number should be 12 digits for this UI preview.',
            $memberFormJs
        );
    }

    public function test_philhealth_twelve_digits_accepted(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-922']);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-922']),
            $this->validMemberPayload([
                'relation' => 'Head',
                'philhealth' => '987654321098',
            ])
        )->assertRedirect();

        $this->assertDatabaseHas('residents', [
            'household_id' => $household->id,
            'philhealth' => '987654321098',
        ]);
    }

    public function test_member_no_allocation_uses_persisted_db_suffixes_only(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-930']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-700',
        ]);

        $service = app(ResidentService::class);
        $next = $service->allocateNextMemberNo();

        $this->assertSame('MB-701', $next);
        $this->assertNotNull(DemoCatalog::findHousehold('HH-151'));
        $this->assertSame('MB-701', $service->allocateNextMemberNo());
    }

    public function test_head_rules_for_add_and_edit(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-940']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-940',
            'relation' => 'Head',
            'first_name' => 'Active',
            'last_name' => 'Head',
        ]);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-940']),
            $this->validMemberPayload(['relation' => 'Head'])
        )->assertSessionHasErrors('relation');

        $spouse = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-941',
            'relation' => 'Spouse',
            'first_name' => 'Other',
            'last_name' => 'Person',
        ]);

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-940',
                'memberId' => 'MB-941',
            ]),
            $this->validMemberPayload([
                'relation' => 'Head',
                'first_name' => 'Other',
                'last_name' => 'Person',
            ])
        )->assertSessionHasErrors('relation');

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-940',
                'memberId' => 'MB-940',
            ]),
            $this->validMemberPayload([
                'relation' => 'Parent',
                'first_name' => 'Active',
                'last_name' => 'Head',
                'sex' => 'Male',
            ])
        )->assertRedirect(route('household-profiling.members.show', [
            'householdNo' => 'HH-940',
            'memberId' => 'MB-940',
        ]));

        $this->assertSame('Parent', Resident::query()->where('member_no', 'MB-940')->value('relation'));
        $this->assertSame(0, Resident::query()
            ->where('household_id', $household->id)
            ->where('relation', 'Head')
            ->count());
    }

    public function test_soft_deleted_head_allows_new_head(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-941']);
        $old = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-942',
            'relation' => 'Head',
        ]);
        $old->delete();

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-941']),
            $this->validMemberPayload(['relation' => 'Head'])
        )->assertRedirect();

        $this->assertSame(1, Resident::query()
            ->where('household_id', $household->id)
            ->where('relation', 'Head')
            ->count());
    }

    public function test_edit_member_persists_and_keeps_identity_immutable(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-950']);
        $other = Household::factory()->create(['household_no' => 'HH-951']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-950',
            'relation' => 'Head',
            'first_name' => 'Before',
            'last_name' => 'Name',
            'birthday' => '1980-05-05',
        ]);

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-950',
                'memberId' => 'MB-950',
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
            'householdNo' => 'HH-950',
            'memberId' => 'MB-950',
        ]));

        $fresh = $resident->fresh();
        $this->assertSame('MB-950', $fresh->member_no);
        $this->assertSame($household->id, $fresh->household_id);
        $this->assertSame('After', $fresh->first_name);
        $this->assertTrue($fresh->birthday->equalTo('1981-06-06'));
    }

    public function test_demo_only_member_cannot_be_edited(): void
    {
        $this->get(route('household-profiling.members.edit', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))->assertOk()->assertSee('Member was not found', false);

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-001',
            ]),
            $this->validMemberPayload(['relation' => 'Head'])
        )->assertNotFound();
    }

    public function test_fallback_read_does_not_create_db_rows(): void
    {
        $beforeH = Household::query()->count();
        $beforeR = Resident::query()->count();

        $this->get(route('household-profiling.view', ['householdNo' => 'HH-151']))
            ->assertOk()
            ->assertSee('Household not found', false)
            ->assertDontSee('Kristine Reyes', false);
        $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))->assertOk()->assertDontSee('Kristine Reyes', false);

        $this->assertSame($beforeH, Household::query()->count());
        $this->assertSame($beforeR, Resident::query()->count());
    }

    public function test_json_fields_persist(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-960']);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-960']),
            $this->validMemberPayload([
                'relation' => 'Head',
                'disability' => ['Physical Disability (PD)', 'others'],
                'disability_others' => 'Custom disability',
                'medical_history' => ['Hypertension', 'Diabetes Mellitus'],
                'medical_others' => null,
            ])
        )->assertRedirect();

        $resident = Resident::query()->where('household_id', $household->id)->first();
        $this->assertSame(['Physical Disability (PD)', 'others'], $resident->disability);
        $this->assertSame('Custom disability', $resident->disability_others);
        $this->assertSame(['Hypertension', 'Diabetes Mellitus'], $resident->medical_history);
    }
}
