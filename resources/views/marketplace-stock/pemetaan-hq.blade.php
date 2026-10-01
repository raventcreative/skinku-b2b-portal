@extends('layouts.app')
@section('title', 'Pemetaan ke HQ')
@section('heading', 'Pemetaan Produk Master ke Stok Gudang (HQ)')

@section('content')
<div class="mx-auto max-w-6xl space-y-5 px-1 sm:px-2">
    <div class="bg-white rounded-2xl border border-stone-200 p-5 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-bold text-stone-800">Persiapan penggabungan stok</h2>
                <p class="mt-1 text-xs text-stone-500 leading-relaxed">Tandai tiap produk <b>satuan</b> = produk apa di gudang HQ, rapikan produk yang sebenarnya <b>bundling</b>, dan isi <b>resep bundling</b> dari resep HQ. <b>Hanya merapikan data Produk Master</b> — stok HQ, SKU map, dan marketplace tidak berubah.</p>
            </div>
            <a href="{{ route('marketplace-stock.index') }}" class="text-xs px-3 py-2 rounded-lg bg-stone-100 text-stone-700 font-semibold hover:bg-stone-200">← Produk Master</a>
        </div>
        <div class="flex flex-wrap gap-2 text-xs">
            <span class="px-3 py-1.5 rounded-full bg-stone-100 text-stone-700">Satuan tertandai: <b>{{ $stat['satuan_ok'] }}/{{ $stat['satuan'] }}</b></span>
            <span class="px-3 py-1.5 rounded-full bg-stone-100 text-stone-700">Bundling beresep: <b>{{ $stat['bundle_ok'] }}/{{ $stat['bundle'] }}</b></span>
        </div>
        <p class="text-[11px] text-stone-500"><span class="inline-block w-2.5 h-2.5 rounded-sm align-middle" style="background:#fef3c7;border:1px solid #f59e0b"></span> = <b>tebakan otomatis</b> (belum tersimpan) — periksa dulu, ganti bila salah, lalu klik <b>Simpan pemetaan</b>.</p>
    </div>

    <form method="POST" action="{{ route('marketplace-stock.pemetaan-hq.simpan') }}" class="space-y-4">
        @csrf
        <div class="bg-white rounded-2xl border border-stone-200 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-stone-50 text-left text-xs text-stone-500">
                    <tr><th class="px-4 py-3">Produk Master</th><th class="px-4 py-3">Tipe</th><th class="px-4 py-3">Produk gudang (HQ) / Resep bundling</th></tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($rows as $row)
                        @php $m = $row['m']; @endphp
                        <tr class="align-top">
                            <td class="px-4 py-3">
                                <div class="font-medium text-stone-800">{{ $m->parent ? $m->parent->name.' — '.$m->variant_name : $m->name }}</div>
                                <div class="text-[11px] text-stone-400">{{ $m->master_sku }}@if($m->listings->isNotEmpty()) · SKU jual: {{ $m->listings->pluck('seller_sku')->unique()->implode(', ') }}@endif</div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if($m->is_bundle)
                                    <span class="px-2 py-0.5 rounded bg-amber-100 text-amber-800 font-semibold">Bundle</span>
                                @else
                                    <span class="px-2 py-0.5 rounded bg-stone-100 text-stone-600">Satuan</span>
                                    @if($row['seperti_bundle'])
                                        <label class="mt-2 flex items-center gap-1 text-amber-800"><input type="checkbox" name="jadikan_bundle[]" value="{{ $m->id }}"> Jadikan Bundle?</label>
                                    @endif
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if(! $m->is_bundle)
                                    @php $pilih = $m->product_id ?: ($row['tebak']['id'] ?? null); @endphp
                                    <select name="produk[{{ $m->id }}]" class="w-full px-2 py-1.5 border rounded-lg text-sm" @if(! $m->product_id && $row['tebak']) style="background:#fef3c7;border-color:#f59e0b" @else style="border-color:#e7e5e4" @endif>
                                        <option value="">— belum ditandai —</option>
                                        @foreach($produk as $p)<option value="{{ $p->id }}" @selected((int) $pilih === $p->id)>{{ $p->name }}{{ $p->sku ? ' ('.$p->sku.')' : '' }}</option>@endforeach
                                    </select>
                                    @if(! $m->product_id && $row['tebak'])<div class="text-[11px] text-amber-700 mt-1">Tebakan: {{ $row['tebak']['sumber'] }}</div>@endif
                                @elseif($m->bundleItems->isNotEmpty())
                                    <span class="text-xs text-emerald-700 font-semibold">✓ Resep terisi ({{ $m->bundleItems->count() }} isi)</span>
                                    <a href="{{ route('marketplace-stock.edit', $m->parent_id ?: $m->id) }}" class="text-xs text-indigo-600 hover:underline ml-2">lihat</a>
                                @elseif($row['resep'] && $row['resep']['sumber'])
                                    @php $lengkap = $row['resep']['rows'] !== [] && $row['resep']['gagal'] === []; @endphp
                                    <label class="flex items-start gap-2 text-xs">
                                        <input type="checkbox" name="resep_hq[]" value="{{ $m->id }}" class="mt-0.5" @checked($lengkap)>
                                        <span>Isi resep dari HQ ({{ $row['resep']['sumber'] }}):
                                            @foreach($row['resep']['rows'] as $x)<b>{{ $x['qty'] }} × {{ $x['label'] }}</b>@if(! $loop->last), @endif @endforeach
                                            @if($row['resep']['gagal'] !== [])<span class="text-amber-700">{{ $row['resep']['rows'] !== [] ? ' · ' : '' }}belum ada padanan: {{ implode(', ', $row['resep']['gagal']) }} — tandai produk satuannya di atas lalu simpan, atau isi manual.</span>@endif
                                        </span>
                                    </label>
                                @else
                                    <span class="text-xs text-stone-500">Belum ada resep HQ untuk SKU ini — <a href="{{ route('marketplace-stock.edit', $m->parent_id ?: $m->id) }}" class="text-indigo-600 hover:underline">isi manual</a>.</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="flex items-center gap-3">
            <button class="px-5 py-2.5 bg-red-700 text-white rounded-lg hover:bg-red-800 font-semibold text-sm">Simpan pemetaan</button>
            <span class="text-xs text-stone-500">Urutan simpan: tanda produk gudang → Jadikan Bundle → resep HQ (resep hanya diisi bila semua isinya ketemu padanan).</span>
        </div>
    </form>
</div>
@endsection
