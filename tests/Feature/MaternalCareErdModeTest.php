<?php

namespace Tests\Feature;

use App\Support\ClientTestingProvisioner;
use App\Support\MaternalCareErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class MaternalCareErdModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_maternal_care_table_bmi_is_not_treated_as_generated(): void
    {
        MaternalCareErdMode::resetCachedState();

        $this->assertFalse(Schema::hasTable('maternal_care'));
        $this->assertFalse(MaternalCareErdMode::isBmiGenerated());
        $this->assertSame(
            ['bmi' => 21.5],
            MaternalCareErdMode::filterWritablePayload(['bmi' => 21.5])
        );
    }

    public function test_erd_fixture_detects_generated_bmi_and_strips_it_from_payload(): void
    {
        ClientTestingErdSchema::ensure();

        $this->assertTrue(MaternalCareErdMode::isBmiGenerated());
        $this->assertFalse(ClientTestingProvisioner::wouldWriteMaternalCareBmi());

        $payload = ClientTestingProvisioner::maternalCareInsertPayload();
        $this->assertArrayNotHasKey('bmi', $payload);
        $this->assertSame(55.0, $payload['weight_kg']);
        $this->assertSame(160.0, $payload['height_cm']);
    }

    public function test_provision_persists_source_columns_and_database_derives_bmi(): void
    {
        ClientTestingErdSchema::ensure();

        (new ClientTestingProvisioner)->provision();

        $row = DB::table('maternal_care')->first();
        $this->assertNotNull($row);
        $this->assertSame(55.0, (float) $row->weight_kg);
        $this->assertSame(160.0, (float) $row->height_cm);
        $this->assertEqualsWithDelta(21.5, (float) $row->bmi, 0.1);
    }

    public function test_reprovision_remains_idempotent_for_maternal_care(): void
    {
        ClientTestingErdSchema::ensure();

        $provisioner = new ClientTestingProvisioner;
        $provisioner->provision();
        $firstCount = DB::table('maternal_care')->count();

        $provisioner->provision();
        $secondCount = DB::table('maternal_care')->count();

        $this->assertSame($firstCount, $secondCount);
        $this->assertGreaterThanOrEqual(1, $secondCount);
    }
}
