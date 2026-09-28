<?php

namespace App\Models;

use App\Models\Scopes\StaffNotDeletedScope;
use App\Support\AnnouncementPresenter;
use App\Support\UiRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Audience lists live in announcement_zones / announcement_age_presets (3NF).
 * zones, age_presets, zone_mode, audience_label and posted_by_name are derived
 * attributes, not columns. Assigning zones / age_presets queues the rows, which
 * are written when the announcement is saved.
 *
 * @property list<string> $zones
 * @property list<string> $age_presets
 * @property-read string $zone_mode
 * @property-read string $audience_label
 * @property-read string $posted_by_name
 */
class Announcement extends Model
{
    /** @use HasFactory<\Database\Factories\AnnouncementFactory> */
    use HasFactory;

    public const TARGET_ALL = 'all';

    public const TARGET_AGE = 'age';

    public const TARGET_ACTIVE_MATERNAL = 'active_maternal';

    public const TARGET_ACTIVE_FP_USER = 'active_fp_user';

    public const ZONE_ALL = 'all';

    public const ZONE_SPECIFIC = 'specific';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'message',
        'event_date',
        'event_time',
        'place',
        'target_group',
        'age_min_months',
        'age_max_months',
        'estimated_reach',
        'posted_by_user_id',
        'posted_by_role',
        'posted_at',
    ];

    /** @var array{zones?: list<string>, age_presets?: list<string>} */
    private array $pendingAudience = [];

    protected static function booted(): void
    {
        static::saved(function (Announcement $announcement): void {
            if ($announcement->pendingAudience === []) {
                return;
            }

            $pending = $announcement->pendingAudience;
            $announcement->pendingAudience = [];
            $announcement->syncAudience(
                $pending['zones'] ?? $announcement->zones,
                $pending['age_presets'] ?? $announcement->age_presets,
            );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'posted_at' => 'datetime',
            'estimated_reach' => 'integer',
        ];
    }

    /**
     * @return HasMany<AnnouncementZone, $this>
     */
    public function zoneRows(): HasMany
    {
        return $this->hasMany(AnnouncementZone::class, 'announcement_id')->orderBy('announcement_zone_id');
    }

    /**
     * @return HasMany<AnnouncementAgePreset, $this>
     */
    public function agePresetRows(): HasMany
    {
        return $this->hasMany(AnnouncementAgePreset::class, 'announcement_id')->orderBy('announcement_age_preset_id');
    }

    /**
     * Includes archived staff so old announcements keep their poster's name.
     *
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id', (new User)->getKeyName())
            ->withoutGlobalScope(StaffNotDeletedScope::class);
    }

    /**
     * Replace the zone and age preset rows (call inside the saving transaction).
     *
     * @param  list<string>  $zones
     * @param  list<string>  $agePresets
     */
    public function syncAudience(array $zones, array $agePresets): void
    {
        $this->zoneRows()->delete();
        $this->agePresetRows()->delete();

        foreach (array_values(array_unique($zones)) as $zone) {
            $this->zoneRows()->create(['zone' => $zone]);
        }

        foreach (array_values(array_unique($agePresets)) as $preset) {
            $this->agePresetRows()->create(['preset' => $preset]);
        }

        $this->unsetRelation('zoneRows');
        $this->unsetRelation('agePresetRows');
    }

    public function setZonesAttribute(mixed $value): void
    {
        $this->pendingAudience['zones'] = self::normalizeList($value);
    }

    public function setAgePresetsAttribute(mixed $value): void
    {
        $this->pendingAudience['age_presets'] = self::normalizeList($value);
    }

    /**
     * @return list<string>
     */
    public function getZonesAttribute(): array
    {
        if (array_key_exists('zones', $this->pendingAudience)) {
            return $this->pendingAudience['zones'];
        }

        return $this->zoneRows->pluck('zone')->map(static fn ($zone): string => (string) $zone)->values()->all();
    }

    /**
     * @return list<string>
     */
    public function getAgePresetsAttribute(): array
    {
        if (array_key_exists('age_presets', $this->pendingAudience)) {
            return $this->pendingAudience['age_presets'];
        }

        return $this->agePresetRows->pluck('preset')->map(static fn ($preset): string => (string) $preset)->values()->all();
    }

    public function getZoneModeAttribute(): string
    {
        return $this->zones === [] ? self::ZONE_ALL : self::ZONE_SPECIFIC;
    }

    public function getAudienceLabelAttribute(): string
    {
        return AnnouncementPresenter::audienceLabel(
            (string) $this->target_group,
            $this->age_presets,
            $this->age_min_months !== null ? (int) $this->age_min_months : null,
            $this->age_max_months !== null ? (int) $this->age_max_months : null,
        );
    }

    public function getPostedByNameAttribute(): string
    {
        $name = $this->poster?->composeDisplayName() ?? '';
        if ($name !== '') {
            return $name;
        }

        $role = UiRole::normalize((string) $this->posted_by_role);

        return $role !== null ? UiRole::label($role) : 'Staff';
    }

    /**
     * @return list<string>
     */
    private static function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($item): string => trim((string) $item), $value),
            static fn (string $item): bool => $item !== ''
        )));
    }
}
