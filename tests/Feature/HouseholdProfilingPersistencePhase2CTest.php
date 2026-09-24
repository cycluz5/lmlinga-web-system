<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Services\HouseholdService;
use App\Services\ResidentService;
use App\Support\DemoCatalog;
use App\Support\StaffRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * DB-17 Phase 2C — Household member DB integrity hardening.
 */
class HouseholdProfilingPersistencePhase2CTest extends TestCase
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

    private function dbOnlyHousehold(string $householdNo): Household
    {
        $this->assertNull(DemoCatalog::findHousehold($householdNo));

        return Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 3',
            'street' => 'DB-only Street',
        ]);
    }

    public function test_db_only_household_member_creation_works_without_democatalog(): void
    {
        $household = $this->dbOnlyHousehold('HH-980');

        $response = $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-980']),
            $this->validMemberPayload([
                'relation' => 'Head',
                'first_name' => 'First',
            ])
        );

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();
        $response->assertRedirect(route('household-profiling.members.show', [
            'householdNo' => 'HH-980',
            'memberId' => $resident->member_no,
        ]));
        $this->assertMatchesRegularExpression('/^MB-\d{3,}$/', $resident->member_no);
    }

    public function test_first_and_second_member_receive_server_allocated_member_numbers(): void
    {
        $household = $this->dbOnlyHousehold('HH-981');

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-981']),
            $this->validMemberPayload([
                'relation' => 'Head',
                'first_name' => 'Head',
            ])
        )->assertRedirect();

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-981']),
            $this->validMemberPayload([
                'relation' => 'Son',
                'first_name' => 'Second',
                'sex' => 'Male',
            ])
        )->assertRedirect();

        $members = Resident::query()
            ->where('household_id', $household->id)
            ->orderBy('id')
            ->pluck('member_no')
            ->all();

        $this->assertCount(2, $members);
        $this->assertMatchesRegularExpression('/^MB-\d{3,}$/', $members[0]);
        $this->assertMatchesRegularExpression('/^MB-\d{3,}$/', $members[1]);
        $this->assertNotSame($members[0], $members[1]);
    }

    public function test_forged_member_no_household_id_and_household_no_are_ignored(): void
    {
        $household = $this->dbOnlyHousehold('HH-982');
        $other = $this->dbOnlyHousehold('HH-983');

        // Seed a higher global member_no so the next server allocation is deterministic
        // and cannot coincidentally equal a forged client value like MB-001.
        Resident::factory()->create([
            'household_id' => $other->id,
            'member_no' => 'MB-007',
            'relation' => 'Head',
        ]);

        $forgedMemberNo = 'MB-999';

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-982']),
            $this->validMemberPayload([
                'relation' => 'Head',
                'member_no' => $forgedMemberNo,
                'household_id' => $other->id,
                'household_no' => 'HH-983',
            ])
        )->assertRedirect();

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();
        $this->assertSame($household->id, $resident->household_id);
        $this->assertSame('MB-008', $resident->member_no);
        $this->assertNotSame($forgedMemberNo, $resident->member_no);
        $this->assertSame(0, Resident::query()->where('household_id', $other->id)->where('member_no', 'MB-008')->count());
    }

    public function test_member_number_survives_reload_and_edit_unchanged(): void
    {
        $household = $this->dbOnlyHousehold('HH-984');
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-984',
            'relation' => 'Head',
        ]);

        $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-984',
            'memberId' => 'MB-984',
        ]))->assertOk()->assertSee('MB-984', false);

        $this->put(route('household-profiling.members.update', [
            'householdNo' => 'HH-984',
            'memberId' => 'MB-984',
        ]), $this->validMemberPayload([
            'relation' => 'Head',
            'first_name' => 'Edited',
            'member_no' => 'MB-999',
            'household_id' => 9999,
        ]))->assertRedirect();

        $fresh = $resident->fresh();
        $this->assertSame('MB-984', $fresh->member_no);
        $this->assertSame('Edited', $fresh->first_name);
        $this->assertSame($household->id, $fresh->household_id);
    }

    public function test_member_number_allocation_is_persisted_db_only_not_democatalog(): void
    {
        $this->assertNotNull(DemoCatalog::findHousehold('HH-151'));
        $service = app(ResidentService::class);

        $this->assertSame('MB-001', $service->allocateNextMemberNo());

        Resident::factory()->create(['member_no' => 'MB-250']);
        $this->assertSame('MB-251', $service->allocateNextMemberNo());
    }

    public function test_database_rejects_duplicate_member_no_globally(): void
    {
        $householdA = $this->dbOnlyHousehold('HH-985');
        $householdB = $this->dbOnlyHousehold('HH-986');

        Resident::factory()->create([
            'household_id' => $householdA->id,
            'member_no' => 'MB-777',
        ]);

        $this->expectException(QueryException::class);
        Resident::factory()->create([
            'household_id' => $householdB->id,
            'member_no' => 'MB-777',
        ]);
    }

    public function test_application_prevents_second_household_head_on_create_and_update(): void
    {
        $household = $this->dbOnlyHousehold('HH-987');
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-987',
            'relation' => 'Head',
        ]);
        $spouse = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-988',
            'relation' => 'Spouse',
        ]);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-987']),
            $this->validMemberPayload(['relation' => 'Head'])
        )->assertSessionHasErrors('relation');

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-987',
                'memberId' => $spouse->member_no,
            ]),
            $this->validMemberPayload([
                'relation' => 'Head',
                'first_name' => $spouse->first_name,
                'last_name' => $spouse->last_name,
                'sex' => $spouse->sex,
            ])
        )->assertSessionHasErrors('relation');

        $this->assertSame(1, Resident::query()
            ->where('household_id', $household->id)
            ->where('relation', 'Head')
            ->count());
    }

    public function test_database_prevents_second_active_head_when_service_validation_bypassed(): void
    {
        if (! $this->residentsHaveUniqueActiveHeadConstraint()) {
            $this->markTestSkipped(
                'Laravel-migration sqlite does not unique-index household heads. Application validation remains the production guard. Live ERD unique-head is not added by this test.'
            );
        }

        $household = $this->dbOnlyHousehold('HH-988');

        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-990',
            'relation' => 'Head',
        ]);

        $this->expectException(QueryException::class);

        Resident::query()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-991',
            'last_name' => 'Bypass',
            'first_name' => 'Direct',
            'middle_name' => null,
            'relation' => 'Head',
            'birthday' => '1990-01-01',
            'sex' => 'Female',
            'relationship_status' => 'Single',
            'occupation' => 'Teacher',
            'monthly_income' => '20,000 – 29,999',
            'religion' => 'Roman Catholic',
            'education' => 'College Graduate',
            'fp_user' => 'No',
            'philhealth' => null,
            'disability' => ['none'],
            'disability_others' => null,
            'medical_history' => ['none'],
            'medical_others' => null,
        ]);
    }

    public function test_head_creation_failure_rolls_back_household_create_with_head_and_keeps_existing_head(): void
    {
        $existingHousehold = $this->dbOnlyHousehold('HH-989');
        Resident::factory()->create([
            'household_id' => $existingHousehold->id,
            'member_no' => 'MB-989',
            'relation' => 'Head',
        ]);

        $householdService = app(HouseholdService::class);
        $residentService = app(ResidentService::class);
        $beforeHouseholds = Household::query()->count();
        $beforeResidents = Resident::query()->count();

        try {
            DB::transaction(function () use ($householdService, $residentService): void {
                $householdService->createWithHead(
                    [
                        'household_no' => '992',
                        'zone' => 'Zone 1',
                        'street' => 'Rollback St.',
                        'date_registered' => '2026-08-20',
                        'address' => null,
                        'latitude' => null,
                        'longitude' => null,
                        'accomplished_by' => null,
                    ],
                    [
                        ...$this->validMemberPayload([
                            'relation' => 'Head',
                            'first_name' => 'First',
                            'last_name' => 'Head',
                        ]),
                    ],
                    $residentService
                );

                // Force a second head in the same new household to fail.
                $createdHousehold = Household::query()->where('street', 'Rollback St.')->firstOrFail();
                $residentService->create($createdHousehold, $this->validMemberPayload([
                    'relation' => 'Head',
                    'first_name' => 'Second',
                    'last_name' => 'Head',
                ]));
            });

            $this->fail('Expected ValidationException for duplicate head.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('relation', $e->errors());
        }

        $this->assertSame($beforeHouseholds, Household::query()->count());
        $this->assertSame($beforeResidents, Resident::query()->count());
        $this->assertSame(1, Resident::query()
            ->where('household_id', $existingHousehold->id)
            ->where('relation', 'Head')
            ->count());
    }

    public function test_cross_household_member_update_blocked_and_health_link_stays_attached_to_same_resident(): void
    {
        $householdA = $this->dbOnlyHousehold('HH-990');
        $householdB = $this->dbOnlyHousehold('HH-991');
        $residentA = Resident::factory()->create([
            'household_id' => $householdA->id,
            'member_no' => 'MB-995',
            'relation' => 'Head',
        ]);

        // assessment_no is intentionally non-fillable; use the project factory.
        $assessment = RiskAssessment::factory()->create([
            'resident_id' => $residentA->id,
            'assessment_no' => 'RA-995',
            'conducted_at' => '2026-08-01',
            'red_flags' => ['none'],
        ]);
        $linkedResidentId = $assessment->resident_id;

        $this->assertSame($residentA->id, $linkedResidentId);
        $this->assertSame(1, RiskAssessment::query()->where('resident_id', $residentA->id)->count());

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-991',
                'memberId' => 'MB-995',
            ]),
            $this->validMemberPayload([
                'first_name' => 'ShouldNotMove',
                'member_no' => 'MB-999',
                'household_id' => $householdB->id,
            ])
        )->assertNotFound();

        $this->assertSame($householdA->id, $residentA->fresh()->household_id);
        $this->assertSame($residentA->id, $assessment->fresh()->resident_id);
        $this->assertSame($linkedResidentId, $assessment->fresh()->resident_id);
        $this->assertInstanceOf(RiskAssessment::class, $residentA->fresh()->riskAssessments()->first());
    }

    private function residentsHaveUniqueActiveHeadConstraint(): bool
    {
        try {
            foreach (Schema::getIndexes('residents') as $index) {
                if (! ($index['unique'] ?? false)) {
                    continue;
                }
                $name = strtolower((string) ($index['name'] ?? ''));
                if (str_contains($name, 'head')) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}