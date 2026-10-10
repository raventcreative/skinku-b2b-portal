@php
    $rp = fn ($n) => ((int) $n < 0 ? '−' : '').'Rp '.number_format(abs((int) $n), 0, ',', '.');
    $persen = fn (int $bps) => str_replace('.', ',', (string) ($bps / 100)).'%';
    $draf = ! $run->dikunci();
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Slip gaji {{ $run->label() }}{{ $items->count() === 1 ? ' — '.$items->first()->employee_name : '' }}</title>
    {{-- Slip gaji (cetak → Simpan PDF dari browser, tanpa paket tambahan). Satu karyawan satu halaman. --}}
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 0; font-size: 12px; }
        .slip { max-width: 720px; margin: 0 auto 12px; padding: 10mm; background: #fff; }
        .slip + .slip { break-before: page; page-break-before: always; }
        .kepala { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 10px; }
        .kepala img { height: 40px; width: auto; }
        .judul { text-align: right; }
        .judul b { display: block; font-size: 16px; letter-spacing: .06em; }
        .muted { color: #555; }
        .info { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 24px; margin-bottom: 12px; }
        .info div { display: flex; justify-content: space-between; gap: 8px; border-bottom: 1px dotted #ccc; padding: 2px 0; }
        .kolom { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        h3 { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; margin: 0 0 4px; color: #333; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 0; vertical-align: top; }
        td.n { text-align: right; white-space: nowrap; }
        tr.total td { border-top: 1px solid #111; font-weight: 700; padding-top: 5px; }
        .bersih { margin-top: 14px; padding: 10px 12px; border: 2px solid #111; display: flex; justify-content: space-between; align-items: center; font-size: 15px; font-weight: 700; }
        .kecil { font-size: 10px; color: #555; margin-top: 10px; line-height: 1.5; }
        .draf { color: #b45309; font-weight: 700; }
        .alat { max-width: 720px; margin: 0 auto 12px; display: flex; gap: 12px; align-items: center; }
        .alat button { padding: 8px 16px; font-size: 13px; font-weight: 700; background: #b91c1c; color: #fff; border: 0; border-radius: 8px; cursor: pointer; }
        @media screen { body { background: #e7e5e4; padding: 16px; } .slip { box-shadow: 0 1px 4px rgba(0,0,0,.15); } }
        @media print { .alat { display: none; } .slip { padding: 0; box-shadow: none; } }
    </style>
</head>
<body>
<div class="alat">
    <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
    @if($draf)<span class="draf">DRAF — angka belum final sampai payroll dikunci.</span>@endif
</div>

@foreach($items as $i)
    <div class="slip">
        <div class="kepala">
            <img src="{{ asset('img/skinku-logo.jpg') }}" alt="SKINKU">
            <div class="judul">
                <b>SLIP GAJI{{ $draf ? ' (DRAF)' : '' }}</b>
                <span>{{ $run->label() }}</span>
                <div class="muted">Rahasia — hanya untuk karyawan ybs.</div>
            </div>
        </div>

        <div class="info">
            <div><span class="muted">Nama</span><b>{{ $i->employee_name }}</b></div>
            <div><span class="muted">Kode</span><span>{{ $i->employee?->kode ?? '—' }}</span></div>
            <div><span class="muted">Jabatan</span><span>{{ $i->position ?: '—' }}</span></div>
            <div><span class="muted">Status PTKP</span><span>{{ $i->ptkp_status }}</span></div>
        </div>

        <div class="kolom">
            <div>
                <h3>Penghasilan</h3>
                <table>
                    <tr><td>Gaji pokok</td><td class="n">{{ $rp($i->base_salary) }}</td></tr>
                    @if($i->fixed_allowance)<tr><td>Tunjangan tetap</td><td class="n">{{ $rp($i->fixed_allowance) }}</td></tr>@endif
                    @if($i->overtime)<tr><td>Lembur</td><td class="n">{{ $rp($i->overtime) }}</td></tr>@endif
                    @if($i->bonus)<tr><td>Bonus / THR</td><td class="n">{{ $rp($i->bonus) }}</td></tr>@endif
                    <tr class="total"><td>Total penghasilan</td><td class="n">{{ $rp($i->penghasilan()) }}</td></tr>
                </table>
            </div>
            <div>
                <h3>Potongan</h3>
                <table>
                    @if($i->kes_employee)<tr><td>BPJS Kesehatan (1%)</td><td class="n">{{ $rp($i->kes_employee) }}</td></tr>@endif
                    @if($i->jht_employee)<tr><td>BPJS JHT (2%)</td><td class="n">{{ $rp($i->jht_employee) }}</td></tr>@endif
                    @if($i->jp_employee)<tr><td>BPJS Jaminan Pensiun (1%)</td><td class="n">{{ $rp($i->jp_employee) }}</td></tr>@endif
                    <tr>
                        <td>PPh 21 {{ $i->pph21 < 0 ? '(lebih potong, dikembalikan)' : ($i->annual ? '(hitung setahun)' : '(TER '.$persen($i->ter_rate).')') }}</td>
                        <td class="n">{{ $rp($i->pph21) }}</td>
                    </tr>
                    @if($i->kasbon)<tr><td>Kasbon</td><td class="n">{{ $rp($i->kasbon) }}</td></tr>@endif
                    <tr class="total"><td>Total potongan</td><td class="n">{{ $rp($i->totalPotongan()) }}</td></tr>
                </table>
            </div>
        </div>

        <div class="bersih"><span>GAJI BERSIH DITERIMA</span><span>{{ $rp($i->net_pay) }}</span></div>

        @if($i->notes)<p class="kecil"><b>Catatan:</b> {{ $i->notes }}</p>@endif
        <p class="kecil">
            Ditanggung perusahaan (tidak dipotong dari gaji): BPJS Kesehatan 4% {{ $rp($i->kes_company) }} · JHT 3,7% {{ $rp($i->jht_company) }} ·
            JP 2% {{ $rp($i->jp_company) }} · JKK {{ $rp($i->jkk) }} · JKM {{ $rp($i->jkm) }}.<br>
            Penghasilan bruto untuk PPh 21: {{ $rp($i->bruto) }} (termasuk premi BPJS Kesehatan, JKK &amp; JKM yang dibayar perusahaan).<br>
            Dicetak {{ now()->translatedFormat('d F Y H:i') }} dari portal SKINKU — dokumen dibuat sistem, sah tanpa tanda tangan.
        </p>
    </div>
@endforeach
</body>
</html>
