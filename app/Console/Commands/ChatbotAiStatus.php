<?php

namespace App\Console\Commands;

use App\Exceptions\ChatbotAiUnavailableException;
use App\Services\RagService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SR-08 read-only AI status: configured provider and models, the endpoint the chatbot would use, whether
 * an API key is configured (never its value), and whether the local Ollama server responds and has the
 * models installed. Makes no chat request and never contacts the cloud.
 */
class ChatbotAiStatus extends Command
{
    protected $signature = 'chatbot:ai-status';

    protected $description = 'Show the chatbot AI provider, models and local Ollama availability (read-only)';

    public function handle(RagService $rag): int
    {
        $key = config('services.ollama.api_key');
        $baseUrl = (string) config('services.ollama.base_url', 'http://127.0.0.1:11434');

        try {
            $settings = $rag->chatSettings();
            $endpoint = $settings['endpoint'];
            $endpointLine = $endpoint['provider'].' — '.$endpoint['url'].($endpoint['token'] !== null ? ' (API key sent)' : ' (no API key sent)');
            $ok = true;
        } catch (ChatbotAiUnavailableException $e) {
            $settings = ['provider' => (string) config('services.ollama.chat_provider'), 'chat_model' => (string) config('services.ollama.chat_model'), 'embedding_model' => (string) config('services.ollama.embedding_model')];
            $endpointLine = 'CONFIGURATION ERROR: '.$e->getMessage();
            $ok = false;
        }

        $installed = null;
        $localLine = 'not responding at '.$baseUrl;
        try {
            $response = Http::timeout(3)->get(rtrim($baseUrl, '/').'/api/tags');
            if ($response->successful()) {
                $installed = array_values(array_filter(array_map(
                    static fn ($m): string => (string) ($m['name'] ?? ''),
                    (array) $response->json('models', [])
                )));
                $localLine = 'responding at '.$baseUrl.' ('.count($installed).' models installed)';
            }
        } catch (Throwable) {
            // Unreachable local server is reported, not thrown.
        }

        $isInstalled = static function (string $model) use ($installed): string {
            if ($installed === null) {
                return 'unknown (local server not responding)';
            }
            $names = array_merge($installed, array_map(static fn (string $n): string => preg_replace('/:latest$/', '', $n), $installed));

            return in_array($model, $names, true) ? 'installed locally' : 'not installed locally';
        };

        $this->table(['Setting', 'Value'], [
            ['Chat provider (configured)', $settings['provider']],
            ['Chat model', $settings['chat_model']],
            ['Chat endpoint used', $endpointLine],
            ['API key', ($key !== null && $key !== '') ? 'configured (value hidden)' : 'not configured'],
            ['Embedding model', $settings['embedding_model'].' — '.$isInstalled($settings['embedding_model'])],
            ['Local Ollama server', $localLine],
            ['Chat model locally', str_ends_with($settings['chat_model'], '-cloud') ? 'cloud model (not installed locally)' : $isInstalled($settings['chat_model'])],
            ['Bikol-Iriga answers', 'extractive (no chat model call); embeddings used only as a search fallback'],
        ]);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
