{{-- Task 10: file HTML laporan Ads yang dikirim balik ke Telegram (sendDocument).
     Bukan halaman in-app (tidak extends layouts.app) — dokumen HTML mandiri, port
     dari node n8n "Code in Generate HTML1" (CSS & label kolom apa adanya), tapi
     disederhanakan mengikuti struktur leads.blade.php: SATU section (bukan
     @foreach per-CS seperti Leads — Ads cuma 1 laporan per file yang diupload),
     judul statis "LAPORAN ADS" (sumber aslinya menyisipkan brand+rentang Day hasil
     scan teks balasan AI ke judul; sengaja tidak diikuti, lihat dokblok
     AdsReportFlow — itu murni kosmetik, bukan isi laporan).
     $daily/$summary: hasil AdsReportFlow::splitReport() dari output naratif
     ReportAi::analyze(); escaping via e()+nl2br() setara escapeHtml()+\n→<br>
     sisi n8n, sama seperti leads.blade.php. --}}
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Laporan Ads</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      font-size: 13px;
      margin: 0;
      padding: 16px;
    }
    h2 {
      text-align: center;
      margin-bottom: 24px;
    }
    .page {
      margin-bottom: 32px;
      page-break-after: always;
    }
    .container {
      display: flex;
      gap: 16px;
    }
    .column {
      width: 50%;
      border: 1px solid #ccc;
      padding: 12px;
      border-radius: 6px;
    }
    .header {
      font-weight: bold;
      background: #f3f3f3;
      padding: 6px;
      border-radius: 4px;
      margin-bottom: 10px;
    }
  </style>
</head>
<body>

<h2>LAPORAN ADS</h2>

<div class="page">
  <div class="container">
    <div class="column">
      <div class="header">📅 DAILY REPORT</div>
      {!! nl2br(e($daily), false) !!}
    </div>
    <div class="column">
      <div class="header">📈 SUMMARY</div>
      {!! $summary !== '' ? nl2br(e($summary), false) : '<i>Tidak ada summary</i>' !!}
    </div>
  </div>
</div>

</body>
</html>
