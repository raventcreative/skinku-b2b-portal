@extends('layouts.app')
@section('title', 'Susun OKR')
@section('heading', 'Susun OKR dengan AI')

@section('content')
<div class="mx-auto max-w-4xl space-y-5">
    <div class="flex items-start gap-4 rounded-2xl border border-brand-maroon/20 bg-brand-cream p-5">
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-cream text-brand-maroon"><svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v3m0 12v3m9-9h-3M6 12H3m15.364-6.364-2.122 2.122M7.758 16.242l-2.122 2.122m12.728 0-2.122-2.122M7.758 7.758 5.636 5.636"/><circle cx="12" cy="12" r="4"/></svg></span>
        <div class="min-w-0"><p class="text-sm font-bold text-brand-dark">Panel CMO, CFO, dan COO bekerja bersama</p>
        <p class="mt-1 text-xs leading-relaxed text-stone-600">Setiap spesialis membaca Pengetahuan AI dan data aktual. Orchestrator menyelaraskan usulan, membagi tugas ke anggota aktif, dan memilih papan Kanban. Hasilnya menjadi draf untuk ditinjau sebelum kartu dibuat.</p>
        <a href="{{ route('ai.knowledge') }}" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-brand-maroon underline underline-offset-2">Periksa Pengetahuan AI<svg aria-hidden="true" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.22 14.78a.75.75 0 0 1 0-1.06l7.72-7.72H7a.75.75 0 0 1 0-1.5h7.75a.75.75 0 0 1 .75.75V13a.75.75 0 0 1-1.5 0V7.06l-7.72 7.72a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd"/></svg></a></div>
    </div>

    <form method="POST" action="{{ route('okr.generate') }}" class="space-y-5">
        @csrf

        <div class="bg-brand-cream rounded-2xl border border-stone-200 p-5 sm:p-6">
            <p class="text-sm font-bold text-stone-900 mb-4"><span class="mr-2 text-brand-maroon">01</span>Periode</p>
            <div class="grid sm:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-xs font-semibold text-stone-700">Jenis periode</span>
                    <select name="period_type" id="periodType" onchange="togglePeriod()" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                        <option value="monthly" @selected(old('period_type') === 'monthly')>Bulanan</option>
                        <option value="quarterly" @selected(old('period_type', 'quarterly') === 'quarterly')>Kuartalan</option>
                    </select>
                </label>
                <label id="monthlyFields" class="block">
                    <span class="text-xs font-semibold text-stone-700">Bulan</span>
                    <input type="month" name="period_month" value="{{ old('period_month', $defaultMonth) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                </label>
                <div id="quarterlyFields" class="grid grid-cols-2 gap-2">
                    <label class="block">
                        <span class="text-xs font-semibold text-stone-700">Tahun</span>
                        <input type="number" name="period_year" min="2020" max="2100" value="{{ old('period_year', $defaultYear) }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold text-stone-700">Kuartal</span>
                        <select name="period_quarter" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                            @foreach([1, 2, 3, 4] as $quarter)
                                <option value="{{ $quarter }}" @selected((int) old('period_quarter', $defaultQuarter) === $quarter)>Q{{ $quarter }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </div>
        </div>

        <div class="bg-brand-cream rounded-2xl border border-stone-200 p-5 sm:p-6">
            <p class="text-sm font-bold text-stone-900 mb-4"><span class="mr-2 text-brand-maroon">02</span>Cakupan</p>
            <div class="grid sm:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-xs font-semibold text-stone-700">Level OKR</span>
                    <select name="scope_type" id="scopeType" onchange="toggleScope()" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                        <option value="company" @selected(old('scope_type', 'company') === 'company')>Seluruh perusahaan</option>
                        <option value="team" @selected(old('scope_type') === 'team')>Tim/divisi</option>
                        <option value="individual" @selected(old('scope_type') === 'individual')>Individu</option>
                    </select>
                </label>
                <label id="teamField" class="block">
                    <span class="text-xs font-semibold text-stone-700">Nama tim/divisi</span>
                    <input name="scope_name" maxlength="150" value="{{ old('scope_name') }}" placeholder="mis. Marketing" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                </label>
                <label id="individualField" class="block">
                    <span class="text-xs font-semibold text-stone-700">Pemilik OKR</span>
                    <select name="scope_owner_user_id" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                        <option value="">pilih anggota…</option>
                        @foreach($members as $member)
                            <option value="{{ $member->id }}" @selected((int) old('scope_owner_user_id') === $member->id)>{{ $member->displayName() }} · {{ $member->role }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>

        <div class="bg-brand-cream rounded-2xl border border-stone-200 p-5 sm:p-6">
            <p class="text-sm font-bold text-stone-900 mb-4"><span class="mr-2 text-brand-maroon">03</span>Arahan awal</p>
            <label class="block">
                <span class="text-xs font-semibold text-stone-700">Apa hasil bisnis yang ingin dicapai?</span>
                <span class="block text-[11px] text-stone-500 mt-0.5">Tidak perlu menyusun format OKR. Tulis sasaran, masalah, baseline, batasan, atau prioritas; AI yang memecahnya.</span>
                <textarea name="direction" required rows="7" maxlength="5000" placeholder="Contoh: Q3 fokus menaikkan penjualan TikTok 30%, memperbaiki konsistensi konten, dan mengurangi order dengan SKU belum dipetakan. Beban kerja harus merata…"
                    class="mt-2 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">{{ old('direction') }}</textarea>
            </label>
            <label class="block mt-4">
                <span class="text-xs font-semibold text-stone-700">Papan Kanban utama <span class="font-normal text-stone-400">(opsional)</span></span>
                <select name="preferred_board_id" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    <option value="">AI pilih otomatis</option>
                    @foreach($boards as $board)
                        <option value="{{ $board->id }}" @selected((int) old('preferred_board_id') === $board->id)>{{ $board->name }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <button class="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-brand-maroon px-5 py-3 text-sm font-bold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-maroon focus-visible:ring-offset-2">
             Jalankan Panel AI &amp; Buat Pratinjau
        </button>
        <p class="text-center text-[11px] text-stone-400">CMO, CFO, dan COO dijalankan paralel; setelah semuanya selesai, Orchestrator menyusun pratinjau. Belum ada kartu Kanban yang dibuat.</p>
    </form>
</div>
@endsection

@push('scripts')
<script>
    function togglePeriod() {
        const monthly = document.getElementById('periodType').value === 'monthly';
        document.getElementById('monthlyFields').classList.toggle('hidden', !monthly);
        document.getElementById('quarterlyFields').classList.toggle('hidden', monthly);
    }
    function toggleScope() {
        const scope = document.getElementById('scopeType').value;
        document.getElementById('teamField').classList.toggle('hidden', scope !== 'team');
        document.getElementById('individualField').classList.toggle('hidden', scope !== 'individual');
    }
    togglePeriod();
    toggleScope();
</script>
@endpush
