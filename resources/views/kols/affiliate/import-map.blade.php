@extends('layouts.app')
@section('title', 'Petakan Kolom Import')
@section('heading', 'Petakan Kolom Import')

@section('content')
<a href="{{ route('kol-affiliate.import') }}" class="text-xs text-stone-500 hover:text-stone-800">← Unggah file lain</a>

<div class="mt-3 space-y-5">
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-sm text-stone-700">
            File <b>{{ $filename }}</b> ({{ strtoupper($platform) }}) — <b class="tabular-nums">{{ number_format($rowCount, 0, ',', '.') }}</b> baris data.
            Cocokkan tiap kolom SKINKU ke kolom di file kamu. Pilihan disimpan otomatis untuk import berikutnya.
        </p>
    </div>

    <form method="POST" action="{{ route('kol-affiliate.import.commit') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="platform" value="{{ $platform }}">
        <input type="hidden" name="filename" value="{{ $filename }}">

        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <p class="text-sm font-bold text-stone-800 mb-4">Pemetaan Kolom</p>
            <div class="grid sm:grid-cols-2 gap-4">
                @foreach($fields as $field => $label)
                    <label class="block text-sm">
                        <span class="text-xs font-semibold text-stone-600">{{ $label }}</span>
                        <select name="map[{{ $field }}]" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white text-sm">
                            <option value="">— (abaikan) —</option>
                            @foreach($header as $i => $colName)
                                <option value="{{ $i }}" @selected(($guess[$field] ?? null) === $i)>
                                    {{ $colName !== '' ? $colName : 'Kolom ' . ($i + 1) }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
            </div>
            <p class="text-[11px] text-stone-400 mt-3">Kolom bertanda * wajib. Order dengan Order ID sama akan di-replace (aman re-upload).</p>
        </div>

        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <p class="text-sm font-bold text-stone-800 mb-3">Urutan Tanggal</p>
            <div class="flex flex-wrap gap-4 text-sm">
                @foreach(['auto' => 'Otomatis (tebak)', 'dmy' => 'DD/MM/YYYY (Indonesia)', 'mdy' => 'MM/DD/YYYY (AS)'] as $val => $lbl)
                    <label class="inline-flex items-center gap-2">
                        <input type="radio" name="date_order" value="{{ $val }}" @checked($dateOrder === $val) class="text-red-600 focus:ring-red-500">
                        <span class="text-stone-700">{{ $lbl }}</span>
                    </label>
                @endforeach
            </div>
            <p class="text-[11px] text-stone-400 mt-2">Menentukan tafsir tanggal seperti <span class="font-mono">03/04/2026</span> (3 Apr vs 4 Mar).</p>
        </div>

        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <p class="text-sm font-bold text-stone-800 mb-3">Preview 20 Baris Pertama</p>
            <div class="overflow-x-auto">
                <table class="text-xs border border-stone-200">
                    <thead>
                        <tr class="bg-stone-50 text-left text-stone-500">
                            @foreach($header as $colName)
                                <th class="px-2.5 py-1.5 border-b border-stone-200 whitespace-nowrap font-semibold">{{ $colName !== '' ? $colName : '—' }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($preview as $row)
                            <tr>
                                @foreach($header as $i => $colName)
                                    <td class="px-2.5 py-1.5 whitespace-nowrap text-stone-600">{{ $row[$i] ?? '' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex items-center gap-4">
            <button class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Import sekarang</button>
            <span class="text-[11px] text-stone-400">Username tak dikenal masuk daftar "Belum Cocok" untuk ditautkan manual.</span>
        </div>
    </form>
</div>
@endsection
