<?php

namespace Tests\Unit;

use App\Services\BikolIntentClassifier;
use PHPUnit\Framework\TestCase;

/**
 * The "health or chat?" gate (ml/bikol-chat/train_gate.py) runs through the same PHP classifier as the
 * intent model. The JSON also carries samples from Python, so PHP must give the same answers.
 */
class BikolGateClassifierTest extends TestCase
{
    private function gate(): BikolIntentClassifier
    {
        return new BikolIntentClassifier(dirname(__DIR__, 2) . '/resources/ml/bikol_gate_model.json');
    }

    public function test_model_file_loads(): void
    {
        $this->assertTrue($this->gate()->isAvailable());
    }

    public function test_health_questions_are_health(): void
    {
        foreach (['Ono a sinyales ka dengue?', 'Ono an autism?', 'Ono a mga bakuna para sa igin?'] as $question) {
            $result = $this->gate()->predict($question);
            $this->assertSame('health', $result['intent'], $question);
        }
    }

    public function test_greetings_and_feelings_are_chat(): void
    {
        foreach (['Kumusta ka?', 'Salamat tabi', 'Hello!'] as $question) {
            $result = $this->gate()->predict($question);
            $this->assertSame('chat', $result['intent'], $question);
        }
    }

    public function test_php_matches_the_python_samples_stored_in_the_model(): void
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/ml/bikol_gate_model.json'), true);
        $this->assertNotEmpty($data['samples']);

        foreach ($data['samples'] as $sample) {
            $result = $this->gate()->predict($sample['question']);
            $this->assertSame($sample['intent'], $result['intent'], $sample['question']);
            $this->assertEqualsWithDelta($sample['confidence'], $result['confidence'], 0.01, $sample['question']);
        }
    }
}
