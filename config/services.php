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

    // TikTok Shop Open API (Custom app). App secret WAJIB diisi di .env server —
    // jangan commit. service_id ada di Partner Center URL.
    'tiktok' => [
        'app_key' => env('TIKTOK_APP_KEY'),
        'app_secret' => env('TIKTOK_APP_SECRET'),
        'service_id' => env('TIKTOK_SERVICE_ID'),
        'auth_base' => env('TIKTOK_AUTH_BASE', 'https://auth.tiktok-shops.com'),
        'api_base' => env('TIKTOK_API_BASE', 'https://open-api.tiktokglobalshop.com'),
        'authorize_base' => env('TIKTOK_AUTHORIZE_BASE', 'https://services.tiktokshop.com'),
    ],

    // Shopee Open Platform (akun "Shopee Seller" — app untuk toko sendiri).
    // partner_key WAJIB diisi di .env server — jangan pernah commit.
    // Catatan: access_token Shopee hanya berlaku ~4 JAM (TikTok 7 hari), jadi
    // refresh harus dicek di setiap panggilan.
    'shopee' => [
        'partner_id' => env('SHOPEE_PARTNER_ID'),
        'partner_key' => env('SHOPEE_PARTNER_KEY'),
        // Sandbox: https://partner.test-stable.shopeemobile.com
        'api_base' => env('SHOPEE_API_BASE', 'https://partner.shopeemobile.com'),
    ],

    // Asisten AI (provider-agnostic). Key WAJIB di .env server — jangan commit.
    // Provider & model aktif dipilih di Pengaturan (AppSetting), fallback ke sini.
    // Lihat AI_ASSISTANT_SPEC.md.
    'ai' => [
        'provider' => env('AI_PROVIDER', 'openai'),
        'default_model' => env('AI_MODEL', 'gpt-4o-mini'),
        'max_iterations' => (int) env('AI_MAX_ITERATIONS', 5),
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 1500),
        'openai' => [
            'key' => env('OPENAI_API_KEY'),
            'base' => env('OPENAI_API_BASE', 'https://api.openai.com/v1'),
        ],
        'anthropic' => [
            'key' => env('ANTHROPIC_API_KEY'),
            'base' => env('ANTHROPIC_API_BASE', 'https://api.anthropic.com/v1'),
        ],
    ],

];
