<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Support\StaffRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-28 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Free Deworming Program — August 30',
            'message' => 'Bring your children for deworming.',
            'date' => '2026-08-30',
            'time' => '08:00',
            'place' => 'Barangay Health Center',
            'audience_type' => 'all',
            'zone_coverage' => 'all',
        ], $overrides);
    }

    public function test_index_loads_persisted_announcement_rows(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        Announcement::factory()->create([
            'title' => 'Persisted Barangay Measles Drive',
            'message' => 'Bring immunization cards.',
            'event_date' => '2026-09-01',
            'posted_at' => now(),
        ]);

        $this->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('Persisted Barangay Measles Drive', false);
    }

    public function test_index_action_menu_wires_view_edit_and_delete_for_the_selected_row(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $announcement = Announcement::factory()->create([
            'title' => 'Action Menu Target Notice',
            'event_date' => '2026-09-01',
            'posted_at' => now(),
        ]);

        $html = $this->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('data-announce-action-toggle', false)
            ->assertSee('data-announce-action="view"', false)
            ->assertSee('data-announce-action="edit"', false)
            ->assertSee('data-announce-action="delete"', false)
            ->assertSee(route('announcements.show', $announcement, false), false)
            ->assertSee(route('announcements.edit', $announcement, false), false)
            ->assertSee(route('announcements.destroy', $announcement, false), false)
            ->getContent();

        $this->assertStringContainsString('name="_method"', $html);
        $this->assertStringContainsString('value="DELETE"', $html);
        $this->assertStringContainsString('name="_token"', $html);
    }

    public function test_view_returns_the_selected_announcement(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $selected = Announcement::factory()->create([
            'title' => 'Selected View Title',
            'message' => 'Selected view message body.',
            'place' => 'Zone 2 Covered Court',
            'event_date' => '2026-09-02',
            'posted_at' => now(),
        ]);
        Announcement::factory()->create([
            'title' => 'Other Announcement Title',
            'message' => 'Should not appear on the selected view.',
            'event_date' => '2026-09-03',
            'posted_at' => now(),
        ]);

        $this->get(route('announcements.show', $selected))
            ->assertOk()
            ->assertSee('Selected View Title', false)
            ->assertSee('Selected view message body.', false)
            ->assertSee('Zone 2 Covered Court', false)
            ->assertDontSee('Other Announcement Title', false);
    }

    public function test_edit_form_loads_selected_announcement_values(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $announcement = Announcement::factory()->create([
            'title' => 'Editable Nutrition Day',
            'message' => 'Bring feeding records.',
            'place' => 'Barangay Hall',
            'event_date' => '2026-09-04',
            'event_time' => '09:30:00',
            'posted_at' => now(),
        ]);

        $this->get(route('announcements.edit', $announcement))
            ->assertOk()
            ->assertSee('value="Editable Nutrition Day"', false)
            ->assertSee('Bring feeding records.', false)
            ->assertSee('value="2026-09-04"', false)
            ->assertSee('value="09:30"', false)
            ->assertSee('value="Barangay Hall"', false);
    }

    public function test_update_changes_the_existing_row_without_creating_a_duplicate(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $announcement = Announcement::factory()->create([
            'title' => 'Original Title',
            'message' => 'Original message.',
            'event_date' => '2026-09-05',
            'posted_by_name' => 'Original Poster',
            'posted_at' => now(),
        ]);

        $this->put(route('announcements.update', $announcement), $this->validPayload([
            'title' => 'Updated Title',
            'message' => 'Updated message.',
            'date' => '2026-09-10',
        ]))
            ->assertRedirect(route('announcements.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseCount('announcements', 1);
        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'title' => 'Updated Title',
            'message' => 'Updated message.',
            'posted_by_name' => 'Original Poster',
        ]);

        $announcement->refresh();
        $this->assertSame('2026-09-10', $announcement->event_date->toDateString());
    }

    public function test_update_rejects_a_past_date(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $announcement = Announcement::factory()->create([
            'title' => 'Keep This Title',
            'event_date' => '2026-09-06',
            'posted_at' => now(),
        ]);

        $this->from(route('announcements.edit', $announcement))
            ->put(route('announcements.update', $announcement), $this->validPayload([
                'date' => '2026-08-27',
            ]))
            ->assertRedirect(route('announcements.edit', $announcement))
            ->assertSessionHasErrors('date');

        $announcement->refresh();
        $this->assertSame('2026-09-06', $announcement->event_date->toDateString());
        $this->assertSame('Keep This Title', $announcement->title);
        $this->assertDatabaseCount('announcements', 1);
    }

    public function test_update_accepts_today(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $announcement = Announcement::factory()->create([
            'event_date' => '2026-09-07',
            'posted_at' => now(),
        ]);

        $this->put(route('announcements.update', $announcement), $this->validPayload([
            'date' => '2026-08-28',
        ]))->assertRedirect(route('announcements.index'));

        $announcement->refresh();
        $this->assertSame('2026-08-28', $announcement->event_date->toDateString());
    }

    public function test_update_accepts_a_future_date(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $announcement = Announcement::factory()->create([
            'event_date' => '2026-09-08',
            'posted_at' => now(),
        ]);

        $this->put(route('announcements.update', $announcement), $this->validPayload([
            'date' => '2026-09-15',
        ]))->assertRedirect(route('announcements.index'));

        $announcement->refresh();
        $this->assertSame('2026-09-15', $announcement->event_date->toDateString());
    }

    public function test_delete_removes_only_the_selected_row_and_redirects(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $keep = Announcement::factory()->create([
            'title' => 'Keep This Announcement',
            'event_date' => '2026-09-09',
            'posted_at' => now(),
        ]);
        $remove = Announcement::factory()->create([
            'title' => 'Remove This Announcement',
            'event_date' => '2026-09-10',
            'posted_at' => now(),
        ]);

        $this->delete(route('announcements.destroy', $remove))
            ->assertRedirect(route('announcements.index'))
            ->assertSessionHas('status', 'Announcement deleted successfully.');

        $this->assertDatabaseMissing('announcements', ['id' => $remove->id]);
        $this->assertDatabaseHas('announcements', [
            'id' => $keep->id,
            'title' => 'Keep This Announcement',
        ]);
        $this->assertDatabaseCount('announcements', 1);
    }

    public function test_guest_cannot_update_or_delete(): void
    {
        $announcement = Announcement::factory()->create([
            'title' => 'Protected Announcement',
            'event_date' => '2026-09-11',
            'posted_at' => now(),
        ]);

        $this->put(route('announcements.update', $announcement), $this->validPayload())
            ->assertRedirect(route('login'));

        $this->delete(route('announcements.destroy', $announcement))
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'title' => 'Protected Announcement',
        ]);
    }

    public function test_bhw_can_still_view_edit_update_and_delete(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $announcement = Announcement::factory()->create([
            'title' => 'BHW Managed Notice',
            'message' => 'BHW message.',
            'event_date' => '2026-09-12',
            'posted_at' => now(),
        ]);

        $this->get(route('announcements.show', $announcement))->assertOk();
        $this->get(route('announcements.edit', $announcement))->assertOk();

        $this->put(route('announcements.update', $announcement), $this->validPayload([
            'title' => 'BHW Updated Notice',
        ]))->assertRedirect(route('announcements.index'));

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'title' => 'BHW Updated Notice',
        ]);

        $this->delete(route('announcements.destroy', $announcement))
            ->assertRedirect(route('announcements.index'));

        $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);
    }

    public function test_missing_announcement_returns_404(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->get('/announcements/99999')->assertNotFound();
        $this->get('/announcements/99999/edit')->assertNotFound();
        $this->put('/announcements/99999', $this->validPayload())->assertNotFound();
        $this->delete('/announcements/99999')->assertNotFound();
    }
}
