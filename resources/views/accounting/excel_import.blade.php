@extends('layouts.app')
@section('title', 'Impor Jurnal Excel')
@section('heading', 'Impor Jurnal dari Excel')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
@endpush

@section('content')
<a href="{{ route('accounting.journals') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Jurnal</a>

@if(! $branch)
    <div class="mt-3 px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs">Belum ada cabang. Seed Chart of Account dulu.</div>
@else
<div class="mt-3 px-4 py-2.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-[11px]">
    ⚠️ Karena kamu pilih Excel sbg sumber, <b>jangan pakai "Impor Mutasi Bank" untuk periode yang sama</b> — bisa dobel. Impor ulang file sama otomatis dilewati (anti-dobel).
</div>

{{-- Bersihkan hasil impor Excel (buat impor ulang kalau ada yang salah) --}}
<form method="POST" action="{{ route('accounting.excel-import.purge') }}" class="mt-2 flex items-center gap-2 flex-wrap"
      onsubmit="return confirm('Hapus PERMANEN semua jurnal hasil impor Excel' + (document.getElementById('purgePeriod').value ? ' periode ' + document.getElementById('purgePeriod').value : '') + '? Jurnal manual & impor bank tidak tersentuh.')">
    @csrf
    <span class="text-[11px] text-stone-500">Mau impor ulang? Bersihkan dulu hasil impor Excel:</span>
    <input type="month" id="purgePeriod" name="period" value="2026-06" class="px-2 py-1 border border-stone-300 rounded text-xs">
    <button class="px-3 py-1.5 text-xs bg-rose-600 text-white rounded-lg hover:bg-rose-700">🗑 Hapus impor Excel (periode ini)</button>
    <span class="text-[10px] text-stone-400">kosongkan bulan = hapus semua periode</span>
</form>

{{-- STEP 1 --}}
<div class="bg-white rounded-2xl border border-stone-200 p-5 mt-3 space-y-4 text-sm">
    <div class="grid sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-xs font-semibold mb-1">File Excel (.xlsx) *</label>
            <input type="file" id="xlsxFile" accept=".xlsx,.xls" class="w-full text-xs">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1">Periode (bulan) *</label>
            <input type="month" id="period" value="2026-06" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
            <p class="text-[10px] text-stone-400 mt-1">Tanggal = kolom hari di sheet + bulan ini.</p>
        </div>
        <div class="flex items-end"><button type="button" onclick="readWorkbook()" class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">Baca File →</button></div>
    </div>
</div>

{{-- STEP 2 --}}
<div id="sheetCard" class="hidden bg-white rounded-2xl border border-stone-200 p-5 mt-4 text-sm">
    <div class="grid sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-xs font-semibold mb-1">Sheet</label>
            <select id="sheetSel" onchange="onSheetChange()" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1">Tipe Jurnal</label>
            <select id="typeSel" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
                <option value="umum">Jurnal Umum / Penyesuaian</option>
                <option value="persediaan">Persediaan &gt; HPP</option>
                <option value="pengeluaran">Pengeluaran Kas</option>
                <option value="penerimaan">Penerimaan Kas</option>
            </select>
        </div>
        <div class="flex items-end"><button type="button" onclick="parseSheet()" class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">Proses Sheet →</button></div>
    </div>
</div>

{{-- STEP 3: mapping akun --}}
<div id="mapCard" class="hidden bg-white rounded-2xl border border-stone-200 p-5 mt-4 text-sm">
    <h3 class="font-bold text-stone-800 mb-1">Petakan Akun</h3>
    <p class="text-[11px] text-stone-500 mb-3">Tiap "kunci" dari sheet (kode akun / nama kolom kas) dipetakan ke akun COA app. Yang belum kepetakan ditandai merah — baris terkait dilewati.</p>
    <div class="overflow-x-auto max-h-72 overflow-y-auto border border-stone-100 rounded-xl">
        <table class="w-full text-xs"><thead class="bg-stone-50 text-stone-400 text-[10px] uppercase sticky top-0"><tr><th class="text-left px-3 py-2">Kunci (dari Excel)</th><th class="text-left">→ Akun COA App</th></tr></thead><tbody id="mapRows"></tbody></table>
    </div>
    <button type="button" onclick="buildPreview()" class="mt-3 px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">Terapkan &amp; Pratinjau →</button>
</div>

{{-- STEP 4: preview + simpan --}}
<div id="previewCard" class="hidden mt-4">
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Pratinjau Jurnal — <span id="jCount">0</span> jurnal <span id="jNote" class="font-normal text-[11px] text-stone-500"></span></div>
        <div class="overflow-x-auto max-h-96 overflow-y-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px] sticky top-0"><tr><th class="text-left px-4 py-2">Tanggal</th><th class="text-left">Keterangan</th><th class="text-left">Baris (Akun · D/K)</th><th class="text-right pr-4">Status</th></tr></thead>
            <tbody id="prevRows"></tbody>
        </table>
        </div>
    </div>
    <div class="flex justify-end gap-2 mt-3">
        <a href="{{ route('accounting.journals') }}" class="px-4 py-2 text-sm text-stone-600 rounded-lg">Batal</a>
        <button type="button" id="saveBtn" onclick="submitJournals()" class="px-6 py-2.5 text-sm bg-indigo-700 text-white rounded-lg hover:bg-indigo-800 font-semibold">Simpan ke Jurnal</button>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
    const ACCOUNTS = {{ \Illuminate\Support\Js::from($accounts) }};
    const BRANCH_ID = {{ $branch->id ?? 'null' }};
    let WB = null, SHEETROWS = [], JOURNALS = [], KEYS = [];

    const num = v => {
        if (typeof v === 'number') return v;
        if (v == null) return 0;
        const s = String(v).replace(/[^0-9.\-]/g, '');
        const n = parseFloat(s);
        return isFinite(n) ? n : 0;
    };
    const txt = v => (v == null ? '' : String(v)).trim();

    function accOptions(sel) {
        let h = '<option value="">— (lewati) —</option>';
        ACCOUNTS.forEach(a => h += `<option value="${a.id}" ${a.id == sel ? 'selected' : ''}>${a.code} · ${a.name}</option>`);
        return h;
    }
    // auto-suggest app account for a key
    function suggest(key) {
        const k = String(key).trim();
        if (/^\d+$/.test(k)) { // kode → cocokkan legacy_code
            const a = ACCOUNTS.find(x => (x.legacy_code || '').split('/').map(s => s.trim()).includes(k));
            if (a) return a.id;
        }
        const low = k.toLowerCase();
        const rules = [ // urut: cocokan spesifik dulu (menang pertama)
            [/potongan pembelian/, /potongan pembelian/i], // tak ada akun → biar unmapped (bukan salah map ke Potongan Penjualan)
            [/potongan penjualan/, /potongan penjualan/i, /potongan/i],
            [/deposit/, /deposit/i, /hutang deposit/i],
            [/penjualan.*jakarta|jakarta/, /penjualan.*jakarta/i, /penjualan/i],
            [/penjualan/, /penjualan timur/i, /penjualan/i],
            [/utang dagang|hutang dagang/, /utang dagang|hutang dagang/i],
            [/perlengkapan/, /perlengkapan/i],
            [/pembelian/, /pembelian|persediaan/i],
            [/beban hpp|hpp/, /beban hpp/i],
            [/persediaan/, /persediaan barang jadi/i],
            [/bank bca|^bank$/, /bank billy|bca/i, /bank/i], // penerimaan "Bank" + pengeluaran "Bank BCA" = Billy
            [/bank cv|^cv$/, /shopee|kas shopee/i, /shopee/i], // "CV"/"BANK CV" = kas Shopee
        ];
        for (const [test, ...nameRes] of rules) {
            if (test.test(low)) {
                for (const nr of nameRes) { const a = ACCOUNTS.find(x => nr.test(x.name)); if (a) return a.id; }
            }
        }
        return '';
    }

    function readWorkbook() {
        const f = document.getElementById('xlsxFile').files[0];
        if (!f) { alert('Pilih file .xlsx dulu.'); return; }
        const rd = new FileReader();
        rd.onload = e => {
            WB = XLSX.read(new Uint8Array(e.target.result), { type: 'array' });
            const sel = document.getElementById('sheetSel');
            sel.innerHTML = WB.SheetNames.map(n => `<option value="${n}">${n}</option>`).join('');
            document.getElementById('sheetCard').classList.remove('hidden');
            onSheetChange();
        };
        rd.readAsArrayBuffer(f);
    }
    function onSheetChange() {
        const name = document.getElementById('sheetSel').value.toLowerCase();
        let t = 'umum';
        if (/pengeluaran/.test(name)) t = 'pengeluaran';
        else if (/penerimaan/.test(name)) t = 'penerimaan';
        else if (/persediaan|hpp/.test(name)) t = 'persediaan';
        else if (/umum|penyesuaian/.test(name)) t = 'umum';
        document.getElementById('typeSel').value = t;
    }

    function sheetToRows(name) {
        const ws = WB.Sheets[name];
        return XLSX.utils.sheet_to_json(ws, { header: 1, raw: true, defval: null });
    }
    function findHeader(rows) {
        for (let i = 0; i < Math.min(rows.length, 15); i++) {
            if (rows[i] && rows[i].some(c => /tangg/i.test(String(c || '')))) return i;
        }
        return 2;
    }
    function dateOf(day) {
        const p = document.getElementById('period').value; // YYYY-MM
        const d = String(Math.trunc(num(day))).padStart(2, '0');
        return `${p}-${d}`;
    }

    // ---- parsers: hasilkan JOURNALS [{date,desc,type,lines:[{key,side,amount}]}] ----
    function parseSheet() {
        const name = document.getElementById('sheetSel').value;
        const type = document.getElementById('typeSel').value;
        SHEETROWS = sheetToRows(name);
        const h = findHeader(SHEETROWS);
        JOURNALS = ({ umum: pUmum, persediaan: pPersediaan, pengeluaran: pPengeluaran, penerimaan: pPenerimaan })[type](SHEETROWS, h, type);
        // kumpulkan kunci unik
        const set = new Set();
        JOURNALS.forEach(j => j.lines.forEach(l => set.add(l.key)));
        KEYS = [...set];
        renderMapping();
        document.getElementById('mapCard').classList.remove('hidden');
        document.getElementById('previewCard').classList.add('hidden');
    }

    function pUmum(rows, h, type) {
        const out = []; let cur = null, day = null;
        for (let r = h + 1; r < rows.length; r++) {
            const row = rows[r] || [];
            if (txt(row[0]).toLowerCase() === 'total') break;
            const dc = row[1];
            if (num(dc) > 0) { if (cur) out.push(cur); cur = { date: dateOf(dc), desc: txt(row[3]), type: 'general', lines: [] }; day = dc; }
            if (!cur) continue;
            const ref = txt(row[4]).replace(/\.0$/, ''); const d = num(row[5]), c = num(row[6]);
            if (ref && (d > 0 || c > 0)) cur.lines.push({ key: ref, side: d > 0 ? 'debit' : 'credit', amount: d > 0 ? d : c });
        }
        if (cur) out.push(cur);
        return out.filter(j => j.lines.length >= 2);
    }
    function pPersediaan(rows, h) {
        const out = []; let day = null;
        for (let r = h + 1; r < rows.length; r++) {
            const row = rows[r] || [];
            if (txt(row[0]).toLowerCase() === 'total') break;
            if (num(row[1]) > 0) day = row[1];
            const amt = num(row[5]); // F = Beban HPP
            if (amt <= 0) continue;
            out.push({ date: dateOf(day || 1), desc: txt(row[3]), type: 'inventory', lines: [{ key: 'Beban HPP', side: 'debit', amount: amt }, { key: 'Persediaan', side: 'credit', amount: amt }] });
        }
        return out;
    }
    // Jurnal kolumnar: TIAP kolom nominal = 1 akun tetap. Satu baris Excel = satu jurnal
    // (jumlah kolom debit = jumlah kolom kredit per baris). Emit 1 baris jurnal per kolom terisi.
    // side: 'debit' | 'credit' | 'signed' (kolom Serba: nilai + = debit, − = kredit).
    // Nilai NEGATIF di kolom debit/kredit = pembalikan (refund/retur) → dibukukan di sisi
    // lawan, persis seperti Excel me-net-kan-nya. (mis. Penjualan −115rb = debit Penjualan).
    const flip = s => (s === 'debit' ? 'credit' : 'debit');
    function colJournal(day, ket, type, cols) {
        const lines = [];
        cols.forEach(c => {
            const a = num(c.v);
            if (a === 0) return;
            if (c.side === 'signed') lines.push({ key: c.key, side: a > 0 ? 'debit' : 'credit', amount: Math.abs(a) });
            else if (a > 0) lines.push({ key: c.key, side: c.side, amount: a });
            else lines.push({ key: c.key, side: flip(c.side), amount: Math.abs(a) }); // negatif = pembalikan
        });
        if (lines.length < 2) return null;
        return { date: dateOf(day || 1), desc: ket, type, lines };
    }
    function pPengeluaran(rows, h) {
        const out = []; let day = null;
        for (let r = h + 1; r < rows.length; r++) {
            const row = rows[r] || [];
            if (txt(row[0]).toLowerCase() === 'total') break;
            if (num(row[1]) > 0) day = row[1];
            const ref = txt(row[8]).replace(/\.0$/, '');            // I = kode akun serba-serbi
            const serbaKey = (ref && /^\d+$/.test(ref)) ? ref : (txt(row[7]) || 'Serba-serbi'); // fallback nama H
            const j = colJournal(day, txt(row[3]), 'cash_out', [
                { key: 'Perlengkapan', v: row[4], side: 'debit' },      // E
                { key: 'Pembelian', v: row[5], side: 'debit' },         // F
                { key: 'Utang Dagang', v: row[6], side: 'debit' },      // G
                { key: serbaKey, v: row[9], side: 'debit' },            // J (akun = kode kolom I)
                { key: 'BANK CV', v: row[10], side: 'credit' },         // K (kas Shopee)
                { key: 'Bank BCA', v: row[11], side: 'credit' },        // L (bank Billy)
                { key: 'Potongan Pembelian', v: row[12], side: 'credit' },     // M
                { key: 'Potongan Pembelian Jkt', v: row[13], side: 'credit' }, // N
            ]);
            if (j) out.push(j);
        }
        return out;
    }
    function pPenerimaan(rows, h) {
        const out = []; let day = null;
        for (let r = h + 1; r < rows.length; r++) {
            const row = rows[r] || [];
            if (txt(row[0]).toLowerCase() === 'total') break;
            if (num(row[1]) > 0) day = row[1];
            const sRef = txt(row[9]).replace(/\.0$/, '');            // J = kode akun serba-serbi
            const serbaKey = (sRef && /^\d+$/.test(sRef)) ? sRef : (txt(row[8]) || 'Serba-serbi'); // fallback nama I
            const j = colJournal(day, txt(row[3]), 'cash_in', [
                { key: 'CV', v: row[5], side: 'debit' },                 // F (kas Shopee)
                { key: 'Bank', v: row[6], side: 'debit' },               // G (bank Billy)
                { key: 'Potongan Penjualan', v: row[7], side: 'debit' }, // H (kontra pendapatan)
                { key: serbaKey, v: row[10], side: 'signed' },           // K (akun kode J; + debit / − kredit)
                { key: 'Penjualan Timur', v: row[11], side: 'credit' },      // L
                { key: 'Penjualan Jakarta', v: row[12], side: 'credit' },    // M
                { key: 'Hutang Deposit Timur', v: row[13], side: 'credit' }, // N
                { key: 'Hutang Deposit Jakarta', v: row[14], side: 'credit' },// O
            ]);
            if (j) out.push(j);
        }
        return out;
    }

    function renderMapping() {
        const tb = document.getElementById('mapRows');
        tb.innerHTML = KEYS.map(k => `<tr class="border-t border-stone-100"><td class="px-3 py-1.5 font-mono text-stone-700">${k}</td><td class="py-1.5"><select data-key="${k}" onchange="markMap(this)" class="map-sel w-72 px-2 py-1 border border-stone-300 rounded">${accOptions(suggest(k))}</select></td></tr>`).join('');
        document.querySelectorAll('.map-sel').forEach(markMap);
    }
    function markMap(sel) { sel.closest('td').classList.toggle('bg-rose-50', !sel.value); }

    function mapping() {
        const m = {};
        document.querySelectorAll('.map-sel').forEach(s => m[s.dataset.key] = s.value ? parseInt(s.value) : null);
        return m;
    }

    function buildPreview() {
        const m = mapping();
        const tb = document.getElementById('prevRows');
        let ok = 0, unmapped = 0, unbal = 0;
        window.FINAL = [];
        const rowsHtml = [];
        JOURNALS.forEach(j => {
            const lines = j.lines.map(l => ({ account_id: m[l.key], side: l.side, amount: l.amount, key: l.key }));
            const hasUnmapped = lines.some(l => !l.account_id);
            const d = lines.filter(l => l.side === 'debit').reduce((s, l) => s + l.amount, 0);
            const c = lines.filter(l => l.side === 'credit').reduce((s, l) => s + l.amount, 0);
            const balanced = Math.abs(d - c) < 0.005;
            let status, cls;
            if (hasUnmapped) { status = 'akun blm dipetakan'; cls = 'text-rose-600'; unmapped++; }
            else if (!balanced) { status = 'tidak balance'; cls = 'text-amber-600'; unbal++; }
            else { status = '✓'; cls = 'text-emerald-600'; ok++; window.FINAL.push({ date: j.date, reference: j.desc, description: j.desc, type: j.type, lines: lines.map(l => ({ account_id: l.account_id, debit: l.side === 'debit' ? l.amount : 0, credit: l.side === 'credit' ? l.amount : 0 })) }); }
            if (rowsHtml.length < 300) {
                const lineStr = lines.map(l => { const a = ACCOUNTS.find(x => x.id == l.account_id); return `${a ? a.code : '('+l.key+')'} ${l.side === 'debit' ? 'D' : 'K'} ${Math.round(l.amount).toLocaleString('id-ID')}`; }).join(' · ');
                rowsHtml.push(`<tr class="border-t border-stone-100"><td class="px-4 py-1.5 text-stone-600">${j.date}</td><td class="text-stone-500 max-w-xs truncate">${(j.desc||'').replace(/</g,'&lt;')}</td><td class="text-stone-500">${lineStr}</td><td class="text-right pr-4 font-semibold ${cls}">${status}</td></tr>`);
            }
        });
        tb.innerHTML = rowsHtml.join('');
        document.getElementById('jCount').textContent = ok;
        document.getElementById('jNote').textContent = `· siap: ${ok}${unmapped ? ', blm dipetakan: ' + unmapped : ''}${unbal ? ', tak balance: ' + unbal : ''}`;
        document.getElementById('previewCard').classList.remove('hidden');
        document.getElementById('saveBtn').disabled = ok === 0;
    }

    async function submitJournals() {
        if (!window.FINAL || !window.FINAL.length) { alert('Tidak ada jurnal siap.'); return; }
        const btn = document.getElementById('saveBtn'); btn.disabled = true; btn.textContent = 'Menyimpan…';
        try {
            const res = await fetch('{{ route('accounting.excel-import.store') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.CSRF, 'Accept': 'application/json' },
                body: JSON.stringify({ branch_id: BRANCH_ID, source_label: document.getElementById('sheetSel').value, journals: window.FINAL }),
            });
            if (res.ok) { const d = await res.json(); window.location = d.redirect; }
            else { const e = await res.json().catch(() => ({})); alert('Gagal: ' + (e.message || res.status)); btn.disabled = false; btn.textContent = 'Simpan ke Jurnal'; }
        } catch (err) { alert('Error: ' + err.message); btn.disabled = false; btn.textContent = 'Simpan ke Jurnal'; }
    }
</script>
@endpush
