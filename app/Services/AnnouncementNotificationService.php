<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Support\HouseholdProfilingPresenter;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves notifyable resident accounts for a saved Announcement and can
 * insert System notification rows. Does not own announcement persistence
 * or open its own DB transaction.
 */
final class AnnouncementNotificationService
{
    public const NOTIFICATION_TYPE_SYSTEM = 'System';

    public function __construct(
        private readonly AnnouncementAudienceMatcher $matcher,
    ) {}

    /**
     * Distinct resident-account primary keys for households that contain
     * at least one matcher-matched resident.
     *
     * @return Collection<int, int|string>
     */
    public function recipientAccountIds(Announcement $announcement): Collection
    {
        return $this->recipientPayloads($announcement)
            ->pluck('account_id')
            ->values();
    }

    public function notifyableCount(Announcement $announcement): int
    {
        return $this->recipientAccountIds($announcement)->count();
    }

    /**
     * Insert one System notification per linked recipient account.
     * Returns the number of rows created (0 when there are no recipients).
     * Does not begin a transaction — call within the store transaction.
     */
    public function fanOut(Announcement $announcement): int
    {
        $payloads = $this->recipientPayloads($announcement);

        if ($payloads->isEmpty()) {
            return 0;
        }

        $title = (string) $announcement->title;
        $message = (string) $announcement->message;
        $announcementId = $announcement->getKey();
        $createdAt = now()->toDateTimeString();
        $hasContextColumn = Schema::hasColumn('notifications', 'recipient_context');
        $hasAnnouncementLink = Schema::hasColumn('notifications', 'related_announcement_id');

        $rows = $payloads->map(static function (array $payload) use (
            $title,
            $message,
            $announcementId,
            $createdAt,
            $hasContextColumn,
            $hasAnnouncementLink,
        ): array {
            $row = [
                'account_id' => $payload['account_id'],
                'notification_type' => self::NOTIFICATION_TYPE_SYSTEM,
                'title' => $title,
                'message' => $message,
                'related_request_id' => null,
                'related_conversation_id' => null,
                'is_read' => 0,
                'created_at' => $createdAt,
            ];

            if ($hasContextColumn) {
                $row['recipient_context'] = $payload['recipient_context'];
            }

            // Place/date/time are read from the linked announcement (3NF).
            if ($hasAnnouncementLink) {
                $row['related_announcement_id'] = $announcementId;
            }

            return $row;
        })->all();

        DB::table('notifications')->insert($rows);

        return count($rows);
    }

    /**
     * Portal accounts to notify, each with matched member names from their household.
     *
     * @return Collection<int, array{account_id: int|string, recipient_context: string}>
     */
    private function recipientPayloads(Announcement $announcement): Collection
    {
        $matchedResidents = $this->matcher->matchingQuery(
            $this->criteriaFromAnnouncement($announcement)
        )
            ->whereNotNull('household_id')
            ->get();

        if ($matchedResidents->isEmpty()) {
            return collect();
        }

        $matchedByHousehold = $matchedResidents
            ->groupBy(static fn (Resident $resident): string => (string) $resident->household_id);

        $householdKeys = $matchedByHousehold->keys()->all();
        $residentKey = (new Resident)->getKeyName();
        $accountKey = (new ResidentAccount)->getKeyName();

        $householdResidentKeys = Resident::query()
            ->whereIn('household_id', $householdKeys)
            ->pluck($residentKey)
            ->unique()
            ->values();

        if ($householdResidentKeys->isEmpty()) {
            return collect();
        }

        $accounts = ResidentAccount::query()
            ->whereIn('resident_id', $householdResidentKeys->all())
            ->orderBy($accountKey)
            ->get();

        if ($accounts->isEmpty()) {
            return collect();
        }

        $linkedResidents = Resident::query()
            ->whereIn($residentKey, $accounts->pluck('resident_id')->filter()->unique()->all())
            ->get()
            ->keyBy(static fn (Resident $resident) => (string) $resident->getKey());

        return $accounts
            ->map(function (ResidentAccount $account) use ($matchedByHousehold, $linkedResidents, $accountKey): ?array {
                $linked = $linkedResidents->get((string) $account->resident_id);
                if (! $linked instanceof Resident || $linked->household_id === null || $linked->household_id === '') {
                    return null;
                }

                $matchedInHousehold = $matchedByHousehold->get((string) $linked->household_id, collect());
                if ($matchedInHousehold->isEmpty()) {
                    return null;
                }

                $names = $matchedInHousehold
                    ->sortBy(static function (Resident $resident): string {
                        return mb_strtolower(trim((string) $resident->last_name))
                            .'|'.mb_strtolower(trim((string) $resident->first_name))
                            .'|'.$resident->getKey();
                    })
                    ->map(static fn (Resident $resident): string => HouseholdProfilingPresenter::fullName($resident))
                    ->filter(static fn (string $name): bool => $name !== '')
                    ->unique()
                    ->values();

                if ($names->isEmpty()) {
                    return null;
                }

                return [
                    'account_id' => $account->getAttribute($accountKey) ?? $account->getKey(),
                    'recipient_context' => $names->implode(', '),
                ];
            })
            ->filter()
            ->unique('account_id')
            ->values();
    }

    /**
     * @return array{
     *     target_group: string,
     *     age_presets: list<string>,
     *     age_range_months: array{min: int|null, max: int|null},
     *     zone_mode: string,
     *     zones: list<string|int>,
     *     as_of: \Carbon\CarbonInterface
     * }
     */
    private function criteriaFromAnnouncement(Announcement $announcement): array
    {
        return [
            'target_group' => (string) $announcement->target_group,
            'age_presets' => $announcement->age_presets,
            'age_range_months' => [
                'min' => $announcement->age_min_months !== null
                    ? (int) $announcement->age_min_months
                    : null,
                'max' => $announcement->age_max_months !== null
                    ? (int) $announcement->age_max_months
                    : null,
            ],
            'zone_mode' => (string) $announcement->zone_mode,
            'zones' => $announcement->zones,
            'as_of' => Carbon::today()->startOfDay(),
        ];
    }
}
