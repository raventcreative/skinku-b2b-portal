@echo off
chcp 65001 >nul
title Jurnal AI - Aktifkan SQLite
cd /d "%~dp0"
echo.
echo   Script ini akan mengaktifkan ekstensi pdo_sqlite di php.ini kamu.
echo   File php.ini akan DICADANGKAN dulu sebelum diubah.
echo.
pause
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\aktifkan-sqlite.ps1"
echo.
pause
