<?php

namespace Tests\Support;

use App\Models\User;
use App\Support\RecordRequestErdMode;
use App\Support\ResidentAuthenticator;
use App\Support\StaffRole;
use App\Support\UserManagementErdMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * ERD-shaped fixtures for the resident household-linking flow
 * (request -> match -> OTP -> link -> household access). sqlite :memory: only.
 */
trait HouseholdLinkingFixtures
{
    protected function ensureLinkingSchema(): void
    {
        ClientTestingErdSchema::ensure();
        RecordRequestErdMode::resetCachedState();
        UserManagementErdMode::resetCachedState();

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
    }

    /**
     * @return array{household_id: int, resident_id: int}
     */
    protected function seedLinkableHousehold(
        string $householdNo = '121',
        string $first = 'Lina',
        string $middle = 'Cruz',
        string $last = 'Santos',
    ): array {
        $householdId = (int) DB::table('households')->insertGetId([
            'household_no' => $householdNo,
            'purok' => 'Zone 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $residentId = (int) DB::table('residents')->insertGetId([
            'household_id' => $householdId,
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'relation_to_household_head' => 'Head',
            'birthday' => '1990-05-05',
            'sex' => 'Female',
            'civil_status' => 'Married',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['household_id' => $householdId, 'resident_id' => $residentId];
    }

    /**
     * Accounts are email-verified by default (they completed SR-03 registration); pass false to model
     * an account created before email verification existed.
     */
    protected function createResidentAccount(
        string $email,
        string $first = 'Lina',
        string $middle = 'Cruz',
        string $last = 'Santos',
        bool $emailVerified = true,
    ): int {
        $row = [
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'zone_purok' => '1',
            'email' => $email,
            'password' => bcrypt('SafePassw0rd!x'),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('resident_accounts', 'email_verified_at')) {
            $row['email_verified_at'] = $emailVerified ? now() : null;
        }

        return (int) DB::table('resident_accounts')->insertGetId($row);
    }

    protected function actingAsResident(int $accountId, string $email): static
    {
        return $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $accountId,
            ResidentAuthenticator::SESSION_EMAIL => $email,
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    protected function requestPayload(string $email, array $overrides = []): array
    {
        return array_merge([
            'householdNo' => '121',
            'relationship' => 'Household Head',
            'firstName' => 'Lina',
            'middleName' => 'Cruz',
            'lastName' => 'Santos',
            'mobileNumber' => '09170000001',
            'emailAddress' => $email,
        ], $overrides);
    }

    /** Semaphore OTP endpoint faked; every send is recorded for destination assertions. */
    protected function fakeSms(): void
    {
        config([
            'services.semaphore.api_key' => 'test-only-key',
            'services.semaphore.sender_name' => 'LMLINGA',
        ]);
        Http::fake(['*/otp' => Http::response([['message_id' => 'test-msg', 'status' => 'Queued']], 200)]);
    }

    /**
     * @return list<array{number: string, code: string}>
     */
    protected function sentSms(): array
    {
        return Http::recorded(static fn (HttpRequest $request): bool => str_ends_with($request->url(), '/otp'))
            ->map(static fn (array $pair): array => [
                'number' => (string) ($pair[0]['number'] ?? ''),
                'code' => (string) ($pair[0]['code'] ?? ''),
            ])
            ->values()
            ->all();
    }

    protected function latestRequest(int $accountId): ?object
    {
        return DB::table('record_requests')->where('account_id', $accountId)->orderByDesc('request_id')->first();
    }

    protected function linkedResidentId(int $accountId): mixed
    {
        return DB::table('resident_accounts')->where('account_id', $accountId)->value('resident_id');
    }

    protected function seedErdStaff(string $username, string $role): User
    {
        $id = (int) DB::table('user_management')->insertGetId([
            'first_name' => 'Staff',
            'last_name' => ucfirst($role),
            'email' => $username.'@lamedalla.test',
            'username' => $username,
            'password' => bcrypt('password'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->findOrFail($id);
        $user->assignCurrentAppointment([
            'role' => $role,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);

        return $user->fresh(['currentAppointment']);
    }

    protected function loginStaff(User $user): void
    {
        $user = $user->fresh(['currentAppointment']);
        $this->actingAs($user);
        $this->alignSessionLoginWith($user);
        $user->syncUiRoleSession();
    }

    protected function seedErdAdmin(): User
    {
        return $this->seedErdStaff('linking.admin', StaffRole::ADMIN);
    }
}
