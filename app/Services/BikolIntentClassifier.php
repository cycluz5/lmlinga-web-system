<?php

namespace App\Services;

/**
 * Bikol-Iriga question-type classifier (TF-IDF + logistic regression).
 *
 * The model is trained in Google Colab (ml/bikol-intent/colab/train_bikol_intent.ipynb)
 * and exported as JSON. Feature extraction here must match `features()` in that
 * notebook exactly:
 *   lowercase; characters outside [a-z0-9'- ] become spaces; for every word:
 *   "w:<word>" plus letter pieces of length 2-5 taken from " <word> ";
 *   tf = 1 + ln(count); x = tf * idf; L2-normalise; softmax(intercept + x·coef).
 */
class BikolIntentClassifier
{
    /** @var array<string, array{classes: list<string>, intercept: list<float>, features: array<string, list<float>>}|false> */
    private static array $loaded = [];

    public function __construct(private readonly string $modelPath)
    {
    }

    public static function fromConfig(): self
    {
        return new self((string) config(
            'services.bikol_intent.model_path',
            base_path('resources/ml/bikol_intent_model.json')
        ));
    }

    public function isAvailable(): bool
    {
        return $this->model() !== null;
    }

    /**
     * @return array{intent: string, confidence: float, scores: array<string, float>}|null
     *         null when the model file is missing/invalid or the text has no known features.
     */
    public function predict(string $question): ?array
    {
        $model = $this->model();
        if ($model === null) {
            return null;
        }

        $counts = [];
        foreach (self::features($question) as $feature) {
            if (isset($model['features'][$feature])) {
                $counts[$feature] = ($counts[$feature] ?? 0) + 1;
            }
        }
        if ($counts === []) {
            return null;
        }

        $x = [];
        $norm = 0.0;
        foreach ($counts as $feature => $count) {
            $value = (1.0 + log($count)) * $model['features'][$feature][0];
            $x[$feature] = $value;
            $norm += $value * $value;
        }
        $norm = sqrt($norm);

        $scores = $model['intercept'];
        $classCount = count($model['classes']);
        foreach ($x as $feature => $value) {
            $row = $model['features'][$feature];
            $value /= $norm;
            for ($c = 0; $c < $classCount; $c++) {
                $scores[$c] += $value * $row[$c + 1];
            }
        }

        $max = max($scores);
        $exp = array_map(static fn (float $s): float => exp($s - $max), $scores);
        $sum = array_sum($exp);
        $probabilities = [];
        foreach ($model['classes'] as $c => $label) {
            $probabilities[$label] = $exp[$c] / $sum;
        }
        arsort($probabilities);

        return [
            'intent' => (string) array_key_first($probabilities),
            'confidence' => (float) reset($probabilities),
            'scores' => $probabilities,
        ];
    }

    /**
     * @return list<string>
     */
    public static function features(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace("/[^a-z0-9'\\- ]+/u", ' ', $text);

        $out = [];
        foreach (preg_split('/ +/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $out[] = 'w:' . $word;
            $padded = ' ' . $word . ' ';
            $length = strlen($padded);
            for ($n = 2; $n <= 5; $n++) {
                for ($i = 0; $i + $n <= $length; $i++) {
                    $out[] = substr($padded, $i, $n);
                }
            }
        }

        return $out;
    }

    /**
     * @return array{classes: list<string>, intercept: list<float>, features: array<string, list<float>>}|null
     */
    private function model(): ?array
    {
        if (!array_key_exists($this->modelPath, self::$loaded)) {
            self::$loaded[$this->modelPath] = $this->load();
        }

        return self::$loaded[$this->modelPath] ?: null;
    }

    private function load(): array|false
    {
        if (!is_file($this->modelPath)) {
            return false;
        }
        $data = json_decode((string) file_get_contents($this->modelPath), true);
        if (
            !is_array($data)
            || !isset($data['classes'], $data['intercept'], $data['features'])
            || count($data['classes']) !== count($data['intercept'])
        ) {
            return false;
        }

        return [
            'classes' => array_values($data['classes']),
            'intercept' => array_map('floatval', $data['intercept']),
            'features' => $data['features'],
        ];
    }
}
