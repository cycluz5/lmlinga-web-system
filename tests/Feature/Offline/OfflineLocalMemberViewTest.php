<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineLocalMemberViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_local_member_id_renders_view_shell_without_health_writes(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $household = Household::factory()->create(['household_no' => 'HH-121']);

        $html = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-121',
            'memberId' => 'MB-L-abc12',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-offline-local-member="1"', $html);
        $this->assertStringContainsString('data-offline-local-field="philhealth"', $html);
        $this->assertStringContainsString('Waiting to sync', $html);
        $this->assertStringContainsString('data-hh-nav="edit-member"', $html);
        $this->assertStringContainsString('data-hh-nav="health-record"', $html);
        $this->assertStringContainsString((string) $household->household_no, $html);
    }

    public function test_queued_local_member_edit_and_child_immunization_render_offline_shells(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        Household::factory()->create(['household_no' => 'HH-121']);

        $edit = $this->get(route('household-profiling.members.edit', [
            'householdNo' => 'HH-121',
            'memberId' => 'MB-L-abc12',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-offline-operation="RESIDENT_UPDATE"', $edit);
        $this->assertStringContainsString('data-offline-parent-member-no="MB-L-abc12"', $edit);
        $this->assertStringContainsString('data-offline-local-field="name"', $edit);
        $this->assertStringContainsString('data-mode="edit"', $edit);
        $this->assertStringContainsString('data-member-id="MB-L-abc12"', $edit);
        $this->assertStringContainsString('name="last_name"', $edit);
        $this->assertStringContainsString('Queued member', $edit);
        $this->assertStringNotContainsString('value="Ricky"', $edit);
        $this->assertStringContainsString('Editing', $edit);

        $imm = $this->get(route('household-profiling.members.child-immunization', [
            'householdNo' => 'HH-121',
            'memberId' => 'MB-L-abc12',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-offline-operation="HEALTH_SERVICE_WRITE"', $imm);
        $this->assertStringContainsString('lml-child-imm', $imm);
    }

    public function test_local_member_put_is_not_a_server_update_route(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        Household::factory()->create(['household_no' => '999']);

        $this->call('PUT', '/household-profiling/999/members/MB-L-331929318FDA')
            ->assertStatus(405);

        $edit = $this->get(route('household-profiling.members.edit', [
            'householdNo' => '999',
            'memberId' => 'MB-L-331929318FDA',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-offline-operation="RESIDENT_UPDATE"', $edit);
        $this->assertStringContainsString('data-offline-parent-member-no="MB-L-331929318FDA"', $edit);
        $this->assertStringContainsString('data-offline-local-member="1"', $edit);
        $this->assertStringNotContainsString('data-offline-parent-resident-id', $edit);
        $this->assertStringNotContainsString('name="_method"', $edit);
        $this->assertStringContainsString(
            '/household-profiling/999/members/MB-L-331929318FDA/edit',
            $edit,
        );
    }
}
