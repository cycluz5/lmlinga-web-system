<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Models\Resident;
use App\Services\SpotMappingHandoffService;
use App\Support\EnvironmentalSanitationErdMode;
use App\Support\Offline\OfflineOperationType;
use App\Support\UserManagementErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClientTestingErdSchema;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

class OfflineSyncPlotThenEnvironmentalHealthTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ClientTestingErdSchema::ensure();
        Household::resetResolvedKeyName();
        Resident::resetResolvedKeyName();
        EnvironmentalSanitationErdMode::resetCachedState();
        UserManagementErdMode::resetCachedState();
    }

    public function test_environmental_step_before_plot_does_not_create_an_orphan(): void
    {
        $this->actingAsErdFieldStaff();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
            $this->ehStep1Payload('654'),
            ['parent_server' => ['household_no' => '654']],
        ));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'TARGET_MISSING');
        $this->assertSame(0, Household::query()->count());
        $this->assertSame(0, DB::table('environmental_sanitation')->count());
    }

    public function test_plot_then_environmental_steps_attach_once_to_the_same_household_no(): void
    {
        $this->actingAsErdFieldStaff();

        $plot = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::PLOT_HOUSEHOLD_WITH_HEAD,
            $this->plotHouseholdPayload([
                'household_no' => '654',
                'first_name' => 'Mae',
                'last_name' => 'Tarnate',
                'sex' => 'Female',
                'civil_status' => 'Married',
                'zone' => '1',
                'lat' => 13.372467,
                'lng' => 123.428871,
            ]),
        ));
        $plot->assertOk();
        $plot->assertJsonPath('code', 'SYNCED');
        $plot->assertJsonPath('household.household_no', '654');
        $plot->assertJsonMissingPath('handoff_token');
        $this->assertSame([], session(SpotMappingHandoffService::SESSION_KEY, []));

        $household = Household::query()->where('household_no', '654')->firstOrFail();
        $this->assertEqualsWithDelta(13.372467, (float) $household->latitude, 0.000001);
        $this->assertEqualsWithDelta(123.428871, (float) $household->longitude, 0.000001);

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
            $this->ehStep1Payload('654'),
            ['parent_server' => ['household_no' => '654']],
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
            [
                'household_no' => '654',
                '_eh_step' => 2,
            ],
            ['parent_server' => ['household_no' => '654']],
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
            [
                'household_no' => '654',
                '_eh_step' => 3,
                'toilet_type' => 'open_pit_latrine',
                'open_defecation_practiced' => 'yes',
                'shared_toilet' => 'no',
                'sewage_disposal_method' => 'off_site_collected_and_treated',
            ],
            ['parent_server' => ['household_no' => '654']],
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
            [
                'household_no' => '654',
                '_eh_step' => 4,
                'solid_waste_practices' => ['waste_segregation'],
            ],
            ['parent_server' => ['household_no' => '654']],
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $this->assertSame(1, Household::query()->count());
        $this->assertSame(1, Resident::query()->count());
        $head = Resident::query()->firstOrFail();
        $this->assertSame('Mae', $head->first_name);
        $this->assertSame('Tarnate', $head->last_name);
        $this->assertSame('Head', $head->relation);
        $this->assertSame(1, DB::table('environmental_sanitation')->count());
        $this->assertSame(
            (int) $household->getKey(),
            (int) DB::table('environmental_sanitation')->value('household_id'),
        );
    }

    public function test_plot_then_environmental_step3_syncs_when_sewage_is_omitted(): void
    {
        $this->actingAsErdFieldStaff();

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::PLOT_HOUSEHOLD_WITH_HEAD,
            $this->plotHouseholdPayload([
                'household_no' => '655',
                'first_name' => 'Mae',
                'last_name' => 'Tarnate',
                'sex' => 'Female',
                'civil_status' => 'Married',
                'zone' => '1',
                'lat' => 13.372467,
                'lng' => 123.428871,
            ]),
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $household = Household::query()->where('household_no', '655')->firstOrFail();

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
            $this->ehStep1Payload('655'),
            ['parent_server' => ['household_no' => '655']],
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
            [
                'household_no' => '655',
                '_eh_step' => 2,
            ],
            ['parent_server' => ['household_no' => '655']],
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
            [
                'household_no' => '655',
                '_eh_step' => 3,
                'toilet_type' => 'pour_flush_with_septic_tank',
                'open_defecation_practiced' => 'no',
                'shared_toilet' => 'no',
            ],
            ['parent_server' => ['household_no' => '655']],
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        if ($row !== null) {
            $this->assertNull($row->sewage_disposal_method);
            $this->assertSame('pour_flush_with_septic_tank', $row->toilet_type);
            $this->assertSame(0, (int) $row->shared_toilet);
        }
    }

    public function test_plot_head_then_second_member_create_two_residents_once(): void
    {
        $this->actingAsErdFieldStaff();

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::PLOT_HOUSEHOLD_WITH_HEAD,
            $this->plotHouseholdPayload([
                'household_no' => '534',
                'first_name' => 'Harem',
                'last_name' => 'Scarem',
                'sex' => 'Male',
                'civil_status' => 'Married',
                'zone' => '5',
                'date_registered' => '2026-09-08',
            ]),
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $household = Household::query()->where('household_no', '534')->firstOrFail();
        $this->assertSame(1, Resident::query()->count());

        $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::RESIDENT_CREATE,
            $this->residentMemberPayload([
                'first_name' => 'Ada',
                'last_name' => 'Scarem',
                'relation' => 'Spouse',
            ]),
            ['parent_server' => ['household_no' => '534']],
        ))->assertOk()->assertJsonPath('code', 'SYNCED');

        $this->assertSame(1, Household::query()->count());
        $this->assertSame(2, Resident::query()->count());
        $head = Resident::query()->where('first_name', 'Harem')->where('last_name', 'Scarem')->firstOrFail();
        $this->assertSame('Head', $head->relation);
        $spouse = Resident::query()->where('first_name', 'Ada')->where('last_name', 'Scarem')->firstOrFail();
        $this->assertSame('Spouse', $spouse->relation);
        $this->assertSame(1, Resident::query()->where('first_name', 'Harem')->where('last_name', 'Scarem')->count());
        $this->assertSame(1, Resident::query()->where('first_name', 'Ada')->where('last_name', 'Scarem')->count());
        $this->assertSame(2, Resident::query()->where('household_id', $household->getKey())->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function ehStep1Payload(string $householdNo): array
    {
        return [
            'household_no' => $householdNo,
            'water_supply_status' => 'level_i',
            'specify_water_source' => null,
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
            '_eh_step' => 1,
        ];
    }
}
