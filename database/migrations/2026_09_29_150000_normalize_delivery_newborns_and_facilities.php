<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3NF — delivery outcomes.
 *
 * - newborn_sex / birth_weight_kg described one baby per delivery, so twins and
 *   multiples could not be recorded → one row per baby in delivery_newborns.
 *   plurality and plurality_number are then the number of newborn rows → dropped.
 * - bemonc_cemonc_capable describes the facility, not the delivery → moved with
 *   facility_name to health_facilities; deliveries reference it by facility_id.
 *   place_of_delivery stays on the delivery (home births have no facility).
 *
 * down() restores the columns from the new tables (baby 1 fills newborn_sex /
 * birth_weight_kg).
 */
return new class extends Migration
{
    private const DROPPED = [
        'birth_weight_kg',
        'newborn_sex',
        'plurality',
        'plurality_number',
        'facility_name',
        'bemonc_cemonc_capable',
    ];

    private const FACILITY_FOREIGN_KEY = 'fk_delivery_facility';

    public function up(): void
    {
        if (! Schema::hasTable('delivery_outcomes')) {
            return;
        }

        if (! Schema::hasTable('health_facilities')) {
            Schema::create('health_facilities', function (Blueprint $table) {
                $table->id('facility_id');
                $table->string('facility_name', 160)->unique('uq_health_facility_name');
                $table->boolean('bemonc_cemonc_capable')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('delivery_newborns')) {
            Schema::create('delivery_newborns', function (Blueprint $table) {
                $table->id('newborn_id');
                $table->unsignedBigInteger('delivery_outcome_id');
                $table->unsignedTinyInteger('birth_order');
                $table->enum('sex', ['Female', 'Male'])->nullable();
                $table->decimal('birth_weight_kg', 5, 2)->nullable();
                $table->timestamps();

                $table->unique(['delivery_outcome_id', 'birth_order'], 'uq_delivery_newborn_order');
                $table->foreign('delivery_outcome_id', 'fk_newborn_delivery')
                    ->references('delivery_outcome_id')
                    ->on('delivery_outcomes')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('delivery_outcomes', 'facility_id')) {
            Schema::table('delivery_outcomes', function (Blueprint $table) {
                $table->unsignedBigInteger('facility_id')->nullable();
                $table->foreign('facility_id', self::FACILITY_FOREIGN_KEY)
                    ->references('facility_id')
                    ->on('health_facilities')
                    ->nullOnDelete();
            });
        }

        $this->backfill();

        $columns = array_values(array_filter(
            self::DROPPED,
            static fn (string $column): bool => Schema::hasColumn('delivery_outcomes', $column)
        ));

        if ($columns !== []) {
            Schema::table('delivery_outcomes', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('delivery_outcomes')) {
            return;
        }

        Schema::table('delivery_outcomes', function (Blueprint $table) {
            if (! Schema::hasColumn('delivery_outcomes', 'birth_weight_kg')) {
                $table->decimal('birth_weight_kg', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('delivery_outcomes', 'facility_name')) {
                $table->string('facility_name', 150)->nullable();
            }
            if (! Schema::hasColumn('delivery_outcomes', 'bemonc_cemonc_capable')) {
                $table->boolean('bemonc_cemonc_capable')->nullable();
            }
            if (! Schema::hasColumn('delivery_outcomes', 'newborn_sex')) {
                $table->enum('newborn_sex', ['Female', 'Male'])->nullable();
            }
            if (! Schema::hasColumn('delivery_outcomes', 'plurality')) {
                $table->enum('plurality', ['Single', 'Twins', 'Multiple'])->nullable();
            }
            if (! Schema::hasColumn('delivery_outcomes', 'plurality_number')) {
                $table->unsignedInteger('plurality_number')->nullable();
            }
        });

        if (Schema::hasTable('delivery_newborns')) {
            foreach (DB::table('delivery_newborns')->orderBy('birth_order')->get()->groupBy('delivery_outcome_id') as $deliveryId => $babies) {
                $count = $babies->count();
                $first = $babies->first();
                DB::table('delivery_outcomes')->where('delivery_outcome_id', $deliveryId)->update([
                    'newborn_sex' => $first->sex,
                    'birth_weight_kg' => $first->birth_weight_kg,
                    'plurality' => $count === 1 ? 'Single' : ($count === 2 ? 'Twins' : 'Multiple'),
                    'plurality_number' => $count >= 3 ? $count : null,
                ]);
            }
        }

        if (Schema::hasColumn('delivery_outcomes', 'facility_id') && Schema::hasTable('health_facilities')) {
            $linked = DB::table('delivery_outcomes')
                ->join('health_facilities', 'health_facilities.facility_id', '=', 'delivery_outcomes.facility_id')
                ->get(['delivery_outcomes.delivery_outcome_id', 'health_facilities.facility_name', 'health_facilities.bemonc_cemonc_capable']);

            foreach ($linked as $row) {
                DB::table('delivery_outcomes')->where('delivery_outcome_id', $row->delivery_outcome_id)->update([
                    'facility_name' => $row->facility_name,
                    'bemonc_cemonc_capable' => $row->bemonc_cemonc_capable,
                ]);
            }

            // SQLite can only drop a foreign key by its column list.
            $foreignKey = Schema::getConnection()->getDriverName() === 'sqlite'
                ? ['facility_id']
                : self::FACILITY_FOREIGN_KEY;

            Schema::table('delivery_outcomes', function (Blueprint $table) use ($foreignKey) {
                $table->dropForeign($foreignKey);
            });
            Schema::table('delivery_outcomes', function (Blueprint $table) {
                $table->dropColumn('facility_id');
            });
        }

        Schema::dropIfExists('delivery_newborns');
        Schema::dropIfExists('health_facilities');
    }

    private function backfill(): void
    {
        $has = static fn (string $column): bool => Schema::hasColumn('delivery_outcomes', $column);

        foreach (DB::table('delivery_outcomes')->orderBy('delivery_outcome_id')->get() as $row) {
            $sex = $has('newborn_sex') ? $row->newborn_sex : null;
            $weight = $has('birth_weight_kg') ? $row->birth_weight_kg : null;
            $count = $this->babyCount(
                $has('plurality') ? $row->plurality : null,
                $has('plurality_number') ? $row->plurality_number : null,
                $sex !== null || $weight !== null,
            );

            for ($order = 1; $order <= $count; $order++) {
                DB::table('delivery_newborns')->insertOrIgnore([
                    'delivery_outcome_id' => $row->delivery_outcome_id,
                    'birth_order' => $order,
                    'sex' => $order === 1 ? $sex : null,
                    'birth_weight_kg' => $order === 1 ? $weight : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $name = $has('facility_name') ? self::normalizeName($row->facility_name) : '';
            if ($name !== '') {
                $bemonc = $has('bemonc_cemonc_capable') ? $row->bemonc_cemonc_capable : null;
                DB::table('delivery_outcomes')
                    ->where('delivery_outcome_id', $row->delivery_outcome_id)
                    ->update(['facility_id' => $this->facilityId($name, $bemonc)]);
            }
        }
    }

    private function babyCount(?string $plurality, mixed $pluralityNumber, bool $hasBabyData): int
    {
        $count = match ($plurality) {
            'Single' => 1,
            'Twins' => 2,
            'Multiple' => max(3, (int) $pluralityNumber),
            default => 0,
        };

        return $count === 0 && $hasBabyData ? 1 : $count;
    }

    private function facilityId(string $name, mixed $bemonc): int
    {
        $existing = DB::table('health_facilities')
            ->whereRaw('LOWER(facility_name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing !== null) {
            // Keep the first known capability; fill it in if it was unknown.
            if ($existing->bemonc_cemonc_capable === null && $bemonc !== null) {
                DB::table('health_facilities')
                    ->where('facility_id', $existing->facility_id)
                    ->update(['bemonc_cemonc_capable' => $bemonc, 'updated_at' => now()]);
            }

            return (int) $existing->facility_id;
        }

        return (int) DB::table('health_facilities')->insertGetId([
            'facility_name' => mb_substr($name, 0, 160),
            'bemonc_cemonc_capable' => $bemonc,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'facility_id');
    }

    private static function normalizeName(mixed $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $name));
    }
};
