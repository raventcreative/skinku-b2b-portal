@echo off
chcp 65001 >nul
title Jurnal AI - Server Lokal
cd /d "%~dp0"

echo.
echo   ============================================
echo     JURNAL AI - Pencatatan Jurnal dengan AI
echo   ============================================
echo.

where php >nul 2>nul
if errorlevel 1 goto no_php

php -r "exit(PHP_VERSION_ID < 80300 ? 1 : 0);" 2>nul
if errorlevel 1 goto old_php

php -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);" 2>nul
if errorlevel 1 goto no_sqlite

echo   [OK] PHP siap.
echo.
echo   Membuka browser otomatis dalam 3 detik...
echo.
echo   Alamat : http://127.0.0.1:8001/login
echo   Email  : admin@jurnal.test
echo   Sandi  : password123
echo.
echo   JANGAN TUTUP jendela ini selama testing.
echo   Kalau sudah selesai, tekan Ctrl+C atau tutup jendelanya.
echo.

start "" powershell -NoProfile -WindowStyle Hidden -Command "Start-Sleep 3; Start-Process 'http://127.0.0.1:8001/login'"
php artisan serve --host=127.0.0.1 --port=8001
goto end

:no_php
echo   [X] PHP tidak ditemukan di komputer ini.
echo.
echo       Pasang dulu PHP 8.3 atau lebih baru.
echo       Cara paling gampang: install Laragon dari https://laragon.org
echo       ^(sudah termasuk PHP^), lalu jalankan file ini lagi.
echo.
goto end

:old_php
echo   [X] Versi PHP terlalu lama. Butuh 8.3 atau lebih baru.
echo.
php -v
echo.
echo       Update PHP-nya, lalu jalankan file ini lagi.
echo.
goto end

:no_sqlite
echo   [X] Ekstensi pdo_sqlite belum aktif di PHP.
echo.
echo       File php.ini yang sedang dipakai PHP:
php --ini
echo.
echo       Cara mengaktifkan:
echo       1. Buka file php.ini yang disebut di baris "Loaded Configuration File" di atas
echo       2. Cari baris:  ;extension=pdo_sqlite
echo       3. Hapus tanda titik koma ^(;^) di depannya, lalu simpan
echo       4. Jalankan file ini lagi
echo.
echo       Kalau barisnya tidak ada sama sekali, tambahkan sendiri:
echo         extension=pdo_sqlite
echo.
goto end

:end
pause
