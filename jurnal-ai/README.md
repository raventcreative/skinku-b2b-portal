# Jurnal AI

Pencatatan jurnal akuntansi berbantuan AI. Upload **foto struk, invoice PDF,
screenshot mutasi bank, atau tempel teks mentah** — AI membacanya jadi rincian
biaya, mengusulkan akun COA tiap baris, lalu kamu review dan posting jadi jurnal
double-entry. Tiap klien punya buku, COA, dan laporan keuangannya sendiri.

> Aturan yang tidak bisa dilanggar: **AI tidak pernah memposting sendiri.**
> Hasil bacanya selalu mendarat di layar review untuk dikoreksi manusia. Yang
> masuk buku adalah angka yang sudah dilihat orang.

---

## Alur kerja

```
UPLOAD ───► AI BACA ───► REVIEW MANUSIA ───► POSTING ───► LAPORAN
 foto/PDF    rincian       koreksi akun       jurnal      Laba Rugi
 teks/scan   + usulan      & nominal          balance     Neraca
             akun COA                                     Neraca Saldo
```

1. **Upload** — foto struk, PDF invoice, screenshot mutasi, atau teks tempel.
   Berkas disimpan apa adanya untuk jejak audit.
2. **AI baca** — model multimodal menarik vendor, tanggal, rincian per baris,
   pajak, diskon, total; lalu memetakan tiap baris ke akun COA klien tersebut
   (hanya kode yang benar-benar ada di COA-nya).
3. **Review** — layar review menampilkan dokumen asli di sebelah usulan jurnal
   yang bisa diedit. Baris yang AI-nya ragu ditandai kuning. Ketidakcocokan
   rincian vs total dilaporkan terang-terangan, tidak disembunyikan.
4. **Posting** — jurnal masuk buku lewat satu pintu (`JournalPoster`) yang
   menolak jurnal tidak balance, baris debit+kredit sekaligus, dan akun milik
   klien lain.
5. **Laporan** — Neraca Saldo, Laba Rugi, dan Neraca per klien per periode.

### Yang bisa dibaca

| Jenis | Cara kerja |
|---|---|
| Foto struk / nota | Dikirim ke model vision. Toleran nota tulis tangan — yang buram ditandai sebagai warning, bukan dikarang. |
| Invoice / PDF supplier | Teks PDF diekstrak dulu secara lokal (murah & akurat). Kalau PDF hasil scan atau ber-font CID, berkasnya baru dikirim ke model. |
| Screenshot mutasi bank / e-wallet | Banyak baris sekaligus → **satu jurnal per baris**, masing-masing bersidik jari sendiri sehingga impor ulang tidak menggandakan. |
| Teks tempel (WhatsApp, catatan) | `bayar iklan 500rb, gaji admin 3jt` → dipecah jadi item terpisah dengan akun masing-masing. |

---

## Jalankan lokal

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite      # default: SQLite, nol konfigurasi
php artisan migrate
php artisan db:seed                 # admin + klien contoh + COA standar
php artisan db:seed --class=DemoSeeder   # opsional: sebulan pembukuan contoh

npm install && npm run build        # atau `npm run dev` untuk HMR
php artisan serve
```

Buka http://127.0.0.1:8000 — login `admin@jurnal.test` / `password123`
(ubah lewat `SEED_ADMIN_EMAIL` / `SEED_ADMIN_PASSWORD` sebelum seed).

### Pasang otak AI

Isi di `.env` — **wajib model yang bisa melihat gambar (vision)**:

```env
AI_PROVIDER=openai
AI_MODEL=gpt-4o-mini
OPENAI_API_KEY=sk-...
```

Atau pakai Claude:

```env
AI_PROVIDER=anthropic
AI_MODEL=claude-sonnet-5-5
ANTHROPIC_API_KEY=sk-ant-...
```

**Otak cadangan** (opsional tapi disarankan) — dipakai otomatis kalau primary
kehabisan kuota atau down, supaya upload yang sudah terlanjur masuk tidak
hangus. Endpoint apa pun yang OpenAI-compatible:

```env
AI_BACKUP_KEY=sk-or-...
AI_BACKUP_BASE=https://openrouter.ai/api/v1
AI_BACKUP_MODEL=...
```

Tanpa API key sistem tetap jalan: upload tersimpan, jurnal manual tetap bisa.
Hanya pembacaan otomatis yang tidak aktif.

### Produksi (MySQL)

```env
DB_CONNECTION=mysql
DB_DATABASE=jurnal_ai
DB_USERNAME=...
DB_PASSWORD=...
```

`public/build` sengaja **di-commit** supaya server tanpa Node cukup `git pull`.
Jalankan `npm run build` sebelum commit setiap kali kelas Blade/CSS berubah.

---

## Struktur

```
app/
  Models/            Client · Account · Document · Journal · JournalLine · User
  Services/
    Accounting/
      JournalPoster.php    satu-satunya pintu pembuatan jurnal + validasi
      ChartOfAccounts.php  template COA UMKM/brand Indonesia (50 akun)
    Ai/
      AiProvider.php       antarmuka netral-provider (teks + gambar + PDF)
      OpenAiProvider.php   OpenAI & semua endpoint OpenAI-compatible
      AnthropicProvider.php
      FailoverAiProvider.php  rantai otak: primary → cadangan
    Intake/
      DocumentExtractor.php  prompt + normalisasi hasil baca AI
      JournalDrafter.php     hasil baca → usulan jurnal yang dijamin balance
    Reporting/
      ReportService.php    Neraca Saldo · Laba Rugi · Neraca · snapshot
  Support/
    Rupiah.php             parsing "Rp 1.250.000" / "85rb" / "1,5jt" → float
    PdfTextExtractor.php   ekstraksi teks PDF tanpa dependency
```

### Keputusan desain yang menahan beban

- **Klien = isolasi penuh, bukan sekadar filter.** COA, jurnal, dan laporan
  terpisah per klien. `JournalPoster` menolak jurnal yang memakai akun milik
  klien lain — kalau lolos, laporan dua klien tercampur diam-diam.
- **Semua angka dari AI dicuci ulang di PHP.** Model sering membalas
  `"Rp 1.250.000"` atau `"1,250,000"`. `Rupiah::parse()` menormalkan semuanya;
  tanggal `03/04/2026` dibaca sebagai 3 April (gaya Indonesia), dan tanggal
  ngawur (1970, 2090) ditolak.
- **Kode akun karangan tidak pernah lolos.** Kalau model menyebut kode yang
  tidak ada di COA klien, barisnya dialihkan ke akun default dan ditandai
  warning — jurnal tidak boleh gagal hanya karena model salah ngarang nomor.
- **Draft dijamin balance.** Sisi pembayaran dihitung sebagai sisa, jadi user
  tidak pernah dihadapkan form yang pasti ditolak server. Kalau rincian tidak
  cocok dengan total dokumen, itu dilaporkan sebagai warning — bukan ditambal diam-diam.
- **Anti-dobel dua lapis.** `content_hash` menandai dokumen identik yang
  diupload ulang; `fingerprint` per transaksi menahan posting dobel walau
  struknya difoto ulang dari sudut berbeda.
- **Posting gagal sebagian = batalkan semua.** Setengah mutasi bank di buku
  lebih berbahaya daripada nol.
- **Jangan hapus, nonaktifkan.** Klien & akun yang sudah punya jurnal posted
  tidak bisa dihapus — saldo historis dan laporan periode lalu harus tetap utuh.
  Jurnal salah di-**void** (berhenti dihitung), bukan dihapus.

### Peran

| | Staff | Admin |
|---|---|---|
| Upload dokumen, review, posting | ✅ | ✅ |
| Jurnal manual, void jurnal | ✅ | ✅ |
| Lihat semua laporan & COA | ✅ | ✅ |
| Buat/ubah klien, ubah COA | ❌ | ✅ |
| Hapus jurnal & dokumen permanen | ❌ | ✅ |

---

## Test

```bash
php artisan test
```

107 test menutupi: validasi double-entry, isolasi antar klien, anti-dobel,
parsing rupiah segala rasa, normalisasi hasil AI (kode karangan, angka kotor,
tanggal ngawur), penyusunan draft jurnal (pajak, diskon, hutang, mutasi bank),
ketepatan laporan, kontrol akses, dan smoke test semua halaman dalam keadaan
kosong maupun berisi. Panggilan AI difake — test tidak butuh API key.

---

## Yang belum ada

- Jurnal penutup akhir periode (laba berjalan masih disajikan langsung di ekuitas).
- Laporan Arus Kas.
- Ekspor PDF/Excel laporan.
- Pembacaan asinkron lewat queue — saat ini upload menunggu AI selesai
  (±5–20 detik). Perlu dipindah ke job kalau volumenya sudah ratusan dokumen per hari.
- Multi-user per klien (sekarang semua user melihat semua klien).
