<?php

namespace App\Services\Ai;

use App\Models\AppSetting;

/**
 * Bikin AiProvider aktif: provider & model dari Pengaturan (AppSetting), jatuh
 * ke config/services.php kalau belum diset. Key diambil dari config (.env).
 * Inilah satu-satunya tempat yang tahu "lagi pakai otak apa".
 */
class AiProviderFactory
{
    public static function make(): AiProvider
    {
        $provider = AppSetting::get('ai_provider', (string) config('services.ai.provider'));
        $model = AppSetting::get('ai_model', (string) config('services.ai.default_model'));
        $maxTokens = (int) config('services.ai.max_output_tokens', 1500);

        return match ($provider) {
            'anthropic' => throw new AiException('Provider Anthropic belum didukung — pilih OpenAI di Pengaturan.'),
            default => self::openai($model, $maxTokens),
        };
    }

    private static function openai(string $model, int $maxTokens): OpenAiProvider
    {
        $key = (string) config('services.ai.openai.key');
        if ($key === '') {
            throw new AiException('OPENAI_API_KEY belum diisi di .env server.');
        }

        return new OpenAiProvider($key, (string) config('services.ai.openai.base'), $model, $maxTokens);
    }

    /** Daftar provider yang siap pakai (ada key-nya) — buat dropdown Pengaturan. */
    public static function available(): array
    {
        $out = [];
        if (filled(config('services.ai.openai.key'))) {
            $out['openai'] = 'OpenAI';
        }
        if (filled(config('services.ai.anthropic.key'))) {
            $out['anthropic'] = 'Anthropic (Claude)';
        }

        return $out;
    }
}
