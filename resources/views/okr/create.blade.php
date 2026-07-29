@extends('layouts.app')
@section('title', 'Susun OKR')
@section('heading', 'Susun OKR dengan AI')

@section('content')
<div class="max-w-3xl">
    <div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-4 mb-5">
        <p class="text-sm font-bold text-indigo-900">Panel CMO + CFO + COO AI bekerja bersama ✨</p>
        <p class="text-xs text-indigo-700 mt-1">Setiap spesialis membaca Pengetahuan AI dan data aktual bidangnya. AI Orchestrator kemudian menyelaraskan usulan mereka, membagi pekerjaan ke anggota aktif, serta memilih papan/kolom Kanban. Hasilnya tetap menjadi draf sebelum satu pun kartu dibuat.</p>
        <a href="{{ route('ai.knowledge') }}" class="inline-block mt-2 text-xs font-semibold text-indigo-800 underline">Periksa Pengetahuan AI</a>
    </div>

    <form method="POST" action="{{ route('okr.generate') }}" class="space-y-5">
        @csrf

        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <p class="text-sm font-bold text-stone-900 mb-4">1. Periode</p>
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

        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <p class="text-sm font-bold text-stone-900 mb-4">2. Cakupan</p>
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

        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <p class="text-sm font-bold text-stone-900 mb-4">3. Arahan awal</p>
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

        <button class="w-full px-5 py-3 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-bold">
            ✨ Jalankan Panel AI & Buat Pratinjau
        </button>
        <p class="text-center text-[11px] text-stone-400">Proses memakai 4 giliran AI: CMO, CFO, COO, lalu Orchestrator. Belum ada kartu Kanban yang dibuat.</p>
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
