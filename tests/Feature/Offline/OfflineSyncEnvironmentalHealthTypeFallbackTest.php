<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Models\HouseholdEnvironmentalProfile;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\Offline\OfflineOperationType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class OfflineSyncEnvironmentalHealthTypeFallbackTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    public function test_offline_plot_then_step1_copies_household_row_type_onto_laravel_profile(): void
    {
        $this->prepareLaravelPlotSchema();
        $this->actingAsFieldStaff();

        $this->assertTrue(Schema::hasTable('household_environmental_profiles'));
        $this->assertTrue(Schema::hasColumn('households', 'household_type'));

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::PLOT_HOUSEHOLD_WITH_HEAD,
            $this->plotHouseholdPayload([
                'household_no' => '654',
                'household_type' => 'HHTS',
            ]),
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $household = Household::query()->where('household_no', '654')->firstOrFail();
        $this->assertSame(
            DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
            (string) $household->household_type,
        );
        $this->assertDatabaseMissing('household_environmental_profiles', [
            'household_id' => $household->getKey(),
        ]);

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
            [
                'household_no' => '654',
                'water_supply_status' => 'level_i',
                'specify_water_source' => null,
                'water_source_location' => 'yes',
                'water_availability' => 'yes',
                '_eh_step' => 1,
            ],
            ['parent_server' => ['household_no' => '654']],
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $this->assertSame(1, HouseholdEnvironmentalProfile::query()->where('household_id', $household->getKey())->count());
        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->getKey(),
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
            'water_supply_status' => 'level_i',
            'completed_step' => 1,
        ]);
    }

    /**
     * Laravel create_households / create_residents require columns that Spot Mapping
     * plot payloads omit. Relax those constraints in this test only so plot can
     * persist households.household_type on the Laravel profile schema.
     */
    private function prepareLaravelPlotSchema(): void
    {
        if (! Schema::hasColumn('households', 'household_type')) {
            Schema::table('households', function (Blueprint $table): void {
                $table->string('household_type', 50)->nullable();
            });
        }

        Schema::table('households', function (Blueprint $table): void {
            $table->string('street', 150)->nullable()->change();
        });

        Schema::table('residents', function (Blueprint $table): void {
            $table->string('occupation', 100)->nullable()->change();
            $table->string('monthly_income', 50)->nullable()->change();
            $table->string('religion', 100)->nullable()->change();
            $table->string('education', 100)->nullable()->change();
            $table->string('fp_user', 8)->nullable()->change();
        });
    }
}
