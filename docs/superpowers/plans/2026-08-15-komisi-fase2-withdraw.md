# Komisi Fase 2 (Withdraw) — Rencana Implementasi

> **Untuk pekerja agentik:** SUB-SKILL WAJIB: `superpowers:subagent-driven-development`. Langkah pakai checkbox `- [ ]`.

**Goal:** Mitra isi rekening di profil, lihat saldo komisi & ajukan penarikan; HQ setujui/tolak/cair. Tanpa transfer otomatis (akuntansi-hold).

**Architecture:** Kolom rekening di `users` + halaman mitra self-edit. Tabel `withdrawals` + model. `availableBalance = balance() − Σ(withdrawals belum-ditolak)`. Mitra ajukan (snapshot rekening) → HQ proses (permission `process_withdrawal`). Zero-dependency. Spec: `docs/superpowers/specs/2026-08-15-komisi-override-saldo-withdraw-design.md` (bagian Fase 2).

**Tech Stack:** Laravel 13, PHP 8.3, Blade+Tailwind, Eloquent. Runner `C:\php83\php.exe artisan test`.

## Global Constraints
- **Ledger `commissions` APPEND-ONLY** — JANGAN flip `Commission.status` ke 'ditarik' (komentar migrasi 000081 menyesatkan). Saldo susut lewat lapisan `withdrawals` saja, biar tak dobel-potong.
- **Saldo tersedia = `CommissionService::balance()` − Σ(`withdrawals.amount` where status != 'ditolak')**. Pengajuan (diajukan) langsung "mengunci" saldo; ditolak → lepas.
- **Rekening di-SNAPSHOT** ke baris `withdrawals` saat ajukan (bukan live-join ke `users.bank`) — riwayat lama tak berubah kalau mitra ganti rekening.
- **Tanpa auto-payout:** status 'cair' cuma menandai; transfer manual/offline.
- **Izin:** aksi HQ digate permission baru `process_withdrawal` (default `[ROLE_ADMIN]`). Mitra guard kepemilikan (`$w->user_id === $user->id`).
- Migrasi: users-bank = **000082**, withdrawals = **000083** (terakhir 000081). Pint `--dirty` sebelum commit. Suite existing (762) tetap hijau.
- Konvensi rekening ikut `KolDeal`: kolom `bank`, `no_rekening`, `atas_nama`.

---

## Task 1: Rekening di profil mitra

**Files:**
- Create: `database/migrations/2026_01_01_000082_add_bank_to_users.php`
- Modify: `app/Models/User.php` (fillable), `app/Http/Controllers/AuthController.php`, `routes/web.php`, `resources/views/layouts/app.blade.php`
- Create: `resources/views/auth/bank-account.blade.php`
- Test: `tests/Feature/BankAccountTest.php`

**Interfaces:** Produces route `account.rekening` (GET+POST); kolom `users.bank/no_rekening/atas_nama`.

- [ ] **Step 1: Tulis tes gagal** — `tests/Feature/BankAccountTest.php`
```php
public function test_mitra_simpan_rekening(): void
{
    $u = $this->partner();
    $this->actingAs($u)->post(route('account.rekening'), [
        'bank' => 'BCA', 'no_rekening' => '1234567890', 'atas_nama' => 'Budi',
    ])->assertRedirect();
    $u->refresh();
    $this->assertSame('BCA', $u->bank);
    $this->assertSame('1234567890', $u->no_rekening);
}
```
(`partner()` = bikin user role reseller/distributor aktif; tiru pola tes lain, mis. `BackdatedSaleTest::partner()`.)

- [ ] **Step 2: GAGAL** (`--filter=BankAccountTest`): route belum ada.

- [ ] **Step 3: Migrasi 000082** (pola `000074_add_hierarchy_to_users.php`)
```php
public function up(): void {
    Schema::table('users', function (Blueprint $table) {
        $table->string('bank')->nullable()->after('region');
        $table->string('no_rekening')->nullable()->after('bank');
        $table->string('atas_nama')->nullable()->after('no_rekening');
    });
}
public function down(): void {
    Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['bank','no_rekening','atas_nama']));
}
```

- [ ] **Step 4: Fillable** — `app/Models/User.php` `$fillable` (~:52-57): tambah `'bank', 'no_rekening', 'atas_nama'`.

- [ ] **Step 5: Controller** — `app/Http/Controllers/AuthController.php` tambah:
```php
public function showBankAccount(Request $request) {
    return view('auth.bank-account', ['user' => $request->user()]);
}
public function updateBankAccount(Request $request): RedirectResponse {
    $data = $request->validate([
        'bank' => ['nullable','string','max:50'],
        'no_rekening' => ['nullable','string','max:40'],
        'atas_nama' => ['nullable','string','max:100'],
    ]);
    $request->user()->update($data);
    return back()->with('status', 'Rekening berhasil disimpan.');
}
```

- [ ] **Step 6: Route** — `routes/web.php` dekat `account.password` (~:83-84), dalam grup `['auth','role']`:
```php
Route::get('/account/rekening', [AuthController::class, 'showBankAccount'])->name('account.rekening');
Route::post('/account/rekening', [AuthController::class, 'updateBankAccount']);
```

- [ ] **Step 7: View** — `resources/views/auth/bank-account.blade.php` (pola `auth/change-password.blade.php`): form `@csrf` POST `route('account.rekening')`, 3 input (bank, no_rekening, atas_nama) prefilled `old(...) ?? $user->...`, tampilkan `session('status')`.

- [ ] **Step 8: Nav** — `resources/views/layouts/app.blade.php` dekat link "Ubah Password" (bawah, ~:214-218): tambah link ke `account.rekening` label "Rekening" (buat semua user authed / atau `@if($u->isPartner())`).

- [ ] **Step 9: LULUS + regresi + commit**
```bash
C:\php83\php.exe artisan test --filter=BankAccountTest && C:\php83\php.exe artisan test
C:\php83\php.exe vendor/bin/pint --dirty
git add -A && git commit -m "feat(mlm): rekening bank di profil mitra (isi sendiri via /account/rekening)" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Tabel `withdrawals` + saldo tersedia + mitra ajukan

**Files:**
- Create: `database/migrations/2026_01_01_000083_create_withdrawals_table.php`, `app/Models/Withdrawal.php`, `app/Http/Controllers/CommissionController.php`, `resources/views/commissions/index.blade.php`
- Modify: `app/Services/CommissionService.php`, `routes/web.php`, `resources/views/layouts/app.blade.php`
- Test: `tests/Feature/WithdrawalRequestTest.php`

**Interfaces:** Consumes `CommissionService::balance` (Fase 1) + rekening (Task 1). Produces `Withdrawal` model, `availableBalance()`, route `commissions.*` (mitra).

- [ ] **Step 1: Tulis tes gagal** — `tests/Feature/WithdrawalRequestTest.php`

Helper: bikin mitra + isi rekening + suntik komisi (`Commission::create([... 'user_id'=>$mitra->id, 'amount'=>X, 'status'=>'saldo', 'type'=>'override','level'=>1,'rate'=>6,'base_amount'=>X ...])`). Tes:
```php
public function test_mitra_ajukan_penarikan_kurangi_saldo_tersedia(): void
{
    $m = $this->partnerWithBank();
    $this->giveCommission($m, 500000);
    $svc = app(\App\Services\CommissionService::class);
    $this->assertEqualsWithDelta(500000, $svc->availableBalance($m), 0.01);
    $this->actingAs($m)->post(route('commissions.withdraw'), ['amount' => 200000])->assertRedirect();
    $this->assertEqualsWithDelta(300000, $svc->availableBalance($m->fresh()), 0.01); // dikunci
    $w = \App\Models\Withdrawal::where('user_id',$m->id)->first();
    $this->assertSame('diajukan', $w->status);
    $this->assertSame('BCA', $w->bank); // rekening ke-snapshot
}
public function test_ajukan_lebih_dari_saldo_ditolak(): void { /* amount 999999 > 500000 → assertSessionHasErrors / back error, tak ada withdrawal */ }
public function test_ajukan_kurang_dari_minimum_ditolak(): void { /* amount 50000 < 100000 */ }
public function test_ajukan_tanpa_rekening_ditolak(): void { /* mitra tanpa rekening → error */ }
```

- [ ] **Step 2: GAGAL** (`--filter=WithdrawalRequestTest`).

- [ ] **Step 3: Migrasi 000083 `withdrawals`** (pola `000081_create_commissions_table.php`)
```php
Schema::create('withdrawals', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->decimal('amount', 14, 2);
    $table->string('bank')->nullable();          // snapshot saat ajukan
    $table->string('no_rekening')->nullable();
    $table->string('atas_nama')->nullable();
    $table->string('status', 20)->default('diajukan'); // diajukan|disetujui|ditolak|cair
    $table->text('note')->nullable();            // catatan HQ
    $table->timestamp('requested_at')->nullable();
    $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('processed_at')->nullable();
    $table->timestamps();
    $table->index(['user_id', 'status']);
});
```

- [ ] **Step 4: Model `Withdrawal`** — fillable semua kolom; cast `amount` decimal:2, `requested_at`/`processed_at` datetime; relasi `mitra() belongsTo(User,'user_id')`, `processor() belongsTo(User,'processed_by')`.

- [ ] **Step 5: `availableBalance`** — `app/Services/CommissionService.php` tambah:
```php
public function availableBalance(User $mitra): float
{
    $ditarik = (float) \App\Models\Withdrawal::where('user_id', $mitra->id)
        ->where('status', '!=', 'ditolak')->sum('amount');
    return $this->balance($mitra) - $ditarik;
}
```

- [ ] **Step 6: `CommissionController` (mitra)** — `abort_unless($user->isPartner(), 403)` di tiap method.
  - `index`: tampilkan `availableBalance`, riwayat komisi (Commission where user_id, latest, paginate/limit), riwayat withdrawal (Withdrawal where user_id).
  - `withdraw` (POST): validate `amount` `required|numeric|min:100000`; `abort` kalau `amount > availableBalance` (error flash) atau user belum isi rekening (`!$user->no_rekening` → error "Isi rekening dulu di menu Rekening."). Buat `Withdrawal::create([...'user_id'=>$user->id,'amount'=>$amount,'bank'=>$user->bank,'no_rekening'=>$user->no_rekening,'atas_nama'=>$user->atas_nama,'status'=>'diajukan','requested_at'=>now()])`.
  - `cancel` (POST, opsional): guard owner + status 'diajukan' → hapus/`ditolak` (lepas kunci). (Boleh sederhana: set 'ditolak' + note 'dibatalkan mitra'.)

- [ ] **Step 7: Routes** — `routes/web.php` grup `['auth','role']`:
```php
Route::get('/komisi-saya', [CommissionController::class, 'index'])->name('commissions.index');
Route::post('/komisi-saya/tarik', [CommissionController::class, 'withdraw'])->name('commissions.withdraw');
Route::post('/komisi-saya/tarik/{withdrawal}/batal', [CommissionController::class, 'cancel'])->name('commissions.withdraw-cancel');
```

- [ ] **Step 8: View + Nav** — `resources/views/commissions/index.blade.php` (saldo besar + form ajukan + tabel riwayat komisi & withdrawal). Nav `layouts/app.blade.php`: `@if($u->isPartner()) navItem('commissions.index','Saldo Komisi','commissions.*') @endif`. JANGAN `@json([...])` literal.

- [ ] **Step 9: LULUS + regresi + commit** (`--filter=WithdrawalRequestTest` + suite hijau; Pint; commit `feat(mlm): withdrawals + saldo tersedia + mitra ajukan penarikan`).

---

## Task 3: HQ proses penarikan

**Files:**
- Modify: `app/Support/Permissions.php`, `routes/web.php`, `resources/views/layouts/app.blade.php`
- Create: `app/Http/Controllers/WithdrawalController.php`, `resources/views/withdrawals/index.blade.php`
- Test: `tests/Feature/WithdrawalProcessTest.php`

**Interfaces:** Consumes `Withdrawal` (Task 2). Produces permission `process_withdrawal`, route `withdrawals.*` (HQ).

- [ ] **Step 1: Tulis tes gagal** — `tests/Feature/WithdrawalProcessTest.php`
```php
public function test_hq_setujui_lalu_cair(): void
{
    $admin = $this->admin(); // super_admin/admin
    $m = $this->partnerWithBank(); $this->giveCommission($m, 500000);
    $this->actingAs($m)->post(route('commissions.withdraw'), ['amount'=>200000]);
    $w = \App\Models\Withdrawal::first();
    $this->actingAs($admin)->post(route('withdrawals.process', $w), ['status'=>'disetujui'])->assertRedirect();
    $this->assertSame('disetujui', $w->fresh()->status);
    $this->actingAs($admin)->post(route('withdrawals.process', $w), ['status'=>'cair']);
    $this->assertSame('cair', $w->fresh()->status);
}
public function test_tolak_lepas_kunci_saldo(): void { /* tolak → available mitra balik ke 500000 */ }
public function test_mitra_tak_bisa_proses(): void { /* mitra (tanpa process_withdrawal) POST withdrawals.process → 403 */ }
```

- [ ] **Step 2: GAGAL**.

- [ ] **Step 3: Permission** — `app/Support/Permissions.php`: DEFINITIONS `'process_withdrawal' => 'Proses Penarikan Komisi'`; DEFAULTS `'process_withdrawal' => [User::ROLE_ADMIN]`.

- [ ] **Step 4: `WithdrawalController` (HQ)** (pola `KolDealController`):
  - `index`: daftar withdrawals (filter status), paginate, eager `mitra`.
  - `process` (POST `{withdrawal}`): validate `status` in [disetujui, ditolak, cair]; set status + `processed_by`=user->id + `processed_at`=now + `note` opsional; save + audit (`AuditService::log('process_withdrawal',...)`). (Transisi valid: diajukan→disetujui/ditolak; disetujui→cair/ditolak. Boleh longgar tapi cegah proses yang sudah 'cair'.)

- [ ] **Step 5: Route** — grup `Route::middleware('permission:process_withdrawal')`:
```php
Route::get('/penarikan', [WithdrawalController::class, 'index'])->name('withdrawals.index');
Route::post('/penarikan/{withdrawal}/proses', [WithdrawalController::class, 'process'])->name('withdrawals.process');
```

- [ ] **Step 6: View + Nav** — `resources/views/withdrawals/index.blade.php` (tabel: mitra, jumlah, rekening, status, tombol Setujui/Tolak/Cair per baris — pola `kol_deals/index.blade.php`). Nav: `@if($u->canDo('process_withdrawal')) navItem('withdrawals.index','Penarikan','withdrawals.*') @endif`.

- [ ] **Step 7: LULUS + regresi + commit** (`--filter=WithdrawalProcessTest` + suite; Pint; commit `feat(mlm): HQ proses penarikan komisi (izin process_withdrawal)`).

---

## Penyelesaian
Setelah 3 task + suite hijau: review whole-branch (opus) → `superpowers:finishing-a-development-branch`. Deploy = `git pull && migrate --force (000082+000083) && optimize:clear`. Dormant-safe: nol komisi → nol saldo → nol withdraw.

**Fase 3 (plan terpisah):** laporan komisi HQ (per mitra) + riwayat detail mitra + UI atur rate di Pengaturan.

## Self-Review
- **Cakupan:** rekening profil→Task1 · withdrawals+ajukan+saldo-tersedia→Task2 · HQ proses+izin→Task3.
- **Konsistensi:** `availableBalance` = balance − non-rejected; commissions append-only (TIDAK diflip); rekening snapshot; permission process_withdrawal.
- **Risiko diketahui:** (1) konkurensi 2 pengajuan bareng bisa lewat cek saldo (volume mitra rendah — terima; bisa lock nanti). (2) transisi status withdraw longgar — cegah proses yg sudah 'cair'. (3) mitra tanpa rekening → tolak ajukan dgn pesan jelas.
