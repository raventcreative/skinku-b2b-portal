@extends('layouts.app')
@section('title', 'Isi Views Massal')
@section('heading', 'Isi Views Massal')

@section('content')
<div class="max-w-4xl space-y-4">

    <a href="{{ route('kol-konten.index', ['bulan' => $month]) }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Konten &amp; Views</a>

    @if($errors->any())
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $errors->first() }}</div>
    @endif

    <p class="text-sm text-stone-500">
        Isi views semua konten sekaligus (kaya spreadsheet). <b>Kosongkan baris yang tidak berubah</b> —
        hanya baris berisi Views yang disimpan. Isi ulang di hari yang sama menimpa angka hari ini.
    </p>

    @if($contents->isEmpty())
        <div class="px-4 py-10 rounded-2xl border border-dashed border-stone-300 text-center text-sm text-stone-500">
            Belum ada konten bulan ini. Tambah konten dulu.
        </div>
    @else
        <form method="POST" action="{{ route('kol-konten.grid.save') }}">
            @csrf
            <input type="hidden" name="bulan" value="{{ $month }}">
            <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-stone-50 text-stone-500 text-xs">
                            <tr>
                                <th class="text-left px-3 py-2.5">KOL / Konten</th>
                                <th class="text-right px-3 py-2.5">Views terakhir</th>
                                <th class="text-right px-3 py-2.5">Views</th>
                                <th class="text-right px-3 py-2.5">Likes</th>
                                <th class="text-right px-3 py-2.5">Komentar</th>
                                <th class="text-right px-3 py-2.5">Share</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($contents as $i => $c)
                                <tr>
                                    <td class="px-3 py-2 max-w-xs">
                                        <span class="text-stone-600">{{ '@'.$c->kol->tiktok_username }}</span>
                                        <span class="block text-[11px] text-stone-400 truncate">{{ $c->title ?: $c->url }}</span>
                                        <input type="hidden" name="rows[{{ $i }}][id]" value="{{ $c->id }}">
                                    </td>
                                    <td class="px-3 py-2 text-right text-stone-400">{{ number_format((int) ($c->latestSnapshot->views ?? 0), 0, ',', '.') }}</td>
                                    <td class="px-3 py-2"><input type="number" min="0" name="rows[{{ $i }}][views]" class="w-24 px-2 py-1 border border-stone-300 rounded text-right"></td>
                                    <td class="px-3 py-2"><input type="number" min="0" name="rows[{{ $i }}][likes]" class="w-20 px-2 py-1 border border-stone-300 rounded text-right"></td>
                                    <td class="px-3 py-2"><input type="number" min="0" name="rows[{{ $i }}][comments]" class="w-20 px-2 py-1 border border-stone-300 rounded text-right"></td>
                                    <td class="px-3 py-2"><input type="number" min="0" name="rows[{{ $i }}][shares]" class="w-20 px-2 py-1 border border-stone-300 rounded text-right"></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mt-4">
                <button class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Simpan snapshot hari ini</button>
            </div>
        </form>
    @endif
</div>
@endsection
