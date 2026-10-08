@extends('layouts.app')
@section('title', 'Jurnal manual')

@section('content')
    <h2 class="mb-4 text-lg font-bold text-ink-900">Jurnal manual</h2>

    <form method="POST" action="{{ route('journals.store', $client) }}" class="card p-5" data-journal>
        @csrf

        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="label" for="date">Tanggal <span class="text-rose-600">*</span></label>
                <input type="date" id="date" name="date" value="{{ old('date', now()->toDateString()) }}" required class="field w-40">
            </div>
            <div class="grow min-w-56">
                <label class="label" for="description">Keterangan</label>
                <input type="text" id="description" name="description" value="{{ old('description') }}" class="field">
            </div>
            <div class="w-40">
                <label class="label" for="reference">Referensi</label>
                <input type="text" id="reference" name="reference" value="{{ old('reference') }}" class="field">
            </div>
            <div class="w-40">
                <label class="label" for="type">Jenis</label>
                <select id="type" name="type" class="field">
                    @foreach (['general' => 'Umum', 'expense' => 'Beban', 'purchase' => 'Pembelian', 'sale' => 'Penjualan', 'bank' => 'Bank', 'adjustment' => 'Penyesuaian'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('type') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="th w-2/5">Akun</th>
                        <th class="th">Memo</th>
                        <th class="th num w-36">Debit</th>
                        <th class="th num w-36">Kredit</th>
                    </tr>
                </thead>
                <tbody id="journal-lines">
                    @for ($i = 0; $i < 2; $i++)
                        <tr data-line>
                            <td class="td">
                                <select name="lines[{{ $i }}][account_id]" class="field">
                                    <option value="">— pilih akun —</option>
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}" @selected(old("lines.$i.account_id") == $account->id)>{{ $account->label() }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="td"><input type="text" name="lines[{{ $i }}][memo]" value="{{ old("lines.$i.memo") }}" class="field"></td>
                            <td class="td"><input type="number" step="0.01" min="0" data-money="debit" name="lines[{{ $i }}][debit]" value="{{ old("lines.$i.debit") }}" class="field num"></td>
                            <td class="td"><input type="number" step="0.01" min="0" data-money="credit" name="lines[{{ $i }}][credit]" value="{{ old("lines.$i.credit") }}" class="field num"></td>
                        </tr>
                    @endfor
                </tbody>
                <tfoot>
                    <tr class="bg-ink-50 font-semibold">
                        <td class="td" colspan="2">Total <span data-balance-state class="badge-mute ml-1">—</span></td>
                        <td class="td num" data-total="debit">0</td>
                        <td class="td num" data-total="credit">0</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="mt-4 flex items-center gap-2">
            <button type="button" data-add-line="#journal-lines" class="btn-ghost btn-sm">+ Baris</button>
            <button type="button" data-reverse class="btn-ghost btn-sm" title="Tukar sisi debit dan kredit seluruh baris">⇄ Balik debit/kredit</button>
            <span class="grow"></span>
            <a href="{{ route('journals.index', $client) }}" class="btn-ghost">Batal</a>
            <button class="btn-primary">Posting jurnal</button>
        </div>
        <p class="mt-2 text-xs text-ink-400">Debit harus sama dengan kredit. Satu baris hanya boleh salah satu sisi.</p>
    </form>
@endsection
