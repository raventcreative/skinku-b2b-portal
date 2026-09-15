<?php

namespace Tests\Feature;

use App\Services\Ai\AiProviderFactory;
use Tests\TestCase;

class BackupChainTest extends TestCase
{
    /** Set slot config cadangan; null = tak diisi. */
    private function slots(array $overrides): void
    {
        // Reset ketiga slot ke kosong lalu terapkan override.
        config([
            'services.ai.backup' => ['key' => null, 'base' => 'https://openrouter.ai/api/v1', 'model' => null, 'timeout' => 60, 'sequential' => false],
            'services.ai.backup2' => ['key' => null, 'base' => null, 'model' => null, 'timeout' => 60, 'sequential' => false],
            'services.ai.backup3' => ['key' => null, 'base' => null, 'model' => null, 'timeout' => 60, 'sequential' => false],
        ]);
        foreach ($overrides as $path => $val) {
            config(["services.ai.{$path}" => $val]);
        }
    }

    public function test_hanya_cadangan1_satu_slot(): void
    {
        $this->slots(['backup.key' => 'k1', 'backup.model' => 'm1']);
        $out = AiProviderFactory::resolvedBackupSlots();
        $this->assertCount(1, $out);
        $this->assertSame('m1', $out[0]['model']);
        $this->assertSame('k1', $out[0]['key']);
    }

    public function test_cadangan1_dan_2_urut(): void
    {
        $this->slots([
            'backup.key' => 'k1', 'backup.model' => 'm1',
            'backup2.key' => 'k2', 'backup2.model' => 'm2',
        ]);
        $out = AiProviderFactory::resolvedBackupSlots();
        $this->assertCount(2, $out);
        $this->assertSame('m1', $out[0]['model']); // cadangan-1 dulu
        $this->assertSame('m2', $out[1]['model']);
    }

    public function test_slot2_warisi_key_dan_base_dari_slot1(): void
    {
        $this->slots([
            'backup.key' => 'k1', 'backup.base' => 'https://9router.test/v1', 'backup.model' => 'm1',
            // slot-2: key & base sengaja DIKOSONGKAN → harus warisi slot-1
            'backup2.model' => 'gemini-x',
        ]);
        $out = AiProviderFactory::resolvedBackupSlots();
        $this->assertCount(2, $out);
        $this->assertSame('k1', $out[1]['key']);
        $this->assertSame('https://9router.test/v1', $out[1]['base']);
        $this->assertSame('gemini-x', $out[1]['model']);
    }

    public function test_slot2_model_tanpa_key_di_skip(): void
    {
        // slot-1 tak lengkap (tak ada key) DAN slot-2 tak punya key → dua-duanya di-skip.
        $this->slots([
            'backup.model' => 'm1',   // tanpa key → skip
            'backup2.model' => 'm2',  // warisi key slot-1 yg juga kosong → skip
        ]);
        $out = AiProviderFactory::resolvedBackupSlots();
        $this->assertCount(0, $out);
    }

    public function test_tanpa_cadangan_kosong(): void
    {
        $this->slots([]);
        $this->assertCount(0, AiProviderFactory::resolvedBackupSlots());
    }

    public function test_tiga_slot_urut(): void
    {
        $this->slots([
            'backup.key' => 'k1', 'backup.model' => 'm1',
            'backup2.key' => 'k2', 'backup2.model' => 'm2',
            'backup3.key' => 'k3', 'backup3.model' => 'm3',
        ]);
        $out = AiProviderFactory::resolvedBackupSlots();
        $this->assertSame(['m1', 'm2', 'm3'], array_column($out, 'model'));
    }
}
