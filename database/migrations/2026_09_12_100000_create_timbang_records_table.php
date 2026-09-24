<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-12 — Additive paper ERD timbang_records (measurement events).
 *
 * Live residents PK is resident_id (bigint unsigned). Laravel tests use id.
 * unregistered_children does not exist live (same as live deworming_records),
 * so that paper FK/column is omitted — not invented.
 *
 * Not a drop/rename. Skips when the table already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('timbang_records')) {
            return;
        }

        $residentPk = $this->residentsPrimaryKeyColumn();

        Schema::create('timbang_records', function (Blueprint $table) use ($residentPk): void {
            $table->id('timbang_id');
            $table->unsignedBigInteger('resident_id')->nullable();
            $table->date('measurement_date');
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_cm', 5, 2)->nullable();
            $table->decimal('muac_cm', 4, 1)->nullable();
            $table->enum('weight_for_age', [
                'Severely Underweight',
                'Underweight',
                'Normal',
            ])->nullable();
            $table->enum('height_for_age', [
                'Severely Stunted',
                'Stunted',
                'Normal',
            ])->nullable();
            $table->string('weight_for_height', 50)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->foreign('resident_id', 'fk_timbang_resident')
                ->references($residentPk)
                ->on('residents')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timbang_records');
    }

    /**
     * Live ERD PK is resident_id. Laravel PHPUnit residents PK is id.
     * Do not treat a non-PK resident_id column as the FK target (SQLite mismatch).
     */
    private function residentsPrimaryKeyColumn(): string
    {
        if (! Schema::hasTable('residents')) {
            return Schema::hasColumn('residents', 'resident_id') ? 'resident_id' : 'id';
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
