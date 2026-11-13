<?php

namespace Tests\Unit;

use App\Services\BikolIntentClassifier;
use PHPUnit\Framework\TestCase;

class BikolIntentClassifierTest extends TestCase
{
    private const MODEL = __DIR__ . '/../../resources/ml/bikol_intent_model.json';

    public function test_features_match_the_colab_notebook(): void
    {
        // Same output as features("Ono a sintomas ka dengue?") in train_bikol_intent.ipynb.
        $this->assertSame(
            ['w:ono', ' o', 'on', 'no', 'o ', ' on', 'ono', 'no ', ' ono', 'ono ', ' ono ', 'w:a'],
            array_slice(BikolIntentClassifier::features('Ono a sintomas ka dengue?'), 0, 12)
        );
        $this->assertSame(
            BikolIntentClassifier::features('ono a dengue'),
            BikolIntentClassifier::features("  ONO a,  dengue?!\n")
        );
    }

    public function test_php_gives_the_same_answers_as_colab(): void
    {
        $model = json_decode((string) file_get_contents(self::MODEL), true);
        $classifier = new BikolIntentClassifier(self::MODEL);

        $this->assertNotEmpty($model['samples']);
        foreach ($model['samples'] as $sample) {
            $prediction = $classifier->predict($sample['question']);
            $this->assertNotNull($prediction, $sample['question']);
            $this->assertSame($sample['intent'], $prediction['intent'], $sample['question']);
            $this->assertEqualsWithDelta($sample['confidence'], $prediction['confidence'], 0.002, $sample['question']);
        }
    }

    public function test_common_questions(): void
    {
        $classifier = new BikolIntentClassifier(self::MODEL);
        $cases = [
            'Ono a dengue?' => 'definition',
            'Ono a sintomas ka hika?' => 'symptoms',
            'Ono a kwasa ka malaria?' => 'causes',
            'Paono malikayan a trangkaso?' => 'management',
            'Paono maisiyan kin agko TB?' => 'diagnosis',
            'Ono a benepisyo ka pagpapasoso?' => 'benefits',
            'Agko side effect a pills?' => 'effects',
            'Onong edad pwede a bakuna?' => 'schedule',
            'Libre raw a bakuna sa health center?' => 'general',
        ];
        foreach ($cases as $question => $intent) {
            $this->assertSame($intent, $classifier->predict($question)['intent'] ?? null, $question);
        }
    }

    public function test_missing_model_or_unknown_text_returns_null(): void
    {
        $missing = new BikolIntentClassifier(__DIR__ . '/does-not-exist.json');
        $this->assertFalse($missing->isAvailable());
        $this->assertNull($missing->predict('Ono a dengue?'));

        $this->assertNull((new BikolIntentClassifier(self::MODEL))->predict('???'));
    }
}
