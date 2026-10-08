# Testing Lokal — Jurnal AI

Panduan uji manual di komputer sendiri. **Tidak menyentuh portal B2B SKINKU
sama sekali**: beda folder, beda database, beda port. Portal boleh tetap jalan
bersamaan.

> **Semua perintah di sini untuk komputer lokal, BUKAN server.** Kalau sedang
> SSH ke Hostinger, `exit` dulu. `git checkout` di server akan memindahkan
> portal B2B yang live ke branch ini, dan `migrate`/`db:seed` menyentuh
> database produksi.
>
> Cek sedang di mana — PowerShell: `hostname; pwd` · Mac/Linux: `hostname && pwd`

---

## 0. Pastikan perkakasnya ada

**Windows (PowerShell):**

```powershell
php -v                        # butuh 8.3 atau lebih baru
composer -V
php -m | Select-String sqlite # harus muncul pdo_sqlite
```

**Mac / Linux:**

```bash
php -v && composer -V && php -m | grep sqlite
```

Kalau `pdo_sqlite` tidak muncul: jalankan `php --ini` untuk menemukan lokasi
`php.ini`, buka filenya, hapus tanda `;` di depan baris `extension=pdo_sqlite`,
lalu simpan. (Laragon/XAMPP biasanya sudah aktif.)

Tidak punya SQLite dan malas mengaktifkan? Lihat bagian **Pakai MySQL** di bawah.

## 0b. Pastikan repo-nya ada di komputer ini

Semua perintah berikutnya dijalankan DARI DALAM folder `jurnal-ai`. Kalau salah
folder, semua gagal dengan `Could not open input file: artisan`.

Cari repo-nya dulu — **Windows (PowerShell):**

```powershell
Get-ChildItem C:\ -Directory -Filter "skinku-b2b-portal" -Recurse -Depth 4 -ErrorAction SilentlyContinue |
    Select-Object -ExpandProperty FullName
```

**Mac / Linux:**

```bash
find ~ -maxdepth 4 -type d -name skinku-b2b-portal 2>/dev/null
```

**Belum pernah di-clone di komputer ini?** Clone saja, tidak mengganggu apa pun:

```
cd $HOME/Documents          # PowerShell: cd $HOME\Documents
git clone https://github.com/raventcreative/skinku-b2b-portal.git
cd skinku-b2b-portal
```

Repo ini private — saat clone akan diminta login GitHub (di Windows biasanya
terbuka jendela browser otomatis lewat Git Credential Manager).

## 1. Setup (sekali saja, ±2 menit)

**Windows (PowerShell)** — `&&` tidak jalan di PowerShell 5.1, jalankan
baris per baris:

```powershell
cd <path hasil langkah 0b>     # mis. C:\Users\DELL\Documents\skinku-b2b-portal
git fetch origin claude/quirky-cori-kbvckj
git checkout claude/quirky-cori-kbvckj
cd jurnal-ai

composer install
copy .env.example .env
php artisan key:generate
New-Item -ItemType File database\database.sqlite
php artisan migrate
php artisan db:seed
php artisan db:seed --class=DemoSeeder
```

**Mac / Linux:**

```bash
git fetch origin claude/quirky-cori-kbvckj
git checkout claude/quirky-cori-kbvckj
cd jurnal-ai

composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed                       # admin + 2 klien + COA standar
php artisan db:seed --class=DemoSeeder    # sebulan pembukuan contoh
```

**Tidak perlu `npm install`** — hasil build CSS/JS sudah ikut di-commit.
**Tidak perlu MySQL/MariaDB** — default SQLite, databasenya satu file di
`database/database.sqlite`.

### Pakai MySQL (kalau Laragon/XAMPP sudah jalan)

Buat database kosong bernama `jurnal_ai`, lalu ubah `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=jurnal_ai
DB_USERNAME=root
DB_PASSWORD=
```

Lalu `php artisan config:clear` dan lanjutkan dari `php artisan migrate`.
Database ini terpisah penuh dari database portal B2B.

## 2. Jalankan

```
php artisan serve --host=127.0.0.1 --port=8001
```

Port **8001**, bukan 8000 — 8000 dipakai portal B2B.

Buka http://127.0.0.1:8001/login · `admin@jurnal.test` / `password123`

Hentikan dengan `Ctrl+C`.

## 3. Pasang otak AI (biar fitur intinya kepakai)

Tambahkan di `.env`, **wajib model yang bisa melihat gambar**:

```env
AI_PROVIDER=openai
AI_MODEL=gpt-4o-mini
OPENAI_API_KEY=sk-...
```

Lalu `php artisan config:clear` dan restart `artisan serve`.

Biaya kasar gpt-4o-mini: satu foto struk ±Rp30–100 per dokumen. Testing
30 dokumen masih di bawah Rp3.000.

Tanpa API key sistem tetap jalan — upload tersimpan, jurnal manual tetap bisa,
hanya pembacaan otomatis yang mati (statusnya jadi "Gagal dibaca").

---

## 4. Checklist uji manual

Tanda ✓ = yang seharusnya terjadi. Kalau beda, itu bug — catat.

### A. Baca dokumen (fitur inti)

| # | Yang dicoba | Harus terjadi |
|---|---|---|
| A1 | Foto struk belanja asli pakai HP, upload sebagai "Foto struk / nota" | ✓ Rincian kebaca per baris, vendor & tanggal terisi, tiap baris dapat usulan akun COA |
| A2 | Struk nota tulis tangan / agak buram | ✓ Yang kebaca tetap diisi, yang ragu ditandai kuning `ragu xx%`, yang buram muncul di kotak peringatan — **bukan dikarang** |
| A3 | PDF invoice supplier asli | ✓ Nomor invoice & jatuh tempo kebaca, PPN masuk field "Pajak" terpisah, bukan dicampur ke harga barang |
| A4 | Screenshot mutasi m-banking (banyak baris) | ✓ Jadi **beberapa blok jurnal terpisah**, bukan satu. Uang masuk → bank di debit; uang keluar → bank di kredit |
| A5 | Teks tempel: `bayar iklan meta 500rb, gaji admin 3jt, ongkir jne 85.000` | ✓ Jadi 3 baris terpisah: 500.000 / 3.000.000 / 85.000, akun beda-beda |
| A6 | Teks dengan angka gaya Indonesia: `1,5jt`, `Rp 1.250.000`, `85rb` | ✓ Terbaca 1.500.000 / 1.250.000 / 85.000 |
| A7 | Struk bertanggal `03/04/2026` | ✓ Dibaca **3 April**, bukan 4 Maret |

### B. Review & posting

| # | Yang dicoba | Harus terjadi |
|---|---|---|
| B1 | Ubah akun di dropdown sebelum posting | ✓ Yang masuk buku akun pilihanmu, bukan usulan AI |
| B2 | Ubah nominal sampai debit ≠ kredit | ✓ Badge berubah jadi merah `Selisih Rp…` seketika, sebelum disubmit |
| B3 | Tetap paksa posting saat tidak balance | ✓ Ditolak dengan pesan jelas, **nol jurnal tersimpan** |
| B4 | Uncheck "Posting" di salah satu blok mutasi, lalu submit | ✓ Hanya yang dicentang yang masuk |
| B5 | Posting dokumen yang sama dua kali | ✓ Yang kedua dilewati, muncul pesan "…dilewati karena sidik jarinya sudah pernah masuk buku (anti-dobel)" |
| B6 | Upload ulang file struk yang persis sama | ✓ Muncul peringatan kuning "Dokumen dengan isi identik sudah pernah diolah" + link ke dokumen lamanya |
| B7 | Mutasi bank 5 baris, 1 baris sengaja dibikin tidak balance, submit semua | ✓ **Semua dibatalkan**, bukan 4 masuk 1 gagal |
| B8 | Klik "Baca ulang dengan AI" | ✓ Hasilnya diperbarui, dokumen asli tidak perlu diupload lagi |

### C. Isolasi antar klien (paling penting kalau mau dipakai ke klien orang)

| # | Yang dicoba | Harus terjadi |
|---|---|---|
| C1 | Buat klien baru | ✓ Otomatis dapat 50 akun COA standar, buku kosong |
| C2 | Posting jurnal di klien A, lalu buka laporan klien B | ✓ Angka klien A **tidak muncul** di laporan klien B |
| C3 | Di klien A, buat akun kode `1101` (sudah ada) | ✓ Ditolak — kode dobel dalam satu klien |
| C4 | Di klien B, buat akun kode `1101` | ✓ Boleh — bukunya terpisah |

### D. Laporan

| # | Yang dicoba | Harus terjadi |
|---|---|---|
| D1 | Buka Neraca Saldo | ✓ Total debit = total kredit, badge hijau "Balance" |
| D2 | Buka Neraca | ✓ Total Aktiva = Total Pasiva, keterangan hijau "✓ Aktiva = Pasiva" di bawah |
| D3 | Buka Laba Rugi, cek baris Retur Penjualan | ✓ Tampil negatif dan **mengurangi** Total Pendapatan |
| D4 | Void satu jurnal, lalu refresh laporan | ✓ Angkanya berkurang sesuai jurnal yang di-void |
| D5 | Ganti periode ke bulan yang tidak ada jurnalnya | ✓ Laporan kosong, **bukan error** |
| D6 | Bandingkan: Laba Rugi bulan X vs Neraca bulan X | ✓ Laba Rugi = mutasi bulan itu saja. Neraca = akumulasi sampai akhir bulan itu (termasuk bulan-bulan sebelumnya) |

### E. Hak akses

Buat user staff dulu:

```bash
php artisan tinker --execute="App\Models\User::create([
  'name'=>'Staff Uji','email'=>'staff@jurnal.test','password'=>'password123',
  'role'=>'staff','is_active'=>true]);"
```

| # | Yang dicoba (login sebagai staff) | Harus terjadi |
|---|---|---|
| E1 | Upload, review, posting, jurnal manual, void | ✓ Boleh semua |
| E2 | Buka `/klien-baru` | ✓ Ditolak 403 |
| E3 | Halaman COA | ✓ Tampil read-only, tanpa tombol simpan/hapus |
| E4 | Hapus jurnal | ✓ Tombolnya tidak ada |

### F. Perlakuan saat gagal

| # | Yang dicoba | Harus terjadi |
|---|---|---|
| F1 | Kosongkan `OPENAI_API_KEY`, lalu upload | ✓ Dokumen tetap tersimpan, status "Gagal dibaca" + alasannya. Isi teksnya tidak hilang |
| F2 | Upload file 20 MB | ✓ Ditolak dengan pesan batas ukuran, bukan error 500 |
| F3 | Upload file `.docx` | ✓ Ditolak: format didukung JPG/PNG/WEBP/HEIC/PDF |
| F4 | Submit form upload kosong (tanpa file & tanpa teks) | ✓ Ditolak dengan pesan jelas |
| F5 | Hapus klien yang sudah punya jurnal posted | ✓ **Dinonaktifkan**, bukan dihapus — riwayatnya utuh |

---

## 5. Reset data kalau mau mulai bersih

**Windows (PowerShell):**

```powershell
Remove-Item database\database.sqlite
New-Item -ItemType File database\database.sqlite
php artisan migrate
php artisan db:seed
php artisan db:seed --class=DemoSeeder
```

**Mac / Linux:**

```bash
rm database/database.sqlite && touch database/database.sqlite
php artisan migrate
php artisan db:seed
php artisan db:seed --class=DemoSeeder   # opsional
```

Aman — database ini milik Jurnal AI sendiri, tidak ada hubungannya dengan
database portal B2B.

## 6. Jalankan test otomatis

```bash
php artisan test
```

108 test, tidak butuh API key (panggilan AI difake). Kalau ada yang merah
sebelum kamu mengubah apa pun, itu bug — laporkan.

---

## Kalau macet

| Gejala | Sebabnya biasanya |
|---|---|
| `Could not open input file: artisan` | Salah folder — kamu belum `cd` ke dalam `jurnal-ai`. Lihat bagian 0b |
| `Composer could not find a composer.json file` | Sama: salah folder |
| `could not find driver` | Ekstensi `pdo_sqlite` belum aktif — lihat bagian 0 |
| `The token '&&' is not a valid statement separator` | PowerShell 5.1 tidak mendukung `&&` — jalankan perintahnya satu per satu, atau ganti `&&` jadi `;` |
| `touch` / `rm` tidak dikenali | Itu perintah Mac/Linux — pakai padanan PowerShell di bagian 1 & 5 |
| Halaman polos tanpa warna | `public/build` hilang → jalankan `npm install && npm run build` |
| Perubahan `.env` tidak terasa | `php artisan config:clear`, lalu restart `artisan serve` |
| Upload lama sekali | Normal: menunggu model membaca, ±5–20 detik. Belum pakai queue |
| `Address already in use` | Port 8001 kepakai → ganti `--port=8002` |
