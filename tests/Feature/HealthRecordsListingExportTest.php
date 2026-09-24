<?php

namespace Tests\Feature;

use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthRecordsListingExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function exportRoutes(): array
    {
        return [
            'health-records.child-care.export',
            'health-records.child-care.vitamin-a.export',
            'health-records.child-care.deworming.export',
            'health-records.child-care.operation-timbang.export',
            'health-records.family-planning.export',
            'health-records.family-planning.non-residents.export',
            'health-records.risk-assessment.export',
            'health-records.maternal.export',
            'health-records.maternal.non-residents.export',
        ];
    }

    public function test_listing_export_routes_return_pdf(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        foreach ($this->exportRoutes() as $routeName) {
            $response = $this->get(route($routeName));

            $response->assertOk();
            $response->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent(), $routeName);
        }
    }

    public function test_child_care_listing_exposes_export_url(): void
    {
        $this->actingAsStaff(StaffRole::BNS);

        $this->get(route('health-records.child-care.index'))
            ->assertOk()
            ->assertSee(route('health-records.child-care.report-builder'), false);
    }
}
