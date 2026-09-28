<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3NF — announcement notifications.
 *
 * notifications.place / event_date / event_time copied the announcement's schedule,
 * so an edited announcement left residents with the old date. Link the row to its
 * announcement instead (related_announcement_id) and read the schedule from there.
 * recipient_context stays: it is per-recipient (matched household members).
 */
return new class extends Migration
{
    private const COPIED = ['place', 'event_date', 'event_time'];

    private const FOREIGN_KEY = 'fk_notif_announcement';

    public function up(): void
    {
        if (! Schema::hasTable('notifications') || ! Schema::hasTable('announcements')) {
            return;
        }

        if (! Schema::hasColumn('notifications', 'related_announcement_id')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->unsignedBigInteger('related_announcement_id')->nullable()->after('related_conversation_id');
                $table->foreign('related_announcement_id', self::FOREIGN_KEY)
                    ->references('id')
                    ->on('announcements')
                    ->nullOnDelete();
            });
        }

        $copied = $this->existingCopiedColumns();
        if ($copied === []) {
            return;
        }

        $this->backfillAnnouncementLinks();

        Schema::table('notifications', function (Blueprint $table) use ($copied) {
            $table->dropColumn($copied);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('notifications', 'place')) {
                $table->string('place', 120)->nullable()->after('recipient_context');
            }
            if (! Schema::hasColumn('notifications', 'event_date')) {
                $table->date('event_date')->nullable()->after('place');
            }
            if (! Schema::hasColumn('notifications', 'event_time')) {
                $table->time('event_time')->nullable()->after('event_date');
            }
        });

        if (! Schema::hasColumn('notifications', 'related_announcement_id')) {
            return;
        }

        $linked = DB::table('notifications')
            ->join('announcements', 'announcements.id', '=', 'notifications.related_announcement_id')
            ->select('notifications.notification_id', 'announcements.place', 'announcements.event_date', 'announcements.event_time')
            ->get();

        foreach ($linked as $row) {
            DB::table('notifications')->where('notification_id', $row->notification_id)->update([
                'place' => $row->place,
                'event_date' => $row->event_date,
                'event_time' => $row->event_time,
            ]);
        }

        // SQLite can only drop a foreign key by its column list.
        $foreignKey = Schema::getConnection()->getDriverName() === 'sqlite'
            ? ['related_announcement_id']
            : self::FOREIGN_KEY;

        Schema::table('notifications', function (Blueprint $table) use ($foreignKey) {
            $table->dropForeign($foreignKey);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('related_announcement_id');
        });
    }

    /**
     * @return list<string>
     */
    private function existingCopiedColumns(): array
    {
        return array_values(array_filter(
            self::COPIED,
            static fn (string $column): bool => Schema::hasColumn('notifications', $column)
        ));
    }

    /**
     * Match each announcement notification to its announcement: exact title +
     * message + date first, then title + message, then a unique title (covers
     * announcements edited after the fan-out). Refuse to drop unmatched schedules.
     */
    private function backfillAnnouncementLinks(): void
    {
        $copied = $this->existingCopiedColumns();

        $pending = DB::table('notifications')
            ->whereNull('related_announcement_id')
            ->where(function ($query) use ($copied) {
                foreach ($copied as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->get();

        $unmatched = [];

        foreach ($pending as $notification) {
            $announcementId = $this->matchAnnouncement($notification);

            if ($announcementId === null) {
                $unmatched[] = $notification->notification_id;

                continue;
            }

            DB::table('notifications')
                ->where('notification_id', $notification->notification_id)
                ->update(['related_announcement_id' => $announcementId]);
        }

        if ($unmatched !== []) {
            throw new RuntimeException(
                'Could not match notification(s) '.implode(', ', $unmatched)
                .' to an announcement. Set notifications.related_announcement_id for them '
                .'(or clear their place/event_date/event_time) before running this migration.'
            );
        }
    }

    private function matchAnnouncement(object $notification): ?int
    {
        $eventDate = isset($notification->event_date) ? $notification->event_date : null;

        $attempts = [
            ['title' => $notification->title, 'message' => $notification->message, 'event_date' => $eventDate],
            ['title' => $notification->title, 'message' => $notification->message],
            ['title' => $notification->title],
        ];

        foreach ($attempts as $where) {
            if (array_key_exists('event_date', $where) && $where['event_date'] === null) {
                continue;
            }

            $ids = DB::table('announcements')
                ->where(function ($query) use ($where) {
                    foreach ($where as $column => $value) {
                        $column === 'event_date'
                            ? $query->whereDate($column, $value)
                            : $query->where($column, $value);
                    }
                })
                ->limit(2)
                ->pluck('id');

            if ($ids->count() === 1) {
                return (int) $ids->first();
            }
        }

        return null;
    }
};
