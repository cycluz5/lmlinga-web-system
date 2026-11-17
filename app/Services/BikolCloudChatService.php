<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloud AI (gemma4:31b-cloud) for Bikol, used only when the chat page is on "Cloud AI (Gemma)".
 *
 *  - Answers in Bikol (not Bikol-Iriga), straight from ollama.com's hosted API.
 *  - Small talk, greetings and time/date questions: smallTalk().
 *  - Health facts never come from here: RagService passes the verified answer to chat()
 *    and Gemma only re-tells it.
 */
class BikolCloudChatService
{
    public const ANSWERED_BY = 'Cloud AI (Gemma)';

    public const OFFLINE_MESSAGE = 'Diri makakonekta sa Cloud AI (Gemma). Siguraduhon na may internet ka asin uliton giraray.';

    public function model(): string
    {
        return (string) config('services.bikol_cloud_chat.model', 'gemma4:31b-cloud');
    }

    /**
     * One call to the cloud model. Returns null when it fails (no key, no internet, 402, empty text).
     */
    public function chat(string $system, string $user, int $maxTokens = 400, float $temperature = 0.3, array $examples = []): ?string
    {
        $key = (string) config('services.ollama.api_key');
        if ($key === '') {
            Log::warning('Bikol cloud chat: OLLAMA_API_KEY is not set');

            return null;
        }

        // Tell Gemma the team's Iriga words up front (Central Bikol stays for every other word).
        $terms = app(BikolIrigaTerms::class);
        $glossary = $terms->glossaryPrompt();
        if ($glossary !== '') {
            $system .= "\n" . $glossary;
        }

        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($examples as $example) {
            $messages[] = ['role' => 'user', 'content' => $example['user']];
            $messages[] = ['role' => 'assistant', 'content' => $example['bot']];
        }
        $messages[] = ['role' => 'user', 'content' => $user];

        try {
            $response = Http::timeout((int) config('services.bikol_cloud_chat.timeout', 60))
                ->withToken($key)
                ->post('https://ollama.com/api/chat', [
                    'model' => $this->model(),
                    'stream' => false,
                    'options' => ['temperature' => $temperature, 'num_predict' => $maxTokens],
                    'messages' => $messages,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Bikol cloud chat unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful()) {
            Log::warning('Bikol cloud chat error', ['status' => $response->status()]);

            return null;
        }

        $text = trim(str_replace(['**', '##', '#'], '', (string) $response->json('message.content', '')));

        // Safety net: any listed Central Bikol word that still slipped through becomes the team's Iriga word.
        $text = $terms->apply($text);

        return mb_strlen($text) < 2 ? null : $text;
    }

    /** Gemma writes this word when a health question has no checked answer; the app then sends the team's fixed reply. */
    public const HEALTH_NO_INFO = 'HEALTH_NO_INFO';

    public function smallTalkSystemPrompt(): string
    {
        return "Ika si LMLinga, health chatbot kan health center sa Iriga City.\n"
            . "Magsimbag SANA sa Bikol (bakong English, bakong Tagalog). Mabuot, mahamis, 1 hasta 3 na sentence sana.\n"
            . "Sa umpisa kan mensahe may orasan sa [ ] (oras, aldow, petsa ngonyan). Gamiton iyan SANA kun an hapot "
            . "manongod sa oras, aldow o petsa, o kun nagbabati an tawo (Maray na ramrag/modto/apon/gab'i). "
            . "Dai ito banggitin sa ibang simbag.\n"
            . "Parte kan aldow: 5:00 AM hasta 11:59 AM = ramrag; 12:00 PM hasta 1:59 PM = modto; "
            . "2:00 PM hasta 5:59 PM = apon; 6:00 PM hasta 4:59 AM = gab'i.\n"
            . "Dai ka nakakaaram kan panahon (weather), balita o presyo ngonyan: sabihon iyan, asin isuhestyon an PAGASA kun weather.\n"
            . "Dai magtao nin medical advice: gamot, dosis, sakit, sintomas, bakuna, pagbuntis o treatment. "
            . "Kun health question an hapot, isulat SANA an siring: " . self::HEALTH_NO_INFO . "\n"
            . "Kun BAKONG health an hapot (pangaturugan, pagkaon, eskuwela, kwarta, lugar, kasaysayan, resipe, kasumaruan), "
            . "simbagon nin normal.\n"
            . "Dai gamiton an Tagalog na 'po', 'opo', 'paki-', 'ay' (halimbawa 'An oras ngonyan alas…', dai 'ay alas…'), "
            . "'masaya' (gamiton 'maogma'), 'pano' (gamiton 'paano'). Dai magbati nin 'Marhay na aga' kun dai nagbati an tawo; "
            . "kun nagbati siya, sarong maikling bati sana. Gamiton an Bikol na 'an', 'kan', 'dai', 'ako', 'ika'.\n"
            . "An mga naunang halimbawa na pag-istorya amo sana an ISTILO kan team: sundon an sarita asin tono, "
            . "pero dai kopyahon an mga pangungusap, asin dai pirming magdagdag nin hapot manongod sa kalusugan.\n"
            . "Dai gamiton an markdown (** o #).";
    }

    /**
     * Small-talk reply, or null when the cloud fails. A health question without a checked answer
     * always gets the team's fixed, reviewed reply (never wording written by Gemma).
     */
    public function smallTalk(string $message): ?string
    {
        $terms = app(BikolIrigaTerms::class);
        $context = app(BikolSmallTalkService::class)->contextLine();

        // The team's reviewed chats go in as example turns, so the replies follow the team's Bikol-Iriga wording.
        $answer = $this->chat(
            $this->smallTalkSystemPrompt(),
            $context . "\n" . $message,
            200,
            0.5,
            $terms->examples()
        );

        if ($answer !== null && str_contains($answer, self::HEALTH_NO_INFO)) {
            $redirect = $terms->healthRedirect();

            return $redirect !== '' ? $redirect : 'Manongod sa kalusugan, mag onga ika sa topic arog ka dengue o kalintora.';
        }

        return $answer;
    }

    /** A ready chatbot result array (the offline message when the cloud fails). */
    public function result(string $message): array
    {
        $answer = $this->smallTalk($message);

        return [
            'title' => null,
            'answer' => $answer ?? self::OFFLINE_MESSAGE,
            'points' => [],
            'sources' => [],
            'language' => 'bcl',
            'is_conversation' => true,
            'answered_by' => $answer === null
                ? self::ANSWERED_BY . ' - offline'
                : ($answer === app(BikolIrigaTerms::class)->healthRedirect() ? 'Fixed checked reply' : self::ANSWERED_BY),
        ];
    }
}
