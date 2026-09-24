<?php

namespace Tests\Feature;

use App\Support\ChildNutritionErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ErdChildNutritionSchema;
use Tests\TestCase;

class ChildNutritionErdModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_mode_uses_child_nutritions_table(): void
    {
        ChildNutritionErdMode::resetCachedState();

        $this->assertTrue(Schema::hasTable('child_nutritions'));
        $this->assertFalse(ChildNutritionErdMode::isActive());
        $this->assertSame('child_nutritions', ChildNutritionErdMode::nutritionTable());
        $this->assertSame('id', ChildNutritionErdMode::nutritionPrimaryKey());
        $this->assertTrue(ChildNutritionErdMode::isWriteSupported());
        $this->assertFalse(ChildNutritionErdMode::erdWriteSchemaCompatible());
    }

    public function test_erd_mode_uses_child_nutrition_and_supplementation(): void
    {
        ErdChildNutritionSchema::ensure();

        $this->assertTrue(Schema::hasTable('child_nutrition'));
        $this->assertFalse(Schema::hasTable('child_nutritions'));
        $this->assertTrue(ChildNutritionErdMode::isActive());
        $this->assertSame('child_nutrition', ChildNutritionErdMode::nutritionTable());
        $this->assertSame('child_nutrition_id', ChildNutritionErdMode::nutritionPrimaryKey());
        $this->assertTrue(ChildNutritionErdMode::usesSupplementationTable());
        $this->assertTrue(ChildNutritionErdMode::erdWriteSchemaCompatible());
        $this->assertTrue(ChildNutritionErdMode::isWriteSupported());
    }

    public function test_incomplete_erd_schema_is_not_write_supported(): void
    {
        ErdChildNutritionSchema::ensure();
        Schema::dropIfExists('malnutrition_management');
        ChildNutritionErdMode::resetCachedState();

        $this->assertTrue(ChildNutritionErdMode::isActive());
        $this->assertFalse(ChildNutritionErdMode::erdWriteSchemaCompatible());
        $this->assertFalse(ChildNutritionErdMode::isWriteSupported());
    }
}
