<?php

namespace App\Console\Commands;

use App\Exceptions\ChatbotAiUnavailableException;
use App\Services\ChatbotComparison\ApprovedContextApproach;
use App\Services\ChatbotComparison\ComparisonApproach;
use App\Services\ChatbotComparison\ComparisonFiles;
use App\Services\ChatbotComparison\CurrentSystemApproach;
use App\Services\ChatbotComparison\ModelOnlyApproach;
use App\Services\RagService;
use App\Support\OllamaChatEndpoint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * SR-09 Phase 1: run the fixed question set (resources/health_docs/_reference_only/comparison_questions.csv)
 * through the chatbot approaches and write results, a blind scoring sheet and its separate key as CSV in
 * private storage. Isolated from the resident chatbot: no setting changes, no chat history, no resident data.
 *
 * - A `model_only`: chat model without retrieval — locally hosted (or cloud rehearsal), configured, NOT trained.
 * - B `approved_context`: Linguist-approved datasets — always pending until SR-07b activation.
 * - C `current`: the current system (reference baseline).
 * Bikol-Iriga questions not yet reviewed by the Linguist are skipped unless --include-pending-review, and
 * never count towards a verdict. Requests to a cloud endpoint are refused unless --allow-cloud (D-C9).
 */
class CompareChatbotApproaches extends Command
{
    protected $signature = 'chatbot:compare
                            {--approaches=current,model_only,approved_context : Comma-separated approach keys}
                            {--provider= : Chat provider for A and C (auto, local, cloud)}
                            {--model= : Chat model for A and C}
                            {--language= : Only en, tl or bcl cases}
                            {--limit= : At most this many cases}
                            {--include-pending-review : Also run cases still awaiting review (never counted in a verdict)}
                            {--template : Write the CSV files with every answer marked pending, without calling any model}
                            {--dry-run : List cases and approach availability without writing or calling anything}
                            {--allow-cloud : Permit requests to a cloud endpoint (needs separate approval, D-C9)}';

    protected $description = 'Compare chatbot approaches (SR-09) on the fixed question set and write blind scoring sheets';

    public function handle(RagService $rag): int
    {
        try {
            $approaches = $this->approaches($rag);
            $cases = $this->cases();
        } catch (ChatbotAiUnavailableException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $template = (bool) $this->option('template');
        foreach ($approaches as $approach) {
            $this->line(sprintf('%-17s %s — %s', $approach->key(), $approach->label(), $approach->pendingReason() ?? 'available'));
        }
        $this->line(count($cases).' cases'.($template ? ' · template (no model calls)' : '').($this->option('dry-run') ? ' · dry run' : ''));

        if ($this->option('dry-run')) {
            foreach ($cases as $case) {
                $this->line("{$case['case_id']}  {$case['language']}  {$case['review_status']}  {$case['question']}");
            }

            return self::SUCCESS;
        }

        if (! $template && ($cloud = $this->cloudApproaches($approaches, $rag)) !== [] && ! $this->option('allow-cloud')) {
            $this->error('Refused: '.implode(', ', $cloud).' would send requests to a cloud endpoint. Cloud evaluation requests need separate approval (D-C9). Use --provider=local with an installed local model, --template, or --dry-run.');

            return self::FAILURE;
        }

        $runId = now()->format('Ymd-His').'-'.bin2hex(random_bytes(3));

        $chatCalls = 0;
        Http::globalRequestMiddleware(function (RequestInterface $request) use (&$chatCalls): RequestInterface {
            if (str_ends_with($request->getUri()->getPath(), '/api/chat')) {
                $chatCalls++;
            }

            return $request;
        });

        $results = [];
        foreach ($cases as $case) {
            foreach ($approaches as $approach) {
                $row = [
                    'run_id' => $runId, 'case_id' => $case['case_id'], 'language' => $case['language'], 'origin' => $case['origin'],
                    'review_status' => $case['review_status'], 'question' => $case['question'], 'expected_behaviour' => $case['expected_behaviour'],
                    'expected_source' => $case['expected_source'], 'approach' => $approach->key(), 'approach_label' => $approach->label(),
                ];
                $pending = $template ? 'Template: not run.' : $approach->pendingReason();
                if ($pending !== null) {
                    $results[] = $row + ['status' => 'pending', 'pending_reason' => $pending, 'source_hit' => 'not_run'];

                    continue;
                }

                $chatCalls = 0;
                $started = hrtime(true);
                try {
                    $answer = $approach->answer($case);
                    $results[] = $row + [
                        'status' => 'ok',
                        'latency_ms' => (string) (int) round((hrtime(true) - $started) / 1e6),
                        'chat_calls' => (string) $chatCalls,
                        'output_valid' => $answer['output_valid'],
                        'returned_language' => $answer['language'],
                        'answer' => mb_substr($answer['answer'], 0, 2000),
                        'points' => implode(' | ', $answer['points']),
                        'sources' => implode('; ', $answer['sources']),
                        'source_hit' => $this->sourceHit($case['expected_source'], $answer['sources'], $approach),
                    ];
                } catch (Throwable $e) {
                    $results[] = $row + [
                        'status' => 'error', 'latency_ms' => (string) (int) round((hrtime(true) - $started) / 1e6),
                        'chat_calls' => (string) $chatCalls, 'error' => $e::class, 'source_hit' => 'not_run',
                    ];
                }
            }
            $this->line($case['case_id'].' done');
        }

        [$sheet, $key] = $this->blindSheet($runId, $results, $template);
        $folder = ComparisonFiles::runFolder($runId);
        ComparisonFiles::put($runId, 'run_info.csv', ['setting', 'value'], $this->runInfo($runId, $approaches, $cases, $template));
        ComparisonFiles::put($runId, 'results.csv', ComparisonFiles::RESULT_COLUMNS, $results);
        ComparisonFiles::put($runId, 'scoring_sheet.csv', ComparisonFiles::SHEET_COLUMNS, $sheet);
        ComparisonFiles::put($runId, 'scoring_key.csv', ['case_id', 'answer_slot', 'approach'], $key);

        $counts = array_count_values(array_column($results, 'status'));
        $this->info(sprintf(
            'Run %s saved in storage/app/private/chatbot-evaluations/%s — ok %d, pending %d, errors %d. Give scoring_sheet.csv (not scoring_key.csv) to the reviewers.',
            $runId, $folder, $counts['ok'] ?? 0, $counts['pending'] ?? 0, $counts['error'] ?? 0
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<ComparisonApproach>
     */
    private function approaches(RagService $rag): array
    {
        $keys = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $this->option('approaches'))))));
        $approaches = [];
        foreach ($keys as $key) {
            $approaches[] = match ($key) {
                'current' => new CurrentSystemApproach($rag->withChatSettings($this->option('provider'), $this->option('model'))),
                'model_only' => ModelOnlyApproach::fromConfig($this->option('provider'), $this->option('model')),
                'approved_context' => new ApprovedContextApproach,
                default => throw new InvalidArgumentException("Unknown approach \"{$key}\" (use current, model_only, approved_context)."),
            };
        }
        if ($approaches === []) {
            throw new InvalidArgumentException('Choose at least one approach.');
        }

        return $approaches;
    }

    /**
     * @return list<array<string, string>>
     */
    private function cases(): array
    {
        $language = $this->option('language');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $includePending = (bool) $this->option('include-pending-review');

        $cases = [];
        foreach (ComparisonFiles::questions() as $case) {
            if ($language !== null && $case['language'] !== $language) {
                continue;
            }
            if (! $includePending && $case['review_status'] === 'pending_linguist_review') {
                continue;
            }
            $cases[] = $case;
            if ($limit !== null && count($cases) >= $limit) {
                break;
            }
        }

        return $cases;
    }

    /**
     * @param  list<ComparisonApproach>  $approaches
     * @return list<string>
     */
    private function cloudApproaches(array $approaches, RagService $rag): array
    {
        $cloud = [];
        foreach ($approaches as $approach) {
            if (! $approach->usesChatModel() || $approach->pendingReason() !== null) {
                continue;
            }
            $endpoint = match (true) {
                $approach instanceof ModelOnlyApproach => $approach->endpoint(),
                default => $rag->withChatSettings($this->option('provider'), $this->option('model'))->chatSettings()['endpoint'],
            };
            if ($endpoint['provider'] === OllamaChatEndpoint::CLOUD) {
                $cloud[] = $approach->key();
            }
        }

        return $cloud;
    }

    /**
     * @param  list<string>  $sources
     */
    private function sourceHit(string $expected, array $sources, ComparisonApproach $approach): string
    {
        if ($approach instanceof ModelOnlyApproach) {
            return 'n/a';
        }
        if (trim($expected) === '') {
            return 'not_tagged';
        }

        return in_array(mb_strtolower($expected), array_map('mb_strtolower', $sources), true) ? 'yes' : 'no';
    }

    /**
     * @param  list<array<string, string>>  $results
     * @return array{0: list<array<string, string>>, 1: list<array<string, string>>}
     */
    private function blindSheet(string $runId, array $results, bool $template): array
    {
        $byCase = [];
        foreach ($results as $r) {
            if ($r['status'] === 'ok' || $template) {
                $byCase[$r['case_id']][$r['approach']] = $r;
            }
        }

        $sheet = [];
        $key = [];
        foreach ($byCase as $caseId => $answers) {
            foreach (ComparisonFiles::blindOrder($runId, $caseId, array_keys($answers)) as $slot => $approach) {
                $r = $answers[$approach];
                $sheet[] = [
                    'case_id' => $caseId, 'language' => $r['language'], 'review_status' => $r['review_status'], 'question' => $r['question'],
                    'expected_behaviour' => $r['expected_behaviour'], 'answer_slot' => (string) ($slot + 1),
                    'answer' => $template ? 'PENDING — no answer generated yet' : ($r['answer'] ?? ''),
                    'points' => $r['points'] ?? '',
                ];
                $key[] = ['case_id' => $caseId, 'answer_slot' => (string) ($slot + 1), 'approach' => $approach];
            }
        }

        return [$sheet, $key];
    }

    /**
     * @param  list<ComparisonApproach>  $approaches
     * @param  list<array<string, string>>  $cases
     * @return list<array{setting: string, value: string}>
     */
    private function runInfo(string $runId, array $approaches, array $cases, bool $template): array
    {
        $info = [
            ['setting' => 'run_id', 'value' => $runId],
            ['setting' => 'created_at', 'value' => now()->toIso8601String()],
            ['setting' => 'mode', 'value' => $template ? 'template (no model calls)' : 'run'],
            ['setting' => 'question_file', 'value' => 'resources/'.ComparisonFiles::QUESTION_FILE],
            ['setting' => 'question_file_sha256', 'value' => ComparisonFiles::questionFileHash()],
            ['setting' => 'cases', 'value' => (string) count($cases)],
            ['setting' => 'bikol_unreviewed_included', 'value' => $this->option('include-pending-review') ? 'yes (not counted)' : 'no'],
        ];
        foreach ($approaches as $approach) {
            $info[] = ['setting' => 'approach '.$approach->key(), 'value' => $approach->label().' — '.($approach->pendingReason() ?? 'available')];
            if ($approach instanceof ModelOnlyApproach) {
                $info[] = ['setting' => 'approach model_only model', 'value' => $approach->model().' via '.$approach->endpoint()['provider'].' ('.(parse_url($approach->endpoint()['url'], PHP_URL_HOST) ?: '').')'];
                $info[] = ['setting' => 'approach model_only fixed settings', 'value' => 'temperature '.ModelOnlyApproach::TEMPERATURE.', seed '.ModelOnlyApproach::SEED.', num_predict '.ModelOnlyApproach::NUM_PREDICT];
            }
        }
        $info[] = ['setting' => 'note_model', 'value' => 'Approach A uses a hosted model configured by a prompt. It is NOT trained or fine-tuned by LMLinga.'];
        $info[] = ['setting' => 'note_scores', 'value' => 'Scores must be entered by a qualified Linguist (language quality) and a licensed healthcare professional (accuracy, groundedness, safety). This tool generates no scores.'];
        $info[] = ['setting' => 'note_privacy', 'value' => 'Questions come from reference files; no resident data; no chat history saved.'];

        return $info;
    }
}
