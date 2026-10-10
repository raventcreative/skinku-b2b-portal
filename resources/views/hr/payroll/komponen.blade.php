@extends('layouts.app')
@section('title', 'Data gaji karyawan')
@section('heading', 'Payroll')

@section('content')
{{-- Pesan sukses/gagal & error validasi ditampilkan layout (tak diulang di sini). --}}
@php
    use App\Models\PayrollProfile;
    use App\Services\PayrollService;

    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    $angka = 'w-32 px-2 py-1.5 text-sm text-right border border-stone-300 rounded-lg';
    $input = 'mt-1 w-full px-3 py-2 text-sm border border-stone-300 rounded-lg';
    $label = 'block text-xs font-semibold text-stone-600';
    $kunci = $bolehKelola ? '' : 'disabled';
@endphp
<div class="space-y-4">
    <a href="{{ route('hr.payroll.index') }}" class="text-sm text-stone-500 hover:text-stone-800">← Payroll</a>

    <div>
        <h2 class="text-lg font-bold text-stone-800">Data gaji karyawan</h2>
        <p class="text-sm text-stone-500">Gaji pokok &amp; tunjangan tetap per bulan (keduanya jadi dasar upah BPJS). Status PTKP diubah di data karyawan. Karyawan dengan gaji kosong tidak ikut payroll.</p>
    </div>

    @if($karyawan->isEmpty())
        <div class="bg-white rounded-2xl border border-stone-200 px-5 py-10 text-center text-sm text-stone-400">Belum ada karyawan aktif — tambahkan dulu di menu HR → Karyawan.</div>
    @else
        <form method="POST" action="{{ route('hr.payroll.komponen.update') }}" class="space-y-3">
            @csrf @method('PUT')
            <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-stone-50 text-xs text-stone-500 border-b border-stone-200">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">Karyawan</th>
                            <th class="px-2 py-2 text-left font-medium">PTKP</th>
                            <th class="px-2 py-2 text-right font-medium">Gaji pokok</th>
                            <th class="px-2 py-2 text-right font-medium">Tunjangan tetap</th>
                            <th class="px-2 py-2 text-center font-medium">BPJS Kesehatan</th>
                            <th class="px-2 py-2 text-center font-medium">BPJS TK<div class="font-normal text-[10px]">JHT, JKK, JKM</div></th>
                            <th class="px-2 py-2 text-center font-medium">Jaminan Pensiun</th>
                            <th class="px-4 py-2 text-left font-medium">Kelompok biaya</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                    @foreach($karyawan as $e)
                        @php $p = $e->payrollProfile; $k = "komponen.{$e->id}"; $n = "komponen[{$e->id}]"; @endphp
                        <tr class="hover:bg-stone-50">
                            <td class="px-4 py-2">
                                <div class="font-semibold text-stone-800">{{ $e->name }}</div>
                                <div class="text-[11px] text-stone-400">{{ $e->kode }}{{ $e->position ? ' · '.$e->position : '' }}</div>
                            </td>
                            <td class="px-2 py-2 {{ $e->ptkp_status ? 'text-stone-600' : 'text-amber-700' }}">{{ $e->ptkp_status ?: 'belum diisi' }}</td>
                            <td class="px-2 py-2 text-right"><input type="number" name="{{ $n }}[base_salary]" value="{{ old("$k.base_salary", $p?->base_salary ?? 0) }}" min="0" step="1" required {{ $kunci }} aria-label="Gaji pokok {{ $e->name }}" class="{{ $angka }}"></td>
                            <td class="px-2 py-2 text-right"><input type="number" name="{{ $n }}[fixed_allowance]" value="{{ old("$k.fixed_allowance", $p?->fixed_allowance ?? 0) }}" min="0" step="1" required {{ $kunci }} aria-label="Tunjangan tetap {{ $e->name }}" class="{{ $angka }}"></td>
                            @foreach(['bpjs_kesehatan' => 'BPJS Kesehatan', 'bpjs_tk' => 'BPJS Ketenagakerjaan', 'bpjs_jp' => 'Jaminan Pensiun'] as $f => $nama)
                                <td class="px-2 py-2 text-center">
                                    <input type="hidden" name="{{ $n }}[{{ $f }}]" value="0">
                                    <input type="checkbox" name="{{ $n }}[{{ $f }}]" value="1" @checked(old("$k.$f", $p?->$f ?? true)) {{ $kunci }} aria-label="{{ $nama }} {{ $e->name }}" class="w-4 h-4">
                                </td>
                            @endforeach
                            <td class="px-4 py-2">
                                <select name="{{ $n }}[cost_group]" {{ $kunci }} aria-label="Kelompok biaya {{ $e->name }}" class="px-2 py-1.5 text-sm border border-stone-300 rounded-lg">
                                    @foreach(PayrollProfile::COST_GROUPS as $v => $l)<option value="{{ $v }}" @selected(old("$k.cost_group", $p?->cost_group ?? 'operasional') === $v)>{{ $l }}</option>@endforeach
                                </select>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>
            @if($bolehKelola)
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-[11px] text-stone-400">Isi angka bulat tanpa titik, mis. 5500000. Kelompok biaya Produksi → dijurnal ke Beban &amp; Hutang Gaji Produksi (HPP). Draf payroll yang sudah ada tidak ikut berubah sampai diklik "Ambil ulang data gaji".</p>
                    <button class="px-5 py-2.5 text-sm font-semibold bg-red-700 text-white rounded-lg hover:bg-red-800">Simpan data gaji</button>
                </div>
            @endif
        </form>
    @endif

    <div class="grid lg:grid-cols-2 gap-4 items-start">
        <form method="POST" action="{{ route('hr.payroll.setelan.update') }}" class="bg-white rounded-2xl border border-stone-200 p-5 space-y-3">
            @csrf @method('PUT')
            <h3 class="text-sm font-bold text-stone-800">Setelan BPJS</h3>
            <div class="grid sm:grid-cols-2 gap-3">
                <label class="{{ $label }}">Batas upah BPJS Kesehatan (Rp)<input type="number" name="kes_cap" value="{{ old('kes_cap', $setelan['kes_cap']) }}" min="1000000" step="1" required {{ $kunci }} class="{{ $input }}"></label>
                <label class="{{ $label }}">Batas upah Jaminan Pensiun (Rp)<input type="number" name="jp_cap" value="{{ old('jp_cap', $setelan['jp_cap']) }}" min="1000000" step="1" required {{ $kunci }} class="{{ $input }}"></label>
                <label class="{{ $label }} sm:col-span-2">Tarif JKK (tingkat risiko usaha)
                    <select name="jkk_bps" {{ $kunci }} class="{{ $input }}">
                        @foreach(PayrollService::JKK as $v => $l)<option value="{{ $v }}" @selected((int) old('jkk_bps', $setelan['jkk_bps']) === $v)>{{ $l }}</option>@endforeach
                    </select>
                </label>
            </div>
            <p class="text-[11px] text-stone-400">Batas upah JP disesuaikan BPJS Ketenagakerjaan tiap Maret (2026: Rp11.086.300); batas BPJS Kesehatan Rp12.000.000 (Perpres 64/2020). Tarif JKK mengikuti kelompok risiko yang ditetapkan BPJS untuk perusahaan.</p>
            @if($bolehKelola)<button class="px-4 py-2 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Simpan setelan</button>@endif
        </form>

        <div class="bg-white rounded-2xl border border-stone-200 p-5 text-sm text-stone-600 space-y-2">
            <h3 class="text-sm font-bold text-stone-800">Cara hitung</h3>
            <ul class="list-disc pl-5 space-y-1 text-xs">
                <li><b>BPJS</b> dari gaji pokok + tunjangan tetap: Kesehatan 4% perusahaan + 1% karyawan · JHT 3,7% + 2% · JP 2% + 1% · JKK &amp; JKM 0,3% ditanggung perusahaan.</li>
                <li><b>PPh 21 Januari–November</b>: tarif efektif rata-rata (TER, PP 58/2023) × penghasilan bruto. Kategori TER dari status PTKP (A: TK/0, TK/1, K/0 · B: TK/2, TK/3, K/1, K/2 · C: K/3).</li>
                <li><b>Bruto</b> = gaji + tunjangan + lembur + bonus + BPJS Kesehatan 4%, JKK &amp; JKM yang dibayar perusahaan.</li>
                <li><b>Desember / bulan terakhir bekerja</b>: PPh 21 setahun dihitung ulang (tarif Pasal 17, dikurangi biaya jabatan, iuran JHT &amp; JP karyawan, PTKP) lalu dikurangi yang sudah dipotong — kalau minus, kelebihannya dikembalikan lewat gaji.</li>
                <li>PPh 21 bisa dikoreksi manual per karyawan di halaman payroll.</li>
            </ul>
        </div>
    </div>

    @if($karyawan->isNotEmpty())
        <details class="bg-white rounded-2xl border border-stone-200" @if($errors->has('saldo.*') || $errors->has('tahun')) open @endif>
            <summary class="px-5 py-3 cursor-pointer text-sm font-bold text-stone-800">Saldo awal tahun (bulan yang digaji di luar portal)</summary>
            <form method="POST" action="{{ route('hr.payroll.saldo-awal.update') }}" class="px-5 pb-5 space-y-3">
                @csrf @method('PUT')
                <p class="text-xs text-stone-500">Isi sekali saja bila tahun ini ada bulan yang gajinya diproses di luar portal (mis. Januari–September), supaya PPh 21 setahun di Desember benar. Angka diambil dari catatan payroll lama: jumlah bulan, total penghasilan bruto, total iuran JHT 2% + JP 1% bagian karyawan, dan total PPh 21 yang sudah dipotong.</p>
                <label class="{{ $label }} max-w-[200px]">Tahun<input type="number" name="tahun" value="{{ old('tahun', $tahun) }}" min="2024" max="2100" required {{ $kunci }} class="{{ $input }}"></label>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-stone-500 border-b border-stone-200">
                        <tr>
                            <th class="py-2 pr-2 text-left font-medium">Karyawan</th>
                            <th class="px-2 py-2 text-right font-medium">Jumlah bulan</th>
                            <th class="px-2 py-2 text-right font-medium">Penghasilan bruto</th>
                            <th class="px-2 py-2 text-right font-medium">Iuran JHT + JP karyawan</th>
                            <th class="px-2 py-2 text-right font-medium">PPh 21 dipotong</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                    @foreach($karyawan as $e)
                        @php $p = $e->payrollProfile; $ada = $p && $p->opening_year === $tahun; $k = "saldo.{$e->id}"; $n = "saldo[{$e->id}]"; @endphp
                        <tr>
                            <td class="py-2 pr-2 text-stone-800">{{ $e->name }}</td>
                            <td class="px-2 py-2 text-right"><input type="number" name="{{ $n }}[months]" value="{{ old("$k.months", $ada ? $p->opening_months : '') }}" min="0" max="11" step="1" {{ $kunci }} aria-label="Jumlah bulan {{ $e->name }}" class="w-20 px-2 py-1.5 text-sm text-right border border-stone-300 rounded-lg"></td>
                            @foreach(['bruto' => 'opening_bruto', 'iuran' => 'opening_iuran', 'pph21' => 'opening_pph21'] as $f => $kolom)
                                <td class="px-2 py-2 text-right"><input type="number" name="{{ $n }}[{{ $f }}]" value="{{ old("$k.$f", $ada ? $p->$kolom : '') }}" min="0" step="1" {{ $kunci }} aria-label="{{ $f }} {{ $e->name }}" class="{{ $angka }}"></td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
                @if($bolehKelola)<button class="px-4 py-2 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Simpan saldo awal</button>@endif
            </form>
        </details>
    @endif
</div>
@endsection
