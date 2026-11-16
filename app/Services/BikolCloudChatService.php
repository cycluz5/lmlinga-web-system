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
    public function chat(string $system, string $user, int $maxTokens = 400, float $temperature = 0.3): ?string
    {
        $key = (string) config('services.ollama.api_key');
        if ($key === '') {
            Log::warning('Bikol cloud chat: OLLAMA_API_KEY is not set');

            return null;
        }

        try {
            $response = Http::timeout((int) config('services.bikol_cloud_chat.timeout', 60))
                ->withToken($key)
                ->post('https://ollama.com/api/chat', [
                    'model' => $this->model(),
                    'stream' => false,
                    'options' => ['temperature' => $temperature, 'num_predict' => $maxTokens],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
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

        return mb_strlen($text) < 2 ? null : $text;
    }

    public function smallTalkSystemPrompt(): string
    {
        return "Ika si LMLinga, health chatbot kan health center sa Iriga City.\n"
            . "Magsimbag SANA sa Bikol (bakong English, bakong Tagalog). Mabuot, mahamis, 1 hasta 3 na sentence sana.\n"
            . "Kun may oras/petsa sa umpisa kan mensahe (sa [ ]), iyan an tunay na oras ngonyan; gamiton iyan sa pagsimbag.\n"
            . "Dai ka nakakaaram kan panahon (weather) o balita ngonyan: sabihon iyan asin isuhestyon an PAGASA, "
            . "radyo, o weather app.\n"
            . "Dai magtao nin medical advice: gamot, dosis, sakit, sintomas, pagbuntis o treatment. "
            . "Kun health question an hapot, sabihon na dai pa ika igwa nin checked na impormasyon manongod digdi "
            . "asin magpakonsulta sa health center. Kun BAKONG health an hapot (pangaturugan, pagkaon, eskuwela, kwarta, "
            . "lugar, kasaysayan, resipe, kasumaruan), simbagon nin normal.\n"
            . "Dai gamiton an Tagalog na 'po', 'opo', 'paki-', 'ay' (halimbawa 'An oras ngonyan alas…', dai 'ay alas…'), "
            . "'masaya' (gamiton 'maogma'), 'pano' (gamiton 'paano'). Dai magbati nin 'Marhay na aga' kun dai nagbati an tawo; "
            . "kun nagbati siya, sarong maikling bati sana. Gamiton an Bikol na 'an', 'kan', 'dai', 'ako', 'ika'.
"
            . "Dai gamiton an markdown (** o #).";
    }

    /** Small-talk reply, or null when the cloud fails. */
    public function smallTalk(string $message): ?string
    {
        $context = app(BikolSmallTalkService::class)->contextLine();

        return $this->chat($this->smallTalkSystemPrompt(), $context . "\n" . $message, 200, 0.5);
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
            'answered_by' => $answer === null ? self::ANSWERED_BY . ' - offline' : self::ANSWERED_BY,
        ];
    }
}
