@extends('layouts.app')
@section('title', 'Impor Mutasi Bank')
@section('heading', 'Impor Mutasi Bank')

@section('content')
<a href="{{ route('accounting.journals') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Jurnal</a>

@if(! $branch || $bankAccounts->isEmpty())
    <div class="mt-3 px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs">
        @if(! $branch)Belum ada cabang. @endif
        @if($bankAccounts->isEmpty())Belum ada akun Kas/Bank (subtipe "cash"/"bank") di Master COA. Tambahkan dulu.@endif
    </div>
@else
{{-- STEP 1: sumber data --}}
<div class="bg-white rounded-2xl border border-stone-200 p-5 mt-3 space-y-4 text-sm">
    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-semibold mb-1">Rekening Bank (akun Kas/Bank) *</label>
            <select id="bankAccount" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
                @foreach($bankAccounts as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->name }}</option>@endforeach
            </select>
            <p class="text-[10px] text-stone-400 mt-1">Semua baris akan dijurnal ke akun ini.</p>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1">File Mutasi (CSV) atau tempel teks</label>
            <input type="file" id="csvFile" accept=".csv,.txt" class="w-full text-xs">
            <textarea id="csvPaste" rows="2" placeholder="…atau tempel isi CSV di sini" class="w-full mt-1 px-2 py-1 text-xs border border-stone-300 rounded-lg"></textarea>
        </div>
    </div>
    <button type="button" onclick="readSource()" class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">Baca Data →</button>
</div>

{{-- STEP 2: pemetaan kolom --}}
<div id="mapCard" class="hidden bg-white rounded-2xl border border-stone-200 p-5 mt-4 text-sm">
    <h3 class="font-bold text-stone-800 mb-3">Cocokkan Kolom</h3>
    <label class="flex items-center gap-2 text-xs mb-3"><input type="checkbox" id="hasHeader" checked onchange="onHeaderToggle()" class="accent-red-600"> Baris pertama adalah judul kolom (header)</label>
    <div class="grid sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <div><label class="block text-[10px] font-semibold uppercase text-stone-400 mb-1">Tanggal *</label><select id="mapDate" class="w-full px-2 py-1.5 border border-stone-300 rounded-lg"></select></div>
        <div><label class="block text-[10px] font-semibold uppercase text-stone-400 mb-1">Keterangan</label><select id="mapDesc" class="w-full px-2 py-1.5 border border-stone-300 rounded-lg"></select></div>
        <div><label class="block text-[10px] font-semibold uppercase text-stone-400 mb-1">Uang Keluar (debit)</label><select id="mapOut" class="w-full px-2 py-1.5 border border-stone-300 rounded-lg"></select></div>
        <div><label class="block text-[10px] font-semibold uppercase text-stone-400 mb-1">Uang Masuk (kredit)</label><select id="mapIn" class="w-full px-2 py-1.5 border border-stone-300 rounded-lg"></select></div>
        <div><label class="block text-[10px] font-semibold uppercase text-stone-400 mb-1">Format Tgl</label>
            <select id="dateFmt" class="w-full px-2 py-1.5 border border-stone-300 rounded-lg"><option value="dmy">DD/MM/YYYY</option><option value="ymd">YYYY-MM-DD</option><option value="mdy">MM/DD/YYYY</option></select>
        </div>
        <div><label class="block text-[10px] font-semibold uppercase text-stone-400 mb-1">Pemisah ribuan</label>
            <select id="numSep" class="w-full px-2 py-1.5 border border-stone-300 rounded-lg"><option value="titik">1.000.000 (titik)</option><option value="koma">1,000,000 (koma)</option></select>
        </div>
    </div>
    <button type="button" onclick="buildPreview()" class="mt-4 px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">Proses & Pratinjau →</button>
</div>

{{-- STEP 3: assign COA + simpan --}}
<form method="POST" action="{{ route('accounting.import.store') }}" id="importForm" class="hidden mt-4">
    @csrf
    <input type="hidden" name="branch_id" value="{{ $branch->id }}">
    <input type="hidden" name="bank_account_id" id="bankAccountHidden">

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between gap-3 flex-wrap">
            <h3 class="font-bold text-stone-800 text-sm">Pratinjau — <span id="rowCount">0</span> baris</h3>
            <div class="flex items-end gap-2 flex-wrap">
                <div>
                    <label class="block text-[10px] font-semibold uppercase text-stone-400">Assign massal (baris kosong)</label>
                    <select id="bulkCoa" class="px-2 py-1.5 text-xs border border-stone-300 rounded-lg w-52"></select>
                </div>
                <button type="button" onclick="bulkAssign()" class="px-3 py-1.5 text-xs bg-stone-800 text-white rounded-lg">Terapkan</button>
            </div>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr>
                    <th class="text-left px-4 py-3">Tanggal</th>
                    <th class="text-left">Keterangan</th>
                    <th class="text-left">Arah</th>
                    <th class="text-right">Nominal</th>
                    <th class="text-left">Akun Lawan (COA)</th>
                    <th class="text-center pr-4">Abaikan</th>
                </tr>
            </thead>
            <tbody id="importRows"></tbody>
        </table>
        </div>
    </div>
    <p class="text-[11px] text-stone-400 mt-2">Baris pembayaran PO yang sudah dijurnal dari sistem → centang <b>Abaikan</b> biar tidak dobel. Baris tanpa COA otomatis dilewati.</p>
    <div class="flex justify-end gap-2 mt-3">
        <a href="{{ route('accounting.journals') }}" class="px-4 py-2 text-sm text-stone-600 rounded-lg">Batal</a>
        <button class="px-6 py-2.5 text-sm bg-emerald-700 text-white rounded-lg hover:bg-emerald-800 font-semibold">Simpan ke Jurnal</button>
    </div>
</form>
@endif
@endsection

@push('scripts')
<script>
    const ACCOUNTS = {{ \Illuminate\Support\Js::from($accounts->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])) }};
    let RAW = [];
    let ri = 0;

    function accOptions(sel) {
        let h = '<option value="">— pilih akun —</option>';
        ACCOUNTS.forEach(a => h += `<option value="${a.id}" ${a.id == sel ? 'selected' : ''}>${a.code} · ${a.name}</option>`);
        return h;
    }

    function detectDelim(line) {
        const counts = { ';': (line.match(/;/g) || []).length, ',': (line.match(/,/g) || []).length, '\t': (line.match(/\t/g) || []).length };
        return Object.keys(counts).reduce((a, b) => counts[b] > counts[a] ? b : a, ',');
    }

    function parseCSV(text, delim) {
        const rows = []; let row = [], field = '', inQ = false;
        for (let i = 0; i < text.length; i++) {
            const c = text[i];
            if (inQ) {
                if (c === '"') { if (text[i + 1] === '"') { field += '"'; i++; } else inQ = false; } else field += c;
            } else {
                if (c === '"') inQ = true;
                else if (c === delim) { row.push(field); field = ''; }
                else if (c === '\n') { row.push(field); rows.push(row); row = []; field = ''; }
                else if (c !== '\r') field += c;
            }
        }
        if (field.length || row.length) { row.push(field); rows.push(row); }
        return rows.filter(r => r.some(x => (x || '').trim() !== ''));
    }

    function readSource() {
        const file = document.getElementById('csvFile').files[0];
        const paste = document.getElementById('csvPaste').value.trim();
        if (file) {
            const rd = new FileReader();
            rd.onload = e => ingest(e.target.result);
            rd.readAsText(file);
        } else if (paste) {
            ingest(paste);
        } else {
            alert('Pilih file CSV atau tempel teksnya dulu.');
        }
    }

    function ingest(text) {
        const firstLine = text.split(/\r?\n/)[0] || '';
        RAW = parseCSV(text, detectDelim(firstLine));
        if (!RAW.length) { alert('File kosong / tidak terbaca.'); return; }
        populateColMaps();
        document.getElementById('mapCard').classList.remove('hidden');
    }

    function headerNames() {
        const has = document.getElementById('hasHeader').checked;
        const cols = RAW[0].length;
        return Array.from({ length: cols }, (_, i) => has ? (RAW[0][i] || ('Kolom ' + (i + 1))) : ('Kolom ' + (i + 1)));
    }

    function colOptions(names, guessRe) {
        let h = '<option value="">— tidak ada —</option>';
        let guess = -1;
        names.forEach((n, i) => { if (guess < 0 && guessRe && guessRe.test(n)) guess = i; });
        names.forEach((n, i) => h += `<option value="${i}" ${i === guess ? 'selected' : ''}>${n}</option>`);
        return h;
    }

    function populateColMaps() {
        const names = headerNames();
        document.getElementById('mapDate').innerHTML = colOptions(names, /tgl|tangg|date/i);
        document.getElementById('mapDesc').innerHTML = colOptions(names, /ket|urai|desc|transaksi|remark|berita|narasi/i);
        document.getElementById('mapOut').innerHTML = colOptions(names, /debe?t|keluar|db\b/i);
        document.getElementById('mapIn').innerHTML = colOptions(names, /kredit|credit|masuk|cr\b/i);
    }
    function onHeaderToggle() { populateColMaps(); }

    function normDate(raw, fmt) {
        if (!raw) return '';
        const p = raw.trim().split(/[\/\-.\s]+/).filter(Boolean);
        if (p.length < 3) return '';
        let d, m, y;
        if (fmt === 'ymd') { [y, m, d] = p; } else if (fmt === 'mdy') { [m, d, y] = p; } else { [d, m, y] = p; }
        if (y.length === 2) y = '20' + y;
        d = d.padStart(2, '0'); m = m.padStart(2, '0');
        if (+m < 1 || +m > 12 || +d < 1 || +d > 31) return '';
        return `${y}-${m}-${d}`;
    }

    function parseAmt(raw, sep) {
        if (!raw) return 0;
        let s = String(raw).replace(/[^0-9.,\-]/g, '');
        if (sep === 'titik') s = s.replace(/\./g, '').replace(',', '.');
        else s = s.replace(/,/g, '');
        return Math.abs(parseFloat(s) || 0);
    }

    function buildPreview() {
        const has = document.getElementById('hasHeader').checked;
        const start = has ? 1 : 0;
        const cDate = document.getElementById('mapDate').value;
        const cDesc = document.getElementById('mapDesc').value;
        const cOut = document.getElementById('mapOut').value;
        const cIn = document.getElementById('mapIn').value;
        const fmt = document.getElementById('dateFmt').value;
        const sep = document.getElementById('numSep').value;
        if (cDate === '' || (cOut === '' && cIn === '')) { alert('Petakan minimal kolom Tanggal + salah satu kolom nominal (keluar/masuk).'); return; }

        const tbody = document.getElementById('importRows');
        tbody.innerHTML = ''; ri = 0;
        let count = 0;
        for (let r = start; r < RAW.length; r++) {
            const row = RAW[r];
            const date = normDate(row[cDate], fmt);
            const out = cOut !== '' ? parseAmt(row[cOut], sep) : 0;
            const inn = cIn !== '' ? parseAmt(row[cIn], sep) : 0;
            let amount = 0, dir = '';
            if (out > 0) { amount = out; dir = 'keluar'; } else if (inn > 0) { amount = inn; dir = 'masuk'; } else continue;
            if (!date) continue;
            const desc = cDesc !== '' ? (row[cDesc] || '') : '';
            addPreviewRow(date, desc, dir, amount);
            count++;
        }
        document.getElementById('rowCount').textContent = count;
        document.getElementById('bulkCoa').innerHTML = accOptions('');
        document.getElementById('bankAccountHidden').value = document.getElementById('bankAccount').value;
        document.getElementById('importForm').classList.remove('hidden');
        if (!count) alert('Tidak ada baris valid terbaca. Cek pemetaan kolom / format tanggal.');
    }

    function addPreviewRow(date, desc, dir, amount) {
        const i = ri++;
        const tr = document.createElement('tr');
        tr.className = 'border-t border-stone-100';
        const dirBadge = dir === 'keluar' ? '<span class="px-2 py-0.5 rounded bg-rose-50 text-rose-700">Keluar</span>' : '<span class="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700">Masuk</span>';
        tr.innerHTML = `
            <td class="px-4 py-2 text-stone-600">${date}<input type="hidden" name="rows[${i}][date]" value="${date}"></td>
            <td class="text-stone-600 max-w-xs truncate" title="${desc.replace(/"/g,'&quot;')}">${desc}<input type="hidden" name="rows[${i}][description]" value="${desc.replace(/"/g,'&quot;')}"></td>
            <td>${dirBadge}<input type="hidden" name="rows[${i}][direction]" value="${dir}"></td>
            <td class="text-right font-semibold text-stone-800">${'Rp ' + Math.round(amount).toLocaleString('id-ID')}<input type="hidden" name="rows[${i}][amount]" value="${amount}"></td>
            <td><select name="rows[${i}][account_id]" class="coa-sel w-52 px-2 py-1.5 border border-stone-300 rounded-lg">${accOptions('')}</select></td>
            <td class="text-center pr-4"><input type="checkbox" name="rows[${i}][ignore]" value="1" class="accent-rose-600"></td>`;
        document.getElementById('importRows').appendChild(tr);
    }

    function bulkAssign() {
        const v = document.getElementById('bulkCoa').value;
        if (!v) return;
        document.querySelectorAll('#importRows tr').forEach(tr => {
            const sel = tr.querySelector('.coa-sel');
            const ign = tr.querySelector('[type=checkbox]');
            if (!ign.checked && !sel.value) sel.value = v;
        });
    }
</script>
@endpush
