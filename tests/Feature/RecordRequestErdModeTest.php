<?php

namespace Tests\Feature;

use App\Support\ClientTestingProvisioner;
use App\Support\RecordRequestErdMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class RecordRequestErdModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_requires_account_id_for_record_requests(): void
    {
        ClientTestingErdSchema::ensure();

        $this->assertTrue(RecordRequestErdMode::requiresAccountId());
        $this->assertTrue(ClientTestingProvisioner::recordRequestPayloadIncludesAccountId());
        $this->assertFalse(ClientTestingProvisioner::wouldWriteRecordRequestWithoutAccountId());
        $this->assertFalse(ClientTestingProvisioner::wouldWriteInvalidRecordRequestPayload());
    }

    public function test_provisioner_creates_deterministic_resident_account_and_links_request(): void
    {
        ClientTestingErdSchema::ensure();

        (new ClientTestingProvisioner)->provision();

        $accountId = (int) DB::table('resident_accounts')
            ->where('email', 'juan.delacruz@example.local')
            ->value('account_id');

        $this->assertGreaterThan(0, $accountId);

        $request = DB::table('record_requests')
            ->where('first_name_submitted', 'Juan')
            ->where('last_name_submitted', 'Dela Cruz')
            ->where('household_no_submitted', 'HH-001')
            ->first();

        $this->assertNotNull($request);
        $this->assertSame($accountId, (int) $request->account_id);
        $this->assertSame('juan.delacruz@example.local', $request->email_submitted);

        $residentId = DB::table('residents')
            ->where('first_name', 'Juan')
            ->where('last_name', 'Dela Cruz')
            ->value('resident_id');

        $this->assertSame((int) $residentId, (int) $request->matched_resident_id);
    }

    public function test_reprovision_remains_idempotent_for_account_and_request(): void
    {
        ClientTestingErdSchema::ensure();

        $provisioner = new ClientTestingProvisioner;
        $provisioner->provision();

        $accountCount = DB::table('resident_accounts')->count();
        $requestCount = DB::table('record_requests')->count();

        $provisioner->provision();

        $this->assertSame($accountCount, DB::table('resident_accounts')->count());
        $this->assertSame($requestCount, DB::table('record_requests')->count());
        $this->assertSame(1, $accountCount);
        $this->assertSame(1, $requestCount);
    }
}
