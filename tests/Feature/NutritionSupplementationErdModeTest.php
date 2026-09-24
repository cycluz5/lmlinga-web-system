<?php

namespace Tests\Feature;

use App\Support\ClientTestingProvisioner;
use App\Support\NutritionSupplementationErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class NutritionSupplementationErdModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_physical_erd_values_are_defined_for_vitamin_a_scenarios(): void
    {
        $this->assertSame('Vitamin A', NutritionSupplementationErdMode::vitaminASupplementType());
        $this->assertSame('6-11 Months', NutritionSupplementationErdMode::ageGroup6to11Months());
        $this->assertSame('12-59 Months', NutritionSupplementationErdMode::ageGroup12to59Months());
    }

    public function test_provisioner_payload_uses_authoritative_physical_values(): void
    {
        ClientTestingErdSchema::ensure();

        $payload611 = ClientTestingProvisioner::vitaminASupplementationPayload('6-11');
        $payload1259 = ClientTestingProvisioner::vitaminASupplementationPayload('12-59');

        $this->assertSame('Vitamin A', $payload611['supplement_type']);
        $this->assertSame('6-11 Months', $payload611['age_group']);
        $this->assertSame(1, $payload611['dose_number']);

        $this->assertSame('Vitamin A', $payload1259['supplement_type']);
        $this->assertSame('12-59 Months', $payload1259['age_group']);
        $this->assertSame(1, $payload1259['dose_number']);
        $this->assertFalse(ClientTestingProvisioner::wouldWriteInvalidNutritionSupplementation());
    }

    public function test_normalize_maps_legacy_display_labels_to_physical_values(): void
    {
        $this->assertSame(
            'Vitamin A',
            NutritionSupplementationErdMode::normalizeSupplementType('Vitamin A 100,000 IU')
        );
        $this->assertSame(
            '6-11 Months',
            NutritionSupplementationErdMode::normalizeAgeGroup('6-11 mos')
        );
        $this->assertSame(
            '12-59 Months',
            NutritionSupplementationErdMode::normalizeAgeGroup('12-59 mos')
        );
    }

    public function test_provision_persists_physical_values_and_remains_idempotent(): void
    {
        ClientTestingErdSchema::ensure();

        $provisioner = new ClientTestingProvisioner;
        $provisioner->provision();

        $rows = DB::table('nutrition_supplementation')->orderBy('supplementation_id')->get();
        $this->assertGreaterThanOrEqual(2, $rows->count());

        foreach ($rows as $row) {
            $this->assertSame('Vitamin A', $row->supplement_type);
            $this->assertContains($row->age_group, ['6-11 Months', '12-59 Months']);
            $this->assertSame(1, (int) $row->dose_number);
        }

        $countAfterFirst = $rows->count();
        $provisioner->provision();
        $this->assertSame($countAfterFirst, DB::table('nutrition_supplementation')->count());
    }

    public function test_legacy_display_labels_normalize_to_authoritative_physical_values(): void
    {
        $legacyPayload = [
            'supplement_type' => 'Vitamin A 100,000 IU',
            'age_group' => '6-11 mos',
            'dose_number' => 1,
            'date_given' => now()->toDateString(),
        ];

        $this->assertFalse(in_array($legacyPayload['supplement_type'], ['Vitamin A', 'MNP', 'LNS-SQ'], true));
        $this->assertFalse(in_array($legacyPayload['age_group'], ['6-11 Months', '12-23 Months', '12-59 Months'], true));

        $normalized = NutritionSupplementationErdMode::normalizePayload($legacyPayload);
        $this->assertSame('Vitamin A', $normalized['supplement_type']);
        $this->assertSame('6-11 Months', $normalized['age_group']);
        $this->assertTrue(NutritionSupplementationErdMode::isWritablePayloadValid($normalized));
    }
}
