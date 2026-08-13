# MLM Hirarki Mitra — Tahap 3c: "Model X" Rantai Pasok (Beli-dari-Upline) — Design

**Tanggal:** 2026-08-11
**Status:** Disetujui (siap masuk rencana implementasi)
**Bagian dari:** Tahap 3 MLM. Ini sub-fase **paling berat** (menyentuh core PO + stok).
**Lanjutan dari:** Tahap 1 (pondasi hirarki, live), Tahap 2 (Jaringan Saya, live), Tahap 3a (harga Grand per produk, live).

---

## 1. Konteks & Tujuan

Sekarang **semua** PO mitra masuk ke HQ (`PurchaseOrderService::createForPartner` → `complete()` memotong stok HQ, menambah stok mitra pembeli). Upline tidak mendapat apa-apa dari pembelian downline-nya.

**Tujuan 3c (Model X):** downline membeli **dari upline-nya** (bukan HQ), upline **memiliki + mengirim** stok fisik ke downline. Upline untung dari **selisih harga** (margin): pembeli bayar harga tier pembeli, cost upline = harga tier upline sendiri. Ini "margin komisi" yang jadi duit utama MLM.

Contoh (Sabun): HQ→Grand 22rb, Grand→Distributor 24rb (Grand +2rb), Distributor→Reseller 29rb (Distributor +5rb), Reseller→customer 39rb.

**Bukan tujuan 3c** (ditunda):
- Override bonus komisi (HQ bayar % naik-pohon di atas margin) — sub-fase berikutnya.
- Onboarding / paket join — sub-fase terpisah.
- Buku akuntansi per-mitra otomatis — **HOLD** (lihat §7).

---

## 2. Keputusan Inti (dikunci dari brainstorming user Freddie)

| Aspek | Keputusan |
|---|---|
| Model fulfillment | **Model X** — upline PUNYA + KIRIM stok fisik ke downline (transfer antar-mitra). Tiap tier stockist termasuk reseller (ide "reseller no-stock" DIBATALKAN). |
| Routing PO | Downline pesan ke `upline_id`-nya; **fallback HQ** kalau upline null. |
| Workflow | **Upline yang proses** PO downline-nya (pakai ulang alur status PO existing; processor = seller). HQ tetap proses PO seller=HQ. |
| Pembayaran | Alur bayar existing dipakai ulang; **seller (upline) yang verifikasi** (bukan HQ). |
| Akuntansi | **HOLD** — inter-partner PO TIDAK auto-journal ke HQ; rekons manual via Excel. Tidak bangun buku per-mitra. |
| Harga | Harga tier **pembeli** via `Product::priceForRole($buyer->role)` (sudah ada dari 3a). |
| Stok kurang | Upline stok tak cukup → PO **tak bisa diselesaikan** sampai upline restock (guard atomik, no stok minus). |

---

## 3. Dormant-Safe (kunci de-risk)

Jaringan prod **sekarang kosong** (belum ada Grand/cabang, semua `upline_id` null). Dengan routing "fallback HQ kalau upline null", maka **semua PO tetap ke HQ = perilaku existing.**

→ **Deploy Model X = NOL perubahan operasional** sampai jaringan diisi. Fitur "nyala" per-mitra begitu `upline_id` di-set → **rollout bertahap** (set 1 distributor punya Grand, tes alur inter-partner di situ, baru lebar; kalau aneh, lepas upline → balik HQ).

**Wajib dijaga (tidak boleh rusak):** alur PO↔HQ existing (Grand + mitra tanpa upline tetap beli ke HQ), integritas stok atomik, buku HQ bersih (no auto-journal inter-partner).

---

## 4. Arsitektur & Komponen

Ikut pola existing: controller tipis + `PurchaseOrderService`/`InventoryService` + Blade. Zero-dependency.

### 4.1 Data — `purchase_orders.seller_id`
- Migrasi **000080**: `seller_id` (foreignId nullable constrained('users') nullOnDelete after `user_id`). `null = HQ` (default, backward-compatible — semua PO lama tetap seller HQ).
- Tak ada backfill: PO lama `seller_id` null = HQ (benar).

### 4.2 Penentuan seller saat PO dibuat
- `PurchaseOrderService::createForPartner(User $buyer, ...)`: set `seller_id = $buyer->upline_id` (null → HQ). (Upline pembeli = penjualnya; kalau tak ada upline → HQ.)
- Harga baris = `$product->priceForRole($buyer->role)` (sudah begitu sejak 3a). Override tetap didukung.

### 4.3 Fulfillment — `PurchaseOrderService::complete()` bercabang (JANTUNG)
Saat ini `complete()`: potong `hq_stock` (`adjustHqStock -qty`) + tambah stok pembeli (`adjustPartnerStock +qty`), + guard stok HQ, + cek `isBeforeStockCutoff` (opname).

Perubahan — bercabang di `seller_id`:
- **`seller_id === null` (HQ):** persis seperti sekarang (potong HQ, tambah pembeli, guard HQ, cek opname). **Nol perubahan.**
- **`seller_id` = mitra:** potong stok **SELLER** (`adjustPartnerStock(seller_id, -qty)`) + tambah stok **pembeli** (`adjustPartnerStock(buyer, +qty)`). Dua sisi dalam satu transaksi (atomik). Guard: `adjustPartnerStock` sudah melempar bila saldo jadi negatif → stok upline tak cukup → PO gagal diselesaikan (§4.6). Cek `isBeforeStockCutoff` opname **tidak berlaku** untuk inter-partner (opname titik-nol = stok HQ; transfer antar-mitra bukan HQ) — lewati.

### 4.4 Workflow — upline yang proses (Q1)
- Mesin status PO existing (`draft→pending→approved→processing→shipped→completed`, `updateStatus`/`advanceStatus`/`complete`) **dipakai ulang** — tak diubah logikanya.
- Yang berubah: **siapa yang berwenang** menjalankan transisi untuk sebuah PO = **seller-nya**. PO `seller=HQ` → HQ staff (existing). PO `seller=mitra` → mitra itu (upline).
- Layar baru mitra **"Pesanan Downline"** = daftar PO yang `seller_id = auth user`, dengan aksi status (approve/kirim/selesai) — pakai ulang view/endpoint PO existing, di-scope `seller_id`. HQ tetap lihat semua di halaman PO existing.

### 4.5 Pembayaran — seller verifikasi (Q2)
- Alur bayar existing (`payment_status` unpaid→awaiting→paid, upload bukti, `verifyPayment`) dipakai ulang.
- Yang berubah: untuk PO `seller=mitra`, **seller (upline) yang verifikasi** bukti bayar (bukan HQ). Seller=HQ → HQ verifikasi (existing).
- **AKUNTANSI-HOLD:** implementasi WAJIB memastikan `complete()` untuk `seller≠null` **TIDAK memicu jurnal HQ** (`acc_journals`). Saat implementasi: telusuri pemicu jurnal di alur PO complete (kalau ada) → guard `seller_id === null`. Rekons inter-partner manual via Excel.

### 4.6 Stok upline kurang (Q3)
- Kalau saat `complete()` stok seller (upline) < qty item → `adjustPartnerStock` melempar → transaksi rollback → PO **tetap di status sebelum-completed** dengan pesan "Stok [upline] tidak mencukupi untuk [produk]." Upline harus restock dulu (via PO-nya sendiri ke upline-nya) baru bisa menyelesaikan. Aman & atomik.

### 4.7 Izin & gerakan stok (Q5, Q6)
- **Izin**: mitra boleh **melihat + memproses** PO yang `seller_id`-nya dia. Gate baru (mis. permission `manage_downline_orders`) atau reuse yang ada di-scope seller. Nav **"Pesanan Downline"** muncul untuk mitra yang punya downline (`$u->isPartner() && $u->downlines()->exists()`, pola sama Tahap 2).
- **Gerakan stok**: transfer antar-mitra pakai ulang `adjustPartnerStock` (seller OUT, buyer IN). Beri `movement_type`/`reference` yang jelas (mis. reference `purchase_order`, tipe OUT untuk seller + `PO_FULFILLMENT` untuk buyer). **Laporan Stok HQ tak terpengaruh** (query `user_id null` = stok HQ saja; transfer antar-mitra `user_id` = mitra).

---

## 5. Isolasi & Fase Build

Meski satu spec, build dipecah bertahap (di plan) supaya tiap langkah teruji & aman:
1. **Engine** (dormant, paling aman): kolom `seller_id` + penentuan seller saat create + `complete()` bercabang + guard stok + skip-journal-inter-partner. **Ini yang paling penting** — begitu ada, margin jalan (walau prosesnya masih via HQ/manual sementara).
2. **Workflow**: layar "Pesanan Downline" + izin + nav (upline proses PO-nya).
3. **Pembayaran**: seller verifikasi.

Karena dormant-safe, tiap fase bisa deploy tanpa ganggu operasional (jaringan kosong = semua HQ).

---

## 6. Dampak & Keamanan

- **Additif & dormant**: kolom `seller_id` nullable default HQ; jaringan kosong → nol perubahan.
- **Alur HQ existing utuh**: cabang `seller_id === null` = kode sekarang, tak disentuh logikanya.
- **Atomik**: transfer antar-mitra dua sisi dalam satu DB transaction; stok tak pernah minus (guard `adjustPartnerStock`).
- **Buku HQ bersih**: inter-partner PO tidak auto-journal ke HQ.
- Zero-dependency. Reuse maksimal mesin PO/status/bayar existing.

---

## 7. Akuntansi — HOLD (eksplisit)

Inter-partner PO = jualan distributor, **bukan** jualan HQ. Meng-auto-journal-kannya ke buku HQ akan **mengotori laporan keuangan HQ**. Keputusan: **TIDAK auto-journal untuk `seller≠HQ`**; rekonsiliasi manual via **upload Excel** (fitur existing). Buku per-mitra otomatis = **tidak dibangun** di 3c (bisa jadi fase jauh nanti bila perlu).

---

## 8. Rencana Pengujian

Runner: `C:\php83\php.exe artisan test`. Pint `--dirty` sebelum commit.

- **Regresi HQ (WAJIB hijau):** PO mitra tanpa upline (seller null) → potong stok HQ + tambah stok pembeli, persis existing. Semua tes PO existing tetap lulus.
- **Inter-partner fulfillment:** pembeli punya upline → `seller_id` = upline; complete → stok upline **turun** qty, stok pembeli **naik** qty; saldo lain tak berubah.
- **Stok upline kurang:** upline stok < qty → complete gagal (exception), PO tak jadi completed, stok tak berubah.
- **Harga:** harga baris = harga tier pembeli (mis. reseller beli dari distributor → harga reseller).
- **Dormant:** upline null → seller null → jalur HQ (regresi).
- **No journal inter-partner:** complete seller=mitra → tak ada `acc_journals` baru untuk HQ (assert count jurnal tak nambah). *(kalau alur PO existing memang journal.)*
- **Workflow/izin:** mitra bisa lihat + transisi status PO yang seller-nya dia; TIDAK bisa PO mitra lain; non-seller tak bisa proses.
- **Pembayaran:** seller (upline) bisa verifikasi bayar PO downline-nya.

---

## 9. File yang Disentuh (ringkas)

**Buat:** migrasi `2026_01_01_000080_add_seller_id_to_purchase_orders.php`; view `resources/views/purchase_orders/pesanan_downline...` (atau reuse index di-scope); tests.

**Ubah:** `app/Models/PurchaseOrder.php` (fillable + relasi `seller()`); `app/Services/PurchaseOrderService.php` (`createForPartner` set seller, `complete()` bercabang + skip journal inter-partner); `app/Http/Controllers/PurchaseOrderController.php` (scope seller + otorisasi proses); `routes/web.php` (+ pesanan-downline); `resources/views/layouts/app.blade.php` (nav); Permissions default (izin proses downline order).

**Tidak disentuh:** harga (priceForRole 3a), Laporan Stok HQ, akuntansi (hold), alur PO↔HQ logic (cuma ditambah cabang).

---

## 10. Deploy

ADA migrasi 000080 → `git pull origin main && /opt/alt/php83/usr/bin/php artisan migrate --force && /opt/alt/php83/usr/bin/php artisan optimize:clear`. Deploy **aman/dorman** (jaringan kosong = semua PO ke HQ = existing). Fitur aktif setelah upline mitra diisi.

---

## 11. Jalur berikutnya
Setelah 3c: **onboarding / paket join** (isi jaringan) · **override bonus komisi** (% HQ naik-pohon di atas margin).
