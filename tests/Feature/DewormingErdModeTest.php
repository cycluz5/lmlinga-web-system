<?php

namespace Tests\Feature;

use App\Support\DewormingErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class DewormingErdModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_mode_uses_round_column(): void
    {
        DewormingErdMode::resetCachedState();

        $this->assertTrue(Schema::hasTable('deworming_records'));
        $this->assertTrue(Schema::hasColumn('deworming_records', 'round'));
        $this->assertFalse(DewormingErdMode::isActive());
        $this->assertSame('id', DewormingErdMode::primaryKey());
        $this->assertSame('round', DewormingErdMode::roundColumn());
    }

    public function test_erd_mode_uses_deworming_round_column(): void
    {
        ClientTestingErdSchema::ensure();

        $this->assertTrue(Schema::hasColumn('deworming_records', 'deworming_round'));
        $this->assertTrue(DewormingErdMode::isActive());
        $this->assertSame('deworming_id', DewormingErdMode::primaryKey());
        $this->assertSame('deworming_round', DewormingErdMode::roundColumn());
    }
}
