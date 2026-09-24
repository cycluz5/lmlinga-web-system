<?php

namespace Tests\Unit;

use App\Support\AtRestColumns;
use App\Support\AtRestEncryptionException;
use App\Support\AtRestNarrativeField;
use App\Support\AtRestRecord;
use App\Support\RiskAssessmentErdMode;
use Tests\TestCase;

class AtRestRecordTest extends TestCase
{
    public function test_flags_round_trip_as_zero_or_one(): void
    {
        foreach ([true, 1, '1'] as $truthy) {
            $sealed = AtRestRecord::seal($truthy, 'family_history', 'stroke');
            $this->assertTrue(AtRestNarrativeField::isSealed($sealed));
            $this->assertSame(1, AtRestRecord::open($sealed, 'family_history', 'stroke'));
        }

        foreach ([false, 0, '0', null] as $falsy) {
            $sealed = AtRestRecord::seal($falsy, 'family_history', 'stroke');
            $this->assertTrue(AtRestNarrativeField::isSealed($sealed));
            $this->assertSame(0, AtRestRecord::open($sealed, 'family_history', 'stroke'));
        }
    }

    public function test_numbers_keep_their_column_shape(): void
    {
        $height = AtRestRecord::seal('170.5', 'risk_assessment', 'height_cm');
        $this->assertSame('170.50', AtRestRecord::open($height, 'risk_assessment', 'height_cm'));

        $systolic = AtRestRecord::seal('142', 'risk_assessment', 'systolic_blood_pressure');
        $this->assertSame(142, AtRestRecord::open($systolic, 'risk_assessment', 'systolic_blood_pressure'));

        $this->assertNull(AtRestRecord::seal('', 'risk_assessment', 'height_cm'));
        $this->assertNull(AtRestRecord::seal(null, 'risk_assessment', 'systolic_blood_pressure'));
    }

    public function test_text_round_trips_and_empty_becomes_null(): void
    {
        $sealed = AtRestRecord::seal('Cardiorespiratory Arrest', 'death_records', 'cause_of_death');
        $this->assertStringNotContainsString('Cardiorespiratory', (string) $sealed);
        $this->assertSame('Cardiorespiratory Arrest', AtRestRecord::open($sealed, 'death_records', 'cause_of_death'));

        $this->assertNull(AtRestRecord::seal('   ', 'hiv_screening', 'result'));
    }

    public function test_legacy_plaintext_is_read_with_the_right_type(): void
    {
        $this->assertSame(1, AtRestRecord::open(1, 'medical_history', 'hypertension'));
        $this->assertSame(0, AtRestRecord::open('0', 'medical_history', 'hypertension'));
        $this->assertSame(120, AtRestRecord::open('120', 'risk_assessment', 'systolic_blood_pressure'));
        $this->assertSame('Never', AtRestRecord::open('Never', 'risk_assessment', 'tobacco_vape_usage'));
    }

    public function test_ciphertext_moved_to_another_column_does_not_open(): void
    {
        $sealed = AtRestRecord::seal(true, 'family_history', 'cancer');

        $this->assertSame(0, AtRestRecord::open($sealed, 'family_history', 'stroke'));
        $this->assertSame(
            AtRestNarrativeField::DISPLAY_UNAVAILABLE,
            AtRestRecord::open(AtRestRecord::seal('REACTIVE', 'hiv_screening', 'result'), 'syphilis_screening', 'result')
        );
    }

    public function test_sealing_is_idempotent(): void
    {
        $sealed = AtRestRecord::seal('Never', 'risk_assessment', 'tobacco_vape_usage');

        $this->assertSame($sealed, AtRestRecord::seal($sealed, 'risk_assessment', 'tobacco_vape_usage'));
    }

    public function test_unregistered_column_is_rejected(): void
    {
        $this->expectException(AtRestEncryptionException::class);

        AtRestRecord::seal('x', 'risk_assessment', 'resident_id');
    }

    public function test_seal_row_and_open_row_only_touch_registered_columns(): void
    {
        $sealed = AtRestRecord::sealRow('risk_assessment', [
            'resident_id' => 7,
            'alcohol_intake' => 'Excessive',
            'weight_kg' => '70',
        ]);

        $this->assertSame(7, $sealed['resident_id']);
        $this->assertTrue(AtRestNarrativeField::isSealed($sealed['alcohol_intake']));
        $this->assertTrue(AtRestNarrativeField::isSealed($sealed['weight_kg']));

        $opened = AtRestRecord::openRow('risk_assessment', (object) $sealed);
        $this->assertSame(7, $opened->resident_id);
        $this->assertSame('Excessive', $opened->alcohol_intake);
        $this->assertSame('70.00', $opened->weight_kg);
        $this->assertNull(AtRestRecord::openRow('risk_assessment', null));
    }

    public function test_every_registered_column_has_a_type_and_original_definition(): void
    {
        foreach (AtRestColumns::tables() as $table) {
            foreach (AtRestColumns::columns($table) as $column) {
                $this->assertNotNull(AtRestColumns::type($table, $column), "{$table}.{$column}");
                $this->assertNotNull(AtRestColumns::originalDefinition($table, $column), "{$table}.{$column}");
            }
        }
    }

    public function test_blood_pressure_status_matches_former_mysql_generated_column(): void
    {
        $cases = [
            [null, 80, null],
            [119, 79, 'Normal'],
            [120, 79, 'Elevated'],
            [129, 79, 'Elevated'],
            [130, 70, 'Hypertension Stage 1'],
            [110, 80, 'Hypertension Stage 1'],
            [139, 89, 'Hypertension Stage 1'],
            [140, 70, 'Hypertension Stage 2'],
            [110, 90, 'Hypertension Stage 2'],
            [180, 120, 'Hypertension Stage 2'],
            [181, 80, 'Hypertensive Crisis'],
            [120, 121, 'Hypertensive Crisis'],
            [60, 120, 'Hypertension Stage 2'],
        ];

        foreach ($cases as [$systolic, $diastolic, $expected]) {
            $this->assertSame(
                $expected,
                RiskAssessmentErdMode::bloodPressureStatusLabel($systolic, $diastolic),
                "{$systolic}/{$diastolic}"
            );
        }
    }
}
