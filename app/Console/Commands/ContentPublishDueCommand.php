<?php

namespace App\Console\Commands;

use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Services\Social\ContentPublisher;
use App\Services\Social\MetaClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Terbitkan target konten yang sudah jatuh tempo (FR-50, FR-56..58). Cron tiap menit.
 * Retry & polling container dikelola di tabel (attempts / next_attempt_at),
 * bukan $tries queue — worker global berjalan --tries=1.
 *
 * ponytail: diproses inline di command, bukan queue job — panggilan API-nya
 * singkat (video diproses async oleh platform). Pindah ke job bila volume
 * per menit sudah bikin command melewati 1 menit.
 */
class ContentPublishDueCommand extends Command
{
    protected $signature = 'content:publish-due {--limit=20 : Maksimal target per jalan}';

    protected $description = 'Terbitkan konten terjadwal ke Facebook/Instagram/Threads (retry + polling container).';

    public function handle(ContentPublisher $publisher): int
    {
        $due = ContentPostTarget::query()
            ->where('status', ContentPostTarget::QUEUED)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->whereHas('post', fn ($q) => $q
                ->whereIn('status', [ContentPost::SCHEDULED, ContentPost::PUBLISHING])
                ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now())))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        foreach ($due as $id) {
            // Klaim atomik: kalau proses lain sudah mengambilnya, lewati.
            if (ContentPostTarget::whereKey($id)->where('status', ContentPostTarget::QUEUED)->update(['status' => ContentPostTarget::PUBLISHING]) === 0) {
                continue;
            }
            $this->process(ContentPostTarget::with('post')->find($id), $publisher);
        }

        $this->info("Diproses: {$due->count()} target.");

        return self::SUCCESS;
    }

    private function process(ContentPostTarget $target, ContentPublisher $publisher): void
    {
        $target->post->recomputeStatus();

        if ($target->external_id) { // sudah terbit sebelumnya → jangan dobel (FR-58)
            $target->update(['status' => ContentPostTarget::PUBLISHED]);
            $target->post->recomputeStatus();

            return;
        }

        try {
            $result = $publisher->publish($target);

            if ($result['status'] === 'pending') {
                $polls = $target->container_polls + 1;
                if ($polls >= config('content.container_max_polls')) {
                    throw new \RuntimeException('Platform belum selesai memproses media setelah '.$polls.' menit.');
                }
                $target->update(['status' => ContentPostTarget::QUEUED, 'container_polls' => $polls, 'next_attempt_at' => now()->addMinute()]);
            } else {
                $target->update([
                    'status' => ContentPostTarget::PUBLISHED, 'external_id' => $result['external_id'],
                    'permalink' => $result['permalink'], 'published_at' => now(), 'last_error' => null,
                ]);
            }
        } catch (Throwable $e) {
            $this->recordFailure($target, MetaClient::sanitize($e->getMessage()));
        }

        $target->post->recomputeStatus();
    }

    private function recordFailure(ContentPostTarget $target, string $error): void
    {
        $backoff = config('content.retry_backoff_minutes');
        $attempts = $target->attempts + 1;
        $retry = $attempts <= count($backoff);

        $target->update([
            'attempts' => $attempts,
            'last_error' => $error,
            'container_id' => null, // container gagal/basi → buat ulang saat retry
            'container_polls' => 0,
            'status' => $retry ? ContentPostTarget::QUEUED : ContentPostTarget::FAILED,
            'next_attempt_at' => $retry ? now()->addMinutes($backoff[$attempts - 1]) : null,
        ]);
        $this->warn("Target #{$target->id} ({$target->platform}) gagal [{$attempts}]: {$error}");
    }
}
