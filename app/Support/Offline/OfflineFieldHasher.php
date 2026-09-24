<?php

namespace App\Support\Offline;

use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use App\Models\WorkerAppointment;
use App\Services\HouseholdEnvironmentalProfileService;
use App\Support\DatabaseSchemaGuard;
use App\Support\HouseholdZoneResolver;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical hash of editable household/resident/health-worker fields from the
 * current update forms. Does not hash timestamps, PKs, accessors, relations,
 * or encrypted narratives.
 */
final class OfflineFieldHasher
{
    /**
     * Household Profiling shell update fields (UpdateHouseholdRequest).
     *
     * @var list<string>
     */
    public const HOUSEHOLD_FIELDS = [
        'zone',
        'street',
        'date_registered',
        'address',
        'latitude',
        'longitude',
        'accomplished_by',
        'household_type',
    ];

    /**
     * Household member update fields (UpdateResidentRequest).
     *
     * @var list<string>
     */
    public const RESIDENT_FIELDS = [
        'last_name',
        'first_name',
        'middle_name',
        'relation',
        'birthday',
        'sex',
        'relationship_status',
        'occupation',
        'occupation_other',
        'monthly_income',
        'religion',
        'religion_other',
        'education',
        'fp_user',
        'philhealth',
        'disability',
        'disability_others',
        'medical_history',
        'medical_others',
    ];

    /**
     * Health Worker edit fields (UpdateHealthWorkerRequest) excluding
     * passwords, photos, and client-authoritative PKs.
     *
     * @var list<string>
     */
    public const HEALTH_WORKER_FIELDS = [
        'sex',
        'hw_first_name',
        'hw_last_name',
        'hw_middle_name',
        'hw_suffix',
        'hw_dob',
        'hw_civil_status',
        'hw_nationality',
        'hw_mobile',
        'hw_email',
        'hw_house_no',
        'hw_street',
        'hw_purok_zone',
        'hw_barangay',
        'hw_municipality',
        'hw_province',
        'hw_zip',
        'hw_role',
        'hw_assigned_barangay',
        'hw_assigned_zone',
        'hw_date_appointed',
        'hw_end_appointment',
        'hw_username',
        'hw_status',
    ];

    /**
     * Amenities / Environmental Sanitation editable fields.
     *
     * @var list<string>
     */
    public const ENVIRONMENTAL_FIELDS = [
        'water_supply_status',
        'specify_water_source',
        'water_source_location',
        'water_availability',
        'microbiological_test_date',
        'microbiological_result',
        'physicochemical_test_date',
        'physicochemical_result',
        'toilet_type',
        'open_defecation_practiced',
        'shared_toilet',
        'sewage_disposal_method',
        'solid_waste_practices',
    ];

    public static function household(Household $household): string
    {
        return OfflinePayloadCanonicalizer::hash(self::householdSnapshot($household));
    }

    /**
     * @return array<string, mixed>
     */
    public static function householdSnapshot(Household $household): array
    {
        $guard = app(DatabaseSchemaGuard::class);
        $table = $household->getTable();
        $snapshot = [];

        foreach (self::HOUSEHOLD_FIELDS as $field) {
            if ($field === 'zone') {
                $snapshot['zone'] = self::displayZone($household);

                continue;
            }

            if (! $guard->columnExists($table, $field)) {
                continue;
            }

            $snapshot[$field] = self::normalizeScalar($household->getAttribute($field), $field);
        }

        return $snapshot;
    }

    public static function resident(Resident $resident): string
    {
        return OfflinePayloadCanonicalizer::hash(self::residentSnapshot($resident));
    }

    /**
     * @return array<string, mixed>
     */
    public static function residentSnapshot(Resident $resident): array
    {
        $guard = app(DatabaseSchemaGuard::class);
        $table = $resident->getTable();
        $snapshot = [];

        foreach (self::RESIDENT_FIELDS as $field) {
            $value = self::residentFieldValue($resident, $guard, $table, $field);
            if ($value === '__skip__') {
                continue;
            }

            $snapshot[$field] = $value;
        }

        return $snapshot;
    }

    public static function healthWorker(User $user): string
    {
        return OfflinePayloadCanonicalizer::hash(self::healthWorkerSnapshot($user));
    }

    /**
     * @return array<string, mixed>
     */
    public static function healthWorkerSnapshot(User $user): array
    {
        $user->loadMissing('currentAppointment');
        $appointment = $user->resolveCurrentAppointment();
        if ($appointment !== null && Schema::hasTable('worker_appointment_zones')) {
            $appointment->loadMissing('assignedZones');
        }
        $roleLabel = StaffRole::label($appointment?->role);

        return [
            'sex' => self::nullableString($user->sex),
            'hw_first_name' => self::nullableString($user->first_name),
            'hw_last_name' => self::nullableString($user->last_name),
            'hw_middle_name' => self::nullableString($user->middle_name),
            'hw_suffix' => self::nullableString($user->suffix),
            'hw_dob' => self::normalizeScalar($user->date_of_birth, 'hw_dob'),
            'hw_civil_status' => self::nullableString($user->civil_status),
            'hw_nationality' => self::nullableString($user->nationality),
            'hw_mobile' => self::nullableString($user->mobile_number),
            'hw_email' => self::nullableString($user->email),
            'hw_house_no' => self::nullableString($user->house_no),
            'hw_street' => self::nullableString($user->street),
            'hw_purok_zone' => self::nullableString($user->purok_zone),
            'hw_barangay' => self::nullableString($user->barangay),
            'hw_municipality' => self::nullableString($user->municipality_city),
            'hw_province' => self::nullableString($user->province),
            'hw_zip' => self::nullableString($user->zip_code),
            'hw_role' => $roleLabel !== '' ? $roleLabel : null,
            'hw_assigned_barangay' => self::nullableString($appointment?->assigned_barangay),
            'hw_assigned_zone' => self::assignedZonesSnapshot($appointment),
            'hw_date_appointed' => self::normalizeScalar($appointment?->date_appointed, 'hw_date_appointed'),
            'hw_end_appointment' => self::normalizeScalar($appointment?->end_of_appointment, 'hw_end_appointment'),
            'hw_username' => self::nullableString($user->username),
            'hw_status' => StaffAccountStatus::normalize($user->status),
        ];
    }

    public static function environmental(Household $household): string
    {
        return OfflinePayloadCanonicalizer::hash(self::environmentalSnapshot($household));
    }

    public static function environmentalExists(Household $household): bool
    {
        $snapshot = self::environmentalSnapshot($household);

        return ($snapshot['present'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public static function environmentalSnapshot(Household $household): array
    {
        $presentation = app(HouseholdEnvironmentalProfileService::class)->findPresentation($household);
        if (! is_array($presentation) || $presentation === []) {
            return ['present' => false];
        }

        $snapshot = ['present' => true];
        foreach (self::ENVIRONMENTAL_FIELDS as $field) {
            $value = $presentation[$field] ?? null;
            if ($field === 'solid_waste_practices') {
                $snapshot[$field] = self::normalizeStringList($value);

                continue;
            }
            if (str_ends_with($field, '_date')) {
                $snapshot[$field] = self::normalizeScalar($value, $field);

                continue;
            }
            $snapshot[$field] = self::nullableString($value);
        }

        return $snapshot;
    }

    /**
     * @return mixed
     */
    private static function residentFieldValue(
        Resident $resident,
        DatabaseSchemaGuard $guard,
        string $table,
        string $field
    ) {
        if ($field === 'relation') {
            return self::nullableString($resident->relation);
        }

        if ($field === 'occupation') {
            if ($guard->columnExists($table, 'occupation')) {
                return self::nullableString($resident->getAttribute('occupation'));
            }

            if ($guard->columnExists($table, 'occupation_id')) {
                $id = $resident->getAttribute('occupation_id');

                return is_numeric($id) ? (int) $id : null;
            }

            return '__skip__';
        }

        if ($field === 'religion') {
            if ($guard->columnExists($table, 'religion')) {
                return self::nullableString($resident->getAttribute('religion'));
            }

            if ($guard->columnExists($table, 'religion_id')) {
                $id = $resident->getAttribute('religion_id');

                return is_numeric($id) ? (int) $id : null;
            }

            return '__skip__';
        }

        if ($field === 'relationship_status') {
            if ($guard->columnExists($table, 'relationship_status')) {
                return self::nullableString($resident->getAttribute('relationship_status'));
            }

            if ($guard->columnExists($table, 'civil_status')) {
                return self::nullableString($resident->getAttribute('civil_status'));
            }

            return '__skip__';
        }

        if ($field === 'education') {
            if ($guard->columnExists($table, 'education')) {
                return self::nullableString($resident->getAttribute('education'));
            }

            if ($guard->columnExists($table, 'educational_attainment')) {
                return self::nullableString($resident->getAttribute('educational_attainment'));
            }

            return '__skip__';
        }

        if ($field === 'fp_user') {
            if ($guard->columnExists($table, 'fp_user')) {
                return self::nullableString($resident->getAttribute('fp_user'));
            }

            if ($guard->columnExists($table, 'is_fp_user')) {
                return ((bool) $resident->getAttribute('is_fp_user')) ? 'Yes' : 'No';
            }

            return '__skip__';
        }

        if ($field === 'philhealth') {
            if ($guard->columnExists($table, 'philhealth')) {
                return self::nullableString($resident->getAttribute('philhealth'));
            }

            if ($guard->columnExists($table, 'philhealth_number')) {
                return self::nullableString($resident->getAttribute('philhealth_number'));
            }

            return '__skip__';
        }

        if ($field === 'disability') {
            return self::disabilitySnapshot($resident, $guard, $table);
        }

        if ($field === 'disability_others') {
            return self::disabilityOthersSnapshot($resident, $guard, $table);
        }

        if ($field === 'medical_history') {
            return self::medicalHistorySnapshot($resident, $guard, $table);
        }

        if ($field === 'medical_others') {
            return self::medicalOthersSnapshot($resident, $guard, $table);
        }

        if (! $guard->columnExists($table, $field)) {
            return '__skip__';
        }

        return self::normalizeScalar($resident->getAttribute($field), $field);
    }

    /**
     * @return list<string>|null|string
     */
    private static function disabilitySnapshot(Resident $resident, DatabaseSchemaGuard $guard, string $table): mixed
    {
        if ($guard->columnExists($table, 'disability')) {
            return self::normalizeStringList($resident->getAttribute('disability'));
        }

        if (! Schema::hasTable('disability_type')) {
            return '__skip__';
        }

        $resident->loadMissing('disabilityType');
        $row = $resident->disabilityType;
        if ($row === null) {
            return null;
        }

        $items = [];
        if ($row->no_disability) {
            $items[] = 'none';
        }
        if ($row->intellectual_disability) {
            $items[] = 'Intellectual Disability (ID)';
        }
        if ($row->mental_disability) {
            $items[] = 'Mental Disability (MD)';
        }
        if ($row->physical_disability) {
            $items[] = 'Physical Disability (PD)';
        }
        if ($row->other_disability) {
            $items[] = 'others';
        }

        return self::normalizeStringList($items);
    }

    private static function disabilityOthersSnapshot(Resident $resident, DatabaseSchemaGuard $guard, string $table): mixed
    {
        if ($guard->columnExists($table, 'disability_others')) {
            return self::nullableString($resident->getAttribute('disability_others'));
        }

        if (! Schema::hasTable('disability_type')) {
            return '__skip__';
        }

        $resident->loadMissing('disabilityType');
        $row = $resident->disabilityType;
        if ($row === null) {
            return null;
        }

        return self::nullableString($row->other_disability_specify);
    }

    /**
     * @return list<string>|null|string
     */
    private static function medicalHistorySnapshot(Resident $resident, DatabaseSchemaGuard $guard, string $table): mixed
    {
        if ($guard->columnExists($table, 'medical_history')) {
            return self::normalizeStringList($resident->getAttribute('medical_history'));
        }

        if (! Schema::hasTable('medical_history')) {
            return '__skip__';
        }

        $resident->loadMissing('medicalHistoryEntry');
        $row = $resident->medicalHistoryEntry;
        if ($row === null) {
            return null;
        }

        $items = [];
        if ($row->no_medical_history) {
            $items[] = 'none';
        }
        if ($row->diabetes_mellitus) {
            $items[] = 'Diabetes Mellitus';
        }
        if ($row->heart_disease) {
            $items[] = 'Heart Disease';
        }
        if ($row->hypertension) {
            $items[] = 'Hypertension';
        }
        if ($row->kidney_disease) {
            $items[] = 'Kidney Disease';
        }
        if ($row->tuberculosis) {
            $items[] = 'Tuberculosis';
        }
        if (trim((string) ($row->other_medical_history ?? '')) !== '') {
            $items[] = 'others';
        }

        return self::normalizeStringList($items);
    }

    private static function medicalOthersSnapshot(Resident $resident, DatabaseSchemaGuard $guard, string $table): mixed
    {
        if ($guard->columnExists($table, 'medical_others')) {
            return self::nullableString($resident->getAttribute('medical_others'));
        }

        if (! Schema::hasTable('medical_history')) {
            return '__skip__';
        }

        $resident->loadMissing('medicalHistoryEntry');
        $row = $resident->medicalHistoryEntry;
        if ($row === null) {
            return null;
        }

        return self::nullableString($row->other_medical_history);
    }

    /**
     * Ordered unique zone labels. List order is preserved (first selected is primary).
     *
     * @return list<string>|null
     */
    private static function assignedZonesSnapshot(?WorkerAppointment $appointment): ?array
    {
        if ($appointment === null) {
            return null;
        }

        $zones = $appointment->assignedZoneLabels();

        return $zones === [] ? null : $zones;
    }

    private static function displayZone(Household $household): ?string
    {
        $stored = HouseholdZoneResolver::storedValueFromHousehold($household);
        if ($stored === '') {
            return null;
        }

        $number = HouseholdZoneResolver::zoneNumberFromLabel($stored)
            ?? HouseholdZoneResolver::zoneNumberFromStoredValue($stored);

        return $number !== null ? 'Zone '.$number : $stored;
    }

    private static function normalizeScalar(mixed $value, string $field): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (in_array($field, ['latitude', 'longitude'], true)) {
            return is_numeric($value) ? (string) $value : self::nullableString($value);
        }

        if (in_array($field, ['disability', 'medical_history'], true)) {
            return self::normalizeStringList($value);
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return self::nullableString($value);
    }

    /**
     * @return list<string>|null
     */
    private static function normalizeStringList(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }

        if (! is_array($value)) {
            return [(string) $value];
        }

        $items = array_values(array_unique(array_map(static fn ($item): string => (string) $item, $value)));
        sort($items, SORT_STRING);

        return $items;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
