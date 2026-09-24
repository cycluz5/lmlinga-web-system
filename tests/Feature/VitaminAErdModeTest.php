<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\HealthRecordsVitaminA;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ErdChildNutritionSchema;
use Tests\TestCase;

class VitaminAErdModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ErdChildNutritionSchema::ensure();
    }

    public function test_erd_supplementation_rows_count_toward_vitamin_a_monitoring(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Vitamin',
            'last_name' => 'ErdChild',
            'sex' => 'Female',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);

        $childNutritionId = DB::table('child_nutrition')->insertGetId([
            'resident_id' => $resident->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nutrition_supplementation')->insert([
            'child_nutrition_id' => $childNutritionId,
            'supplement_type' => 'Vitamin A',
            'age_group' => '6-11 Months',
            'dose_number' => 1,
            'date_given' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row611 = collect(HealthRecordsVitaminA::monitoringRows())
            ->firstWhere('key', '6-11');

        $this->assertIsArray($row611);
        $this->assertSame('1', $row611['target']);
        $this->assertSame('0', $row611['va_100k_male']);
        $this->assertSame('1', $row611['va_100k_female']);
        $this->assertSame('1', $row611['va_100k_total']);
        $this->assertSame('100%', $row611['percentage']);
    }

    public function test_erd_mode_returns_empty_cells_when_no_supplementation(): void
    {
        foreach (HealthRecordsVitaminA::monitoringRows() as $row) {
            if (! empty($row['is_total'])) {
                continue;
            }

            $this->assertSame('0', (string) $row['target']);
            $this->assertSame('', (string) $row['percentage']);
        }
    }

    public function test_mnp_and_lns_sq_rows_are_not_counted(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'sex' => 'Female',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);

        $childNutritionId = DB::table('child_nutrition')->insertGetId([
            'resident_id' => $resident->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nutrition_supplementation')->insert([
            [
                'child_nutrition_id' => $childNutritionId,
                'supplement_type' => 'MNP',
                'age_group' => '6-11 Months',
                'dose_number' => 1,
                'date_given' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'child_nutrition_id' => $childNutritionId,
                'supplement_type' => 'LNS-SQ',
                'age_group' => '6-11 Months',
                'dose_number' => 1,
                'date_given' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $row611 = collect(HealthRecordsVitaminA::monitoringRows())->firstWhere('key', '6-11');
        $this->assertSame('1', $row611['target']);
        $this->assertSame('0', $row611['va_100k_total']);
        $this->assertSame('', $row611['percentage']);
    }

    public function test_undated_vitamin_a_is_not_counted(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'sex' => 'Male',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);

        $childNutritionId = DB::table('child_nutrition')->insertGetId([
            'resident_id' => $resident->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nutrition_supplementation')->insert([
            'child_nutrition_id' => $childNutritionId,
            'supplement_type' => 'Vitamin A',
            'age_group' => '6-11 Months',
            'dose_number' => 1,
            'date_given' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row611 = collect(HealthRecordsVitaminA::monitoringRows())->firstWhere('key', '6-11');
        $this->assertSame('0', $row611['va_100k_total']);
    }

    public function test_12_59_erd_vitamin_a_credits_200k_and_two_doses_count_once(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'sex' => 'Female',
            'birthday' => now()->subMonths(24)->toDateString(),
        ]);

        $childNutritionId = DB::table('child_nutrition')->insertGetId([
            'resident_id' => $resident->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nutrition_supplementation')->insert([
            [
                'child_nutrition_id' => $childNutritionId,
                'supplement_type' => 'Vitamin A',
                'age_group' => '12-59 Months',
                'dose_number' => 1,
                'date_given' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'child_nutrition_id' => $childNutritionId,
                'supplement_type' => 'Vitamin A',
                'age_group' => '12-59 Months',
                'dose_number' => 2,
                'date_given' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $row = collect(HealthRecordsVitaminA::monitoringRows())->firstWhere('key', '12-59');
        $this->assertSame('1', $row['va_200k_female']);
        $this->assertSame('1', $row['va_200k_total']);
    }

    public function test_60_71_current_age_credits_compatible_12_59_vitamin_a(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'sex' => 'Male',
            'birthday' => now()->subMonths(65)->toDateString(),
        ]);

        $childNutritionId = DB::table('child_nutrition')->insertGetId([
            'resident_id' => $resident->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nutrition_supplementation')->insert([
            'child_nutrition_id' => $childNutritionId,
            'supplement_type' => 'Vitamin A',
            'age_group' => '12-59 Months',
            'dose_number' => 1,
            'date_given' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = collect(HealthRecordsVitaminA::monitoringRows())->firstWhere('key', '60-71');
        $this->assertSame('1', $row['target']);
        $this->assertSame('1', $row['va_200k_male']);
    }

    public function test_erd_resident_isolation_and_zone_filter(): void
    {
        $zone1 = Household::factory()->create(['zone' => 'Zone 1']);
        $zone2 = Household::factory()->create(['zone' => 'Zone 2']);
        $a = Resident::factory()->create([
            'household_id' => $zone1->id,
            'sex' => 'Female',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);
        $b = Resident::factory()->create([
            'household_id' => $zone2->id,
            'sex' => 'Male',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);

        $aNutrition = DB::table('child_nutrition')->insertGetId([
            'resident_id' => $a->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bNutrition = DB::table('child_nutrition')->insertGetId([
            'resident_id' => $b->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nutrition_supplementation')->insert([
            [
                'child_nutrition_id' => $aNutrition,
                'supplement_type' => 'Vitamin A',
                'age_group' => '6-11 Months',
                'dose_number' => 1,
                'date_given' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'child_nutrition_id' => $bNutrition,
                'supplement_type' => 'Vitamin A',
                'age_group' => '6-11 Months',
                'dose_number' => 1,
                'date_given' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $z1 = collect(HealthRecordsVitaminA::monitoringRows('Zone 1'))->firstWhere('key', '6-11');
        $this->assertSame('1', $z1['target']);
        $this->assertSame('1', $z1['va_100k_female']);
        $this->assertSame('0', $z1['va_100k_male']);
    }
}
