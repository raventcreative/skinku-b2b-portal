# Edit PO (selagi pending & belum bayar) — Design

**Tanggal:** 2026-08-19
**Status:** Disetujui user (Freddie) — siap ke plan.

## Goal

Mitra/admin bisa mengedit isi Purchase Order (tambah/kurangi produk & ubah qty) **selagi PO masih `pending` dan belum ada bukti bayar**, tanpa harus batalin + buat ulang.

## Latar

Sekarang PO tak bisa diedit sama sekali — begitu dibuat, item terkunci; satu-satunya jalan koreksi adalah **Batalkan/Hapus + buat ulang**. Karena stok & komisi baru bergerak di `PurchaseOrderService::complete()` (saat status → `completed`), PO `pending` **belum menyentuh stok/komisi** → mengedit itemnya aman, tak ada efek samping yang perlu dibalik.

## Keputusan (dikunci user)

1. **Cakupan:** item & qty saja. Alamat kirim & catatan **tidak** diubah lewat form ini. Diskon/ongkir tetap ditangani terpisah (via WA).
2. **Siapa boleh:** mitra **pemilik** PO (punya `create_po` + pegang stok) **atau** admin/staff dengan izin `update_po_status`.
3. **Kapan boleh:** status `pending` (atau `draft`) **DAN** `payment_status = unpaid`. Begitu bukti transfer masuk (`awaiting_verification`) atau status naik → terkunci.
4. **Harga:** hitung ulang semua baris di harga tier pembeli terkini (`priceForRole($po->user_role)`) — konsisten dengan Buat PO. Diskon & ongkir yang sudah ada dipertahankan: `total_amount = subtotal_baru − discount + shipping_cost`.

## Arsitektur

Cermin dari alur Buat PO. Logika bangun-baris-item diekstrak agar dipakai bersama create & edit (DRY); `createForPartner` tetap berperilaku identik (dijaga tes create existing). Otorisasi inline di controller (pola `cancel()`), bukan lewat grup permission — karena butuh OR dua izin (owner `create_po` vs admin `update_po_status`).

### Komponen

- **`PurchaseOrderService`**
  - `cleanLines(array $lines): array` — dedup per product_id, buang qty ≤ 0, lempar `ValidationException` kalau kosong. (diekstrak dari `createForPartner`)
  - `buildItemLines(array $clean, string $role, array $priceOverrides = []): array` — kunci produk aktif (`lockForUpdate`), hitung `unit_price` (override → `priceForRole($role)`), kembalikan `[$itemsData, $subtotal]`. (diekstrak dari `createForPartner`; dipanggil di dalam transaksi caller)
  - `createForPartner(...)` — refactor memakai kedua helper; perilaku & output identik.
  - `updateItems(PurchaseOrder $po, array $lines): PurchaseOrder` — **baru**. Guard defensif (status pending/draft + unpaid, else `RuntimeException`). Dalam 1 `DB::transaction`: bangun baris baru, `items()->delete()` lalu `createMany`, update `subtotal` + `total_amount` (pakai discount & shipping_cost existing), `AuditService::log('edit_po', before/after)`. Return `$po->load('items')`.

- **`PurchaseOrder` model**
  - `isEditable(): bool` — `in_array($status, [pending, draft]) && $payment_status === unpaid`. Dipakai controller (guard) & view (tampilkan tombol).

- **`PurchaseOrderController`**
  - `canEditPo(User, PurchaseOrder): bool` (privat) — `update_po_status` (admin) **atau** (`isPartner` + pemilik + `holdsStock`).
  - `edit(Request, PurchaseOrder)` — `abort_unless(canEditPo, 403)`; kalau `!isEditable()` redirect ke show + error; else render form Edit dengan qty ter-prefill.
  - `update(Request, PurchaseOrder)` — otorisasi + guard sama; validasi `items` (rule sama `store`); panggil `updateItems`; redirect show + status.
  - `show(...)` — tambah `$canEdit = $po->isEditable() && canEditPo($user, $po)` untuk tombol.

- **Route** (`routes/web.php`, auth saja — controller yang otorisasi):
  - `GET  /purchase-orders/{purchaseOrder}/edit` → `edit` (name `purchase-orders.edit`)
  - `PUT  /purchase-orders/{purchaseOrder}` → `update` (name `purchase-orders.update`)

- **View**
  - `purchase_orders/_catalog.blade.php` — **partial baru**: kartu katalog (tabel produk + input qty). Param `$products`, `$user`, `$qtyByProduct` (map product_id→qty, default 0). Termasuk `recalc()` script.
  - `create.blade.php` — pakai partial (`$qtyByProduct` kosong). Perilaku sama.
  - `edit.blade.php` — **baru**: partial (qty ter-prefill) + panel ringkasan + tombol "Simpan Perubahan"; action `update` + `@method('PUT')`. Tanpa field alamat/catatan (cakupan item saja).
  - `purchase_orders/show.blade.php` — tombol **"Edit PO"** (muncul hanya kalau `$canEdit`), dekat "Batalkan PO".

## Alur data (update)

`PUT /purchase-orders/{po}` → validasi items → `updateItems($po, $lines)` → `cleanLines` → transaksi: `buildItemLines($clean, $po->user_role)` → hapus item lama → `createMany` item baru → update subtotal & total → audit `edit_po` → redirect show. **Tak ada** panggilan `complete()` → stok & komisi tak tersentuh.

## Penanganan error

- Bukan owner & bukan admin → **403**.
- PO tak editable (sudah bayar/diproses) → redirect/back + pesan "PO hanya bisa diedit selagi pending & belum ada bukti bayar".
- Semua qty 0 / item kosong → `ValidationException` (pesan sama Buat PO).
- Produk nonaktif/terhapus → `ValidationException` "Produk #X tidak tersedia".

## Testing (Feature: `PurchaseOrderEditTest`)

- Owner tambah produk baru + ubah qty + hapus (qty→0) → item & `subtotal`/`total_amount` benar.
- Admin (`update_po_status`) bisa edit PO milik mitra lain.
- Mitra lain (bukan owner, bukan admin) → 403.
- Terkunci saat `payment_status != unpaid` (bukti sudah diupload) → ditolak, item tak berubah.
- Terkunci saat status ≠ pending/draft (mis. `processing`) → ditolak.
- `total_amount = subtotal − discount + shipping_cost` (dengan discount & shipping_cost ter-set).
- **Nol efek samping:** setelah edit, `hq_stock` produk tak berubah & tak ada baris `Commission`.
- Form Edit (GET) render untuk owner dengan qty ter-prefill; redirect kalau tak editable.
- Regresi: seluruh tes Buat PO existing tetap hijau (jaga refactor `createForPartner`).

## Di luar cakupan

Edit alamat/catatan/diskon/ongkir lewat form ini; edit PO yang sudah `processing`/`completed`; edit stok/komisi. (Model X routing tetap dorman — `seller_id` tak disentuh.)
