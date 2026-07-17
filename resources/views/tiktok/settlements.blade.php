@extends('layouts.app')
@section('title', 'Dana Cair TikTok')
@section('heading', 'Dana Cair / Pencairan TikTok')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

<div class="flex flex-wrap items-center gap-2 mb-4">
    <a href="{{ route('tiktok.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Integrasi</a>
    @php $needKind = $settlements->getCollection()->whereNull('kind')->count(); @endphp
    {{-- Kedua tombol ini JALAN PINTAS, bukan satu-satunya jalan: semuanya sudah
         dijadwalkan. Tanpa keterangan ini tombolnya terbaca seperti pekerjaan
         wajib harian, dan orang mengira integrasinya manual. --}}
    <form method="POST" action="{{ route('tiktok.settlements.describe') }}" class="ml-auto">@csrf
        <button class="px-4 py-2 text-sm bg-white border border-stone-300 text-stone-700 rounded-lg hover:bg-stone-50"
            title="Otomatis tiap jam. Tombol ini cuma untuk mendahului jadwal.">🏷️ Ambil Keterangan sekarang</button>
    </form>
    <form method="POST" action="{{ route('tiktok.settlements.sync') }}">@csrf
        <button class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800"
            title="Otomatis tiap hari 01:00. Tombol ini cuma untuk mendahului jadwal.">↻ Tarik Pencairan sekarang</button>
    </form>
</div>

<div class="mb-4 px-4 py-3 rounded-xl bg-stone-50 border border-stone-200 text-[11px] text-stone-600">
    <span class="font-semibold text-stone-700">Semua tarikan berjalan otomatis</span> — tombol di atas hanya untuk mendahului jadwal.
    <span class="block mt-1">
        Order tiap 30 menit · Retur &amp; pencairan tiap hari 01:00 · Keterangan potongan tiap jam · Sapu penuh tiap hari 03:30.
    </span>
    @if($needKind)
        <span class="block mt-1 text-amber-700">{{ $needKind }} pencairan di halaman ini belum berketerangan — akan terisi sendiri, tak perlu ditunggui.</span>
    @endif
</div>

{{-- Pembukuan: default MATI. Kode siap, tapi buku keuangan tak tersentuh sampai dinyalakan. --}}
@php $journalOn = (bool) ($connection?->journal_enabled); @endphp
<div class="mb-4 rounded-xl border {{ $journalOn ? 'border-indigo-200 bg-indigo-50' : 'border-stone-200 bg-stone-50' }} px-4 py-3">
    <div class="flex flex-wrap items-center gap-3">
        <span class="text-sm font-bold {{ $journalOn ? 'text-indigo-800' : 'text-stone-700' }}">
            📒 Pembukuan TikTok — {{ $journalOn ? 'AKTIF' : 'MATI (siap pakai)' }}
        </span>
        @if($connection)
            <form method="POST" action="{{ route('tiktok.toggle-journal') }}" class="ml-auto">@csrf
                <input type="hidden" name="journal_enabled" value="0">
                <label class="flex items-center gap-1.5 text-xs cursor-pointer">
                    <input type="checkbox" name="journal_enabled" value="1" onchange="this.form.submit()" @checked($journalOn)>
                    Nyalakan pembukuan
                </label>
            </form>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-2 mt-3">
        @if($journalOn)
            <form method="POST" action="{{ route('tiktok.post-journals') }}"
                onsubmit="return confirm('Buat jurnal untuk semua yang belum: barang keluar, order sampai (omzet+HPP), dan pencairan?')">@csrf
                <button class="px-4 py-2 text-sm bg-indigo-700 text-white rounded-lg hover:bg-indigo-800 font-semibold">📒 Posting Jurnal</button>
            </form>
        @endif
        {{-- Selalu tersedia: dipakai untuk membersihkan jurnal yang terlanjur diposting. --}}
        <form method="POST" action="{{ route('tiktok.unpost-journals') }}"
            onsubmit="return confirm('CABUT semua jurnal TikTok? Jurnal bersumber TikTok akan dihapus dan buku kembali seperti sebelum pembukuan dinyalakan. Jurnal lain (impor Excel, manual, PO) TIDAK tersentuh.')">@csrf
            <button class="px-3 py-2 text-xs text-rose-600 hover:text-rose-800 underline">↩ Cabut semua jurnal TikTok</button>
        </form>
    </div>

    @unless($journalOn)
        <p class="text-[11px] text-stone-500 mt-2">
            Buku keuangan <b>tidak tersentuh</b> selama saklar mati. Pantau dulu sisi stok; kalau sudah yakin, nyalakan.
            PR sebelum dinyalakan: <b>saldo awal Piutang TikTok</b> per 14 Jul, biar tidak minus.
        </p>
    @endunless
</div>

<div class="mb-4 px-4 py-2.5 rounded-xl bg-indigo-50 border border-indigo-200 text-indigo-800 text-[11px] leading-relaxed">
    ℹ️ Tahap <b>M3a</b> — baru menarik & menampilkan data pencairan (read-only). Belum masuk jurnal.
    Setelah data asli terlihat benar, lanjut <b>M3b</b>: preview jurnal (Bank + Beban Fee = Pendapatan) + HPP, lalu posting.
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-2.5">Statement ID</th>
                <th class="text-left">Tanggal</th>
                <th class="text-left">Jenis</th>
                <th class="text-right">Omzet (Bruto)</th>
                <th class="text-right">Fee</th>
                <th class="text-right">Penyesuaian</th>
                <th class="text-right">Cair (Net)</th>
                <th class="text-left px-4">Rincian</th>
            </tr>
        </thead>
        <tbody>
            @forelse($settlements as $s)
                @php
                    $isSale = (float) $s->revenue_amount > 0;
                    $net = (float) $s->settlement_amount;
                @endphp
                <tr class="border-t border-stone-100 hover:bg-stone-50/50">
                    <td class="px-4 py-2 font-mono text-stone-700">{{ $s->tiktok_statement_id }}</td>
                    <td class="text-stone-500">{{ $s->statement_time?->format('d M Y') ?? '—' }}</td>
                    <td>
                        @if($s->kind)
                            <span class="px-2 py-0.5 rounded-full text-[10px] {{ $isSale ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800' }}">{{ $s->kind }}</span>
                        @elseif($isSale)
                            <span class="px-2 py-0.5 rounded-full text-[10px] bg-emerald-100 text-emerald-700">Penjualan</span>
                        @else
                            <span class="px-2 py-0.5 rounded-full text-[10px] bg-stone-100 text-stone-500" title="Klik Ambil Keterangan untuk isi">Potongan (?)</span>
                        @endif
                    </td>
                    <td class="text-right font-mono text-stone-700">{{ $isSale ? $rp($s->revenue_amount) : '·' }}</td>
                    <td class="text-right font-mono text-rose-600">{{ (float) $s->fee_amount ? '−'.$rp($s->fee_amount) : '·' }}</td>
                    <td class="text-right font-mono text-stone-500">{{ (float) $s->adjustment_amount ? $rp($s->adjustment_amount) : '·' }}</td>
                    <td class="text-right font-mono font-bold {{ $net < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ $rp($net) }}</td>
                    <td class="px-4">
                        <a href="{{ route('tiktok.settlements.detail', $s) }}" class="text-indigo-700 hover:text-indigo-900 hover:underline text-[11px]">Lihat rincian →</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-10 text-center text-stone-400">Belum ada data pencairan. Klik <b>Tarik Pencairan</b> untuk menariknya dari TikTok.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $settlements->links() }}</div>
@endsection
