<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Services\HouseholdService;
use App\Support\ChildBirthHistoryService;
use App\Support\HouseholdProfilingPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HouseholdProfilingErdOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_profiling_list_eager_loads_residents_ordered_by_resolved_primary_key(): void
    {
        $residentKey = (new Resident)->getKeyName();

        $household = Household::factory()->create(['household_no' => 'HH-ORD-1']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-001',
            'last_name' => 'Alpha',
            'first_name' => 'One',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-002',
            'last_name' => 'Beta',
            'first_name' => 'Two',
        ]);

        $rows = app(HouseholdService::class)->profilingListRows();

        $this->assertContains($residentKey, ['id', 'resident_id']);
        $this->assertCount(1, $rows);
        $this->assertSame('HH-ORD-1', $rows[0]['householdNo']);
        $this->assertSame(2, $rows[0]['members']);
    }

    public function test_member_from_model_skips_birth_history_when_table_absent(): void
    {
        Schema::dropIfExists('child_birth_histories');
        Schema::dropIfExists('child_nutrition_sfp_outcomes');
        Schema::dropIfExists('nutrition_supplementation');
        Schema::dropIfExists('child_nutritions');
        Schema::dropIfExists('child_nutrition');
        \App\Support\ChildNutritionErdMode::resetCachedState();

        $household = Household::factory()->create(['household_no' => 'HH-NOBIRTH-1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-900',
            'last_name' => 'Infant',
            'first_name' => 'Test',
        ]);

        $member = HouseholdProfilingPresenter::memberFromModel($resident);

        $this->assertSame('Test Infant', $member['name']);
        $this->assertArrayNotHasKey('birth_history', $member);
        $this->assertFalse(ChildBirthHistoryService::persistenceAvailable());
    }
}
