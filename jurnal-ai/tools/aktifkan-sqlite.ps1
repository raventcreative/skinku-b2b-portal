# Mengaktifkan ekstensi pdo_sqlite + sqlite3 di php.ini.
# Selalu membuat cadangan lebih dulu; tidak pernah menimpa tanpa backup.

$ErrorActionPreference = 'Stop'

function Tulis($teks, $warna = 'Gray') { Write-Host $teks -ForegroundColor $warna }

Tulis ''
Tulis '  Mengaktifkan pdo_sqlite di PHP' 'Cyan'
Tulis '  ------------------------------' 'Cyan'
Tulis ''

# 1. Temukan php.ini yang sedang dipakai.
$baris = (& php --ini 2>&1 | Select-String 'Loaded Configuration File')
if (-not $baris) { Tulis '  [X] Tidak bisa menjalankan php. Pastikan PHP ada di PATH.' 'Red'; exit 1 }

$ini = ($baris.ToString() -replace '.*Loaded Configuration File:\s*', '').Trim()

if ($ini -eq '(none)' -or [string]::IsNullOrWhiteSpace($ini)) {
    # PHP belum punya php.ini sama sekali - buat dari contoh bawaan.
    $dir = Split-Path (Get-Command php).Source
    $contoh = @("$dir\php.ini-development", "$dir\php.ini-production") | Where-Object { Test-Path $_ } | Select-Object -First 1
    if (-not $contoh) { Tulis "  [X] php.ini tidak ada dan contoh bawaannya juga tidak ditemukan di $dir" 'Red'; exit 1 }
    $ini = "$dir\php.ini"
    Copy-Item $contoh $ini
    Tulis "  php.ini belum ada, dibuat baru dari: $contoh" 'Yellow'
}

if (-not (Test-Path $ini)) { Tulis "  [X] File tidak ditemukan: $ini" 'Red'; exit 1 }
Tulis "  File  : $ini"

# 2. Cadangkan.
$backup = "$ini.backup-" + (Get-Date -Format 'yyyyMMdd-HHmmss')
Copy-Item $ini $backup -Force
Tulis "  Backup: $backup" 'DarkGray'
Tulis ''

# 3. Aktifkan extension_dir + ekstensi sqlite.
$isi = Get-Content $ini -Raw
$asli = $isi
$dirPhp = Split-Path (Get-Command php).Source

# extension_dir wajib aktif, kalau tidak semua extension=... diabaikan.
if ($isi -notmatch '(?m)^\s*extension_dir\s*=') {
    if ($isi -match '(?m)^\s*;\s*extension_dir\s*=\s*"ext"') {
        $isi = $isi -replace '(?m)^\s*;\s*(extension_dir\s*=\s*"ext")', '$1'
    } else {
        $isi = $isi.TrimEnd() + "`r`n`r`nextension_dir = `"$dirPhp\ext`"`r`n"
    }
    Tulis '  + extension_dir diaktifkan' 'Green'
}

foreach ($ext in @('pdo_sqlite', 'sqlite3')) {
    if ($isi -match "(?m)^\s*extension\s*=\s*$ext\s*$") {
        Tulis "  = extension=$ext sudah aktif" 'DarkGray'
        continue
    }
    if ($isi -match "(?m)^\s*;\s*extension\s*=\s*$ext\s*$") {
        $isi = $isi -replace "(?m)^\s*;\s*(extension\s*=\s*$ext)\s*$", '$1'
    } else {
        $isi = $isi.TrimEnd() + "`r`nextension=$ext`r`n"
    }
    Tulis "  + extension=$ext diaktifkan" 'Green'
}

if ($isi -ne $asli) {
    Set-Content -Path $ini -Value $isi -NoNewline -Encoding UTF8
} else {
    Tulis '  (tidak ada yang perlu diubah)' 'DarkGray'
}

# 4. Verifikasi.
Tulis ''
& php -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);" 2>$null
if ($LASTEXITCODE -eq 0) {
    Tulis '  [OK] pdo_sqlite sekarang AKTIF.' 'Green'
    Tulis ''
    Tulis '  Tutup jendela ini, lalu klik dua kali JALANKAN.bat' 'Cyan'
    exit 0
}

Tulis '  [X] Masih belum aktif. Kemungkinan file DLL-nya tidak ada.' 'Red'
Tulis ''
Tulis "  Cek apakah file ini ada: $dirPhp\ext\php_pdo_sqlite.dll"
if (Test-Path "$dirPhp\ext\php_pdo_sqlite.dll") {
    Tulis '  DLL-nya ADA. Coba restart PowerShell lalu jalankan JALANKAN.bat lagi.' 'Yellow'
} else {
    Tulis '  DLL-nya TIDAK ADA - PHP dari winget ini tidak menyertakan SQLite.' 'Yellow'
    Tulis '  Jalan paling gampang: install Laragon (https://laragon.org), PHP-nya lengkap.' 'Yellow'
}
Tulis ''
Tulis "  Kalau mau membatalkan perubahan, timpa php.ini dengan: $backup" 'DarkGray'
exit 1
