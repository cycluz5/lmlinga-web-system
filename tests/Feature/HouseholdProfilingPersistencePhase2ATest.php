<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Services\HouseholdService;
use App\Services\ResidentService;
use App\Support\DemoCatalog;
use App\Support\HouseholdMemberResolver;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * DB-17 Phase 2A — Household shell create/update persistence.
 */
class HouseholdProfilingPersistencePhase2ATest extends TestCase
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
    private function validHouseholdPayload(array $overrides = []): array
    {
        return array_merge([
            'household_no' => '121',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'address' => '123 Layuan St., Brgy. La Medalla',
            'latitude' => '7.12345678',
            'longitude' => '125.12345678',
            'accomplished_by' => 'Maria BHW',
        ], $overrides);
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

    public function test_household_create_persists_to_database(): void
    {
        $response = $this->post(route('household-profiling.store'), $this->validHouseholdPayload());

        $household = Household::query()->first();
        $this->assertNotNull($household);
        $this->assertSame('121', (string) $household->household_no);

        $response->assertRedirect();
        $this->assertStringContainsString(
            '/environmental-health/household-water-supply',
            (string) $response->headers->get('Location')
        );
        $this->assertStringContainsString('handoff=', (string) $response->headers->get('Location'));

        $this->assertDatabaseHas('households', [
            'id' => $household->id,
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'address' => '123 Layuan St., Brgy. La Medalla',
            'accomplished_by' => 'Maria BHW',
        ]);
        $this->assertSame('2026-01-15', $household->date_registered?->format('Y-m-d'));
    }

    public function test_created_household_survives_reload(): void
    {
        $this->post(route('household-profiling.store'), $this->validHouseholdPayload([
            'zone' => 'Zone 3',
            'street' => 'Dalipay St.',
        ]))->assertRedirect();

        $household = Household::query()->firstOrFail();

        $html = $this->get(route('household-profiling.view', [
            'householdNo' => $household->household_no,
        ]))
            ->assertOk()
            ->assertSee('Zone 3', false)
            ->getContent();

        $this->assertStringContainsString('data-source="db"', $html);
        $this->assertStringContainsString('data-household-no="'.$household->household_no.'"', $html);
    }

    public function test_household_update_persists_and_survives_reload(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-810',
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
            'accomplished_by' => 'Before Edit',
        ]);

        $this->put(route('household-profiling.update', ['householdNo' => 'HH-810']), $this->validHouseholdPayload([
            'zone' => 'Zone 5',
            'street' => 'Cateel Bay St.',
            'accomplished_by' => 'After Edit',
            'address' => 'Updated Address',
            'household_no' => '999',
        ]))
            ->assertRedirect(route('household-profiling.view', ['householdNo' => 'HH-810']));

        $household->refresh();
        $this->assertSame('HH-810', $household->household_no);
        $this->assertSame('Zone 5', $household->zone);
        $this->assertSame('Cateel Bay St.', $household->street);
        $this->assertSame('After Edit', $household->accomplished_by);
        $this->assertSame('Updated Address', $household->address);

        $this->get(route('household-profiling.view', ['householdNo' => 'HH-810']))
            ->assertOk()
            ->assertSee('Zone 5', false)
            ->assertSee('data-source="db"', false);
    }

    public function test_duplicate_household_no_rejected_by_unique_constraint(): void
    {
        Household::factory()->create(['household_no' => 'HH-777']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Household::factory()->create(['household_no' => 'HH-777']);
    }

    public function test_client_supplied_household_no_is_persisted_on_create(): void
    {
        Household::factory()->create(['household_no' => 'HH-100']);

        $this->post(route('household-profiling.store'), $this->validHouseholdPayload([
            'household_no' => '121',
            'id' => 999,
            'household_id' => 999,
        ]))->assertRedirect();

        $this->assertSame(2, Household::query()->count());
        $created = Household::query()->where('household_no', '121')->firstOrFail();
        $this->assertSame('121', $created->household_no);
        $this->assertNotSame(999, (int) $created->id);
        $this->assertNotSame(999, (int) $created->getKey());
    }

    public function test_db_only_household_does_not_require_democatalog(): void
    {
        $this->actingAsStaff(\App\Support\StaffRole::BHW);
        $household = Household::factory()->create([
            'household_no' => 'HH-888',
            'zone' => 'Zone 4',
            'street' => 'Unique Db Street',
            'accomplished_by' => 'Db Only Staff',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-888',
            'first_name' => 'DbOnly',
            'last_name' => 'Head',
            'relation' => 'Head',
        ]);

        $this->assertNull(DemoCatalog::findHousehold('HH-888'));

        $index = $this->get(route('household-profiling.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-household-no="HH-888"', $index);
        $this->assertStringContainsString('DbOnly Head', $index);

        $view = $this->get(route('household-profiling.view', ['householdNo' => 'HH-888']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-source="db"', $view);
        $this->assertStringContainsString('DbOnly Head', $view);
        $this->assertStringNotContainsString('Unique Db Street', $view);
        $this->assertStringNotContainsString('>Street</span>', $view);
        $this->assertStringNotContainsString('>Accomplished By</span>', $view);

        $this->get(route('household-profiling.members.create', ['householdNo' => 'HH-888']))
            ->assertOk()
            ->assertSee('data-persistable="1"', false);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-888']),
            $this->validMemberPayload([
                'relation' => 'Son',
                'first_name' => 'DbChild',
                'last_name' => 'Head',
                'sex' => 'Male',
            ])
        )->assertRedirect();

        $this->assertSame(2, $household->residents()->count());
    }

    public function test_household_residents_relation_remains_correct_after_shell_update(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-820']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-820',
            'relation' => 'Head',
        ]);

        $this->put(route('household-profiling.update', ['householdNo' => 'HH-820']), $this->validHouseholdPayload([
            'zone' => 'Zone 1',
            'street' => 'Updated St.',
        ]))->assertRedirect();

        $household->refresh();
        $this->assertSame(1, $household->residents()->count());
        $this->assertTrue($household->residents->contains('id', $resident->id));
        $this->assertSame($household->id, $resident->fresh()->household_id);
    }

    public function test_cross_household_member_scoping_remains_protected(): void
    {
        $householdA = Household::factory()->create(['household_no' => 'HH-830']);
        $householdB = Household::factory()->create(['household_no' => 'HH-831']);
        Resident::factory()->create([
            'household_id' => $householdA->id,
            'member_no' => 'MB-830',
            'relation' => 'Head',
        ]);

        $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-831',
            'memberId' => 'MB-830',
        ]))->assertOk()->assertSee('Member was not found', false);

        $this->put(route('household-profiling.members.update', [
            'householdNo' => 'HH-831',
            'memberId' => 'MB-830',
        ]), $this->validMemberPayload())->assertNotFound();

        $this->assertSame(0, $householdB->residents()->count());
    }

    public function test_create_with_head_rolls_back_household_when_head_fails(): void
    {
        Resident::creating(function (): void {
            throw ValidationException::withMessages([
                'relation' => 'Forced head failure for rollback test.',
            ]);
        });

        $service = app(HouseholdService::class);
        $residents = app(ResidentService::class);

        try {
            $service->createWithHead(
                $this->validHouseholdPayload(['street' => 'Atomic St.']),
                $this->validMemberPayload(['relation' => 'Head']),
                $residents
            );
            $this->fail('Expected ValidationException from head create.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('relation', $e->errors());
        }

        $this->assertSame(0, Household::query()->where('street', 'Atomic St.')->count());
        $this->assertSame(0, Resident::query()->count());
    }

    public function test_create_with_head_rolls_back_when_second_head_would_violate_invariant(): void
    {
        $beforeHouseholds = Household::query()->count();
        $beforeResidents = Resident::query()->count();

        $service = app(HouseholdService::class);
        $residents = app(ResidentService::class);

        try {
            DB::transaction(function () use ($service, $residents): void {
                $household = $service->create($this->validHouseholdPayload([
                    'street' => 'Rollback St.',
                ]));
                $residents->create($household, $this->validMemberPayload([
                    'relation' => 'Head',
                    'first_name' => 'First',
                ]));
                $residents->create($household, $this->validMemberPayload([
                    'relation' => 'Head',
                    'first_name' => 'Second',
                ]));
            });
            $this->fail('Expected ValidationException for second Head.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('relation', $e->errors());
        }

        $this->assertSame($beforeHouseholds, Household::query()->count());
        $this->assertSame($beforeResidents, Resident::query()->count());
        $this->assertSame(0, Household::query()->where('street', 'Rollback St.')->count());
    }

    public function test_create_persists_client_household_no_independent_of_democatalog(): void
    {
        $this->assertNotNull(DemoCatalog::findHousehold('HH-156'));

        $this->post(route('household-profiling.store'), $this->validHouseholdPayload([
            'household_no' => '121',
        ]))->assertRedirect();

        $created = Household::query()->firstOrFail();
        $this->assertSame('121', (string) $created->household_no);
        $this->assertNull(DemoCatalog::findHousehold('121'));
    }

    public function test_create_and_edit_forms_render(): void
    {
        $this->get(route('household-profiling.create'))
            ->assertOk()
            ->assertSee('Register Household', false)
            ->assertSee(route('household-profiling.store'), false)
            ->assertSee('name="household_no"', false)
            ->assertDontSee('HH-### will be assigned', false)
            ->assertDontSee('Assigned automatically', false);

        $household = Household::factory()->create(['household_no' => 'HH-850']);

        $this->get(route('household-profiling.edit', ['householdNo' => 'HH-850']))
            ->assertOk()
            ->assertSee('Edit Household', false)
            ->assertSee('HH-850', false)
            ->assertSee('Household number cannot be changed', false);

        $this->get(route('household-profiling.edit', ['householdNo' => 'HH-151']))
            ->assertOk()
            ->assertSee('Demo-only households cannot be edited', false);
    }

    public function test_resolver_still_resolves_db_household_for_member_ops(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-860']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-860',
            'relation' => 'Head',
            'first_name' => 'Resolver',
            'last_name' => 'Check',
        ]);

        $resolved = app(HouseholdMemberResolver::class)->resolveMember('HH-860', 'MB-860');
        $this->assertNotNull($resolved);
        $this->assertSame('db', $resolved['source']);
        $this->assertSame($household->id, $resolved['household']->id);
        $this->assertSame('MB-860', $resolved['resident']->member_no);
    }
}
