<?php

namespace App\Http\Requests;

use App\Models\Announcement;
use App\Support\AnnouncementAgePreset;
use App\Support\UiRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Targeting-only validation for live estimated-reach preview.
 * Does not accept title/message/date or client-supplied estimated_reach.
 */
class PreviewAnnouncementReachRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = UiRole::current();

        return $role !== null && in_array($role, UiRole::ALLOWED, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'audience_type' => [
                'required',
                'string',
                Rule::in([
                    Announcement::TARGET_ALL,
                    Announcement::TARGET_AGE,
                    Announcement::TARGET_ACTIVE_MATERNAL,
                    Announcement::TARGET_ACTIVE_FP_USER,
                ]),
            ],
            'zone_coverage' => [
                'required',
                'string',
                Rule::in([Announcement::ZONE_ALL, Announcement::ZONE_SPECIFIC]),
            ],
            'age_groups' => ['nullable', 'array'],
            'age_groups.*' => ['string', Rule::in(array_keys(AnnouncementAgePreset::PRESETS))],
            'age_from' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'age_to' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'age_from_unit' => ['nullable', 'string', Rule::in(['months', 'years'])],
            'age_to_unit' => ['nullable', 'string', Rule::in(['months', 'years'])],
            'zones' => ['nullable', 'array'],
            'zones.*' => ['string', 'max:64'],
            'custom_zones' => ['nullable', 'array'],
            'custom_zones.*' => ['string', 'max:64'],
            'estimated_reach' => ['prohibited'],
            'title' => ['prohibited'],
            'message' => ['prohibited'],
            'date' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $audienceType = (string) $this->input('audience_type');
            $zoneCoverage = (string) $this->input('zone_coverage');

            if ($audienceType === Announcement::TARGET_AGE) {
                $presets = collect($this->input('age_groups', []))
                    ->filter(fn ($value) => filled($value))
                    ->values();
                $hasCustomFrom = filled($this->input('age_from'));
                $hasCustomTo = filled($this->input('age_to'));

                if ($presets->isEmpty() && ! $hasCustomFrom && ! $hasCustomTo) {
                    $validator->errors()->add(
                        'age_groups',
                        'Select at least one age group or enter a valid custom age range.',
                    );
                }

                if ($hasCustomFrom || $hasCustomTo) {
                    try {
                        $fromMonths = $hasCustomFrom
                            ? AnnouncementAgePreset::toMonths(
                                (int) $this->input('age_from'),
                                (string) ($this->input('age_from_unit') ?? 'months'),
                            )
                            : null;
                        $toMonths = $hasCustomTo
                            ? AnnouncementAgePreset::toMonths(
                                (int) $this->input('age_to'),
                                (string) ($this->input('age_to_unit') ?? 'months'),
                            )
                            : null;

                        if ($fromMonths !== null && $toMonths !== null && $fromMonths > $toMonths) {
                            $validator->errors()->add(
                                'age_to',
                                'Custom age range is invalid. “From” must be less than or equal to “To”.',
                            );
                        }
                    } catch (\InvalidArgumentException $exception) {
                        $validator->errors()->add('age_from', $exception->getMessage());
                    }
                }
            }

            if ($zoneCoverage === Announcement::ZONE_SPECIFIC) {
                $zones = collect($this->input('zones', []))->filter(fn ($value) => filled($value));
                $customZones = collect($this->input('custom_zones', []))->filter(fn ($value) => filled($value));

                if ($zones->isEmpty() && $customZones->isEmpty()) {
                    $validator->errors()->add('zones', 'Select at least one zone.');
                }
            }
        });
    }
}
