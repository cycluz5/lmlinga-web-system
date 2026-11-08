<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Health Summary adult immunization (Pneumococcal / Flu, one dose each).
 *
 * Creates adult_immunization only when missing. Never reuses
 * immunization_doses / child_immunizations / school_immunizations.
 *
 * Live residents PK may be resident_id; Laravel PHPUnit uses id.
 * Skips when adult_immunization or adult_immunizations already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('residents')) {
            return;
        }

        if (Schema::hasTable('adult_immunization') || Schema::hasTable('adult_immunizations')) {
            return;
        }

        $residentPk = $this->residentsPrimaryKeyColumn();

        Schema::create('adult_immunization', function (Blueprint $table) use ($residentPk): void {
            $table->id('adult_immunization_id');
            $table->unsignedBigInteger('resident_id');
            $table->string('vaccine_type', 64);
            $table->date('date_given');
            $table->timestamps();

            $table->unique(['resident_id', 'vaccine_type'], 'uq_adult_imm_resident_vaccine');
            $table->index('resident_id', 'idx_adult_imm_resident');
            $table->foreign('resident_id', 'fk_adult_imm_resident')
                ->references($residentPk)
                ->on('residents')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adult_immunization');
    }

    private function residentsPrimaryKeyColumn(): string
    {
        if (! Schema::hasTable('residents')) {
            return 'id';
        }

        try {
            foreach (Schema::getIndexes('residents') as $index) {
                if (! ($index['primary'] ?? false)) {
                    continue;
                }
                $columns = $index['columns'] ?? [];
                if (count($columns) === 1 && is_string($columns[0]) && $columns[0] !== '') {
                    return $columns[0];
                }
            }
        } catch (\Throwable) {
            // Fall through to column-shape detection.
        }

        if (Schema::hasColumn('residents', 'resident_id') && ! Schema::hasColumn('residents', 'id')) {
            return 'resident_id';
        }

        return Schema::hasColumn('residents', 'id') ? 'id' : 'resident_id';
    }
};
