<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Support\HealthRecordsRiskAssessment;
use App\Support\Offline\OfflineOperationType;
use App\Support\RiskAssessmentService;
use App\Support\StaffRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class RiskAssessmentAgeEligibilityTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    private const AS_OF = '2026-08-11';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::AS_OF)->startOfDay());
        $this->actingAsStaff(StaffRole::BHW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedMember(string $birthday, string $householdNo = 'HH-1419', string $memberNo = 'MB-1419'): array
    {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 1',
        ]);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'first_name' => 'Age',
            'last_name' => 'Gate',
            'birthday' => $birthday,
            'sex' => 'Female',
            'relation' => 'Daughter',
        ]);

        return compact('household', 'resident');
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

    /**
     * @return array<string, mixed>
     */
    private function validStorePayload(): array
    {
        return [
            'red_flags' => ['none'],
            'past_medical' => ['none'],
            'family_history' => ['none'],
            'tobacco' => 'never',
            'alcohol' => 'never',
            'dietary' => 'yes',
            'physical_activity' => 'yes',
            'height_cm' => '165',
            'weight_kg' => '58',
            'waist_cm' => '72',
            'systolic' => '120',
            'diastolic' => '80',
        ];
    }

    public function test_nineteenth_birthday_is_eligible(): void
    {
        $this->assertTrue(RiskAssessmentService::isEligibleForRiskAssessment([
            'birthday' => '2007-08-11',
        ]));
        $this->assertSame(19, HealthRecordsRiskAssessment::ageInYears(['birthday' => '2007-08-11']));
    }

    public function test_day_before_nineteenth_birthday_is_ineligible(): void
    {
        $this->assertFalse(RiskAssessmentService::isEligibleForRiskAssessment([
            'birthday' => '2007-08-12',
        ]));
        $this->assertSame(18, HealthRecordsRiskAssessment::ageInYears(['birthday' => '2007-08-12']));
    }

    public function test_older_than_nineteen_is_eligible(): void
    {
        $this->assertTrue(RiskAssessmentService::isEligibleForRiskAssessment([
            'birthday' => '1990-05-01',
        ]));
    }

    public function test_missing_birthday_is_ineligible(): void
    {
        $this->assertFalse(RiskAssessmentService::isEligibleForRiskAssessment(['birthday' => '']));
        $this->assertFalse(RiskAssessmentService::isEligibleForRiskAssessment([]));
        $resident = new Resident(['birthday' => null]);
        $this->assertFalse(RiskAssessmentService::isEligibleForRiskAssessment($resident));
    }

    public function test_under_19_member_card_shows_unavailable_state(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('2007-08-12', 'HH-1420', 'MB-1420');
        $params = $this->routeParams($household, $resident);

        $html = $this->get(route('household-profiling.members.show', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Risk Assessment', $html);
        $this->assertStringContainsString('data-hh-member-risk-assessment-unavailable', $html);
        $this->assertStringContainsString(RiskAssessmentService::INELIGIBLE_MESSAGE, $html);
        $this->assertStringNotContainsString('data-hh-member-risk-assessment"', $html);
        $this->assertStringContainsString('Family Planning', $html);
        $this->assertStringContainsString('Death', $html);
    }

    public function test_eligible_member_card_keeps_risk_assessment_action(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('2007-08-11', 'HH-1421', 'MB-1421');
        $params = $this->routeParams($household, $resident);

        $html = $this->get(route('household-profiling.members.show', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-hh-member-risk-assessment', $html);
        $this->assertStringNotContainsString('data-hh-member-risk-assessment-unavailable', $html);
        $this->assertStringContainsString(route('household-profiling.members.risk-assessment', $params), $html);
    }

    public function test_under_19_history_and_create_get_are_ineligible(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('2010-01-01', 'HH-1422', 'MB-1422');
        $params = $this->routeParams($household, $resident);

        $history = $this->get(route('household-profiling.members.risk-assessment', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-risk-assess-ineligible', $history);
        $this->assertStringContainsString(RiskAssessmentService::INELIGIBLE_MESSAGE, $history);
        $this->assertStringNotContainsString('data-risk-assess-add', $history);

        $create = $this->get(route('household-profiling.members.risk-assessment.create', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-risk-assess-ineligible', $create);
        $this->assertStringNotContainsString('data-risk-assess-form', $create);
    }

    public function test_eligible_get_create_and_history_remain_available(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('1990-05-01', 'HH-1423', 'MB-1423');
        $params = $this->routeParams($household, $resident);

        $this->get(route('household-profiling.members.risk-assessment', $params))->assertOk();
        $html = $this->get(route('household-profiling.members.risk-assessment.create', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('data-risk-assess-ineligible', $html);
        $this->assertStringContainsString('data-risk-assess-form', $html);
    }

    public function test_under_19_post_create_is_rejected_with_no_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('2008-01-01', 'HH-1424', 'MB-1424');
        $params = $this->routeParams($household, $resident);

        $this->from(route('household-profiling.members.risk-assessment.create', $params))
            ->post(route('household-profiling.members.risk-assessment.store', $params), $this->validStorePayload())
            ->assertRedirect()
            ->assertSessionHasErrors('assessment');

        $this->assertSame(0, RiskAssessment::query()->count());
    }

    public function test_under_19_section_update_is_rejected_and_row_unchanged(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('2012-03-01', 'HH-1425', 'MB-1425');
        $params = $this->routeParams($household, $resident);

        $row = RiskAssessment::factory()->create([
            'resident_id' => $resident->id,
            'assessment_no' => 'RA-001',
            'conducted_at' => '2026-01-15',
            'tobacco' => 'never',
        ]);

        $this->from(route('household-profiling.members.risk-assessment.section.edit', $params + [
            'assessmentId' => 'RA-001',
            'section' => 'lifestyle',
        ]))
            ->put(route('household-profiling.members.risk-assessment.section.update', $params + [
                'assessmentId' => 'RA-001',
                'section' => 'lifestyle',
            ]), [
                'tobacco' => 'current',
                'alcohol' => 'never',
                'dietary' => 'yes',
                'physical_activity' => 'yes',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('assessment');

        $this->assertSame(1, RiskAssessment::query()->count());
        $this->assertSame('never', $row->fresh()->tobacco);
    }

    public function test_under_19_section_edit_get_is_not_editable(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember('2012-03-01', 'HH-1426', 'MB-1426');
        $params = $this->routeParams($household, $resident);
        RiskAssessment::factory()->create([
            'resident_id' => $resident->id,
            'assessment_no' => 'RA-001',
            'conducted_at' => '2026-01-15',
        ]);

        $html = $this->get(route('household-profiling.members.risk-assessment.section.edit', $params + [
            'assessmentId' => 'RA-001',
            'section' => 'lifestyle',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-risk-assess-ineligible', $html);
        $this->assertStringNotContainsString('data-risk-assess-history-save', $html);
        $this->assertStringNotContainsString('data-risk-assess-history-edit', $html);
        $this->assertSame(1, RiskAssessment::query()->count());
    }

    public function test_service_create_rejects_under_19(): void
    {
        ['resident' => $resident] = $this->seedMember('2011-06-01', 'HH-1427', 'MB-1427');

        try {
            app(RiskAssessmentService::class)->createForResident($resident, $this->validStorePayload());
            $this->fail('Expected ineligible create to throw.');
        } catch (ValidationException $e) {
            $this->assertSame(
                [RiskAssessmentService::INELIGIBLE_MESSAGE],
                $e->errors()['assessment']
            );
        }

        $this->assertSame(0, RiskAssessment::query()->count());
    }

    public function test_service_section_update_rejects_under_19(): void
    {
        ['resident' => $resident] = $this->seedMember('2011-06-01', 'HH-1428', 'MB-1428');
        $row = RiskAssessment::factory()->create([
            'resident_id' => $resident->id,
            'assessment_no' => 'RA-001',
            'conducted_at' => '2026-01-15',
            'tobacco' => 'never',
        ]);

        try {
            app(RiskAssessmentService::class)->updateSectionForResident(
                $resident,
                'RA-001',
                'lifestyle',
                [
                    'tobacco' => 'current',
                    'alcohol' => 'never',
                    'dietary' => 'yes',
                    'physical_activity' => 'yes',
                ]
            );
            $this->fail('Expected ineligible update to throw.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assessment', $e->errors());
        }

        $this->assertSame('never', $row->fresh()->tobacco);
    }

    public function test_service_rejects_missing_birthday_without_500(): void
    {
        ['resident' => $resident] = $this->seedMember('1990-05-01', 'HH-1429', 'MB-1429');
        $resident->setAttribute('birthday', null);

        try {
            app(RiskAssessmentService::class)->createForResident($resident, $this->validStorePayload());
            $this->fail('Expected missing birthday to throw.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assessment', $e->errors());
        }

        $this->assertSame(0, RiskAssessment::query()->count());
    }

    public function test_resident_isolation_unchanged_for_eligible_members(): void
    {
        $a = $this->seedMember('1990-01-01', 'HH-1430', 'MB-1430');
        $b = $this->seedMember('1988-01-01', 'HH-1431', 'MB-1431');

        $this->post(
            route('household-profiling.members.risk-assessment.store', $this->routeParams($a['household'], $a['resident'])),
            $this->validStorePayload()
        )->assertRedirect();

        $this->assertSame(1, RiskAssessment::query()->where('resident_id', $a['resident']->id)->count());
        $this->assertSame(0, RiskAssessment::query()->where('resident_id', $b['resident']->id)->count());
    }

    public function test_guest_cannot_open_risk_assessment(): void
    {
        auth()->logout();
        ['household' => $household, 'resident' => $resident] = $this->seedMember('1990-01-01', 'HH-1432', 'MB-1432');

        $this->get(route(
            'household-profiling.members.risk-assessment',
            $this->routeParams($household, $resident)
        ))->assertRedirect();
    }

    public function test_offline_replay_rejects_under_19_write(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember('2015-01-01', 'HH-1433', 'MB-1433');

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            array_merge($this->validStorePayload(), [
                '_health_action' => 'risk_assessment_store',
            ]),
            ['parent_server' => [
                'household_id' => $household->getKey(),
                'household_no' => $household->household_no,
                'resident_id' => $resident->getKey(),
                'member_no' => $resident->member_no,
            ]],
        ));

        $response->assertStatus(422);
        $this->assertSame(0, RiskAssessment::query()->count());
    }
}
