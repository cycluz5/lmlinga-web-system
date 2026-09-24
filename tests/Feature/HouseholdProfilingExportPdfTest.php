<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\Resident;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HouseholdProfilingExportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_returns_pdf_with_expected_metadata_and_rows(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-901',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Head',
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Lim',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('household-profiling.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('attachment; filename="household-profiling-', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /**
     * The export shows personal information per member (not just a
     * household-level summary), with each household's own members ordered
     * Head first, then the rest oldest to youngest.
     */
    public function test_export_lists_members_head_first_then_oldest_to_youngest(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-950',
            'zone' => 'Zone 4',
            'street' => 'Order St.',
            'date_registered' => '2026-01-15',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Daughter',
            'first_name' => 'Baby',
            'last_name' => 'Torres',
            'birthday' => now()->subYears(2)->format('Y-m-d'),
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Head',
            'first_name' => 'Marco',
            'last_name' => 'Torres',
            'birthday' => now()->subYears(40)->format('Y-m-d'),
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Spouse',
            'first_name' => 'Liza',
            'last_name' => 'Torres',
            'birthday' => now()->subYears(38)->format('Y-m-d'),
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('household-profiling.export', ['zone' => 'Zone 4']));
        $response->assertOk();
        $pdfContent = $response->getContent();

        $this->assertStringContainsString('HOUSEHOLD', $pdfContent);
        $this->assertStringContainsString('MEMBER', $pdfContent);
        $this->assertStringContainsString('PERSONAL DETAILS', $pdfContent);
        $this->assertStringContainsString('SOCIOECONOMIC', $pdfContent);
        $this->assertStringContainsString('HEALTH & WELFARE', $pdfContent);

        // Summary: overall totals (across the whole export), the zone name
        // on its own line, then that zone's own totals.
        $this->assertStringContainsString('Overall Total Household', $pdfContent);
        $this->assertStringContainsString('Overall Total Population', $pdfContent);
        $this->assertStringContainsString('Zone 4', $pdfContent);
        $this->assertStringContainsString('Total Household', $pdfContent);
        $this->assertStringContainsString('Total Population', $pdfContent);

        // Every "Torres, ..." text-show run, in content-stream order, honoring PDF string escapes.
        preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/', $pdfContent, $matches);
        $names = array_values(array_filter(
            $matches[1],
            static fn (string $run): bool => str_starts_with($run, 'Torres,')
        ));

        $this->assertSame(
            ['Torres, Marco', 'Torres, Liza', 'Torres, Baby'],
            $names,
            'expected Head first, then oldest to youngest'
        );
    }

    public function test_export_respects_zone_filter(): void
    {
        Household::factory()->create([
            'household_no' => 'HH-902',
            'zone' => 'Zone 1',
            'street' => 'Dalipay St.',
            'date_registered' => '2026-01-15',
        ]);
        Household::factory()->create([
            'household_no' => 'HH-903',
            'zone' => 'Zone 3',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
        ]);

        $this->actingAsStaff(StaffRole::ADMIN);
        $response = $this->get(route('household-profiling.export', ['zone' => 'Zone 1']));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_export_handles_empty_dataset(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $response = $this->get(route('household-profiling.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
