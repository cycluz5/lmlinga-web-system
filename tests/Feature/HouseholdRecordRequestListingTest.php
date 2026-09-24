<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\RecordRequest;
use App\Support\HouseholdRecordRequestPresenter;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ErdRecordRequestsSchema;
use Tests\TestCase;

class HouseholdRecordRequestListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ErdRecordRequestsSchema::ensure();
    }

    public function test_empty_database_shows_empty_state_without_demo_names(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('household-requests.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-has-records="0"', $html);
        $this->assertStringContainsString('No household record access requests are available.', $html);
        $this->assertStringNotContainsString('Kristine Mendoza Reyes', $html);
        $this->assertStringNotContainsString('res-001', $html);
    }

    public function test_record_request_appears_in_listing(): void
    {
        $record = RecordRequest::factory()->create([
            'first_name_submitted' => 'Maria',
            'middle_name_submitted' => 'L',
            'last_name_submitted' => 'Santos',
            'zone_submitted' => 'Zone 1',
            'status' => 'Approved',
        ]);

        $publicId = HouseholdRecordRequestPresenter::publicId((int) $record->getKey());

        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('household-requests.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-has-records="1"', $html);
        $this->assertStringContainsString('Maria L Santos', $html);
        $this->assertStringContainsString($publicId, $html);
    }

    public function test_detail_view_loads_by_public_id(): void
    {
        $record = RecordRequest::factory()->create([
            'first_name_submitted' => 'Ana',
            'middle_name_submitted' => '',
            'last_name_submitted' => 'Cruz',
            'status' => 'Approved',
        ]);

        $publicId = HouseholdRecordRequestPresenter::publicId((int) $record->getKey());

        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('household-requests.view', ['id' => $publicId]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Ana Cruz', $html);
        $this->assertStringContainsString('Automatic verification result', $html);
    }

    public function test_presenter_maps_erd_status_values(): void
    {
        $record = RecordRequest::factory()->rejected()->create();

        $presented = HouseholdRecordRequestPresenter::present($record);

        $this->assertSame('Rejected', $presented['status']);
        $this->assertNotEmpty($presented['decision_reason']);
    }
}
