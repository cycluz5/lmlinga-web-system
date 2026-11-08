<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3NF — Child Immunization header.
 *
 * - child_immunizations.selected_vaccine_types stored a JSON list (1NF) of manual
 *   "Vaccines Type" checkboxes. The UI no longer posts them; the checklist is shown
 *   from immunization_doses, so the column is dropped instead of moved to a table.
 * - fic_cic_status stored FIC/CIC flags that are derived from immunization_doses
 *   (ChildImmunizationService::ficCompleted / cicCompleted), so the table is dropped.
 * - One immunization card per child: enforce UNIQUE(resident_id).
 *
 * down() restores the structure only; dropped values cannot be recovered.
 */
return new class extends Migration
{
    private const HEADER = 'child_immunizations';

    private const RESIDENT_UNIQUE = 'uq_child_immunizations_resident';

    public function up(): void
    {
        if (Schema::hasTable(self::HEADER)) {
            $this->assertOneCardPerResident();

            if (! Schema::hasIndex(self::HEADER, ['resident_id'], 'unique')) {
                Schema::table(self::HEADER, function (Blueprint $table) {
                    $table->unique('resident_id', self::RESIDENT_UNIQUE);
                });
            }

            if (Schema::hasColumn(self::HEADER, 'selected_vaccine_types')) {
                Schema::table(self::HEADER, function (Blueprint $table) {
                    $table->dropColumn('selected_vaccine_types');
                });
            }
        }

        Schema::dropIfExists('fic_cic_status');
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::HEADER)) {
            return;
        }

        if (! Schema::hasColumn(self::HEADER, 'selected_vaccine_types')) {
            Schema::table(self::HEADER, function (Blueprint $table) {
                $table->json('selected_vaccine_types')->nullable()->after('resident_id');
            });
        }

        if (Schema::hasIndex(self::HEADER, self::RESIDENT_UNIQUE)) {
            Schema::table(self::HEADER, function (Blueprint $table) {
                $table->dropUnique(self::RESIDENT_UNIQUE);
            });
        }

        if (! Schema::hasTable('fic_cic_status')) {
            Schema::create('fic_cic_status', function (Blueprint $table) {
                $table->id('fic_cic_id');
                $table->foreignId('child_immunization_id')
                    ->unique('uq_ficcic_childimm')
                    ->constrained(self::HEADER)
                    ->cascadeOnDelete();
                $table->boolean('fic_completed')->default(false);
                $table->boolean('cic_completed')->default(false);
                $table->timestamps();
            });
        }
    }

    /**
     * Refuse to guess which duplicate card to keep; staff must merge them first.
     */
    private function assertOneCardPerResident(): void
    {
        $duplicates = DB::table(self::HEADER)
            ->select('resident_id')
            ->groupBy('resident_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('resident_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'child_immunizations has more than one card for resident_id(s) '
                .$duplicates->implode(', ')
                .'. Merge the duplicate cards before running this migration.'
            );
        }
    }
};
