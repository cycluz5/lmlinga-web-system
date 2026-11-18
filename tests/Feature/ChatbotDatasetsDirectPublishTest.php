<?php

namespace Tests\Feature;

use App\Models\ChatbotDataset;
use App\Models\HealthChunk;
use App\Models\User;
use App\Services\RagService;
use App\Support\ApprovedChatbotDatasets;
use App\Support\ChatbotDatasetCatalog;
use App\Support\DemoStaffLogin;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Admin adds datasets (English, Tagalog, Bikol-Iriga) and saving sends them straight into the chatbot's
 * health information: no Linguist step, the file lands in the admin docs folder and only that file is indexed.
 * Fictional placeholder text only. The embedding model is faked; no real model is called.
 */
class ChatbotDatasetsDirectPublishTest extends TestCase
{
    use RefreshDatabase;

    private string $docsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docsRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lml-admin-docs-'.bin2hex(random_bytes(4));
        config([
            'lmlinga.chatbot_datasets.require_review' => false,
            'lmlinga.chatbot_datasets.admin_docs_path' => $this->docsRoot,
        ]);
        Http::preventStrayRequests();
        $this->embeddingsWork();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->docsRoot);
        parent::tearDown();
    }

    /** One fake whose behaviour can be switched (Http::fake keeps the first stub registered). */
    private bool $embeddingsUp = true;

    private function embeddingsWork(): void
    {
        $this->embeddingsUp = true;
        $this->fakeEmbeddingsOnce();
    }

    private function embeddingsDown(): void
    {
        $this->embeddingsUp = false;
    }

    private bool $embeddingsFaked = false;

    private function fakeEmbeddingsOnce(): void
    {
        if ($this->embeddingsFaked) {
            return;
        }
        $this->embeddingsFaked = true;
        Http::fake(['*/api/embeddings' => fn () => $this->embeddingsUp
            ? Http::response(['embedding' => array_fill(0, 8, 0.1)])
            : Http::response('model not reachable', 500)]);
    }

    private function text(string $marker): string
    {
        return "Placeholder dataset {$marker}\n\n"
            .str_repeat("This is placeholder text {$marker} for testing the direct publish flow, and it is not health advice.\n", 4);
    }

    /** @return array<string, mixed> */
    private function payload(string $marker = 'alpha', string $language = 'en', string $category = 'other'): array
    {
        return [
            'title' => "Placeholder dataset {$marker}",
            'language' => $language,
            'category' => $category,
            'source_reference' => 'Placeholder source reference for testing',
            'dataset_file' => UploadedFile::fake()->createWithContent("{$marker}.txt", $this->text($marker)),
            'no_personal_data_confirmed' => '1',
        ];
    }

    private function admin(): User
    {
        $admin = $this->actingAsStaff(StaffRole::ADMIN, ['email' => 'direct.admin@example.test', 'username' => 'direct.admin']);

        return $admin;
    }

    private function file(string $language, string $category, string $name): string
    {
        return $this->docsRoot.'/'.$language.'/'.$category.'/'.$name;
    }

    public function test_saving_publishes_at_once_and_sends_the_file_into_the_chatbot_health_information(): void
    {
        $this->admin();

        $this->post(route('chatbot-datasets.store'), $this->payload('alpha'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, 'sent to the chatbot health information'));

        $dataset = ChatbotDataset::query()->firstOrFail();
        $this->assertSame(ChatbotDatasetCatalog::APPROVED, $dataset->displayStatus(), 'live without any review');
        $this->assertSame('Live', ChatbotDatasetCatalog::label($dataset->displayStatus()));
        $this->assertSame(['placeholder_dataset_alpha.txt'], array_column(ApprovedChatbotDatasets::all(), 'source_name'));

        $path = $this->file('en', 'other', 'placeholder_dataset_alpha.txt');
        $this->assertFileExists($path);
        $this->assertStringContainsString('placeholder text alpha', (string) file_get_contents($path));
        $this->assertGreaterThan(0, HealthChunk::query()
            ->where(['language' => 'en', 'category' => 'other', 'source_file' => 'placeholder_dataset_alpha.txt'])->count());

        $this->get(route('chatbot-datasets.show', ['datasetId' => $dataset->id]))
            ->assertOk()->assertSee('data-lml-dataset-indexed', false)->assertDontSee('data-lml-dataset-reindex', false);
    }

    public function test_english_tagalog_and_bikol_iriga_datasets_each_land_in_their_language_folder(): void
    {
        $this->admin();

        foreach (['en' => 'one', 'tl' => 'two', 'bcl' => 'three'] as $language => $marker) {
            $this->post(route('chatbot-datasets.store'), $this->payload($marker, $language, 'child_care'))->assertSessionHasNoErrors();
            $this->assertFileExists($this->file($language, 'child_care', "placeholder_dataset_{$marker}.txt"));
            $this->assertSame(1, HealthChunk::query()->where(['language' => $language, 'source_file' => "placeholder_dataset_{$marker}.txt"])
                ->distinct()->count('source_file'));
        }
    }

    public function test_no_linguist_review_item_is_created(): void
    {
        $this->admin();
        $this->post(route('chatbot-datasets.store'), $this->payload('gamma'))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('chatbot_dataset_versions', ['status' => ChatbotDatasetCatalog::SUBMITTED]);
        $this->assertDatabaseHas('chatbot_dataset_events', ['action' => 'published']);
    }

    public function test_changing_a_dataset_replaces_the_live_text_and_its_chunks(): void
    {
        $this->admin();
        $this->post(route('chatbot-datasets.store'), $this->payload('alpha'))->assertSessionHasNoErrors();
        $dataset = ChatbotDataset::query()->firstOrFail();

        $this->put(route('chatbot-datasets.update', ['datasetId' => $dataset->id]), [
            'title' => 'Placeholder dataset alpha',
            'source_reference' => 'Placeholder source reference for testing',
            'dataset_file' => UploadedFile::fake()->createWithContent('alpha2.txt', $this->text('omega')),
            'no_personal_data_confirmed' => '1',
        ])->assertSessionHasNoErrors();

        $path = $this->file('en', 'other', 'placeholder_dataset_alpha.txt');
        $this->assertStringContainsString('placeholder text omega', (string) file_get_contents($path));
        $this->assertStringNotContainsString('placeholder text alpha', (string) file_get_contents($path));
        $chunks = HealthChunk::query()->where('source_file', 'placeholder_dataset_alpha.txt')->pluck('content')->implode(' ');
        $this->assertStringContainsString('omega', $chunks);
        $this->assertStringNotContainsString('text alpha', $chunks);
        $this->assertSame(1, $dataset->fresh()->versions()->where('status', ChatbotDatasetCatalog::APPROVED)->count());
        $this->assertSame(1, $dataset->fresh()->versions()->where('status', ChatbotDatasetCatalog::SUPERSEDED)->count());
    }

    public function test_saving_the_same_text_again_is_refused(): void
    {
        $this->admin();
        $this->post(route('chatbot-datasets.store'), $this->payload('alpha'))->assertSessionHasNoErrors();
        $dataset = ChatbotDataset::query()->firstOrFail();

        $this->put(route('chatbot-datasets.update', ['datasetId' => $dataset->id]), [
            'title' => 'Placeholder dataset alpha',
            'source_reference' => 'Placeholder source reference for testing',
            'dataset_file' => UploadedFile::fake()->createWithContent('same.txt', $this->text('alpha')),
            'no_personal_data_confirmed' => '1',
        ])->assertSessionHasErrors();

        $this->assertSame(1, $dataset->fresh()->versions()->count());
    }

    public function test_when_the_ai_model_is_down_the_dataset_is_saved_and_can_be_re_indexed(): void
    {
        $this->embeddingsDown();
        $this->admin();

        $this->post(route('chatbot-datasets.store'), $this->payload('alpha'))
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, 'Re-index'));

        $dataset = ChatbotDataset::query()->firstOrFail();
        $this->assertFileExists($this->file('en', 'other', 'placeholder_dataset_alpha.txt'));
        $this->assertSame(0, HealthChunk::query()->count(), 'nothing half-indexed');
        $this->assertSame(ChatbotDatasetCatalog::APPROVED, $dataset->displayStatus(), 'still saved and live');

        $this->get(route('chatbot-datasets.show', ['datasetId' => $dataset->id]))
            ->assertOk()->assertSee('data-lml-dataset-waiting', false)->assertSee('data-lml-dataset-reindex', false);

        $this->embeddingsWork();
        $this->post(route('chatbot-datasets.reindex', ['datasetId' => $dataset->id]))
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, 'sent to the chatbot health information'));
        $this->assertGreaterThan(0, HealthChunk::query()->where('source_file', 'placeholder_dataset_alpha.txt')->count());
    }

    public function test_a_failed_re_index_keeps_the_existing_chunks(): void
    {
        $this->admin();
        $this->post(route('chatbot-datasets.store'), $this->payload('alpha'))->assertSessionHasNoErrors();
        $dataset = ChatbotDataset::query()->firstOrFail();
        $before = HealthChunk::query()->where('source_file', 'placeholder_dataset_alpha.txt')->count();
        $this->assertGreaterThan(0, $before);

        $this->embeddingsDown();
        $this->post(route('chatbot-datasets.reindex', ['datasetId' => $dataset->id]));

        $this->assertSame($before, HealthChunk::query()->where('source_file', 'placeholder_dataset_alpha.txt')->count());
    }

    public function test_retiring_removes_the_file_and_chunks_and_restoring_sends_it_again(): void
    {
        $this->admin();
        $this->post(route('chatbot-datasets.store'), $this->payload('alpha'))->assertSessionHasNoErrors();
        $dataset = ChatbotDataset::query()->firstOrFail();
        $path = $this->file('en', 'other', 'placeholder_dataset_alpha.txt');

        $this->post(route('chatbot-datasets.retire', ['datasetId' => $dataset->id]))->assertSessionHas('status');
        $this->assertFileDoesNotExist($path);
        $this->assertSame(0, HealthChunk::query()->where('source_file', 'placeholder_dataset_alpha.txt')->count());

        $this->post(route('chatbot-datasets.restore', ['datasetId' => $dataset->id]))->assertSessionHas('status');
        $this->assertFileExists($path);
        $this->assertGreaterThan(0, HealthChunk::query()->where('source_file', 'placeholder_dataset_alpha.txt')->count());
        $this->assertSame(ChatbotDatasetCatalog::APPROVED, $dataset->fresh()->displayStatus());
    }

    public function test_the_chatbot_prefers_the_admin_file_over_a_built_in_file_of_the_same_name(): void
    {
        $rag = app(RagService::class);
        $builtIn = resource_path('health_docs_v2/en/other/placeholder_dataset_alpha.txt');
        $this->assertSame(
            str_replace('\\', '/', $builtIn),
            str_replace('\\', '/', $rag->healthDocPath('en', 'other', 'placeholder_dataset_alpha.txt'))
        );

        $this->admin();
        $this->post(route('chatbot-datasets.store'), $this->payload('alpha'))->assertSessionHasNoErrors();

        $this->assertSame($this->file('en', 'other', 'placeholder_dataset_alpha.txt'), $rag->healthDocPath('en', 'other', 'placeholder_dataset_alpha.txt'));
    }

    public function test_a_full_re_index_keeps_admin_published_datasets(): void
    {
        $this->admin();
        $this->post(route('chatbot-datasets.store'), $this->payload('alpha', 'tl', 'other'))->assertSessionHasNoErrors();

        app(RagService::class)->indexAllDocuments();

        $this->assertGreaterThan(0, HealthChunk::query()
            ->where(['language' => 'tl', 'category' => 'other', 'source_file' => 'placeholder_dataset_alpha.txt'])->count());
    }

    public function test_the_personal_data_confirmation_is_still_required(): void
    {
        $this->admin();

        $payload = $this->payload('alpha');
        unset($payload['no_personal_data_confirmed']);
        $this->post(route('chatbot-datasets.store'), $payload)->assertSessionHasErrors('no_personal_data_confirmed');

        $this->assertSame(0, ChatbotDataset::query()->count());
        $this->assertDirectoryDoesNotExist($this->docsRoot);
    }

    public function test_only_an_active_admin_can_publish(): void
    {
        $this->actingAsStaff(StaffRole::BHW, ['email' => 'direct.bhw@example.test', 'username' => 'direct.bhw']);

        $this->post(route('chatbot-datasets.store'), $this->payload('alpha'))->assertForbidden();

        $this->assertSame(0, ChatbotDataset::query()->count());
    }

    // ------------------------------------------------------------------ existing documents + Add button

    public function test_the_list_has_an_add_button_and_offers_to_show_the_existing_documents(): void
    {
        $this->admin();
        $corpus = count(glob(resource_path('health_docs_v2/{bcl,en,tl}/*/*.txt'), GLOB_BRACE) ?: []);

        $this->get(route('chatbot-datasets.index'))
            ->assertOk()
            ->assertSee('data-lml-dataset-add', false)->assertSee('Add Dataset')
            ->assertSee('data-lml-dataset-import', false)->assertSee('Show existing documents');
        $this->assertGreaterThan(0, $corpus);
    }

    public function test_showing_the_existing_documents_lists_them_as_live_without_touching_the_chatbot(): void
    {
        $this->admin();
        $corpus = count(glob(resource_path('health_docs_v2/{bcl,en,tl}/*/*.txt'), GLOB_BRACE) ?: []);

        $this->post(route('chatbot-datasets.import-existing'), ['no_personal_data_confirmed' => '1'])
            ->assertRedirect(route('chatbot-datasets.index'))->assertSessionHasNoErrors();

        $this->assertSame($corpus, ChatbotDataset::query()->count());
        $this->assertSame(0, ChatbotDataset::query()->get()->filter(fn ($d) => $d->displayStatus() !== ChatbotDatasetCatalog::APPROVED)->count());
        $this->assertSame(0, HealthChunk::query()->count(), 'the chatbot index is untouched');
        $this->assertDirectoryDoesNotExist($this->docsRoot);
        Http::assertNothingSent();

        $this->get(route('chatbot-datasets.index'))->assertOk()->assertDontSee('data-lml-dataset-import', false)->assertSee('Live');
    }

    public function test_showing_the_existing_documents_twice_adds_nothing_new(): void
    {
        $this->admin();
        $this->post(route('chatbot-datasets.import-existing'), ['no_personal_data_confirmed' => '1']);
        $count = ChatbotDataset::query()->count();

        $this->post(route('chatbot-datasets.import-existing'), ['no_personal_data_confirmed' => '1'])->assertSessionHasNoErrors();

        $this->assertSame($count, ChatbotDataset::query()->count());
    }

    public function test_showing_the_existing_documents_needs_the_personal_data_confirmation(): void
    {
        $this->admin();

        $this->post(route('chatbot-datasets.import-existing'))->assertSessionHasErrors('no_personal_data_confirmed');

        $this->assertSame(0, ChatbotDataset::query()->count());
    }

    public function test_only_an_admin_can_show_the_existing_documents(): void
    {
        $this->actingAsStaff(StaffRole::BHW, ['email' => 'imp.bhw@example.test', 'username' => 'imp.bhw']);

        $this->post(route('chatbot-datasets.import-existing'), ['no_personal_data_confirmed' => '1'])->assertForbidden();

        $this->assertSame(0, ChatbotDataset::query()->count());
    }

    public function test_changing_a_listed_existing_document_replaces_the_built_in_one_for_the_chatbot(): void
    {
        $this->admin();
        $this->post(route('chatbot-datasets.import-existing'), ['no_personal_data_confirmed' => '1']);
        $dataset = ChatbotDataset::query()->where('language', 'en')->orderBy('id')->firstOrFail();
        $builtIn = resource_path("health_docs_v2/en/{$dataset->category}/{$dataset->source_name}");
        $this->assertSame(
            str_replace('\\', '/', $builtIn),
            str_replace('\\', '/', app(RagService::class)->healthDocPath('en', $dataset->category, $dataset->source_name))
        );

        $this->put(route('chatbot-datasets.update', ['datasetId' => $dataset->id]), [
            'title' => 'Changed '.$dataset->currentVersion()->title,
            'source_reference' => 'Placeholder source reference for testing',
            'dataset_file' => UploadedFile::fake()->createWithContent('changed.txt', $this->text('replacement')),
            'no_personal_data_confirmed' => '1',
        ])->assertSessionHasNoErrors();

        $adminCopy = $this->file('en', $dataset->category, $dataset->source_name);
        $this->assertFileExists($adminCopy);
        $this->assertSame($adminCopy, app(RagService::class)->healthDocPath('en', $dataset->category, $dataset->source_name));
        $this->assertFileExists($builtIn, 'the built-in file is never modified');
    }

    // ------------------------------------------------------------------ grouped by language

    private function addDatasets(): void
    {
        $this->admin();
        foreach ([['one', 'en'], ['two', 'en'], ['three', 'tl'], ['four', 'bcl'], ['five', 'bcl'], ['six', 'bcl']] as [$marker, $language]) {
            $this->post(route('chatbot-datasets.store'), $this->payload($marker, $language, 'child_care'))->assertSessionHasNoErrors();
        }
    }

    public function test_datasets_are_grouped_into_english_tagalog_and_bikol_iriga_tabs_with_counts(): void
    {
        $this->addDatasets();

        $html = $this->get(route('chatbot-datasets.index'))->assertOk()->getContent();

        foreach (['en' => 2, 'tl' => 1, 'bcl' => 3] as $language => $count) {
            $this->assertMatchesRegularExpression(
                '/data-lml-dataset-language="'.$language.'".*?lml-cd__count">'.$count.'</s',
                $html,
                "tab for {$language} shows {$count}"
            );
        }
        $this->assertStringContainsString('English', $html);
        $this->assertStringContainsString('Tagalog', $html);
        $this->assertStringContainsString('Bikol-Iriga', $html);
    }

    public function test_english_is_the_default_tab_and_each_tab_lists_only_its_language(): void
    {
        $this->addDatasets();

        $english = $this->get(route('chatbot-datasets.index'))->assertOk();
        $this->assertSame(2, substr_count($english->getContent(), 'data-lml-dataset-row'));
        $english->assertSee('Placeholder dataset one')->assertDontSee('Placeholder dataset three');

        $bikol = $this->get(route('chatbot-datasets.index', ['language' => 'bcl']))->assertOk();
        $this->assertSame(3, substr_count($bikol->getContent(), 'data-lml-dataset-row'));
        $bikol->assertSee('Placeholder dataset four')->assertDontSee('Placeholder dataset one');

        $tagalog = $this->get(route('chatbot-datasets.index', ['language' => 'tl']))->assertOk();
        $this->assertSame(1, substr_count($tagalog->getContent(), 'data-lml-dataset-row'));
    }

    public function test_an_unknown_language_falls_back_to_english(): void
    {
        $this->addDatasets();

        $response = $this->get(route('chatbot-datasets.index', ['language' => 'xx']))->assertOk();

        $this->assertSame(2, substr_count($response->getContent(), 'data-lml-dataset-row'));
    }

    public function test_the_list_uses_the_standard_admin_listing_markup_and_pagination(): void
    {
        $this->addDatasets();

        $this->get(route('chatbot-datasets.index'))
            ->assertOk()
            ->assertSee('lml-user-mgmt__tabs', false)
            ->assertSee('lml-user-mgmt__toolbar', false)
            ->assertSee('lml-hr-table', false)
            ->assertSee('lml-hr-table__view-btn', false)
            ->assertSee('data-lml-paginate="lml-dataset-row"', false)
            ->assertDontSee('lml-language-management__filter', false);
    }

    public function test_add_dataset_on_a_language_tab_preselects_that_language(): void
    {
        $this->admin();

        $this->get(route('chatbot-datasets.index', ['language' => 'bcl']))
            ->assertOk()->assertSee(route('chatbot-datasets.create', ['language' => 'bcl']), false);

        $page = $this->get(route('chatbot-datasets.create', ['language' => 'bcl']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="bcl"[^>]*selected/', $page);
        $this->assertDoesNotMatchRegularExpression('/<option value="en"[^>]*selected/', $page);
    }

    public function test_the_detail_page_goes_back_to_its_own_language_tab(): void
    {
        $this->addDatasets();
        $bikol = ChatbotDataset::query()->where('language', 'bcl')->firstOrFail();

        $this->get(route('chatbot-datasets.show', ['datasetId' => $bikol->id]))
            ->assertOk()->assertSee(route('chatbot-datasets.index', ['language' => 'bcl']), false);
    }
}
