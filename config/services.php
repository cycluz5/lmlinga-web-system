<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Semaphore SMS (delivery only)
    |--------------------------------------------------------------------------
    |
    | LMLinga owns OTP generation/verification. Semaphore delivers the
    | Laravel-generated OTP via POST /otp. Never commit a real API key.
    |
    */
    'semaphore' => [
        'base_url' => env('SEMAPHORE_BASE_URL', 'https://api.semaphore.co/api/v4'),
        'api_key' => env('SEMAPHORE_API_KEY'),
        'sender_name' => env('SEMAPHORE_SENDER_NAME', 'LMLINGA'),
        'timeout' => (int) env('SEMAPHORE_HTTP_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ollama (chatbot embeddings + chat completion)
    |--------------------------------------------------------------------------
    |
    | Ollama runs inside the same container as the app (see Dockerfile /
    | docker/entrypoint.sh), listening on localhost.
    |
    */
    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
        // When set and the chat model ends in "-cloud", requests go straight
        // to ollama.com's hosted API instead of the local server — no
        // `ollama signin` needed. Generate at https://ollama.com/settings/keys.
        'api_key' => env('OLLAMA_API_KEY'),

        /*
         | SR-08 configurable inference. Defaults reproduce the existing behaviour exactly:
         | chat model gemma4:31b-cloud, provider "auto" (ollama.com only for a "-cloud" model with an
         | API key, otherwise the local server), embeddings always on the local server.
         | "local" never sends the API key; "cloud" requires it. There is no automatic fallback
         | between providers.
         */
        'chat_provider' => env('OLLAMA_CHAT_PROVIDER') ?: 'auto',
        'chat_model' => env('OLLAMA_CHAT_MODEL') ?: 'gemma4:31b-cloud',
        'embedding_model' => env('OLLAMA_EMBEDDING_MODEL') ?: 'nomic-embed-text',
        'cloud_url' => env('OLLAMA_CLOUD_URL') ?: 'https://ollama.com',
        'chat_timeout' => (int) (env('OLLAMA_CHAT_TIMEOUT') ?: 180),
        'embedding_timeout' => (int) (env('OLLAMA_EMBEDDING_TIMEOUT') ?: 60),
        'keep_alive' => env('OLLAMA_KEEP_ALIVE') ?: '30m',
    ],

];
