<?php

return [
    // openai = semua endpoint OpenAI-compatible · anthropic = Claude Messages API.
    'provider' => env('AI_PROVIDER', 'openai'),
    'model' => env('AI_MODEL', 'gpt-4o-mini'),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 4000),
    // Nama parameter batas keluaran. 'max_tokens' diterima hampir semua endpoint
    // OpenAI-compatible; model penalaran OpenAI terbaru menuntut
    // 'max_completion_tokens'. Salah pilih tidak fatal — provider menukarnya
    // otomatis kalau endpoint menolak.
    'token_param' => env('AI_TOKEN_PARAM', 'max_tokens'),
    'request_timeout' => (int) env('AI_REQUEST_TIMEOUT', 120),
    'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 10),

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'base' => env('OPENAI_API_BASE', 'https://api.openai.com/v1'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'base' => env('ANTHROPIC_API_BASE', 'https://api.anthropic.com/v1'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
    ],

    // Otak cadangan — dipakai otomatis saat primary gagal/kuota habis.
    'backup' => [
        'key' => env('AI_BACKUP_KEY'),
        'base' => env('AI_BACKUP_BASE', 'https://openrouter.ai/api/v1'),
        'model' => env('AI_BACKUP_MODEL'),
        'timeout' => (int) env('AI_BACKUP_TIMEOUT', 120),
    ],

    'intake' => [
        'max_upload_kb' => (int) env('INTAKE_MAX_UPLOAD_KB', 12288),
    ],
];
