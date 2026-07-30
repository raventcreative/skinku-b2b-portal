# Validasi produksi OKR Q3

Dokumen ini merekonstruksi arahan dari acceptance checklist pada handoff. Teks
prompt asli tidak tersimpan di Git atau log aplikasi, sehingga prompt di bawah
tidak boleh disebut salinan verbatim.

## Prompt rekonstruksi

Susun OKR perusahaan untuk Q3 dengan tepat satu Objective utama per fungsi:
CMO/Freddie, CFO/Billy, dan COO/Devrina.

- Target omzet e-commerce Rp500 juta harus dipisahkan dari target distributor.
- Bangun 30 distributor yang masing-masing mencapai Rp100 juta per bulan.
  Perlakukan target ini sebagai aspiratif dan jelaskan gap bila baseline aktual
  belum mendukung.
- Bangun funnel 5.000 affiliate dengan tahap terdaftar/rekrut, onboarding, aktif
  membuat konten atau live, menghasilkan order, GMV/conversion, retention, dan
  produktivitas per affiliate.
- Kembangkan 15 master item baru di luar Perfume dan Acne. Jangan menganggap
  semuanya siap launch; gunakan gate riset, konsep, costing/HPP, sampling, uji
  pasar, produksi, launch, dan evaluasi.
- Bagi pekerjaan sesuai job desk pada Pengetahuan AI. Agatha menangani desain
  dan konten, Tiar menangani KOL/affiliate, Gracelyn menangani talent/video/UGC.
  Freddie, Billy, dan Devrina tetap mendapat pekerjaan review/approval dengan
  nama PIC BOD walaupun memakai akun teknis Super Admin.
- Gunakan kolom To Do bernama PIC bila tersedia. Jangan mengalihkan pekerjaan
  Hida diam-diam bila ia belum mempunyai akun/kolom; tampilkan kebutuhan tindak
  lanjut.
- Setiap pekerjaan wajib mempunyai tindakan yang jelas, output/deliverable,
  kriteria selesai, PIC, kolom Kanban, dan tenggat dalam Q3.

Jangan membuat kartu Kanban sebelum pratinjau diperiksa dan disetujui manusia.

## Verifikasi deploy

Jalankan pada server:

```bash
cd ~/domains/skinku.id/laravel-b2b
git log -1 --oneline
git merge-base --is-ancestor cb10fe6 HEAD && echo "OK: memuat cb10fe6"
/opt/alt/php83/usr/bin/php artisan migrate:status
```

Setelah kode sumber data baru ditarik:

```bash
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear
```

Generate prompt satu kali dan jangan approve sampai panel “Checklist kelayakan
pratinjau” seluruhnya hijau.
