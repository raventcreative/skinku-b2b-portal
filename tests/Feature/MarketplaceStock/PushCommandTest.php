<?php

namespace Tests\Feature\MarketplaceStock;

use App\Services\MarketplaceStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 7: command `marketplace:push-stock` — pembungkus tipis di atas
 * MarketplaceStockService::pushDirty(), dipanggil scheduler tiap 5 menit
 * (routes/console.php). Command sendiri tak menyentuh DB/HTTP — itu semua
 * tanggung jawab service (sudah diuji di PushStockTest). Di sini cukup
 * pastikan command memanggil pushDirty() sekali dan melaporkan hasilnya.
 */
class PushCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_memanggil_push_dirty_dan_sukses(): void
    {
        $this->mock(MarketplaceStockService::class, function ($m) {
            $m->shouldReceive('pushDirty')->once()->andReturn(['pushed' => 0, 'skipped' => 0, 'failed' => 0]);
        });

        $this->artisan('marketplace:push-stock')->assertExitCode(0);
    }

    public function test_command_melaporkan_ringkasan_angka_pada_output(): void
    {
        $this->mock(MarketplaceStockService::class, function ($m) {
            $m->shouldReceive('pushDirty')->once()->andReturn(['pushed' => 3, 'skipped' => 1, 'failed' => 2]);
        });

        $this->artisan('marketplace:push-stock')
            ->assertExitCode(0)
            ->expectsOutputToContain('Push stok: 3 terkirim · 1 dilewati · 2 gagal.');
    }
}
