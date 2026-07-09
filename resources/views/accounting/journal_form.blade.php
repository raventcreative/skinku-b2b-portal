@extends('layouts.app')
@section('title', 'Input Jurnal')
@section('heading', 'Input Jurnal Umum')

@section('content')
<a href="{{ route('accounting.journals') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke daftar jurnal</a>

@if(! $branch)
    <div class="mt-3 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs">Belum ada cabang/akun. Jalankan seeder Chart of Account dulu.</div>
@else
<form method="POST" action="{{ route('accounting.journals.store') }}" class="mt-3 space-y-5" id="jurnalForm">
    @csrf
    <input type="hidden" name="branch_id" value="{{ $branch->id }}">

    <div class="bg-white rounded-2xl border border-stone-200 p-5 grid sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
        <div><label class="block text-xs font-semibold mb-1">Tanggal *</label><input type="date" name="date" value="{{ old('date', date('Y-m-d')) }}" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
        <div><label class="block text-xs font-semibold mb-1">Referensi</label><input name="reference" value="{{ old('reference') }}" placeholder="No. bukti / sumber" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
        <div><label class="block text-xs font-semibold mb-1">Tipe</label>
            <select name="type" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
                @foreach(['general'=>'Umum','sales'=>'Penjualan','purchase'=>'Pembelian','cash_in'=>'Kas Masuk','cash_out'=>'Kas Keluar','inventory'=>'Persediaan','adjustment'=>'Penyesuaian'] as $v=>$l)
                    <option value="{{ $v }}" @selected(old('type')===$v)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div><label class="block text-xs font-semibold mb-1">Deskripsi</label><input name="description" value="{{ old('description') }}" placeholder="Keterangan" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between">
            <h3 class="text-sm font-bold text-stone-800">Baris Jurnal</h3>
            <button type="button" onclick="addRow()" class="px-3 py-1.5 text-xs bg-stone-800 text-white rounded-lg hover:bg-stone-900">+ Baris</button>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr>
                    <th class="text-left px-4 py-3">Akun</th>
                    <th class="text-left">Memo</th>
                    <th class="text-right">Debit</th>
                    <th class="text-right">Kredit</th>
                    <th class="pr-4"></th>
                </tr>
            </thead>
            <tbody id="rows"></tbody>
            <tfoot>
                <tr class="border-t-2 border-stone-200 bg-stone-50 font-bold text-stone-900">
                    <td class="px-4 py-3" colspan="2">TOTAL</td>
                    <td class="text-right" id="sumDebit">Rp 0</td>
                    <td class="text-right" id="sumCredit">Rp 0</td>
                    <td class="pr-4"></td>
                </tr>
                <tr>
                    <td colspan="5" class="px-4 py-2 text-right">
                        <span id="balanceMsg" class="text-xs font-semibold"></span>
                    </td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>

    <div class="flex justify-end gap-2">
        <a href="{{ route('accounting.journals') }}" class="px-4 py-2 text-sm text-stone-600 rounded-lg">Batal</a>
        <button id="saveBtn" class="px-6 py-2.5 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">Simpan Jurnal</button>
    </div>
</form>
@endif
@endsection

@push('scripts')
<script>
    const ACCOUNTS = {{ \Illuminate\Support\Js::from($accounts->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])) }};
    let ri = 0;
    const rupiah = n => 'Rp ' + (Math.round(n) || 0).toLocaleString('id-ID');

    function accOptions() {
        let h = '<option value="">— pilih akun —</option>';
        ACCOUNTS.forEach(a => h += `<option value="${a.id}">${a.code} · ${a.name}</option>`);
        return h;
    }

    function addRow() {
        const i = ri++;
        const tr = document.createElement('tr');
        tr.className = 'border-t border-stone-100';
        tr.innerHTML = `
            <td class="px-4 py-2"><select name="lines[${i}][account_id]" class="w-56 px-2 py-1.5 border border-stone-300 rounded-lg">${accOptions()}</select></td>
            <td class="px-2"><input name="lines[${i}][memo]" class="w-40 px-2 py-1.5 border border-stone-300 rounded-lg" placeholder="opsional"></td>
            <td class="text-right"><input type="number" step="0.01" min="0" name="lines[${i}][debit]" oninput="recalc()" class="w-32 px-2 py-1.5 border border-stone-300 rounded-lg text-right"></td>
            <td class="text-right"><input type="number" step="0.01" min="0" name="lines[${i}][credit]" oninput="recalc()" class="w-32 px-2 py-1.5 border border-stone-300 rounded-lg text-right"></td>
            <td class="pr-4 text-right"><button type="button" onclick="this.closest('tr').remove();recalc()" class="text-rose-600 hover:text-rose-800 font-bold">✕</button></td>`;
        document.getElementById('rows').appendChild(tr);
    }

    function recalc() {
        let d = 0, c = 0;
        document.querySelectorAll('#rows tr').forEach(tr => {
            d += parseFloat(tr.querySelector('[name$="[debit]"]').value) || 0;
            c += parseFloat(tr.querySelector('[name$="[credit]"]').value) || 0;
        });
        document.getElementById('sumDebit').textContent = rupiah(d);
        document.getElementById('sumCredit').textContent = rupiah(c);
        const diff = Math.round((d - c) * 100) / 100;
        const msg = document.getElementById('balanceMsg');
        const btn = document.getElementById('saveBtn');
        if (Math.abs(diff) < 0.005 && d > 0) {
            msg.textContent = '✓ Balance';
            msg.className = 'text-xs font-semibold text-emerald-600';
            btn.disabled = false; btn.classList.remove('opacity-50');
        } else {
            msg.textContent = 'Selisih: ' + rupiah(diff) + ' (debit harus = kredit)';
            msg.className = 'text-xs font-semibold text-rose-600';
            btn.disabled = true; btn.classList.add('opacity-50');
        }
    }

    addRow(); addRow();
    recalc();
</script>
@endpush
