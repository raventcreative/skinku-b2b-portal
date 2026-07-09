@extends('layouts.app')
@section('title', 'Master COA')
@section('heading', 'Master COA (Chart of Account)')

@section('content')
<div class="flex justify-between items-center mb-5 gap-3 flex-wrap">
    <div>
        <a href="{{ route('accounting.journals') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Jurnal</a>
        <p class="text-xs text-stone-500 mt-1 max-w-xl">Daftar akun. Tipe & subtipe menentukan pengelompokan di Laba Rugi / Neraca — isi dengan benar agar akun baru masuk laporan yang tepat.</p>
    </div>
    <button onclick="openAccount()" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">+ Akun</button>
</div>

@php
    $typeLabels = ['asset'=>'ASET','liability'=>'LIABILITAS','equity'=>'EKUITAS','revenue'=>'PENDAPATAN','expense'=>'BEBAN'];
    $grouped = $accounts->groupBy('type');
@endphp

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-3">Kode</th>
                <th class="text-left">Nama Akun</th>
                <th class="text-left">Tipe</th>
                <th class="text-left">Subtipe</th>
                <th class="text-left">Saldo Normal</th>
                <th class="text-left">Status</th>
                <th class="text-right pr-4">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @foreach($typeLabels as $type => $label)
                @if(($grouped[$type] ?? collect())->isNotEmpty())
                    <tr class="bg-stone-100/70"><td colspan="7" class="px-4 py-1.5 text-[10px] font-bold text-stone-500 uppercase tracking-wide">{{ $label }}</td></tr>
                    @foreach($grouped[$type] as $a)
                        <tr class="border-t border-stone-100 hover:bg-stone-50 {{ $a->is_active ? '' : 'opacity-50' }}">
                            <td class="px-4 py-2 text-stone-500">{{ $a->code }}</td>
                            <td class="font-semibold text-stone-800">{{ $a->name }}</td>
                            <td><span class="px-2 py-0.5 rounded-full bg-stone-100 text-stone-600 text-[10px] font-semibold">{{ ucfirst(strtolower($label)) }}</span></td>
                            <td class="text-stone-500">{{ $a->subtype ?: '—' }}</td>
                            <td class="text-stone-500">{{ $a->normal_balance === 'debit' ? 'Debit' : 'Kredit' }}</td>
                            <td>@if($a->is_active)<span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[10px] font-bold">Aktif</span>@else<span class="px-2 py-0.5 rounded-full bg-stone-200 text-stone-600 text-[10px] font-bold">Nonaktif</span>@endif</td>
                            <td class="text-right pr-4 whitespace-nowrap">
                                <button class="text-stone-500 hover:text-stone-900 font-semibold" onclick='openAccount({{ json_encode($a->only(["id","code","name","type","subtype","normal_balance","is_active"])) }})'>Edit</button>
                                <form method="POST" action="{{ route('accounting.accounts.destroy', $a) }}" class="inline ml-2" onsubmit="return confirm('Hapus akun ini?')">
                                    @csrf @method('DELETE')
                                    <button class="text-rose-600 hover:text-rose-800 font-semibold">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                @endif
            @endforeach
        </tbody>
    </table>
    </div>
</div>

{{-- Modal --}}
<div id="accModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-md p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 id="accModalTitle" class="text-sm font-bold text-stone-900">Tambah Akun</h3>
            <button onclick="toggleModal('accModal')" class="text-stone-400 hover:text-stone-700">✕</button>
        </div>
        <form method="POST" id="accForm" action="{{ route('accounting.accounts.store') }}" class="space-y-3 text-sm">
            @csrf
            <input type="hidden" name="_method" id="accMethod" value="POST">
            <div class="grid grid-cols-3 gap-3">
                <div><label class="block text-xs font-semibold mb-1">Kode *</label><input name="code" required placeholder="mis. 6014" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
                <div class="col-span-2"><label class="block text-xs font-semibold mb-1">Nama Akun *</label><input name="name" required placeholder="mis. Beban Internet" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-xs font-semibold mb-1">Tipe *</label>
                    <select name="type" id="accType" onchange="onTypeChange()" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
                        <option value="asset">Aset</option><option value="liability">Liabilitas</option><option value="equity">Ekuitas</option><option value="revenue">Pendapatan</option><option value="expense">Beban</option>
                    </select>
                </div>
                <div><label class="block text-xs font-semibold mb-1">Saldo Normal *</label>
                    <select name="normal_balance" id="accNormal" class="w-full px-3 py-2 border border-stone-300 rounded-lg"><option value="debit">Debit</option><option value="credit">Kredit</option></select>
                </div>
            </div>
            <div><label class="block text-xs font-semibold mb-1">Subtipe <span class="text-stone-400 font-normal">(menentukan grup laporan)</span></label>
                <select name="subtype" id="accSubtype" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></select>
            </div>
            <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="is_active" id="accActive" value="1" checked class="accent-red-600"> Aktif</label>
            <div class="flex justify-end gap-2 mt-2">
                <button type="button" onclick="toggleModal('accModal')" class="px-4 py-2 text-stone-600 rounded-lg">Batal</button>
                <button class="px-5 py-2 bg-red-600 text-white rounded-lg">Simpan</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Subtipe per tipe (mengikuti klasifikasi laporan). '' = umum/tanpa subtipe.
    const SUBTYPES = {
        asset:     [['cash','Kas'],['bank','Bank'],['receivable','Piutang'],['inventory','Persediaan'],['prepaid','Dibayar Dimuka'],['fixed_asset','Aset Tetap'],['contra_asset','Akum. Penyusutan (kontra)']],
        liability: [['current','Lancar'],['long_term','Jangka Panjang'],['unearned','Diterima Dimuka']],
        equity:    [['','Umum'],['contra_equity','Prive (kontra)'],['closing','Ikhtisar L/R']],
        revenue:   [['sales','Penjualan'],['shipping','Ongkir'],['other','Lain-lain'],['contra_revenue','Retur/Potongan (kontra)']],
        expense:   [['cogs','HPP'],['contra_cogs','Retur Pembelian (kontra)'],['operating','Operasional'],['non_operating','Non-operasional'],['tax','Pajak']],
    };
    const DEFAULT_NORMAL = { asset:'debit', expense:'debit', liability:'credit', equity:'credit', revenue:'credit' };

    function fillSubtypes(type, selected) {
        const sel = document.getElementById('accSubtype');
        sel.innerHTML = '';
        (SUBTYPES[type] || []).forEach(([v, label]) => {
            const o = document.createElement('option');
            o.value = v; o.textContent = label;
            if (v === (selected ?? '')) o.selected = true;
            sel.appendChild(o);
        });
    }

    function onTypeChange() {
        const type = document.getElementById('accType').value;
        fillSubtypes(type, null);
        document.getElementById('accNormal').value = DEFAULT_NORMAL[type] || 'debit';
    }

    function openAccount(a) {
        const f = document.getElementById('accForm');
        f.reset();
        if (a) {
            f.action = '/accounting/coa/' + a.id;
            document.getElementById('accMethod').value = 'PUT';
            document.getElementById('accModalTitle').textContent = 'Edit Akun';
            f.querySelector('[name=code]').value = a.code ?? '';
            f.querySelector('[name=name]').value = a.name ?? '';
            document.getElementById('accType').value = a.type;
            fillSubtypes(a.type, a.subtype ?? '');
            document.getElementById('accNormal').value = a.normal_balance;
            document.getElementById('accActive').checked = !!a.is_active;
        } else {
            f.action = '{{ route('accounting.accounts.store') }}';
            document.getElementById('accMethod').value = 'POST';
            document.getElementById('accModalTitle').textContent = 'Tambah Akun';
            document.getElementById('accType').value = 'expense';
            onTypeChange();
            document.getElementById('accActive').checked = true;
        }
        toggleModal('accModal');
    }
</script>
@endpush
