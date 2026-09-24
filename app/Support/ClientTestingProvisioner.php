<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\DeathRequest;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent client-acceptance dataset for lmlinga_erd_reference.
 */
final class ClientTestingProvisioner
{
    private const STAFF_PASSWORD = 'LMLinga@2026';

    private const RESIDENT_ACCOUNT_PASSWORD = 'LMLinga@2026';

    private const HOUSEHOLD_REQUEST_ACCOUNT_EMAIL = 'juan.delacruz@example.local';

    /** @var list<string> */
    private array $messages = [];

    /**
     * @return list<string>
     */
    public function validateSchema(): array
    {
        return ClientTestingSchemaValidator::errors();
    }

    /**
     * Whether the maternal_care insert payload includes an explicit bmi value.
     * Used by schema validation to catch generated-column write attempts before live provision.
     */
    public static function wouldWriteMaternalCareBmi(): bool
    {
        return array_key_exists('bmi', self::maternalCareInsertPayload());
    }

    /**
     * @return array<string, mixed>
     */
    public static function maternalCareInsertPayload(): array
    {
        return self::maternalCareInsertPayloadForResident(0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function nutritionSupplementationInsertPayloads(): array
    {
        return [
            self::vitaminASupplementationPayload('6-11'),
            self::vitaminASupplementationPayload('12-59'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function vitaminASupplementationPayload(string $scenario): array
    {
        $ageGroup = $scenario === '6-11'
            ? NutritionSupplementationErdMode::ageGroup6to11Months()
            : NutritionSupplementationErdMode::ageGroup12to59Months();

        return NutritionSupplementationErdMode::normalizePayload([
            'supplement_type' => NutritionSupplementationErdMode::vitaminASupplementType(),
            'age_group' => $ageGroup,
            'dose_number' => 1,
            'date_given' => now()->toDateString(),
        ]);
    }

    public static function wouldWriteInvalidNutritionSupplementation(): bool
    {
        foreach (self::nutritionSupplementationInsertPayloads() as $payload) {
            if (! NutritionSupplementationErdMode::isWritablePayloadValid($payload)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function riskAssessmentScenarios(): array
    {
        return [
            [
                'first' => 'Juan',
                'last' => 'Dela Cruz',
                'height' => 170,
                'weight' => 68,
                'systolic' => 115,
                'diastolic' => 75,
                'tobacco' => 'Never',
                'alcohol' => 'Never',
                'activity' => 'Yes',
            ],
            [
                'first' => 'Elena',
                'last' => 'Villanueva',
                'height' => 158,
                'weight' => 72,
                'systolic' => 125,
                'diastolic' => 78,
                'tobacco' => 'Never',
                'alcohol' => 'Light (Occasional)',
                'activity' => 'No',
            ],
            [
                'first' => 'Grace',
                'last' => 'Navarro',
                'height' => 162,
                'weight' => 49,
                'systolic' => 110,
                'diastolic' => 70,
                'tobacco' => 'Current User',
                'alcohol' => 'Never',
                'activity' => 'Yes',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    public static function riskAssessmentInsertPayloadForScenario(array $scenario, int $userId): array
    {
        $payload = [
            'resident_id' => 0,
            'height_cm' => $scenario['height'],
            'weight_kg' => $scenario['weight'],
            'systolic_blood_pressure' => $scenario['systolic'],
            'diastolic_blood_pressure' => $scenario['diastolic'],
            'tobacco_vape_usage' => $scenario['tobacco'],
            'alcohol_intake' => $scenario['alcohol'],
            'dietary_habits' => $scenario['dietary'] ?? 'Yes',
            'physical_activity' => $scenario['activity'],
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (RiskAssessmentErdMode::requiresUserId()) {
            $payload['user_id'] = $userId;
        }

        if (RiskAssessmentErdMode::writesBloodPressureStatus()) {
            $payload['blood_pressure_status'] = RiskAssessmentErdMode::bloodPressureStatusLabel(
                $scenario['systolic'],
                $scenario['diastolic']
            );
        }

        return RiskAssessmentErdMode::filterWritablePayload(
            RiskAssessmentErdMode::normalizePayload($payload)
        );
    }

    public static function wouldWriteRiskAssessmentWithoutUserId(): bool
    {
        return ! self::riskAssessmentPayloadIncludesUserId();
    }

    public static function riskAssessmentPayloadIncludesUserId(): bool
    {
        if (! RiskAssessmentErdMode::requiresUserId()) {
            return true;
        }

        $payload = self::riskAssessmentInsertPayloadForScenario(self::riskAssessmentScenarios()[0], 1);

        return isset($payload['user_id']) && (int) $payload['user_id'] > 0;
    }

    public static function wouldWriteInvalidRiskAssessmentPayload(): bool
    {
        foreach (self::riskAssessmentScenarios() as $scenario) {
            $payload = self::riskAssessmentInsertPayloadForScenario($scenario, 1);
            if (! RiskAssessmentErdMode::isWritablePayloadValid($payload)) {
                return true;
            }
        }

        return false;
    }

    public static function wouldWriteGeneratedRiskAssessmentBloodPressureStatus(): bool
    {
        if (! RiskAssessmentErdMode::isBloodPressureStatusGenerated()) {
            return false;
        }

        $payload = self::riskAssessmentInsertPayloadForScenario(self::riskAssessmentScenarios()[0], 1);

        return array_key_exists('blood_pressure_status', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public static function recordRequestInsertPayload(int $accountId): array
    {
        return [
            'account_id' => $accountId,
            'household_no_submitted' => 'HH-001',
            'zone_submitted' => '1',
            'relationship_submitted' => 'Head',
            'first_name_submitted' => 'Juan',
            'middle_name_submitted' => 'M.',
            'last_name_submitted' => 'Dela Cruz',
            'mobile_number_submitted' => '09181234567',
            'email_submitted' => self::HOUSEHOLD_REQUEST_ACCOUNT_EMAIL,
            'submitter_ip' => '127.0.0.1',
            'matched_resident_id' => null,
            'status' => 'Approved',
            'decision_reason' => 'The submitted household information passed the required completeness and validation checks.',
            'evaluated_at' => now(),
            'approved_at' => now(),
            'created_at' => now()->subDays(3),
            'updated_at' => now(),
        ];
    }

    public static function recordRequestPayloadIncludesAccountId(): bool
    {
        if (! RecordRequestErdMode::requiresAccountId()) {
            return true;
        }

        $payload = self::recordRequestInsertPayload(1);

        return isset($payload['account_id']) && (int) $payload['account_id'] > 0;
    }

    public static function wouldWriteRecordRequestWithoutAccountId(): bool
    {
        return ! self::recordRequestPayloadIncludesAccountId();
    }

    public static function wouldWriteInvalidRecordRequestPayload(): bool
    {
        return ! RecordRequestErdMode::isWritablePayloadValid(self::recordRequestInsertPayload(1));
    }

    /**
     * @return array{messages: list<string>, counts: array<string, int>}
     */
    public function provision(bool $replaceDeathRecord = false): array
    {
        $this->messages = [];

        $errors = $this->validateSchema();
        if ($errors !== []) {
            throw new ClientTestingProvisionException($errors);
        }

        DB::transaction(function () use ($replaceDeathRecord): void {
            $staff = $this->provisionStaffAccounts();
            $this->provisionLookupTables();
            $this->provisionHouseholdsAndResidents();
            $this->provisionEnvironmentalSanitation($staff);
            $this->provisionClinicalRecords($staff);
            $this->provisionRecordRequest();
            $this->provisionAnnouncements($staff);
            $this->provisionDeathRecord($staff, $replaceDeathRecord);
        });

        return [
            'messages' => $this->messages,
            'counts' => $this->tableCounts(),
        ];
    }

    /**
     * @return array<string, User>
     */
    private function provisionStaffAccounts(): array
    {
        $accounts = [
            'admin' => [
                'first_name' => 'Maria',
                'middle_name' => 'Lopez',
                'last_name' => 'Santos',
                'username' => 'maria.santos',
                'email' => 'maria.santos@lamedalla.local',
                'role' => StaffRole::ADMIN,
                'zone' => 'Zone 1',
            ],
            'bhw' => [
                'first_name' => 'Angela',
                'middle_name' => 'Cruz',
                'last_name' => 'Reyes',
                'username' => 'angela.reyes',
                'email' => 'angela.reyes@lamedalla.local',
                'role' => StaffRole::BHW,
                'zone' => 'Zone 2',
            ],
            'bns' => [
                'first_name' => 'Camille',
                'middle_name' => 'Ramos',
                'last_name' => 'Mendoza',
                'username' => 'camille.mendoza',
                'email' => 'camille.mendoza@lamedalla.local',
                'role' => StaffRole::BNS,
                'zone' => 'Zone 3',
            ],
            'bspo' => [
                'first_name' => 'Roberto',
                'middle_name' => 'Diaz',
                'last_name' => 'Garcia',
                'username' => 'roberto.garcia',
                'email' => 'roberto.garcia@lamedalla.local',
                'role' => StaffRole::BSPO,
                'zone' => 'Zone 4',
            ],
        ];

        $created = [];

        foreach ($accounts as $key => $spec) {
            $user = User::query()->where('email', $spec['email'])->first();

            if ($user === null) {
                $user = new User;
                $user->fill([
                    'first_name' => $spec['first_name'],
                    'middle_name' => $spec['middle_name'],
                    'last_name' => $spec['last_name'],
                    'username' => $spec['username'],
                    'email' => $spec['email'],
                    'mobile_number' => '09171234567',
                    'password' => self::STAFF_PASSWORD,
                    'status' => StaffAccountStatus::ACTIVE,
                    'must_change_password' => false,
                ]);
                ErdStaffProfileDefaults::applyToUser($user, [
                    'purok_zone' => $spec['zone'],
                ]);
                $user->save();
                $this->messages[] = "Created staff: {$spec['email']}";
            } else {
                $this->messages[] = "Staff exists: {$spec['email']}";
            }

            $this->ensureCurrentAppointment($user, $spec['role'], $spec['zone']);
            $created[$key] = $user;
        }

        return $created;
    }

    private function ensureCurrentAppointment(User $user, string $role, string $zone): void
    {
        $userId = (int) $user->getKey();
        $storedRole = UserManagementErdMode::appointmentRoleForStorage($role);
        $appointmentKey = UserManagementErdMode::appointmentKeyName();
        $defaults = ErdStaffProfileDefaults::appointmentDefaults($zone);

        $open = DB::table('worker_appointments')
            ->where('user_id', $userId)
            ->whereNull('end_of_appointment')
            ->orderByDesc('date_appointed')
            ->first();

        if ($open === null) {
            DB::table('worker_appointments')->insert([
                'user_id' => $userId,
                'role' => $storedRole,
                'assigned_barangay' => $defaults['assigned_barangay'],
                'assigned_zone' => $defaults['assigned_zone'],
                'date_appointed' => $defaults['date_appointed'],
                'end_of_appointment' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('worker_appointments')
            ->where($appointmentKey, $open->{$appointmentKey})
            ->update([
                'role' => $storedRole,
                'assigned_barangay' => $defaults['assigned_barangay'],
                'assigned_zone' => $defaults['assigned_zone'],
                'date_appointed' => $defaults['date_appointed'],
                'updated_at' => now(),
            ]);
    }

    private function provisionLookupTables(): void
    {
        if (! Schema::hasTable('occupation')) {
            return;
        }

        if (DB::table('occupation')->count() === 0) {
            DB::table('occupation')->insert([
                ['occupation_name' => 'Farmer'],
                ['occupation_name' => 'Vendor'],
                ['occupation_name' => 'Government Employee'],
                ['occupation_name' => 'Student'],
                ['occupation_name' => 'Unemployed'],
            ]);
            $this->messages[] = 'Seeded occupation lookup rows.';
        }

        if (Schema::hasTable('religion') && DB::table('religion')->count() === 0) {
            DB::table('religion')->insert([
                ['religion_name' => 'Roman Catholic'],
                ['religion_name' => 'Iglesia ni Cristo'],
                ['religion_name' => 'Born Again Christian'],
            ]);
            $this->messages[] = 'Seeded religion lookup rows.';
        }
    }

    private function provisionHouseholdsAndResidents(): void
    {
        $occupationId = (int) (DB::table('occupation')->value('occupation_id') ?? 0);
        $religionId = (int) (DB::table('religion')->value('religion_id') ?? 0);

        foreach ($this->householdSpecs() as $spec) {
            $householdId = $this->ensureHousehold($spec);
            $this->ensureResidentsForHousehold($householdId, $spec, $occupationId, $religionId);
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function ensureHousehold(array $spec): int
    {
        $existingId = DB::table('households')->where('household_no', $spec['no'])->value('household_id');

        $payload = [
            'purok' => $spec['purok'],
            'latitude' => $spec['lat'],
            'longitude' => $spec['lng'],
            'household_type' => $spec['type'],
            'date_registered' => '2026-01-15',
            'updated_at' => now(),
        ];

        if ($existingId !== null) {
            DB::table('households')->where('household_id', $existingId)->update($payload);

            return (int) $existingId;
        }

        $payload['household_no'] = $spec['no'];
        $payload['created_at'] = now();

        $householdId = (int) DB::table('households')->insertGetId($payload);
        $this->messages[] = "Created household {$spec['no']}.";

        return $householdId;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function ensureResidentsForHousehold(int $householdId, array $spec, int $occupationId, int $religionId): void
    {
        foreach ($spec['members'] as $member) {
            $exists = DB::table('residents')
                ->where('household_id', $householdId)
                ->where('first_name', $member['first_name'])
                ->where('last_name', $member['last_name'])
                ->exists();

            if ($exists) {
                DB::table('residents')
                    ->where('household_id', $householdId)
                    ->where('first_name', $member['first_name'])
                    ->where('last_name', $member['last_name'])
                    ->update([
                        'birthday' => $member['birthday'],
                        'sex' => $member['sex'],
                        'is_fp_user' => $member['is_fp_user'] ?? 0,
                        'updated_at' => now(),
                    ]);

                continue;
            }

            $row = [
                'household_id' => $householdId,
                'last_name' => $member['last_name'],
                'first_name' => $member['first_name'],
                'middle_name' => $member['middle_name'] ?? null,
                'birthday' => $member['birthday'],
                'sex' => $member['sex'],
                'is_fp_user' => $member['is_fp_user'] ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('residents', 'relation_to_household_head')) {
                $row['relation_to_household_head'] = $member['relation'];
            } elseif (Schema::hasColumn('residents', 'relation')) {
                $row['relation'] = $member['relation'];
            }

            foreach ([
                'civil_status' => 'Married',
                'occupation_id' => $occupationId > 0 ? $occupationId : null,
                'religion_id' => $religionId > 0 ? $religionId : null,
                'educational_attainment' => 'High School Graduate',
                'monthly_income' => 'Below 5,000',
            ] as $column => $value) {
                if (Schema::hasColumn('residents', $column)) {
                    $row[$column] = $value;
                }
            }

            DB::table('residents')->insert($row);
        }
    }

    /**
     * @param  array<string, User>  $staff
     */
    private function provisionEnvironmentalSanitation(array $staff): void
    {
        if (! Schema::hasTable('environmental_sanitation')) {
            return;
        }

        $angelaId = (int) ($staff['bhw']->getKey() ?? 0);
        if ($angelaId <= 0) {
            return;
        }

        $profiles = [
            'HH-001' => DemoHouseholdWaterSupply::SANITARY_TOILET_TYPES[0],
            'HH-002' => DemoHouseholdWaterSupply::SANITARY_TOILET_TYPES[1],
            'HH-003' => DemoHouseholdWaterSupply::SANITARY_TOILET_TYPES[2],
            'HH-004' => 'open_pit_latrine',
            'HH-005' => DemoHouseholdWaterSupply::SANITARY_TOILET_TYPES[0],
        ];

        foreach ($profiles as $householdNo => $toiletType) {
            $householdId = DB::table('households')->where('household_no', $householdNo)->value('household_id');
            if (! $householdId) {
                continue;
            }

            if (DB::table('environmental_sanitation')->where('household_id', $householdId)->exists()) {
                DB::table('environmental_sanitation')
                    ->where('household_id', $householdId)
                    ->update([
                        'toilet_type' => $toiletType,
                        'updated_at' => now(),
                    ]);

                continue;
            }

            $row = [
                'household_id' => $householdId,
                'user_id' => $angelaId,
                'toilet_type' => $toiletType,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            foreach ([
                'water_supply_status' => 'Level II',
                'water_source_location' => 'Community faucet',
                'water_availability' => 1,
                'microbiological_validation_date' => '2026-06-01',
                'microbio_result' => 'Passed',
                'physico_chem_test_date' => '2026-06-01',
                'physico_chem_result' => 'Passed',
                'open_defecation_place' => 0,
                'shared_toilet' => 0,
                'sewage_disposal_method' => 'On-site Disposed',
            ] as $column => $value) {
                if (Schema::hasColumn('environmental_sanitation', $column)) {
                    $row[$column] = $value;
                }
            }

            DB::table('environmental_sanitation')->insert($row);
        }

        $this->messages[] = 'Ensured environmental_sanitation rows for client-testing households.';
    }

    private function provisionClinicalRecords(array $staff): void
    {
        $this->provisionMaternalCare();
        $this->provisionChildNutritionAndVitaminA();
        $this->provisionDewormingRecords();
        $this->provisionRiskAssessments($staff);
        $this->provisionFamilyPlanning();
    }

    private function provisionMaternalCare(): void
    {
        if (! Schema::hasTable('maternal_care')) {
            return;
        }

        $residentId = $this->residentId('Ana', 'Bautista');
        if ($residentId === null) {
            return;
        }

        if (DB::table('maternal_care')->where('resident_id', $residentId)->exists()) {
            return;
        }

        DB::table('maternal_care')->insert(self::maternalCareInsertPayloadForResident($residentId));
        $this->messages[] = 'Created maternal_care record for Ana Bautista (active pregnancy).';
    }

    /**
     * @return array<string, mixed>
     */
    public static function maternalCareInsertPayloadForResident(int $residentId): array
    {
        return MaternalCareErdMode::filterWritablePayload([
            'resident_id' => $residentId,
            'lmp_date' => now()->subMonths(4)->toDateString(),
            'gravida' => 1,
            'edd' => now()->addMonths(5)->toDateString(),
            'parity' => 0,
            'weight_kg' => 55.0,
            'height_cm' => 160.0,
            'bmi' => 21.5,
            'bp_systolic' => 110,
            'bp_diastolic' => 70,
            'pregnancy_status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function provisionChildNutritionAndVitaminA(): void
    {
        if (! ChildNutritionErdMode::isActive()) {
            return;
        }

        $this->ensureVitaminASupplementation('Sofia', 'Dela Cruz', '6-11');
        $this->ensureVitaminASupplementation('Lucia', 'Villanueva', '12-59');
    }

    private function ensureVitaminASupplementation(
        string $firstName,
        string $lastName,
        string $scenario
    ): void {
        $payload = self::vitaminASupplementationPayload($scenario);
        $supplementType = (string) $payload['supplement_type'];
        $ageGroup = (string) $payload['age_group'];
        $doseNumber = (int) $payload['dose_number'];
        $residentId = $this->residentId($firstName, $lastName);
        if ($residentId === null) {
            return;
        }

        $nutritionId = DB::table('child_nutrition')->where('resident_id', $residentId)->value('child_nutrition_id');
        if ($nutritionId === null) {
            $nutritionId = DB::table('child_nutrition')->insertGetId([
                'resident_id' => $residentId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $exists = DB::table('nutrition_supplementation')
            ->where('child_nutrition_id', $nutritionId)
            ->where('supplement_type', $supplementType)
            ->where('dose_number', $doseNumber)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('nutrition_supplementation')->insert([
            'child_nutrition_id' => $nutritionId,
            'supplement_type' => $supplementType,
            'age_group' => $ageGroup,
            'dose_number' => $doseNumber,
            'date_given' => $payload['date_given'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->messages[] = "Created Vitamin A supplementation ({$ageGroup}) for {$firstName} {$lastName}.";
    }

    private function provisionDewormingRecords(): void
    {
        if (! Schema::hasTable('deworming_records')) {
            return;
        }

        $year = (int) now()->year;
        $roundColumn = DewormingErdMode::roundColumn();
        $scenarios = [
            ['first' => 'Sofia', 'last' => 'Dela Cruz', 'deworming_round' => 1, 'se' => 'NHTS'],
            ['first' => 'Jose', 'last' => 'Bautista', 'deworming_round' => 1, 'se' => 'Non-NHTS'],
            ['first' => 'Jose', 'last' => 'Bautista', 'deworming_round' => 2, 'se' => 'Non-NHTS'],
        ];

        foreach ($scenarios as $scenario) {
            $residentId = $this->residentId($scenario['first'], $scenario['last']);
            if ($residentId === null) {
                continue;
            }

            $round = (int) $scenario['deworming_round'];

            $exists = DB::table('deworming_records')
                ->where('resident_id', $residentId)
                ->where('year', $year)
                ->where($roundColumn, $round)
                ->exists();

            if ($exists) {
                continue;
            }

            $row = [
                'resident_id' => $residentId,
                'year' => $year,
                $roundColumn => $round,
                'date_given' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (DewormingErdMode::hasSeStatusColumn()) {
                $row['se_status'] = $scenario['se'];
            }

            if (Schema::hasColumn('deworming_records', 'remarks')) {
                $row['remarks'] = HealthRecordsDeworming::REMARKS_NONE;
            }

            DB::table('deworming_records')->insert($row);
        }

        $this->messages[] = 'Ensured deworming_records for eligible children.';
    }

    /**
     * @param  array<string, User>  $staff
     */
    private function provisionRiskAssessments(array $staff): void
    {
        if (! Schema::hasTable('risk_assessment')) {
            return;
        }

        $userId = (int) ($staff['bhw']->getKey() ?? 0);
        if (RiskAssessmentErdMode::requiresUserId() && $userId <= 0) {
            return;
        }

        foreach (self::riskAssessmentScenarios() as $scenario) {
            $residentId = $this->residentId($scenario['first'], $scenario['last']);
            if ($residentId === null) {
                continue;
            }

            if (DB::table('risk_assessment')->where('resident_id', $residentId)->exists()) {
                continue;
            }

            $row = self::riskAssessmentInsertPayloadForScenario($scenario, $userId);
            $row['resident_id'] = $residentId;

            $insert = [];
            foreach ($row as $column => $value) {
                if (Schema::hasColumn('risk_assessment', $column)) {
                    $insert[$column] = $value;
                }
            }

            DB::table('risk_assessment')->insert(AtRestRecord::sealRow('risk_assessment', $insert));
        }

        $this->messages[] = 'Ensured risk_assessment rows across multiple zones.';
    }

    private function provisionFamilyPlanning(): void
    {
        if (! Schema::hasTable('family_planning')) {
            return;
        }

        $residentId = $this->residentId('Isabel', 'Villanueva');
        if ($residentId === null) {
            return;
        }

        $dates = [
            now()->subMonths(8)->toDateString(),
            now()->subMonths(2)->toDateString(),
        ];

        foreach ($dates as $visitDate) {
            $exists = DB::table('family_planning')
                ->where('resident_id', $residentId)
                ->where('visitation_date', $visitDate)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('family_planning')->insert([
                'resident_id' => $residentId,
                'visitation_date' => $visitDate,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->messages[] = 'Ensured family_planning visits for Isabel Villanueva.';
    }

    private function provisionRecordRequest(): void
    {
        if (! Schema::hasTable('record_requests')) {
            return;
        }

        $exists = DB::table('record_requests')
            ->where('first_name_submitted', 'Juan')
            ->where('last_name_submitted', 'Dela Cruz')
            ->where('household_no_submitted', 'HH-001')
            ->exists();

        if ($exists) {
            return;
        }

        $account = $this->ensureHouseholdRequestResidentAccount();
        $accountId = $account !== null ? (int) $account->getKey() : null;

        if (RecordRequestErdMode::requiresAccountId() && ($accountId === null || $accountId <= 0)) {
            return;
        }

        $matchedResidentId = $this->residentId('Juan', 'Dela Cruz');
        $payload = self::recordRequestInsertPayload((int) ($accountId ?? 0));
        $payload['matched_resident_id'] = $matchedResidentId;

        $insert = [];
        foreach ($payload as $column => $value) {
            if (Schema::hasColumn('record_requests', $column)) {
                $insert[$column] = $value;
            }
        }

        DB::table('record_requests')->insert($insert);
        $this->messages[] = 'Created record_requests row for Juan Dela Cruz (HH-001).';
    }

    private function ensureHouseholdRequestResidentAccount(): ?ResidentAccount
    {
        if (! Schema::hasTable('resident_accounts')) {
            return null;
        }

        $account = ResidentAccount::query()
            ->where('email', self::HOUSEHOLD_REQUEST_ACCOUNT_EMAIL)
            ->first();

        if ($account !== null) {
            return $account;
        }

        $account = new ResidentAccount;
        $account->fill([
            'first_name' => 'Juan',
            'middle_name' => 'M.',
            'last_name' => 'Dela Cruz',
            'zone' => '1',
            'email' => self::HOUSEHOLD_REQUEST_ACCOUNT_EMAIL,
            'password' => self::RESIDENT_ACCOUNT_PASSWORD,
        ]);
        $account->save();

        $this->messages[] = 'Created resident account for Juan Dela Cruz (HH-001 request).';

        return $account;
    }

    /**
     * @param  array<string, User>  $staff
     */
    private function provisionAnnouncements(array $staff): void
    {
        if (! Schema::hasTable('announcements')) {
            return;
        }

        $admin = $staff['admin'];
        $adminId = (int) $admin->getKey();

        $announcements = [
            [
                'title' => 'Client Testing — Recent Barangay Assembly',
                'message' => 'Recent client-testing announcement for acceptance review.',
                'event_date' => now()->subDays(2)->toDateString(),
                'event_time' => '09:00',
                'place' => 'Barangay Hall',
            ],
            [
                'title' => 'Client Testing — Upcoming Immunization Day',
                'message' => 'Upcoming client-testing announcement for acceptance review.',
                'event_date' => now()->addDays(14)->toDateString(),
                'event_time' => '08:00',
                'place' => 'Health Center',
            ],
        ];

        foreach ($announcements as $spec) {
            if (Announcement::query()->where('title', $spec['title'])->exists()) {
                continue;
            }

            Announcement::query()->create([
                'title' => $spec['title'],
                'message' => $spec['message'],
                'event_date' => $spec['event_date'],
                'event_time' => $spec['event_time'],
                'place' => $spec['place'],
                'target_group' => Announcement::TARGET_ALL,
                'age_presets' => null,
                'age_min_months' => null,
                'age_max_months' => null,
                'zone_mode' => Announcement::ZONE_ALL,
                'zones' => null,
                'audience_label' => 'All Residents',
                'estimated_reach' => 0,
                'posted_by_user_id' => $adminId > 0 ? $adminId : null,
                'posted_by_name' => trim("{$admin->first_name} {$admin->last_name}"),
                'posted_by_role' => 'Admin',
                'posted_at' => now(),
            ]);
        }

        $this->messages[] = 'Ensured client-testing announcements.';
    }

    /**
     * @param  array<string, User>  $staff
     */
    private function provisionDeathRecord(array $staff, bool $replace): void
    {
        if (! Schema::hasTable('death_records')) {
            return;
        }

        $residentId = $this->residentId('Ramon', 'Bautista');
        if ($residentId === null) {
            return;
        }

        $existing = DeathRequest::query()->where('resident_id', $residentId)->first();

        if ($existing !== null && ! $replace) {
            $this->messages[] = 'Death record for Ramon Bautista already exists.';

            return;
        }

        if ($existing !== null && $replace) {
            DeathCertificateStorage::deleteStored($existing);
            $existing->delete();
        }

        $bhw = $staff['bhw'];
        Auth::login($bhw);

        $certificatePath = storage_path('app/client-testing/death-certificate-placeholder.pdf');
        if (! is_file($certificatePath)) {
            @mkdir(dirname($certificatePath), 0777, true);
            file_put_contents($certificatePath, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        }

        $upload = new UploadedFile(
            $certificatePath,
            'DC-2026-0001.pdf',
            'application/pdf',
            null,
            true
        );

        $resident = Resident::query()->findOrFail($residentId);

        app(DeathRecordService::class)->submit(
            [
                'householdNo' => 'HH-003',
                'zone' => 'Zone 3',
                'purok' => '3',
                'displayNo' => 'HH-003',
                'address' => 'Purok 3, La Medalla, Iriga City',
            ],
            [
                'id' => (string) $residentId,
                'name' => 'Ramon Bautista',
                'sex' => 'Male',
                'age' => 41,
            ],
            [
                'cause_of_death' => 'Cardiorespiratory Arrest',
                'date_of_death' => '2026-08-15',
                'registry_no' => 'DC-2026-0001',
            ],
            $upload,
            $resident
        );

        Auth::logout();
        $this->messages[] = 'Submitted pending death record for Ramon Bautista (DC-2026-0001).';
    }

    private function residentId(string $firstName, string $lastName): ?int
    {
        $id = DB::table('residents')
            ->where('first_name', $firstName)
            ->where('last_name', $lastName)
            ->value('resident_id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function householdSpecs(): array
    {
        $fiftyNineMonths = now()->subMonths(59)->toDateString();
        $sixtyMonths = now()->subMonths(60)->toDateString();

        return [
            [
                'no' => 'HH-001',
                'purok' => '1',
                'type' => 'NHTS',
                'lat' => 13.38110000,
                'lng' => 123.43060000,
                'members' => [
                    ['first_name' => 'Juan', 'middle_name' => 'M.', 'last_name' => 'Dela Cruz', 'relation' => 'Head', 'sex' => 'Male', 'birthday' => '1985-03-12', 'is_fp_user' => 0],
                    ['first_name' => 'Maria', 'middle_name' => 'L.', 'last_name' => 'Dela Cruz', 'relation' => 'Spouse', 'sex' => 'Female', 'birthday' => '1988-07-20', 'is_fp_user' => 0],
                    ['first_name' => 'Miguel', 'middle_name' => 'R.', 'last_name' => 'Dela Cruz', 'relation' => 'Son', 'sex' => 'Male', 'birthday' => '2018-05-10', 'is_fp_user' => 0],
                    ['first_name' => 'Sofia', 'middle_name' => 'A.', 'last_name' => 'Dela Cruz', 'relation' => 'Daughter', 'sex' => 'Female', 'birthday' => now()->subMonths(8)->toDateString(), 'is_fp_user' => 0],
                    ['first_name' => 'Antonio', 'middle_name' => 'P.', 'last_name' => 'Dela Cruz', 'relation' => 'Son', 'sex' => 'Male', 'birthday' => '2020-11-03', 'is_fp_user' => 0],
                    ['first_name' => 'Liza', 'middle_name' => 'M.', 'last_name' => 'Dela Cruz', 'relation' => 'Daughter', 'sex' => 'Female', 'birthday' => '2022-04-18', 'is_fp_user' => 0],
                ],
            ],
            [
                'no' => 'HH-002',
                'purok' => '2',
                'type' => 'NHTS',
                'lat' => 13.38200000,
                'lng' => 123.43150000,
                'members' => [
                    ['first_name' => 'Elena', 'middle_name' => 'R.', 'last_name' => 'Villanueva', 'relation' => 'Head', 'sex' => 'Female', 'birthday' => '1984-01-18', 'is_fp_user' => 0],
                    ['first_name' => 'Marco', 'middle_name' => 'D.', 'last_name' => 'Villanueva', 'relation' => 'Spouse', 'sex' => 'Male', 'birthday' => '1982-09-05', 'is_fp_user' => 0],
                    ['first_name' => 'Isabel', 'middle_name' => 'C.', 'last_name' => 'Villanueva', 'relation' => 'Daughter', 'sex' => 'Female', 'birthday' => '1995-06-22', 'is_fp_user' => 1],
                    ['first_name' => 'Lucia', 'middle_name' => 'F.', 'last_name' => 'Villanueva', 'relation' => 'Daughter', 'sex' => 'Female', 'birthday' => now()->subMonths(14)->toDateString(), 'is_fp_user' => 0],
                ],
            ],
            [
                'no' => 'HH-003',
                'purok' => '3',
                'type' => 'Non-NHTS',
                'lat' => 13.38300000,
                'lng' => 123.43250000,
                'members' => [
                    ['first_name' => 'Ramon', 'middle_name' => 'S.', 'last_name' => 'Bautista', 'relation' => 'Head', 'sex' => 'Male', 'birthday' => '1985-06-14', 'is_fp_user' => 0],
                    ['first_name' => 'Corazon', 'middle_name' => 'L.', 'last_name' => 'Bautista', 'relation' => 'Spouse', 'sex' => 'Female', 'birthday' => '1987-02-08', 'is_fp_user' => 0],
                    ['first_name' => 'Ana', 'middle_name' => 'M.', 'last_name' => 'Bautista', 'relation' => 'Daughter', 'sex' => 'Female', 'birthday' => '2008-02-14', 'is_fp_user' => 0],
                    ['first_name' => 'Paolo', 'middle_name' => 'J.', 'last_name' => 'Bautista', 'relation' => 'Son', 'sex' => 'Male', 'birthday' => '2021-03-20', 'is_fp_user' => 0],
                    ['first_name' => 'Jose', 'middle_name' => 'R.', 'last_name' => 'Bautista', 'relation' => 'Son', 'sex' => 'Male', 'birthday' => $fiftyNineMonths, 'is_fp_user' => 0],
                ],
            ],
            [
                'no' => 'HH-004',
                'purok' => '4',
                'type' => 'Non-NHTS',
                'lat' => 13.38400000,
                'lng' => 123.43350000,
                'members' => [
                    ['first_name' => 'Grace', 'middle_name' => 'P.', 'last_name' => 'Navarro', 'relation' => 'Head', 'sex' => 'Female', 'birthday' => '1983-11-30', 'is_fp_user' => 0],
                    ['first_name' => 'Daniel', 'middle_name' => 'T.', 'last_name' => 'Navarro', 'relation' => 'Spouse', 'sex' => 'Male', 'birthday' => '1981-04-12', 'is_fp_user' => 0],
                    ['first_name' => 'Hannah', 'middle_name' => 'G.', 'last_name' => 'Navarro', 'relation' => 'Daughter', 'sex' => 'Female', 'birthday' => '2016-08-25', 'is_fp_user' => 0],
                    ['first_name' => 'Nico', 'middle_name' => 'B.', 'last_name' => 'Navarro', 'relation' => 'Son', 'sex' => 'Male', 'birthday' => $sixtyMonths, 'is_fp_user' => 0],
                ],
            ],
            [
                'no' => 'HH-005',
                'purok' => '5',
                'type' => 'NHTS',
                'lat' => 0,
                'lng' => 0,
                'members' => [
                    ['first_name' => 'Pedro', 'middle_name' => 'L.', 'last_name' => 'Castillo', 'relation' => 'Head', 'sex' => 'Male', 'birthday' => '1980-12-01', 'is_fp_user' => 0],
                    ['first_name' => 'Rosa', 'middle_name' => 'V.', 'last_name' => 'Castillo', 'relation' => 'Spouse', 'sex' => 'Female', 'birthday' => '1982-05-19', 'is_fp_user' => 0],
                    ['first_name' => 'Carlo', 'middle_name' => 'E.', 'last_name' => 'Castillo', 'relation' => 'Son', 'sex' => 'Male', 'birthday' => '2019-07-07', 'is_fp_user' => 0],
                    ['first_name' => 'Mia', 'middle_name' => 'S.', 'last_name' => 'Castillo', 'relation' => 'Daughter', 'sex' => 'Female', 'birthday' => now()->subMonths(4)->toDateString(), 'is_fp_user' => 0],
                ],
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        $tables = [
            'user_management',
            'worker_appointments',
            'households',
            'residents',
            'death_records',
            'maternal_care',
            'environmental_sanitation',
            'child_nutrition',
            'nutrition_supplementation',
            'deworming_records',
            'risk_assessment',
            'family_planning',
            'record_requests',
            'announcements',
            'occupation',
            'religion',
        ];

        $counts = [];
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = (int) DB::table($table)->count();
            }
        }

        return $counts;
    }
}
