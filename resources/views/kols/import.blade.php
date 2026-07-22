@extends('layouts.app')
@section('title', 'Impor KOL')
@section('heading', 'Impor Massal KOL')

@section('content')
<a href="{{ route('kols.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Database KOL</a>

@if($errors->any())
    <p class="mt-3 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs">{{ $errors->first() }}</p>
@endif

{{-- Langkah 1: unduh template + unggah --}}
<div class="bg-white rounded-2xl border border-stone-200 p-5 mt-3 mb-5">
    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('kols.import.template') }}" class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">⬇ Unduh Template</a>
        <p class="text-xs text-stone-500 flex-1 min-w-[16rem]">
            Isi mulai <b>baris ke-2</b>. Wajib: <b>username</b>, <b>followers</b>, <b>views_1…views_7</b>.
            Opsional: platform (kosong = tiktok), ratecard, tanggal_listing, agency, kategori. Sheet "Petunjuk" ada di template.
        </p>
    </div>

    <form method="POST" action="{{ route('kols.import.preview') }}" enctype="multipart/form-data" class="mt-4 flex flex-wrap items-end gap-3">
        @csrf
        <label class="text-[11px] font-semibold text-stone-500">File template (.xlsx / .csv)
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                class="mt-1 block text-sm border border-stone-300 rounded-lg px-3 py-2">
        </label>
        <label class="text-[11px] font-semibold text-stone-500">Tanggal listing default
            <input type="date" name="default_date" value="{{ old('default_date', $today) }}" required
                class="mt-1 block text-sm border border-stone-300 rounded-lg px-3 py-2">
        </label>
        <button class="px-4 py-2 text-sm bg-stone-700 text-white rounded-lg hover:bg-stone-800">Preview →</button>
    </form>
    <p class="text-[11px] text-stone-400 mt-2">"Tanggal listing default" dipakai untuk baris yang kolom <code>tanggal_listing</code>-nya kosong.</p>
</div>

{{-- Langkah 2: preview (belum tersimpan) --}}
@isset($preview)
    @php $s = $preview['summary']; $bisa = $s['baru'] + $s['lama']; @endphp

    <div class="flex flex-wrap items-center gap-2 mb-3 text-xs">
        <span class="px-3 py-1.5 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 font-semibold">🟢 {{ $s['baru'] }} KOL baru</span>
        <span class="px-3 py-1.5 rounded-lg bg-sky-50 border border-sky-200 text-sky-800 font-semibold">🔵 {{ $s['lama'] }} KOL lama (+screening)</span>
        <span class="px-3 py-1.5 rounded-lg bg-stone-50 border border-stone-200 text-stone-600 font-semibold">⚪ {{ $s['skip'] }} dilewati</span>
        <span class="text-stone-400">dari {{ $s['total'] }} baris</span>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden mb-4">
        <div class="overflow-x-auto max-h-[28rem] overflow-y-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px] sticky top-0">
                <tr>
                    <th class="text-left px-4 py-2">Baris</th>
                    <th class="text-left">Username</th>
                    <th class="text-left px-3">Status</th>
                    <th class="text-right">Median</th>
                    <th class="text-left px-3">Verdict</th>
                    <th class="text-left">Keterangan</th>
                </tr>
            </thead>
            <tbody>
                @foreach($preview['items'] as $it)
                    <tr class="border-t border-stone-100">
                        <td class="px-4 py-2 text-stone-400">{{ $it['n'] }}</td>
                        <td class="font-semibold text-stone-800">{{ '@'.$it['username'] }}</td>
                        <td class="px-3">
                            @if($it['status'] === 'baru')
                                <span class="px-2 py-0.5 rounded-full text-[10px] bg-emerald-100 text-emerald-700 font-semibold">🟢 BARU</span>
                            @elseif($it['status'] === 'lama')
                                <span class="px-2 py-0.5 rounded-full text-[10px] bg-sky-100 text-sky-700 font-semibold">🔵 +Screening</span>
                            @else
                                <span class="px-2 py-0.5 rounded-full text-[10px] bg-stone-100 text-stone-500 font-semibold">⚪ Dilewati</span>
                            @endif
                        </td>
                        <td class="text-right text-stone-600">{{ isset($it['median']) ? number_format($it['median'], 0, ',', '.') : '—' }}</td>
                        <td class="px-3 text-stone-700">{{ $it['verdict'] ?? '—' }}</td>
                        <td class="text-stone-500">{{ $it['reason'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>

    <form method="POST" action="{{ route('kols.import.commit') }}"
        onsubmit="return confirm('Impor {{ $bisa }} baris ke database?')">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="ext" value="{{ $ext }}">
        <input type="hidden" name="default_date" value="{{ $defaultDate }}">
        <button @disabled($bisa === 0)
            class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold disabled:opacity-40 disabled:cursor-not-allowed">
            ✔ Konfirmasi Impor ({{ $bisa }} baris)
        </button>
        <span class="ml-2 text-[11px] text-stone-400">yang "dilewati" tidak ikut disimpan</span>
    </form>
@endisset
@endsection
