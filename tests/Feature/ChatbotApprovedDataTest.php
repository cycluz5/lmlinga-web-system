<?php

namespace Tests\Feature;

use App\Models\ChatbotDataset;
use App\Models\ChatbotDatasetVersion;
use App\Models\ChatbotTerm;
use App\Models\User;
use App\Services\ChatbotDatasetWorkflow;
use App\Services\ChatbotTermWorkflow;
use App\Services\RagService;
use App\Support\ApprovedBikolTerms;
use App\Support\ApprovedChatbotDatasets;
use App\Support\ChatbotDatasetFileInspector;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\AuthenticatesStaff;
use Tests\TestCase;

/**
 * The chatbot uses Linguist-approved data:
 * approved Bikol-Iriga terms are given to the resident chatbot as a prompt glossary.
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
        // These tests cover the Linguist review flow, which stays available behind this setting.
        config(['lmlinga.chatbot_datasets.require_review' => true, 'lmlinga.chatbot.terms_require_review' => true]);
        Http::preventStrayRequests();
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
}
