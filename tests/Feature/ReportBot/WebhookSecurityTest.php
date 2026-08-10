<?php

namespace Tests\Feature\ReportBot;

use Tests\TestCase;

class WebhookSecurityTest extends TestCase
{
    public function test_webhook_tolak_secret_salah_terima_secret_benar(): void
    {
        config()->set('services.telegram.webhook_secret', 'rahasia123');
        $this->postJson('/telegram/webhook', ['update_id' => 1])->assertForbidden();
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'salah'])
            ->postJson('/telegram/webhook', ['update_id' => 1])->assertForbidden();
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'rahasia123'])
            ->postJson('/telegram/webhook', ['update_id' => 1])->assertOk();
    }

    /**
     * hash_equals('', '') === true di PHP — kalau secret belum dikonfigurasi
     * (kosong) dan request datang tanpa header, perbandingan naif akan lolos.
     * Kontrol tambahan "secret kosong -> selalu tolak" wajib ada.
     */
    public function test_webhook_tolak_saat_secret_belum_dikonfigurasi(): void
    {
        config()->set('services.telegram.webhook_secret', null);
        $this->postJson('/telegram/webhook', ['update_id' => 1])->assertForbidden();
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => ''])
            ->postJson('/telegram/webhook', ['update_id' => 1])->assertForbidden();
    }
}
