<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\OpenAiProvider;
use Illuminate\Console\Command;

/**
 * Uji otak AI + rantai CADANGAN (failover) satu per satu — supaya ketahuan
 * provider mana yang hidup/mati tanpa menebak. Dipakai saat AI chat "berhenti
 * membalas" (mis. OpenAI down): kalau cadangan HIDUP, chat otomatis pindah ke
 * sana; kalau cadangan KOSONG/GAGAL, isi/perbaiki AI_BACKUP_* di .env.
 */
class AiPingCommand extends Command
{
    protected $signature = 'ai:ping {--prompt=Balas satu kata saja: ok}';

    protected $description = 'Uji otak AI utama + cadangan (failover) — cek mana yang hidup/mati.';

    public function handle(): int
    {
        $prompt = [['role' => 'user', 'content' => (string) $this->option('prompt')]];
        $maxTokens = (int) config('services.ai.max_output_tokens', 1500);
        $reqTo = (int) config('services.ai.request_timeout', 45);
        $connTo = (int) config('services.ai.connect_timeout', 10);
        $alive = [];

        // --- Primary (OpenAI) ---
        $pModel = (string) AppSetting::get('ai_model', (string) config('services.ai.default_model'));
        $pKey = (string) config('services.ai.openai.key');
        $this->line('Primary   : OpenAI · model '.$pModel.' · key '.($pKey !== '' ? 'ADA' : 'KOSONG'));
        if ($pKey !== '') {
            $alive[] = $this->probe('  Primary', new OpenAiProvider($pKey, (string) config('services.ai.openai.base'), $pModel, $maxTokens, $reqTo, $connTo), $prompt);
        }

        // --- Cadangan (backup1..3) ---
        $slots = AiProviderFactory::resolvedBackupSlots();
        if ($slots === []) {
            $this->warn('Cadangan  : BELUM ADA. Auto-switch tak jalan — isi AI_BACKUP_KEY, AI_BACKUP_BASE, AI_BACKUP_MODEL di .env server.');
        }
        foreach ($slots as $i => $s) {
            $n = $i + 1;
            $this->line("Cadangan-{$n}: base ".($s['base'] !== '' ? $s['base'] : '(warisi cadangan-1)').' · model '.$s['model']);
            $alive[] = $this->probe("  Cadangan-{$n}", new OpenAiProvider($s['key'], $s['base'], $s['model'], $maxTokens, $s['timeout'], $connTo, sequential: $s['sequential']), $prompt);
        }

        $this->newLine();
        if (in_array(true, $alive, true)) {
            $this->info('KESIMPULAN: minimal satu otak AI HIDUP → chat AI bisa membalas (otomatis pindah ke cadangan bila primary mati).');

            return self::SUCCESS;
        }

        $this->error('KESIMPULAN: SEMUA otak AI mati / salah konfigurasi → AI chat belum bisa membalas. Perbaiki key/base/model di atas.');

        return self::FAILURE;
    }

    private function probe(string $label, OpenAiProvider $provider, array $prompt): bool
    {
        try {
            $provider->chat($prompt, []);
            $this->info($label.' → HIDUP ✅');

            return true;
        } catch (\Throwable $e) {
            $this->error($label.' → MATI: '.$e->getMessage());

            return false;
        }
    }
}
