@php
    use App\Support\Barcode;
    $totalQty = $po->items->sum('qty');
    $recipientName = $po->company_name ?: ($po->user->fullname ?? '-');
    $recipientAddr = $po->shipping_address ?: ($po->user->address ?? '-');
    $recipientCity = $po->user->city ?? '';
    $recipientPhone = $po->user->phone ?? '';
    $noResi = trim((string) $po->no_resi);
    $logoSrc = asset('img/skinku-logo.jpg');
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $tanggal = $po->orderDate()->format('d M Y');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Cetak {{ $po->po_number }}</title>
    <style>
        @page { size: {{ $size }}; margin: 4mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #000; margin: 0; font-size: 11px; }
        /* Dokumen mengalir berurutan; hanya Faktur yang wajib mulai di lembar baru.
           Label + Daftar Pengemasan boleh berbagi satu lembar (hemat kertas) kalau muat,
           dipisah garis potong putus-putus. */
        .doc-page { padding: 2mm; }
        .doc-page + .doc-page:not(.doc-faktur) { margin-top: 6mm; padding-top: 6mm; border-top: 1px dashed #999; }
        .doc-faktur { break-before: page; page-break-before: always; }
        .bd { border: 1px solid #000; }
        .row { display: flex; justify-content: space-between; gap: 8px; }
        .muted { color: #333; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
        .big { font-size: 15px; font-weight: 700; }
        /* Tanpa garis pemisah antar bagian — sisakan bingkai luar (.bd) saja biar lega. */
        .sec { padding: 6px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 3px 4px; border-bottom: 1px solid #ccc; }
        th { font-size: 9px; text-transform: uppercase; color: #333; }
        td.num, th.num { text-align: right; }
        .barcode svg { width: 100%; height: 46px; }
        .totrow { display: flex; justify-content: space-between; padding: 2px 4px; }
        .totrow.grand { font-weight: 700; font-size: 13px; border-top: 1px solid #000; margin-top: 2px; padding-top: 4px; }
        h2.doc-title { font-size: 13px; margin: 0 0 4px; }
        @media screen { body { background: #eee; } .doc-page { background: #fff; margin: 8px auto; max-width: 480px; box-shadow: 0 1px 4px rgba(0,0,0,.2); } }
    </style>
</head>
<body>

@if(in_array('label', $docs, true))
    <div class="doc-page">
        <div class="bd">
            <div class="sec" style="text-align:center; padding:10px 6px; border-bottom:0;">
                <img src="{{ $logoSrc }}" alt="SKINKU Official" style="height:44px; width:auto; object-fit:contain; display:inline-block;">
            </div>
            <div class="sec row">
                <div><span class="muted">Kurir</span><div class="big">{{ $po->kurir ?: '—' }}</div></div>
                <div style="text-align:right"><span class="muted">No. PO</span><div>{{ $po->po_number }}</div><div class="muted">{{ $tanggal }}</div></div>
            </div>
            <div class="sec">
                <span class="muted">Pengirim</span>
                <div><strong>{{ $sender['name'] ?: '—' }}</strong></div>
                <div>{{ $sender['address'] }}{{ $sender['city'] ? ', '.$sender['city'] : '' }}</div>
                <div>{{ $sender['phone'] }}</div>
            </div>
            <div class="sec">
                <span class="muted">Penerima</span>
                <div class="big">{{ $recipientName }}</div>
                <div>{{ $recipientAddr }}{{ $recipientCity ? ', '.$recipientCity : '' }}</div>
                <div>{{ $recipientPhone }}</div>
            </div>
            <div class="sec">
                @if($noResi !== '')
                    <div class="barcode">{!! Barcode::code128($noResi) !!}</div>
                    <div style="text-align:center; font-family: monospace; letter-spacing: 2px;">{{ $noResi }}</div>
                @else
                    <div style="text-align:center; padding:14px 4px; color:#b91c1c; font-weight:700; letter-spacing:1px;">— Resi belum diisi —</div>
                @endif
            </div>
            <div class="sec row">
                <div><span class="muted">Jumlah</span> <strong>{{ $totalQty }} pcs</strong></div>
            </div>
        </div>
    </div>
@endif

@if(in_array('packing', $docs, true))
    <div class="doc-page">
        <h2 class="doc-title">Daftar Pengemasan</h2>
        <div class="row" style="margin-bottom:4px">
            <div><span class="muted">No. PO</span> {{ $po->po_number }}</div>
            <div><span class="muted">Tanggal</span> {{ $tanggal }}</div>
        </div>
        <div style="margin-bottom:4px"><span class="muted">Mitra</span> {{ $recipientName }}</div>
        <table>
            <thead><tr><th>Produk</th><th>SKU</th><th class="num">Qty</th></tr></thead>
            <tbody>
                @foreach($po->items as $it)
                    <tr><td>{{ $it->product_name }}</td><td>{{ $it->sku }}</td><td class="num">{{ $it->qty }}</td></tr>
                @endforeach
            </tbody>
            <tfoot><tr><td colspan="2" class="num"><strong>Total</strong></td><td class="num"><strong>{{ $totalQty }}</strong></td></tr></tfoot>
        </table>
    </div>
@endif

@if(in_array('faktur', $docs, true))
    <div class="doc-page doc-faktur">
        <div class="row" style="margin-bottom:6px">
            <div><strong>{{ $sender['name'] ?: config('app.name') }}</strong><div class="muted">{{ $sender['address'] }}</div></div>
            <div style="text-align:right"><h2 class="doc-title">FAKTUR / NOTA</h2><div>{{ $po->po_number }}</div><div class="muted">{{ $tanggal }}</div></div>
        </div>
        <div style="margin-bottom:6px"><span class="muted">Kepada</span> <strong>{{ $recipientName }}</strong><div>{{ $recipientAddr }}</div></div>
        <table>
            <thead><tr><th>Produk</th><th class="num">Qty</th><th class="num">Harga</th><th class="num">Subtotal</th></tr></thead>
            <tbody>
                @foreach($po->items as $it)
                    <tr>
                        <td>{{ $it->product_name }}</td>
                        <td class="num">{{ $it->qty }}</td>
                        <td class="num">{{ $rp($it->unit_price) }}</td>
                        <td class="num">{{ $rp($it->total_price) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div style="margin-top:6px">
            <div class="totrow"><span>Subtotal</span><span>{{ $rp($po->subtotal) }}</span></div>
            <div class="totrow"><span>Diskon</span><span>{{ $rp($po->discount) }}</span></div>
            <div class="totrow"><span>Ongkir</span><span>{{ $rp($po->shipping_cost) }}</span></div>
            <div class="totrow grand"><span>Total</span><span>{{ $rp($po->total_amount) }}</span></div>
            <div class="totrow"><span class="muted">Status Bayar</span><span>{{ $po->isPaid() ? 'LUNAS' : 'BELUM LUNAS' }}</span></div>
        </div>
    </div>
@endif

<script>window.onload = function () { window.print(); };</script>
</body>
</html>
