<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Support\ResidentAuthenticator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class ChatbotHouseholdInformationMemberCardsTest extends TestCase
{
    use RefreshDatabase;

    private bool $erdReady = false;

    public function test_member_cards_show_real_personal_fields_and_empty_nutrition(): void
    {
        [$accountId, $selfId] = $this->seedVerifiedHousehold();

        $html = $this->actingAsResidentSession($accountId, 'cards.a@example.test')
            ->get(route('chatbot.household.information'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Verified A', $html);
        $this->assertStringContainsString('Head of Household', $html);
        $this->assertStringContainsString('January 1, 1990', $html);
        $this->assertStringContainsString('Married', $html);
        $this->assertStringContainsString('Teacher', $html);
        $this->assertStringContainsString('Female', $html);
        $this->assertStringContainsString('years old', $html);

        $this->assertStringContainsString('>Weight</', $html);
        $this->assertStringContainsString('>Height</', $html);
        $this->assertStringContainsString('Nutritional Status', $html);
        $this->assertStringContainsString('No record', $html);
        $this->assertStringNotContainsString('>N/A</', $html);
        $this->assertStringNotContainsString('11.5 kg', $html);
        $this->assertStringNotContainsString('87 cm', $html);

        $this->assertStringContainsString(
            route('chatbot.household.members.show', ['member' => $selfId]),
            $html
        );
        $this->assertStringNotContainsString('BMI', $html);
        $this->assertStringNotContainsString('Underweight', $html);
        $this->assertStringNotContainsString('Overweight', $html);
        $this->assertStringNotContainsString('Obese', $html);
        $this->assertFalse(Schema::hasColumn('timbang_records', 'bmi'));
    }

    public function test_member_cards_show_latest_timbang_records_weight_and_height(): void
    {
        [$accountId, $selfId] = $this->seedVerifiedHousehold();

        $this->insertTimbangRecord((int) $selfId, '2025-01-10', '50.00', '150.00');
        $this->insertTimbangRecord((int) $selfId, '2026-03-15', '12.50', '85.00');

        $html = $this->actingAsResidentSession($accountId, 'cards.a@example.test')
            ->get(route('chatbot.household.information'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('12.5 kg', $html);
        $this->assertStringContainsString('85 cm', $html);
        $this->assertStringNotContainsString('50 kg', $html);
        $this->assertStringNotContainsString('150 cm', $html);
        $this->assertStringContainsString('No record', $html);
        $this->assertStringNotContainsString('BMI', $html);
    }

    public function test_equal_measurement_dates_use_latest_timbang_id(): void
    {
        [$accountId, $selfId] = $this->seedVerifiedHousehold();

        $this->insertTimbangRecord((int) $selfId, '2026-03-15', '12.50', '85.00');
        $this->insertTimbangRecord((int) $selfId, '2026-03-15', '21.00', '111.00');

        $html = $this->actingAsResidentSession($accountId, 'cards.a@example.test')
            ->get(route('chatbot.household.information'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('21 kg', $html);
        $this->assertStringContainsString('111 cm', $html);
        $this->assertStringNotContainsString('12.5 kg', $html);
        $this->assertStringNotContainsString('85 cm', $html);
        $this->assertStringContainsString('No record', $html);
    }

    public function test_legacy_operation_timbang_measurements_do_not_drive_member_cards(): void
    {
        [$accountId] = $this->seedVerifiedHousehold();
        $this->ensureOperationTimbangTable();

        $selfId = DB::table('resident_accounts')
            ->where('email', 'cards.a@example.test')
            ->value('resident_id');
        $this->assertNotNull($selfId);

        DB::table('operation_timbang_measurements')->insert([
            'resident_id' => $selfId,
            'weighed_at' => '2026-03-15',
            'weight_kg' => 99.00,
            'height_cm' => 180.00,
            'muac_cm' => null,
            'remarks' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->actingAsResidentSession($accountId, 'cards.a@example.test')
            ->get(route('chatbot.household.information'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('99 kg', $html);
        $this->assertStringNotContainsString('180 cm', $html);
        $this->assertStringContainsString('No record', $html);
    }

    public function test_staff_shaped_timbang_record_appears_on_household_listing(): void
    {
        [$accountId, $selfId] = $this->seedVerifiedHousehold();
        $this->insertTimbangRecord((int) $selfId, '2026-09-01', '21.00', '111.00', [
            'muac_cm' => '14.5',
            'weight_for_age' => 'Underweight',
        ]);

        $html = $this->actingAsResidentSession($accountId, 'cards.a@example.test')
            ->get(route('chatbot.household.information'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('21 kg', $html);
        $this->assertStringContainsString('111 cm', $html);
        $this->assertStringContainsString('No record', $html);
        $this->assertStringNotContainsString('Underweight', $html);
        $this->assertStringNotContainsString('Overweight', $html);
        $this->assertStringNotContainsString('Obese', $html);
        $this->assertStringNotContainsString('BMI', $html);
        $this->assertFalse(Schema::hasColumn('timbang_records', 'bmi'));
    }

    public function test_cross_household_timbang_is_not_listed(): void
    {
        [$accountId, $selfId, $foreignId] = $this->seedVerifiedHousehold();
        $this->insertTimbangRecord((int) $selfId, '2026-09-01', '21.00', '111.00');
        $this->insertTimbangRecord((int) $foreignId, '2026-09-01', '99.00', '180.00');

        $html = $this->actingAsResidentSession($accountId, 'cards.a@example.test')
            ->get(route('chatbot.household.information'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('21 kg', $html);
        $this->assertStringNotContainsString('99 kg', $html);
        $this->assertStringNotContainsString('180 cm', $html);
        $this->assertStringNotContainsString('Foreign B', $html);
    }

    public function test_soft_deleted_member_timbang_is_not_listed(): void
    {
        [$accountId, $selfId] = $this->seedVerifiedHousehold();
        $householdId = DB::table('residents')->where('resident_id', $selfId)->value('household_id');
        $siblingId = DB::table('residents')->insertGetId([
            'household_id' => $householdId,
            'first_name' => 'Sibling',
            'middle_name' => '',
            'last_name' => 'A',
            'relation_to_household_head' => 'Daughter',
            'birthday' => '2015-05-05',
            'sex' => 'Female',
            'civil_status' => 'Single',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertTimbangRecord((int) $siblingId, '2026-09-01', '21.00', '111.00');

        if (! Schema::hasColumn('residents', 'deleted_at')) {
            Schema::table('residents', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        DB::table('residents')->where('resident_id', $siblingId)->update([
            'deleted_at' => now(),
        ]);

        $html = $this->actingAsResidentSession($accountId, 'cards.a@example.test')
            ->get(route('chatbot.household.information'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Sibling A', $html);
        $this->assertStringNotContainsString('21 kg', $html);
        $this->assertStringNotContainsString('111 cm', $html);
        $this->assertStringContainsString('No record', $html);
    }

    public function test_listing_loads_timbang_records_in_one_batch_query(): void
    {
        [$accountId, $selfId] = $this->seedVerifiedHousehold();
        $this->insertTimbangRecord((int) $selfId, '2026-09-01', '21.00', '111.00');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAsResidentSession($accountId, 'cards.a@example.test')
            ->get(route('chatbot.household.information'))
            ->assertOk();

        $timbangQueries = array_values(array_filter(
            DB::getQueryLog(),
            static function (array $query): bool {
                $sql = strtolower((string) ($query['query'] ?? ''));

                return str_contains($sql, 'timbang_records')
                    && str_contains($sql, 'resident_id')
                    && str_contains($sql, 'measurement_date')
                    && ! str_contains($sql, 'sqlite_master')
                    && ! str_contains($sql, 'pragma');
            }
        ));
        DB::disableQueryLog();

        $this->assertCount(1, $timbangQueries);
        $querySql = (string) ($timbangQueries[0]['query'] ?? '');
        $this->assertMatchesRegularExpression('/resident_id["`]?\s+in\s*\(/i', $querySql);
        $this->assertStringContainsString('measurement_date', $querySql);
        $this->assertStringContainsString('timbang_id', $querySql);
    }

    public function test_cross_household_member_is_not_listed(): void
    {
        [$accountId] = $this->seedVerifiedHousehold();

        $html = $this->actingAsResidentSession($accountId, 'cards.a@example.test')
            ->get(route('chatbot.household.information'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Foreign B', $html);
    }

    /**
     * @return array{0: int|string, 1: int|string, 2: int|string}
     */
    private function seedVerifiedHousehold(): array
    {
        $this->ensureErdSchema();

        $householdA = DB::table('households')->insertGetId([
            'household_no' => 'HH-901',
            'purok' => 'Zone 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $householdB = DB::table('households')->insertGetId([
            'household_no' => 'HH-902',
            'purok' => 'Zone 2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $occupationId = DB::table('occupation')->insertGetId([
            'occupation_name' => 'Teacher',
        ]);

        $selfId = DB::table('residents')->insertGetId([
            'household_id' => $householdA,
            'first_name' => 'Verified',
            'middle_name' => '',
            'last_name' => 'A',
            'relation_to_household_head' => 'Head',
            'birthday' => '1990-01-01',
            'sex' => 'Female',
            'civil_status' => 'Married',
            'occupation_id' => $occupationId,
            'educational_attainment' => 'College Graduate',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreignId = DB::table('residents')->insertGetId([
            'household_id' => $householdB,
            'first_name' => 'Foreign',
            'middle_name' => '',
            'last_name' => 'B',
            'relation_to_household_head' => 'Head',
            'birthday' => '1988-03-03',
            'sex' => 'Male',
            'civil_status' => 'Single',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accountId = DB::table('resident_accounts')->insertGetId([
            'resident_id' => $selfId,
            'first_name' => 'Verified',
            'middle_name' => '',
            'last_name' => 'A',
            'zone_purok' => '1',
            'email' => 'cards.a@example.test',
            'password' => bcrypt('SafePassw0rd!x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $requestId = DB::table('record_requests')->insertGetId([
            'account_id' => $accountId,
            'household_no_submitted' => 'HH-901',
            'zone_submitted' => '1',
            'relationship_submitted' => 'Self',
            'first_name_submitted' => 'Verified',
            'middle_name_submitted' => '',
            'last_name_submitted' => 'A',
            'mobile_number_submitted' => '09170000091',
            'email_submitted' => 'cards.a@example.test',
            'matched_resident_id' => $selfId,
            'status' => RecordRequest::STATUS_APPROVED,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('record_request_otps')->insert([
            'request_id' => $requestId,
            'channel' => 'sms',
            'code_hash' => bcrypt('123456'),
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$accountId, $selfId, $foreignId];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function insertTimbangRecord(
        int $residentId,
        string $measurementDate,
        mixed $weightKg,
        mixed $heightCm,
        array $extra = []
    ): int {
        return (int) DB::table('timbang_records')->insertGetId([
            'resident_id' => $residentId,
            'measurement_date' => $measurementDate,
            'weight_kg' => $weightKg,
            'height_cm' => $heightCm,
            'muac_cm' => $extra['muac_cm'] ?? null,
            'weight_for_age' => $extra['weight_for_age'] ?? null,
            'height_for_age' => $extra['height_for_age'] ?? null,
            'weight_for_height' => $extra['weight_for_height'] ?? null,
            'remarks' => $extra['remarks'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureOperationTimbangTable(): void
    {
        if (Schema::hasTable('operation_timbang_measurements')) {
            return;
        }

        Schema::create('operation_timbang_measurements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('resident_id');
            $table->date('weighed_at');
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_cm', 5, 2)->nullable();
            $table->decimal('muac_cm', 5, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    private function ensureErdSchema(): void
    {
        if ($this->erdReady) {
            return;
        }

        ClientTestingErdSchema::ensure();

        if (! Schema::hasColumn('resident_accounts', 'resident_id')) {
            Schema::table('resident_accounts', function (Blueprint $table): void {
                $table->unsignedBigInteger('resident_id')->nullable()->unique();
            });
        }

        if (! Schema::hasTable('record_request_otps')) {
            Schema::create('record_request_otps', function (Blueprint $table): void {
                $table->id('otp_id');
                $table->unsignedBigInteger('request_id');
                $table->string('channel', 20)->nullable();
                $table->string('code_hash');
                $table->string('destination_fingerprint')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('invalidated_at')->nullable();
                $table->unsignedInteger('attempt_count')->default(0);
                $table->unsignedInteger('resend_count')->default(0);
                $table->timestamps();
            });
        }

        $this->erdReady = true;
    }

    private function actingAsResidentSession(int|string $accountId, string $email): static
    {
        return $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $accountId,
            ResidentAuthenticator::SESSION_EMAIL => $email,
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ]);
    }
}
