@extends('layouts.app')
@section('title','Kalkulator ROI')
@section('heading','Kalkulator ROI')
@section('content')
@php
    $rp = fn ($v) => 'Rp'.number_format((float) $v, 0, ',', '.');
    $roi = fn ($v) => $v === null ? '—' : number_format($v, 2, ',', '.').'×';
@endphp
<div class="space-y-4">

    @if(session('status'))
        <div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">
            <div class="font-semibold mb-1">Periksa input yang dimasukkan:</div>
            <ul class="list-disc list-inside space-y-0.5">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Setelan Biaya global --}}
    <details class="bg-white rounded-2xl border border-stone-200 p-5">
        <summary class="cursor-pointer text-sm font-bold text-stone-800">Setelan Biaya (global)</summary>
        <p class="text-xs text-stone-500 mt-1 mb-4">Default untuk semua produk. Persen dalam angka (mis. 8 = 8%). Bisa di-override per produk.</p>
        <form method="POST" action="{{ route('roi-calculator.settings') }}">
            @csrf
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                @php
                    $fields = [
                        'admin_pct' => 'Admin %', 'voucher_pct' => 'Voucher Xtra %', 'komisi_pct' => 'Komisi Dinamis %',
                        'komisi_cap' => 'Cap Komisi (Rp)', 'mall_pct' => 'Layanan Mall %', 'pajak_pct' => 'Pajak %',
                        'operasional_pct' => 'Operasional %', 'affiliate_pct' => 'Affiliate %',
                        'packing_default' => 'Packing (Rp)', 'proses_order_default' => 'Proses Order (Rp)',
                    ];
                @endphp
                @foreach($fields as $name => $label)
                    <label class="block">
                        <span class="text-[11px] text-stone-500">{{ $label }}</span>
                        <input type="number" step="any" min="0" name="{{ $name }}" value="{{ old($name, $settings->$name) }}"
                            class="mt-0.5 w-full px-2 py-1 border border-stone-300 rounded-lg text-xs @error($name) border-rose-400 @enderror">
                        @error($name)<span class="text-[10px] text-rose-600">{{ $message }}</span>@enderror
                    </label>
                @endforeach
            </div>
            <button class="mt-4 px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">Simpan Setelan</button>
        </form>
    </details>
    {{-- Tambah produk dari katalog --}}
    @if(count($products))
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <form method="POST" action="{{ route('roi-calculator.items.store') }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <label class="block">
                    <span class="text-[11px] text-stone-500">Produk</span>
                    <select name="product_id" required class="mt-0.5 px-2 py-1.5 border border-stone-300 rounded-lg text-xs max-w-[240px]">
                        <option value="">Pilih produk…</option>
                        @foreach($products as $p)
                            <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->sku }})</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-[11px] text-stone-500">Harga Jual (Rp)</span>
                    <input type="number" name="selling_price" min="0" step="1" required class="mt-0.5 w-32 px-2 py-1.5 border border-stone-300 rounded-lg text-xs">
                </label>
                <button class="px-4 py-2 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800">+ Tambah Produk</button>
                @error('product_id')<span class="text-[11px] text-rose-600">{{ $message }}</span>@enderror
            </form>
        </div>
    @endif

    {{-- Tabel hasil --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Produk — {{ count($rows) }}</div>
        @if(count($rows))
            <div class="overflow-x-auto">
                <table class="w-full text-xs whitespace-nowrap">
                    <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr>
                        <th class="text-left px-4 py-2">Produk</th>
                        <th class="text-right">Harga Jual</th>
                        <th class="text-right">Modal</th>
                        <th class="text-right">Total Biaya</th>
                        <th class="text-right">Profit Bersih</th>
                        <th class="text-right">BEP ROI</th>
                        <th class="text-right">Target Min 10%</th>
                        <th class="text-right">Target Optimum 20%</th>
                        <th class="text-right pr-4"></th>
                    </tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                            @php $p = $row['product']; $res = $row['result']; $in = $row['in']; @endphp
                            <tr class="border-t border-stone-100 align-top">
                                <td class="px-4 py-2.5">
                                    <div class="font-semibold text-stone-800">{{ $p?->name ?? '(produk dihapus)' }}</div>
                                    <div class="text-[11px] text-stone-400 font-mono">{{ $p?->sku }}</div>
                                </td>
                                <td class="text-right">{{ $rp($in['selling_price']) }}</td>
                                <td class="text-right">{{ $rp($in['modal']) }}</td>
                                <td class="text-right text-stone-600">{{ $rp($res['total_biaya']) }}</td>
                                <td class="text-right font-semibold {{ $res['profit_bersih'] > 0 ? 'text-emerald-700' : 'text-rose-600' }}">{{ $rp($res['profit_bersih']) }}</td>
                                <td class="text-right">{{ $roi($res['bep_roi']) }}</td>
                                <td class="text-right">{{ $roi($res['avg_min']) }}</td>
                                <td class="text-right">{{ $roi($res['avg_optimum']) }}</td>
                                <td class="text-right pr-4">
                                    <form method="POST" action="{{ route('roi-calculator.items.destroy', $row['item']) }}" onsubmit="return confirm('Hapus baris ini?')">
                                        @csrf @method('DELETE')
                                        <button class="text-rose-600 hover:underline text-[11px]">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                            {{-- Rincian lengkap (native <details>, zero-JS) --}}
                            <tr class="border-t border-stone-50 bg-stone-50/40">
                                <td colspan="9" class="px-4 py-1.5">
                                    <details>
                                        <summary class="cursor-pointer text-[11px] text-indigo-700 hover:underline">Rincian biaya & target</summary>
                                        <form method="POST" action="{{ route('roi-calculator.items.update', $row['item']) }}" class="mt-2 flex flex-wrap items-end gap-2 pb-2 border-b border-stone-100">
                                            @csrf
                                            @php
                                                $edit = [
                                                    'selling_price' => ['Harga Jual', $in['selling_price']],
                                                    'modal' => ['Modal', $row['item']->modal],
                                                    'packing' => ['Packing', $row['item']->packing],
                                                    'proses_order' => ['Proses Order', $row['item']->proses_order],
                                                    'admin_pct' => ['Admin %', $row['item']->admin_pct],
                                                    'voucher_pct' => ['Voucher %', $row['item']->voucher_pct],
                                                    'komisi_pct' => ['Komisi %', $row['item']->komisi_pct],
                                                    'komisi_cap' => ['Cap Komisi', $row['item']->komisi_cap],
                                                    'mall_pct' => ['Mall %', $row['item']->mall_pct],
                                                    'pajak_pct' => ['Pajak %', $row['item']->pajak_pct],
                                                    'operasional_pct' => ['Operasional %', $row['item']->operasional_pct],
                                                    'affiliate_pct' => ['Affiliate %', $row['item']->affiliate_pct],
                                                ];
                                            @endphp
                                            @foreach($edit as $name => [$label, $val])
                                                <label class="block">
                                                    <span class="text-[10px] text-stone-400">{{ $label }}</span>
                                                    <input type="number" step="any" min="0" name="{{ $name }}" value="{{ $val }}"
                                                        placeholder="{{ $name === 'selling_price' ? '' : 'warisi' }}"
                                                        {{ $name === 'selling_price' ? 'required' : '' }}
                                                        class="mt-0.5 w-20 px-1.5 py-1 border border-stone-300 rounded-sm text-[11px]">
                                                </label>
                                            @endforeach
                                            <button class="px-3 py-1.5 text-[11px] bg-stone-800 text-white rounded-lg hover:bg-stone-900">Simpan</button>
                                        </form>
                                        <div class="mt-2 grid grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-1 text-[11px] text-stone-600">
                                            <div>Profit kotor: <span class="font-semibold text-stone-800">{{ $rp($res['profit']) }}</span></div>
                                            <div>Admin: {{ $rp($res['admin']) }}</div>
                                            <div>Voucher Xtra: {{ $rp($res['voucher']) }}</div>
                                            <div>Komisi Dinamis: {{ $rp($res['komisi']) }}</div>
                                            <div>Layanan Mall: {{ $rp($res['mall']) }}</div>
                                            <div>Pajak: {{ $rp($res['pajak']) }}</div>
                                            <div>Operasional: {{ $rp($res['operasional']) }}</div>
                                            <div>Proses order: {{ $rp($in['proses_order']) }}</div>
                                            <div>Packing: {{ $rp($in['packing']) }}</div>
                                            <div>Komisi Affiliate: {{ $rp($res['affiliate']) }}</div>
                                            <div>Profit − Aff: <span class="font-semibold text-stone-800">{{ $rp($res['profit_after_aff']) }}</span></div>
                                            <div>BEP ROI (dgn aff): {{ $roi($res['bep_roi_aff']) }}</div>
                                        </div>
                                        <div class="mt-2 overflow-x-auto">
                                            <table class="text-[11px] text-stone-600">
                                                <thead class="text-stone-400"><tr>
                                                    <th class="text-left pr-3">Target ROI</th>
                                                    <th class="text-right px-3">5%</th><th class="text-right px-3">10%</th>
                                                    <th class="text-right px-3">15%</th><th class="text-right px-3">20%</th>
                                                </tr></thead>
                                                <tbody>
                                                    <tr><td class="pr-3">Tanpa Aff</td>
                                                        @foreach([5,10,15,20] as $x)<td class="text-right px-3">{{ $roi($res['target_noaff'][$x]) }}</td>@endforeach
                                                    </tr>
                                                    <tr><td class="pr-3">Dgn Aff</td>
                                                        @foreach([5,10,15,20] as $x)<td class="text-right px-3">{{ $roi($res['target_aff'][$x]) }}</td>@endforeach
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-stone-50 text-stone-700 font-semibold">
                        <tr class="border-t border-stone-200">
                            <td class="px-4 py-2.5" colspan="6">Rata-rata semua produk (patokan setelan iklan)</td>
                            <td class="text-right">{{ $roi($summary['avg_min_all']) }}</td>
                            <td class="text-right">{{ $roi($summary['avg_optimum_all']) }}</td>
                            <td class="pr-4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @else
            <p class="px-5 py-6 text-center text-stone-400 text-sm">Belum ada produk. Tambahkan produk dari katalog untuk mulai menghitung.</p>
        @endif
    </div>

</div>
@endsection
