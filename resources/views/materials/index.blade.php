@extends('layouts.app')
@section('title', 'Bahan Baku')
@section('heading', 'Bahan Baku & Stok')

@section('content')
<div class="flex justify-between items-center mb-5 gap-3 flex-wrap">
    <p class="text-xs text-stone-500 max-w-xl">Master bahan baku beserta stok dan HPP rata-ratanya. "Beli Bahan" menambah stok bahan dan memperbarui HPP bahan (rata-rata bergerak).</p>
    <div class="flex gap-2 flex-wrap">
        <button onclick="toggleModal('buyModal')" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">+ Beli Bahan</button>
        <button onclick="openMaterial()" class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">+ Bahan Baku</button>
    </div>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Daftar Bahan Baku</div>
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-3">Nama Bahan</th>
                <th class="text-left">Satuan</th>
                <th class="text-right">Stok</th>
                <th class="text-right">HPP Rata-rata / unit</th>
                <th class="text-right">Nilai Stok</th>
                <th class="text-left">Status</th>
                <th class="pr-4"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($materials as $m)
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    <td class="px-4 py-2.5 font-semibold text-stone-800">{{ $m->name }}</td>
                    <td class="text-stone-500">{{ $m->unit }}</td>
                    <td class="text-right text-stone-700">{{ rtrim(rtrim(number_format($m->stock, 3, ',', '.'), '0'), ',') }}</td>
                    <td class="text-right text-stone-700">Rp {{ number_format($m->avg_cost, 0, ',', '.') }}</td>
                    <td class="text-right text-stone-500">Rp {{ number_format($m->stock * $m->avg_cost, 0, ',', '.') }}</td>
                    <td>@if($m->status==='active')<span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[10px] font-bold">Aktif</span>@else<span class="px-2 py-0.5 rounded-full bg-stone-200 text-stone-600 text-[10px] font-bold">Nonaktif</span>@endif</td>
                    <td class="pr-4 text-right whitespace-nowrap">
                        <button class="text-stone-500 hover:text-stone-900 font-semibold"
                            onclick='openMaterial({{ json_encode($m->only(["id","name","unit","status","notes","stock"])) }})'>Edit</button>
                        <form method="POST" action="{{ route('materials.destroy', $m) }}" class="inline ml-2" onsubmit="return confirm('Hapus bahan baku ini? Riwayat produksi/pembelian yang sudah pakai bahan ini tetap aman.')">
                            @csrf @method('DELETE')
                            <button class="text-rose-600 hover:text-rose-800 font-semibold">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-stone-400">Belum ada bahan baku. Klik "+ Bahan Baku".</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

<h3 class="text-sm font-bold text-stone-800 mt-8 mb-3">Riwayat Beli Bahan</h3>
<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-3">Tanggal</th>
                <th class="text-left">Bahan</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Harga / unit</th>
                <th class="text-right">Subtotal</th>
                <th class="text-right">HPP → jadi</th>
                <th class="text-left">Supplier</th>
            </tr>
        </thead>
        <tbody>
            @forelse($purchases as $p)
                <tr class="border-t border-stone-100">
                    <td class="px-4 py-2 text-stone-600">{{ $p->purchased_at?->format('d M Y') }}</td>
                    <td class="font-semibold text-stone-800">{{ $p->material_name }}</td>
                    <td class="text-right text-stone-600">{{ rtrim(rtrim(number_format($p->quantity, 3, ',', '.'), '0'), ',') }}</td>
                    <td class="text-right text-stone-600">Rp {{ number_format($p->unit_cost, 0, ',', '.') }}</td>
                    <td class="text-right font-semibold text-stone-800">Rp {{ number_format($p->subtotal, 0, ',', '.') }}</td>
                    <td class="text-right text-stone-400">Rp {{ number_format($p->cost_before, 0, ',', '.') }} → <span class="text-emerald-700 font-semibold">Rp {{ number_format($p->cost_after, 0, ',', '.') }}</span></td>
                    <td class="text-stone-500">{{ $p->supplier_name ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-stone-400">Belum ada pembelian bahan.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-4">{{ $purchases->links() }}</div>

{{-- Material master modal --}}
<div id="materialModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-md p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 id="materialModalTitle" class="text-sm font-bold text-stone-900">Tambah Bahan Baku</h3>
            <button onclick="toggleModal('materialModal')" class="text-stone-400 hover:text-stone-700">✕</button>
        </div>
        <form method="POST" id="materialForm" action="{{ route('materials.store') }}" class="space-y-3 text-sm">
            @csrf
            <input type="hidden" name="_method" id="materialMethod" value="POST">
            <div><label class="block text-xs font-semibold mb-1">Nama Bahan *</label><input name="name" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-xs font-semibold mb-1">Satuan *</label><input name="unit" list="unitList" required placeholder="pcs / kg / botol / ml" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
                    <datalist id="unitList"><option value="pcs"><option value="kg"><option value="gram"><option value="botol"><option value="ml"><option value="liter"></datalist>
                </div>
                <div id="statusWrap" class="hidden"><label class="block text-xs font-semibold mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 border border-stone-300 rounded-lg"><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select>
                </div>
            </div>
            <div id="hppWrap" class="hidden"><label class="block text-xs font-semibold mb-1">HPP / unit <span class="text-stone-400 font-normal">(isi untuk set manual; kosongkan = tidak diubah)</span></label><input type="number" step="0.01" min="0" name="avg_cost" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div id="stockWrap" class="hidden space-y-3 border-t border-stone-100 pt-3">
                <p class="text-xs text-stone-500">Stok saat ini: <b id="curStock" class="text-stone-800">—</b></p>
                <div>
                    <label class="block text-xs font-semibold mb-1">Sesuaikan Stok <span class="text-stone-400 font-normal">(isi angka stok yang benar; kosongkan = tidak diubah)</span></label>
                    <input type="number" step="0.001" name="stock" placeholder="mis. hasil stok opname" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1">Alasan Penyesuaian <span class="text-stone-400 font-normal">(wajib jika stok diubah)</span></label>
                    <input name="adjustment_reason" placeholder="mis. stok opname / koreksi stok awal" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
                </div>
            </div>
            <div><label class="block text-xs font-semibold mb-1">Catatan</label><input name="notes" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div class="flex justify-end gap-2 mt-2">
                <button type="button" onclick="toggleModal('materialModal')" class="px-4 py-2 text-stone-600 rounded-lg">Batal</button>
                <button class="px-5 py-2 bg-red-600 text-white rounded-lg">Simpan</button>
            </div>
        </form>
    </div>
</div>

{{-- Buy material modal --}}
<div id="buyModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-md p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-sm font-bold text-stone-900">Beli / Tambah Stok Bahan</h3>
            <button onclick="toggleModal('buyModal')" class="text-stone-400 hover:text-stone-700">✕</button>
        </div>
        <form method="POST" action="{{ route('materials.purchase') }}" class="space-y-3 text-sm">
            @csrf
            <div><label class="block text-xs font-semibold mb-1">Bahan *</label>
                <select name="material_id" required class="w-full px-3 py-2 border border-stone-300 rounded-lg">
                    <option value="">— pilih bahan —</option>
                    @foreach($materials as $m)<option value="{{ $m->id }}">{{ $m->name }} ({{ $m->unit }})</option>@endforeach
                </select>
                @if($materials->isEmpty())<p class="text-[10px] text-rose-500 mt-1">Belum ada bahan. Tambah bahan baku dulu.</p>@endif
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-xs font-semibold mb-1">Qty *</label><input type="number" step="0.001" min="0.001" name="quantity" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
                <div><label class="block text-xs font-semibold mb-1">Harga / unit *</label><input type="number" step="0.01" min="0" name="unit_cost" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">Metode HPP bahan *</label>
                <div class="space-y-1.5">
                    <label class="flex items-start gap-2"><input type="radio" name="cost_mode" value="average" checked class="mt-0.5 accent-red-600"><span>Rata-rata bergerak <span class="text-stone-400">— campur dengan stok lama (default)</span></span></label>
                    <label class="flex items-start gap-2"><input type="radio" name="cost_mode" value="direct" class="mt-0.5 accent-red-600"><span>Harga langsung <span class="text-stone-400">— HPP = harga di atas (harga asli supplier, tidak dicampur)</span></span></label>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-xs font-semibold mb-1">Tanggal *</label><input type="date" name="purchased_at" value="{{ date('Y-m-d') }}" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
                <div><label class="block text-xs font-semibold mb-1">Supplier</label>
                    <select name="supplier_id" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
                        <option value="">— tanpa supplier —</option>
                        @foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                    </select>
                    @if($suppliers->isEmpty())<p class="text-[10px] text-stone-400 mt-1">Belum ada supplier — tambahkan di menu <b>Supplier</b>.</p>@endif
                </div>
            </div>
            <div><label class="block text-xs font-semibold mb-1">Catatan</label><input name="notes" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div class="flex justify-end gap-2 mt-2">
                <button type="button" onclick="toggleModal('buyModal')" class="px-4 py-2 text-stone-600 rounded-lg">Batal</button>
                <button class="px-5 py-2 bg-red-600 text-white rounded-lg">Simpan</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function openMaterial(m) {
        const f = document.getElementById('materialForm');
        f.reset();
        const statusWrap = document.getElementById('statusWrap');
        const hppWrap = document.getElementById('hppWrap');
        const stockWrap = document.getElementById('stockWrap');
        f.querySelector('[name=avg_cost]').value = '';
        f.querySelector('[name=stock]').value = '';
        f.querySelector('[name=adjustment_reason]').value = '';
        if (m) {
            f.action = '/materials/' + m.id;
            document.getElementById('materialMethod').value = 'PUT';
            document.getElementById('materialModalTitle').textContent = 'Edit Bahan Baku';
            f.querySelector('[name=name]').value = m.name ?? '';
            f.querySelector('[name=unit]').value = m.unit ?? '';
            f.querySelector('[name=notes]').value = m.notes ?? '';
            f.querySelector('[name=status]').value = m.status ?? 'active';
            document.getElementById('curStock').textContent = (m.stock ?? '0') + ' ' + (m.unit ?? '');
            statusWrap.classList.remove('hidden');
            hppWrap.classList.remove('hidden');
            stockWrap.classList.remove('hidden');
        } else {
            f.action = '{{ route('materials.store') }}';
            document.getElementById('materialMethod').value = 'POST';
            document.getElementById('materialModalTitle').textContent = 'Tambah Bahan Baku';
            statusWrap.classList.add('hidden');
            hppWrap.classList.add('hidden');
            stockWrap.classList.add('hidden');
        }
        toggleModal('materialModal');
    }
</script>
@endpush
