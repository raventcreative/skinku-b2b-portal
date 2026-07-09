@extends('layouts.app')
@section('title', 'Supplier')
@section('heading', 'Master Supplier')

@section('content')
<div class="flex justify-between items-center mb-5 gap-3 flex-wrap">
    <p class="text-xs text-stone-500 max-w-xl">Daftar supplier untuk pembelian bahan baku. Dipakai di form "Beli Bahan".</p>
    <button onclick="openSupplier()" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">+ Supplier</button>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-3">Nama Supplier</th>
                <th class="text-left">Telepon</th>
                <th class="text-left">Alamat</th>
                <th class="text-left">Status</th>
                <th class="pr-4"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($suppliers as $s)
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    <td class="px-4 py-2.5 font-semibold text-stone-800">{{ $s->name }}</td>
                    <td class="text-stone-600">{{ $s->phone ?: '—' }}</td>
                    <td class="text-stone-500">{{ $s->address ?: '—' }}</td>
                    <td>@if($s->status==='active')<span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[10px] font-bold">Aktif</span>@else<span class="px-2 py-0.5 rounded-full bg-stone-200 text-stone-600 text-[10px] font-bold">Nonaktif</span>@endif</td>
                    <td class="pr-4 text-right whitespace-nowrap">
                        <button class="text-stone-500 hover:text-stone-900 font-semibold" onclick='openSupplier({{ json_encode($s->only(["id","name","phone","address","notes","status"])) }})'>Edit</button>
                        <form method="POST" action="{{ route('suppliers.destroy', $s) }}" class="inline ml-2" onsubmit="return confirm('Hapus supplier ini?')">
                            @csrf @method('DELETE')
                            <button class="text-rose-600 hover:text-rose-800 font-semibold">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-stone-400">Belum ada supplier. Klik "+ Supplier".</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

{{-- Supplier modal --}}
<div id="supplierModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-md p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 id="supplierModalTitle" class="text-sm font-bold text-stone-900">Tambah Supplier</h3>
            <button onclick="toggleModal('supplierModal')" class="text-stone-400 hover:text-stone-700">✕</button>
        </div>
        <form method="POST" id="supplierForm" action="{{ route('suppliers.store') }}" class="space-y-3 text-sm">
            @csrf
            <input type="hidden" name="_method" id="supplierMethod" value="POST">
            <div><label class="block text-xs font-semibold mb-1">Nama Supplier *</label><input name="name" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-xs font-semibold mb-1">Telepon</label><input name="phone" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
                <div id="supStatusWrap" class="hidden"><label class="block text-xs font-semibold mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 border border-stone-300 rounded-lg"><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select>
                </div>
            </div>
            <div><label class="block text-xs font-semibold mb-1">Alamat</label><input name="address" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div><label class="block text-xs font-semibold mb-1">Catatan</label><input name="notes" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div class="flex justify-end gap-2 mt-2">
                <button type="button" onclick="toggleModal('supplierModal')" class="px-4 py-2 text-stone-600 rounded-lg">Batal</button>
                <button class="px-5 py-2 bg-red-600 text-white rounded-lg">Simpan</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function openSupplier(s) {
        const f = document.getElementById('supplierForm');
        f.reset();
        const statusWrap = document.getElementById('supStatusWrap');
        if (s) {
            f.action = '/suppliers/' + s.id;
            document.getElementById('supplierMethod').value = 'PUT';
            document.getElementById('supplierModalTitle').textContent = 'Edit Supplier';
            f.querySelector('[name=name]').value = s.name ?? '';
            f.querySelector('[name=phone]').value = s.phone ?? '';
            f.querySelector('[name=address]').value = s.address ?? '';
            f.querySelector('[name=notes]').value = s.notes ?? '';
            f.querySelector('[name=status]').value = s.status ?? 'active';
            statusWrap.classList.remove('hidden');
        } else {
            f.action = '{{ route('suppliers.store') }}';
            document.getElementById('supplierMethod').value = 'POST';
            document.getElementById('supplierModalTitle').textContent = 'Tambah Supplier';
            statusWrap.classList.add('hidden');
        }
        toggleModal('supplierModal');
    }
</script>
@endpush
