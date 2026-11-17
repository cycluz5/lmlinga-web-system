<?php
/**
 * Runs the three-types test list through the SAME path as App\Http\Controllers\Chatbot\ChatbotController (Bikol):
 *   serious message / time-date / unrelated chat layer  ->  else RagService::ask (verified health documents)
 *
 *   THREE_MODE=context|ai|cloud  [THREE_IDS=H001,C002 ...]  php artisan tinker --execute="require '<this file>';"
 *
 * context = Context-based (no AI for unrelated messages), ai = Local AI model, cloud = Cloud AI (Gemma).
 * Writes <this folder>/results_<mode>.json
 */

use App\Services\BikolSmallTalkService as Small;
use App\Services\RagService;
use Carbon\Carbon;

$mode = getenv('THREE_MODE') ?: 'ai';
$only = getenv('THREE_IDS') ? explode(',', getenv('THREE_IDS')) : null;
$dir = base_path('ml/bikol-chat/three_types');
$tests = json_decode(file_get_contents($dir . '/test_list.json'), true);
config(['services.bikol_chat.send_context' => true]);

$rag = app(RagService::class);
$rag->setAnswerMode($mode);
Small::useMode($mode);
$svc = app(Small::class);

function flatten(array $r): string
{
    return trim(($r['answer'] ?? '') . "\n" . implode("\n", array_map('strval', $r['points'] ?? [])));
}

function runOne($svc, $rag, string $q, Carbon $now): array
{
    $t = microtime(true);
    $error = null;
    $route = 'RAG';
    $result = null;
    try {
        $result = $svc->timeDateResult($q) ?? $svc->unrelatedResult($q);
        if ($result !== null) {
            $route = 'chat layer';
        } else {
            $result = $rag->ask($q, 'bcl', null, null);
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        $result = ['answer' => '', 'points' => [], 'sources' => []];
    }
    $answer = trim((string) ($result['answer'] ?? ''));
    $kind = $svc->timeDateKind($q);

    return [
        'answer' => $answer,
        'points' => array_map('strval', $result['points'] ?? []),
        'sources' => array_values(array_map('strval', (array) ($result['sources'] ?? []))),
        'answered_by' => (string) ($result['answered_by'] ?? ''),
        'route' => $route,
        'language' => $result['language'] ?? '',
        'clock_kind' => $kind,
        'clock_ok' => $kind !== null && $answer !== '' ? $svc->clockAnswerIsRight($kind, $answer, Carbon::now('Asia/Manila')) : null,
        'seconds' => round(microtime(true) - $t, 2),
        'error' => $error,
        'flat' => flatten($result),
    ];
}

$out = [];
$stored = [];
$lastConv = null;
$t0 = microtime(true);
foreach ($tests as $i => $t) {
    if ($only !== null && !in_array($t['id'], $only, true)) {
        continue;
    }
    if (($t['group'] ?? '') === 'M') {
        if ($lastConv !== $t['conv']) {
            $stored = [];
            $lastConv = $t['conv'];
        }
        Small::useHistory(Small::historyFromMessages($stored));
    } else {
        Small::useHistory([]);
    }
    $r = runOne($svc, $rag, $t['message'], Carbon::now('Asia/Manila'));
    if (($t['group'] ?? '') === 'M') {
        $stored[] = (object) ['sender' => 'Resident', 'message_text' => $t['message']];
        $stored[] = (object) ['sender' => 'Chatbot', 'message_text' => $r['flat']];
    }
    $out[] = $t + ['result' => $r, 'mode' => $mode];
    if (($i + 1) % 25 === 0) {
        fwrite(STDERR, sprintf("[%s] %d/%d (%.0fs)\n", $mode, $i + 1, count($tests), microtime(true) - $t0));
    }
}
$file = $dir . '/results_' . $mode . ($only !== null ? '_pilot' : '') . '.json';
file_put_contents($file, json_encode(['mode' => $mode, 'ran_at' => Carbon::now('Asia/Manila')->toIso8601String(),
    'model' => config('services.bikol_chat.model'), 'seconds' => round(microtime(true) - $t0, 1), 'results' => $out],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "mode $mode: " . count($out) . " tests in " . round(microtime(true) - $t0) . "s -> $file\n";
