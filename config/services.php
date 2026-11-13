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
    ],

    /*
    | Health documents used by the context-based chatbot (RAG).
    | Linguist-verified set, made by ml/health-docs/convert_verified_docs.py.
    */
    'health_docs' => [
        'path' => env('HEALTH_DOCS_PATH', resource_path('health_docs_v2')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bikol-Iriga question-type model (ml/bikol-intent)
    |--------------------------------------------------------------------------
    |
    | Trained in Colab and exported as JSON. When enabled, the model decides
    | the Bikol question type if it is at least `min_confidence` sure;
    | otherwise the keyword rules in RagService are used.
    |
    */
    'bikol_intent' => [
        'enabled' => (bool) env('BIKOL_INTENT_MODEL_ENABLED', true),
        'model_path' => env('BIKOL_INTENT_MODEL_PATH', base_path('resources/ml/bikol_intent_model.json')),
        'min_confidence' => (float) env('BIKOL_INTENT_MIN_CONFIDENCE', 0.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bikol-Iriga small-talk model (ml/bikol-chat)
    |--------------------------------------------------------------------------
    |
    | Fine-tuned Gemma 3 4B, run by the LOCAL Ollama (`ollama create
    | lmlinga-bikol-chat -f Modelfile`). Used only for Bikol messages that are
    | not health questions. Serious messages always get a fixed checked reply.
    |
    */
    'bikol_chat' => [
        'enabled' => (bool) env('BIKOL_CHAT_MODEL_ENABLED', true),
        'model' => env('BIKOL_CHAT_MODEL', 'lmlinga-bikol-chat'),
        'base_url' => env('BIKOL_CHAT_BASE_URL', env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434')),
        'timeout' => (int) env('BIKOL_CHAT_TIMEOUT', 60),
    ],

];
