<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'huggingface' => [
        'token' => env('HF_TOKEN'),
        // Multilingual (Hebrew + English) sentence-embedding model.
        'model' => env('HF_EMBEDDING_MODEL', 'intfloat/multilingual-e5-large'),
        'endpoint' => env('HF_ENDPOINT', 'https://router.huggingface.co/hf-inference/models/{model}/pipeline/feature-extraction'),
        // e5 models expect "query: " / "passage: " prefixes.
        'query_prefix' => env('HF_QUERY_PREFIX', 'query: '),
        'passage_prefix' => env('HF_PASSAGE_PREFIX', 'passage: '),
        'timeout' => (float) env('HF_TIMEOUT', 6),
    ],

];
