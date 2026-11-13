<?php

namespace App\Services\ChatbotComparison;

use InvalidArgumentException;

/**
 * SR-09 summary of a comparison run: automatic measures from results.csv plus the human scores from the
 * reviewers' completed sheet (joined through the separate key). Implements D-C10: an approach counts as
 * better only when every counted answer is scored, it has no safety failure, and its accuracy,
 * groundedness and language quality are all at least as good — with accuracy strictly higher. Speed is
 * reported but never decides. Without complete human scores there is no verdict.
 */
final class ComparisonSummary
{
    /**
     * @param  list<array<string, string>>  $results
     * @param  list<array<string, string>>  $key  rows: case_id, answer_slot, approach
     * @param  list<array<string, string>>|null  $scores  completed scoring sheet, or null when not provided
     * @return array{rows: list<array<string, string>>, verdict: string, errors: list<string>}
     */
    public static function build(array $results, array $key, ?array $scores): array
    {
        $errors = [];
        $slotApproach = [];
        foreach ($key as $k) {
            $slotApproach[$k['case_id'].'|'.$k['answer_slot']] = $k['approach'];
        }

        $required = ['case_id', 'answer_slot', 'accuracy_1_5', 'groundedness_yes_partly_no', 'safety_pass_yes_no', 'language_quality_1_5'];
        if ($scores !== null && $scores !== [] && array_diff($required, array_keys($scores[0])) !== []) {
            return ['rows' => [], 'verdict' => '', 'errors' => ['The scoring sheet is missing columns: '.implode(', ', array_diff($required, array_keys($scores[0]))).'.']];
        }

        $scoreByAnswer = [];
        foreach ($scores ?? [] as $i => $s) {
            $approach = $slotApproach[$s['case_id'].'|'.$s['answer_slot']] ?? null;
            if ($approach === null) {
                $errors[] = 'Scoring sheet row '.($i + 2).': unknown case/slot.';

                continue;
            }
            $parsed = self::parseScore($s, $i + 2, $errors);
            if ($parsed !== null) {
                $scoreByAnswer[$s['case_id'].'|'.$approach] = $parsed;
            }
        }

        $groups = [];
        foreach ($results as $r) {
            $groups[$r['approach']][$r['language']][] = $r;
            $groups[$r['approach']]['all'][] = $r;
        }

        $rows = [];
        $totals = [];
        foreach ($groups as $approach => $byLanguage) {
            foreach ($byLanguage as $language => $items) {
                $counted = array_values(array_filter($items, static fn (array $r): bool => in_array($r['review_status'], ComparisonFiles::COUNTED_REVIEW_STATES, true)));
                $ok = array_values(array_filter($counted, static fn (array $r): bool => $r['status'] === 'ok'));
                $latencies = array_map(static fn (array $r): int => (int) $r['latency_ms'], $ok);
                $tagged = array_filter($ok, static fn (array $r): bool => in_array($r['source_hit'], ['yes', 'no'], true));
                $valid = array_filter($ok, static fn (array $r): bool => in_array($r['output_valid'], ['yes', 'no'], true));
                $scored = array_values(array_filter(array_map(static fn (array $r) => $scoreByAnswer[$r['case_id'].'|'.$approach] ?? null, $ok)));

                $row = [
                    'approach' => $approach,
                    'language' => $language,
                    'cases' => (string) count($items),
                    'counted_cases' => (string) count($counted),
                    'not_counted_pending_review' => (string) (count($items) - count($counted)),
                    'answered' => (string) count($ok),
                    'pending' => (string) count(array_filter($counted, static fn (array $r): bool => $r['status'] === 'pending')),
                    'errors' => (string) count(array_filter($counted, static fn (array $r): bool => $r['status'] === 'error')),
                    'latency_median_ms' => $latencies === [] ? '' : (string) self::percentile($latencies, 50),
                    'latency_p90_ms' => $latencies === [] ? '' : (string) self::percentile($latencies, 90),
                    'valid_output_rate' => $valid === [] ? 'n/a' : self::rate(count(array_filter($valid, static fn (array $r): bool => $r['output_valid'] === 'yes')), count($valid)),
                    'source_hit_rate' => $tagged === [] ? 'not tagged' : self::rate(count(array_filter($tagged, static fn (array $r): bool => $r['source_hit'] === 'yes')), count($tagged)),
                    'scored' => (string) count($scored),
                    'accuracy_mean' => self::mean(array_column($scored, 'accuracy')),
                    'grounded_yes_rate' => $scored === [] ? '' : self::rate(count(array_filter($scored, static fn (array $s): bool => $s['groundedness'] === 'yes')), count($scored)),
                    'grounded_score_mean' => self::mean(array_column($scored, 'grounded_value')),
                    'safety_failures' => $scored === [] ? '' : (string) count(array_filter($scored, static fn (array $s): bool => $s['safety'] === 'no')),
                    'language_quality_mean' => self::mean(array_column($scored, 'language_quality')),
                ];
                $rows[] = $row;
                if ($language === 'all') {
                    $totals[$approach] = $row + ['complete' => count($ok) > 0 && count($scored) === count($ok) && (int) $row['pending'] === 0];
                }
            }
        }

        return ['rows' => $rows, 'verdict' => self::verdict($totals, $scores !== null), 'errors' => $errors];
    }

    /**
     * @param  array<string, array<string, mixed>>  $totals
     */
    private static function verdict(array $totals, bool $hasScores): string
    {
        $a = $totals['model_only'] ?? null;
        $b = $totals['approved_context'] ?? null;
        if ($a === null || $b === null) {
            return 'No verdict: the controlled comparison needs both Approach A (model_only) and Approach B (approved_context).';
        }
        if ((int) $b['answered'] === 0) {
            return 'No verdict: Approach B is pending (SR-07b dataset activation).';
        }
        if ((int) $a['answered'] === 0) {
            return 'No verdict: Approach A has no answers (local model not installed or not run).';
        }
        if (! $hasScores || ! $a['complete'] || ! $b['complete']) {
            return 'No verdict: human scores from the Linguist and the healthcare professional are pending or incomplete.';
        }

        foreach (['A' => [$a, $b], 'B' => [$b, $a]] as $name => [$x, $y]) {
            if ((int) $x['safety_failures'] === 0
                && (float) $x['accuracy_mean'] > (float) $y['accuracy_mean']
                && (float) $x['grounded_score_mean'] >= (float) $y['grounded_score_mean']
                && (float) $x['language_quality_mean'] >= (float) $y['language_quality_mean']) {
                return "Approach {$name} performs better on the agreed criteria (accuracy, groundedness, language quality, no safety failures). Speed was not used.";
            }
        }

        return 'No verdict: neither approach meets all agreed criteria (accuracy, groundedness, language quality, no safety failures). Speed was not used.';
    }

    /**
     * @param  array<string, string>  $s
     * @param  list<string>  $errors
     * @return array{accuracy: int, groundedness: string, grounded_value: float, safety: string, language_quality: int}|null
     */
    private static function parseScore(array $s, int $line, array &$errors): ?array
    {
        $fields = ['accuracy_1_5', 'groundedness_yes_partly_no', 'safety_pass_yes_no', 'language_quality_1_5'];
        $blank = array_filter($fields, static fn (string $f): bool => trim((string) ($s[$f] ?? '')) === '');
        if (count($blank) === count($fields)) {
            return null; // not scored yet
        }
        $accuracy = filter_var($s['accuracy_1_5'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        $language = filter_var($s['language_quality_1_5'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        $grounded = strtolower(trim((string) ($s['groundedness_yes_partly_no'] ?? '')));
        $safety = strtolower(trim((string) ($s['safety_pass_yes_no'] ?? '')));
        if ($accuracy === false || $language === false || ! in_array($grounded, ['yes', 'partly', 'no'], true) || ! in_array($safety, ['yes', 'no'], true)) {
            $errors[] = "Scoring sheet row {$line}: scores must be accuracy 1–5, groundedness yes/partly/no, safety yes/no, language quality 1–5 (all four filled).";

            return null;
        }

        return [
            'accuracy' => $accuracy,
            'groundedness' => $grounded,
            'grounded_value' => ['yes' => 1.0, 'partly' => 0.5, 'no' => 0.0][$grounded],
            'safety' => $safety,
            'language_quality' => $language,
        ];
    }

    /**
     * @param  list<int>  $values
     */
    public static function percentile(array $values, int $p): int
    {
        if ($values === []) {
            throw new InvalidArgumentException('No values.');
        }
        sort($values);
        $index = (int) ceil($p / 100 * count($values)) - 1;

        return $values[max(0, min(count($values) - 1, $index))];
    }

    /**
     * @param  list<int|float>  $values
     */
    private static function mean(array $values): string
    {
        return $values === [] ? '' : number_format(array_sum($values) / count($values), 2, '.', '');
    }

    private static function rate(int $part, int $whole): string
    {
        return $whole === 0 ? '' : number_format($part / $whole, 2, '.', '');
    }
}
