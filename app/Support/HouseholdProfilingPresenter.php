<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Resident;
use App\Services\HouseholdEnvironmentalProfileService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Maps Eloquent Household/Resident to the frozen Blade presentation shape
 * previously supplied by resources/demo/households.php.
 */
final class HouseholdProfilingPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function fromModel(Household $household): array
    {
        // Member blades may still call lml_demo_* helpers; ensure they exist on DB-only paths.
        DemoCatalog::ensureHouseholdHelpers();

        $with = ['residents'];
        if (Resident::occupationLookupAvailable()) {
            $with[] = 'residents.occupationLookup';
        }
        if (Resident::religionLookupAvailable()) {
            $with[] = 'residents.religionLookup';
        }
        if (Schema::hasTable('disability_type')) {
            $with[] = 'residents.disabilityType';
        }
        if (Schema::hasTable('medical_history')) {
            $with[] = 'residents.medicalHistoryEntry';
        }
        $household->loadMissing($with);

        $residents = $household->residents;
        $head = $residents->first(
            static fn (Resident $r): bool => strcasecmp((string) $r->relation, 'Head') === 0
        );

        $memberList = $residents
            ->map(static fn (Resident $r): array => self::memberFromModel($r))
            ->values()
            ->all();

        $householdNo = (string) $household->household_no;
        $displayNo = preg_replace('/^HH-/i', 'HH ', $householdNo) ?: $householdNo;
        $zone = self::zoneLabel($household);
        $street = self::streetLabel($household);
        $amenities = app(HouseholdEnvironmentalProfileService::class)->findPresentation($household);

        return [
            'householdNo' => $householdNo,
            'displayNo' => $displayNo,
            'houseHead' => $head ? self::fullName($head) : '—',
            'zone' => $zone,
            'street' => $street,
            'address' => self::addressLabel($household, $street),
            'purok' => self::rawLocationValue($household),
            'members' => count($memberList),
            'lat' => $household->latitude !== null ? (float) $household->latitude : null,
            'lng' => $household->longitude !== null ? (float) $household->longitude : null,
            'mapStatus' => 'plotted',
            'accomplishedBy' => self::accomplishedByLabel($household),
            'accomplishedDate' => $household->date_registered instanceof Carbon
                ? $household->date_registered->format('m/d/Y')
                : '—',
            'water' => self::waterCardFromRecord($amenities),
            'sanitation' => self::sanitationCardFromRecord($amenities),
            'memberList' => $memberList,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $record
     * @return array{title: string, level: string, status: string}
     */
    private static function waterCardFromRecord(?array $record): array
    {
        $fallback = [
            'title' => 'Access to Safe Water',
            'level' => '—',
            'status' => 'Not recorded',
        ];

        if ($record === null || blank($record['water_supply_status'] ?? null)) {
            return $fallback;
        }

        $status = (string) ($record['basic_safe_water_status']
            ?? DemoHouseholdWaterSupply::deriveBasicSafeWaterStatus((string) $record['water_supply_status']));

        return [
            'title' => 'Access to Safe Water',
            'level' => DemoHouseholdWaterSupply::waterSupplyLevelLabel((string) $record['water_supply_status']),
            'status' => DemoHouseholdWaterSupply::basicSafeWaterStatusLabel($status),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $record
     * @return array{title: string, facility: string, status: string}
     */
    private static function sanitationCardFromRecord(?array $record): array
    {
        $fallback = [
            'title' => 'Sanitation Services',
            'facility' => '—',
            'status' => 'Not recorded',
        ];

        if ($record === null || blank($record['toilet_type'] ?? null)) {
            return $fallback;
        }

        $management = (string) ($record['management_status']
            ?? DemoHouseholdWaterSupply::deriveManagementStatus(
                (string) $record['toilet_type'],
                $record['sewage_disposal_method'] ?? null
            ));

        $statusLabel = match ($management) {
            DemoHouseholdWaterSupply::MANAGEMENT_STATUS_SAFELY_MANAGED => 'Safely Managed',
            DemoHouseholdWaterSupply::MANAGEMENT_STATUS_NOT_SAFELY_MANAGED => 'Not Safely Managed',
            default => 'Not recorded',
        };

        return [
            'title' => 'Sanitation Services',
            'facility' => HouseholdAmenitiesPresentation::toiletStatusLabel(
                (string) ($record['toilet_status'] ?? '')
            ),
            'status' => $statusLabel,
        ];
    }

    private static function zoneLabel(Household $household): string
    {
        $raw = self::rawLocationValue($household);

        return $raw === '' ? '' : HouseholdZoneResolver::displayLabelFromStoredValue($raw);
    }

    private static function rawLocationValue(Household $household): string
    {
        $column = HouseholdZoneResolver::locationColumn();
        if ($column === null) {
            return '';
        }

        return trim((string) ($household->getAttributes()[$column] ?? ''));
    }

    private static function streetLabel(Household $household): string
    {
        $column = app(DatabaseSchemaGuard::class)->householdStreetColumn();
        if ($column === null) {
            return '—';
        }

        $value = trim((string) ($household->getAttributes()[$column] ?? ''));

        return $value !== '' ? $value : '—';
    }

    private static function addressLabel(Household $household, string $street): string
    {
        $guard = app(DatabaseSchemaGuard::class);
        if ($guard->columnExists('households', 'address')) {
            $address = trim((string) ($household->getAttributes()['address'] ?? ''));
            if ($address !== '') {
                return $address;
            }
        }

        return $street;
    }

    private static function accomplishedByLabel(Household $household): string
    {
        if (! app(DatabaseSchemaGuard::class)->columnExists('households', 'accomplished_by')) {
            return '—';
        }

        $value = trim((string) ($household->getAttributes()['accomplished_by'] ?? ''));

        return $value !== '' ? $value : '—';
    }

    private static function occupationLabel(Resident $resident): string
    {
        $state = self::occupationFormState($resident);

        if (ResidentMemberFieldMap::isOtherChoice($state['select']) && $state['other'] !== '') {
            return $state['other'];
        }

        return $state['select'];
    }

    private static function religionLabel(Resident $resident): string
    {
        $state = self::religionFormState($resident);

        if (ResidentMemberFieldMap::isOtherChoice($state['select']) && $state['other'] !== '') {
            return $state['other'];
        }

        return $state['select'];
    }

    /**
     * @return array{select: string, other: string}
     */
    private static function occupationFormState(Resident $resident): array
    {
        $options = ResidentMemberFieldMap::occupationOptions();
        $guard = app(DatabaseSchemaGuard::class);
        $table = $resident->getTable();

        $other = '';
        if ($guard->columnExists($table, 'occupation_other')) {
            $other = trim((string) ($resident->getAttributes()['occupation_other'] ?? ''));
        }

        $legacy = '';
        if ($guard->columnExists($table, 'occupation')) {
            $legacy = trim((string) ($resident->getAttributes()['occupation'] ?? ''));
        }

        if ($legacy !== '') {
            if (in_array($legacy, $options, true)) {
                return [
                    'select' => $legacy,
                    'other' => ResidentMemberFieldMap::isOtherChoice($legacy) ? $other : '',
                ];
            }

            return ['select' => 'Other', 'other' => $legacy];
        }

        $lookupName = '';
        if (Resident::occupationLookupAvailable()) {
            $resident->loadMissing('occupationLookup');
            $lookupName = trim((string) ($resident->occupationLookup?->occupation_name ?? ''));
        }

        return self::selectOrOtherFormState($lookupName, $other, $options);
    }

    /**
     * @return array{select: string, other: string}
     */
    private static function religionFormState(Resident $resident): array
    {
        $options = ResidentMemberFieldMap::religionOptions();
        $guard = app(DatabaseSchemaGuard::class);
        $table = $resident->getTable();

        $other = '';
        if ($guard->columnExists($table, 'religion_other')) {
            $other = trim((string) ($resident->getAttributes()['religion_other'] ?? ''));
        }

        $legacy = '';
        if ($guard->columnExists($table, 'religion')) {
            $legacy = trim((string) ($resident->getAttributes()['religion'] ?? ''));
        }

        if ($legacy !== '') {
            if (in_array($legacy, $options, true)) {
                return [
                    'select' => $legacy,
                    'other' => ResidentMemberFieldMap::isOtherChoice($legacy) ? $other : '',
                ];
            }

            return ['select' => 'Other', 'other' => $legacy];
        }

        $lookupName = '';
        if (Resident::religionLookupAvailable()) {
            $resident->loadMissing('religionLookup');
            $lookupName = trim((string) ($resident->religionLookup?->religion_name ?? ''));
            if (strcasecmp($lookupName, 'Born Again Christian') === 0) {
                $lookupName = 'Born Again';
            }
        }

        return self::selectOrOtherFormState($lookupName, $other, $options);
    }

    /**
     * @param  list<string>  $options
     * @return array{select: string, other: string}
     */
    private static function selectOrOtherFormState(string $lookupName, string $other, array $options): array
    {
        $lookupIsListed = $lookupName !== ''
            && in_array($lookupName, $options, true)
            && ! ResidentMemberFieldMap::isOtherChoice($lookupName);

        if ($lookupIsListed && $other === '') {
            return ['select' => $lookupName, 'other' => ''];
        }

        if ($other !== '' && in_array($other, $options, true) && ! ResidentMemberFieldMap::isOtherChoice($other)) {
            return ['select' => $other, 'other' => ''];
        }

        if (ResidentMemberFieldMap::isOtherChoice($lookupName) || $other !== '') {
            return ['select' => 'Other', 'other' => $other];
        }

        if ($lookupName !== '') {
            return in_array($lookupName, $options, true)
                ? ['select' => $lookupName, 'other' => '']
                : ['select' => 'Other', 'other' => $lookupName];
        }

        return ['select' => '', 'other' => ''];
    }

    private static function relationshipStatusFromResident(Resident $resident): string
    {
        $guard = app(DatabaseSchemaGuard::class);
        $table = $resident->getTable();

        if ($guard->columnExists($table, 'relationship_status')) {
            return (string) ($resident->getAttributes()['relationship_status'] ?? $resident->relationship_status ?? '');
        }

        if ($guard->columnExists($table, 'civil_status')) {
            return ResidentMemberFieldMap::relationshipStatusFromStored(
                trim((string) ($resident->getAttributes()['civil_status'] ?? ''))
            );
        }

        return (string) ($resident->relationship_status ?? '');
    }

    private static function monthlyIncomeFromResident(Resident $resident): string
    {
        $raw = trim((string) ($resident->getAttributes()['monthly_income'] ?? $resident->monthly_income ?? ''));

        return $raw === '' ? '' : ResidentMemberFieldMap::monthlyIncomeFromStored($raw);
    }

    private static function educationFromResident(Resident $resident): string
    {
        $guard = app(DatabaseSchemaGuard::class);
        $table = $resident->getTable();

        if ($guard->columnExists($table, 'education')) {
            return (string) ($resident->getAttributes()['education'] ?? $resident->education ?? '');
        }

        if ($guard->columnExists($table, 'educational_attainment')) {
            $raw = trim((string) ($resident->getAttributes()['educational_attainment'] ?? ''));

            return $raw === '' ? '' : ResidentMemberFieldMap::educationFromStored($raw);
        }

        return (string) ($resident->education ?? '');
    }

    private static function philhealthFromResident(Resident $resident): string
    {
        $guard = app(DatabaseSchemaGuard::class);
        $table = $resident->getTable();

        if ($guard->columnExists($table, 'philhealth')) {
            return (string) ($resident->getAttributes()['philhealth'] ?? $resident->philhealth ?? '');
        }

        if ($guard->columnExists($table, 'philhealth_number')) {
            return (string) ($resident->getAttributes()['philhealth_number'] ?? '');
        }

        return '';
    }

    private static function fpUserFromResident(Resident $resident): string
    {
        $guard = app(DatabaseSchemaGuard::class);
        $table = $resident->getTable();

        if ($guard->columnExists($table, 'fp_user')) {
            return (string) ($resident->getAttributes()['fp_user'] ?? $resident->fp_user ?? '');
        }

        if ($guard->columnExists($table, 'is_fp_user')) {
            $raw = $resident->getAttributes()['is_fp_user'] ?? null;
            if ($raw === null || $raw === '') {
                return '';
            }

            return ((int) $raw === 1 || $raw === true || $raw === '1') ? 'Yes' : 'No';
        }

        return (string) ($resident->fp_user ?? '');
    }

    /**
     * @return array{
     *     disability: list<string>,
     *     disability_others: string,
     *     medical_history: list<string>,
     *     medical_others: string
     * }
     */
    private static function healthWelfareFromResident(Resident $resident): array
    {
        $guard = app(DatabaseSchemaGuard::class);
        $table = $resident->getTable();

        $disability = [];
        $disabilityOthers = '';
        if ($guard->columnExists($table, 'disability') && is_array($resident->disability)) {
            $disability = array_values(array_map('strval', $resident->disability));
            $disabilityOthers = (string) ($resident->disability_others ?? '');
        } elseif (Schema::hasTable('disability_type')) {
            $resident->loadMissing('disabilityType');
            $row = $resident->disabilityType;
            if ($row !== null) {
                if ($row->no_disability) {
                    $disability[] = 'none';
                }
                if ($row->intellectual_disability) {
                    $disability[] = 'Intellectual Disability (ID)';
                }
                if ($row->mental_disability) {
                    $disability[] = 'Mental Disability (MD)';
                }
                if ($row->physical_disability) {
                    $disability[] = 'Physical Disability (PD)';
                }
                if ($row->other_disability) {
                    $disability[] = 'others';
                    $disabilityOthers = trim((string) ($row->other_disability_specify ?? ''));
                }
            }
        }

        $medical = [];
        $medicalOthers = '';
        if ($guard->columnExists($table, 'medical_history') && is_array($resident->medical_history)) {
            $medical = array_values(array_map('strval', $resident->medical_history));
            $medicalOthers = (string) ($resident->medical_others ?? '');
        } elseif (Schema::hasTable('medical_history')) {
            $resident->loadMissing('medicalHistoryEntry');
            $row = $resident->medicalHistoryEntry;
            if ($row !== null) {
                if ($row->no_medical_history) {
                    $medical[] = 'none';
                }
                if ($row->diabetes_mellitus) {
                    $medical[] = 'Diabetes Mellitus';
                }
                if ($row->heart_disease) {
                    $medical[] = 'Heart Disease';
                }
                if ($row->hypertension) {
                    $medical[] = 'Hypertension';
                }
                if ($row->kidney_disease) {
                    $medical[] = 'Kidney Disease';
                }
                if ($row->tuberculosis) {
                    $medical[] = 'Tuberculosis';
                }
                $specify = trim((string) ($row->other_medical_history ?? ''));
                if ($specify !== '') {
                    $medical[] = 'others';
                    $medicalOthers = $specify;
                }
            }
        }

        return [
            'disability' => $disability,
            'disability_others' => $disabilityOthers,
            'medical_history' => $medical,
            'medical_others' => $medicalOthers,
        ];
    }

    /**
     * @return array{id: string, householdNo: string, displayNo: string, houseHead: string, zone: string, street: string, members: int, male: int, female: int, source: 'db'}
     */
    public static function listRowFromModel(Household $household): array
    {
        $presentation = self::fromModel($household);
        $householdNo = (string) $presentation['householdNo'];
        [$male, $female] = self::sexCountsFromMembers($presentation['memberList'] ?? []);

        return [
            'id' => 'db-'.strtolower((string) $household->household_no),
            'householdNo' => $householdNo,
            'displayNo' => HouseholdNumber::forDisplay($householdNo),
            'houseHead' => (string) $presentation['houseHead'],
            'zone' => (string) $presentation['zone'],
            'street' => (string) $presentation['street'],
            'members' => (int) $presentation['members'],
            'male' => $male,
            'female' => $female,
            'source' => 'db',
        ];
    }

    /**
     * @param  array<string, mixed>  $demoHousehold
     * @return array{id: string, householdNo: string, displayNo: string, houseHead: string, zone: string, street: string, members: int, male: int, female: int, source: 'demo'}
     */
    public static function listRowFromDemo(array $demoHousehold): array
    {
        $no = (string) ($demoHousehold['householdNo'] ?? '');
        $memberList = is_array($demoHousehold['memberList'] ?? null) ? $demoHousehold['memberList'] : [];
        [$male, $female] = self::sexCountsFromMembers($memberList);

        return [
            'id' => 'demo-'.strtolower($no),
            'householdNo' => $no,
            'displayNo' => HouseholdNumber::forDisplay($no),
            'houseHead' => (string) ($demoHousehold['houseHead'] ?? '—'),
            'zone' => (string) ($demoHousehold['zone'] ?? ''),
            'street' => (string) ($demoHousehold['street'] ?? ''),
            'members' => (int) ($demoHousehold['members'] ?? count($memberList)),
            'male' => $male,
            'female' => $female,
            'source' => 'demo',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return array{0: int, 1: int}
     */
    private static function sexCountsFromMembers(array $members): array
    {
        $male = 0;
        $female = 0;

        foreach ($members as $member) {
            if (! is_array($member)) {
                continue;
            }
            $sex = trim((string) ($member['sex'] ?? ''));
            if (strcasecmp($sex, 'Male') === 0) {
                $male++;
            } elseif (strcasecmp($sex, 'Female') === 0) {
                $female++;
            }
        }

        return [$male, $female];
    }

    /**
     * @return array<string, mixed>
     */
    public static function memberFromModel(Resident $resident): array
    {
        DemoCatalog::ensureHouseholdHelpers();

        $birthday = $resident->birthday;
        $age = $birthday instanceof Carbon ? $birthday->age : null;

        $occupationState = self::occupationFormState($resident);
        $religionState = self::religionFormState($resident);
        $health = self::healthWelfareFromResident($resident);

        $data = [
            'id' => (string) $resident->member_no,
            'name' => self::fullName($resident),
            'relationship' => (string) $resident->relation,
            'age' => $age,
            'sex' => (string) $resident->sex,
            'occupation' => self::occupationLabel($resident),
            'occupation_select' => $occupationState['select'],
            'occupation_other' => $occupationState['other'],
            'last_name' => (string) $resident->last_name,
            'first_name' => (string) $resident->first_name,
            'middle_name' => (string) ($resident->middle_name ?? ''),
            'relation' => (string) $resident->relation,
            'birthday' => $birthday instanceof Carbon ? $birthday->format('Y-m-d') : (string) $birthday,
            'relationship_status' => self::relationshipStatusFromResident($resident),
            'monthly_income' => self::monthlyIncomeFromResident($resident),
            'religion' => self::religionLabel($resident),
            'religion_select' => $religionState['select'],
            'religion_other' => $religionState['other'],
            'education' => self::educationFromResident($resident),
            'philhealth' => self::philhealthFromResident($resident),
            'fp_user' => self::fpUserFromResident($resident),
            'disability' => $health['disability'],
            'disability_others' => $health['disability_others'],
            'medical_history' => $health['medical_history'],
            'medical_others' => $health['medical_others'],
        ];

        $birthHistory = ChildBirthHistoryService::presentationForResident($resident);
        if ($birthHistory !== null) {
            $data['birth_history'] = $birthHistory;
        }

        return $data;
    }

    public static function fullName(Resident $resident): string
    {
        $parts = array_filter([
            trim((string) $resident->first_name),
            trim((string) ($resident->middle_name ?? '')),
            trim((string) $resident->last_name),
        ], static fn (string $p): bool => $p !== '');

        return implode(' ', $parts);
    }
}
