# Multi-backup AI (Rantai Failover Berlapis) — Rencana Implementasi

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Perpanjang rantai failover AI jadi sampai 3 cadangan berurutan (OpenAI → cadangan-1 → cadangan-2 → cadangan-3), dikonfigurasi lewat slot `.env` bernomor dengan warisan key/base.

**Architecture:** `FailoverAiProvider` sudah mencoba N provider berurutan (tak diubah). Perubahan hanya di perakitan: `config/services.php` dapat slot `backup2`/`backup3`; `AiProviderFactory` mendapat `resolvedBackupSlots()` (murni, publik, teruji) + `backupChain()` yang dipakai `make()` untuk merangkai `[$primary, ...$backups]`.

**Tech Stack:** Laravel 13 / PHP 8.3, zero-dependency. Runner `/c/php83/php.exe artisan test`; format `/c/php83/php.exe vendor/bin/pint --dirty`.

## Global Constraints

- **Zero-dependency:** TANPA paket composer/npm baru.
- **Backward-compatible penuh:** tanpa mengisi slot baru, perilaku IDENTIK sekarang (satu cadangan `AI_BACKUP_*`, atau tanpa cadangan → primary saja). Nama env slot-1 (`AI_BACKUP_KEY/BASE/MODEL/TIMEOUT/SEQUENTIAL`) TAK berubah.
- **`FailoverAiProvider` TIDAK disentuh** (sudah dukung N provider).
- **`AiProviderFactory::make(): AiProvider`** — signature tetap.
- **Warisan:** slot ke-2/3 yang `key`/`base` kosong mewarisi milik cadangan-1; `timeout`/`sequential` pakai default per-slot (60 / false). Slot disertakan HANYA bila key (hasil warisan) & model dua-duanya non-kosong; urutan cadangan-1→2→3 dijaga.
- **Branch:** kerja di `feat/multi-backup-ai` (fork dari `main`). Jangan mulai di `main`.
- Runner tes: `/c/php83/php.exe artisan test` (fokus: `--filter=NamaTest`). Pint `--dirty` sebelum tiap commit.

## File Structure

- Modify: `config/services.php` — tambah `ai.backup2`, `ai.backup3` (Task 1).
- Modify: `app/Services/Ai/AiProviderFactory.php` — tambah `resolvedBackupSlots()` (Task 1); tambah `backupChain()`, rewire `make()`, hapus `backup()` lama (Task 2).
- Modify: `.env.example` — dokumentasi `AI_BACKUP2_*` / `AI_BACKUP3_*` (Task 2).
- Test: `tests/Feature/BackupChainTest.php` (baru, Task 1) — unit `resolvedBackupSlots()`.
- Test: `tests/Feature/AiFailoverTest.php` (ada) — tambah 3-level + chain make() (Task 2).

---

### Task 1: Config slot bernomor + `resolvedBackupSlots()` (logika inti, teruji)

**Files:**
- Modify: `config/services.php` (dalam array `'ai'`, setelah blok `'backup'`)
- Modify: `app/Services/Ai/AiProviderFactory.php`
- Test: `tests/Feature/BackupChainTest.php`

**Interfaces:**
- Consumes: `config('services.ai.backup')` (slot-1, sudah ada: `key`,`base`,`model`,`timeout`,`sequential`).
- Produces: `config('services.ai.backup2')`, `config('services.ai.backup3')` (bentuk sama; `base` TANPA default → kosong = warisi). `AiProviderFactory::resolvedBackupSlots(): array` — array urut `['key','base','model','timeout','sequential']`, hanya slot aktif, warisan diterapkan. **Belum dipakai `make()`** (di-wire di Task 2).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BackupChainTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=BackupChainTest`
Expected: FAIL — `resolvedBackupSlots()` belum ada (Error: Call to undefined method).

- [ ] **Step 3: Add the config slots**

In `config/services.php`, inside the `'ai' => [ ... ]` array, immediately AFTER the existing `'backup' => [ ... ],` block, add:

```php
        // Cadangan BERLAPIS (opsional). Slot ke-2/3 mewarisi key & base dari
        // cadangan-1 bila dikosongkan — mis. cukup isi AI_BACKUP2_MODEL untuk model
        // lain di akun 9router yang sama. base sengaja TANPA default (kosong = warisi).
        // Aktif hanya bila (key hasil-warisan) & model terisi.
        'backup2' => [
            'key' => env('AI_BACKUP2_KEY'),
            'base' => env('AI_BACKUP2_BASE'),
            'model' => env('AI_BACKUP2_MODEL'),
            'timeout' => (int) env('AI_BACKUP2_TIMEOUT', 60),
            'sequential' => (bool) env('AI_BACKUP2_SEQUENTIAL', false),
        ],
        'backup3' => [
            'key' => env('AI_BACKUP3_KEY'),
            'base' => env('AI_BACKUP3_BASE'),
            'model' => env('AI_BACKUP3_MODEL'),
            'timeout' => (int) env('AI_BACKUP3_TIMEOUT', 60),
            'sequential' => (bool) env('AI_BACKUP3_SEQUENTIAL', false),
        ],
```

- [ ] **Step 4: Add `resolvedBackupSlots()` to the factory**

In `app/Services/Ai/AiProviderFactory.php`, add this method (e.g. just after `make()`):

```php
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
```

- [ ] **Step 5: Run test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=BackupChainTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Full suite (no regressions — make() still uses the old backup())**

Run: `/c/php83/php.exe artisan test --filter=AiFailoverTest`
Expected: PASS — perilaku factory lama tak berubah (make() belum memakai method baru).

- [ ] **Step 7: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add config/services.php app/Services/Ai/AiProviderFactory.php tests/Feature/BackupChainTest.php
git commit -m "feat(ai): slot cadangan bernomor + resolvedBackupSlots() (warisan key/base)"
```

---

### Task 2: Rakit rantai di `make()` + `.env.example` + tes failover berlapis

**Files:**
- Modify: `app/Services/Ai/AiProviderFactory.php`
- Modify: `.env.example`
- Test: `tests/Feature/AiFailoverTest.php`

**Interfaces:**
- Consumes: `AiProviderFactory::resolvedBackupSlots()` (Task 1); `OpenAiProvider::__construct(string $apiKey, string $base, string $model, int $maxTokens, int $timeout=45, int $connectTimeout=10, bool $sequential=false)`; `FailoverAiProvider(array $providers)`.
- Produces: `make()` merangkai `[$primary, ...backupChain()]`; `backup()` lama dihapus.

- [ ] **Step 1: Write the failing test**

Add these tests to `tests/Feature/AiFailoverTest.php` (the file already has `failing()`/`replying()` helpers and `use` imports for `FailoverAiProvider`, `AiProviderFactory`, `OpenAiProvider`, `AiProvider`):

```php
    public function test_failover_tiga_level_tembus_ke_cadangan_kedua(): void
    {
        $chain = new FailoverAiProvider([
            $this->failing('primary mati'),
            $this->failing('cadangan-1 mati'),
            $this->replying('jawaban dari cadangan-2'),
        ]);

        $this->assertSame('jawaban dari cadangan-2', $chain->chat([], [])->text);
    }

    public function test_factory_dua_cadangan_bangun_rantai_failover(): void
    {
        config([
            'services.ai.openai.key' => 'sk-primary',
            'services.ai.backup' => ['key' => 'k1', 'base' => 'https://openrouter.ai/api/v1', 'model' => 'openai/gpt-4o-mini', 'timeout' => 60, 'sequential' => false],
            'services.ai.backup2' => ['key' => null, 'base' => null, 'model' => 'google/gemini-flash-1.5', 'timeout' => 60, 'sequential' => false],
            'services.ai.backup3' => ['key' => null, 'base' => null, 'model' => null, 'timeout' => 60, 'sequential' => false],
        ]);

        // Dua cadangan aktif (slot-2 warisi key slot-1) → primary + 2 = rantai Failover.
        $this->assertCount(2, AiProviderFactory::resolvedBackupSlots());
        $this->assertInstanceOf(FailoverAiProvider::class, AiProviderFactory::make());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=AiFailoverTest`
Expected: `test_failover_tiga_level...` PASSES already (FailoverAiProvider supports N) but `test_factory_dua_cadangan...` — the `resolvedBackupSlots` count assertion passes (Task 1), and `make()` returns FailoverAiProvider already (via old single-backup path, since `backup.model` is set). So this may already pass. That is acceptable: the RED that matters is proven in Step 4 by the behavior that only the NEW wiring guarantees — see note below. If both new tests pass at Step 2, proceed; the meaningful verification is that after the rewire `make()` still returns FailoverAiProvider AND the single-backup / no-backup tests stay green.

> Note: these tests assert observable contract (chain returns FailoverAiProvider / a 3-deep fake chain resolves). The internal switch from `backup()` to `backupChain()` is a refactor whose correctness is pinned by Task 1's `resolvedBackupSlots` unit tests plus the unchanged `test_factory_tanpa_backup_kembalikan_provider_tunggal`. Do not fabricate a failing assertion; rely on the full existing suite staying green through the rewire.

- [ ] **Step 3: Add `backupChain()` and rewire `make()`; delete old `backup()`**

In `app/Services/Ai/AiProviderFactory.php`:

1. Replace the backup wiring inside `make()`. Change:

```php
        // Auto-switch: kalau primary kehabisan kuota/billing atau down, request
        // dialihkan ke otak cadangan. Aktif hanya bila AI_BACKUP_KEY & _MODEL diisi.
        $backup = self::backup($maxTokens);
        if ($backup === null) {
            return $primary;
        }

        return new FailoverAiProvider([$primary, $backup]);
```

to:

```php
        // Auto-switch berlapis: kalau primary gagal (kuota/billing/down), request
        // dialihkan ke cadangan-1, lalu -2, -3 berurutan. Rantai kosong → primary saja.
        $backups = self::backupChain($maxTokens);
        if ($backups === []) {
            return $primary;
        }

        return new FailoverAiProvider([$primary, ...$backups]);
```

2. DELETE the entire old `private static function backup(int $maxTokens): ?OpenAiProvider { ... }` method.

3. Add `backupChain()` (e.g. just below `resolvedBackupSlots()`):

```php
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
```

- [ ] **Step 4: Run tests to verify pass (new + existing failover/factory)**

Run: `/c/php83/php.exe artisan test --filter=AiFailoverTest`
Expected: PASS — including the existing `test_factory_tanpa_backup_kembalikan_provider_tunggal` (no backup → `OpenAiProvider`) and `test_factory_dengan_backup_bangun_rantai_failover` (1 backup → `FailoverAiProvider`), plus the 2 new tests. If any existing test fails, the rewire broke backward compatibility — fix before proceeding.

- [ ] **Step 5: Document env vars in `.env.example`**

In `.env.example`, find the existing `AI_BACKUP_*` lines and add below them:

```
# Cadangan AI berlapis (opsional). Slot ke-2/3: kosongkan KEY/BASE untuk mewarisi
# dari AI_BACKUP_* (mis. model lain di akun 9router yang sama → cukup isi MODEL).
# Aktif hanya bila (KEY hasil-warisan) & MODEL terisi. Urutan: OpenAI → 1 → 2 → 3.
AI_BACKUP2_KEY=
AI_BACKUP2_BASE=
AI_BACKUP2_MODEL=
AI_BACKUP3_KEY=
AI_BACKUP3_BASE=
AI_BACKUP3_MODEL=
```

> If `.env.example` has no `AI_BACKUP_*` lines yet, add these under an `# AI` section near the other AI keys.

- [ ] **Step 6: Full suite**

Run: `/c/php83/php.exe artisan test`
Expected: PASS — all existing tests + new ones green.

- [ ] **Step 7: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Services/Ai/AiProviderFactory.php .env.example tests/Feature/AiFailoverTest.php
git commit -m "feat(ai): rakit rantai failover berlapis di make() (primary + N cadangan)"
```

---

## Self-Review

**1. Spec coverage:**
- 3 slot bernomor + warisan key/base → Task 1 (config `backup2`/`backup3` + `resolvedBackupSlots`). ✓
- Rakit rantai `[primary, ...backups]` di `make()` → Task 2. ✓
- `FailoverAiProvider` tak disentuh → tak ada task yang mengubahnya. ✓
- Backward-compat (tanpa slot baru = perilaku lama) → dijaga oleh `test_factory_tanpa_backup...` (Task 2 Step 4) + slot-1 tetap. ✓
- `.env.example` dokumentasi → Task 2 Step 5. ✓
- Rencana Tes spec (resolvedBackupSlots inheritance/order/filter/empty; 3-level failover; make() chain) → Task 1 + Task 2 tests. ✓

**2. Placeholder scan:** Tak ada TBD/TODO. Task 2 Step 2 secara jujur mencatat kedua tes baru bisa langsung hijau (refactor berkontrak-sama) dan tak memaksa assertion palsu — RED sejati untuk logika inti ada di Task 1 (`resolvedBackupSlots` undefined → error).

**3. Type consistency:** `resolvedBackupSlots()` mengembalikan array `['key','base','model','timeout','sequential']`; `backupChain()` memetakannya ke `OpenAiProvider(key, base, model, maxTokens, timeout, connectTimeout, sequential:)` — cocok dengan konstruktor. `make()` memakai `backupChain()`; `backup()` lama dihapus (tak ada pemanggil lain — ia privat, hanya dari `make()`).

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-09-15-multi-backup-ai.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks.

**2. Inline Execution** — execute tasks in this session using executing-plans.

**Which approach?**
