@php($item = $row['item'])
@php($in = $row['in'])
@php($res = $row['result'])
<summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-lg px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
    <span>Rincian biaya dan target</span>
    <svg aria-hidden="true" class="h-4 w-4 shrink-0 text-stone-400 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.22 7.47a.75.75 0 0 1 1.06 0L10 11.19l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.53a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>
</summary>
<div class="border-t border-stone-100 px-3 pb-3 pt-4">
    <form method="POST" action="{{ route('roi-calculator.items.update', $item) }}" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @csrf
        @php
            $edit = [
                'selling_price' => ['Harga jual (Rp)', $in['selling_price']],
                'modal' => ['Modal (Rp)', $item->modal],
                'packing' => ['Packing (Rp)', $item->packing],
                'proses_order' => ['Proses order (Rp)', $item->proses_order],
                'admin_pct' => ['Admin (%)', $item->admin_pct],
                'voucher_pct' => ['Voucher (%)', $item->voucher_pct],
                'komisi_pct' => ['Komisi (%)', $item->komisi_pct],
                'komisi_cap' => ['Cap komisi (Rp)', $item->komisi_cap],
                'mall_pct' => ['Layanan mall (%)', $item->mall_pct],
                'pajak_pct' => ['Pajak (%)', $item->pajak_pct],
                'operasional_pct' => ['Operasional (%)', $item->operasional_pct],
                'affiliate_pct' => ['Affiliate (%)', $item->affiliate_pct],
            ];
        @endphp
        @foreach($edit as $name => [$label, $value])
            <label class="block min-w-0 text-[11px] font-medium text-stone-600">
                <span>{{ $label }}</span>
                <input type="number" step="any" min="0" name="{{ $name }}" value="{{ $value }}"
                    placeholder="{{ $name === 'selling_price' ? '' : 'Ikuti default' }}"
                    @required($name === 'selling_price')
                    class="mt-1 w-full rounded-lg border border-stone-300 px-2.5 py-2 text-xs tabular-nums focus:border-red-500 focus:ring-red-500">
            </label>
        @endforeach
        <div class="col-span-2 flex items-end sm:col-span-3 lg:col-span-4">
            <button class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-red-700 px-4 py-2 text-xs font-semibold text-white transition hover:bg-red-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2">
                <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/></svg>
                Simpan perubahan
            </button>
        </div>
    </form>

    <div class="mt-5">
        <h3 class="text-[11px] font-bold uppercase tracking-wide text-stone-500">Rincian biaya per penjualan</h3>
        <dl class="mt-2 grid grid-cols-2 gap-x-5 gap-y-2 text-xs sm:grid-cols-3 lg:grid-cols-4">
            <div><dt class="text-stone-500">Profit kotor</dt><dd class="font-semibold text-stone-800">{{ $rp($res['profit']) }}</dd></div>
            <div><dt class="text-stone-500">Admin</dt><dd>{{ $rp($res['admin']) }}</dd></div>
            <div><dt class="text-stone-500">Voucher Xtra</dt><dd>{{ $rp($res['voucher']) }}</dd></div>
            <div><dt class="text-stone-500">Komisi dinamis</dt><dd>{{ $rp($res['komisi']) }}</dd></div>
            <div><dt class="text-stone-500">Layanan mall</dt><dd>{{ $rp($res['mall']) }}</dd></div>
            <div><dt class="text-stone-500">Pajak</dt><dd>{{ $rp($res['pajak']) }}</dd></div>
            <div><dt class="text-stone-500">Operasional</dt><dd>{{ $rp($res['operasional']) }}</dd></div>
            <div><dt class="text-stone-500">Proses order</dt><dd>{{ $rp($in['proses_order']) }}</dd></div>
            <div><dt class="text-stone-500">Packing</dt><dd>{{ $rp($in['packing']) }}</dd></div>
            <div><dt class="text-stone-500">Komisi affiliate</dt><dd>{{ $rp($res['affiliate']) }}</dd></div>
            <div><dt class="text-stone-500">Profit setelah affiliate</dt><dd class="font-semibold text-stone-800">{{ $rp($res['profit_after_aff']) }}</dd></div>
            <div><dt class="text-stone-500">BEP ROI dengan affiliate</dt><dd>{{ $roi($res['bep_roi_aff']) }}</dd></div>
        </dl>
    </div>

    <div class="mt-5 overflow-x-auto rounded-lg border border-stone-200">
        <table class="w-full min-w-[28rem] text-xs tabular-nums">
            <caption class="sr-only">Target ROI iklan produk {{ $row['product']?->name ?? 'yang sudah dihapus' }}</caption>
            <thead class="bg-stone-50 text-stone-500"><tr>
                <th scope="col" class="px-3 py-2 text-left font-semibold">Target margin</th>
                @foreach([5,10,15,20] as $target)<th scope="col" class="px-3 py-2 text-right font-semibold">{{ $target }}%</th>@endforeach
            </tr></thead>
            <tbody class="divide-y divide-stone-100 text-stone-700">
                <tr><th scope="row" class="px-3 py-2 text-left font-medium">Tanpa affiliate</th>@foreach([5,10,15,20] as $target)<td class="px-3 py-2 text-right">{{ $roi($res['target_noaff'][$target]) }}</td>@endforeach</tr>
                <tr><th scope="row" class="px-3 py-2 text-left font-medium">Dengan affiliate</th>@foreach([5,10,15,20] as $target)<td class="px-3 py-2 text-right">{{ $roi($res['target_aff'][$target]) }}</td>@endforeach</tr>
            </tbody>
        </table>
    </div>
</div>
