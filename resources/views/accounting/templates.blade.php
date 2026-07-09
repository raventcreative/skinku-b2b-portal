@extends('layouts.app')
@section('title', 'Template Transaksi')
@section('heading', 'Template Transaksi')

@section('content')
<div class="flex justify-between items-center mb-5 gap-3 flex-wrap">
    <div>
        <a href="{{ route('accounting.journals') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Jurnal</a>
        <p class="text-xs text-stone-500 mt-1 max-w-xl">Preset jurnal untuk transaksi berulang. Akun beban/pendapatan dikunci; akun Kas/Bank bisa dibiarkan "pilih saat input".</p>
    </div>
    <button onclick="openTemplate()" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">+ Template</button>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-3">Nama Template</th>
                <th class="text-left">Baris (Debit / Kredit)</th>
                <th class="text-left">Status</th>
                <th class="text-right pr-4">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($templates as $t)
                <tr class="border-t border-stone-100">
                    <td class="px-4 py-2.5 font-semibold text-stone-800">{{ $t->name }}@if($t->description)<span class="block text-[10px] text-stone-400 font-normal">{{ $t->description }}</span>@endif</td>
                    <td class="text-stone-600">
                        @foreach($t->lines as $l)
                            <span class="inline-block mr-2 mb-0.5 px-2 py-0.5 rounded {{ $l->side==='debit' ? 'bg-sky-50 text-sky-700' : 'bg-amber-50 text-amber-700' }}">
                                {{ strtoupper(substr($l->side,0,1)) }}: {{ $l->account?->name ?? 'Kas/Bank (pilih saat input)' }}
                            </span>
                        @endforeach
                    </td>
                    <td>@if($t->is_active)<span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[10px] font-bold">Aktif</span>@else<span class="px-2 py-0.5 rounded-full bg-stone-200 text-stone-600 text-[10px] font-bold">Nonaktif</span>@endif</td>
                    <td class="text-right pr-4 whitespace-nowrap">
                        <button class="text-stone-500 hover:text-stone-900 font-semibold" onclick='openTemplate({{ json_encode(["id"=>$t->id,"name"=>$t->name,"description"=>$t->description,"is_active"=>$t->is_active,"lines"=>$t->lines->map(fn($l)=>["account_id"=>$l->account_id,"side"=>$l->side])->values()]) }})'>Edit</button>
                        <form method="POST" action="{{ route('accounting.templates.destroy', $t) }}" class="inline ml-2" onsubmit="return confirm('Hapus template ini?')">
                            @csrf @method('DELETE')
                            <button class="text-rose-600 hover:text-rose-800 font-semibold">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-stone-400">Belum ada template. Klik "+ Template".</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

{{-- Modal --}}
<div id="tplModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-2xl p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 id="tplModalTitle" class="text-sm font-bold text-stone-900">Tambah Template</h3>
            <button onclick="toggleModal('tplModal')" class="text-stone-400 hover:text-stone-700">✕</button>
        </div>
        <form method="POST" id="tplForm" action="{{ route('accounting.templates.store') }}" class="space-y-3 text-sm">
            @csrf
            <input type="hidden" name="_method" id="tplMethod" value="POST">
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-xs font-semibold mb-1">Nama Template *</label><input name="name" required placeholder="mis. Bayar Listrik" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
                <div><label class="block text-xs font-semibold mb-1">Deskripsi</label><input name="description" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            </div>
            <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="is_active" id="tplActive" value="1" checked class="accent-red-600"> Aktif</label>

            <div class="border border-stone-200 rounded-xl overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 bg-stone-50 border-b border-stone-100">
                    <span class="text-xs font-bold text-stone-700">Baris Template</span>
                    <button type="button" onclick="addLine()" class="px-2.5 py-1 text-[11px] bg-stone-800 text-white rounded">+ Baris</button>
                </div>
                <table class="w-full text-xs">
                    <thead class="text-stone-400 text-[10px] uppercase"><tr><th class="text-left px-3 py-2">Akun</th><th class="text-left w-28">Sisi</th><th class="w-8"></th></tr></thead>
                    <tbody id="tplLines"></tbody>
                </table>
            </div>
            <p class="text-[10px] text-stone-400">Pilih "— Kas/Bank: pilih saat input —" pada baris yang akunnya ditentukan belakangan (mis. sumber pembayaran).</p>

            <div class="flex justify-end gap-2 mt-2">
                <button type="button" onclick="toggleModal('tplModal')" class="px-4 py-2 text-stone-600 rounded-lg">Batal</button>
                <button class="px-5 py-2 bg-red-600 text-white rounded-lg">Simpan</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const TPL_ACCOUNTS = {{ \Illuminate\Support\Js::from($accounts->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])) }};
    let tli = 0;

    function tplAccOptions(sel) {
        let h = '<option value="">— Kas/Bank: pilih saat input —</option>';
        TPL_ACCOUNTS.forEach(a => h += `<option value="${a.id}" ${a.id == sel ? 'selected' : ''}>${a.code} · ${a.name}</option>`);
        return h;
    }

    function addLine(accountId, side) {
        const i = tli++;
        const tr = document.createElement('tr');
        tr.className = 'border-t border-stone-100';
        tr.innerHTML = `
            <td class="px-3 py-1.5"><select name="lines[${i}][account_id]" class="w-full px-2 py-1.5 border border-stone-300 rounded-lg">${tplAccOptions(accountId)}</select></td>
            <td class="px-1"><select name="lines[${i}][side]" class="w-full px-2 py-1.5 border border-stone-300 rounded-lg"><option value="debit" ${side==='debit'?'selected':''}>Debit</option><option value="credit" ${side==='credit'?'selected':''}>Kredit</option></select></td>
            <td class="text-right pr-2"><button type="button" onclick="this.closest('tr').remove()" class="text-rose-600 font-bold">✕</button></td>`;
        document.getElementById('tplLines').appendChild(tr);
    }

    function openTemplate(t) {
        const f = document.getElementById('tplForm');
        f.reset();
        document.getElementById('tplLines').innerHTML = '';
        if (t) {
            f.action = '/accounting/template/' + t.id;
            document.getElementById('tplMethod').value = 'PUT';
            document.getElementById('tplModalTitle').textContent = 'Edit Template';
            f.querySelector('[name=name]').value = t.name ?? '';
            f.querySelector('[name=description]').value = t.description ?? '';
            document.getElementById('tplActive').checked = !!t.is_active;
            (t.lines || []).forEach(l => addLine(l.account_id, l.side));
        } else {
            f.action = '{{ route('accounting.templates.store') }}';
            document.getElementById('tplMethod').value = 'POST';
            document.getElementById('tplModalTitle').textContent = 'Tambah Template';
            document.getElementById('tplActive').checked = true;
            addLine(null, 'debit');
            addLine(null, 'credit');
        }
        toggleModal('tplModal');
    }
</script>
@endpush
