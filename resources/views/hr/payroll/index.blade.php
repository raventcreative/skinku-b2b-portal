@extends('layouts.app')
@section('title', 'Payroll')
@section('heading', 'Payroll')

@section('content')
{{-- Pesan sukses/gagal & error validasi ditampilkan layout (tak diulang di sini). --}}
@php $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.'); @endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-stone-500">Gaji bulanan karyawan: BPJS &amp; PPh 21 dihitung otomatis, dikunci → tercatat di jurnal Akuntansi, slip siap cetak.</p>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('hr.payroll.komponen') }}" class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Data gaji karyawan</a>
            @if($bolehKelola)
                <form method="POST" action="{{ route('hr.payroll.store') }}" class="flex items-center gap-2">@csrf
                    <input type="month" name="period" value="{{ old('period', $saranPeriode) }}" required aria-label="Bulan payroll" class="px-3 py-2 text-sm border border-stone-300 rounded-lg">
                    <button class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-red-700 text-white rounded-lg hover:bg-red-800">+ Buat payroll</button>
                </form>
            @endif
        </div>
    </div>

    @if($belumAdaGaji->isNotEmpty())
        <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            {{ $belumAdaGaji->count() }} karyawan aktif belum diisi gajinya ({{ $belumAdaGaji->take(5)->pluck('name')->implode(', ') }}{{ $belumAdaGaji->count() > 5 ? ', …' : '' }}) sehingga belum ikut payroll —
            isi di <a href="{{ route('hr.payroll.komponen') }}" class="font-semibold underline">Data gaji karyawan</a>.
        </div>
    @endif

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-stone-50 text-xs text-stone-500 border-b border-stone-200">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">Periode</th>
                    <th class="px-4 py-2 text-left font-medium">Status</th>
                    <th class="px-4 py-2 text-right font-medium">Karyawan</th>
                    <th class="px-4 py-2 text-right font-medium">Penghasilan</th>
                    <th class="px-4 py-2 text-right font-medium">BPJS (total)</th>
                    <th class="px-4 py-2 text-right font-medium">PPh 21</th>
                    <th class="px-4 py-2 text-right font-medium">Gaji bersih</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @forelse($runs as $run)
                <tr class="hover:bg-stone-50">
                    <td class="px-4 py-3"><a href="{{ route('hr.payroll.show', $run) }}" class="font-semibold text-stone-800 hover:text-red-700 hover:underline">{{ $run->label() }}</a></td>
                    <td class="px-4 py-3">
                        <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $run->dikunci() ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-100 text-amber-800' }}">{{ \App\Models\PayrollRun::STATUSES[$run->status] ?? $run->status }}</span>
                    </td>
                    <td class="px-4 py-3 text-right text-stone-600">{{ $run->items->count() }}</td>
                    <td class="px-4 py-3 text-right text-stone-700 tabular-nums">{{ $rp($run->items->sum(fn ($i) => $i->penghasilan())) }}</td>
                    <td class="px-4 py-3 text-right text-stone-700 tabular-nums">{{ $rp($run->items->sum(fn ($i) => $i->bpjsKaryawan() + $i->bpjsPerusahaan())) }}</td>
                    <td class="px-4 py-3 text-right text-stone-700 tabular-nums">{{ $rp($run->items->sum('pph21')) }}</td>
                    <td class="px-4 py-3 text-right font-semibold text-stone-800 tabular-nums">{{ $rp($run->items->sum('net_pay')) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-10 text-sm text-stone-500">
                    <p class="font-semibold text-stone-700 text-center mb-2">Belum ada payroll.</p>
                    <ol class="max-w-md mx-auto list-decimal pl-5 space-y-1">
                        <li>Isi gaji pokok, tunjangan &amp; kepesertaan BPJS di <a href="{{ route('hr.payroll.komponen') }}" class="text-red-700 font-semibold hover:underline">Data gaji karyawan</a>.</li>
                        <li>Pilih bulan lalu klik <b>+ Buat payroll</b> — BPJS &amp; PPh 21 terhitung otomatis.</li>
                        <li>Isi lembur, bonus/THR &amp; kasbon bila ada, cek, lalu <b>Kunci payroll</b> → jurnal Akuntansi &amp; slip gaji.</li>
                    </ol>
                </td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
@endsection
