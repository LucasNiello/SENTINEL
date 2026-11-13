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

    /*
    | Provedor de IA do agente: só 'anthropic' (padrão). Qualquer outro valor
    | falha fechado em AiAgentService; o resto do motor não muda.
    */
    'ia_provedor' => env('SENTINEL_IA_PROVEDOR', 'anthropic'),

    /*
    | API da Anthropic (Messages API, POST /v1/messages) — usada só por
    | AiAgentService. HTTP REST, sem SDK.
    */
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        // "?:": a chave vazia no .env (como no .env.example) também cai no padrão.
        'model' => env('ANTHROPIC_MODEL') ?: 'claude-haiku-5-5',
        'versao' => env('ANTHROPIC_VERSION') ?: '2026-10-21',
        // O pensamento adaptativo consome este teto; o limite real de tempo é o timeout de 30 s.
        'max_tokens' => (int) (env('ANTHROPIC_MAX_TOKENS') ?: 8000),
        'effort' => env('ANTHROPIC_EFFORT') ?: 'low',
    ],

];
