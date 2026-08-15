# Komisi Override — Fase 1 (Engine + Saldo) — Rencana Implementasi

> **Untuk pekerja agentik:** SUB-SKILL WAJIB: `superpowers:subagent-driven-development`. Langkah pakai checkbox `- [ ]`.

**Goal:** Matiin routing Model X (semua order ke HQ) + engine komisi override (differensial per rank, naik-pohon) & join (10% upline langsung), masuk saldo.

**Architecture:** `createForPartner` berhenti set `seller_id` (semua PO seller null). `CommissionService` dipicu di `complete()` (jalur normal), telusuri rantai upline pembeli, catat komisi ke tabel `commissions`. Rate dari `AppSetting` (configurable). Zero-dependency. Spec: `docs/superpowers/specs/2026-08-15-komisi-override-saldo-withdraw-design.md`.

**Tech Stack:** Laravel 13, PHP 8.3, Eloquent. Runner `C:\php83\php.exe artisan test`.

## Global Constraints
- Komisi HANYA pada PO **seller_id === null** (HQ langsung) & jalur **normal** `complete()` (BUKAN backdated/pra-opname baris 235 — itu backfill historis, jangan komisi). Guard `seller_id===null` juga cegah dobel bila ada PO inter-partner dorman.
- **Basis = `subtotal − discount`** (nilai barang bersih diskon, tanpa ongkir). BUKAN `subtotal` mentah, bukan `total_amount`.
- **Order PERTAMA member** (belum ada PO completed lain) → **join** (upline langsung dapat join%, default 10%). **Berikutnya** → **override** (naik-pohon, differensial per rank).
- **Rate configurable** via `AppSetting` (jangan hardcode): `komisi_persen_grand_distributor`=6, `komisi_persen_distributor`=4, `komisi_persen_reseller_bronze`=2, `komisi_persen_reseller_gold`=2, `komisi_persen_reseller`=2, `komisi_persen_join`=10.
- **Idempoten:** satu PO → satu set komisi (guard per `source_po_id`).
- Akuntansi hold: TIDAK nulis `acc_`/jurnal.
- Pint `--dirty` sebelum commit. Migrasi terakhir 000080 → baru 000081. `PurchaseOrder` pakai SoftDeletes; `PurchaseOrder::STATUS_COMPLETED='completed'`; `User::upline()` belongsTo(upline_id); `User::isPartner()`; `User::PARTNER_ROLES`.

---

## Task 1: Matiin routing Model X (semua order ke HQ)

**Files:**
- Modify: `app/Services/PurchaseOrderService.php` (`createForPartner`)
- Test: `tests/Feature/PurchaseOrderSellerRoutingTest.php` (update), `tests/Feature/InterPartnerFulfillmentTest.php` (perbaiki)

**Interfaces:**
- Produces: `createForPartner` selalu `seller_id = null` (routing ke upline dimatikan). `upline_id` tinggal buat hierarki komisi.

- [ ] **Step 1: Update tes routing** — `tests/Feature/PurchaseOrderSellerRoutingTest.php`

Ubah ekspektasi: PO downline (punya upline) sekarang `seller_id` **null** (bukan upline_id). Contoh:
```php
public function test_po_seller_selalu_null_semua_ke_hq(): void
{
    $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
    $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);   // punya upline
    $p = $this->product();
    $po = app(PurchaseOrderService::class)->createForPartner($dist, [['product_id' => $p->id, 'qty' => 2]], null, null);
    $this->assertNull($po->seller_id); // routing Model X mati — semua ke HQ
}
```
(Hapus/ganti test lama yang assert `seller_id === upline_id`.)

- [ ] **Step 2: Jalankan — GAGAL** (`--filter=PurchaseOrderSellerRoutingTest`): masih set seller_id=upline_id.

- [ ] **Step 3: Matiin routing** — `app/Services/PurchaseOrderService.php`, `createForPartner` (~baris 88)

Ganti `'seller_id' => $buyer->upline_id,` jadi:
```php
'seller_id' => null, // routing Model X dimatikan — semua order ke HQ (upline_id hanya utk hierarki komisi)
```

- [ ] **Step 4: Perbaiki tes yang mengandalkan routing** — `tests/Feature/InterPartnerFulfillmentTest.php`

Tes ini menguji cabang inter-partner `complete()` (yang tetap ADA di kode tapi dorman). Karena `createForPartner` tak lagi set seller_id, PO-nya jadi jalur HQ. Perbaiki: set `seller_id` MANUAL setelah create biar cabang inter-partner tetap teruji dalam isolasi. Mis. setelah `createForPartner(...)`, tambah `$po->seller_id = $grand->id; $po->save();` sebelum `complete()`. (Atau kalau lebih bersih, hapus tes ini karena inter-partner deprecated — TAPI default: pertahankan dengan set manual biar cabang kode tetap kepegang tes.)

- [ ] **Step 5: LULUS + regresi + commit**

`--filter=PurchaseOrderSellerRoutingTest` + `--filter=InterPartnerFulfillmentTest` hijau → `C:\php83\php.exe artisan test` hijau (perhatikan tes lain yang mungkin mengandalkan seller routing — perbaiki bila perlu, JANGAN ubah perilaku non-routing). Pint. Commit:
```bash
git add app/Services/PurchaseOrderService.php tests/Feature/PurchaseOrderSellerRoutingTest.php tests/Feature/InterPartnerFulfillmentTest.php
git commit -m "feat(mlm): matikan routing Model X — semua order ke HQ (upline_id utk hierarki komisi saja)" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Tabel `commissions` + model + `AppSetting::float()`

**Files:**
- Create: `database/migrations/2026_01_01_000081_create_commissions_table.php`, `app/Models/Commission.php`
- Modify: `app/Models/AppSetting.php`
- Test: `tests/Feature/CommissionModelTest.php`

**Interfaces:**
- Produces: model `Commission` (fillable + relasi), `AppSetting::float(key, default): float`.

- [ ] **Step 1: Tulis tes gagal** — `tests/Feature/CommissionModelTest.php`
```php
public function test_commission_tersimpan_dan_appsetting_float(): void
{
    $u = User::create([/* minimal partner user */ ...]);
    $c = \App\Models\Commission::create([
        'user_id' => $u->id, 'source_po_id' => null, 'source_user_id' => $u->id,
        'type' => 'override', 'level' => 1, 'rate' => 6.0, 'base_amount' => 100000,
        'amount' => 6000, 'status' => 'saldo',
    ]);
    $this->assertSame(6000.0, (float) $c->fresh()->amount);
    \App\Models\AppSetting::put('komisi_persen_grand_distributor', '6');
    $this->assertSame(6.0, \App\Models\AppSetting::float('komisi_persen_grand_distributor', 0));
    $this->assertSame(4.5, \App\Models\AppSetting::float('tidak_ada', 4.5)); // default
}
```
(Sesuaikan pembuatan User minimal ke pola tes lain, mis. `InterPartnerFulfillmentTest::user()`.)

- [ ] **Step 2: GAGAL** (`--filter=CommissionModelTest`).

- [ ] **Step 3: Migrasi 000081**
```php
Schema::create('commissions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();          // penerima (upline)
    $table->foreignId('source_po_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
    $table->foreignId('source_user_id')->nullable()->constrained('users')->nullOnDelete(); // downline pembeli
    $table->string('type', 20);           // override | join
    $table->unsignedSmallInteger('level')->default(1);
    $table->decimal('rate', 5, 2);        // persen saat hitung
    $table->decimal('base_amount', 14, 2);
    $table->decimal('amount', 14, 2);
    $table->string('status', 20)->default('saldo'); // saldo | ditarik
    $table->timestamps();
    $table->index(['user_id', 'status']);
    $table->index('source_po_id');
});
```

- [ ] **Step 4: Model `Commission`** — fillable semua kolom di atas; cast `rate/base_amount/amount` decimal:2; relasi `penerima() belongsTo(User,'user_id')`, `sourcePo() belongsTo(PurchaseOrder,'source_po_id')`, `downline() belongsTo(User,'source_user_id')`.

- [ ] **Step 5: `AppSetting::float()`** — `app/Models/AppSetting.php`, tambah (pola sama `date()`):
```php
public static function float(string $key, float $default = 0.0): float
{
    $v = self::get($key);
    return $v === null || $v === '' ? $default : (float) $v;
}
```

- [ ] **Step 6: LULUS + regresi + commit**
```bash
C:\php83\php.exe artisan test --filter=CommissionModelTest
C:\php83\php.exe artisan test
C:\php83\php.exe vendor/bin/pint --dirty
git add database/migrations/2026_01_01_000081_create_commissions_table.php app/Models/Commission.php app/Models/AppSetting.php tests/Feature/CommissionModelTest.php
git commit -m "feat(mlm): tabel commissions + model + AppSetting::float" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: `CommissionService` + hook di `complete()` + saldo

**Files:**
- Create: `app/Services/CommissionService.php`
- Modify: `app/Services/PurchaseOrderService.php` (hook di `complete()` jalur normal)
- Test: `tests/Feature/CommissionEngineTest.php`

**Interfaces:**
- Consumes: `Commission` (Task 2), `AppSetting::float`, `User::upline/isPartner`, `PurchaseOrder`.
- Produces: `CommissionService::recordForCompletedPo(PurchaseOrder): void` + `balance(User): float`.

- [ ] **Step 1: Tulis tes gagal** — `tests/Feature/CommissionEngineTest.php`

Helper user/product + bikin PO via `PurchaseOrderService::createForPartner` + `complete()` (semua seller null sekarang). Skenario:
```php
public function test_override_differensial_naik_pohon_saat_repeat(): void
{
    $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
    $dist  = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
    $reseller = $this->user(User::ROLE_RESELLER_BRONZE, $dist->id);
    $p = $this->product();
    // Order PERTAMA reseller = join (biar order berikutnya = override)
    $this->completedPo($reseller, $p, 1);
    // Order KEDUA reseller (repeat) senilai barang 100.000
    $po = $this->completedPoValue($reseller, $p, /* subtotal */ 100000);

    // Override: Dist 4% = 4000, Grand 6% = 6000 (dari base 100.000)
    $this->assertEqualsWithDelta(4000, $this->commissionFor($dist, $po), 0.01);
    $this->assertEqualsWithDelta(6000, $this->commissionFor($grand, $po), 0.01);
    $this->assertSame(0.0, $this->commissionFor($reseller, $po)); // pembeli tak dapat
}

public function test_order_pertama_join_upline_langsung_10persen(): void
{
    $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
    $dist  = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
    $po = $this->completedPoValue($dist, $this->product(), 200000); // order pertama dist
    // Join: upline langsung (grand) 10% = 20.000; TIDAK ada override lain di order pertama
    $this->assertEqualsWithDelta(20000, $this->commissionFor($grand, $po), 0.01);
}

public function test_rate_dari_appsetting(): void
{
    \App\Models\AppSetting::put('komisi_persen_grand_distributor', '10');
    // ... repeat order reseller, base 100.000 → grand harusnya 10.000
}

public function test_pembeli_tanpa_upline_nol_komisi(): void
{
    $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR); // upline null
    $po = $this->completedPoValue($grand, $this->product(), 100000);
    $this->assertSame(0, \App\Models\Commission::where('source_po_id', $po->id)->count());
}

public function test_idempoten_tidak_dobel(): void { /* panggil recordForCompletedPo 2x → jumlah baris tetap */ }

public function test_saldo_jumlah_komisi(): void { /* balance(grand) = SUM amount */ }
```
Catatan implementer: `completedPoValue` = bikin PO dengan subtotal tertentu (atur qty×harga). `commissionFor($u,$po)` = SUM `commissions.amount` where user_id=$u & source_po_id=$po.

- [ ] **Step 2: GAGAL** (`--filter=CommissionEngineTest`).

- [ ] **Step 3: `CommissionService`** — `app/Services/CommissionService.php`
```php
class CommissionService
{
    private const DEFAULT_RATES = [
        User::ROLE_GRAND_DISTRIBUTOR => 6.0,
        User::ROLE_DISTRIBUTOR => 4.0,
        User::ROLE_RESELLER_BRONZE => 2.0,
        User::ROLE_RESELLER_GOLD => 2.0,
        User::ROLE_RESELLER => 2.0,
    ];

    public function recordForCompletedPo(PurchaseOrder $po): void
    {
        if ($po->seller_id !== null) return;                         // hanya PO HQ langsung
        if (Commission::where('source_po_id', $po->id)->exists()) return; // idempoten
        $buyer = $po->user;
        if (! $buyer || ! $buyer->upline_id) return;                 // tak ada upline → nol
        $base = (float) $po->subtotal - (float) $po->discount;       // nilai barang bersih
        if ($base <= 0) return;

        $isFirst = ! PurchaseOrder::where('user_id', $po->user_id)
            ->where('status', PurchaseOrder::STATUS_COMPLETED)
            ->where('id', '!=', $po->id)->exists();

        if ($isFirst) {
            $upline = $buyer->upline;
            if ($upline && $upline->isPartner()) {
                $rate = AppSetting::float('komisi_persen_join', 10.0);
                $this->write($upline, $po, $buyer, 'join', 1, $rate, $base);
            }
            return;
        }

        $node = $buyer->upline; $level = 1;
        while ($node && $level <= 10) {
            if ($node->isPartner()) {
                $rate = $this->overrideRate($node->role);
                if ($rate > 0) $this->write($node, $po, $buyer, 'override', $level, $rate, $base);
            }
            $node = $node->upline; $level++;
        }
    }

    public function balance(User $mitra): float
    {
        return (float) Commission::where('user_id', $mitra->id)->where('status', 'saldo')->sum('amount');
    }

    private function overrideRate(string $role): float
    {
        $default = self::DEFAULT_RATES[$role] ?? 0.0;
        return AppSetting::float('komisi_persen_'.$role, $default);
    }

    private function write(User $penerima, PurchaseOrder $po, User $downline, string $type, int $level, float $rate, float $base): void
    {
        Commission::create([
            'user_id' => $penerima->id, 'source_po_id' => $po->id, 'source_user_id' => $downline->id,
            'type' => $type, 'level' => $level, 'rate' => $rate, 'base_amount' => $base,
            'amount' => round($base * $rate / 100, 2), 'status' => 'saldo',
        ]);
    }
}
```

- [ ] **Step 4: Hook di `complete()`** — `app/Services/PurchaseOrderService.php`

Di jalur **normal** (setelah `AuditService::log(...)` ~baris 300, sebelum `return $po;` ~baris 302), tambah:
```php
app(CommissionService::class)->recordForCompletedPo($po);
```
JANGAN taruh di jalur backdated (~baris 235). Guard `seller_id===null` sudah di dalam service (aman walau dipanggil dari mana). `use App\Services\CommissionService;` (atau `app(...)`).

- [ ] **Step 5: LULUS + regresi + commit**
```bash
C:\php83\php.exe artisan test --filter=CommissionEngineTest
C:\php83\php.exe artisan test
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Services/CommissionService.php app/Services/PurchaseOrderService.php tests/Feature/CommissionEngineTest.php
git commit -m "feat(mlm): CommissionService — override differensial + join, hook di complete() + saldo" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Penyelesaian
Setelah 3 task + suite hijau: review whole-branch (opus) → `superpowers:finishing-a-development-branch`. Deploy prod = `git pull origin main && migrate --force && optimize:clear` (jalankan migrasi 000081). Dormant-safe: jaringan kosong → pembeli tak punya upline → nol komisi.

**Fase berikut (plan terpisah):** Fase 2 Withdraw (ajukan→approve→cair) · Fase 3 Laporan HQ + layar mitra (saldo) + UI atur rate.

## Self-Review
- **Cakupan:** routing off→Task1 · tabel+model+float→Task2 · service+hook+saldo→Task3 · tes tersebar. ✅
- **Placeholder:** kode konkret; basis `subtotal-discount`, hook baris ~301 (jalur normal), rate AppSetting default per role.
- **Konsistensi:** guard `seller_id===null` + idempoten di service; rate configurable; join (order pertama, upline langsung) vs override (repeat, naik-pohon differensial) dipisah tegas.
- **Risiko diketahui:** (1) Task1 bisa bikin tes lain yang mengandalkan seller routing merah — implementer perbaiki (jangan ubah perilaku). (2) `complete()` reload PO eager-load `items` saja; akses `$po->user` = lazy-load (aman utk 1 PO). (3) order pertama = join SAJA (chain atas tak dapat override di order pertama) — keputusan sadar sesuai "join vs selanjutnya".
