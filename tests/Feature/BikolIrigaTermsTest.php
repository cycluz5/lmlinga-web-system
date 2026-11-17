<?php

namespace Tests\Feature;

use App\Services\BikolCloudChatService;
use App\Services\BikolIrigaTerms;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BikolIrigaTermsTest extends TestCase
{
    private function terms(array $words, array $glossary = []): BikolIrigaTerms
    {
        $path = tempnam(sys_get_temp_dir(), 'terms');
        file_put_contents($path, json_encode(['words' => $words, 'glossary' => $glossary]));

        return new BikolIrigaTerms($path);
    }

    public function test_swaps_listed_words_and_keeps_capital_letters(): void
    {
        $terms = $this->terms(['ano' => 'ono', 'marhay' => 'maray', 'dakulo' => 'dakolo']);

        $this->assertSame('Ono ana sinyales?', $terms->apply('Ano ana sinyales?'));
        $this->assertSame('ONO ITO', $terms->apply('ANO ITO'));
        $this->assertSame('Maray man ako, maray ka.', $terms->apply('Marhay man ako, marhay ka.'));
    }

    public function test_unlisted_central_words_stay_central(): void
    {
        $terms = $this->terms(['ano' => 'ono']);

        $this->assertSame('Dai pa ako igwa nin kairiba.', $terms->apply('Dai pa ako igwa nin kairiba.'));
    }

    public function test_only_whole_words_are_swapped(): void
    {
        $terms = $this->terms(['ano' => 'ono']);

        $this->assertSame('anoman, banano, ono', $terms->apply('anoman, banano, ano'));
    }

    public function test_missing_file_changes_nothing(): void
    {
        $terms = new BikolIrigaTerms('/no/such/file.json');

        $this->assertSame('Ano ito?', $terms->apply('Ano ito?'));
        $this->assertSame('', $terms->glossaryPrompt());
    }

    public function test_glossary_prompt_groups_variants_by_iriga_word(): void
    {
        $terms = $this->terms([], [['marhay', 'maray'], ['marahay', 'maray'], ['ano', 'ono']]);

        $this->assertSame(
            'Gamiton an Bikol-Iriga na mga sarita: maray (bakong marhay/marahay), ono (bakong ano).',
            $terms->glossaryPrompt()
        );
    }

    public function test_the_real_team_list_swaps_the_common_central_words(): void
    {
        $terms = new BikolIrigaTerms();

        $this->assertGreaterThan(50, $terms->count());
        $this->assertSame('Ono maray kanimo agko?', $terms->apply('Ano marhay saimo igwa?'));
        // The team's real, correct Iriga words must never be changed.
        $iriga = 'Ono ana sinyales kana dengue? Maray na aldow, kanimo ag kanako, uda, osad.';
        $this->assertSame($iriga, $terms->apply($iriga));
    }

    public function test_the_teams_reviewed_chats_are_sent_to_gemma_as_example_turns(): void
    {
        config(['services.ollama.api_key' => 'test-key']);
        Http::fake(['ollama.com/*' => Http::response(['message' => ['content' => 'Maray man ako.']])]);

        $examples = (new BikolIrigaTerms())->examples();
        $this->assertGreaterThan(10, count($examples));
        // The serious-message chats (fixed replies) are never used as examples.
        foreach ($examples as $e) {
            $this->assertStringNotContainsString('pag-asa', $e['user']);
            $this->assertStringNotContainsString('magraan', $e['user']);
            $this->assertStringNotContainsString('kolgan', $e['user']);
        }

        (new BikolCloudChatService())->smallTalk('Kumusta ka?');

        Http::assertSent(function ($r) use ($examples) {
            $m = $r['messages'];

            return $m[0]['role'] === 'system'
                && $m[1] === ['role' => 'user', 'content' => $examples[0]['user']]
                && $m[2] === ['role' => 'assistant', 'content' => $examples[0]['bot']]
                && count($m) === 2 + count($examples) * 2
                && str_contains(collect($m)->last()['content'], 'Kumusta ka?');
        });
    }

    public function test_examples_include_the_teams_reviewed_time_day_date_and_weather_replies(): void
    {
        $bots = array_column((new BikolIrigaTerms())->examples(), 'bot');

        $this->assertContains("Ngowan, 8:20 na sa gab'i.", $bots);
        $this->assertContains('Ngowan, Biyernes.', $bots);
        $this->assertContains('Ngowan ana Sabado, Enero 31, 2026.', $bots);
        $this->assertContains('Diri ko isi ana panawon ngowan. Mas maray kin PAGASA a magsimbag kanimo.', $bots);
        // The situational examples carry the same clock line the app sends live.
        $withClock = array_filter(array_column((new BikolIrigaTerms())->examples(), 'user'), fn ($u) => str_starts_with($u, '[Oras:'));
        $this->assertGreaterThan(8, count($withClock));
    }

    public function test_a_health_question_without_checked_info_gets_the_teams_fixed_reply(): void
    {
        config(['services.ollama.api_key' => 'test-key']);
        Http::fake(['ollama.com/*' => Http::response(['message' => ['content' => 'HEALTH_NO_INFO']])]);

        $redirect = (new BikolIrigaTerms())->healthRedirect();
        $this->assertStringStartsWith('Manongod sa kalusugan', $redirect);

        $service = new BikolCloudChatService();
        $this->assertSame($redirect, $service->smallTalk('Ono a bolong sa kanser sa utok?'));

        $result = $service->result('Ono a bolong sa kanser sa utok?');
        $this->assertSame($redirect, $result['answer']);
        $this->assertSame('Fixed checked reply', $result['answered_by']);
    }

    public function test_prompt_has_the_part_of_day_rule_and_the_health_flag(): void
    {
        $prompt = (new BikolCloudChatService())->smallTalkSystemPrompt();

        $this->assertStringContainsString('5:00 AM hasta 11:59 AM = ramrag', $prompt);
        $this->assertStringContainsString("6:00 PM hasta 4:59 AM = gab'i", $prompt);
        $this->assertStringContainsString('HEALTH_NO_INFO', $prompt);
    }

    public function test_cloud_reply_goes_through_the_term_layer_and_prompt_gets_the_glossary(): void
    {
        config(['services.ollama.api_key' => 'test-key']);
        Http::fake(['ollama.com/*' => Http::response(['message' => ['content' => 'Marhay man ako. Ano an kailangan mo?']])]);

        $answer = (new BikolCloudChatService())->smallTalk('Kumusta ka?');

        $this->assertSame('Maray man ako. Ono an kailangan mo?', $answer);
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'], 'Gamiton an Bikol-Iriga na mga sarita:')
            && str_contains($r['messages'][0]['content'], 'maray (bakong'));
    }
}
