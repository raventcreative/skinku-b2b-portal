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
- **Akuntansi/GL** — Accounting, AccAccount, AccTemplate + Acc* model; FinancialReport/Ledger/CashFlow/ComparativeReport Service (double-entry, L/R, neraca, mutasi bank).
- **Sistem** — Auth, User, Permission/Role, Announcement, AuditLog, Setting (+ backup DB), Impersonation, Dashboard, Export, Supplier.

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

### KOL Command Center (14 modul — paritas Iyuro tuntas)
- Kol (Database+CRM+multi-akun), KolDashboard, KolDeal (+budget/month-picker/detail), KolPipeline (2 papan), KolContent, KolAffiliate (+Jadikan KOL), KolScoring (APS/KSS), KolScreening, KolCampaign, KolSample, KolReminder, KolSettings, KolImport.
- **KolAgent** — endpoint penerima `POST /api/kol-agent/affiliate` (untuk agen scraper lokal).

### AI & Produktivitas
- **AI** — AiAssistant + AiDiscovery (Tavily) + `Ai/` (provider factory, 8 tools). OKR AI (`OkrAiService`, `OkrBusinessSnapshotService`).
- **Produktivitas** — Okr, Kanban, Mindmap (+ AI tools), Learning (SKINKU Academy).

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
