<?php

namespace Tests\Feature;

use App\Services\RagService;
use Tests\TestCase;

class BikolIntentDetectionTest extends TestCase
{
    private function detect(string $question): string
    {
        $method = new \ReflectionMethod(RagService::class, 'detectBikolIntent');
        $method->setAccessible(true);

        return $method->invoke(new RagService(), $question);
    }

    public function test_model_handles_ono_spelling(): void
    {
        $this->assertSame('symptoms', $this->detect('Ono a sintomas ka dengue?'));
        $this->assertSame('management', $this->detect('Paono malikayan a dengue?'));
        $this->assertSame('diagnosis', $this->detect('Paono maiisiyan kin agko dengue a igin ko?'));
    }

    public function test_warning_rules_run_before_the_model(): void
    {
        $this->assertSame('warning', $this->detect('Kaipuhan ko na magpakonsulta sa ospital?'));
        $this->assertSame('warning', $this->detect('Nagkombolsyon an aki ko, uno an dapat kong gibuhon?'));
    }

    public function test_falls_back_to_rules_when_model_is_off_or_missing(): void
    {
        config(['services.bikol_intent.enabled' => false]);
        $this->assertSame('definition', $this->detect('Uno an dengue?'));

        config([
            'services.bikol_intent.enabled' => true,
            'services.bikol_intent.model_path' => __DIR__ . '/missing-model.json',
        ]);
        $this->assertSame('causes', $this->detect('Uno an dahilan kan dengue?'));
    }
}
