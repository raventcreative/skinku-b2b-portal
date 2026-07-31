<?php

namespace App\Jobs;

use App\Models\OkrCycle;
use App\Services\OkrAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Susun draf OKR (panel AI + orchestrator) di BACKGROUND. Dijalankan worker
 * antrean via scheduler cron, sehingga request web tak menunggu proses AI yang
 * bisa lama — tak ada lagi Request Timeout / 503 saat generate.
 */
class GenerateOkrDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Proses AI boleh lama; jangan di-retry otomatis (hindari kartu dobel/biaya). */
    public int $timeout = 300;

    public int $tries = 1;

    /**
     * @param  array<string,mixed>  $input
     */
    public function __construct(public int $cycleId, public array $input) {}

    public function handle(OkrAiService $service): void
    {
        $cycle = OkrCycle::find($this->cycleId);
        // Hanya proses siklus yang memang sedang menunggu digenerate.
        if (! $cycle || ! $cycle->isGenerating()) {
            return;
        }

        try {
            $service->runGeneration($cycle, $this->input);
        } catch (Throwable $e) {
            $cycle->update([
                'generation_status' => OkrCycle::GEN_FAILED,
                'generation_error' => Str::limit($e->getMessage(), 1000, ''),
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        OkrCycle::where('id', $this->cycleId)
            ->where('generation_status', OkrCycle::GEN_GENERATING)
            ->update([
                'generation_status' => OkrCycle::GEN_FAILED,
                'generation_error' => Str::limit($e->getMessage(), 1000, ''),
            ]);
    }
}
