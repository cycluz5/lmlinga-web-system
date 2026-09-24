<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\TimbangRecord;
use App\Models\User;
use App\Support\RiskAssessmentErdMode;
use App\Support\StaffRole;
use App\Support\TimbangRecordService;
use App\Support\UserManagementErdMode;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ErdRiskAssessmentChildSchema;
use Tests\TestCase;

class NutritionalStatusProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-12')->startOfDay());
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
    private function seedMember(
        string $householdNo = 'HH-1818',
        string $memberNo = 'MB-1818',
        array $overrides = []
    ): array {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 1',
        ]);
        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'first_name' => 'Progress',
            'last_name' => 'Adult',
            'birthday' => '1990-05-01',
            'sex' => 'Female',
        ], $overrides));

        return compact('household', 'resident');
    }

    private function provisionErd(): void
    {
        ErdRiskAssessmentChildSchema::drop();
        Schema::dropIfExists('risk_assessments');
        Schema::dropIfExists('risk_assessment');
        Schema::dropIfExists('users');
        Schema::dropIfExists('user_management');

        Schema::create('user_management', function ($table): void {
            $table->id('user_id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('username')->unique();
            $table->string('password');
            $table->string('status')->nullable();
            $table->timestamps();
        });
        UserManagementErdMode::resetCachedState();

        Schema::create('risk_assessment', function ($table): void {
            $table->id('risk_assessment_id');
            $table->unsignedBigInteger('resident_id');
            $table->unsignedBigInteger('user_id');
            $table->string('tobacco_vape_usage')->nullable();
            $table->string('alcohol_intake')->nullable();
            $table->string('dietary_habits')->nullable();
            $table->string('physical_activity')->nullable();
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->decimal('waist_circum_cm', 8, 2)->nullable();
            $table->unsignedSmallInteger('systolic_blood_pressure')->nullable();
            $table->unsignedSmallInteger('diastolic_blood_pressure')->nullable();
            $table->string('blood_pressure_status')->nullable();
            $table->timestamps();
        });
        ErdRiskAssessmentChildSchema::create();
        RiskAssessmentErdMode::resetCachedState();
    }

    private function authenticateStaffUser(): User
    {
        $userId = (int) DB::table('user_management')->insertGetId([
            'name' => 'FR-18 Staff',
            'email' => fake()->unique()->safeEmail(),
            'username' => fake()->unique()->userName(),
            'password' => Hash::make('password'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var User $user */
        $user = User::query()->findOrFail($userId);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function raPayload(array $overrides = []): array
    {
        return array_merge([
            'tobacco' => 'never',
            'alcohol' => 'light',
            'dietary' => 'yes',
            'physical_activity' => 'yes',
            'height_cm' => '165',
            'weight_kg' => '60',
            'waist_cm' => '80',
            'systolic' => '120',
            'diastolic' => '80',
        ], $overrides);
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
     * @param  list<array{date: string, weight?: float|null, height?: float|null}>  $rows
     */
    private function seedHistory(Resident $resident, array $rows): void
    {
        foreach ($rows as $row) {
            TimbangRecord::factory()->create([
                'resident_id' => $resident->id,
                'measurement_date' => $row['date'],
                'weight_kg' => array_key_exists('weight', $row) ? $row['weight'] : 20,
                'height_cm' => array_key_exists('height', $row) ? $row['height'] : 110,
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function presentation(Resident $resident): array
    {
        return app(TimbangRecordService::class)->historyPresentationForResident($resident);
    }

    public function test_single_measurement_is_baseline(): void
    {
        ['resident' => $resident] = $this->seedMember();
        $this->seedHistory($resident, [
            ['date' => '2026-08-01', 'weight' => 20, 'height' => 110],
        ]);

        $rows = $this->presentation($resident);
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['is_weight_baseline']);
        $this->assertNull($rows[0]['weight_change']);
        $this->assertSame('—', $rows[0]['weight_change_label']);
    }

    public function test_weight_gain_shows_plus_five_kg(): void
    {
        ['resident' => $resident] = $this->seedMember();
        $this->seedHistory($resident, [
            ['date' => '2026-08-01', 'weight' => 20.00],
            ['date' => '2026-08-08', 'weight' => 25.00],
        ]);

        $rows = $this->presentation($resident);
        $this->assertSame('+5 kg', $rows[0]['weight_change_label']);
        $this->assertEqualsWithDelta(5.0, $rows[0]['weight_change'], 0.001);
        $this->assertTrue($rows[1]['is_weight_baseline']);
    }

    public function test_weight_loss_shows_minus_one_kg(): void
    {
        ['resident' => $resident] = $this->seedMember();
        $this->seedHistory($resident, [
            ['date' => '2026-08-01', 'weight' => 25.00],
            ['date' => '2026-08-08', 'weight' => 24.00],
        ]);

        $this->assertSame('-1 kg', $this->presentation($resident)[0]['weight_change_label']);
    }

    public function test_unchanged_weight_shows_zero_kg_not_plus_zero(): void
    {
        ['resident' => $resident] = $this->seedMember();
        $this->seedHistory($resident, [
            ['date' => '2026-08-01', 'weight' => 24.00],
            ['date' => '2026-08-08', 'weight' => 24.00],
        ]);

        $this->assertSame('0 kg', $this->presentation($resident)[0]['weight_change_label']);
    }

    public function test_decimal_weight_delta_trims_trailing_zeros(): void
    {
        ['resident' => $resident] = $this->seedMember();
        $this->seedHistory($resident, [
            ['date' => '2026-08-01', 'weight' => 20.50],
            ['date' => '2026-08-08', 'weight' => 21.00],
        ]);

        $this->assertSame('+0.5 kg', $this->presentation($resident)[0]['weight_change_label']);
    }

    public function test_height_only_row_is_skipped_for_weight_delta(): void
    {
        ['resident' => $resident] = $this->seedMember();
        $this->seedHistory($resident, [
            ['date' => '2026-08-01', 'weight' => 20, 'height' => 110],
            ['date' => '2026-08-15', 'weight' => null, 'height' => 111],
            ['date' => '2026-09-01', 'weight' => 22, 'height' => 112],
        ]);

        $byDate = [];
        foreach ($this->presentation($resident) as $row) {
            $byDate[$row['record']->measurement_date->toDateString()] = $row;
        }

        $this->assertSame('+2 kg', $byDate['2026-09-01']['weight_change_label']);
        $this->assertSame('—', $byDate['2026-08-15']['weight_change_label']);
        $this->assertFalse($byDate['2026-08-15']['is_weight_baseline']);
        $this->assertTrue($byDate['2026-08-01']['is_weight_baseline']);
        $this->assertSame('+1 cm', $byDate['2026-08-15']['height_change_label']);
        $this->assertSame('+1 cm', $byDate['2026-09-01']['height_change_label']);
    }

    public function test_first_weight_after_height_only_history_is_baseline(): void
    {
        ['resident' => $resident] = $this->seedMember();
        $this->seedHistory($resident, [
            ['date' => '2026-08-01', 'weight' => null, 'height' => 110],
            ['date' => '2026-09-01', 'weight' => 22, 'height' => 111],
        ]);

        $latest = $this->presentation($resident)[0];
        $this->assertTrue($latest['is_weight_baseline']);
        $this->assertNull($latest['weight_change']);
        $this->assertSame('—', $latest['weight_change_label']);
    }

    public function test_current_row_without_weight_has_no_weight_delta(): void
    {
        ['resident' => $resident] = $this->seedMember();
        $this->seedHistory($resident, [
            ['date' => '2026-08-01', 'weight' => 20, 'height' => 110],
            ['date' => '2026-09-01', 'weight' => null, 'height' => 111],
        ]);

        $latest = $this->presentation($resident)[0];
        $this->assertNull($latest['record']->weight_kg);
        $this->assertNull($latest['weight_change']);
        $this->assertFalse($latest['is_weight_baseline']);
        $this->assertSame('—', $latest['weight_change_label']);
    }

    public function test_same_date_uses_timbang_id_order(): void
    {
        ['resident' => $resident] = $this->seedMember();
        $first = TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-09-12',
            'weight_kg' => 10.00,
            'height_cm' => 80.00,
        ]);
        $second = TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-09-12',
            'weight_kg' => 10.50,
            'height_cm' => 80.50,
        ]);
        $this->assertGreaterThan($first->timbang_id, $second->timbang_id);

        $rows = $this->presentation($resident);
        $this->assertSame($second->timbang_id, $rows[0]['record']->timbang_id);
        $this->assertSame('+0.5 kg', $rows[0]['weight_change_label']);
        $this->assertTrue($rows[1]['is_weight_baseline']);
    }

    public function test_history_page_renders_progress_labels(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->seedHistory($resident, [
            ['date' => '2026-08-01', 'weight' => 20],
            ['date' => '2026-09-01', 'weight' => 25],
        ]);

        $html = $this->get(route(
            'household-profiling.members.nutritional-status',
            $this->routeParams($household, $resident)
        ))->assertOk()->getContent();

        $this->assertStringContainsString('Weight Progress', $html);
        $this->assertStringContainsString('data-weight-progress="+5 kg"', $html);
        $this->assertStringContainsString('data-weight-progress-baseline="true"', $html);
        $this->assertStringContainsString('Height Progress', $html);
        $this->assertFalse(Schema::hasColumn('timbang_records', 'weight_change'));
        $this->assertFalse(Schema::hasColumn('timbang_records', 'progress'));
        $this->assertFalse(Schema::hasColumn('timbang_records', 'delta'));
    }

    public function test_member_card_does_not_show_weight_progress(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->seedHistory($resident, [
            ['date' => '2026-08-01', 'weight' => 20],
            ['date' => '2026-09-01', 'weight' => 25],
        ]);

        $html = $this->get(route(
            'household-profiling.members.show',
            $this->routeParams($household, $resident)
        ))->assertOk()->getContent();

        $this->assertStringContainsString('25 kg', $html);
        $this->assertStringNotContainsString('Weight Progress', $html);
        $this->assertStringNotContainsString('+5 kg', $html);
    }

    public function test_fr17_ra_physical_update_shows_plus_two_kg_on_history(): void
    {
        $this->provisionErd();
        ['household' => $household, 'resident' => $resident] = $this->seedMember('HH-1819', 'MB-1819');
        $this->authenticateStaffUser();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.risk-assessment.store', $params), $this->raPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $first = $this->presentation($resident);
        $this->assertTrue($first[0]['is_weight_baseline']);
        $this->assertSame('—', $first[0]['weight_change_label']);

        $this->put(route('household-profiling.members.risk-assessment.section.update', $params + [
            'assessmentId' => 'RA-001',
            'section' => 'physical',
        ]), [
            'height_cm' => '165',
            'weight_kg' => '62',
            'waist_cm' => '80',
            'systolic' => '120',
            'diastolic' => '80',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $html = $this->get(route('household-profiling.members.nutritional-status', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-weight-progress="+2 kg"', $html);
        $this->assertSame(2, TimbangRecord::query()->where('resident_id', $resident->id)->count());
    }

    public function test_manual_and_ra_rows_share_one_progress_stream(): void
    {
        $this->provisionErd();
        ['household' => $household, 'resident' => $resident] = $this->seedMember('HH-1820', 'MB-1820');
        $this->authenticateStaffUser();
        $params = $this->routeParams($household, $resident);
        $store = route('household-profiling.members.nutritional-status.store', $params);

        $this->post($store, [
            'measurement_date' => '2026-08-01',
            'weight_kg' => '60',
            'height_cm' => '165',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post(
            route('household-profiling.members.risk-assessment.store', $params),
            $this->raPayload(['weight_kg' => '61'])
        )->assertRedirect()->assertSessionHasNoErrors();

        $this->post($store, [
            'measurement_date' => '2026-09-12',
            'weight_kg' => '59.5',
            'height_cm' => '165',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $chrono = array_reverse($this->presentation($resident));
        $this->assertCount(3, $chrono);
        $this->assertSame('—', $chrono[0]['weight_change_label']);
        $this->assertTrue($chrono[0]['is_weight_baseline']);
        $this->assertSame('+1 kg', $chrono[1]['weight_change_label']);
        $this->assertSame('-1.5 kg', $chrono[2]['weight_change_label']);
    }

    public function test_history_presentation_bmi_uses_each_rows_own_measurements(): void
    {
        ['resident' => $resident] = $this->seedMember();
        $this->seedHistory($resident, [
            ['date' => '2026-08-01', 'weight' => 20, 'height' => 110],
            ['date' => '2026-08-15', 'weight' => 20, 'height' => null],
            ['date' => '2026-09-01', 'weight' => null, 'height' => 111],
            ['date' => '2026-09-12', 'weight' => 21, 'height' => 111],
        ]);

        $byDate = [];
        foreach ($this->presentation($resident) as $row) {
            $byDate[$row['record']->measurement_date->toDateString()] = $row;
        }

        $this->assertSame('16.5', $byDate['2026-08-01']['bmi']);
        $this->assertSame('16.5', $byDate['2026-08-01']['bmi_label']);
        $this->assertNull($byDate['2026-08-15']['bmi']);
        $this->assertSame('—', $byDate['2026-08-15']['bmi_label']);
        $this->assertNull($byDate['2026-09-01']['bmi']);
        $this->assertSame('—', $byDate['2026-09-01']['bmi_label']);
        $this->assertSame('17.0', $byDate['2026-09-12']['bmi']);
        $this->assertSame('17.0', $byDate['2026-09-12']['bmi_label']);
        $this->assertFalse(Schema::hasColumn('timbang_records', 'bmi'));
    }
}
