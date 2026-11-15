<?php

namespace Tests\Feature;

use App\Support\HealthRecordsDeath;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthenticatesStaff;
use Tests\TestCase;

/**
 * Batch 3 — every growing listing opts in to the shared paginator, and pagination changes nothing about who
 * may open a listing (admin-only listings still refuse health workers).
 */
class ListingPaginationTest extends TestCase
{
    use AuthenticatesStaff;
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function listings(): array
    {
        return [
            'household requests' => ['household-requests.index', 'hr-row'],
            'death requests' => ['death-requests.index', 'dr-row'],
            'household profiling' => ['household-profiling.index', 'hh-row'],
            'child care' => ['health-records.child-care.index', 'hr-cc-row'],
            'deworming' => ['health-records.child-care.deworming', 'hr-dw-row'],
            'vitamin a' => ['health-records.child-care.vitamin-a', 'hr-va-row'],
            'operation timbang' => ['health-records.child-care.operation-timbang', 'hr-ot-row'],
            'child care non-residents' => ['health-records.child-care.non-residents.index', 'hr-cc-nr-row'],
            'maternal' => ['health-records.maternal.index', 'hr-mc-row'],
            'maternal non-residents' => ['health-records.maternal.non-residents.index', 'hr-mc-row'],
            'family planning' => ['health-records.family-planning.index', 'hr-fp-row'],
            'family planning non-residents' => ['health-records.family-planning.non-residents.index', 'hr-fp-nr-row'],
            'risk assessment' => ['health-records.risk-assessment.index', 'hr-ra-row'],
            'environmental health' => ['environmental-health.index', 'eh-row'],
            'death resident selection' => ['health-records.death.residents', 'hr-death-resident-row'],
            'language management' => ['language-management.index', 'lml-term-row'],
            'chatbot datasets' => ['chatbot-datasets.index', 'lml-dataset-row'],
            'user management workers' => ['user-management.index', 'hw-card'],
        ];
    }

    #[DataProvider('listings')]
    public function test_listing_renders_the_pagination_hook_for_an_admin(string $route, string $rowSelector): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        // These two tables render only when at least one record exists: seed one synthetic placeholder.
        if ($route === 'language-management.index') {
            $this->post(route('language-management.store'), [
                'term' => 'placeholder-term-pagination',
                'english_meaning' => 'placeholder meaning',
                'tagalog_meaning' => '',
                'part_of_speech' => 'noun',
                'example_usage' => 'placeholder example sentence',
                'notes' => '',
            ])->assertSessionHasNoErrors();
        }
        if ($route === 'chatbot-datasets.index') {
            $this->post(route('chatbot-datasets.store'), [
                'title' => 'Placeholder dataset pagination',
                'language' => 'en',
                'category' => 'other',
                'source_reference' => 'Placeholder source reference for testing',
                'dataset_file' => UploadedFile::fake()->createWithContent(
                    'placeholder.txt',
                    "Placeholder dataset\n\n".str_repeat("This is placeholder text for testing pagination, not health advice.\n", 4)
                ),
                'no_personal_data_confirmed' => '1',
            ])->assertSessionHasNoErrors();
        }

        $this->get(route($route))
            ->assertOk()
            ->assertSee('data-lml-paginate="'.$rowSelector.'"', false)
            ->assertSee('data-lml-paginate-label=', false);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function adminOnlyListings(): array
    {
        return [
            'household requests' => ['household-requests.index'],
            'death requests' => ['death-requests.index'],
            'language management' => ['language-management.index'],
            'chatbot datasets' => ['chatbot-datasets.index'],
            'user management' => ['user-management.index'],
        ];
    }

    #[DataProvider('adminOnlyListings')]
    public function test_admin_only_listings_still_refuse_health_workers(string $route): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $response = $this->get(route($route));

        $this->assertContains($response->getStatusCode(), [302, 403]);
        $response->assertDontSee('data-lml-paginate=', false);
    }

    public function test_existing_server_side_death_pagination_is_unchanged(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        // Death keeps its own Laravel server-side paginator (shown when there is more than one page).
        $this->assertSame(7, HealthRecordsDeath::LISTING_PER_PAGE);
        $this->get(route('health-records.death.index'))
            ->assertOk()
            ->assertDontSee('data-lml-paginate=', false);
    }
}
