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

        $primary = match ($provider) {
            'anthropic' => throw new AiException('Provider Anthropic belum didukung — pilih OpenAI di Pengaturan.'),
            default => self::openai($model, $maxTokens),
        };

        // Auto-switch: kalau primary kehabisan kuota/billing atau down, request
        // dialihkan ke otak cadangan. Aktif hanya bila AI_BACKUP_KEY & _MODEL diisi.
        $backup = self::backup($maxTokens);
        if ($backup === null) {
            return $primary;
        }

        return new FailoverAiProvider([$primary, $backup]);
    }

    /** Otak cadangan OpenAI-compatible (OpenRouter/DeepSeek/Groq). Null bila tak diset. */
    private static function backup(int $maxTokens): ?OpenAiProvider
    {
        $key = (string) config('services.ai.backup.key');
        $model = (string) config('services.ai.backup.model');
        if ($key === '' || $model === '') {
            return null;
        }

        return new OpenAiProvider(
            $key,
            (string) config('services.ai.backup.base'),
            $model,
            $maxTokens,
            (int) config('services.ai.backup.timeout', 60),
            (int) config('services.ai.connect_timeout', 10),
            // Default PARALEL (seperti primary): panel CMO/CFO/COO jalan bersamaan
            // lalu di-merge orchestrator, agar total waktu ≈ satu jendela timeout —
            // bukan dijumlah. Mode sekuensial hanya untuk router yang benar-benar
            // tak kuat paralel (opt-in via AI_BACKUP_SEQUENTIAL=true).
            sequential: (bool) config('services.ai.backup.sequential', false),
        );
    }

    private static function openai(string $model, int $maxTokens): OpenAiProvider
    {
        $key = (string) config('services.ai.openai.key');
        if ($key === '') {
            throw new AiException('OPENAI_API_KEY belum diisi di .env server.');
        }

        return new OpenAiProvider(
            $key,
            (string) config('services.ai.openai.base'),
            $model,
            $maxTokens,
            (int) config('services.ai.request_timeout', 45),
            (int) config('services.ai.connect_timeout', 10),
        );
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
