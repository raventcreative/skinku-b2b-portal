@extends('layouts.app')
@section('title', 'Payroll '.$run->label())
@section('heading', 'Payroll')

@section('content')
{{-- Pesan sukses/gagal & error validasi ditampilkan layout (tak diulang di sini). --}}
@php
    $rp = fn ($n) => ((int) $n < 0 ? '−' : '').'Rp'.number_format(abs((int) $n), 0, ',', '.');
    $persen = fn (int $bps) => str_replace('.', ',', (string) ($bps / 100)).'%';
    $draf = ! $run->dikunci();
    $ubah = $draf && $bolehKelola;
    $angka = 'w-24 px-2 py-1 text-sm text-right border border-stone-300 rounded-lg';
    $total = [
        'base_salary' => $items->sum('base_salary'), 'fixed_allowance' => $items->sum('fixed_allowance'),
        'overtime' => $items->sum('overtime'), 'bonus' => $items->sum('bonus'),
        'penghasilan' => $items->sum(fn ($i) => $i->penghasilan()), 'bpjs_karyawan' => $items->sum(fn ($i) => $i->bpjsKaryawan()),
        'bpjs_perusahaan' => $items->sum(fn ($i) => $i->bpjsPerusahaan()), 'pph21' => $items->sum('pph21'),
        'kasbon' => $items->sum('kasbon'), 'bersih' => $items->sum('net_pay'),
    ];
    $kartu = [
        ['Karyawan', (string) $items->count()],
        ['Total penghasilan', $rp($total['penghasilan'])],
        ['PPh 21 dipotong', $rp($total['pph21'])],
        ['BPJS karyawan + perusahaan', $rp($total['bpjs_karyawan'] + $total['bpjs_perusahaan'])],
        ['Gaji bersih ditransfer', $rp($total['bersih'])],
        ['Biaya perusahaan', $rp($total['penghasilan'] + $total['bpjs_perusahaan'])],
    ];
    // Dua isian per kolom (ditumpuk) supaya tabel muat tanpa geser ke samping.
    $kolom = [
        'Gaji pokok / tunjangan' => ['base_salary' => 'pokok', 'fixed_allowance' => 'tunjangan'],
        'Lembur / bonus' => ['overtime' => 'lembur', 'bonus' => 'bonus/THR'],
    ];
@endphp
<div class="space-y-4">
    <a href="{{ route('hr.payroll.index') }}" class="text-sm text-stone-500 hover:text-stone-800">← Semua payroll</a>

    <div class="bg-white rounded-2xl border border-stone-200 p-5 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-bold text-stone-800">Payroll {{ $run->label() }}</h2>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-stone-500">
                <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $draf ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700' }}">{{ $draf ? 'Draf — angka masih bisa berubah' : 'Dikunci' }}</span>
                @unless($draf)
                    <span>{{ $run->locked_at?->translatedFormat('d M Y H:i') }}{{ $run->locker ? ' oleh '.$run->locker->displayName() : '' }}</span>
                    @if($run->journal)
                        <span>·
                            @if($lihatJurnal)
                                <a href="{{ route('accounting.journals', ['period' => $run->period->format('Y-m')]) }}" class="text-red-700 hover:underline">Jurnal {{ $run->journal->reference }}</a>
                            @else
                                Jurnal {{ $run->journal->reference }}
                            @endif
                        </span>
                    @endif
                @endunless
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if($items->isNotEmpty())
                <a href="{{ route('hr.payroll.slip', $run) }}" target="_blank" rel="noopener" class="px-4 py-2 text-sm font-semibold rounded-lg {{ $draf ? 'bg-stone-100 text-stone-700 hover:bg-stone-200' : 'bg-red-700 text-white hover:bg-red-800' }}">{{ $draf ? 'Pratinjau slip' : 'Cetak semua slip' }}</a>
            @endif
            @if($bolehKelola)
                @if($draf)
                    <form method="POST" action="{{ route('hr.payroll.ambil-ulang', $run) }}">@csrf
                        <button class="px-4 py-2 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Ambil ulang data gaji</button>
                    </form>
                    <form method="POST" action="{{ route('hr.payroll.kunci', $run) }}" onsubmit="return confirm(@js('Kunci payroll '.$run->label().'? Angka dibekukan, jurnal dicatat ke Akuntansi, dan slip jadi final.'))">@csrf
                        <button class="px-4 py-2 text-sm font-semibold bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">Kunci payroll</button>
                    </form>
                    <form method="POST" action="{{ route('hr.payroll.destroy', $run) }}" onsubmit="return confirm('Hapus draf payroll ini?')">@csrf @method('DELETE')
                        <button class="px-3 py-2 text-sm text-rose-600 hover:underline">Hapus draf</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('hr.payroll.buka-kunci', $run) }}" onsubmit="return confirm('Buka kunci? Jurnal payroll ini akan di-void dan payroll kembali draf.')">@csrf
                        <button class="px-4 py-2 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Buka kunci</button>
                    </form>
                @endif
            @endif
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3">
        @foreach($kartu as [$label, $nilai])
            <div class="bg-white rounded-2xl border border-stone-200 p-4">
                <p class="text-xs text-stone-500">{{ $label }}</p>
                <p class="text-lg font-bold text-stone-800 tabular-nums">{{ $nilai }}</p>
            </div>
        @endforeach
    </div>

    @if($peringatan)
        <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            <p class="font-semibold mb-1">Cek dulu sebelum dikunci:</p>
            <ul class="list-disc pl-5 space-y-0.5">@foreach($peringatan as $p)<li>{{ $p }}</li>@endforeach</ul>
        </div>
    @endif

    @if($items->isEmpty())
        <div class="bg-white rounded-2xl border border-stone-200 px-5 py-10 text-center text-sm text-stone-400">
            Belum ada karyawan di payroll ini. Isi gaji di <a href="{{ route('hr.payroll.komponen') }}" class="text-red-700 font-semibold hover:underline">Data gaji karyawan</a>, lalu klik "Ambil ulang data gaji".
        </div>
    @else
        @if($ubah)<form method="POST" action="{{ route('hr.payroll.update', $run) }}" class="space-y-3">@csrf @method('PUT')@endif
        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-stone-50 text-xs text-stone-500 border-b border-stone-200">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">Karyawan</th>
                        @foreach(array_keys($kolom) as $judul)<th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ $judul }}</th>@endforeach
                        <th class="px-2 py-2 text-right font-medium whitespace-nowrap">BPJS dipotong</th>
                        <th class="px-2 py-2 text-right font-medium whitespace-nowrap">PPh 21</th>
                        <th class="px-2 py-2 text-right font-medium whitespace-nowrap">Kasbon</th>
                        <th class="px-4 py-2 text-right font-medium whitespace-nowrap">Diterima</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                @foreach($items as $i)
                    @php $k = "item.{$i->id}"; $n = "item[{$i->id}]"; @endphp
                    <tr class="align-top hover:bg-stone-50">
                        <td class="px-4 py-3 min-w-40">
                            <div class="font-semibold text-stone-800">{{ $i->employee_name }}</div>
                            <div class="text-[11px] text-stone-400">{{ $i->position ?: '—' }} · {{ $i->ptkp_status }} · TER {{ $i->ter_category }}{{ $i->cost_group === 'produksi' ? ' · Produksi' : '' }}</div>
                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                @if($i->annual)<span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[10px] font-semibold bg-sky-50 text-sky-700">PPh hitung setahun</span>@endif
                                <a href="{{ route('hr.payroll.slip.item', [$run, $i]) }}" target="_blank" rel="noopener" class="text-[11px] text-red-700 hover:underline">Slip</a>
                            </div>
                            @if($ubah)
                                <input name="{{ $n }}[notes]" value="{{ old("$k.notes", $i->notes) }}" maxlength="255" placeholder="Catatan di slip (opsional)" aria-label="Catatan {{ $i->employee_name }}" class="mt-1 w-full px-2 py-1 text-xs border border-stone-200 rounded-lg">
                            @elseif($i->notes)
                                <div class="mt-1 text-[11px] text-stone-500">{{ $i->notes }}</div>
                            @endif
                        </td>
                        @foreach($kolom as $pasangan)
                            <td class="px-2 py-3 text-right tabular-nums">
                                <div class="flex flex-col items-end gap-1">
                                    @foreach($pasangan as $f => $sebutan)
                                        @if($ubah)
                                            <label class="flex items-center gap-1 text-[10px] text-stone-400">{{ $sebutan }}
                                                <input type="number" name="{{ $n }}[{{ $f }}]" value="{{ old("$k.$f", $i->$f) }}" min="0" step="1" aria-label="{{ $sebutan }} {{ $i->employee_name }}" class="{{ $angka }}">
                                            </label>
                                        @elseif($loop->first || $i->$f)
                                            <div class="{{ $loop->first ? 'text-stone-800' : 'text-[11px] text-stone-500' }}">{{ $loop->first ? '' : $sebutan.' ' }}{{ $rp($i->$f) }}</div>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                        @endforeach
                        <td class="px-2 py-3 text-right tabular-nums" title="Kesehatan {{ $rp($i->kes_employee) }} · JHT {{ $rp($i->jht_employee) }} · JP {{ $rp($i->jp_employee) }}">{{ $rp($i->bpjsKaryawan()) }}</td>
                        <td class="px-2 py-3 text-right tabular-nums">
                            <div class="{{ $i->pph21 < 0 ? 'font-semibold text-emerald-700' : '' }}">{{ $rp($i->pph21) }}</div>
                            <div class="text-[10px] text-stone-400 whitespace-nowrap">{{ $i->annual ? 'setahun' : 'TER '.$persen($i->ter_rate) }}{{ $i->pph21_override !== null ? ' · dikoreksi' : '' }}</div>
                            @if($ubah)
                                <input type="number" name="{{ $n }}[pph21_override]" value="{{ old("$k.pph21_override", $i->pph21_override) }}" step="1" placeholder="otomatis" aria-label="Koreksi PPh 21 {{ $i->employee_name }}" class="mt-1 {{ $angka }}">
                            @endif
                        </td>
                        <td class="px-2 py-3 text-right tabular-nums">
                            @if($ubah)
                                <input type="number" name="{{ $n }}[kasbon]" value="{{ old("$k.kasbon", $i->kasbon) }}" min="0" step="1" aria-label="Kasbon {{ $i->employee_name }}" class="{{ $angka }}">
                            @else
                                {{ $rp($i->kasbon) }}
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-semibold text-stone-800 tabular-nums whitespace-nowrap">{{ $rp($i->net_pay) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot class="bg-stone-50 border-t border-stone-200 text-xs font-semibold text-stone-700">
                    <tr>
                        <td class="px-4 py-2">Total</td>
                        @foreach($kolom as $pasangan)
                            <td class="px-2 py-2 text-right tabular-nums">
                                @foreach($pasangan as $f => $sebutan)<div class="{{ $loop->first ? '' : 'text-[11px] font-normal text-stone-500' }}">{{ $loop->first ? '' : $sebutan.' ' }}{{ $rp($total[$f]) }}</div>@endforeach
                            </td>
                        @endforeach
                        <td class="px-2 py-2 text-right tabular-nums">{{ $rp($total['bpjs_karyawan']) }}</td>
                        <td class="px-2 py-2 text-right tabular-nums">{{ $rp($total['pph21']) }}</td>
                        <td class="px-2 py-2 text-right tabular-nums">{{ $rp($total['kasbon']) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $rp($total['bersih']) }}</td>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>
        @if($ubah)
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-[11px] text-stone-400">Isi angka bulat tanpa titik. Koreksi PPh 21 dikosongkan = pakai hitungan otomatis (boleh minus untuk pengembalian). BPJS dihitung dari gaji pokok + tunjangan.</p>
                <button class="px-5 py-2.5 text-sm font-semibold bg-red-700 text-white rounded-lg hover:bg-red-800">Simpan &amp; hitung ulang</button>
            </div>
        </form>
        @endif

        @foreach($items->where('annual', true) as $i)
            @php $d = (array) $i->tax_detail; @endphp
            <details class="bg-white rounded-2xl border border-stone-200">
                <summary class="px-5 py-3 cursor-pointer text-sm font-semibold text-stone-800">Rincian PPh 21 setahun — {{ $i->employee_name }}</summary>
                <dl class="px-5 pb-4 grid grid-cols-2 gap-x-4 gap-y-1 text-sm max-w-xl">
                    <dt class="text-stone-500">Penghasilan bruto setahun ({{ $d['bulan'] ?? 0 }} bulan)</dt><dd class="text-right tabular-nums">{{ $rp($d['bruto_setahun'] ?? 0) }}</dd>
                    <dt class="text-stone-500">Biaya jabatan (5%, maks Rp500rb/bulan)</dt><dd class="text-right tabular-nums">{{ $rp(-($d['biaya_jabatan'] ?? 0)) }}</dd>
                    <dt class="text-stone-500">Iuran JHT &amp; JP karyawan</dt><dd class="text-right tabular-nums">{{ $rp(-($d['iuran_pensiun'] ?? 0)) }}</dd>
                    <dt class="text-stone-500">Penghasilan neto</dt><dd class="text-right tabular-nums">{{ $rp($d['neto'] ?? 0) }}</dd>
                    <dt class="text-stone-500">PTKP {{ $i->ptkp_status }}</dt><dd class="text-right tabular-nums">{{ $rp(-($d['ptkp'] ?? 0)) }}</dd>
                    <dt class="text-stone-500">Penghasilan kena pajak (dibulatkan ribuan)</dt><dd class="text-right tabular-nums">{{ $rp($d['pkp'] ?? 0) }}</dd>
                    <dt class="text-stone-500">PPh 21 setahun (tarif Pasal 17)</dt><dd class="text-right tabular-nums">{{ $rp($d['pph21_setahun'] ?? 0) }}</dd>
                    <dt class="text-stone-500">Sudah dipotong bulan-bulan sebelumnya</dt><dd class="text-right tabular-nums">{{ $rp(-($d['pph21_sebelumnya'] ?? 0)) }}</dd>
                    <dt class="font-semibold text-stone-800">PPh 21 bulan ini{{ $i->pph21_auto < 0 ? ' (lebih potong, dikembalikan)' : '' }}</dt><dd class="text-right font-semibold tabular-nums">{{ $rp($i->pph21_auto) }}</dd>
                </dl>
            </details>
        @endforeach

        @if($draf && $jurnal)
            <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-stone-100">
                    <h3 class="text-sm font-bold text-stone-800">Pratinjau jurnal saat dikunci</h3>
                    <p class="text-[11px] text-stone-400">Dicatat otomatis ke Akuntansi tanggal {{ $run->period->copy()->endOfMonth()->translatedFormat('d M Y') }}. Akun yang belum ada dibuat otomatis.</p>
                </div>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-stone-50 text-xs text-stone-500">
                        <tr>
                            <th class="px-5 py-2 text-left font-medium">Akun</th>
                            <th class="px-2 py-2 text-left font-medium">Keterangan</th>
                            <th class="px-2 py-2 text-right font-medium">Debit</th>
                            <th class="px-5 py-2 text-right font-medium">Kredit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                    @foreach($jurnal as $b)
                        <tr>
                            <td class="px-5 py-2 text-stone-800"><span class="font-mono text-xs text-stone-500">{{ $b['account']->code }}</span> {{ $b['account']->name }}</td>
                            <td class="px-2 py-2 text-stone-500 text-xs">{{ $b['memo'] }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ $b['debit'] ? $rp($b['debit']) : '' }}</td>
                            <td class="px-5 py-2 text-right tabular-nums">{{ $b['credit'] ? $rp($b['credit']) : '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot class="bg-stone-50 text-xs font-semibold text-stone-700">
                        <tr>
                            <td class="px-5 py-2" colspan="2">Total</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ $rp(array_sum(array_column($jurnal, 'debit'))) }}</td>
                            <td class="px-5 py-2 text-right tabular-nums">{{ $rp(array_sum(array_column($jurnal, 'credit'))) }}</td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            </div>
        @endif
    @endif
</div>
@endsection
