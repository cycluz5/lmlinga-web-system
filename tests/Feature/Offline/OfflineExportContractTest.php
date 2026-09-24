<?php

namespace Tests\Feature\Offline;

use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineExportContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_export_module_blocks_downloads_without_touching_the_queue(): void
    {
        $path = resource_path('js/offline/offline-export.js');
        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);
        $this->assertStringNotContainsString("from './offline-queue", $source);
        $this->assertStringNotContainsString('enqueueOperation', $source);
        $this->assertStringNotContainsString('listOperations', $source);
        $this->assertStringNotContainsString('indexedDB.open', $source);
        $this->assertStringNotContainsString('indexedDB.deleteDatabase', $source);
        $this->assertStringNotContainsString('createObjectURL', $source);
        $this->assertStringNotContainsString('new Blob', $source);
        $this->assertStringNotContainsString('buildCsv', $source);
        $this->assertStringNotContainsString('extractHouseholdProfilingSnapshot', $source);
        $this->assertStringContainsString('Export is available when online.', $source);

        $appJs = (string) file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("import './offline/offline-export';", $appJs);

        $policy = (string) file_get_contents(resource_path('js/offline/offline-sw-policy.js'));
        $this->assertStringContainsString('/\/export(?:\/|$)/i', $policy);
    }

    public function test_online_listing_exports_remain_server_generated(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $householdPage = $this->get(route('household-profiling.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-hh-export', $householdPage);
        $this->assertStringContainsString('data-hh-export-url="'.e(route('household-profiling.export')).'"', $householdPage);

        $householdExport = $this->get(route('household-profiling.export'));
        $householdExport->assertOk();
        $householdExport->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $householdExport->getContent());

        $childCarePage = $this->get(route('health-records.child-care.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-hr-cc-export', $childCarePage);

        $childCareExport = $this->get(route('health-records.child-care.export'));
        $childCareExport->assertOk();
        $childCareExport->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $childCareExport->getContent());

        $spotPage = $this->get(route('spot-mapping.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-spot-map-export', $spotPage);
        $this->assertStringContainsString('Save Map', $spotPage);
    }

    public function test_other_module_export_controls_remain_on_their_pages(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $pages = [
            route('environmental-health.report-builder') => 'data-eh-export',
            route('health-records.risk-assessment.index') => 'data-hr-ra-export',
            route('health-records.family-planning.index') => 'data-hr-fp-export',
            route('health-records.maternal.index') => 'data-hr-mc-export',
            route('health-records.death.index') => 'data-hr-death-export',
        ];

        foreach ($pages as $url => $marker) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString($marker, $html, $url);
        }
    }
}
