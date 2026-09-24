<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\HealthRecordsChildCare;
use App\Support\HouseholdProfilingWriteGuard;
use App\Support\Offline\OfflineOperationType;
use App\Support\SchoolImmunizationErdMode;
use App\Support\SchoolImmunizationService;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\ErdSchoolImmunizationSchema;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

/**
 * FR-08 live ERD persistence + FR-09 0–59 month SBI age floor.
 */
class SchoolBasedImmunizationErdEligibilityTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    private SchoolImmunizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
        $this->service = app(SchoolImmunizationService::class);
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedResident(string $birthday, string $householdNo = 'HH-1008', string $memberNo = 'MB-1008'): array
    {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 1',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'first_name' => 'School',
            'last_name' => 'Erd',
            'relation' => 'Daughter',
            'birthday' => $birthday,
            'sex' => 'Female',
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    private function olderThanFloorBirthday(): string
    {
        return now()->subMonths(HealthRecordsChildCare::MAX_AGE_MONTHS + 1)->format('Y-m-d');
    }

    private function atFloorBirthday(): string
    {
        return now()->subMonths(HealthRecordsChildCare::MAX_AGE_MONTHS)->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    private function fullPayload(): array
    {
        return [
            'vaccines' => [
                'grade-1' => ['td' => '2025-01-15', 'mr' => '2025-01-20'],
                'grade-7' => ['td' => '2025-02-01', 'mr' => '2025-02-05'],
                'hpv' => ['1' => '2025-03-01', '2' => '2025-09-01'],
            ],
            'vaccine_types' => ['grade1_td'],
        ];
    }

    /**
     * @return array{householdNo: string, memberId: string}
     */
    private function routeParams(Household $household, Resident $resident): array
    {
        return [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];
    }

    public function test_erd_grade_1_td_and_mr_persist(): void
    {
        ErdSchoolImmunizationSchema::ensure();
        ['resident' => $resident] = $this->seedResident($this->olderThanFloorBirthday());

        $this->service->saveForResident($resident, [
            'vaccines' => [
                'grade-1' => ['td' => '2025-01-15', 'mr' => '2025-01-20'],
            ],
        ]);

        $this->assertSame(1, DB::table('school_immunization')->count());
        $row = DB::table('school_immunization')->first();
        $this->assertSame((int) $resident->getKey(), (int) $row->resident_id);
        $this->assertSame(SchoolImmunizationErdMode::GRADE_1, $row->grade_level);
        $this->assertSame('2025-01-15', $this->isoDate($row->td_date));
        $this->assertSame('2025-01-20', $this->isoDate($row->mr_date));
        $this->assertFalse(Schema::hasTable('school_immunizations'));
        $this->assertDatabaseCount('hpv_immunization', 0);
    }

    public function test_erd_grade_7_td_and_mr_persist(): void
    {
        ErdSchoolImmunizationSchema::ensure();
        ['resident' => $resident] = $this->seedResident($this->olderThanFloorBirthday());

        $this->service->saveForResident($resident, [
            'vaccines' => [
                'grade-7' => ['td' => '2025-02-01', 'mr' => '2025-02-05'],
            ],
        ]);

        $row = DB::table('school_immunization')->first();
        $this->assertSame(SchoolImmunizationErdMode::GRADE_7, $row->grade_level);
        $this->assertSame('2025-02-01', $this->isoDate($row->td_date));
        $this->assertSame('2025-02-05', $this->isoDate($row->mr_date));
    }

    public function test_erd_hpv_doses_persist_to_dose_number_rows(): void
    {
        ErdSchoolImmunizationSchema::ensure();
        ['resident' => $resident] = $this->seedResident($this->olderThanFloorBirthday());

        $this->service->saveForResident($resident, [
            'vaccines' => [
                'hpv' => ['1' => '2025-03-01', '2' => '2025-09-01'],
            ],
        ]);

        $this->assertSame(2, DB::table('hpv_immunization')->count());
        $first = DB::table('hpv_immunization')->where('dose_number', SchoolImmunizationErdMode::HPV_1ST_DOSE)->first();
        $second = DB::table('hpv_immunization')->where('dose_number', SchoolImmunizationErdMode::HPV_2ND_DOSE)->first();
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('2025-03-01', $this->isoDate($first->date_given));
        $this->assertSame('2025-09-01', $this->isoDate($second->date_given));
        $this->assertSame(0, DB::table('school_immunization')->count());
    }

    public function test_erd_repeat_save_updates_without_duplicate_rows(): void
    {
        ErdSchoolImmunizationSchema::ensure();
        ['resident' => $resident] = $this->seedResident($this->olderThanFloorBirthday());

        $this->service->saveForResident($resident, $this->fullPayload());
        $this->service->saveForResident($resident, [
            'vaccines' => [
                'grade-1' => ['td' => '2025-06-01', 'mr' => '2025-06-02'],
                'grade-7' => ['td' => '2025-07-01', 'mr' => '2025-07-02'],
                'hpv' => ['1' => '2025-08-01', '2' => '2025-08-02'],
            ],
        ]);

        $this->assertSame(2, DB::table('school_immunization')->count());
        $this->assertSame(2, DB::table('hpv_immunization')->count());
        $g1 = DB::table('school_immunization')->where('grade_level', SchoolImmunizationErdMode::GRADE_1)->first();
        $this->assertSame('2025-06-01', $this->isoDate($g1->td_date));
        $hpv1 = DB::table('hpv_immunization')->where('dose_number', SchoolImmunizationErdMode::HPV_1ST_DOSE)->first();
        $this->assertSame('2025-08-01', $this->isoDate($hpv1->date_given));
    }

    public function test_erd_read_hydrates_six_ui_slots(): void
    {
        ErdSchoolImmunizationSchema::ensure();
        ['household' => $household, 'resident' => $resident] = $this->seedResident($this->olderThanFloorBirthday());

        $this->service->saveForResident($resident, $this->fullPayload());
        $state = $this->service->forResident($resident->fresh());

        $this->assertTrue($state['persisted']);
        $this->assertSame('2025-01-15', $state['vaccines']['grade-1']['td']);
        $this->assertSame('2025-01-20', $state['vaccines']['grade-1']['mr']);
        $this->assertSame('2025-02-01', $state['vaccines']['grade-7']['td']);
        $this->assertSame('2025-02-05', $state['vaccines']['grade-7']['mr']);
        $this->assertSame('2025-03-01', $state['vaccines']['hpv']['1']);
        $this->assertSame('2025-09-01', $state['vaccines']['hpv']['2']);
        $this->assertSame(\App\Models\SchoolImmunization::SELECTABLE_TYPE_KEYS, $state['selected_vaccine_types']);

        $html = $this->get(route('household-profiling.members.school-based-immunization', $this->routeParams($household, $resident)))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="2025-01-15"', $html);
        $this->assertStringContainsString('value="2025-09-01"', $html);
    }

    public function test_erd_does_not_write_selected_vaccine_types_column(): void
    {
        ErdSchoolImmunizationSchema::ensure();
        $this->assertFalse(Schema::hasColumn('school_immunization', 'selected_vaccine_types'));
        $this->assertFalse(Schema::hasColumn('hpv_immunization', 'selected_vaccine_types'));
    }

    public function test_resident_a_erd_records_do_not_appear_for_resident_b(): void
    {
        ErdSchoolImmunizationSchema::ensure();
        ['household' => $householdA, 'resident' => $residentA] = $this->seedResident($this->olderThanFloorBirthday(), 'HH-1009', 'MB-1009');
        ['household' => $householdB, 'resident' => $residentB] = $this->seedResident($this->olderThanFloorBirthday(), 'HH-1010', 'MB-1010');

        $this->service->saveForResident($residentA, $this->fullPayload());

        $stateB = $this->service->forResident($residentB->fresh());
        $this->assertFalse($stateB['persisted']);
        $this->assertSame('', $stateB['vaccines']['grade-1']['td']);
        $this->assertSame(0, DB::table('school_immunization')->where('resident_id', $residentB->getKey())->count());

        $html = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->routeParams($householdB, $residentB)
        ))->assertOk()->getContent();
        $this->assertStringNotContainsString('value="2025-01-15"', $html);

        $this->assertSame(
            2,
            DB::table('school_immunization')->where('resident_id', $residentA->getKey())->count()
        );
        unset($householdA);
    }

    public function test_request_identity_fields_remain_prohibited_on_erd(): void
    {
        ErdSchoolImmunizationSchema::ensure();
        ['household' => $household, 'resident' => $resident] = $this->seedResident($this->olderThanFloorBirthday());

        $this->post(route('household-profiling.members.school-based-immunization.store', $this->routeParams($household, $resident)), array_merge($this->fullPayload(), [
            'resident_id' => 999,
            'school_immunization_id' => 999,
        ]))->assertSessionHasErrors(['resident_id', 'school_immunization_id']);

        $this->assertSame(0, DB::table('school_immunization')->count());
        $this->assertSame(0, DB::table('hpv_immunization')->count());
    }

    public function test_unsupported_schema_still_fails_closed(): void
    {
        Schema::dropIfExists('school_immunization_doses');
        Schema::dropIfExists('school_immunizations');
        Schema::dropIfExists('hpv_immunization');
        Schema::dropIfExists('school_immunization');
        SchoolImmunizationErdMode::resetCachedState();

        $this->assertTrue(HouseholdProfilingWriteGuard::isSchoolImmunizationWriteUnsupported());

        ['resident' => $resident] = $this->seedResident($this->olderThanFloorBirthday(), 'HH-1011', 'MB-1011');

        try {
            $this->service->saveForResident($resident, $this->fullPayload());
            $this->fail('Expected schema guard to reject the write.');
        } catch (ValidationException $e) {
            $this->assertContains(HouseholdProfilingWriteGuard::MESSAGE, $e->errors()['immunization'] ?? []);
        }
    }

    public function test_ineligible_get_shows_message_and_hides_edit_workflow(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            now()->subMonths(8)->format('Y-m-d'),
            'HH-1012',
            'MB-1012'
        );

        $html = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->routeParams($household, $resident)
        ))->assertOk()->getContent();

        $this->assertStringContainsString('data-sbi-ineligible', $html);
        $this->assertStringContainsString(SchoolImmunizationService::INELIGIBLE_TITLE, $html);
        $this->assertStringContainsString(SchoolImmunizationService::INELIGIBLE_DETAIL, $html);
        $this->assertStringNotContainsString('data-sbi-edit', $html);
        $this->assertStringNotContainsString('data-sbi-save', $html);
        $this->assertStringNotContainsString('name="vaccines[grade-1][td]"', $html);
        $memberBack = route('household-profiling.members.show', $this->routeParams($household, $resident));
        $this->assertStringContainsString('href="'.e($memberBack).'"', $html);
        $this->assertStringContainsString('class="lml-sbi__back lml-focus-ring"', $html);
        $this->assertStringContainsString('>Back</span>', $html);
        $this->assertStringNotContainsString('javascript:history.back()', $html);
    }

    public function test_ineligible_post_is_rejected_with_no_rows(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            now()->subMonths(8)->format('Y-m-d'),
            'HH-1013',
            'MB-1013'
        );

        $this->from(route('household-profiling.members.school-based-immunization', $this->routeParams($household, $resident)))
            ->post(
                route('household-profiling.members.school-based-immunization.store', $this->routeParams($household, $resident)),
                $this->fullPayload()
            )
            ->assertRedirect()
            ->assertSessionHasErrors('immunization');

        $this->assertDatabaseCount('school_immunizations', 0);
        $this->assertDatabaseCount('school_immunization_doses', 0);
    }

    public function test_max_age_months_boundary_is_ineligible_and_one_month_older_remains_accessible(): void
    {
        $atFloor = $this->seedResident($this->atFloorBirthday(), 'HH-1014', 'MB-1014');
        $aboveFloor = $this->seedResident($this->olderThanFloorBirthday(), 'HH-1015', 'MB-1015');

        $this->assertTrue(SchoolImmunizationService::isBelowSchoolImmunizationAgeFloor($atFloor['resident']));
        $this->assertFalse(SchoolImmunizationService::isEligibleForSchoolImmunization($atFloor['resident']));
        $this->assertFalse(SchoolImmunizationService::isBelowSchoolImmunizationAgeFloor($aboveFloor['resident']));
        $this->assertTrue(SchoolImmunizationService::isEligibleForSchoolImmunization($aboveFloor['resident']));

        $floorHtml = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->routeParams($atFloor['household'], $atFloor['resident'])
        ))->assertOk()->getContent();
        $this->assertStringContainsString('data-sbi-ineligible', $floorHtml);

        $openHtml = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->routeParams($aboveFloor['household'], $aboveFloor['resident'])
        ))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-sbi-ineligible', $openHtml);
        $this->assertStringContainsString('data-sbi-edit', $openHtml);
        $this->assertStringNotContainsString('Grade 1 eligible', $openHtml);
        $this->assertStringNotContainsString('Grade 7 eligible', $openHtml);
    }

    public function test_ineligible_erd_post_creates_no_live_rows(): void
    {
        ErdSchoolImmunizationSchema::ensure();
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            now()->subMonths(3)->format('Y-m-d'),
            'HH-1016',
            'MB-1016'
        );

        $this->post(
            route('household-profiling.members.school-based-immunization.store', $this->routeParams($household, $resident)),
            $this->fullPayload()
        )->assertSessionHasErrors('immunization');

        $this->assertSame(0, DB::table('school_immunization')->count());
        $this->assertSame(0, DB::table('hpv_immunization')->count());
    }

    public function test_offline_replay_rejects_ineligible_resident(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            now()->subMonths(8)->format('Y-m-d'),
            'HH-1017',
            'MB-1017'
        );

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            array_merge($this->fullPayload(), [
                '_health_action' => 'school_immunization_store',
            ]),
            ['parent_server' => [
                'household_id' => $household->getKey(),
                'household_no' => $household->household_no,
                'resident_id' => $resident->getKey(),
                'member_no' => $resident->member_no,
            ]],
        ));

        $response->assertStatus(422);
        $this->assertDatabaseCount('school_immunizations', 0);
        $this->assertDatabaseCount('school_immunization_doses', 0);
    }

    public function test_sixty_months_opens_form_and_persists_grade_1_and_grade_7(): void
    {
        ErdSchoolImmunizationSchema::ensure();
        $sixty = $this->seedResident(
            now()->subMonths(HealthRecordsChildCare::MAX_AGE_MONTHS + 1)->format('Y-m-d'),
            'HH-1019',
            'MB-1019'
        );

        $this->assertTrue(SchoolImmunizationService::isEligibleForSchoolImmunization($sixty['resident']));

        $html = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->routeParams($sixty['household'], $sixty['resident'])
        ))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-sbi-ineligible', $html);
        $this->assertStringContainsString('data-sbi-edit', $html);
        $this->assertStringNotContainsString('Grade 1 eligible', $html);
        $this->assertStringNotContainsString('Grade 7 eligible', $html);
        $this->assertStringNotContainsString('currently eligible for Grade 1 or Grade 7', $html);

        $this->service->saveForResident($sixty['resident'], [
            'vaccines' => [
                'grade-1' => ['td' => '2025-01-15', 'mr' => '2025-01-20'],
                'grade-7' => ['td' => '2025-02-01', 'mr' => '2025-02-05'],
            ],
        ]);

        $this->assertSame(2, DB::table('school_immunization')->where('resident_id', $sixty['resident']->getKey())->count());
    }

    public function test_unknown_birthday_is_ineligible_with_no_rows(): void
    {
        ErdSchoolImmunizationSchema::ensure();
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            $this->olderThanFloorBirthday(),
            'HH-1020',
            'MB-1020'
        );

        $this->clearResidentBirthday($resident);
        $resident = $resident->fresh();

        $this->assertFalse(SchoolImmunizationService::isEligibleForSchoolImmunization($resident));

        $html = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->routeParams($household, $resident)
        ))->assertOk()->getContent();

        $this->assertStringContainsString('data-sbi-ineligible', $html);
        $this->assertStringContainsString(SchoolImmunizationService::INELIGIBLE_TITLE, $html);
        $this->assertStringContainsString(SchoolImmunizationService::INELIGIBLE_UNKNOWN_DETAIL, $html);
        $this->assertStringNotContainsString('data-sbi-edit', $html);

        $this->post(
            route('household-profiling.members.school-based-immunization.store', $this->routeParams($household, $resident)),
            $this->fullPayload()
        )->assertSessionHasErrors('immunization');

        $this->assertSame(0, DB::table('school_immunization')->count());
        $this->assertSame(0, DB::table('hpv_immunization')->count());
    }

    public function test_unparseable_birthday_fails_closed_without_write(): void
    {
        ErdSchoolImmunizationSchema::ensure();
        ['resident' => $resident] = $this->seedResident(
            $this->olderThanFloorBirthday(),
            'HH-1021',
            'MB-1021'
        );

        $resident->mergeCasts(['birthday' => 'string']);
        $attrs = $resident->getAttributes();
        $attrs['birthday'] = 'not-a-date';
        $resident->setRawAttributes($attrs);

        $this->assertFalse(SchoolImmunizationService::isEligibleForSchoolImmunization($resident));

        try {
            $this->service->saveForResident($resident, $this->fullPayload());
            $this->fail('Expected unparseable birthday to reject the write.');
        } catch (ValidationException $e) {
            $this->assertContains(SchoolImmunizationService::INELIGIBLE_TITLE, $e->errors()['immunization'] ?? []);
        }

        $this->assertSame(0, DB::table('school_immunization')->count());
        $this->assertSame(0, DB::table('hpv_immunization')->count());
    }

    public function test_educational_attainment_does_not_select_grade(): void
    {
        ErdSchoolImmunizationSchema::ensure();
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            $this->olderThanFloorBirthday(),
            'HH-1022',
            'MB-1022'
        );

        $updates = [];
        if (Schema::hasColumn($resident->getTable(), 'education')) {
            $updates['education'] = 'Elementary Level';
        }
        if (Schema::hasColumn($resident->getTable(), 'educational_attainment')) {
            $updates['educational_attainment'] = 'Elementary Level';
        }
        if ($updates !== []) {
            $resident->forceFill($updates)->save();
        }

        $html = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->routeParams($household, $resident->fresh())
        ))->assertOk()->getContent();

        $this->assertStringContainsString('id="lml-sbi-grade-1"', $html);
        $this->assertStringContainsString('id="lml-sbi-grade-7"', $html);
        $this->assertStringNotContainsString('Grade 1 eligible', $html);
        $this->assertStringNotContainsString('Grade 7 eligible', $html);

        $this->service->saveForResident($resident->fresh(), [
            'vaccines' => [
                'grade-7' => ['td' => '2025-02-01', 'mr' => '2025-02-05'],
            ],
        ]);

        $this->assertSame(1, DB::table('school_immunization')->count());
        $this->assertSame(
            SchoolImmunizationErdMode::GRADE_7,
            DB::table('school_immunization')->first()->grade_level
        );
    }

    public function test_high_school_level_does_not_force_grade_7(): void
    {
        ErdSchoolImmunizationSchema::ensure();
        ['resident' => $resident] = $this->seedResident(
            $this->olderThanFloorBirthday(),
            'HH-1023',
            'MB-1023'
        );

        if (Schema::hasColumn($resident->getTable(), 'education')) {
            $resident->forceFill(['education' => 'High School Level'])->save();
        }

        $this->service->saveForResident($resident->fresh(), [
            'vaccines' => [
                'grade-1' => ['td' => '2025-01-15', 'mr' => '2025-01-20'],
            ],
        ]);

        $this->assertSame(
            SchoolImmunizationErdMode::GRADE_1,
            DB::table('school_immunization')->first()->grade_level
        );
    }

    public function test_offline_replay_accepts_eligible_resident(): void
    {
        ErdSchoolImmunizationSchema::ensure();
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            $this->olderThanFloorBirthday(),
            'HH-1024',
            'MB-1024'
        );

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            array_merge($this->fullPayload(), [
                '_health_action' => 'school_immunization_store',
            ]),
            ['parent_server' => [
                'household_id' => $household->getKey(),
                'household_no' => $household->household_no,
                'resident_id' => $resident->getKey(),
                'member_no' => $resident->member_no,
            ]],
        ));

        $response->assertOk();
        $this->assertSame(2, DB::table('school_immunization')->where('resident_id', $resident->getKey())->count());
        $this->assertSame(2, DB::table('hpv_immunization')->where('resident_id', $resident->getKey())->count());
    }

    public function test_offline_replay_rejects_unknown_birthday(): void
    {
        ErdSchoolImmunizationSchema::ensure();
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedResident(
            $this->olderThanFloorBirthday(),
            'HH-1025',
            'MB-1025'
        );

        $this->clearResidentBirthday($resident);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            array_merge($this->fullPayload(), [
                '_health_action' => 'school_immunization_store',
            ]),
            ['parent_server' => [
                'household_id' => $household->getKey(),
                'household_no' => $household->household_no,
                'resident_id' => $resident->getKey(),
                'member_no' => $resident->member_no,
            ]],
        ));

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('school_immunization')->count());
        $this->assertSame(0, DB::table('hpv_immunization')->count());
    }

    public function test_guest_cannot_open_school_based_immunization(): void
    {
        auth()->logout();
        ['household' => $household, 'resident' => $resident] = $this->seedResident($this->olderThanFloorBirthday(), 'HH-1018', 'MB-1018');

        $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->routeParams($household, $resident)
        ))->assertRedirect();
    }

    private function isoDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return \Carbon\Carbon::parse((string) $value)->format('Y-m-d');
    }

    private function clearResidentBirthday(Resident $resident): void
    {
        $table = $resident->getTable();
        $key = $resident->getKeyName();

        try {
            Schema::table($table, function (\Illuminate\Database\Schema\Blueprint $blueprint): void {
                $blueprint->date('birthday')->nullable()->change();
            });
        } catch (\Throwable) {
            // SQLite without doctrine change() still accepts an empty date string.
        }

        try {
            DB::table($table)->where($key, $resident->getKey())->update(['birthday' => null]);
        } catch (\Throwable) {
            DB::table($table)->where($key, $resident->getKey())->update(['birthday' => '']);
        }
    }
}