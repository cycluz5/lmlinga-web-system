<?php

namespace Tests\Feature;

use App\Models\ChatbotDataset;
use App\Models\ChatbotDatasetVersion;
use App\Models\ChatbotTerm;
use App\Models\User;
use App\Services\ChatbotComparison\ApprovedContextApproach;
use App\Services\ChatbotComparison\ComparisonFiles;
use App\Services\ChatbotComparison\ModelOnlyApproach;
use App\Services\ChatbotDatasetWorkflow;
use App\Services\ChatbotTermWorkflow;
use App\Services\RagService;
use App\Support\ApprovedBikolTerms;
use App\Support\ApprovedChatbotDatasets;
use App\Support\ChatbotDatasetFileInspector;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AuthenticatesStaff;
use Tests\TestCase;

/**
 * Batch 6 — the chatbot uses Linguist-approved data:
 * - approved Bikol-Iriga terms are given to the resident chatbot as a prompt glossary;
 * - `chatbot:compare` Approach B answers only from approved datasets with the same model settings as A.
 * All terms and dataset text below are synthetic placeholders, created through the real review workflow.
 */
class ChatbotApprovedDataTest extends TestCase
{
    use AuthenticatesStaff;
    use RefreshDatabase;

    private User $admin;

    private User $linguist;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake(ComparisonFiles::DISK);
        config(['services.ollama.api_key' => 'test-key-not-real-0000', 'services.ollama.base_url' => 'http://127.0.0.1:11434']);
        $this->linguist = $this->actingAsStaff(StaffRole::LINGUIST, ['email' => 'ling.b6@example.test', 'username' => 'ling.b6']);
        $this->admin = $this->actingAsStaff(StaffRole::ADMIN, ['email' => 'admin.b6@example.test', 'username' => 'admin.b6']);
        ApprovedBikolTerms::forget();
        ApprovedChatbotDatasets::forget();
    }

    // ------------------------------------------------------------------ helpers (real workflows)

    private function term(string $word, string $meaning, string $outcome = 'approve'): ChatbotTerm
    {
        $workflow = app(ChatbotTermWorkflow::class);
        $term = $workflow->create($this->admin, [
            'term' => $word, 'english_meaning' => $meaning, 'tagalog_meaning' => '', 'part_of_speech' => 'noun',
            'example_usage' => 'placeholder example', 'notes' => '',
        ]);
        $version = $workflow->submit($this->admin, $term);
        if ($outcome === 'approve') {
            $workflow->approve($this->linguist, $version);
        } elseif ($outcome === 'reject') {
            $workflow->reject($this->linguist, $version, 'placeholder rejection note');
        }

        return $term->fresh();
    }

    private function dataset(string $title, string $language, string $text, string $outcome = 'approve'): ChatbotDatasetVersion
    {
        $workflow = app(ChatbotDatasetWorkflow::class);
        $inspection = app(ChatbotDatasetFileInspector::class)->inspect($text, 'placeholder.txt', $language);
        $dataset = $workflow->upload($this->admin, [
            'title' => $title, 'language' => $language, 'category' => 'other',
            'source_reference' => 'Placeholder source reference for testing', 'no_personal_data_confirmed' => true,
        ], $inspection);
        $version = $workflow->submit($this->admin, $dataset);
        if ($outcome === 'approve') {
            $version = $workflow->approve($this->linguist, $version);
        }

        return $version->fresh();
    }

    private function datasetText(string $marker, string $topic): string
    {
        return "Placeholder dataset {$marker}\n\n"
            .str_repeat("This placeholder passage about {$topic} is test text {$marker} and is not health advice.\n", 3);
    }

    private function fakeModel(): void
    {
        Http::fake([
            'http://127.0.0.1:11434/api/chat' => Http::response(['message' => ['content' => '{"answer":"placeholder approved answer","points":["placeholder point"]}']]),
        ]);
    }

    /** @return list<array{role: string, content: string}> */
    private function messagesFor(string $language): array
    {
        $rag = new class extends RagService
        {
            public function messagesFor(string $context, string $question, string $language): array
            {
                return $this->buildMessages($context, $question, $language);
            }
        };

        return $rag->messagesFor('placeholder context', 'placeholder question', $language);
    }

    // ------------------------------------------------------------------ approved terms → resident chatbot prompt

    public function test_no_approved_terms_leaves_the_chatbot_prompt_unchanged(): void
    {
        $this->term('placeholder-pending', 'pending meaning', 'submit-only');

        $this->assertNull(app(RagService::class)->approvedTermsGlossary());
        foreach (['en', 'tl', 'bcl'] as $language) {
            $this->assertStringNotContainsString('APPROVED BIKOL-IRIGA TERMS', json_encode($this->messagesFor($language)));
        }
    }

    public function test_approved_terms_are_added_to_every_language_prompt_right_after_the_system_prompt(): void
    {
        $this->term('placeholder-alpha', 'alpha meaning');

        foreach (['en', 'tl', 'bcl'] as $language) {
            $messages = $this->messagesFor($language);
            $this->assertSame('system', $messages[0]['role']);
            $this->assertSame('system', $messages[1]['role'], $language);
            $this->assertStringStartsWith('APPROVED BIKOL-IRIGA TERMS', $messages[1]['content']);
            $this->assertStringContainsString('- placeholder-alpha: alpha meaning', $messages[1]['content']);
            $this->assertStringContainsString('placeholder context', end($messages)['content'], 'the CONTEXT question is still last');
        }
    }

    public function test_only_approved_non_retired_terms_reach_the_prompt(): void
    {
        $this->term('placeholder-approved', 'approved meaning');
        $this->term('placeholder-submitted', 'submitted meaning', 'submit-only');
        $this->term('placeholder-rejected', 'rejected meaning', 'reject');
        $retired = $this->term('placeholder-retired', 'retired meaning');
        app(ChatbotTermWorkflow::class)->retire($this->admin, $retired);
        ApprovedBikolTerms::forget();

        $glossary = (string) app(RagService::class)->approvedTermsGlossary();

        $this->assertStringContainsString('placeholder-approved', $glossary);
        foreach (['placeholder-submitted', 'placeholder-rejected', 'placeholder-retired'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $glossary);
        }
    }

    public function test_the_glossary_can_be_turned_off(): void
    {
        $this->term('placeholder-alpha', 'alpha meaning');
        config(['lmlinga.chatbot.approved_terms_in_prompt' => false]);

        $this->assertNull(app(RagService::class)->approvedTermsGlossary());
        $this->assertStringNotContainsString('APPROVED BIKOL-IRIGA TERMS', json_encode($this->messagesFor('bcl')));
    }

    // ------------------------------------------------------------------ approved datasets → comparison Approach B

    public function test_approach_b_stays_pending_without_approved_datasets(): void
    {
        $this->dataset('Placeholder unapproved', 'en', $this->datasetText('alpha', 'handwashing'), 'submit-only');

        $approach = ApprovedContextApproach::fromConfig('local', 'small-model:3b');

        $this->assertStringContainsString('SR-07b', (string) $approach->pendingReason());
    }

    public function test_approach_b_answers_only_from_approved_text_with_the_same_settings_as_a(): void
    {
        $this->fakeModel();
        $approved = $this->dataset('Placeholder approved handwashing', 'en', $this->datasetText('approvedmarker', 'handwashing'));
        $this->dataset('Placeholder submitted handwashing', 'en', $this->datasetText('unapprovedmarker', 'handwashing'), 'submit-only');

        $approach = ApprovedContextApproach::fromConfig('local', 'small-model:3b');
        $this->assertNull($approach->pendingReason());
        $result = $approach->answer(['case_id' => 'T1', 'language' => 'en', 'question' => 'What about handwashing?']);

        $this->assertSame('placeholder approved answer', $result['answer']);
        $this->assertSame('yes', $result['output_valid']);
        $this->assertSame(['approved-dataset:'.$approved->id.' Placeholder approved handwashing'], $result['sources']);
        Http::assertSent(function (Request $r): bool {
            $prompt = json_encode($r['messages']);

            return $r->url() === 'http://127.0.0.1:11434/api/chat'
                && $r['model'] === 'small-model:3b'
                && $r['options'] === ['temperature' => ModelOnlyApproach::TEMPERATURE, 'seed' => ModelOnlyApproach::SEED, 'num_predict' => ModelOnlyApproach::NUM_PREDICT]
                && str_contains($prompt, 'approvedmarker')
                && ! str_contains($prompt, 'unapprovedmarker');
        });
    }

    public function test_approach_b_does_not_call_the_model_when_no_approved_passage_matches(): void
    {
        Http::fake();
        $this->dataset('Placeholder approved handwashing', 'en', $this->datasetText('alpha', 'handwashing'));

        $result = ApprovedContextApproach::fromConfig('local', 'small-model:3b')
            ->answer(['case_id' => 'T2', 'language' => 'en', 'question' => 'Zzyzx qwerty?']);

        $this->assertSame(ApprovedContextApproach::NO_CONTEXT_ANSWER, $result['answer']);
        $this->assertSame('n/a', $result['output_valid']);
        $this->assertSame([], $result['sources']);
        Http::assertNothingSent();
    }

    public function test_approach_b_uses_only_the_questions_language_and_never_retired_datasets(): void
    {
        $this->dataset('Placeholder english', 'en', $this->datasetText('englishmarker', 'handwashing'));
        $retired = $this->dataset('Placeholder retired tagalog', 'tl', $this->datasetText('retiredmarker', 'handwashing'));
        app(ChatbotDatasetWorkflow::class)->retire($this->admin, ChatbotDataset::query()->findOrFail($retired->chatbot_dataset_id));

        $approach = ApprovedContextApproach::fromConfig('local', 'small-model:3b');

        $this->assertSame([], $approach->passages('handwashing placeholder', 'tl'));
        $this->assertNotSame([], $approach->passages('handwashing placeholder', 'en'));
    }

    public function test_comparison_run_answers_with_b_locally_and_the_cloud_guard_covers_b(): void
    {
        $this->dataset('Placeholder approved', 'en', $this->datasetText('alpha', 'handwashing'));

        // Cloud endpoint for B is refused without the separate approval flag, and nothing is sent.
        Http::fake();
        $this->artisan('chatbot:compare', ['--approaches' => 'approved_context', '--no-interaction' => true])->assertFailed();
        Http::assertNothingSent();

        // Local run: B is no longer pending.
        $this->fakeModel();
        $this->artisan('chatbot:compare', [
            '--approaches' => 'approved_context', '--provider' => 'local', '--model' => 'small-model:3b',
            '--language' => 'en', '--limit' => 2, '--no-interaction' => true,
        ])->assertSuccessful();

        $files = Storage::disk(ComparisonFiles::DISK)->allFiles();
        $runId = explode('/', $files[0])[1];
        $statuses = collect(ComparisonFiles::read($runId, 'results.csv'))->pluck('status')->unique()->values()->all();
        $this->assertNotContains('pending', $statuses);
        $this->assertNotContains('error', $statuses);
    }
}
