<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use App\Support\Offline\OfflineFieldHasher;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineClientQueueContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_exposes_actor_and_sync_urls_without_durable_csrf(): void
    {
        $user = $this->actingAsStaff(StaffRole::BHW);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-offline-actor-id="'.$user->getKey().'"', $html);
        $this->assertStringContainsString('data-offline-actor-username="'.e((string) $user->username).'"', $html);
        $this->assertStringContainsString('data-offline-sync-url="'.e(route('offline.sync')).'"', $html);
        $this->assertStringContainsString('data-offline-status-url="'.e(route('offline.status')).'"', $html);
        $this->assertStringContainsString('data-lml-offline-pending', $html);
        $this->assertMatchesRegularExpression('/data-lml-offline-pending[\s\S]*?\bhidden\b/', $html);
        $this->assertStringNotContainsString('data-offline-csrf', $html);
        $this->assertStringNotContainsString('LMLINGA_AT_REST_KEY', $html);
        $this->assertStringNotContainsString('APP_KEY', $html);
    }

    public function test_guest_login_does_not_expose_offline_queue_actor_hooks(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-offline-actor-id', $html);
        $this->assertStringNotContainsString('data-offline-sync-url', $html);
        $this->assertStringNotContainsString('data-offline-operation', $html);
    }

    public function test_household_create_form_is_offline_capable_without_client_pks(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $html = $this->get(route('household-profiling.create'))->assertOk()->getContent();

        $this->assertStringContainsString('data-offline-operation="HOUSEHOLD_CREATE"', $html);
        $this->assertStringContainsString('name="household_no"', $html);
        $this->assertStringNotContainsString('name="id"', $html);
        $this->assertStringNotContainsString('name="household_id"', $html);
        $this->assertStringNotContainsString('name="member_no"', $html);
        $this->assertStringNotContainsString('name="resident_id"', $html);
    }

    public function test_household_update_form_includes_server_field_hash(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $household = Household::factory()->create([
            'household_no' => '151',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
        ]);

        $html = $this->get(route('household-profiling.edit', ['householdNo' => '151']))
            ->assertOk()
            ->getContent();

        $hash = OfflineFieldHasher::household($household);
        $this->assertSame(64, strlen($hash));
        $this->assertStringContainsString('data-offline-operation="HOUSEHOLD_UPDATE"', $html);
        $this->assertStringContainsString('data-offline-field-hash="'.$hash.'"', $html);
        $this->assertStringContainsString('data-offline-parent-household-id="'.$household->getKey().'"', $html);
        $this->assertStringContainsString('data-offline-parent-household-no="151"', $html);
        $this->assertStringNotContainsString('name="household_id"', $html);
        $this->assertStringNotContainsString('name="id"', $html);
    }

    public function test_resident_create_and_update_forms_use_server_parent_identity(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $household = Household::factory()->create(['household_no' => '152']);
        $resident = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'member_no' => 'MB-152',
            'relation' => 'Spouse',
        ]);

        $create = $this->get(route('household-profiling.members.create', ['householdNo' => '152']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-offline-operation="RESIDENT_CREATE"', $create);
        $this->assertStringContainsString('data-offline-parent-household-id="'.$household->getKey().'"', $create);
        $this->assertStringContainsString('data-offline-parent-household-no="152"', $create);
        $this->assertStringNotContainsString('name="member_no"', $create);
        $this->assertStringNotContainsString('name="resident_id"', $create);
        $this->assertStringNotContainsString('data-offline-field-hash', $create);

        $edit = $this->get(route('household-profiling.members.edit', [
            'householdNo' => '152',
            'memberId' => 'MB-152',
        ]))->assertOk()->getContent();

        $hash = OfflineFieldHasher::resident($resident);
        $this->assertStringContainsString('data-offline-operation="RESIDENT_UPDATE"', $edit);
        $this->assertStringContainsString('data-offline-field-hash="'.$hash.'"', $edit);
        $this->assertStringContainsString('data-offline-parent-resident-id="'.$resident->getKey().'"', $edit);
        $this->assertStringContainsString('data-offline-parent-member-no="MB-152"', $edit);
        $this->assertStringNotContainsString('name="member_no"', $edit);
        $this->assertStringNotContainsString('name="id"', $edit);
    }

    public function test_spot_mapping_page_keeps_online_plot_url_and_offline_queue_import(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $html = $this->get(route('spot-mapping.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-plot-new-url="'.e(route('spot-mapping.plot-new')).'"', $html);

        $js = (string) file_get_contents(resource_path('js/pages/spot-mapping.js'));
        $this->assertStringContainsString('queuePlotNewHousehold', $js);
        $this->assertStringContainsString('isClientOffline', $js);
        $this->assertStringContainsString("operation_type: OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD", (string) file_get_contents(resource_path('js/offline/offline-forms.js')));
        $this->assertStringContainsString('handoff_token', $js);
        $this->assertStringContainsString('if (isClientOffline())', $js);
        $client = (string) file_get_contents(resource_path('js/offline/offline-client.js'));
        $this->assertStringContainsString('handlePlotHouseholdQueued', $client);
        $this->assertStringContainsString('handleEnvironmentalStepQueued', $client);
    }

    public function test_health_worker_edit_form_is_offline_update_capable_without_create(): void
    {
        $admin = $this->actingAsStaff(StaffRole::ADMIN);
        $worker = User::factory()->create([
            'first_name' => 'Maria',
            'last_name' => 'Reyes',
            'email' => 'maria.reyes.offline@example.test',
            'username' => 'maria.reyes.offline',
        ]);
        $worker->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-15',
        ]);
        $worker = $worker->fresh(['currentAppointment']);

        $html = $this->get(route('user-management.health-workers.edit', ['id' => (string) $worker->getKey()]))
            ->assertOk()
            ->getContent();

        $hash = OfflineFieldHasher::healthWorker($worker);
        $this->assertStringContainsString('data-offline-operation="HEALTH_WORKER_UPDATE"', $html);
        $this->assertStringContainsString('data-offline-parent-user-id="'.$worker->getKey().'"', $html);
        $this->assertStringContainsString('data-offline-field-hash="'.$hash.'"', $html);
        $this->assertStringNotContainsString('HEALTH_WORKER_CREATE', $html);
        $this->assertStringNotContainsString('name="id"', $html);

        $create = $this->get(route('user-management.health-workers.create'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-offline-operation', $create);
        $this->assertSame((int) $admin->getKey() > 0, true);
    }

    public function test_user_management_index_exposes_listed_worker_warmup_markers(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $worker = User::factory()->create([
            'first_name' => 'Levi',
            'last_name' => 'Cruz',
            'email' => 'levi.cruz.warmup@example.test',
            'username' => 'levi.cruz.warmup',
        ]);
        $worker->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 2',
            'date_appointed' => '2021-03-01',
        ]);
        $id = (string) $worker->getKey();

        $html = $this->get(route('user-management.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-um-worker-id="'.$id.'"', $html);
        $this->assertStringContainsString(
            'data-um-worker-view-url="'.e(route('user-management.health-workers.view', ['id' => $id])).'"',
            $html,
        );
        $this->assertStringContainsString(
            'data-um-worker-edit-url="'.e(route('user-management.health-workers.edit', ['id' => $id])).'"',
            $html,
        );
        $this->assertStringNotContainsString(
            'data-um-worker-view-url="'.e(route('user-management.health-workers.create')).'"',
            $html,
        );
        $this->assertStringNotContainsString('HEALTH_WORKER_CREATE', $html);
    }

    public function test_client_modules_use_indexeddb_and_exclude_secrets(): void
    {
        $files = [
            resource_path('js/offline/offline-db.js'),
            resource_path('js/offline/offline-queue.js'),
            resource_path('js/offline/offline-replay.js'),
            resource_path('js/offline/offline-forms.js'),
            resource_path('js/offline/offline-client.js'),
            resource_path('js/offline/offline-related-warmup.js'),
            resource_path('js/offline/offline-identity-map.js'),
            resource_path('js/offline/offline-local-member.js'),
            resource_path('js/offline/offline-hp-store.js'),
            resource_path('js/offline/offline-hp-hydrate.js'),
            resource_path('js/offline/offline-hp-bootstrap.js'),
            resource_path('js/offline/offline-eh-hydrate.js'),
        ];

        foreach ($files as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringNotContainsString('localStorage.setItem', $source);
            $this->assertStringNotContainsString('localStorage.getItem', $source);
            $this->assertStringNotContainsString('sql.js', $source);
            $this->assertStringNotContainsString('sqlite', $source);
            $this->assertStringNotContainsString('LMLINGA_AT_REST_KEY', $source);
            $this->assertDoesNotMatchRegularExpression('/process\.env\.APP_KEY|config\(\s*[\'"]app\.key[\'"]/', $source);
        }

        $db = (string) file_get_contents(resource_path('js/offline/offline-db.js'));
        $this->assertStringContainsString("lmlinga_offline", $db);
        $this->assertStringContainsString('indexedDB', $db);

        $replay = (string) file_get_contents(resource_path('js/offline/offline-replay.js'));
        $this->assertStringContainsString('/offline/status', $replay);
        $this->assertStringContainsString('/offline/sync', $replay);
        $this->assertStringContainsString('X-CSRF-TOKEN', $replay);
        $this->assertStringContainsString('must_change_password', $replay);
        $this->assertStringContainsString('informational only', $replay);
        $this->assertDoesNotMatchRegularExpression('/if\s*\(\s*status\.must_change_password/', $replay);
    }

    public function test_amenities_environmental_and_member_create_expose_offline_hooks(): void
    {
        $amenities = (string) file_get_contents(resource_path('views/pages/household-profiling/amenities-edit.blade.php'));
        $this->assertStringContainsString('HOUSEHOLD_AMENITIES_UPDATE', $amenities);
        $this->assertStringContainsString('data-household-no', $amenities);

        $eh = (string) file_get_contents(resource_path('views/pages/environmental-health/household-water-supply.blade.php'));
        $this->assertStringContainsString('ENVIRONMENTAL_WATER_SUPPLY_UPDATE', $eh);
        $hydrate = (string) file_get_contents(resource_path('js/offline/offline-eh-hydrate.js'));
        $this->assertStringContainsString('handlePlotHouseholdQueued', $hydrate);
        $this->assertStringContainsString('nextEhUrl', $hydrate);
        $this->assertStringContainsString('prepareEnvironmentalHealthShells', $hydrate);
        $this->assertStringContainsString('isCanonicalEhShellHtml', $hydrate);
        $this->assertStringNotContainsString('function fallbackEhHtml', $hydrate);
        $this->assertStringNotContainsString('fallbackEhHtml(', $hydrate);

        $create = (string) file_get_contents(resource_path('views/pages/household-profiling/member-create.blade.php'));
        $this->assertStringContainsString('data-offline-local-member-template', $create);
        $this->assertStringContainsString('RESIDENT_CREATE', $create);

        $imm = (string) file_get_contents(resource_path('views/pages/household-profiling/child-immunization.blade.php'));
        $this->assertStringContainsString('HEALTH_SERVICE_WRITE', $imm);
        $this->assertStringContainsString('child_immunization_store', $imm);
    }

    public function test_at_rest_encryption_module_is_untouched_by_client_queue(): void
    {
        $encrypter = (string) file_get_contents(app_path('Support/AtRestEncrypter.php'));
        $this->assertStringNotContainsString('indexedDB', $encrypter);
        $this->assertStringNotContainsString('offline-queue', $encrypter);
        $this->assertStringContainsString('AES-256-GCM', $encrypter);
    }
}
