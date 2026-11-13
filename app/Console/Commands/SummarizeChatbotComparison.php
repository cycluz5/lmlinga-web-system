<?php

namespace App\Console\Commands;

use App\Services\ChatbotComparison\ComparisonFiles;
use App\Services\ChatbotComparison\ComparisonSummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * SR-09 summary of a `chatbot:compare` run: automatic measures plus the human scores from the reviewers'
 * completed sheet (default scoring_sheet_scored.csv in the run folder). Writes summary.csv and summary.md.
 * A verdict is given only under D-C10 with complete human scores; speed never decides.
 */
class SummarizeChatbotComparison extends Command
{
    protected $signature = 'chatbot:compare-summary
                            {--run= : Run id printed by chatbot:compare}
                            {--scores=scoring_sheet_scored.csv : Completed scoring sheet file name inside the run folder}';

    protected $description = 'Summarize a chatbot comparison run (SR-09) with the reviewers\' scores';

    public function handle(): int
    {
        $runId = (string) $this->option('run');
        try {
            $folder = ComparisonFiles::runFolder($runId);
            $results = ComparisonFiles::read($runId, 'results.csv');
            $key = ComparisonFiles::read($runId, 'scoring_key.csv');
            $scoresFile = (string) $this->option('scores');
            if (basename($scoresFile) !== $scoresFile) {
                throw new InvalidArgumentException('--scores must be a file name inside the run folder.');
            }
            $scores = Storage::disk(ComparisonFiles::DISK)->exists($folder.'/'.basename($scoresFile))
                ? ComparisonFiles::read($runId, basename($scoresFile))
                : null;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $summary = ComparisonSummary::build($results, $key, $scores);
        if ($summary['errors'] !== []) {
            foreach ($summary['errors'] as $error) {
                $this->error($error);
            }
            $this->error('Summary not written: fix the scoring sheet first.');

            return self::FAILURE;
        }

        $columns = array_keys($summary['rows'][0] ?? ['approach' => '']);
        ComparisonFiles::put($runId, 'summary.csv', $columns, $summary['rows']);
        Storage::disk(ComparisonFiles::DISK)->put($folder.'/summary.md', $this->markdown($runId, $summary, $scores !== null));

        $this->table(['approach', 'language', 'answered', 'pending', 'errors', 'scored', 'accuracy', 'safety fails', 'language'], array_map(
            static fn (array $r): array => [$r['approach'], $r['language'], $r['answered'], $r['pending'], $r['errors'], $r['scored'], $r['accuracy_mean'], $r['safety_failures'], $r['language_quality_mean']],
            $summary['rows']
        ));
        $this->info($summary['verdict']);
        $this->line("Saved {$folder}/summary.csv and summary.md");

        return self::SUCCESS;
    }

    /**
     * @param  array{rows: list<array<string, string>>, verdict: string}  $summary
     */
    private function markdown(string $runId, array $summary, bool $hasScores): string
    {
        $md = "# Chatbot comparison summary — run {$runId}\n\n";
        $md .= "**Verdict (D-C10):** {$summary['verdict']}\n\n";
        $md .= "- Approach A is a hosted model configured by a prompt; it is **not trained or fine-tuned** by LMLinga.\n";
        $md .= "- Approach B uses only Linguist-approved datasets; it is **pending** until SR-07b activation.\n";
        $md .= "- Approach C is the current system (reference baseline; corpus not Linguist-verified).\n";
        $md .= '- Human scores: '.($hasScores ? 'from the reviewers\' completed sheet' : '**pending** (no completed scoring sheet)')
            .". Scores must come from a qualified Linguist and a licensed healthcare professional; this tool generates none.\n";
        $md .= "- Cases still awaiting review (e.g. Bikol-Iriga questions pending Linguist review) are reported but not counted.\n";
        $md .= "- Speed is reported but never decides the verdict.\n\n";
        $md .= "| Approach | Language | Answered | Pending | Errors | Median ms | P90 ms | Scored | Accuracy | Grounded (yes) | Safety failures | Language quality |\n";
        $md .= "|---|---|---|---|---|---|---|---|---|---|---|---|\n";
        foreach ($summary['rows'] as $r) {
            $md .= "| {$r['approach']} | {$r['language']} | {$r['answered']} | {$r['pending']} | {$r['errors']} | {$r['latency_median_ms']} | {$r['latency_p90_ms']} | {$r['scored']} | {$r['accuracy_mean']} | {$r['grounded_yes_rate']} | {$r['safety_failures']} | {$r['language_quality_mean']} |\n";
        }

        return $md;
    }
}
