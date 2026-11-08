<?php

namespace Tests\Feature;

use App\Services\ChatbotComparison\ComparisonFiles;
use App\Services\ChatbotComparison\ComparisonSummary;
use App\Services\ChatbotComparison\ModelOnlyApproach;
use App\Services\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * SR-09 Phase 1: comparison tooling only. No real model is called (Http::fake / preventStrayRequests),
 * Approach B is always pending, the resident chatbot is untouched, and no scores are generated.
 */
class ChatbotComparisonTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_KEY = 'test-key-not-real-0000';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake(ComparisonFiles::DISK);
        config(['services.ollama.api_key' => self::TEST_KEY, 'services.ollama.base_url' => 'http://127.0.0.1:11434']);
    }

    private function fakeLocalModel(): void
    {
        Http::fake([
            'http://127.0.0.1:11434/api/chat' => Http::response(['message' => ['content' => '{"answer":"placeholder model answer","points":["placeholder point"]}']]),
            'http://127.0.0.1:11434/api/embeddings' => Http::response(['embedding' => array_fill(0, 8, 0.1)]),
        ]);
    }

    /** @return array{0: string, 1: array<string, string>} run id and file contents */
    private function onlyRun(): array
    {
        $files = Storage::disk(ComparisonFiles::DISK)->allFiles();
        $this->assertNotEmpty($files);
        $runId = explode('/', $files[0])[1];
        $contents = [];
        foreach ($files as $file) {
            $contents[basename($file)] = (string) Storage::disk(ComparisonFiles::DISK)->get($file);
        }

        return [$runId, $contents];
    }

    public function test_question_set_uses_existing_questions_and_marks_bikol_as_pending_review(): void
    {
        $cases = ComparisonFiles::questions();
        $this->assertCount(77, $cases);
        $this->assertSame(count($cases), count(array_unique(array_column($cases, 'case_id'))));

        $reference = file_get_contents(resource_path('health_docs/_reference_only/chatbot_test_checklist.csv'))
            .file_get_contents(resource_path('health_docs/_reference_only/acceptable_questions_by_intent.txt'));
        foreach ($cases as $case) {
            if ($case['language'] === 'bcl') {
                $this->assertSame('pending_linguist_review', $case['review_status'], $case['case_id']);
            }
            if ($case['origin'] === 'safety_probe') {
                $this->assertSame('en', $case['language'], 'safety probes are English only');
                $this->assertSame('pending_health_review', $case['review_status']);

                continue;
            }
            // Every non-probe question is copied verbatim from the existing reference files (nothing invented).
            $this->assertStringContainsString($case['question'], $reference, $case['case_id']);
        }
    }

    public function test_dry_run_and_cloud_guard_send_nothing_and_write_nothing(): void
    {
        Http::fake();

        $this->artisan('chatbot:compare', ['--dry-run' => true])->assertSuccessful();
        // Defaults resolve to the cloud model: refused without the separate approval flag (D-C9).
        $this->artisan('chatbot:compare', ['--no-interaction' => true])->assertFailed();
        $this->artisan('chatbot:compare', ['--approaches' => 'model_only'])->assertFailed();

        Http::assertNothingSent();
        $this->assertSame([], Storage::disk(ComparisonFiles::DISK)->allFiles());
    }

    public function test_template_writes_pending_files_without_calling_any_model(): void
    {
        Http::fake();

        $this->artisan('chatbot:compare', ['--template' => true])->assertSuccessful();
        Http::assertNothingSent();

        [$runId, $files] = $this->onlyRun();
        $this->assertMatchesRegularExpression('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $runId);
        $names = array_keys($files);
        sort($names);
        $this->assertSame(['results.csv', 'run_info.csv', 'scoring_key.csv', 'scoring_sheet.csv'], $names);

        $results = ComparisonFiles::read($runId, 'results.csv');
        $this->assertCount(54 * 3, $results, '54 counted-language cases (Bikol excluded) × 3 approaches');
        $this->assertSame(['pending'], array_values(array_unique(array_column($results, 'status'))));
        $this->assertNotContains('bcl', array_column($results, 'language'));

        $this->assertStringContainsString('NOT trained or fine-tuned', $files['run_info.csv']);
        $this->assertStringContainsString('licensed healthcare professional', $files['run_info.csv']);
        $this->assertStringContainsString('PENDING — no answer generated yet', $files['scoring_sheet.csv']);
        foreach (['model_only', 'approved_context', 'current'] as $approach) {
            $this->assertStringNotContainsString($approach, $files['scoring_sheet.csv'], 'blind sheet');
        }
        $this->assertStringContainsString('approved_context', $files['scoring_key.csv']);
        foreach ($files as $content) {
            $this->assertStringNotContainsString(self::TEST_KEY, $content);
        }
    }

    public function test_local_run_isolates_approaches_and_keeps_the_resident_chatbot_unchanged(): void
    {
        $this->fakeLocalModel();
        $defaultModel = config('services.ollama.chat_model');

        $this->artisan('chatbot:compare', [
            '--provider' => 'local', '--model' => 'small-model:3b', '--language' => 'en', '--limit' => 3, '--no-interaction' => true,
        ])->assertSuccessful();

        [$runId] = $this->onlyRun();
        $results = collect(ComparisonFiles::read($runId, 'results.csv'))->groupBy('approach');

        // A: model only — answers with fixed settings, no retrieval, local endpoint, no key.
        $this->assertSame(['ok'], $results['model_only']->pluck('status')->unique()->values()->all());
        $this->assertSame(['yes'], $results['model_only']->pluck('output_valid')->unique()->values()->all());
        $this->assertSame(['n/a'], $results['model_only']->pluck('source_hit')->unique()->values()->all());
        Http::assertSent(function (Request $r): bool {
            return $r->url() === 'http://127.0.0.1:11434/api/chat'
                && ! $r->hasHeader('Authorization')
                && $r['model'] === 'small-model:3b'
                && $r['options'] === ['temperature' => ModelOnlyApproach::TEMPERATURE, 'seed' => ModelOnlyApproach::SEED, 'num_predict' => ModelOnlyApproach::NUM_PREDICT]
                && count($r['messages']) === 2 && $r['messages'][1]['role'] === 'user';
        });
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'ollama.com'));

        // B: always pending, never substituted.
        $this->assertSame(['pending'], $results['approved_context']->pluck('status')->unique()->values()->all());
        $this->assertStringContainsString('SR-07b', $results['approved_context']->first()['pending_reason']);
        $this->assertSame([''], $results['approved_context']->pluck('answer')->unique()->values()->all());

        // C: current system ran.
        $this->assertSame(['ok'], $results['current']->pluck('status')->unique()->values()->all());

        // Resident chatbot untouched: settings and the shared RagService instance unchanged; no history.
        $this->assertSame($defaultModel, config('services.ollama.chat_model'));
        $this->assertSame('gemma4:31b-cloud', app(RagService::class)->chatSettings()['chat_model']);
        $this->assertFalse(Schema::hasTable('chatbot_messages') && DB::table('chatbot_messages')->exists());

        // Blind sheet: only answered rows (A and C), approaches only in the separate key.
        $sheet = ComparisonFiles::read($runId, 'scoring_sheet.csv');
        $key = ComparisonFiles::read($runId, 'scoring_key.csv');
        $this->assertCount(6, $sheet);
        $this->assertSame(array_map(fn ($r) => $r['case_id'].'|'.$r['answer_slot'], $sheet), array_map(fn ($k) => $k['case_id'].'|'.$k['answer_slot'], $key));
        $this->assertArrayNotHasKey('approach', $sheet[0]);
    }

    public function test_bikol_questions_need_the_explicit_pending_review_flag_and_are_never_counted(): void
    {
        $this->fakeLocalModel();

        $this->artisan('chatbot:compare', ['--provider' => 'local', '--model' => 'small-model:3b', '--language' => 'bcl', '--template' => true])->assertSuccessful();
        [$runId] = $this->onlyRun();
        $this->assertSame([], ComparisonFiles::read($runId, 'results.csv'), 'no Bikol case without the flag');

        Storage::fake(ComparisonFiles::DISK);
        $this->artisan('chatbot:compare', ['--provider' => 'local', '--model' => 'small-model:3b', '--language' => 'bcl', '--limit' => 2,
            '--include-pending-review' => true, '--approaches' => 'model_only,approved_context', '--no-interaction' => true])->assertSuccessful();
        [$runId] = $this->onlyRun();
        $results = ComparisonFiles::read($runId, 'results.csv');
        $this->assertSame(['pending_linguist_review'], array_values(array_unique(array_column($results, 'review_status'))));

        $summary = ComparisonSummary::build($results, ComparisonFiles::read($runId, 'scoring_key.csv'), null);
        $all = collect($summary['rows'])->firstWhere(fn ($r) => $r['approach'] === 'model_only' && $r['language'] === 'all');
        $this->assertSame('0', $all['counted_cases']);
        $this->assertSame('2', $all['not_counted_pending_review']);
    }

    public function test_blind_order_is_reproducible_from_run_and_case(): void
    {
        $approaches = ['current', 'model_only', 'approved_context'];
        $first = ComparisonFiles::blindOrder('20261030-120000-abcdef', 'CL-T05', $approaches);
        $this->assertSame($first, ComparisonFiles::blindOrder('20261030-120000-abcdef', 'CL-T05', array_reverse($approaches)));
        $this->assertEqualsCanonicalizing($approaches, $first);
    }

    /**
     * @return array{0: list<array<string, string>>, 1: list<array<string, string>>}
     */
    private function syntheticRun(int $cases = 2): array
    {
        $results = [];
        $key = [];
        for ($i = 1; $i <= $cases; $i++) {
            foreach (['model_only' => 100, 'approved_context' => 900] as $approach => $latency) {
                $results[] = ['case_id' => "C{$i}", 'language' => 'en', 'review_status' => 'existing_reference', 'approach' => $approach,
                    'status' => 'ok', 'latency_ms' => (string) $latency, 'output_valid' => 'yes', 'source_hit' => $approach === 'model_only' ? 'n/a' : 'yes'];
                $key[] = ['case_id' => "C{$i}", 'answer_slot' => $approach === 'model_only' ? '1' : '2', 'approach' => $approach];
            }
        }

        return [$results, $key];
    }

    /**
     * @return list<array<string, string>>
     */
    private function scores(array $a, array $b, int $cases = 2): array
    {
        $rows = [];
        for ($i = 1; $i <= $cases; $i++) {
            foreach (['1' => $a, '2' => $b] as $slot => [$accuracy, $grounded, $safety, $language]) {
                $rows[] = ['case_id' => "C{$i}", 'answer_slot' => $slot, 'accuracy_1_5' => (string) $accuracy,
                    'groundedness_yes_partly_no' => $grounded, 'safety_pass_yes_no' => $safety, 'language_quality_1_5' => (string) $language];
            }
        }

        return $rows;
    }

    public function test_verdict_follows_the_agreed_criteria_and_never_speed(): void
    {
        [$results, $key] = $this->syntheticRun();

        $this->assertStringContainsString('human scores', ComparisonSummary::build($results, $key, null)['verdict']);

        // B more accurate and grounded, A much faster → B (speed ignored).
        $verdict = ComparisonSummary::build($results, $key, $this->scores([3, 'partly', 'yes', 4], [5, 'yes', 'yes', 4]))['verdict'];
        $this->assertStringStartsWith('Approach B performs better', $verdict);
        $this->assertStringContainsString('Speed was not used', $verdict);

        // A safety failure in B → no verdict for B; A is less accurate → no winner.
        $verdict = ComparisonSummary::build($results, $key, $this->scores([3, 'partly', 'yes', 4], [5, 'yes', 'no', 4]))['verdict'];
        $this->assertStringStartsWith('No verdict: neither approach', $verdict);

        // Equal scores: speed alone never makes A the winner.
        $verdict = ComparisonSummary::build($results, $key, $this->scores([4, 'yes', 'yes', 4], [4, 'yes', 'yes', 4]))['verdict'];
        $this->assertStringStartsWith('No verdict: neither approach', $verdict);

        // Better accuracy but worse language quality → no verdict.
        $verdict = ComparisonSummary::build($results, $key, $this->scores([3, 'yes', 'yes', 5], [5, 'yes', 'yes', 2]))['verdict'];
        $this->assertStringStartsWith('No verdict: neither approach', $verdict);

        // Incomplete scores → no verdict.
        $partial = $this->scores([3, 'yes', 'yes', 4], [5, 'yes', 'yes', 4]);
        $partial[3] = array_merge($partial[3], ['accuracy_1_5' => '', 'groundedness_yes_partly_no' => '', 'safety_pass_yes_no' => '', 'language_quality_1_5' => '']);
        $this->assertStringContainsString('pending or incomplete', ComparisonSummary::build($results, $key, $partial)['verdict']);

        // Invalid values are reported, not guessed.
        $invalid = $this->scores([7, 'maybe', 'yes', 4], [5, 'yes', 'yes', 4]);
        $this->assertNotEmpty(ComparisonSummary::build($results, $key, $invalid)['errors']);
    }

    public function test_pending_approach_b_blocks_any_verdict(): void
    {
        [$results, $key] = $this->syntheticRun();
        foreach ($results as &$row) {
            if ($row['approach'] === 'approved_context') {
                $row['status'] = 'pending';
            }
        }
        unset($row);

        $this->assertStringContainsString('Approach B is pending', ComparisonSummary::build($results, $key, $this->scores([5, 'yes', 'yes', 5], [5, 'yes', 'yes', 5]))['verdict']);
        $this->assertSame(90, ComparisonSummary::percentile(range(1, 100), 90));
    }

    public function test_summary_command_on_a_template_run_reports_pending_and_no_verdict(): void
    {
        Http::fake();
        $this->artisan('chatbot:compare', ['--template' => true, '--limit' => 3])->assertSuccessful();
        [$runId] = $this->onlyRun();

        $this->assertSame(0, Artisan::call('chatbot:compare-summary', ['--run' => $runId]));
        $md = (string) Storage::disk(ComparisonFiles::DISK)->get("comparisons/{$runId}/summary.md");
        $this->assertStringContainsString('No verdict', $md);
        $this->assertStringContainsString('not trained or fine-tuned', $md);
        $this->assertStringContainsString('**pending** (no completed scoring sheet)', $md);
        $this->assertTrue(Storage::disk(ComparisonFiles::DISK)->exists("comparisons/{$runId}/summary.csv"));

        // Path traversal and unknown runs are refused.
        $this->assertSame(1, Artisan::call('chatbot:compare-summary', ['--run' => '../../etc']));
        $this->assertSame(1, Artisan::call('chatbot:compare-summary', ['--run' => $runId, '--scores' => '../results.csv']));
        // A file without the scoring columns is rejected, not misread.
        $this->assertSame(1, Artisan::call('chatbot:compare-summary', ['--run' => $runId, '--scores' => 'results.csv']));
        Http::assertNothingSent();
    }
}
