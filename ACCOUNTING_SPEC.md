# SKINKU — Modul Akuntansi & Laporan Keuangan
### Spec untuk repo `laravel-b2b` (system.skinku.id)

> Dokumen ini konteks untuk Claude Code. Baca penuh sebelum nulis kode.
> Basis: analisa `Financial_Skinku.xlsx` (Mei & Juni 2026) — sistem akuntansi manual di Excel yang mau dipindah & di-otomasi ke dalam app.

---

## 0. LANGKAH WAJIB SEBELUM NGODING — AUDIT MODUL EXISTING

App B2B ini sudah punya modul operasional. Modul akuntansi **narik dari data yang sudah ada**, jangan bikin input jurnal manual dobel. Sebelum bikin apa pun, audit dulu dan laporkan:

1. **Purchase Orders** — tabel & model PO, statusnya apa aja, kapan dianggap "terjadi" (created / approved / received). Ini sumber jurnal pembelian & hutang.
2. **Manajemen Produk + Stok + Stock Movement** — gimana stok nambah/berkurang, tabel movement-nya, apakah tiap movement ada nilai rupiah (HPP per unit).
3. **Produksi (HPP)** — ini kunci. Gimana HPP dihitung sekarang (dari Bahan Baku → Produksi → Barang Jadi?). Modul akuntansi HARUS pakai angka HPP dari sini, jangan hitung ulang sendiri.
4. **Bahan Baku** — apakah persediaan dipisah: bahan baku vs barang jadi vs WIP? (Excel cuma punya 1 akun "Persediaan" — app kemungkinan lebih detail. COA mungkin perlu 3 akun persediaan, konfirmasi saat audit.)
5. **Laporan Penjualan** — sumber angka penjualan; pastikan definisinya sama dengan "Penjualan" di laporan keuangan.
6. **Cabang/lokasi** — apakah app sudah punya konsep cabang (Timur/Jakarta/Barat)? Kalau sudah, pakai yang existing, jangan bikin `acc_branches` baru.

**Output Langkah 0:** peta titik integrasi — event operasional apa memicu jurnal apa. Baru lanjut Fase 1.

---

## 1. PRINSIP DESAIN (jangan diubah)

- **Double-entry.** Tiap transaksi: total debit = total kredit. Validasi di level aplikasi, tolak kalau nggak balance.
- **Cabang = dimensi, bukan akun.** Excel bikin "Penjualan Timur", "Penjualan Jakarta", dst → COA membengkak. Di app: akun netral + kolom `branch_id`. Nambah cabang = tambah 1 baris, bukan puluhan akun.
- **`type` & `normal_balance` eksplisit per akun.** Jangan turunkan tipe dari nomor akun. (Di Excel `4008`=revenue tapi `4002`=liability — penomoran nggak konsisten, makanya di-renumber.)
- **Bulatkan ke rupiah** (`decimal(18,2)`). Excel simpan desimal panjang → bikin neraca selisih Rp0,75.
- **Prefix tabel `acc_`** biar nggak nabrak tabel operasional existing.

---

## 2. SKEMA DATABASE (Fase 1 — sudah dibuat)

File: `create_accounting_tables.php` + `ChartOfAccountSeeder.php` (terlampir).

- `acc_branches` — kalau app sudah punya tabel cabang, SKIP ini, foreign key ke tabel existing.
- `acc_accounts` — Chart of Account. Field: `code, name, type, subtype, normal_balance, legacy_code`.
- `acc_journals` — header transaksi: `branch_id, date, period(YYYY-MM), reference, description, type, status(draft/posted/void)`. Tambah kolom polymorphic `source_type` + `source_id` untuk link balik ke PO/penjualan asal (audit trail).
- `acc_journal_lines` — detail double-entry: `journal_id, account_id, branch_id, debit, credit, memo`.

---

## 3. CHART OF ACCOUNT

47 akun bersih (dari 60 baris campur di Excel), sudah dikelompokkan:
`1xxx` aset · `2xxx` liabilitas · `3xxx` ekuitas · `4xxx` pendapatan · `5xxx` HPP · `6xxx` beban ops · `7xxx` non-ops.

Detail lengkap ada di `ChartOfAccountSeeder.php`. `legacy_code` = referensi kode Excel untuk cross-check.

**Catatan audit:** kalau app pisah persediaan bahan baku / barang jadi / WIP, pecah akun `1201 Persediaan` jadi beberapa akun. Konfirmasi saat Langkah 0.

---

## 4. ROADMAP FASE

| Fase | Isi | Cara test |
|---|---|---|
| **1. Struktur data** | 4 tabel + COA seeder | `acc_accounts` keisi 47 akun, type/normal_balance bener |
| **2. Posting engine** | Service `postJournal()`: validasi debit=kredit, status draft→posted, void | Unit test: jurnal nggak balance → ditolak |
| **3. Buku besar & neraca saldo** | Agregasi saldo per akun/cabang/periode | Neraca saldo per 30 Juni cocok sama Excel |
| **4. Laporan** | Laba Rugi, Neraca, Arus Kas (replika sheet AI REPORT, tanpa bug `#VALUE!`) | Angka == patokan di §6 |
| **5. Auto-posting (integrasi)** | Event operasional → jurnal otomatis (§5) | PO/penjualan baru → jurnal kegenerate benar |

**Urutan test tiap fase:** input ulang data Juni 2026, bandingin app vs Excel. Match = mesin bener.

---

## 5. TITIK INTEGRASI (hipotesis — konfirmasi via Langkah 0)

Event → jurnal otomatis yang harus dibuat:

- **PO diterima** → (D) Persediaan / (K) Hutang Usaha atau Kas.
- **Penjualan** → (D) Kas/Piutang / (K) Penjualan **+** jurnal HPP: (D) Beban HPP / (K) Persediaan. Angka HPP ambil dari modul Produksi, JANGAN hitung ulang.
- **Retur penjualan** → (D) Retur Penjualan / (K) Kas/Piutang. (Ini yang bikin `#VALUE!` di Excel — di app tangani sebagai akun kontra-pendapatan biasa, aman.)
- **Beban (iklan, gaji, sewa, e-commerce fee, ongkir)** → (D) Beban terkait / (K) Kas/Hutang.
- **Bunga bank bulanan** → (D) Beban Bunga / (K) Kas. Jadwal: pokok Rp284.462.000, bunga ±Rp1.119.217/bln, periode Apr-2026 s/d Apr-2030. Kandidat modul amortisasi auto-generate (KEPUTUSAN TERBUKA).

---

## 6. PATOKAN TEST — DATA JUNI 2026 (Surabaya Timur, satu-satunya cabang aktif)

Angka target dari Excel (yang sudah dibersihkan dari bug). App dianggap benar kalau match:

| Pos | Nilai |
|---|---|
| Penjualan | 147.424.672 |
| Total HPP | 57.214.496 |
| Laba Kotor | 90.210.176 |
| Total Beban Operasional | 88.336.367 |
| Operating Income | 1.873.809 |
| Net Income | 954.792 |
| Total Aktiva | 363.579.580 |
| Total Pasiva | 363.579.580 (harus = Aktiva) |
| Persediaan Akhir | 82.147.721 |
| Hutang Bank | 266.683.126 |
| Modal Akhir | 76.696.454 |

Komponen beban ops kunci (buat cek mapping): Beban Iklan 20.531.376 · Beban Gaji Pegawai 15.701.000 · **Beban Biaya E-commerce 40.435.454** (fee marketplace TikTok/Shopee — di Excel ke-label salah jadi "Beban Administrasi", perbaiki mapping-nya) · Beban Sewa 2.500.000 · Beban Operasional 7.260.185 · Beban Ongkir 1.621.582.

---

## 7. KEPUTUSAN TERBUKA (butuh jawaban Freddie)

1. **HPP** — pakai angka dari modul Produksi existing (rekomendasi), atau input manual seperti Excel?
2. **Amortisasi bank** — modul auto-generate jurnal bunga tiap bulan, atau input manual?
3. **Persediaan** — satu akun (ikut Excel) atau pisah bahan baku / barang jadi / WIP (ikut struktur app)?
4. **Titik "penjualan terjadi"** — saat PO approved, dikirim, atau lunas? (nentuin kapan jurnal dibuat)
