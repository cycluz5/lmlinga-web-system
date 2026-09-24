<?php

namespace Tests\Support;

use App\Support\ChildImmunizationErdMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHPUnit-only repair for sqlite in-memory Child Immunization tables.
 *
 * The Laravel child_immunizations migration FKs to residents.resident_id (live ERD).
 * PHPUnit sqlite residents still use id, which makes SQLite reject inserts with
 * "foreign key mismatch". Not a migration — tests only.
 */
final class LaravelChildImmunizationSqliteCompatibility
{
    public static function ensure(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        if (! Schema::hasTable('child_immunizations') || ! Schema::hasTable('residents')) {
            return;
        }

        if (Schema::hasColumn('residents', 'resident_id')) {
            return;
        }

        if (! self::headerReferencesMissingResidentIdColumn()) {
            return;
        }

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('immunization_doses');
        Schema::dropIfExists('child_immunizations');

        Schema::create('child_immunizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resident_id')
                ->unique()
                ->constrained('residents')
                ->restrictOnDelete();
            $table->json('selected_vaccine_types')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('immunization_doses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('child_immunization_id')
                ->constrained('child_immunizations')
                ->cascadeOnDelete();
            $table->string('vaccine_type', 32);
            $table->unsignedTinyInteger('dose_index');
            $table->date('date_given')->nullable();
            $table->timestamps();
            $table->unique(
                ['child_immunization_id', 'vaccine_type', 'dose_index'],
                'immunization_doses_record_vaccine_dose_unique'
            );
            $table->index('child_immunization_id');
        });

        Schema::enableForeignKeyConstraints();
        ChildImmunizationErdMode::resetCachedState();
    }

    private static function headerReferencesMissingResidentIdColumn(): bool
    {
        $row = DB::selectOne(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['child_immunizations']
        );

        $sql = (string) ($row->sql ?? '');

        return (bool) preg_match(
            '/references\s+["`]?residents["`]?\s*\(\s*["`]?resident_id["`]?\s*\)/i',
            $sql
        );
    }
}
