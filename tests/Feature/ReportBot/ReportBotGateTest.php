<?php

namespace Tests\Feature\ReportBot;

use App\Models\AppSetting;
use App\Models\TelegramBotChat;
use App\Services\ReportBot\ReportBotGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportBotGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_gerbang_kode_akses(): void
    {
        AppSetting::put('report_bot_access_code', 'BUKA123');
        $g = app(ReportBotGate::class);
        $this->assertSame('need_code', $g->check(1, 'A', 'halo'));      // belum aktif, bukan kode
        $this->assertSame('wrong_code', $g->check(1, 'A', 'salah'));
        $this->assertSame('authorized_now', $g->check(1, 'A', 'BUKA123'));
        $this->assertSame('active', $g->check(1, 'A', 'apa saja'));     // sudah aktif
        TelegramBotChat::where('chat_id', 1)->update(['is_blocked' => true]);
        $this->assertSame('blocked', $g->check(1, 'A', 'apa saja'));
    }
}
