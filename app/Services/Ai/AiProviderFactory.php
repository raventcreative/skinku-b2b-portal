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

        // Auto-switch berlapis: kalau primary gagal (kuota/billing/down), request
        // dialihkan ke cadangan-1, lalu -2, -3 berurutan. Rantai kosong → primary saja.
        $backups = self::backupChain($maxTokens);
        if ($backups === []) {
            return $primary;
        }

        return new FailoverAiProvider([$primary, ...$backups]);
    }

    /**
     * Slot cadangan yang SIAP pakai, berurutan (cadangan-1..3). Slot ke-2/3 mewarisi
     * key & base dari cadangan-1 bila dikosongkan. Slot disertakan HANYA bila key
     * (hasil warisan) & model dua-duanya terisi. Publik agar logika inti bisa diuji.
     *
     * @return array<int,array{key:string,base:string,model:string,timeout:int,sequential:bool}>
     */
    public static function resolvedBackupSlots(): array
    {
        $first = (array) config('services.ai.backup');
        $firstKey = (string) ($first['key'] ?? '');
        $firstBase = (string) ($first['base'] ?? '');

        $out = [];
        foreach (['backup', 'backup2', 'backup3'] as $i => $name) {
            $slot = config("services.ai.{$name}");
            if (! is_array($slot)) {
                continue;
            }

            $key = (string) ($slot['key'] ?? '');
            $base = (string) ($slot['base'] ?? '');
            // Cadangan-1 (i=0) pakai nilainya sendiri; slot berikutnya warisi bila kosong.
            if ($i > 0) {
                $key = $key !== '' ? $key : $firstKey;
                $base = $base !== '' ? $base : $firstBase;
            }
            $model = (string) ($slot['model'] ?? '');
            if ($key === '' || $model === '') {
                continue;
            }

            $out[] = [
                'key' => $key,
                'base' => $base,
                'model' => $model,
                'timeout' => (int) ($slot['timeout'] ?? 60),
                'sequential' => (bool) ($slot['sequential'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Rakit provider cadangan dari slot ter-resolve, berurutan.
     *
     * @return array<int,OpenAiProvider>
     */
    private static function backupChain(int $maxTokens): array
    {
        $connectTimeout = (int) config('services.ai.connect_timeout', 10);

        return array_map(
            fn (array $s) => new OpenAiProvider(
                $s['key'],
                $s['base'],
                $s['model'],
                $maxTokens,
                $s['timeout'],
                $connectTimeout,
                sequential: $s['sequential'],
            ),
            self::resolvedBackupSlots(),
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
