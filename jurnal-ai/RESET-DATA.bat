@echo off
chcp 65001 >nul
title Jurnal AI - Reset Data
cd /d "%~dp0"
echo.
echo   Ini akan MENGHAPUS semua klien, jurnal, dan dokumen,
echo   lalu mengisi ulang dengan data contoh.
echo.
pause
echo.
if exist database\database.sqlite del /q database\database.sqlite
type nul > database\database.sqlite
php artisan migrate --force
php artisan db:seed --force
php artisan db:seed --class=DemoSeeder --force
echo.
echo   Selesai. Jalankan JALANKAN.bat untuk mulai lagi.
echo.
pause
