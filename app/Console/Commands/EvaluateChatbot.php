<?php

namespace App\Console\Commands;

use App\Exceptions\ChatbotAiUnavailableException;
use App\Services\RagService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * SR-08 evaluation (D-L6): run the reference checklist (resources/health_docs/_reference_only/
 * chatbot_test_checklist.csv) through RagService with a chosen provider/model and write a CSV to private
 * storage (disk `chatbot_evaluations`). Saves no chat history, changes no setting, and never switches
 * providers. Pass/Fail is left blank for a human reviewer (expected results are free text).
 *
 * Note: with a cloud provider the checklist questions are sent to ollama.com; a local provider needs the
 * model installed on the local Ollama server (SR-08 Phase C, separate approval). Cloud runs are refused
 * unless --allow-cloud is given (SR-09 D-C9).
 */
class EvaluateChatbot extends Command
{
    protected $signature = 'chatbot:evaluate
                            {--provider= : auto, local or cloud (default: configured provider)}
                            {--model= : Chat model (default: configured model)}
                            {--language= : Only en, tl or bcl cases}
                            {--limit= : Run at most this many cases}
                            {--dry-run : List the cases without calling any model}
                            {--allow-cloud : Permit requests to a cloud endpoint (needs separate approval, D-C9)}';

    protected $description = 'Evaluate chatbot answers on the reference checklist and save a CSV to private storage';

    private const LANGUAGES = ['English' => 'en', 'Tagalog' => 'tl', 'Bikol-Iriga' => 'bcl'];

    private const CHECKLIST = 'health_docs/_reference_only/chatbot_test_checklist.csv';

    public function handle(RagService $rag): int
    {
        $rag = $rag->withChatSettings($this->option('provider'), $this->option('model'));
        try {
            $settings = $rag->chatSettings();
        } catch (ChatbotAiUnavailableException $e) {
            $this->error('Configuration error: '.$e->getMessage());

            return self::FAILURE;
        }

        $cases = $this->cases();
        if ($cases === null) {
            $this->error('Checklist not found or unreadable: resources/'.self::CHECKLIST);

            return self::FAILURE;
        }
        $this->line(sprintf(
            'Provider: %s (%s) · chat model: %s · cases: %d%s',
            $settings['provider'], $settings['endpoint']['provider'], $settings['chat_model'], count($cases),
            $this->option('dry-run') ? ' · dry run' : ''
        ));

        if ($this->option('dry-run')) {
            foreach ($cases as $case) {
                $this->line("{$case['id']}  {$case['language']}  {$case['question']}");
            }

            return self::SUCCESS;
        }
        if ($settings['endpoint']['provider'] === 'cloud') {
            // SR-09 D-C9: cloud evaluation requests need separate approval.
            if (! $this->option('allow-cloud')) {
                $this->error('Refused: this run would send the checklist questions to ollama.com. Cloud evaluation requests need separate approval (D-C9). Use --provider=local with an installed local model, or --dry-run.');

                return self::FAILURE;
            }
            $this->warn('The checklist questions will be sent to ollama.com.');
        }
        if ($this->input->isInteractive() && ! $this->confirm('Run the evaluation now?', true)) {
            return self::FAILURE;
        }

        $chatCalls = 0;
        Http::globalRequestMiddleware(function (RequestInterface $request) use (&$chatCalls): RequestInterface {
            if (str_ends_with($request->getUri()->getPath(), '/api/chat')) {
                $chatCalls++;
            }

            return $request;
        });

        $rows = [];
        foreach ($cases as $case) {
            $chatCalls = 0;
            $started = hrtime(true);
            $row = [
                'test_id' => $case['id'], 'language' => $case['language'], 'topic' => $case['topic'], 'intent' => $case['intent'],
                'question' => $case['question'], 'expected_result' => $case['expected'],
                'provider' => $settings['endpoint']['provider'], 'chat_model' => $settings['chat_model'],
            ];
            try {
                $result = $rag->ask($case['question'], $case['language']);
                $row += [
                    'status' => 'ok',
                    'latency_ms' => (int) round((hrtime(true) - $started) / 1e6),
                    'chat_calls' => $chatCalls,
                    'returned_language' => (string) ($result['language'] ?? ''),
                    'language_match' => ($result['language'] ?? '') === $case['language'] ? 'yes' : 'no',
                    'is_conversation' => ! empty($result['is_conversation']) ? 'yes' : 'no',
                    'answer_empty' => trim((string) ($result['answer'] ?? '')) === '' ? 'yes' : 'no',
                    'points' => count((array) ($result['points'] ?? [])),
                    'sources' => implode('; ', array_map(static fn ($s): string => is_array($s) ? (string) ($s['source_file'] ?? json_encode($s)) : (string) $s, (array) ($result['sources'] ?? []))),
                    'answer' => mb_substr(trim((string) ($result['answer'] ?? '')), 0, 1000),
                    'error' => '',
                ];
            } catch (Throwable $e) {
                $row += [
                    'status' => 'error', 'latency_ms' => (int) round((hrtime(true) - $started) / 1e6), 'chat_calls' => $chatCalls,
                    'returned_language' => '', 'language_match' => '', 'is_conversation' => '', 'answer_empty' => '', 'points' => 0,
                    'sources' => '', 'answer' => '', 'error' => $e::class,
                ];
            }
            $row['pass_fail'] = '';
            $rows[] = $row;
            $this->line(sprintf('%s  %s  %s  %d ms', $row['test_id'], $row['language'], $row['status'], $row['latency_ms']));
        }

        $path = sprintf(
            'evaluation-%s-%s-%s.csv',
            now()->format('Ymd-His'),
            $settings['endpoint']['provider'],
            preg_replace('/[^a-z0-9.-]+/i', '-', $settings['chat_model'])
        );
        Storage::disk('chatbot_evaluations')->put($path, $this->toCsv($rows));

        $errors = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'error'));
        $this->info("Saved storage/app/private/chatbot-evaluations/{$path} — {$errors} errors out of ".count($rows).' cases. Pass/Fail is left for the reviewer.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{id: string, language: string, topic: string, intent: string, question: string, expected: string}>|null
     */
    private function cases(): ?array
    {
        $handle = @fopen(resource_path(self::CHECKLIST), 'r');
        if ($handle === false) {
            return null;
        }
        $onlyLanguage = $this->option('language');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $cases = [];
        fgetcsv($handle, escape: '\\');
        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $language = self::LANGUAGES[$row[1] ?? ''] ?? null;
            if ($language === null || trim((string) ($row[4] ?? '')) === '') {
                continue; // Security rows are browser checks, not chatbot questions.
            }
            if ($onlyLanguage !== null && $onlyLanguage !== $language) {
                continue;
            }
            $cases[] = [
                'id' => (string) $row[0], 'language' => $language, 'topic' => (string) ($row[2] ?? ''),
                'intent' => (string) ($row[3] ?? ''), 'question' => (string) $row[4], 'expected' => (string) ($row[5] ?? ''),
            ];
            if ($limit !== null && count($cases) >= $limit) {
                break;
            }
        }
        fclose($handle);

        return $cases;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function toCsv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        if ($rows !== []) {
            fputcsv($out, array_keys($rows[0]), escape: '\\');
        }
        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn ($v): string => (string) $v, $row), escape: '\\');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
