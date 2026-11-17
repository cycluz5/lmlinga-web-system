<?php

namespace Tests\Feature;

use App\Models\HealthChunk;
use App\Services\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * English / Tagalog: what reaches the language model (retrieve -> identity gate -> section scoping),
 * checked against the real health documents. The model itself is not called.
 */
class EnglishTagalogScopingTest extends TestCase
{
    use RefreshDatabase;

    private RagService $rag;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['embedding' => [0.0]], 200)]);

        $root = resource_path('health_docs_v2');
        foreach (['en', 'tl'] as $language) {
            foreach (glob("{$root}/{$language}/*/*.txt") ?: [] as $path) {
                HealthChunk::create([
                    'language' => $language,
                    'category' => basename(dirname($path)),
                    'source_file' => basename($path),
                    'chunk_index' => 0,
                    'content' => (string) file_get_contents($path),
                    'embedding' => [0.0],
                ]);
            }
        }

        $this->rag = app(RagService::class);
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod(RagService::class, $method);
        $m->setAccessible(true);

        return $m->invoke($this->rag, ...$args);
    }

    /** @return array{sources: list<string>, context: string} what would be sent to the model */
    private function sentToModel(string $question, string $language): array
    {
        $chunks = array_values((array) ($this->rag->retrieve($question, $language)['chunks'] ?? []));
        if ($chunks !== []) {
            $chunks = $language === 'tl'
                ? $this->invoke('filterTagalogSupportedChunks', $chunks, $question)
                : $this->invoke('filterEnglishSupportedChunks', $chunks, $question);
        }
        if ($chunks !== []) {
            $chunks = $language === 'tl'
                ? $this->invoke('scopeTagalogChunksForMistral', $chunks, $question)
                : $this->invoke('scopeEnglishChunksForMistral', $chunks, $question);
        }

        return [
            'sources' => array_values(array_unique(array_map(fn ($c) => $c->source_file, $chunks))),
            'context' => implode("\n", array_map(fn ($c) => (string) $c->content, $chunks)),
        ];
    }

    public function test_english_intents_for_questions_the_old_keywords_missed(): void
    {
        $this->assertSame('diagnosis', $this->invoke('detectEnglishSupportIntent', 'How is autism assessed?'));
        $this->assertSame('diagnosis', $this->invoke('detectEnglishSupportIntent', 'How is dengue diagnosed?'));
        $this->assertSame('care', $this->invoke('detectEnglishSupportIntent', 'What care and interventions can help a child with autism?'));
        $this->assertSame('care', $this->invoke('detectEnglishSupportIntent', 'Can a child with autism be helped from early childhood?'));
        $this->assertSame('factors', $this->invoke('detectEnglishSupportIntent', 'What factors make a child more likely to have autism?'));
    }

    public function test_existing_english_intents_are_unchanged(): void
    {
        $this->assertSame('definition', $this->invoke('detectEnglishSupportIntent', 'What is autism?'));
        $this->assertSame('definition', $this->invoke('detectEnglishSupportIntent', 'What is newborn hearing screening?'));
        $this->assertSame('symptoms', $this->invoke('detectEnglishSupportIntent', 'What are the symptoms of dengue?'));
        $this->assertSame('causes', $this->invoke('detectEnglishSupportIntent', 'What causes autism?'));
        $this->assertSame('prevention', $this->invoke('detectEnglishSupportIntent', 'How can dengue be prevented?'));
        $this->assertSame('treatment', $this->invoke('detectEnglishSupportIntent', 'How is dengue treated?'));
    }

    public function test_english_autism_questions_get_the_matching_section(): void
    {
        $assessed = $this->sentToModel('How is autism assessed?', 'en');
        $this->assertContains('autism.txt', $assessed['sources']);
        $this->assertStringContainsString('psychosocial interventions', $assessed['context']);

        $care = $this->sentToModel('What care and interventions can help a child with autism?', 'en');
        $this->assertStringContainsString('psychosocial interventions', $care['context']);

        $factors = $this->sentToModel('What factors make a child more likely to have autism?', 'en');
        $this->assertStringContainsString('genetic', mb_strtolower($factors['context']));
    }

    public function test_new_intents_fall_back_to_the_definition_when_the_document_has_no_such_section(): void
    {
        // dengue.txt has no assessment section: the model still gets the definition, as "general" questions did before.
        $result = $this->sentToModel('How is dengue diagnosed?', 'en');

        $this->assertContains('dengue.txt', $result['sources']);
        $this->assertNotSame('', trim($result['context']));
    }

    public function test_english_vaccination_document_passes_the_identity_gate(): void
    {
        foreach ([
            'Why are vaccines important for my child?',
            'What does a fully vaccinated child mean?',
            'What is the DOH vaccination schedule (EPI) for babies?',
        ] as $question) {
            $this->assertContains('baby_is_a_champion.txt', $this->sentToModel($question, 'en')['sources'], $question);
        }
    }

    public function test_tagalog_documents_with_english_file_names_are_found_by_their_tagalog_name(): void
    {
        $this->assertContains('measles.txt', $this->sentToModel('Ano ang tigdas?', 'tl')['sources']);
        $this->assertContains('autism.txt', $this->sentToModel('Ano ang autism?', 'tl')['sources']);
        $this->assertContains('asthma.txt', $this->sentToModel('Ano ang mga sintomas ng hika?', 'tl')['sources']);
        $this->assertContains('asthma.txt', $this->sentToModel('Paano pinamamahalaan ang hika?', 'tl')['sources']);
    }

    public function test_a_question_the_headings_do_not_name_gets_the_section_with_the_matching_text(): void
    {
        // chickenpox.txt keeps the risk and spread text under other headings and has no "Causes" section
        $virus = $this->sentToModel('What virus causes chickenpox?', 'en');
        $this->assertContains('chickenpox.txt', $virus['sources']);
        $this->assertStringContainsString('varicella-zoster', $virus['context']);

        $risk = $this->sentToModel('Who is at risk of getting chickenpox?', 'en');
        $this->assertStringContainsString('has not had chickenpox', $risk['context']);

        $vaccine = $this->sentToModel('Does the chickenpox vaccine protect against the disease?', 'en');
        $this->assertStringContainsString('Protect yourself with the vaccine', $vaccine['context']);
    }

    public function test_a_strict_intent_that_has_its_section_does_not_get_extra_text(): void
    {
        // autism.txt has a Causes section: "What causes autism?" must still get only that section.
        $causes = $this->sentToModel('What causes autism?', 'en');

        $this->assertContains('autism.txt', $causes['sources']);
        $this->assertStringContainsString('environmental', $causes['context']);
        $this->assertStringNotContainsString('psychosocial interventions', $causes['context']);
    }
}
