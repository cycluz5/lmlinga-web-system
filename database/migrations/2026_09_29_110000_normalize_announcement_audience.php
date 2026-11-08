<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3NF — announcement audience.
 *
 * - announcements.zones / age_presets stored JSON lists (1NF) → one row per value
 *   in announcement_zones / announcement_age_presets.
 * - zone_mode is implied by zones ("specific" exactly when zone rows exist; the
 *   form requires at least one zone for specific coverage) → dropped.
 * - audience_label is built from target_group + age presets/range → dropped.
 * - posted_by_name depends on posted_by_user_id (user_management name) → dropped.
 *   posted_by_role stays: it records the poster's role at posting time.
 *
 * down() restores the columns and the zone / age preset lists; audience_label and
 * posted_by_name come back empty (the app derives them).
 */
return new class extends Migration
{
    private const DROPPED = ['age_presets', 'zones', 'zone_mode', 'audience_label', 'posted_by_name'];

    public function up(): void
    {
        if (! Schema::hasTable('announcements')) {
            return;
        }

        if (! Schema::hasTable('announcement_zones')) {
            Schema::create('announcement_zones', function (Blueprint $table) {
                $table->id('announcement_zone_id');
                $table->foreignId('announcement_id')
                    ->constrained('announcements')
                    ->cascadeOnDelete();
                $table->string('zone', 64);
                $table->unique(['announcement_id', 'zone'], 'uq_announcement_zone');
            });
        }

        if (! Schema::hasTable('announcement_age_presets')) {
            Schema::create('announcement_age_presets', function (Blueprint $table) {
                $table->id('announcement_age_preset_id');
                $table->foreignId('announcement_id')
                    ->constrained('announcements')
                    ->cascadeOnDelete();
                $table->string('preset', 32);
                $table->unique(['announcement_id', 'preset'], 'uq_announcement_age_preset');
            });
        }

        $this->backfillListRows();

        $columns = array_values(array_filter(
            self::DROPPED,
            static fn (string $column): bool => Schema::hasColumn('announcements', $column)
        ));

        if ($columns !== []) {
            Schema::table('announcements', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('announcements')) {
            return;
        }

        Schema::table('announcements', function (Blueprint $table) {
            if (! Schema::hasColumn('announcements', 'age_presets')) {
                $table->json('age_presets')->nullable();
            }
            if (! Schema::hasColumn('announcements', 'zone_mode')) {
                $table->string('zone_mode', 16)->default('all');
            }
            if (! Schema::hasColumn('announcements', 'zones')) {
                $table->json('zones')->nullable();
            }
            if (! Schema::hasColumn('announcements', 'audience_label')) {
                $table->string('audience_label', 255)->default('');
            }
            if (! Schema::hasColumn('announcements', 'posted_by_name')) {
                $table->string('posted_by_name', 120)->default('');
            }
        });

        if (Schema::hasTable('announcement_zones')) {
            foreach (DB::table('announcement_zones')->get()->groupBy('announcement_id') as $id => $rows) {
                DB::table('announcements')->where('id', $id)->update([
                    'zone_mode' => 'specific',
                    'zones' => json_encode($rows->pluck('zone')->values()->all()),
                ]);
            }
        }

        if (Schema::hasTable('announcement_age_presets')) {
            foreach (DB::table('announcement_age_presets')->get()->groupBy('announcement_id') as $id => $rows) {
                DB::table('announcements')->where('id', $id)->update([
                    'age_presets' => json_encode($rows->pluck('preset')->values()->all()),
                ]);
            }
        }

        Schema::dropIfExists('announcement_age_presets');
        Schema::dropIfExists('announcement_zones');
    }

    private function backfillListRows(): void
    {
        if (! Schema::hasColumn('announcements', 'zones') && ! Schema::hasColumn('announcements', 'age_presets')) {
            return;
        }

        $select = array_values(array_filter(
            ['id', 'zone_mode', 'zones', 'age_presets'],
            static fn (string $column): bool => $column === 'id' || Schema::hasColumn('announcements', $column)
        ));

        foreach (DB::table('announcements')->select($select)->orderBy('id')->get() as $row) {
            $zoneMode = $row->zone_mode ?? 'specific';
            if ($zoneMode === 'specific') {
                $zones = $this->decodeList($row->zones ?? null);
                if ($zones === []) {
                    // Without zone rows it would read back as "All Zones".
                    throw new RuntimeException(
                        "Announcement {$row->id} has specific zone coverage but no zones. "
                        .'Add its zones or set zone_mode to "all" before running this migration.'
                    );
                }

                foreach ($zones as $zone) {
                    DB::table('announcement_zones')->insertOrIgnore([
                        'announcement_id' => $row->id,
                        'zone' => mb_substr($zone, 0, 64),
                    ]);
                }
            }

            foreach ($this->decodeList($row->age_presets ?? null) as $preset) {
                DB::table('announcement_age_presets')->insertOrIgnore([
                    'announcement_id' => $row->id,
                    'preset' => mb_substr($preset, 0, 32),
                ]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function decodeList(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode((string) $json, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($value): string => trim((string) $value), $decoded),
            static fn (string $value): bool => $value !== ''
        )));
    }
};
