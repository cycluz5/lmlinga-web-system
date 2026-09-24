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

class ChatbotHouseholdMemberInformationTest extends TestCase
{
    use RefreshDatabase;

    private bool $erdReady = false;

    public function test_verified_resident_can_view_same_household_member(): void
    {
        [$accountId, $selfId, $siblingId] = $this->seedTwoHouseholds();

        $html = $this->actingAsResidentSession($accountId, 'house.a@example.test')
            ->get(route('chatbot.household.members.show', ['member' => $siblingId]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Member Information', $html);
        $this->assertStringContainsString('Sibling A', $html);
        $this->assertStringContainsString('Personal Information', $html);
        $this->assertStringContainsString('Socio-Economic Details', $html);
        $this->assertStringContainsString('Health &amp; Welfare', $html);
        $this->assertStringNotContainsString('Health Summary Records', $html);
        $this->assertStringNotContainsString('Nutritional Status', $html);
        $this->assertStringNotContainsString(
            route('chatbot.household.members.child-care', ['member' => $siblingId]),
            $html
        );
        $this->assertStringNotContainsString(
            route('chatbot.household.members.risk-assessment', ['member' => $siblingId]),
            $html
        );
        $this->assertStringNotContainsString(
            route('chatbot.household.members.family-planning', ['member' => $siblingId]),
            $html
        );
        $this->assertStringNotContainsString(
            route('chatbot.household.members.maternal', ['member' => $siblingId]),
            $html
        );
        $this->assertStringNotContainsString('>Death</', $html);
        $this->assertStringNotContainsString('VIEW ONLY', $html);
        $this->assertStringNotContainsString('View only', $html);
        $this->assertStringNotContainsString('lml-chatbot-member-record__record-chevron', $html);
        $this->assertStringContainsString('aria-label="Back to Household Record"', $html);
        $this->assertStringContainsString(route('chatbot.household.information'), $html);
        $this->assertStringNotContainsString('household-profiling', $html);
        $this->assertStringNotContainsString('>Edit</', $html);
        $this->assertStringNotContainsString('Save', $html);
        $this->assertStringNotContainsString('Foreign B', $html);
        $this->assertStringNotContainsString('method="post"', $html);
    }

    public function test_verified_resident_can_view_self(): void
    {
        [$accountId, $selfId] = $this->seedTwoHouseholds();

        $this->actingAsResidentSession($accountId, 'house.a@example.test')
            ->get(route('chatbot.household.members.show', ['member' => $selfId]))
            ->assertOk()
            ->assertSee('Verified A', false);
    }

    public function test_cross_household_member_is_denied(): void
    {
        [$accountId, , , $foreignId] = $this->seedTwoHouseholds();

        $this->actingAsResidentSession($accountId, 'house.a@example.test')
            ->get(route('chatbot.household.members.show', ['member' => $foreignId]))
            ->assertNotFound();
    }

    public function test_invalid_member_identifier_is_denied(): void
    {
        [$accountId] = $this->seedTwoHouseholds();

        $this->actingAsResidentSession($accountId, 'house.a@example.test')
            ->get(route('chatbot.household.members.show', ['member' => 999999]))
            ->assertNotFound();
    }

    public function test_unverified_account_is_redirected_to_main(): void
    {
        $this->ensureErdSchema();

        $householdId = DB::table('households')->insertGetId([
            'household_no' => 'HH-700',
            'purok' => 'Zone 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $residentId = DB::table('residents')->insertGetId([
            'household_id' => $householdId,
            'first_name' => 'Pending',
            'middle_name' => '',
            'last_name' => 'User',
            'birthday' => '1991-02-02',
            'sex' => 'Male',
            'civil_status' => 'Single',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accountId = DB::table('resident_accounts')->insertGetId([
            'resident_id' => null,
            'first_name' => 'Pending',
            'middle_name' => '',
            'last_name' => 'User',
            'zone_purok' => '1',
            'email' => 'pending.member@example.test',
            'password' => bcrypt('SafePassw0rd!x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsResidentSession($accountId, 'pending.member@example.test')
            ->get(route('chatbot.household.members.show', ['member' => $residentId]))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_authenticated_but_unlinked_account_cannot_view_member(): void
    {
        [$accountId, $selfId] = $this->seedTwoHouseholds();

        DB::table('resident_accounts')
            ->where('account_id', $accountId)
            ->update(['resident_id' => null]);

        $this->actingAsResidentSession($accountId, 'house.a@example.test')
            ->get(route('chatbot.household.members.show', ['member' => $selfId]))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('chatbot.household.members.show', ['member' => 1]))
            ->assertRedirect(route('chatbot.login'));
    }

    public function test_household_record_view_record_links_to_member_route(): void
    {
        [$accountId, $selfId, $siblingId] = $this->seedTwoHouseholds();

        $html = $this->actingAsResidentSession($accountId, 'house.a@example.test')
            ->get(route('chatbot.household.information'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('chatbot.household.members.show', ['member' => $selfId]),
            $html
        );
        $this->assertStringContainsString(
            route('chatbot.household.members.show', ['member' => $siblingId]),
            $html
        );
    }

    public function test_soft_deleted_member_returns_not_found(): void
    {
        [$accountId, $selfId, $siblingId] = $this->seedTwoHouseholds();

        if (! Schema::hasColumn('residents', 'deleted_at')) {
            Schema::table('residents', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        DB::table('residents')->where('resident_id', $siblingId)->update([
            'deleted_at' => now(),
        ]);

        $this->actingAsResidentSession($accountId, 'house.a@example.test')
            ->get(route('chatbot.household.members.show', ['member' => $siblingId]))
            ->assertNotFound();

        $this->actingAsResidentSession($accountId, 'house.a@example.test')
            ->get(route('chatbot.household.members.show', ['member' => $selfId]))
            ->assertOk();
    }

    public function test_same_household_can_open_supported_health_modules(): void
    {
        [$accountId, $selfId, $siblingId] = $this->seedTwoHouseholds();

        foreach ([
            'chatbot.household.members.child-care',
            'chatbot.household.members.risk-assessment',
            'chatbot.household.members.family-planning',
            'chatbot.household.members.maternal',
        ] as $routeName) {
            $html = $this->actingAsResidentSession($accountId, 'house.a@example.test')
                ->get(route($routeName, ['member' => $siblingId]))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('aria-label="Back to Member Information"', $html);
            $this->assertStringContainsString(
                route('chatbot.household.members.show', ['member' => $siblingId]),
                $html
            );
            $this->assertStringNotContainsString('← Back to Member Information', $html);
            $this->assertStringNotContainsString('lml-chatbot-member-health__back-text', $html);
            $this->assertStringNotContainsString('household-profiling', $html);
            $this->assertStringNotContainsString('>Edit</', $html);
            $this->assertStringNotContainsString('>Save</', $html);
            $this->assertStringNotContainsString('>Delete</', $html);
            $this->assertStringNotContainsString('>Add Record</', $html);
            $this->assertStringNotContainsString('method="post"', $html);
            $this->assertStringNotContainsString('VIEW ONLY', $html);
        }

        $this->actingAsResidentSession($accountId, 'house.a@example.test')
            ->get(route('chatbot.household.members.child-care', ['member' => $selfId]))
            ->assertOk();
    }

    public function test_cross_household_health_modules_are_denied(): void
    {
        [$accountId, , , $foreignId] = $this->seedTwoHouseholds();

        foreach ([
            'chatbot.household.members.child-care',
            'chatbot.household.members.risk-assessment',
            'chatbot.household.members.family-planning',
            'chatbot.household.members.maternal',
        ] as $routeName) {
            $this->actingAsResidentSession($accountId, 'house.a@example.test')
                ->get(route($routeName, ['member' => $foreignId]))
                ->assertNotFound();
        }
    }

    public function test_invalid_member_health_modules_are_denied(): void
    {
        [$accountId] = $this->seedTwoHouseholds();

        $this->actingAsResidentSession($accountId, 'house.a@example.test')
            ->get(route('chatbot.household.members.child-care', ['member' => 999999]))
            ->assertNotFound();
    }

    public function test_guest_cannot_open_health_modules(): void
    {
        $this->get(route('chatbot.household.members.child-care', ['member' => 1]))
            ->assertRedirect(route('chatbot.login'));
    }

    public function test_unverified_account_cannot_open_health_modules(): void
    {
        $this->ensureErdSchema();

        $householdId = DB::table('households')->insertGetId([
            'household_no' => 'HH-701',
            'purok' => 'Zone 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $residentId = DB::table('residents')->insertGetId([
            'household_id' => $householdId,
            'first_name' => 'Pending',
            'middle_name' => '',
            'last_name' => 'Health',
            'birthday' => '1991-02-02',
            'sex' => 'Female',
            'civil_status' => 'Single',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accountId = DB::table('resident_accounts')->insertGetId([
            'resident_id' => null,
            'first_name' => 'Pending',
            'middle_name' => '',
            'last_name' => 'Health',
            'zone_purok' => '1',
            'email' => 'pending.health@example.test',
            'password' => bcrypt('SafePassw0rd!x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsResidentSession($accountId, 'pending.health@example.test')
            ->get(route('chatbot.household.members.child-care', ['member' => $residentId]))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_male_member_maternal_route_is_not_linked_and_returns_404(): void
    {
        [$accountId, $selfId] = $this->seedTwoHouseholds();

        $maleId = DB::table('residents')->insertGetId([
            'household_id' => DB::table('residents')->where('resident_id', $selfId)->value('household_id'),
            'first_name' => 'Brother',
            'middle_name' => '',
            'last_name' => 'A',
            'relation_to_household_head' => 'Son',
            'birthday' => '2012-04-04',
            'sex' => 'Male',
            'civil_status' => 'Single',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->actingAsResidentSession($accountId, 'house.a@example.test')
            ->get(route('chatbot.household.members.show', ['member' => $maleId]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            route('chatbot.household.members.maternal', ['member' => $maleId]),
            $html
        );

        $this->actingAsResidentSession($accountId, 'house.a@example.test')
            ->get(route('chatbot.household.members.maternal', ['member' => $maleId]))
            ->assertNotFound();
    }

    /**
     * @return array{0: int|string, 1: int|string, 2: int|string, 3: int|string}
     */
    private function seedTwoHouseholds(): array
    {
        $this->ensureErdSchema();

        $householdA = DB::table('households')->insertGetId([
            'household_no' => 'HH-801',
            'purok' => 'Zone 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $householdB = DB::table('households')->insertGetId([
            'household_no' => 'HH-802',
            'purok' => 'Zone 2',
            'created_at' => now(),
            'updated_at' => now(),
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
            'monthly_income' => '10000-19999',
            'educational_attainment' => 'College Graduate',
            'philhealth_number' => '123456789012',
            'is_fp_user' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $siblingId = DB::table('residents')->insertGetId([
            'household_id' => $householdA,
            'first_name' => 'Sibling',
            'middle_name' => '',
            'last_name' => 'A',
            'relation_to_household_head' => 'Daughter',
            'birthday' => '2015-05-05',
            'sex' => 'Female',
            'civil_status' => 'Single',
            'educational_attainment' => 'Elementary',
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
            'email' => 'house.a@example.test',
            'password' => bcrypt('SafePassw0rd!x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $requestId = DB::table('record_requests')->insertGetId([
            'account_id' => $accountId,
            'household_no_submitted' => 'HH-801',
            'zone_submitted' => '1',
            'relationship_submitted' => 'Self',
            'first_name_submitted' => 'Verified',
            'middle_name_submitted' => '',
            'last_name_submitted' => 'A',
            'mobile_number_submitted' => '09170000001',
            'email_submitted' => 'house.a@example.test',
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

        return [$accountId, $selfId, $siblingId, $foreignId];
    }

    private function ensureErdSchema(): void
    {
        if ($this->erdReady) {
            return;
        }

        ClientTestingErdSchema::ensure();

        // Prefer ERD clinical tables for resident read paths in these tests.
        Schema::dropIfExists('risk_assessments');
        Schema::dropIfExists('family_planning_visits');
        Schema::dropIfExists('child_immunizations');
        Schema::dropIfExists('school_immunizations');
        Schema::dropIfExists('child_nutritions');
        \App\Support\ChildNutritionErdMode::resetCachedState();
        \App\Support\RiskAssessmentErdMode::resetCachedState();
        \App\Support\FamilyPlanningErdMode::resetCachedState();
        \App\Support\MaternalCareErdMode::resetCachedState();
        \App\Support\RecordRequestErdMode::resetCachedState();

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
