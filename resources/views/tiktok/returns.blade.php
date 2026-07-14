@extends('layouts.app')
@section('title', 'Retur TikTok')
@section('heading', 'Retur TikTok — Review Layak Jual')

@section('content')
<a href="{{ route('tiktok.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Integrasi</a>

<div class="mt-3 flex flex-wrap items-center gap-3">
    <form method="POST" action="{{ route('tiktok.returns.sync') }}">@csrf
        <button class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">↻ Tarik Retur dari TikTok</button>
    </form>
    <span class="text-[11px] text-stone-500">Barang retur <b>tidak otomatis masuk stok</b>. Cek dulu: <b>layak jual</b> → Terima (stok +), <b>cacat</b> → Tolak.</span>
</div>

<div class="mt-4 space-y-2">
    @forelse($returns as $r)
        @php $pv = $previews[$r->id]; @endphp
        <div class="bg-white rounded-2xl border border-stone-200 p-4 flex flex-col sm:flex-row sm:items-start gap-3">
            <div class="sm:w-56 sm:shrink-0">
                <div class="font-mono text-xs text-stone-700 break-all">{{ $r->tiktok_return_id }}</div>
                <div class="text-[10px] text-stone-400">Order {{ $r->tiktok_order_id ?? '—' }} · {{ $r->return_created_at?->format('d M Y') ?? '—' }}</div>
                <span class="inline-block mt-1 px-2 py-0.5 rounded-full bg-stone-100 text-stone-500 text-[10px] font-bold">{{ $r->status ?? $r->return_type ?? 'retur' }}</span>
            </div>
            <div class="flex-1 text-xs min-w-0">
                @forelse($pv['lines'] as $l)
                    <div class="py-0.5">
                        <span class="font-mono text-stone-500">{{ $l['sku'] }}</span>
                        <span class="text-stone-400">× {{ $l['qty'] }}</span>
                        <span class="text-stone-300">→</span>
                        @if(count($l['components']))
                            @foreach($l['components'] as $c)
                                <span class="text-emerald-700">{{ $c['product']->name }}</span><span class="text-emerald-600 font-semibold"> +{{ $c['add'] }}</span>@if(!$loop->last)<span class="text-stone-300"> + </span>@endif
                            @endforeach
                        @else
                            <span class="text-rose-600 font-semibold">❌ SKU belum ada resep</span>
                        @endif
                    </div>
                @empty
                    <span class="text-stone-400">— tidak ada item —</span>
                @endforelse
                @if($r->review_note)<div class="text-[10px] text-stone-400 mt-1">Catatan: {{ $r->review_note }}</div>@endif
            </div>
            <div class="sm:w-48 sm:shrink-0 sm:text-right">
                @if($r->review_status === \App\Models\TiktokReturn::REVIEW_RESTOCKED)
                    <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[10px] font-bold">✓ stok ditambah</span>
                    <form method="POST" action="{{ route('tiktok.returns.reset', $r) }}" class="inline" onsubmit="return confirm('Batalkan? Stok yang tadi ditambah akan ditarik lagi.')">@csrf
                        <button class="ml-1 text-[10px] text-stone-500 hover:text-rose-600 underline">batalkan</button>
                    </form>
                @elseif($r->review_status === \App\Models\TiktokReturn::REVIEW_REJECTED)
                    <span class="px-2 py-0.5 rounded-full bg-rose-100 text-rose-700 text-[10px] font-bold">✗ ditolak (cacat)</span>
                    <form method="POST" action="{{ route('tiktok.returns.reset', $r) }}" class="inline">@csrf
                        <button class="ml-1 text-[10px] text-stone-500 hover:text-stone-700 underline">ubah</button>
                    </form>
                @else
                    <div class="flex sm:flex-col gap-1.5 sm:items-end">
                        @if($pv['all_matched'])
                            <form method="POST" action="{{ route('tiktok.returns.restock', $r) }}" onsubmit="return confirm('Barang layak jual — tambah stok?')">@csrf
                                <button class="px-3 py-1.5 text-[11px] bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 font-semibold w-full">✓ Terima &amp; Tambah Stok</button>
                            </form>
                        @else
                            <span class="text-[10px] text-rose-500">petakan SKU dulu (di Pesanan)</span>
                        @endif
                        <form method="POST" action="{{ route('tiktok.returns.reject', $r) }}" onsubmit="return confirm('Tandai cacat / tidak layak jual? Stok tidak ditambah.')">@csrf
                            <button class="px-3 py-1.5 text-[11px] bg-white border border-rose-300 text-rose-600 rounded-lg hover:bg-rose-50 font-semibold w-full">✗ Tolak (cacat)</button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    @empty
        <div class="bg-white rounded-2xl border border-stone-200 px-4 py-8 text-center text-stone-400 text-sm">Belum ada retur. Klik "Tarik Retur dari TikTok".</div>
    @endforelse
</div>
<div class="mt-4">{{ $returns->links() }}</div>
@endsection
