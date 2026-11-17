<?php
/**
 * Health-only check of RagService::ask (the verified documents), used to compare the RAG branch with this branch.
 * Read-only: it runs the code of whichever worktree it is started from and changes nothing there.
 *
 *   THREE_MODE=context|ai  THREE_TAG=name  php artisan tinker --execute="require '<this file>';"
 * Writes <this folder>/results_<tag>_<mode>.json (H and E tests only).
 */

use App\Services\RagService;
use Carbon\Carbon;

$mode = getenv('THREE_MODE') ?: 'context';
$tag = getenv('THREE_TAG') ?: 'rag';
$dir = 'C:/Users/Kathlyn Cris/Desktop/final/lmlinga-train/ml/bikol-chat/three_types';
$tests = array_values(array_filter(json_decode(file_get_contents($dir . '/test_list.json'), true), fn ($t) => in_array($t['group'], ['H'], true)));

// This is a check of the verified-document search only: the chat-model fallback inside RagService is switched off so a question the
// documents cannot answer shows as a plain "no information" miss (and does not wait for a model to load).
config(['services.bikol_chat.enabled' => false]);
$rag = app(RagService::class);
if (method_exists($rag, 'setAnswerMode')) {
    $rag->setAnswerMode($mode);
}
$out = [];
$t0 = microtime(true);
foreach ($tests as $t) {
    $s = microtime(true);
    $error = null;
    try {
        $r = $rag->ask($t['message'], 'bcl', null, null);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        $r = ['answer' => '', 'points' => [], 'sources' => []];
    }
    $answer = trim((string) ($r['answer'] ?? ''));
    $out[] = $t + ['result' => [
        'answer' => $answer, 'points' => array_map('strval', $r['points'] ?? []),
        'sources' => array_values(array_map('strval', (array) ($r['sources'] ?? []))),
        'answered_by' => (string) ($r['answered_by'] ?? ''), 'route' => 'RAG', 'seconds' => round(microtime(true) - $s, 2),
        'error' => $error, 'flat' => trim($answer . "\n" . implode("\n", array_map('strval', $r['points'] ?? []))),
        'clock_kind' => null, 'clock_ok' => null,
    ], 'mode' => $mode];
}
$file = "$dir/results_{$tag}_{$mode}.json";
file_put_contents($file, json_encode(['mode' => $mode, 'tag' => $tag, 'ran_at' => Carbon::now('Asia/Manila')->toIso8601String(),
    'seconds' => round(microtime(true) - $t0, 1), 'results' => $out], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "$tag/$mode: " . count($out) . " health tests in " . round(microtime(true) - $t0) . "s -> $file\n";
