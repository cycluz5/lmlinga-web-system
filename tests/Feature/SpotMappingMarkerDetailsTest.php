<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Services\SpotMappingService;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SpotMappingMarkerDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsStaff(StaffRole::BHW);
    }

    public function test_plot_new_household_panel_labels_household_head_above_name_fields(): void
    {
        $html = $this->get(route('spot-mapping.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Household Head', $html);
        $headPos = strpos($html, 'id="lml-spot-map-head-heading"');
        $firstPos = strpos($html, 'id="lml-spot-map-head-first"');
        $middlePos = strpos($html, 'id="lml-spot-map-head-middle"');
        $lastPos = strpos($html, 'id="lml-spot-map-head-last"');

        $this->assertNotFalse($headPos);
        $this->assertNotFalse($firstPos);
        $this->assertNotFalse($middlePos);
        $this->assertNotFalse($lastPos);
        $this->assertLessThan($firstPos, $headPos);
        $this->assertLessThan($middlePos, $firstPos);
        $this->assertLessThan($lastPos, $middlePos);
        $this->assertStringContainsString('id="lml-spot-map-zone-label"', $html);
        $this->assertStringNotContainsString('data-field="purok"', $html);
        $this->assertStringNotContainsString('<dt>Purok</dt>', $html);
    }

    public function test_existing_plotted_marker_payload_includes_saved_head_and_type(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-002',
            'zone' => 'Zone 2',
            'latitude' => 13.3811,
            'longitude' => 123.4306,
        ]);
        if (Schema::hasColumn('households', 'household_type')) {
            DB::table('households')->where($household->getKeyName(), $household->getKey())->update([
                'household_type' => 'NHTS',
            ]);
            $household->refresh();
        }
        if (Schema::hasColumn('households', 'purok')) {
            DB::table('households')->where($household->getKeyName(), $household->getKey())->update([
                'purok' => '2',
            ]);
            $household->refresh();
        }

        Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
            'last_name' => 'Reyes',
            'relation' => 'Head',
        ]);
        Resident::factory()->count(3)->create([
            'household_id' => $household->getKey(),
            'relation' => 'Child',
        ]);

        $marker = collect(app(SpotMappingService::class)->mappedMarkers())
            ->firstWhere('householdNo', 'HH-002');

        $this->assertNotNull($marker);
        $this->assertSame('HH-002', $marker['householdNo']);
        if (Schema::hasColumn('households', 'household_type')) {
            $this->assertSame('NHTS', $marker['householdType']);
        }
        $this->assertSame('Maria', $marker['headFirstName']);
        $this->assertSame('Santos', $marker['headMiddleName']);
        $this->assertSame('Reyes', $marker['headLastName']);
        $this->assertSame(4, $marker['members']);
        $this->assertSame('Maria Santos Reyes', $marker['houseHead']);

        $html = $this->get(route('spot-mapping.index'))->assertOk()->getContent();
        $this->assertStringContainsString('"householdNo":"HH-002"', $html);
        $this->assertStringContainsString('"headFirstName":"Maria"', $html);
        $this->assertStringContainsString('"headMiddleName":"Santos"', $html);
        $this->assertStringContainsString('"headLastName":"Reyes"', $html);
    }

    public function test_zero_is_household_head_flag_still_uses_relation_to_household_head(): void
    {
        $resident = new Resident;
        $resident->setRawAttributes([
            'is_household_head' => 0,
            'relation' => '',
            'relation_to_household_head' => 'Household Head',
            'first_name' => 'Ana',
            'middle_name' => '',
            'last_name' => 'Cruz',
        ]);

        $this->assertTrue($resident->isHouseholdHead());

        $household = Household::factory()->create([
            'household_no' => 'HH-002',
            'zone' => 'Zone 2',
            'latitude' => 13.3811,
            'longitude' => 123.4306,
        ]);

        $head = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Ana',
            'middle_name' => null,
            'last_name' => 'Cruz',
            'relation' => 'Head',
        ]);
        if (Schema::hasColumn('residents', 'is_household_head')) {
            DB::table('residents')->where($head->getKeyName(), $head->getKey())->update([
                'is_household_head' => 0,
            ]);
        }
        if (Schema::hasColumn('residents', 'relation_to_household_head')) {
            DB::table('residents')->where($head->getKeyName(), $head->getKey())->update([
                'relation_to_household_head' => 'Head',
            ]);
        }

        $marker = collect(app(SpotMappingService::class)->mappedMarkers())
            ->firstWhere('householdNo', 'HH-002');

        $this->assertNotNull($marker);
        $this->assertSame('Ana', $marker['headFirstName']);
        $this->assertSame('', $marker['headMiddleName']);
        $this->assertSame('Cruz', $marker['headLastName']);
        $this->assertSame('Ana Cruz', $marker['houseHead']);
    }

    public function test_marker_does_not_invent_head_name_when_head_is_missing(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-088',
            'zone' => 'Zone 1',
            'latitude' => 13.3811,
            'longitude' => 123.4306,
        ]);
        if (Schema::hasColumn('households', 'household_type')) {
            DB::table('households')->where($household->getKeyName(), $household->getKey())->update([
                'household_type' => 'NON-NHTS',
            ]);
            $household->refresh();
        }

        Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Only',
            'middle_name' => '',
            'last_name' => 'Member',
            'relation' => 'Child',
        ]);

        $marker = collect(app(SpotMappingService::class)->mappedMarkers())
            ->firstWhere('householdNo', 'HH-088');

        $this->assertNotNull($marker);
        if (Schema::hasColumn('households', 'household_type')) {
            $this->assertSame('Non-NHTS', $marker['householdType']);
        }
        $this->assertSame('—', $marker['houseHead']);
        $this->assertSame('', $marker['headFirstName']);
        $this->assertSame('', $marker['headMiddleName']);
        $this->assertSame('', $marker['headLastName']);
        $this->assertSame(1, $marker['members']);
        $this->assertStringNotContainsString('Only Member', $marker['houseHead']);
    }

    public function test_legacy_and_three_digit_markers_expose_db_backed_details(): void
    {
        $legacy = Household::factory()->create([
            'household_no' => 'HH-001',
            'zone' => 'Zone 1',
            'latitude' => 13.3811,
            'longitude' => 123.4306,
        ]);
        $current = Household::factory()->create([
            'household_no' => '121',
            'zone' => 'Zone 2',
            'latitude' => 13.378472,
            'longitude' => 123.430925,
        ]);

        if (Schema::hasColumn('households', 'household_type')) {
            DB::table('households')->where($legacy->getKeyName(), $legacy->getKey())->update([
                'household_type' => 'NHTS',
            ]);
            DB::table('households')->where($current->getKeyName(), $current->getKey())->update([
                'household_type' => 'Non-NHTS',
            ]);
        }

        Resident::factory()->create([
            'household_id' => $legacy->getKey(),
            'first_name' => 'Legacy',
            'middle_name' => 'Mae',
            'last_name' => 'Head',
            'relation' => 'Head',
        ]);
        Resident::factory()->create([
            'household_id' => $current->getKey(),
            'first_name' => 'Doi',
            'middle_name' => null,
            'last_name' => 'Chipi',
            'relation' => 'Head',
        ]);

        $markers = collect(app(SpotMappingService::class)->mappedMarkers());
        $legacyMarker = $markers->firstWhere('householdNo', 'HH-001');
        $currentMarker = $markers->firstWhere('householdNo', '121');

        $this->assertNotNull($legacyMarker);
        $this->assertNotNull($currentMarker);

        if (Schema::hasColumn('households', 'household_type')) {
            $this->assertSame('NHTS', $legacyMarker['householdType']);
            $this->assertSame('Non-NHTS', $currentMarker['householdType']);
        }

        $this->assertSame('Legacy', $legacyMarker['headFirstName']);
        $this->assertSame('Mae', $legacyMarker['headMiddleName']);
        $this->assertSame('Head', $legacyMarker['headLastName']);
        $this->assertSame('Doi', $currentMarker['headFirstName']);
        $this->assertSame('', $currentMarker['headMiddleName']);
        $this->assertSame('Chipi', $currentMarker['headLastName']);
        $this->assertSame('Doi Chipi', $currentMarker['houseHead']);
        $this->assertSame('1', $legacyMarker['zone']);
        $this->assertSame('Zone 1', $legacyMarker['zoneLabel']);
        $this->assertSame('2', $currentMarker['zone']);
        $this->assertSame('Zone 2', $currentMarker['zoneLabel']);
        $this->assertSame(1, $legacyMarker['members']);
        $this->assertSame(1, $currentMarker['members']);
        $this->assertArrayNotHasKey('purok', $currentMarker);

        $html = $this->get(route('spot-mapping.index'))->assertOk()->getContent();
        $this->assertStringContainsString('"householdNo":"HH-001"', $html);
        $this->assertStringContainsString('"headFirstName":"Legacy"', $html);
        $this->assertStringContainsString('"householdNo":"121"', $html);
        $this->assertStringContainsString('"headFirstName":"Doi"', $html);
        $this->assertStringContainsString('"headLastName":"Chipi"', $html);
        $this->assertStringContainsString('"zoneLabel":"Zone 2"', $html);
        $this->assertStringNotContainsString('"headFirstName":"Only"', $html);

        $this->get(route('household-profiling.view', ['householdNo' => 'HH-001']))->assertOk();
        $this->get(route('household-profiling.view', ['householdNo' => '121']))
            ->assertOk()
            ->assertSee('Doi Chipi', false);
    }

    public function test_valid_head_names_are_not_replaced_with_dash_in_index_payload(): void
    {
        $household = Household::factory()->create([
            'household_no' => '999',
            'zone' => 'Zone 3',
            'latitude' => 13.38,
            'longitude' => 123.43,
        ]);
        Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Valid',
            'middle_name' => null,
            'last_name' => 'Person',
            'relation' => 'Head',
        ]);

        $html = $this->get(route('spot-mapping.index'))->assertOk()->getContent();
        $this->assertStringContainsString('"headFirstName":"Valid"', $html);
        $this->assertStringContainsString('"headLastName":"Person"', $html);
        $this->assertStringContainsString('"headMiddleName":""', $html);
        $this->assertDoesNotMatchRegularExpression('/"headFirstName":"—"/', $html);
        $this->assertDoesNotMatchRegularExpression('/"headLastName":"—"/', $html);
    }

    public function test_mapped_markers_do_not_use_demo_catalog_when_db_households_exist(): void
    {
        $this->assertNotNull(\App\Support\DemoCatalog::findHousehold('HH-151'));

        $household = Household::factory()->create([
            'household_no' => '121',
            'zone' => 'Zone 2',
            'latitude' => 13.38,
            'longitude' => 123.43,
        ]);
        Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Doi',
            'last_name' => 'Chipi',
            'relation' => 'Head',
        ]);

        $nos = array_column(app(SpotMappingService::class)->mappedMarkers(), 'householdNo');
        $this->assertSame(['121'], $nos);
        $this->assertNotContains('HH-151', $nos);
    }

    public function test_client_normalize_keeps_head_names_and_household_type(): void
    {
        $js = (string) file_get_contents(base_path('resources/js/pages/spot-mapping.js'));
        $this->assertStringContainsString('function normalizeServerMarker', $js);
        $this->assertStringContainsString('headFirstName: marker.headFirstName', $js);
        $this->assertStringContainsString('householdType: marker.householdType', $js);
        $this->assertStringContainsString('canonicalHouseholdType', $js);
        $this->assertStringNotContainsString("purok: marker.purok || (zone ? ZONE_LABELS[zone] : '—')", $js);
    }
}
