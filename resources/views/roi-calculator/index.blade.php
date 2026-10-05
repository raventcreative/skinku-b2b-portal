@extends('layouts.app')
@section('title', 'Kalkulator ROI')
@section('heading', 'Kalkulator ROI')
@section('content')
@php
    $rp = fn ($value) => 'Rp'.number_format((float) $value, 0, ',', '.');
    $roi = fn ($value) => $value === null ? '—' : number_format($value, 2, ',', '.').'×';
    $metric = 'rounded-xl border border-stone-200 bg-white px-4 py-3';
@endphp

<div class="space-y-5">
    @if(session('status'))
        <div role="status" class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <svg aria-hidden="true" class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg>
            {{ session('status') }}
        </div>
    @endif
    @if(session('error') || $errors->any())
        <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            @if(session('error'))<p>{{ session('error') }}</p>@endif
            @if($errors->any())
                <p class="font-semibold">Periksa input yang dimasukkan:</p>
                <ul class="mt-1 list-inside list-disc space-y-0.5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            @endif
        </div>
    @endif

    <section aria-label="Ringkasan kalkulator ROI" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="{{ $metric }}">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500">Produk dihitung</p>
            <p class="mt-1 text-xl font-bold tabular-nums text-stone-900">{{ count($rows) }} <span class="text-sm font-medium text-stone-500">produk</span></p>
        </div>
        <div class="{{ $metric }}">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500">Target minimum rata-rata</p>
            <p class="mt-1 text-xl font-bold tabular-nums text-stone-900">{{ $roi($summary['avg_min_all']) }}</p>
            <p class="mt-0.5 text-[11px] text-stone-500">Perkiraan ROAS iklan minimum</p>
        </div>
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-red-800">Target optimum rata-rata</p>
            <p class="mt-1 text-xl font-bold tabular-nums text-red-900">{{ $roi($summary['avg_optimum_all']) }}</p>
            <p class="mt-0.5 text-[11px] text-red-800/75">Patokan ROAS iklan optimum</p>
        </div>
    </section>

    <details class="group overflow-hidden rounded-2xl border border-stone-200 bg-white" {{ $errors->any() ? 'open' : '' }}>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-red-500">
            <span class="flex min-w-0 items-center gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-red-50 text-red-800">
                    <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m9-9H3m3.5-5.5h11M6.5 17.5h11"/></svg>
                </span>
                <span><span class="block text-sm font-bold text-stone-900">Setelan biaya global</span><span class="mt-0.5 block text-xs text-stone-500">Nilai default untuk produk baru; bisa diubah per produk.</span></span>
            </span>
            <svg aria-hidden="true" class="h-4 w-4 shrink-0 text-stone-500 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.22 7.47a.75.75 0 0 1 1.06 0L10 11.19l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.53a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>
        </summary>
        <div class="border-t border-stone-100 px-5 py-5">
            <form method="POST" action="{{ route('roi-calculator.settings') }}" class="space-y-5">
                @csrf
                @php
                    $percentageFields = [
                        'admin_pct' => 'Admin', 'voucher_pct' => 'Voucher Xtra', 'komisi_pct' => 'Komisi dinamis',
                        'mall_pct' => 'Layanan mall', 'pajak_pct' => 'Pajak', 'operasional_pct' => 'Operasional', 'affiliate_pct' => 'Affiliate',
                    ];
                    $fixedFields = ['komisi_cap' => 'Cap komisi', 'packing_default' => 'Packing', 'proses_order_default' => 'Proses order'];
                @endphp
                <fieldset>
                    <legend class="mb-3 text-xs font-bold text-stone-800">Potongan persentase</legend>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach($percentageFields as $name => $label)
                            <label for="setting-{{ $name }}" class="block text-[11px] font-medium text-stone-600">{{ $label }} (%)
                                <input id="setting-{{ $name }}" type="number" step="any" min="0" name="{{ $name }}" value="{{ old($name, $settings->$name) }}" inputmode="decimal" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm tabular-nums focus:border-red-500 focus:ring-red-500 @error($name) border-rose-400 @enderror">
                                @error($name)<span class="mt-1 block text-[10px] text-rose-700">{{ $message }}</span>@enderror
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <fieldset>
                    <legend class="mb-3 text-xs font-bold text-stone-800">Biaya tetap</legend>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach($fixedFields as $name => $label)
                            <label for="setting-{{ $name }}" class="block text-[11px] font-medium text-stone-600">{{ $label }} (Rp)
                                <input id="setting-{{ $name }}" type="number" step="1" min="0" name="{{ $name }}" value="{{ old($name, $settings->$name) }}" inputmode="numeric" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm tabular-nums focus:border-red-500 focus:ring-red-500 @error($name) border-rose-400 @enderror">
                                @error($name)<span class="mt-1 block text-[10px] text-rose-700">{{ $message }}</span>@enderror
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <div class="flex flex-col gap-2 border-t border-stone-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-[11px] text-stone-500">Persentase dimasukkan sebagai angka, misalnya 8 untuk 8%.</p>
                    <button class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-red-700 px-4 py-2 text-xs font-semibold text-white transition hover:bg-red-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/></svg>
                        Simpan setelan
                    </button>
                </div>
            </form>
        </div>
    </details>

    <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white">
        <div class="border-b border-stone-100 px-5 py-4">
            <h2 class="text-sm font-bold text-stone-900">Tambah produk ke kalkulator</h2>
            <p class="mt-1 text-xs text-stone-500">Pilih dari katalog dan masukkan harga jual untuk mulai menghitung margin.</p>
        </div>
        @if(count($products))
            <form method="POST" action="{{ route('roi-calculator.items.store') }}" class="grid gap-3 p-5 sm:grid-cols-[minmax(0,1fr)_minmax(12rem,0.45fr)_auto] sm:items-end">
                @csrf
                <label for="roi-product" class="block min-w-0 text-[11px] font-semibold text-stone-600">Produk katalog
                    <select id="roi-product" name="product_id" required class="mt-1 w-full rounded-lg border border-stone-300 bg-white px-3 py-2.5 text-sm focus:border-red-500 focus:ring-red-500">
                        <option value="">Pilih produk…</option>
                        @foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }} ({{ $product->sku }})</option>@endforeach
                    </select>
                    @error('product_id')<span class="mt-1 block text-[11px] text-rose-700">{{ $message }}</span>@enderror
                </label>
                <label for="roi-selling-price" class="block text-[11px] font-semibold text-stone-600">Harga jual (Rp)
                    <input id="roi-selling-price" type="number" name="selling_price" min="0" step="1" inputmode="numeric" required class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2.5 text-sm tabular-nums focus:border-red-500 focus:ring-red-500">
                </label>
                <button class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-red-700 px-4 py-2.5 text-xs font-semibold text-white transition hover:bg-red-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2">
                    <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M10 3v14M3 10h14"/></svg>
                    Tambah produk
                </button>
            </form>
        @else
            <p class="px-5 py-4 text-xs text-stone-500">Semua produk katalog sudah ada di kalkulator.</p>
        @endif
    </section>

    <section aria-labelledby="roi-products-heading" class="overflow-hidden rounded-2xl border border-stone-200 bg-white">
        <div class="flex flex-col gap-1 border-b border-stone-100 px-5 py-4 sm:flex-row sm:items-end sm:justify-between">
            <div><h2 id="roi-products-heading" class="text-sm font-bold text-stone-900">Hasil per produk</h2><p class="mt-1 text-xs text-stone-500">Profit dan target iklan dihitung dari harga jual serta biaya yang berlaku.</p></div>
            <span class="text-xs font-semibold tabular-nums text-stone-500">{{ count($rows) }} produk</span>
        </div>
        @if(count($rows))
            {{-- Mobile: ringkasan per produk tanpa memaksa tabel melebar. --}}
            <div class="space-y-3 p-3 sm:p-4 md:hidden">
                @foreach($rows as $row)
                    @php($product = $row['product'])
                    @php($item = $row['item'])
                    @php($in = $row['in'])
                    @php($res = $row['result'])
                    <article class="overflow-hidden rounded-xl border border-stone-200 bg-white">
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0"><h3 class="break-words text-sm font-bold text-stone-900">{{ $product?->name ?? '(produk dihapus)' }}</h3><p class="mt-1 font-mono text-[11px] text-stone-500">{{ $product?->sku ?? 'SKU tidak tersedia' }}</p></div>
                                <form method="POST" action="{{ route('roi-calculator.items.destroy', $item) }}" onsubmit="return confirm('Hapus baris produk ini dari kalkulator?')" class="shrink-0">
                                    @csrf @method('DELETE')
                                    <button type="submit" aria-label="Hapus {{ $product?->name ?? 'produk' }} dari kalkulator" title="Hapus produk" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-stone-500 hover:bg-rose-50 hover:text-rose-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
                                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16m-10 4v6m4-6v6M5 7l1 13h12l1-13M9 7V4h6v3"/></svg>
                                    </button>
                                </form>
                            </div>
                            <dl class="mt-4 grid grid-cols-2 gap-2">
                                <div class="rounded-lg bg-red-50 px-3 py-2"><dt class="text-[10px] font-semibold uppercase tracking-wide text-red-800">Profit bersih</dt><dd class="mt-0.5 text-sm font-bold tabular-nums {{ $res['profit_bersih'] > 0 ? 'text-emerald-800' : 'text-rose-700' }}">{{ $rp($res['profit_bersih']) }}</dd></div>
                                <div class="rounded-lg bg-stone-50 px-3 py-2"><dt class="text-[10px] font-semibold uppercase tracking-wide text-stone-500">Harga jual</dt><dd class="mt-0.5 text-sm font-semibold tabular-nums text-stone-800">{{ $rp($in['selling_price']) }}</dd></div>
                                <div class="rounded-lg border border-stone-100 px-3 py-2"><dt class="text-[10px] font-semibold text-stone-500">Modal</dt><dd class="mt-0.5 text-xs font-semibold tabular-nums text-stone-800">{{ $rp($in['modal']) }}</dd></div>
                                <div class="rounded-lg border border-stone-100 px-3 py-2"><dt class="text-[10px] font-semibold text-stone-500">Total biaya</dt><dd class="mt-0.5 text-xs font-semibold tabular-nums text-stone-800">{{ $rp($res['total_biaya']) }}</dd></div>
                                <div class="rounded-lg border border-stone-100 px-3 py-2"><dt class="text-[10px] font-semibold text-stone-500">BEP ROI</dt><dd class="mt-0.5 text-xs font-semibold tabular-nums text-stone-800">{{ $roi($res['bep_roi']) }}</dd></div>
                                <div class="rounded-lg border border-stone-100 px-3 py-2"><dt class="text-[10px] font-semibold text-stone-500">Target min. / optimum</dt><dd class="mt-0.5 text-xs font-semibold tabular-nums text-stone-800">{{ $roi($res['avg_min']) }} <span class="text-stone-400">/</span> {{ $roi($res['avg_optimum']) }}</dd></div>
                            </dl>
                        </div>
                        <details class="group border-t border-stone-100">
                            @include('roi-calculator._item-details', ['row' => $row])
                        </details>
                    </article>
                @endforeach
                <div class="rounded-xl border border-red-100 bg-red-50 px-4 py-3">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-red-800">Rata-rata target semua produk</p>
                    <div class="mt-2 grid grid-cols-2 gap-3 text-xs"><p class="text-stone-600">Minimum <strong class="ml-1 tabular-nums text-stone-900">{{ $roi($summary['avg_min_all']) }}</strong></p><p class="text-stone-600">Optimum <strong class="ml-1 tabular-nums text-red-900">{{ $roi($summary['avg_optimum_all']) }}</strong></p></div>
                </div>
            </div>

            {{-- Desktop: tabel komparasi dengan kolom angka konsisten. --}}
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[68rem] text-xs tabular-nums">
                    <caption class="sr-only">Perbandingan harga, biaya, profit bersih, dan target ROI untuk setiap produk</caption>
                    <thead class="bg-stone-50 text-[10px] uppercase tracking-wide text-stone-500">
                        <tr>
                            <th scope="col" class="sticky left-0 z-10 bg-stone-50 px-4 py-3 text-left font-bold">Produk</th>
                            <th scope="col" class="px-3 py-3 text-right font-bold">Harga jual</th>
                            <th scope="col" class="px-3 py-3 text-right font-bold">Modal</th>
                            <th scope="col" class="px-3 py-3 text-right font-bold">Total biaya</th>
                            <th scope="col" class="px-3 py-3 text-right font-bold">Profit bersih</th>
                            <th scope="col" class="px-3 py-3 text-right font-bold">BEP ROI</th>
                            <th scope="col" class="px-3 py-3 text-right font-bold">Target min. 10%</th>
                            <th scope="col" class="px-3 py-3 text-right font-bold">Target optimum 20%</th>
                            <th scope="col" class="w-14 px-3 py-3"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    @foreach($rows as $row)
                        @php($product = $row['product'])
                        @php($item = $row['item'])
                        @php($in = $row['in'])
                        @php($res = $row['result'])
                        <tbody class="divide-y divide-stone-100">
                            <tr class="align-middle hover:bg-stone-50/70">
                                <th scope="row" class="sticky left-0 z-[1] bg-white px-4 py-3 text-left hover:bg-stone-50">
                                    <span class="block max-w-56 truncate font-semibold text-stone-900">{{ $product?->name ?? '(produk dihapus)' }}</span>
                                    <span class="mt-1 block font-mono text-[10px] font-normal text-stone-500">{{ $product?->sku }}</span>
                                </th>
                                <td class="whitespace-nowrap px-3 py-3 text-right text-stone-700">{{ $rp($in['selling_price']) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right text-stone-700">{{ $rp($in['modal']) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right text-stone-700">{{ $rp($res['total_biaya']) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-bold {{ $res['profit_bersih'] > 0 ? 'text-emerald-800' : 'text-rose-700' }}">{{ $rp($res['profit_bersih']) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-semibold text-stone-800">{{ $roi($res['bep_roi']) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right text-stone-700">{{ $roi($res['avg_min']) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-semibold text-red-800">{{ $roi($res['avg_optimum']) }}</td>
                                <td class="px-3 py-3 text-center">
                                    <form method="POST" action="{{ route('roi-calculator.items.destroy', $item) }}" onsubmit="return confirm('Hapus baris produk ini dari kalkulator?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" aria-label="Hapus {{ $product?->name ?? 'produk' }} dari kalkulator" title="Hapus produk" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-stone-500 hover:bg-rose-50 hover:text-rose-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
                                            <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16m-10 4v6m4-6v6M5 7l1 13h12l1-13M9 7V4h6v3"/></svg>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <tr class="bg-stone-50/50"><td colspan="9" class="px-3 py-1.5"><details class="group">@include('roi-calculator._item-details', ['row' => $row])</details></td></tr>
                        </tbody>
                    @endforeach
                    <tfoot class="border-t border-stone-200 bg-stone-50 text-xs font-semibold text-stone-700">
                        <tr>
                            <th scope="row" class="px-4 py-3 text-left">Rata-rata target semua produk</th>
                            <td colspan="5"></td>
                            <td class="whitespace-nowrap px-3 py-3 text-right">{{ $roi($summary['avg_min_all']) }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-right text-red-800">{{ $roi($summary['avg_optimum_all']) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @else
            <div class="px-5 py-12 text-center">
                <span class="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-stone-100 text-stone-500"><svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 14l4-4 3 3 6-7"/></svg></span>
                <h3 class="mt-3 text-sm font-bold text-stone-800">Belum ada hasil ROI</h3>
                <p class="mx-auto mt-1 max-w-sm text-xs leading-relaxed text-stone-500">Tambahkan produk dari katalog dan isi harga jual untuk mulai membandingkan profit serta target iklan.</p>
            </div>
        @endif
    </section>
</div>
@endsection
