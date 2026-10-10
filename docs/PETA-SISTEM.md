# Peta Sistem SKINKU — Status & Roadmap

> **Snapshot status "apa yang sudah dibangun / belum".** Beda dari [`SISTEM.md`](SISTEM.md)
> (dokumentasi teknis A-Z "bagaimana cara kerjanya"). File ini = "apa yang ADA & statusnya".
>
> **Diverifikasi dari KODE**, bukan ingatan — per **31 Agustus 2026**.
> ⚠️ Snapshot cepat basi. Sebelum menyatakan "belum dibangun", **verifikasi ke kode dulu**
> (`php artisan route:list`, `ls app/Http/Controllers`, `grep`), jangan andalkan baris ini.

## Sensus kode (per 2026-08-31)

| | Jumlah |
|---|---|
| Controller | 60 |
| Service | 46 + 33 sub (Ai/Discovery/ReportBot) |
| Model | 86 |
| Migrasi | 115 (68 bikin tabel), terakhir `000114_create_report_sku_maps` |
| Tes | 163 file · 1.053 metode |
| Fitur ter-gate izin | 40 permission |

Cara audit ulang: `php artisan route:list`, `ls app/Http/Controllers/*.php`, `ls app/Services`,
`grep -rh "public function test" tests/ | wc -l`.

---

## ✅ SUDAH dibangun & live (ada controller + route + model + tes)

### Fondasi Portal
- **PO & Distributor** — `PurchaseOrderController` + `PurchaseOrderService`; PoPayment, PoReturn. Bukti transfer, mass-status, backdate sale.
- **Produk/Stok/Produksi** — Product, Production (HPP moving-avg), Material, Inventory, StockMovement, StockOpname, StockReceipt, HqStockReport.
- **Akuntansi/GL** — laporan keuangan (Laba Rugi, Neraca, Arus Kas, dll.) butuh izin Lihat HPP sejak 2026-10-08, jurnal/COA/impor cukup `view_accounting`. Accounting, AccAccount, AccTemplate + Acc* model; FinancialReport/Ledger/CashFlow/ComparativeReport Service (double-entry, L/R, neraca, mutasi bank).
- **Sistem** — Auth, User, Permission/Role, Announcement, AuditLog (kolom Perubahan = `AuditLog::ringkasPerubahan()` before → after, target bernama: kreator/user/produk/PO), Setting (+ backup DB), Impersonation, Dashboard, Export, Supplier.

### HR (2026-10-10, bertahap — spec `docs/superpowers/specs/2026-10-10-hr-design.md`)
- **Karyawan (Fase 1)** — `HrEmployeeController` + `Employee` (`000162`): database karyawan, identitas terenkripsi, checklist onboarding + dokumen di disk privat, pengingat percobaan/kontrak; izin `hr.view`/`hr.manage`, route `/hr/*` di balik `internal`.
- **Rekrutmen + Psikotes (Fase 2)** — `HrRekrutmenController` / `HrPsikotesController` + `JobOpening`, `Candidate`, `PsychotestSession` (`000163`): lowongan, kandidat & tahap seleksi, CV privat, "Jadikan karyawan" (+`hr.manage`); psikotes publik `PsikotesPublikController` `/tes/{token}` (tanpa login, 7 hari, sekali pakai) — bank soal `App\Support\Psikotes\BankSoal`, skor `PsikotesService`; izin `hr.recruit`.
- **Payroll (Fase 3)** — `HrPayrollController` + `PayrollService` + `PayrollProfile` / `PayrollRun` / `PayrollItem` (`000164`), tarif `App\Support\Payroll\Pajak` (TER PP 58/2023, Pasal 17, PTKP): data gaji, BPJS & PPh 21 otomatis (Desember hitung setahun), run draf → kunci (jurnal Akuntansi 6002/5004 · 2003/2002 · 2004 · 2009 · 1105) → buka kunci (void), slip cetak; izin `payroll.view`/`payroll.manage`.

### Jaringan Mitra (MLM) — model AKTIF = Model A (margin/inter-partner)
Model komisi terpusat lama → **dorman (revivable)**. Semua ke-wire (route+nav+logika+tes):
- **Model A** — `DownlineOrderController` (`pesanan-downline` + fulfill/reject/verify-payment). Inti: `PurchaseOrderService::resolveSeller()` — distributor stockist dgn upline stockist (GD) → PO ke GD (inter-partner, transfer stok antar gudang); selain itu → HQ. **Dormant-safe:** `upline_id` null = semua ke HQ (perilaku lama). Gate `process_downline_po`.
- **Sponsor role + dual-link** — `RecruitController` (`rekrutan-saya`); `sponsor_id` + role sponsor; RO cashback 5% (`CommissionService::recordRoCashback`, GD restock ke HQ → perekrut).
- **Insentif Volume Grand** — `VolumeIncentiveService` + `VolumeIncentiveTier`; tier admin di `settings/volume-tier`; clawback saat retur.
- **Komisi & saldo** — `CommissionController` (`komisi-saya` + tarik/batal), Withdrawal (`penarikan`).
- **Hierarki** — `PartnerHierarchyController` (`struktur-jaringan` + place/tier), `JaringanSayaController`.
- **Onboarding & Paket Join** — `OnboardingController`, `JoinPackageController` (Bronze/Gold, bonus join 10%).
- **Retur Distributor** — `ReturController` (retur/approve/void/reject + `join-transactions/cancel`) dgn clawback komisi.
- **Penjualan Mitra** — `PartnerSaleController` (`inventory/sales`).

### Integrasi Marketplace
- **Shopee** — `ShopeeController` + 6 service (Order/Return/Settlement/Wallet/Sync/Accounting) + 5 model. Orders→stok→jurnal, settlement, retur, backfill.
- **TikTok** — `TikTokController` + `TikTokIncomeController` + 6 service + 4 model + SkuMap. Orders/SKU-map/potong-stok/funnel/retur/settlement/jurnal (cron) + Laporan Income.
- **Report Bot Telegram** — `TelegramWebhookController` + `ReportBotAdminController`; flow Leads/Ads (AI) + TikTok Income parser; **peta SKU editable** (`ReportSkuMap`, Settings→Report Bot).
- **Naikkan Produk Shopee otomatis** (`ShopeeBoostService`, tabel `shopee_boost_items` migrasi `000161`, command `shopee:naikkan-produk` tiap 10 menit): maks 5 produk pilihan dijaga naik 24 jam lewat API `boost_item`/`get_boosted_list` — SISTEM.md §9d Alur no. 9.
- **Kontrol Stok & Harga Marketplace — Produk Master** — engine SELESAI & LIVE di `main`: `MarketplaceStockController` + `MarketplaceMasterService` (engine) + model `MarketplaceMaster`/`MarketplaceMasterChannel`/`MarketplaceListing`. Tiap unit jual (satuan/varian/bundle) = 1 Produk Master dgn stok+harga sendiri, override per channel (TikTok/Shopee), push cron `marketplace:push-stock` tiap 5 menit, order-mirror via `applyOrderDelta` terpisah total dari HQ. Tabel Fase 1/1.5 lama (`marketplace_stocks`/`marketplace_channel_overrides`) **di-drop** (migrasi `000146`, data lama dimigrasikan ke master dulu kalau ada). Izin `manage_marketplace_stock`.
  **Model MANUAL ala Desty SELESAI & MERGED `main`** (`7b87bef`; branch `feat/produk-master-manual`, 4 task SDD) — **menggantikan** redesign dedup-by-nama di atas (`feat/produk-master-katalog`, sempat merge & live di `main`, sekarang dibuang): admin bikin master satu-per-satu (CRUD manual `create`/`store`/`edit`/`update`/`duplikat`) lalu tautkan ke listing TikTok/Shopee existing (via modal Kaitkan Produk) buat sinkron stok — tak ada lagi auto-buat-master dari resolve/dedup nama. Route baru: `create`/`store`/`edit`/`update`/`duplikat`. Route dihapus: `siapkan`, `seed-tiktok`, `masterize-all`, `master.gabung`, push per-master (`push/{master}`), `master.foto` (CODE-VERIFIED via `route:list --path=marketplace-stock`). Halaman `/marketplace-stock` = tabel katalog Desty (Foto/Informasi Produk/Master SKU/Harga/Stok/Produk Terkait/Toko Terkait/Atur) + tab Semua/Satuan/Bundle; menu Atur = Ubah/Duplikat/Tambah ke Marketplace/Jadikan Bundle-Satuan/Hapus. Tanpa migrasi baru (skema `000139`–`000148` sudah lengkap). Detail: [`docs/SISTEM.md` §9d](SISTEM.md#9d-produk-master-e-commerce).
  **Modal "Kaitkan Produk" ala Desty SELESAI** (branch `feat/produk-master-kaitkan-modal`, 3 task SDD) — ganti picker `<select>` lama di menu Atur → "Tambah ke Marketplace" dengan **modal** (tab Semua/Produk Terkait/Produk Tidak Terkait + hitungan live, search nama/SKU, filter channel TikTok/Shopee, bulk **"Tautkan terpilih"**/**"Lepas terpilih"**); angka **Produk Terkait**/**Toko Terkait** di tabel katalog kini bisa **diklik langsung** buka modal itu (pre-filter tab Produk Terkait). Backend: `MarketplaceMasterService::linkListings()`/`unlinkListings()` (bulk) + route baru `POST marketplace-stock.kaitkan`/`marketplace-stock.lepas` (CODE-VERIFIED via `route:list --path=marketplace-stock`); route lama `tautkan` (single) tetap ada & dites tapi tak lagi punya entry point UI. Data modal di-embed sekali via `json_encode(JSON_HEX_TAG|...)`, tanpa AJAX/lib baru, tanpa migrasi. Detail: [`docs/SISTEM.md` §9d](SISTEM.md#9d-produk-master-e-commerce).
  **Kosongkan Semua Master + cleanup SELESAI** (branch `feat/okr-delete-and-cleanups`) — tombol **"Kosongkan Semua Master"** (`kosongkan` → `deleteAllMasters()` bulk delete; FK auto-null listing + cascade channel override; HQ tak disentuh) buat mulai bersih / buang sisa master auto lama. Dead code `tautkan` (route + `tautkanListing()` + `findOrCreateMaster()`) **DIHAPUS** (tak terjangkau UI sejak modal Kaitkan; `TautkanTest`+`DedupByNameTest` ikut dihapus). Tanpa migrasi.
  **Form Produk Master LENGKAP ala Desty SELESAI & MERGED `main`** (branch `feat/produk-master-form-lengkap`, 3 task SDD) — form Tambah/Ubah (`marketplace-stock.form`) diperkaya: Informasi Produk (nama/**kategori**/**deskripsi**), Informasi Penjualan (harga/stok/master SKU/**barcode**/tipe), **galeri foto s.d. 9** (upload banyak `foto[]`, **hapus per-foto** + **jadikan foto utama** di form Ubah) dan Pengiriman (**berat** g + **panjang/lebar/tinggi** cm). 7 kolom baru di `marketplace_masters` (migrasi **`000150`**, nullable) + foto pakai tabel `files`/`HasFiles` koleksi `master_image` (tanpa tabel/paket baru). Route baru: `marketplace-stock.master.foto.hapus` (`DELETE`) + `marketplace-stock.master.foto.utama` (`POST`), guard IDOR, izin `manage_marketplace_stock` (CODE-VERIFIED via `route:list --path=marketplace-stock`, 21 route). ⚠️ **Field baru = data internal SKINKU; yang di-push OTOMATIS ke marketplace tetap HANYA stok & harga** — push deskripsi/berat/dimensi kini ada tapi MANUAL (lihat "Dorong Konten" di bawah), foto ikut sejak Fase 2; kategori/barcode/nama belum. Catatan: `duplicateMaster` belum menyalin kolom detail baru (master hasil duplikat mulai kosong utk kategori/deskripsi/berat/dimensi/barcode). HQ tak disentuh. Detail: [`docs/SISTEM.md` §9d](SISTEM.md#9d-produk-master-e-commerce).
  **Dorong Konten ke Marketplace — Fase 1 SELESAI** (branch `feat/dorong-konten-marketplace`, 5 task SDD) — tombol **"Dorong Konten"** (kartu di form Ubah + item di menu Atur katalog) mengirim **deskripsi + berat + dimensi** master ke listing TikTok (`partial_edit`) & Shopee (`update_item`) yang tertaut. **MANUAL** (tombol + konfirmasi, **bukan cron** — cron tetap hanya stok+harga), **skip-empty** (field kosong tak menimpa isi marketplace), konten **level-item** (berlaku ke seluruh produk listing-nya, bukan per varian). Migrasi **`000151`** (4 kolom jejak di `marketplace_listings`: `last_content_status`/`last_content_error`/`last_content_pushed_at`/`content_hash`); route baru `marketplace-stock.master.konten` (`POST`, izin `manage_marketplace_stock`). Status per listing (badge `Konten ✓/gagal` + pesan error) tampil di halaman channel `/marketplace-stock/{channel}`. ⚠️ **Belum:** nama/judul, kategori, barcode (foto: lihat Fase 2 di bawah). HQ tak disentuh. Detail: [`docs/SISTEM.md` §9d](SISTEM.md#9d-produk-master-e-commerce).
  **Dorong Foto ke Marketplace — Fase 2 SELESAI** (branch `feat/dorong-foto-marketplace`) — **digabung** ke tombol "Dorong Konten" (label kini "Dorong konten & foto"): foto master (s.d. 9, urut, pertama = cover) di-upload multipart (TikTok `images/upload`, Shopee `media_space/upload_image`) lalu **mengganti SELURUH set foto listing** (TikTok `partial_edit` `main_images`, Shopee `update_item` `image.image_id_list`). Pengaman: master tanpa foto → dilewati; ⚠️ **dorong PERTAMA ke tiap listing mengganti SEMUA fotonya** (disebut di konfirmasi); **diff-guard `photo_hash`** — setelahnya foto hanya dikirim bila set foto master berubah (klik ulang tak mengganti foto); varian produk yang sama beda master → klik terakhir menang; upload sekali per channel per run + `set_time_limit(180)` best-effort (anti-timeout). Migrasi **`000153`** (4 kolom `last_photo_*`/`photo_hash`); badge `Foto ✓/gagal` di halaman channel. ⚠️ Belum terkonfirmasi dari dok publik: TikTok `partial_edit` menerima `main_images` — bila ditolak tercatat `failed` + pesan asli. **Varian ber-isi** (kolom "Isi (SKU × qty)" di tabel varian, mis. "3 Pcs" = Scrub-1 × 3): resep `marketplace_bundle_items` di master varian → stok varian otomatis = stok isi ÷ qty, order varian memotong stok isi. **Pemetaan ke HQ**: halaman satu-tabel untuk menandai produk gudang tiap satuan (tebakan otomatis), Jadikan Bundle, dan isi resep dari HQ — persiapan gabung stok, tanpa menyentuh stok. **Stok bundle otomatis (Fase 6)**: resep bundling (`marketplace_bundle_items`, migrasi `000156`) → stok bundle dihitung dari isi, order bundle memotong stok isi. **Varian ala Desty (Fase 5)**: master induk + master anak per opsi varian (`parent_id`, migrasi `000155`), SKU/harga/stok/barcode/listing per varian, konten/foto/kategori dari induk; katalog menampilkan varian di bawah induk. **Sinkron otomatis tiap Simpan (Fase 3)**: nama (produk 1 SKU)/deskripsi/berat/dimensi/barcode (GTIN valid)/foto ikut terkirim saat Simpan ke listing yang sudah pernah didorong manual (diff-guard); tombol Dorong = paksa SEMUA (stok, harga, konten, foto). **Kategori marketplace (Fase 4)**: pemilih kategori TikTok & Shopee terpisah dari API + atribut wajib (+ merek Shopee), tombol "Ambil dari listing marketplace", ikut sinkron (migrasi `000154`). **Atur urutan foto dengan geser** (branch `feat/foto-urut-drag`): drag & drop di galeri form Ubah, simpan via AJAX `marketplace-stock.master.foto.urutan` (himpunan foto wajib persis → 422). Foto **Baru** (belum di-Simpan, juga di form Tambah) ikut bisa digeser — urutan dikirim lewat `urutan_foto` saat Simpan. **Upload foto master anti-timeout** (sudah MERGED `main`): foto dikompres di browser (maks 1600px, q0.85) sebelum Simpan; server simpan 1600px q85. Detail: [`docs/SISTEM.md` §9d](SISTEM.md#9d-produk-master-e-commerce).

### KOL Command Center (14 modul — paritas Iyuro tuntas)
- **Views Harian SKINKU** (`kol_content_daily_snapshots`, migrasi `000158`): views per hari video SKINKU per kreator dari potret sync 04:00 — SISTEM.md §15.
- **Tracker performa TikTok** (`kol_tiktok_snapshots`, migrasi `000157`, cron mingguan `tiktok:kol-performance-sync`): views/engagement/GPM/GMV Rupiah 30 hari + grafik di Detail KOL — lihat SISTEM.md §15.
- Kol (Database+CRM+multi-akun), KolDashboard, KolDeal (+budget/month-picker/detail), KolPipeline (2 papan), KolContent, KolAffiliate (+Jadikan KOL), KolScoring (APS/KSS), KolScreening, KolCampaign, KolSample, KolReminder, KolSettings, KolImport.
- **KolAgent** — endpoint penerima `POST /api/kol-agent/affiliate` (untuk agen scraper lokal).

### AI & Produktivitas
- **AI** — AiAssistant + AiDiscovery (Tavily) + `Ai/` (provider factory, 43 tools — termasuk alat baca per menu: Stok HQ, PO, Stok Marketplace, Pesanan Marketplace, Komisi, Views Harian KOL, Data KOL, Produk Master, Pemantauan Stok, Retur, Bahan Baku, Produksi (HPP), Stok Opname, Laporan Penjualan/Pembelian, Omzet Mitra, Penjualan Downline, Generate Report, Laporan Keuangan (Akuntansi, + Lihat HPP), Penarikan (tanpa data rekening), Struktur Jaringan, Jaringan Saya, Rekrutan Saya, Dormansi Member (+ mitra lama tidak order), Paket Join, Pesanan Downline, Tim Gapok, Pipeline KOL, Deal KOL (biaya khusus finance), Reminder KOL, Konten & Views KOL, Pipeline & Kalender Konten (creator = miliknya), Insight Konten, Akun Sosial Media (tanpa token), Supplier (tanpa kontak), Kalkulator ROI (angka modal/target khusus Lihat HPP), OKR (internal), Academy (sesuai audiens); disaring izin role + data mitra milik sendiri). OKR AI (`OkrAiService`, `OkrBusinessSnapshotService`).
- **Produktivitas** — Okr, Kanban, Mindmap (+ AI tools), Learning (SKINKU Academy). OKR: `destroy()` kini bisa hapus OKR **draf & AKTIF** (aktif → `forceDelete()` kartu Kanban terkait dulu [BoardCard SoftDeletes; komentar cascade], lalu cascade objectives/KR/tasks); tombol Hapus di kartu daftar `/okr` & halaman detail (izin `okr.manage`) — branch `feat/okr-delete-and-cleanups`.

### Kalkulator ROI
- **Kalkulator ROI** — SELESAI (branch `feat/kalkulator-roi`, belum merge ke `main`): 2 tabel `roi_settings`/`roi_items` (migrasi `000141`), `RoiCalculatorService` (compute + effectiveInputs/rowFor/summary), `RoiCalculatorController` (1 controller), halaman `/kalkulator-roi`, izin `manage_roi_calculator`, tes Unit+Feature (27 tes).

### Portal Content Creator
- **Content Creator** — Fase 1 SELESAI: 3 tabel (migrasi `000142`), `ContentPostService`, `Social\MetaClient` + `Social\ContentPublisher`, `ContentPostController` / `ContentReviewController` / `SocialConnectionController`, command `content:publish-due` + `social:refresh-tokens`, izin `content.*` + `social.connect`, middleware `business`, tes `ContentCreatorTest`. Fase 2 (TikTok Content Posting API) selesai. Fase 3 insight: tabel `content_post_snapshots` (`000149`), `Social\ContentInsights`, command `content:sync-insights` (harian 05:00), `ContentInsightController` (`/content-insights`), tes `ContentInsightTest`.

---

## ⛔ BELUM dibangun (diverifikasi lewat KETIADAAN kode)

| Item | Cek | Catatan |
|---|---|---|
| **Agen scraper KOL** | tak ada `playwright/puppeteer/cdp` di repo | **By design** — ada di repo Iyuro terpisah, **wajib jalan di PC lokal** (butuh browser + login TikTok). Penerima (`KolAgentController`) sudah siap. Sementara: export Seller Center → import manual. |
| **Pembayaran in-app** | tak ada `midtrans/xendit/snap` | Opsional |
| **Shopee Ads (AMS)** | tak ada `shopee ads/AMS` | Opsional |
| **Satukan 2 peta SKU** | — | Opsional. Bot (`ReportSkuMap`, SKU ID→kategori) vs `TiktokSkuMap` (Seller SKU→produk, potong stok API) — beda fungsi. |
| **Mindmaps Fase 2** | sebagian AI-tool ada | Opsional lanjutan |

---

## Langkah selanjutnya (opsional)
Inti sistem sudah lengkap & live — tak ada keputusan arah besar yang menggantung.
1. **Agen scraper KOL** (kalau mau otomasi) — proyek tersendiri, jalan lokal.
2. **Polish opsional** — satukan peta SKU, Shopee AMS, in-app payment, Mindmaps Fase 2.
3. **Fitur baru sesuai kebutuhan** — mis. cetak resi (Shopee/TikTok/J&T).

## Catatan penting
- **Model A "dormant-safe":** live di kode, tapi baru aktif per-mitra begitu `upline_id`-nya diisi
  (Struktur Jaringan). Jaringan prod kosong = semua PO ke HQ = perilaku lama.
- **Update file ini** tiap modul besar berubah, dan **selalu cek kode** sebelum klaim status.
