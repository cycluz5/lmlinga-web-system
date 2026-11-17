<?php

namespace Tests\Feature;

use App\Models\HealthChunk;
use App\Services\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bikol questions answered from the real verified documents in
 * resources/health_docs_v2/bcl (context mode, no AI model, no embeddings).
 */
class BikolRetrievalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['embedding' => [0.0]], 200)]);

        $root = resource_path('health_docs_v2');
        foreach (['bcl', 'en', 'tl'] as $language) {
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
    }

    protected function tearDown(): void
    {
        // RagService::ask() calls set_time_limit(180); under PHPUnit that would cap the whole run.
        set_time_limit(0);

        parent::tearDown();
    }

    private function ask(string $question, ?string $category = null, ?string $hint = null, string $mode = 'context'): string
    {
        $rag = app(RagService::class);
        $rag->setAnswerMode($mode);

        $result = $rag->ask($question, 'bcl', $category, $hint);

        // List-style answers (diagnosis, management, ...) come back in "points".
        return trim(((string) ($result['answer'] ?? '')) . "\n" . implode("\n", (array) ($result['points'] ?? [])));
    }

    public function test_diagnosis_question_for_a_fully_bulleted_document(): void
    {
        $answer = $this->ask('Ono ana diyagnosis kana hika?');

        $this->assertStringContainsString('Diri madaling maisihan ana hika', $answer);
    }

    public function test_diagnosis_and_management_questions_in_the_local_ai_mode(): void
    {
        // "Local AI model": the trained intent classifier picks the intent first.
        $this->assertStringContainsString(
            'Diri madaling maisihan ana hika',
            $this->ask('Ono ana diyagnosis kana hika?', null, null, 'ai')
        );
        $this->assertStringContainsString(
            'Nganing masiguradong kontrolado ana hika',
            $this->ask('Paono pamahalaan ana hika?', null, null, 'ai')
        );
    }

    public function test_management_question_for_hika(): void
    {
        $this->assertStringContainsString('Nganing masiguradong kontrolado ana hika', $this->ask('Paono pamahalaan ana hika?'));
    }

    public function test_short_topic_word_beats_the_inherited_topic(): void
    {
        $service = app(RagService::class);

        $this->assertTrue($service->questionHasExplicitTopic('Ono ana ubo?', 'bcl'));
        $this->assertStringContainsString('ubo', mb_strtolower((string) $service->deriveTopicHintFromText('Ono ana ubo?', 'bcl')));

        $after = $this->ask('Ono ana ubo?', null, 'hika');
        $alone = $this->ask('Ono ana ubo?');

        $this->assertNotSame('', $after);
        $this->assertSame($alone, $after, '"Ono ana ubo?" must not inherit the earlier hika topic.');
    }

    public function test_selected_category_does_not_hide_an_explicit_topic(): void
    {
        $answer = $this->ask('Ono ana ubo?', 'child_care');

        $this->assertNotSame('', $answer);
        $this->assertSame($this->ask('Ono ana ubo?'), $answer);
    }

    public function test_leptospirosis_treatment_heading_is_one_line(): void
    {
        $answer = $this->ask('Paono binubulong ana leptospirosis?');

        $this->assertStringContainsString('Penicillin', $answer);
    }

    public function test_new_vocabulary_aliases_find_their_topics(): void
    {
        $service = app(RagService::class);

        foreach ([
            'Ono ana rayuma?' => 'gout',
            'Ono ana kolera?' => 'cholera',
            'Ono ana pagkalilipaton?' => 'dementia',
            'Ono ana pantal?' => 'rashes',
            'Ono ana paninigarilyo?' => 'smoking',
            'Ono ana flu?' => 'influenza',
        ] as $question => $slug) {
            $this->assertTrue(
                $service->questionHasExplicitTopic($question, 'bcl'),
                "{$question} should be recognised as naming a topic ({$slug})."
            );
        }
    }

    private function sourcesFor(string $question, string $mode = 'context'): array
    {
        $rag = app(RagService::class);
        $rag->setAnswerMode($mode);

        return (array) ($rag->ask($question, 'bcl')['sources'] ?? []);
    }

    public function test_multi_word_aliases_retrieve_their_topic_document(): void
    {
        $rag = app(RagService::class);

        foreach ([
            'Ono ana atake sa poso?' => 'heart_attack.txt',
            'Ono ana kolog sa likod?' => 'low_back_pain.txt',
            'Ono ana ilang sa bato?' => 'chronic_kidney_disease.txt',
            'Ono ana kulog kan payo?' => 'headache.txt',
            'Ono ana maklog na payo?' => 'headache.txt',
            'Ono ana nailo sa pagkaon?' => 'foodborne_illness.txt',
            'Ono ana pagtao kan rogo?' => 'blood_donation.txt',
            'Ono ana pagtao sa rogo?' => 'blood_donation.txt',
            'Ono ana amay na pangingigin?' => 'pre_term_birth.txt',
            'Ono ana tapos na a regla?' => 'menopause.txt',
            'Ono ana tapos na an pagregla?' => 'menopause.txt',
        ] as $question => $file) {
            $files = collect($rag->retrieve($question, 'bcl')['chunks'])->pluck('source_file')->all();

            $this->assertSame($file, $files[0] ?? null, "{$question} should retrieve {$file} first.");
        }
    }

    public function test_multi_word_aliases_answer_from_their_document(): void
    {
        // These documents have an extractable definition. (blood_donation.txt and
        // pre_term_birth.txt have none, so their definition questions stay "no information".)
        foreach ([
            'Ono ana atake sa poso?' => 'heart_attack.txt',
            'Ono ana kolog sa likod?' => 'low_back_pain.txt',
            'Ono ana ilang sa bato?' => 'chronic_kidney_disease.txt',
            'Ono ana kulog kan payo?' => 'headache.txt',
            'Ono ana nailo sa pagkaon?' => 'foodborne_illness.txt',
            'Ono ana tapos na a regla?' => 'menopause.txt',
        ] as $question => $file) {
            $this->assertContains($file, $this->sourcesFor($question), $question);
        }
    }

    public function test_multi_word_aliases_work_for_a_section_question_and_in_local_ai_mode(): void
    {
        $this->assertContains('low_back_pain.txt', $this->sourcesFor('Ono ana sinyales kana kolog sa likod?'));
        $this->assertContains('low_back_pain.txt', $this->sourcesFor('Ono ana sinyales kana kolog sa likod?', 'ai'));
        $this->assertContains('heart_attack.txt', $this->sourcesFor('Ono ana mga sinyales kana atake sa poso?'));
    }

    public function test_words_of_a_phrase_alias_are_not_aliases_on_their_own(): void
    {
        $method = new \ReflectionMethod(RagService::class, 'bikolTopicAliasTokens');
        $method->setAccessible(true);
        $tokens = $method->invoke(app(RagService::class), 'heart_attack.txt');

        $this->assertContains('atakesaposo', $tokens);
        $this->assertNotContains('poso', $tokens);
        $this->assertNotContains('ilang', $method->invoke(app(RagService::class), 'chronic_kidney_disease.txt'));
    }

    public function test_high_blood_pressure_is_not_confused_with_low_blood_pressure(): void
    {
        foreach ([
            'Ono ana alangkaw na presyon kana rogo?',
            'Ono ana mga sinyales kana alangkaw na presyon kana rogo?',
            'Paono pamahalaan ana alangkaw na presyon kana rogo?',
        ] as $question) {
            $this->assertContains('high_blood_pressure.txt', $this->sourcesFor($question), $question);
        }
        $this->assertContains('low_blood_pressure.txt', $this->sourcesFor('Ono ana mga sinyales kana mababang presyon kana rogo?'));
    }
}
