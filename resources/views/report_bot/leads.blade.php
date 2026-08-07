{{-- Task 9: file HTML laporan Leads yang dikirim balik ke Telegram (sendDocument).
     Bukan halaman in-app (tidak extends layouts.app) — ini dokumen HTML mandiri,
     port dari node n8n "Code in Generate HTML" (markup, CSS, & label header apa
     adanya, termasuk 📊 vs 📈 yang memang beda emoji di sumber aslinya).
     $sections: list of {csName, daily, summary} — daily/summary sudah dipecah
     LeadsReportFlow::splitReport() dari output naratif ReportAi::analyze tiap CS. --}}
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Laporan Kinerja CS</title>
  <style>
    body {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 12px;
    }
    h2 {
      text-align: center;
      margin-bottom: 20px;
    }
    .page {
      page-break-after: always;
      margin-bottom: 24px;
    }
    .container {
      display: flex;
      gap: 16px;
    }
    .column {
      width: 50%;
      border: 1px solid #ccc;
      padding: 12px;
      box-sizing: border-box;
    }
    .header {
      font-weight: bold;
      margin-bottom: 8px;
      border-bottom: 1px solid #ccc;
      padding-bottom: 4px;
    }
  </style>
</head>
<body>

<h2>LAPORAN KINERJA CS</h2>

@foreach ($sections as $section)
  <div class="page">
    <div class="container">
      <div class="column">
        <div class="header">📊 LAPORAN HARIAN</div>
        {!! nl2br(e($section['daily']), false) !!}
      </div>
      <div class="column">
        <div class="header">📈 LAPORAN SUMMARY</div>
        {!! $section['summary'] !== '' ? nl2br(e($section['summary']), false) : 'Tidak ada data summary' !!}
      </div>
    </div>
  </div>
@endforeach

</body>
</html>
