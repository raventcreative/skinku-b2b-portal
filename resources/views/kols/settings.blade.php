@extends('layouts.app')
@section('title', 'Setelan KOL')
@section('heading', 'Setelan KOL')

@php $rp = fn ($n) => 'Rp ' . number_format((int) $n, 0, ',', '.'); @endphp

@section('content')
<div class="max-w-4xl space-y-6">
    <p class="text-sm text-stone-500">
        Semua angka acuan modul KOL di satu tempat. Nilai ini dipakai sebagai default;
        override per-bulan (di bawah) menimpanya hanya untuk bulan yang bersangkutan.
    </p>

    {{-- Setelan global --}}
    <form method="POST" action="{{ route('kol-settings.save') }}" class="bg-white rounded-2xl border border-stone-200 p-5">
        @csrf
        <p class="text-sm font-bold text-stone-800 mb-4">Setelan Global</p>

        <div class="grid sm:grid-cols-2 gap-4">
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Budget endorse / bulan</span>
                <input type="number" name="budget" min="0" value="{{ old('budget', $budget) }}"
                    class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm tabular-nums">
                <span class="text-[11px] text-stone-400">Batas belanja endorse bulanan (untuk kartu Sisa Budget).</span>
            </label>
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">CPM anchor (acuan wajar)</span>
                <input type="number" name="anchor" min="0" value="{{ old('anchor', $anchor) }}"
                    class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm tabular-nums">
                <span class="text-[11px] text-stone-400">CPM paid di atas ini memicu peringatan di dashboard.</span>
            </label>
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Target views / bulan</span>
                <input type="number" name="views_target" min="0" value="{{ old('views_target', $viewsTarget) }}"
                    class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm tabular-nums">
            </label>
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Target GMV / bulan</span>
                <input type="number" name="gmv_target" min="0" value="{{ old('gmv_target', $gmvTarget) }}"
                    class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm tabular-nums">
            </label>
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Margin kotor (%)</span>
                <input type="number" name="margin_pct" min="0" max="100" value="{{ old('margin_pct', $marginPct) }}"
                    class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm tabular-nums">
                <span class="text-[11px] text-stone-400">Untuk ROI margin-aware di dashboard (laba kotor GMV ÷ biaya).</span>
            </label>
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">HPP sampel default / unit</span>
                <input type="number" name="sample_hpp" min="0" value="{{ old('sample_hpp', $sampleHpp) }}"
                    class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm tabular-nums">
                <span class="text-[11px] text-stone-400">Prefill biaya/unit saat menambah sampel produk di deal.</span>
            </label>
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Urutan tanggal import (default)</span>
                <select name="date_order" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white text-sm">
                    <option value="auto" @selected($dateOrder === 'auto')>Otomatis (tebak)</option>
                    <option value="dmy" @selected($dateOrder === 'dmy')>Hari/Bulan/Tahun (DD/MM/YYYY)</option>
                    <option value="mdy" @selected($dateOrder === 'mdy')>Bulan/Hari/Tahun (MM/DD/YYYY)</option>
                </select>
                <span class="text-[11px] text-stone-400">Menafsirkan tanggal seperti 03/04/2026 saat import affiliate.</span>
            </label>
        </div>

        <div class="mt-5">
            <button class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Simpan setelan</button>
        </div>
    </form>

    {{-- Override target per-bulan --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-sm font-bold text-stone-800">Target per-Bulan (Override)</p>
        <p class="text-[11px] text-stone-400 mt-1 mb-4">
            Isi hanya kolom yang ingin ditimpa untuk bulan tsb — kolom kosong tetap ikut setelan global.
        </p>

        @if($targets->isEmpty())
            <p class="text-sm text-stone-400 italic mb-4">Belum ada override. Semua bulan memakai setelan global.</p>
        @else
            <div class="overflow-x-auto mb-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-stone-400 border-b border-stone-200">
                            <th class="py-2 pr-3">Bulan</th>
                            <th class="py-2 pr-3 text-right">Budget</th>
                            <th class="py-2 pr-3 text-right">Target Views</th>
                            <th class="py-2 pr-3 text-right">Target GMV</th>
                            <th class="py-2 pr-3 text-right">Margin</th>
                            <th class="py-2 pr-3">Catatan</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($targets as $t)
                            <tr>
                                <td class="py-2 pr-3 font-semibold text-stone-700 tabular-nums">{{ $t->month }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $t->budget !== null ? $rp($t->budget) : '—' }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $t->views_target !== null ? number_format($t->views_target, 0, ',', '.') : '—' }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $t->gmv_target !== null ? $rp($t->gmv_target) : '—' }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $t->margin !== null ? (int) round($t->margin * 100) . '%' : '—' }}</td>
                                <td class="py-2 pr-3 text-stone-500">{{ $t->notes ?: '—' }}</td>
                                <td class="py-2 text-right">
                                    <form method="POST" action="{{ route('kol-settings.monthly.destroy', $t) }}" onsubmit="return confirm('Hapus override bulan {{ $t->month }}?')">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-rose-500 hover:text-rose-700">hapus</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <form method="POST" action="{{ route('kol-settings.monthly.store') }}" class="grid sm:grid-cols-3 lg:grid-cols-6 gap-3 items-end border-t border-stone-100 pt-4">
            @csrf
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Bulan</span>
                <input type="month" name="month" value="{{ old('month', $thisMonth) }}" required
                    class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Budget</span>
                <input type="number" name="budget" min="0" placeholder="global" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm tabular-nums">
            </label>
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Target Views</span>
                <input type="number" name="views_target" min="0" placeholder="global" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm tabular-nums">
            </label>
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Target GMV</span>
                <input type="number" name="gmv_target" min="0" placeholder="global" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm tabular-nums">
            </label>
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Margin %</span>
                <input type="number" name="margin_pct" min="0" max="100" placeholder="global" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg text-sm tabular-nums">
            </label>
            <button class="px-4 py-2.5 bg-stone-800 hover:bg-stone-900 text-white text-sm font-semibold rounded-xl">Simpan bulan</button>
        </form>
    </div>
</div>
@endsection
