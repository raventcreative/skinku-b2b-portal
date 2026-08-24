# Shopee Fase 4 (Jurnal Akuntansi) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** Jurnal akrual otomatis Shopee (niru `TikTokAccountingService` "Opsi C") dari data order+escrow+wallet, ke GL yang ada. Termasuk wallet cash-out (2-tahap: Kas Shopee wallet → Bank).

**Architecture:** `ShopeeAccountingService` pakai engine `AccountingService::record` (double-entry, wajib balance). Idempoten (guard journal-id/posting_status), cutoff `deduct_from`, saklar `journal_enabled` (default OFF), preview→post manual + unpost scoped. Plus subsistem wallet-tx.

**Tech Stack:** Laravel 13/PHP 8.3. Runner `C:/php83/php.exe artisan test`. Pint `--dirty` sebelum commit.

## Global Constraints

- **Zero-dependency**. **Balance wajib** (engine tolak kalau tidak). **Saklar OFF default**. **Idempoten**. **Unpost scoped** `source_type IN shopee_*`. **Jangan dobel-hitung** (wallet SKIP tipe escrow). **Deploy=git pull** (migrasi 000095).
- **Referensi WAJIB dibaca implementer**: `app/Services/TikTokAccountingService.php` (template Opsi C: `accounts()`, private `record()` wrapper, `previewTransit/postTransit`, `previewSale/postSale`, `previewSettlement`, `postPending`, `unpostAll`, `enabled`, `cutoff`, `acc()`), `app/Services/AccountingService.php` (`record(header,lines,status)`), `app/Models/{AccJournal,AccAccount,AccBranch}.php`, `docs/superpowers/research/tiktok-fase2-4-map.md` §3.3-3.4, spec `docs/superpowers/specs/2026-08-24-shopee-fase4-accounting-design.md`. Serta struktur Fase 3 (`ShopeeSettlementService`, `ShopeeSyncService::syncSettlements`) untuk pola wallet.

**Akun (kode asli, verified di ChartOfAccountSeeder):** kas=`1001` Kas Shopee, bank=`1002` Bank, piutang=`1104` Piutang Shopee (mint), transit=`1203` Persediaan Dalam Perjalanan (lazy), persediaan=`1202`, penjualan=`4001`, pendapatan_lain=`4002`, hpp=`5003`, fee=`6005`, iklan=`6001`, ongkir=`6007`.

---

### Task 1: Migrasi 000095 + model `ShopeeWalletTransaction`

**Files:** Create `database/migrations/2026_01_01_000095_shopee_accounting.php`, `app/Models/ShopeeWalletTransaction.php`; Test `tests/Feature/ShopeeAccountingTest.php`.

- [ ] **Step 1: Failing test** `tests/Feature/ShopeeAccountingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ShopeeWalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopeeAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_model_dan_kolom_jurnal_order(): void
    {
        $w = ShopeeWalletTransaction::create([
            'transaction_id' => 'W-1', 'transaction_type' => 'WITHDRAWAL_COMPLETED',
            'kind' => 'Tarik ke bank', 'amount' => 50000, 'money_flow' => 'MONEY_OUT',
            'posting_status' => ShopeeWalletTransaction::POST_PENDING,
        ]);
        $this->assertFalse($w->isPosted());
        $this->assertEquals('50000.00', $w->fresh()->amount);

        // kolom jurnal order ada
        $o = \App\Models\ShopeeOrder::create([
            'order_sn' => 'O-1', 'status' => 'COMPLETED', 'total_amount' => 100, 'hpp_amount' => 40,
            'stock_status' => 'deducted',
        ]);
        $o->update(['transit_journal_id' => 5, 'sale_journal_id' => 6]);
        $this->assertEquals(5, $o->fresh()->transit_journal_id);

        // journal_enabled di connection
        $c = \App\Models\ShopeeConnection::create(['shop_id' => '1', 'access_token' => 'A', 'refresh_token' => 'R',
            'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30), 'journal_enabled' => true]);
        $this->assertTrue($c->fresh()->journal_enabled);
    }
}
```

- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Migration** `2026_01_01_000095_shopee_accounting.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('transit_journal_id')->nullable()->after('deducted_by');
            $table->unsignedBigInteger('sale_journal_id')->nullable()->after('transit_journal_id');
        });
        Schema::table('shopee_connections', function (Blueprint $table) {
            $table->boolean('journal_enabled')->default(false)->after('deduct_from');
        });
        Schema::create('shopee_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique();
            $table->string('transaction_type')->nullable()->index();
            $table->string('kind', 80)->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->decimal('current_balance', 16, 2)->default(0);
            $table->string('money_flow', 20)->nullable();
            $table->string('order_sn')->nullable()->index();
            $table->string('refund_sn')->nullable();
            $table->string('reason', 190)->nullable();
            $table->string('status')->nullable();
            $table->dateTime('transaction_time')->nullable();
            $table->json('raw')->nullable();
            $table->string('posting_status', 20)->default('pending')->index();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_wallet_transactions');
        Schema::table('shopee_connections', fn (Blueprint $t) => $t->dropColumn('journal_enabled'));
        Schema::table('shopee_orders', fn (Blueprint $t) => $t->dropColumn(['transit_journal_id', 'sale_journal_id']));
    }
};
```

- [ ] **Step 4: Model** `app/Models/ShopeeWalletTransaction.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeWalletTransaction extends Model
{
    public const POST_PENDING = 'pending';

    public const POST_POSTED = 'posted';

    protected $fillable = [
        'transaction_id', 'transaction_type', 'kind', 'amount', 'current_balance',
        'money_flow', 'order_sn', 'refund_sn', 'reason', 'status', 'transaction_time',
        'raw', 'posting_status', 'journal_id', 'posted_at', 'posted_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2', 'current_balance' => 'decimal:2',
        'raw' => 'array', 'transaction_time' => 'datetime', 'posted_at' => 'datetime',
    ];

    public function isPosted(): bool
    {
        return $this->posting_status === self::POST_POSTED;
    }
}
```

Tambah ke `app/Models/ShopeeOrder.php` `$fillable`: `'transit_journal_id', 'sale_journal_id'`. Tambah ke `app/Models/ShopeeConnection.php` `$fillable`: `'journal_enabled'` + `$casts` `'journal_enabled' => 'boolean'`.

- [ ] **Step 5: Run — PASS. Step 6: Pint + commit** `feat(shopee) Fase 4: migrasi 000095 + model ShopeeWalletTransaction`.

---

### Task 2: Wallet subsystem (client + service + sync + command)

**Files:** Modify `app/Services/ShopeeClient.php` (+`getWalletTransactionList`), `app/Services/ShopeeSyncService.php` (+`syncWallet` + dep), `app/Console/Commands/ShopeeSyncCommand.php` (+`--wallet`), `routes/console.php` (cron); Create `app/Services/ShopeeWalletService.php`; Test `ShopeeAccountingTest.php`.

**Interfaces:** mirror Fase-3 `syncSettlements` pola (paginasi page_no + guard 40 + Log). `getWalletTransactionList(access,shop,from,to,pageNo=0,pageSize=100)` → GET `/api/v2/payment/get_wallet_transaction_list` (params `create_time_from/to`,`page_no`,`page_size`). Response `response.transaction_list` + `more`.

- [ ] **Step 1: Failing test** (store + kindFromType + sync):

```php
public function test_wallet_store_dan_kind_mapping(): void
{
    $svc = app(\App\Services\ShopeeWalletService::class);
    $n = $svc->store([
        ['transaction_id' => 'T1', 'transaction_type' => 'PAID_ADS_CHARGE', 'amount' => 5000, 'money_flow' => 'MONEY_OUT', 'create_time' => now()->timestamp],
        ['transaction_id' => 'T2', 'transaction_type' => 'WITHDRAWAL_COMPLETED', 'amount' => 60000, 'money_flow' => 'MONEY_OUT', 'create_time' => now()->timestamp],
    ]);
    $this->assertSame(2, $n);
    $this->assertSame('Biaya iklan', ShopeeWalletTransaction::where('transaction_id', 'T1')->value('kind'));
    $this->assertSame('Tarik ke bank', ShopeeWalletTransaction::where('transaction_id', 'T2')->value('kind'));
}
```

- [ ] **Step 2: Run — FAIL. Step 3:** Add `ShopeeClient::getWalletTransactionList` (mirror `getEscrowList` via `shopCall` GET). 
- [ ] **Step 4:** Create `app/Services/ShopeeWalletService.php`:

```php
<?php

namespace App\Services;

use App\Models\ShopeeWalletTransaction;
use Illuminate\Support\Carbon;

class ShopeeWalletService
{
    public function store(array $apiTx): int
    {
        $n = 0;
        foreach ($apiTx as $t) {
            $id = $t['transaction_id'] ?? null;
            if (! $id) {
                continue;
            }
            $existing = ShopeeWalletTransaction::where('transaction_id', $id)->first();
            $type = $t['transaction_type'] ?? null;
            $ct = $t['create_time'] ?? null;

            ShopeeWalletTransaction::updateOrCreate(
                ['transaction_id' => (string) $id],
                [
                    'transaction_type' => $type,
                    'kind' => $this->kindFromType((string) $type),
                    'amount' => (float) ($t['amount'] ?? 0),
                    'current_balance' => (float) ($t['current_balance'] ?? 0),
                    'money_flow' => $t['money_flow'] ?? null,
                    'order_sn' => $t['order_sn'] ?? null,
                    'refund_sn' => $t['refund_sn'] ?? null,
                    'reason' => $t['reason'] ?? null,
                    'status' => $t['status'] ?? null,
                    'transaction_time' => $ct ? Carbon::createFromTimestamp((int) $ct) : null,
                    'raw' => $t,
                    'posting_status' => $existing->posting_status ?? ShopeeWalletTransaction::POST_PENDING,
                ],
            );
            $n++;
        }

        return $n;
    }

    /** Peta transaction_type Shopee → label ID (Shopee kasih tipe eksplisit — bukan tebak). */
    public function kindFromType(string $type): string
    {
        return match ($type) {
            'ESCROW_VERIFIED_ADD', 'FAST_ESCROW_DISBURSE', 'FAST_ESCROW_DISBURSE_REMAIN' => 'Order cair (ke saldo)',
            'ESCROW_VERIFIED_MINUS', 'FAST_ESCROW_DEDUCT' => 'Koreksi escrow',
            'WITHDRAWAL_COMPLETED' => 'Tarik ke bank',
            'WITHDRAWAL_CREATED' => 'Tarik dibuat',
            'WITHDRAWAL_CANCELLED' => 'Tarik dibatalkan',
            'PAID_ADS_CHARGE', 'AFFILIATE_ADS_SELLER_FEE', 'AFFILIATE_FEE_DEDUCT' => 'Biaya iklan',
            'PAID_ADS_REFUND', 'AFFILIATE_ADS_SELLER_FEE_REFUND' => 'Refund iklan',
            'ADJUSTMENT_ADD', 'ADJUSTMENT_CENTER_ADD', 'FBS_ADJUSTMENT_ADD' => 'Penyesuaian (+)',
            'ADJUSTMENT_MINUS', 'ADJUSTMENT_CENTER_DEDUCT', 'FBS_ADJUSTMENT_MINUS', 'FSF_COST_PASSING_DEDUCT' => 'Penyesuaian (−)',
            default => 'Lainnya',
        };
    }
}
```

- [ ] **Step 5:** `ShopeeSyncService::syncWallet(ShopeeConnection $conn): array` — mirror `syncSettlements` tapi lebih sederhana (getWalletTransactionList paginasi → `ShopeeWalletService::store`, return `['count'=>...]`). Tambah `private ShopeeWalletService $wallet` ke konstruktor. Command: `{--wallet : Tarik mutasi saldo}` blok try/catch. Cron `shopee:sync --wallet` `dailyAt('01:45')`.
- [ ] **Step 6: Run — PASS. Step 7: Pint + commit** `feat(shopee) Fase 4: wallet subsystem (client+service+sync+command)`.

---

### Task 3: `ShopeeAccountingService` — accounts + transit + sale

**Files:** Create `app/Services/ShopeeAccountingService.php`; Test `ShopeeAccountingTest.php`.

> **Baca `TikTokAccountingService`**: tiru `__construct(AccountingService, ShopeeOrderService)`, `accounts()`, private `acc()`, private `record()`, `previewTransit/postTransit`, `previewSale/postSale`, `enabled()`, `cutoff()` — ganti kode akun + `tiktok_`→`shopee_`, `TiktokOrder`→`ShopeeOrder`, `total_amount`/`hpp_amount`/`isDelivered()`/`SHIPPED_STATUSES`/`DELIVERED_STATUSES` dari ShopeeOrder. Butuh `AccBranch::active()` — bila test tak punya cabang, buat 1 di test setup.

- [ ] **Step 1: Failing test** — perlu cabang + akun. Setup helper:

```php
use App\Models\AccBranch;
use App\Services\ShopeeAccountingService;

private function branch(): AccBranch
{
    return AccBranch::firstOrCreate(['code' => 'HQ'], ['name' => 'HQ', 'is_active' => true]);
}

public function test_transit_lalu_sale_akui_omzet_dan_hpp(): void
{
    $this->branch();
    $svc = app(ShopeeAccountingService::class);
    $a = $svc->accounts();
    $o = \App\Models\ShopeeOrder::create(['order_sn' => 'AC-1', 'status' => 'COMPLETED',
        'total_amount' => 100000, 'hpp_amount' => 40000, 'stock_status' => 'deducted']);

    $svc->postTransit($o);
    $this->assertNotNull($o->fresh()->transit_journal_id);
    $this->assertEquals(40000, $svc->balanceOf($a['transit']->id)); // Dr transit 40000

    $svc->postSale($o->fresh());
    $this->assertNotNull($o->fresh()->sale_journal_id);
    $this->assertEquals(0, $svc->balanceOf($a['transit']->id));       // transit lepas
    $this->assertEquals(-100000, $svc->balanceOf($a['penjualan']->id)); // Cr penjualan
    $this->assertEquals(40000, $svc->balanceOf($a['hpp']->id));        // Dr HPP
    $this->assertEquals(100000, $svc->balanceOf($a['piutang']->id));   // Dr piutang
}
```

(Tambah helper `balanceOf` di service yang mendelegasi ke `AccountingService::balanceOf`, atau panggil `app(AccountingService::class)->balanceOf(...)` langsung di test.)

- [ ] **Step 2: Run — FAIL. Step 3:** Tulis `ShopeeAccountingService` bagian accounts+transit+sale (mirror TikTok, akun Shopee). `accounts()` pakai kode di Global Constraints. `postTransit`: guard `transit_journal_id`+`hpp>0`, Dr transit/Cr persediaan (hpp), set `transit_journal_id`, source `shopee_order_transit`. `postSale`: guard `sale_journal_id`+`isDelivered()`, Dr piutang/Cr penjualan (total_amount) + [bila transit ada] Dr hpp/Cr transit (hpp), set `sale_journal_id`, source `shopee_order_sale`.
- [ ] **Step 4: Run — PASS. Step 5: Pint + commit** `feat(shopee) Fase 4: ShopeeAccountingService accounts+transit+sale`.

---

### Task 4: `ShopeeAccountingService` — settlement + wallet recipes

**Files:** Modify `app/Services/ShopeeAccountingService.php`; Test `ShopeeAccountingTest.php`.

- [ ] **Step 1: Failing test** — settlement pakai DATA ASLI (balance) + wallet:

```php
public function test_settlement_balance_dari_data_asli(): void
{
    $this->branch();
    $svc = app(ShopeeAccountingService::class);
    $a = $svc->accounts();
    // Piutang harus ada dulu (dari sale) supaya lunas — buat order+sale
    $o = \App\Models\ShopeeOrder::create(['order_sn' => '2608247FYHUBMG', 'status' => 'COMPLETED',
        'total_amount' => 77665, 'hpp_amount' => 40000, 'stock_status' => 'deducted']);
    $svc->postTransit($o);
    $svc->postSale($o->fresh()); // Dr piutang 77665

    $s = \App\Models\ShopeeSettlement::create(['order_sn' => '2608247FYHUBMG', 'escrow_amount' => 64675,
        'buyer_total_amount' => 77665, 'actual_shipping_fee' => 11765, 'campaign_fee' => 0,
        'posting_status' => \App\Models\ShopeeSettlement::POST_PENDING]);

    $svc->postSettlement($s);
    $this->assertTrue($s->fresh()->isPosted());
    $this->assertEquals(64675, $svc->balanceOf($a['kas']->id));    // Dr kas net
    $this->assertEquals(11765, $svc->balanceOf($a['ongkir']->id)); // Dr ongkir
    $this->assertEquals(1225, $svc->balanceOf($a['fee']->id));     // Dr fee catch-all (77665-64675-11765)
    $this->assertEquals(0, $svc->balanceOf($a['piutang']->id));    // piutang lunas (77665 Dr - 77665 Cr)
}

public function test_wallet_withdrawal_dan_ads_dan_skip_escrow(): void
{
    $this->branch();
    $svc = app(ShopeeAccountingService::class);
    $a = $svc->accounts();

    $wd = \App\Models\ShopeeWalletTransaction::create(['transaction_id' => 'WD', 'transaction_type' => 'WITHDRAWAL_COMPLETED',
        'kind' => 'Tarik ke bank', 'amount' => 50000, 'posting_status' => 'pending']);
    $svc->postWallet($wd);
    $this->assertEquals(50000, $svc->balanceOf($a['bank']->id));  // Dr bank
    $this->assertEquals(-50000, $svc->balanceOf($a['kas']->id));  // Cr kas

    $ads = \App\Models\ShopeeWalletTransaction::create(['transaction_id' => 'AD', 'transaction_type' => 'PAID_ADS_CHARGE',
        'kind' => 'Biaya iklan', 'amount' => 8000, 'posting_status' => 'pending']);
    $svc->postWallet($ads);
    $this->assertEquals(8000, $svc->balanceOf($a['iklan']->id));  // Dr iklan

    // ESCROW_VERIFIED_ADD di-SKIP (sudah di settlement) → tak buat jurnal
    $esc = \App\Models\ShopeeWalletTransaction::create(['transaction_id' => 'ES', 'transaction_type' => 'ESCROW_VERIFIED_ADD',
        'kind' => 'Order cair (ke saldo)', 'amount' => 64675, 'posting_status' => 'pending']);
    $svc->postWallet($esc);
    $this->assertSame('pending', $esc->fresh()->posting_status); // tetap pending (di-skip)
}
```

- [ ] **Step 2: Run — FAIL. Step 3:** Tambah `previewSettlement/postSettlement` + `previewWallet/postWallet`.

`previewSettlement(ShopeeSettlement $s)` → lines (SELALU balance):
```php
$a = $this->accounts();
$net = (float) $s->escrow_amount; $buyer = (float) $s->buyer_total_amount;
$shipping = (float) $s->actual_shipping_fee; $campaign = (float) $s->campaign_fee;
$feeOther = round($buyer - $net - $shipping - $campaign, 2);
$lines = [];
if ($net != 0)      $lines[] = ['account' => $a['kas'],    'debit' => $net,      'credit' => 0, 'memo' => "Escrow cair {$s->order_sn}"];
if ($shipping > 0)  $lines[] = ['account' => $a['ongkir'], 'debit' => $shipping, 'credit' => 0, 'memo' => 'Ongkir'];
if ($campaign > 0)  $lines[] = ['account' => $a['iklan'],  'debit' => $campaign, 'credit' => 0, 'memo' => 'Iklan'];
if ($feeOther > 0)  $lines[] = ['account' => $a['fee'],    'debit' => $feeOther, 'credit' => 0, 'memo' => 'Fee e-commerce'];
elseif ($feeOther < 0) $lines[] = ['account' => $a['pendapatan_lain'], 'debit' => 0, 'credit' => -$feeOther, 'memo' => 'Penyesuaian'];
if ($buyer != 0)    $lines[] = ['account' => $a['piutang'], 'debit' => 0, 'credit' => $buyer, 'memo' => 'Piutang Shopee lunas'];
return $lines;
```
`postSettlement`: guard `isPosted()`, bila lines kosong return null, `record(..., 'shopee_settlement', $s->id, 'cash_in')`, set `posting_status=posted`+`journal_id`+`posted_at`.

`previewWallet(ShopeeWalletTransaction $w)` → lines by type (amt = abs(amount)):
```php
$a = $this->accounts(); $amt = abs((float) $w->amount); $t = (string) $w->transaction_type;
$L = fn ($acc, $dr, $cr, $m) => ['account' => $acc, 'debit' => $dr, 'credit' => $cr, 'memo' => $m];
return match (true) {
    $t === 'WITHDRAWAL_COMPLETED' => [$L($a['bank'], $amt, 0, 'Cair ke bank'), $L($a['kas'], 0, $amt, 'Saldo Shopee keluar')],
    in_array($t, ['PAID_ADS_CHARGE','AFFILIATE_ADS_SELLER_FEE','AFFILIATE_FEE_DEDUCT'], true) => [$L($a['iklan'], $amt, 0, 'Biaya iklan'), $L($a['kas'], 0, $amt, 'Saldo keluar')],
    in_array($t, ['PAID_ADS_REFUND','AFFILIATE_ADS_SELLER_FEE_REFUND'], true) => [$L($a['kas'], $amt, 0, 'Refund iklan'), $L($a['iklan'], 0, $amt, 'Balik iklan')],
    in_array($t, ['ADJUSTMENT_ADD','ADJUSTMENT_CENTER_ADD','FBS_ADJUSTMENT_ADD'], true) => [$L($a['kas'], $amt, 0, 'Penyesuaian masuk'), $L($a['pendapatan_lain'], 0, $amt, 'Pendapatan lain')],
    in_array($t, ['ADJUSTMENT_MINUS','ADJUSTMENT_CENTER_DEDUCT','FBS_ADJUSTMENT_MINUS','FSF_COST_PASSING_DEDUCT'], true) => [$L($a['fee'], $amt, 0, 'Penyesuaian keluar'), $L($a['kas'], 0, $amt, 'Saldo keluar')],
    default => [], // ESCROW_* (sudah di settlement), WITHDRAWAL_CREATED/CANCELLED, tak dikenal → SKIP
};
```
`postWallet`: guard `isPosted()`, `$lines = previewWallet()`; **bila kosong → return null (skip, TAK ubah posting_status)**; else `record(..., 'shopee_wallet', $w->id, type cash_in/out)`, set posted.

- [ ] **Step 4: Run — PASS. Step 5: Pint + commit** `feat(shopee) Fase 4: resep settlement (balance) + wallet (withdrawal/ads/adjust, skip escrow)`.

---

### Task 5: `postPending` + `unpostAll` + switches

**Files:** Modify `app/Services/ShopeeAccountingService.php`; Test `ShopeeAccountingTest.php`.

> Mirror `TikTokAccountingService::postPending/unpostAll/enabled/cutoff`. `enabled()` = `ShopeeConnection::latest('id')->first()?->journal_enabled`.

- [ ] **Step 1: Failing test:**

```php
public function test_switch_off_throw_dan_unpost_scoped_dan_idempoten(): void
{
    $this->branch();
    $svc = app(ShopeeAccountingService::class);
    // tanpa journal_enabled → throw
    \App\Models\ShopeeConnection::create(['shop_id' => '1', 'access_token' => 'A', 'refresh_token' => 'R',
        'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]); // journal_enabled default false
    $this->expectException(\RuntimeException::class);
    $svc->postPending();
}

public function test_full_cycle_idempoten_dan_unpost(): void
{
    $this->branch();
    \App\Models\ShopeeConnection::create(['shop_id' => '1', 'access_token' => 'A', 'refresh_token' => 'R',
        'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30), 'journal_enabled' => true]);
    $svc = app(ShopeeAccountingService::class);
    $a = $svc->accounts();
    \App\Models\ShopeeOrder::create(['order_sn' => 'FC', 'status' => 'COMPLETED', 'total_amount' => 100000,
        'hpp_amount' => 40000, 'stock_status' => 'deducted']);
    \App\Models\ShopeeSettlement::create(['order_sn' => 'FC', 'escrow_amount' => 90000, 'buyer_total_amount' => 100000,
        'actual_shipping_fee' => 0, 'campaign_fee' => 0, 'posting_status' => 'pending']);

    $r1 = $svc->postPending();
    $this->assertGreaterThanOrEqual(1, $r1['sale']);
    $this->assertEquals(0, $svc->balanceOf($a['piutang']->id)); // lunas
    $r2 = $svc->postPending();
    $this->assertSame(0, $r2['sale'] + $r2['settlement']); // idempoten

    // jurnal non-shopee tak kehapus
    $svc->unpostAll();
    $this->assertEquals(0, $svc->balanceOf($a['kas']->id)); // semua jurnal shopee dicabut
    $this->assertSame('pending', \App\Models\ShopeeSettlement::where('order_sn', 'FC')->value('posting_status'));
}
```

- [ ] **Step 2: Run — FAIL. Step 3:** `postPending()`: throw bila `!enabled()`; backfill hpp (order DEDUCTED hpp=0 via `ShopeeOrderService::computeHpp` bila ada, else skip); transit pass (stock_status deducted & transit_journal_id null, cutoff order_created_at); sale pass (status COMPLETED & sale_journal_id null); settlement pass (posting_status pending, cutoff escrow_release_time); wallet pass (posting_status pending, cutoff transaction_time). Per-baris try/catch+Log. Return `{transit,sale,settlement,wallet,failed}`. `unpostAll()`: hapus AccJournal `source_type IN ('shopee_order_transit','shopee_order_sale','shopee_settlement','shopee_wallet')`, reset kolom. Tambah `balanceOf(int)` delegasi ke `AccountingService::balanceOf`.
- [ ] **Step 4: Run — PASS. Step 5: Pint + commit** `feat(shopee) Fase 4: postPending + unpostAll + saklar journal_enabled`.

---

### Task 6: Controller + UI + routes

**Files:** Modify `app/Http/Controllers/ShopeeController.php` (dep + aksi `postJournals/unpostJournals/toggleJournal`), `routes/web.php` (3 route), `resources/views/shopee/settlements.blade.php` (panel jurnal); Test `ShopeeAccountingTest.php`.

> Mirror aksi jurnal `TikTokController` (`postJournals/unpostJournals/toggleJournal`) + panel di `tiktok/settlements.blade.php` (saklar + tombol Posting/Unpost + konfirmasi). `AuditService::log` statik (cek pola Fase 1-3).

- [ ] **Step 1: Failing test:**

```php
public function test_toggle_dan_post_journals_route(): void
{
    $this->branch();
    $admin = \App\Models\User::create(['name' => 'A', 'fullname' => 'A', 'username' => 'jadmin',
        'email' => 'jadmin@skinku.test', 'password' => \Illuminate\Support\Facades\Hash::make('secret123'),
        'role' => \App\Models\User::ROLE_ADMIN, 'status' => \App\Models\User::STATUS_ACTIVE]);
    \App\Models\ShopeeConnection::create(['shop_id' => '1', 'access_token' => 'A', 'refresh_token' => 'R',
        'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);

    $this->actingAs($admin)->post('/shopee/toggle-journal', ['journal_enabled' => '1'])->assertRedirect();
    $this->assertTrue(\App\Models\ShopeeConnection::latest('id')->first()->journal_enabled);

    $this->actingAs($admin)->post('/shopee/post-journals')->assertRedirect(); // enabled → jalan (0 data ok)
}
```

- [ ] **Step 2: Run — FAIL. Step 3:** Controller aksi (inject `ShopeeAccountingService $journals`):
```php
public function postJournals(): \Illuminate\Http\RedirectResponse {
    try { $r = $this->journals->postPending();
        \App\Services\AuditService::log(action: 'shopee_post_journals', targetType: 'shopee', after: $r);
        return back()->with('status', "Jurnal: transit {$r['transit']}, sale {$r['sale']}, settlement {$r['settlement']}, wallet {$r['wallet']}, gagal {$r['failed']}.");
    } catch (\Throwable $e) { return back()->with('error', $e->getMessage()); }
}
public function unpostJournals(): \Illuminate\Http\RedirectResponse {
    $r = $this->journals->unpostAll();
    \App\Services\AuditService::log(action: 'shopee_unpost_journals', targetType: 'shopee', after: $r);
    return back()->with('status', 'Semua jurnal Shopee dicabut.');
}
public function toggleJournal(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse {
    $conn = $this->sync->connection();
    if ($conn) $conn->update(['journal_enabled' => $request->boolean('journal_enabled')]);
    return back()->with('status', 'Saklar jurnal diperbarui.');
}
```
- [ ] **Step 4: Routes** (grup manage_shopee): `POST /shopee/post-journals`→`shopee.post-journals`, `POST /shopee/unpost-journals`→`shopee.unpost-journals`, `POST /shopee/toggle-journal`→`shopee.toggle-journal`.
- [ ] **Step 5: View** — di `settlements.blade.php` tambah panel jurnal (mirror tiktok): checkbox `journal_enabled` (auto-submit toggle-journal), tombol "Posting Jurnal" (tampil bila saklar ON, konfirmasi), link "Cabut semua jurnal Shopee" (konfirmasi jelas scoped).
- [ ] **Step 6: Run filter — PASS. Step 7: Run FULL suite** `C:/php83/php.exe artisan test` — semua hijau. **Step 8: Pint + commit** `feat(shopee) Fase 4: UI panel jurnal (toggle/post/unpost) + route`.

---

## Self-Review
Migrasi+model→T1 · wallet subsystem→T2 · accounts+transit+sale→T3 · settlement+wallet recipe→T4 · postPending+unpost+switch→T5 · controller/UI→T6. Balance: settlement selalu (catch-all), transit/sale/withdrawal/ads by construction. Idempoten: guard journal-id/posting_status. Unpost scoped source_type. Double-count dicegah (wallet SKIP escrow). Saklar OFF default. Placeholder: nihil (resep presisi; tanda amount wallet diverifikasi go-live). Konsistensi tipe: akun key/kode, `postTransit/Sale/Settlement/Wallet`, `postPending{transit,sale,settlement,wallet,failed}` konsisten T3-6.

## Verifikasi sandbox (saat build)
Setelah suite hijau: pakai order asli `2608247FYHUBMG` (escrow 64675/buyer 77665/ongkir 11765) → `postSettlement` → cek jurnal balance (Dr kas 64675 + ongkir 11765 + fee 1225 = Cr piutang 77665). Bukti nyata resep escrow. Wallet: unit test (sandbox 0 data; sign kebukti).
