<?php

namespace App\Services\ChatbotComparison;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * SR-09 comparison files in private storage (disk `chatbot_evaluations`, folder comparisons/<run-id>/):
 * run_info.csv, results.csv, scoring_sheet.csv (blind), scoring_key.csv (kept separate), and the
 * summary written by `chatbot:compare-summary`. CSV only; no database tables; no resident data.
 *
 * Scores are entered by the named human reviewers (a qualified Linguist and a licensed healthcare
 * professional). Nothing here generates scores.
 */
final class ComparisonFiles
{
    public const DISK = 'chatbot_evaluations';

    public const QUESTION_FILE = 'health_docs/_reference_only/comparison_questions.csv';

    /** Review states whose cases count towards a verdict. */
    public const COUNTED_REVIEW_STATES = ['existing_reference', 'reviewed'];

    public const RESULT_COLUMNS = [
        'run_id', 'case_id', 'language', 'origin', 'review_status', 'question', 'expected_behaviour', 'expected_source',
        'approach', 'approach_label', 'status', 'pending_reason', 'latency_ms', 'chat_calls', 'output_valid',
        'returned_language', 'answer', 'points', 'sources', 'source_hit', 'error',
    ];

    public const SHEET_COLUMNS = [
        'case_id', 'language', 'review_status', 'question', 'expected_behaviour', 'answer_slot', 'answer', 'points',
        'accuracy_1_5', 'groundedness_yes_partly_no', 'safety_pass_yes_no', 'health_reviewer_notes',
        'language_quality_1_5', 'linguist_notes',
    ];

    /**
     * @return list<array<string, string>>
     */
    public static function questions(): array
    {
        $path = resource_path(self::QUESTION_FILE);
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new InvalidArgumentException('Comparison question file not found: resources/'.self::QUESTION_FILE);
        }
        $header = fgetcsv($handle, escape: '\\') ?: [];
        $rows = [];
        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            if (count($row) === count($header)) {
                $rows[] = array_combine($header, array_map('strval', $row));
            }
        }
        fclose($handle);

        return $rows;
    }

    public static function questionFileHash(): string
    {
        return (string) hash_file('sha256', resource_path(self::QUESTION_FILE));
    }

    public static function runFolder(string $runId): string
    {
        if (preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $runId) !== 1) {
            throw new InvalidArgumentException('Invalid comparison run id.');
        }

        return 'comparisons/'.$runId;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public static function put(string $runId, string $file, array $columns, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $columns, escape: '\\');
        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn (string $c): string => (string) ($row[$c] ?? ''), $columns), escape: '\\');
        }
        rewind($out);
        $path = self::runFolder($runId).'/'.$file;
        Storage::disk(self::DISK)->put($path, (string) stream_get_contents($out));
        fclose($out);

        return $path;
    }

    /**
     * @return list<array<string, string>>
     */
    public static function read(string $runId, string $file): array
    {
        if (preg_match('/^[A-Za-z0-9_.-]+\.csv$/', $file) !== 1) {
            throw new InvalidArgumentException('Invalid file name.');
        }
        $path = self::runFolder($runId).'/'.$file;
        if (! Storage::disk(self::DISK)->exists($path)) {
            throw new InvalidArgumentException("Missing {$file} for run {$runId}.");
        }
        $lines = fopen('php://temp', 'r+');
        fwrite($lines, (string) Storage::disk(self::DISK)->get($path));
        rewind($lines);
        $header = fgetcsv($lines, escape: '\\') ?: [];
        $rows = [];
        while (($row = fgetcsv($lines, escape: '\\')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_map('strval', $row), count($header), ''));
        }
        fclose($lines);

        return $rows;
    }

    /**
     * Deterministic blind order of approaches for one case (reproducible from the run id and the key file).
     *
     * @param  list<string>  $approaches
     * @return list<string>
     */
    public static function blindOrder(string $runId, string $caseId, array $approaches): array
    {
        $sorted = $approaches;
        sort($sorted);
        usort($sorted, static fn (string $a, string $b): int => strcmp(
            hash('sha256', $runId.'|'.$caseId.'|'.$a),
            hash('sha256', $runId.'|'.$caseId.'|'.$b)
        ));

        return $sorted;
    }
}
