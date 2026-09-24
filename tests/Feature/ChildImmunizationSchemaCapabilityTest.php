<?php

namespace Tests\Feature;

use App\Support\ChildImmunizationErdMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ErdChildImmunizationSchema;
use Tests\Support\HybridChildImmunizationSchema;
use Tests\TestCase;

/**
 * Header ERD mode vs immunization_doses / fic_cic_status capability detection.
 */
class ChildImmunizationSchemaCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_plural_laravel_schema_uses_dose_index_and_id(): void
    {
        $this->assertFalse(ChildImmunizationErdMode::isActive());
        $this->assertSame('child_immunizations', ChildImmunizationErdMode::headerTable());
        $this->assertSame('id', ChildImmunizationErdMode::headerPrimaryKey());
        $this->assertFalse(ChildImmunizationErdMode::usesDoseNumberSchema());
        $this->assertFalse(ChildImmunizationErdMode::usesDoseIdPrimaryKey());
        $this->assertSame('dose_index', ChildImmunizationErdMode::doseSequenceColumn());
        $this->assertSame('id', ChildImmunizationErdMode::dosePrimaryKey());
        $this->assertFalse(ChildImmunizationErdMode::usesFicCicStatusTable());
        $this->assertFalse(ChildImmunizationErdMode::usesErdVaccineTypeLabels());
        $this->assertTrue(Schema::hasColumn('immunization_doses', 'dose_index'));
        $this->assertFalse(Schema::hasColumn('immunization_doses', 'dose_number'));
    }

    public function test_singular_erd_header_does_not_force_dose_schema_from_header_mode_alone(): void
    {
        ErdChildImmunizationSchema::ensure();

        $this->assertTrue(ChildImmunizationErdMode::isActive());
        $this->assertSame('child_immunization', ChildImmunizationErdMode::headerTable());
        $this->assertSame('child_immunization_id', ChildImmunizationErdMode::headerPrimaryKey());
        $this->assertTrue(ChildImmunizationErdMode::usesDoseNumberSchema());
        $this->assertTrue(ChildImmunizationErdMode::usesDoseIdPrimaryKey());
        $this->assertSame('dose_number', ChildImmunizationErdMode::doseSequenceColumn());
        $this->assertSame('dose_id', ChildImmunizationErdMode::dosePrimaryKey());
        $this->assertTrue(ChildImmunizationErdMode::usesFicCicStatusTable());
    }

    public function test_hybrid_plural_header_detects_dose_number_and_fic_cic(): void
    {
        HybridChildImmunizationSchema::ensure();

        $this->assertFalse(ChildImmunizationErdMode::isActive());
        $this->assertSame('child_immunizations', ChildImmunizationErdMode::headerTable());
        $this->assertSame('dose_number', ChildImmunizationErdMode::doseSequenceColumn());
        $this->assertSame('dose_id', ChildImmunizationErdMode::dosePrimaryKey());
        $this->assertTrue(ChildImmunizationErdMode::usesFicCicStatusTable());
    }

    public function test_dose_number_is_chosen_whenever_that_column_exists(): void
    {
        Schema::table('immunization_doses', function (Blueprint $table): void {
            $table->unsignedTinyInteger('dose_number')->nullable();
        });
        ChildImmunizationErdMode::resetCachedState();

        $this->assertTrue(Schema::hasColumn('immunization_doses', 'dose_number'));
        $this->assertTrue(Schema::hasColumn('immunization_doses', 'dose_index'));
        $this->assertFalse(ChildImmunizationErdMode::usesDoseNumberSchema());
        $this->assertSame('dose_number', ChildImmunizationErdMode::doseSequenceColumn());
    }

    public function test_ui_index_converts_to_one_based_dose_number(): void
    {
        $this->assertSame(1, ChildImmunizationErdMode::doseNumberFromIndex(0));
        $this->assertSame(2, ChildImmunizationErdMode::doseNumberFromIndex(1));
        $this->assertSame(0, ChildImmunizationErdMode::indexFromDoseNumber(1));
        $this->assertSame(1, ChildImmunizationErdMode::indexFromDoseNumber(2));
    }

    public function test_vaccine_ui_key_maps_to_erd_enum_label(): void
    {
        $this->assertSame('BCG', ChildImmunizationErdMode::storedVaccineTypeFromUiKey('bcg'));
        $this->assertSame('bcg', ChildImmunizationErdMode::uiKeyFromStoredVaccineType('BCG'));
        $this->assertSame('Hepa B', ChildImmunizationErdMode::storedVaccineTypeFromUiKey('hepa-b'));
        $this->assertSame('DPT-HIB-HepB', ChildImmunizationErdMode::storedVaccineTypeFromUiKey('dpt-hib-hepb'));
    }

    public function test_dose_match_attributes_follow_live_hybrid_columns(): void
    {
        HybridChildImmunizationSchema::ensure();

        $this->assertSame(
            [
                'child_immunization_id' => 41,
                'vaccine_type' => 'BCG',
                'dose_number' => 1,
            ],
            ChildImmunizationErdMode::doseMatchAttributes(41, 'bcg', 0)
        );
        $this->assertSame(
            [
                'child_immunization_id' => 41,
                'vaccine_type' => 'BCG',
                'dose_number' => 2,
            ],
            ChildImmunizationErdMode::doseMatchAttributes(41, 'bcg', 1)
        );
    }

    public function test_dose_match_attributes_follow_laravel_dose_index(): void
    {
        $this->assertSame(
            [
                'child_immunization_id' => 41,
                'vaccine_type' => 'bcg',
                'dose_index' => 0,
            ],
            ChildImmunizationErdMode::doseMatchAttributes(41, 'bcg', 0)
        );
    }
}
