@extends('layouts.app')
@section('title', 'Review dokumen')

@section('content')
    @php
        $e = $document->extraction ?? [];
        $warnings = $e['warnings'] ?? [];
    @endphp

    @if (session('duplicate_of'))
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Dokumen dengan isi identik sudah pernah diolah
            (<a href="{{ route('documents.show', [$client, session('duplicate_of')]) }}" class="font-semibold underline">lihat dokumen itu</a>).
            Lanjut posting hanya kalau transaksinya memang terjadi dua kali.
        </div>
    @endif

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-lg font-bold text-ink-900">{{ $document->title ?: $document->original_name ?: 'Teks tempel' }}</h2>
                @include('documents._status', ['document' => $document])
            </div>
            <p class="text-xs text-ink-500">
                {{ $document->kindLabel() }} · diupload {{ $document->created_at->format('d/m/Y H:i') }}
                @if ($document->uploader) oleh {{ $document->uploader->name }} @endif
                @if ($document->model_used) · dibaca <span class="font-mono">{{ $document->model_used }}</span> @endif
            </p>
        </div>
        <div class="flex gap-2">
            <form method="POST" action="{{ route('documents.reextract', [$client, $document]) }}">
                @csrf
                <button class="btn-ghost btn-sm">Baca ulang dengan AI</button>
            </form>
            @if (auth()->user()->isAdmin())
                <form method="POST" action="{{ route('documents.destroy', [$client, $document]) }}"
                      onsubmit="return confirm('Hapus dokumen ini? Jurnal yang sudah diposting tetap tersimpan.')">
                    @csrf @method('DELETE')
                    <button class="btn-danger btn-sm">Hapus</button>
                </form>
            @endif
        </div>
    </div>

    @if ($document->status === \App\Models\Document::STATUS_FAILED)
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
            <p class="font-semibold">AI gagal membaca dokumen ini.</p>
            <p>{{ $document->error }}</p>
            <p class="mt-1 text-xs">Dokumennya tetap tersimpan — coba "Baca ulang", atau catat manual lewat
                <a href="{{ route('journals.create', $client) }}" class="font-semibold underline">jurnal manual</a>.</p>
        </div>
    @endif

    @if ($warnings)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="mb-1 font-semibold">AI menandai {{ count($warnings) }} hal yang perlu kamu periksa:</p>
            <ul class="list-disc space-y-0.5 pl-5">
                @foreach ($warnings as $warning)<li>{{ $warning }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-3">
        {{-- ── Kolom kiri: dokumen asli + ringkasan hasil baca ────────────── --}}
        <div class="space-y-5">
            <div class="card overflow-hidden">
                <p class="border-b border-ink-200 bg-ink-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-ink-500">Dokumen asli</p>
                @if ($document->isImage() && $document->hasFile())
                    <a href="{{ route('documents.file', [$client, $document]) }}" target="_blank">
                        <img src="{{ route('documents.file', [$client, $document]) }}" alt="Dokumen" class="w-full object-contain max-h-96 bg-ink-100">
                    </a>
                @elseif ($document->isPdf() && $document->hasFile())
                    <div class="p-4 text-sm">
                        <a href="{{ route('documents.file', [$client, $document]) }}" target="_blank" class="btn-ghost btn-sm">Buka PDF</a>
                    </div>
                @endif
                @if (filled($document->raw_text))
                    <pre class="max-h-64 overflow-auto border-t border-ink-100 p-4 font-mono text-xs whitespace-pre-wrap text-ink-700">{{ $document->raw_text }}</pre>
                @endif
            </div>

            @if ($e)
                <div class="card p-4 text-sm">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-500">Hasil baca AI</p>
                    <dl class="space-y-1.5">
                        @foreach ([
                            'Vendor' => $e['vendor'] ?? null,
                            'No. dokumen' => $e['document_number'] ?? null,
                            'Tanggal' => $e['document_date'] ?? null,
                            'Jatuh tempo' => $e['due_date'] ?? null,
                            'Arah' => ($e['flow'] ?? null) === 'income' ? 'Uang masuk' : 'Uang keluar',
                            'Cara bayar' => [
                                'cash' => 'Tunai', 'bank' => 'Transfer bank', 'ewallet' => 'E-wallet / QRIS',
                                'credit' => 'Belum dibayar (hutang)', 'unknown' => 'Tidak diketahui',
                            ][$e['payment_method'] ?? 'unknown'] ?? '—',
                        ] as $label => $value)
                            @if (filled($value))
                                <div class="flex justify-between gap-2">
                                    <dt class="text-ink-500">{{ $label }}</dt>
                                    <dd class="text-right font-medium text-ink-800">{{ $value }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>

                    <dl class="mt-3 space-y-1.5 border-t border-ink-100 pt-3">
                        @foreach (['Subtotal' => $e['subtotal'] ?? null, 'Diskon' => $e['discount'] ?? null, 'Pajak' => $e['tax'] ?? null] as $label => $value)
                            @if ($value)
                                <div class="flex justify-between gap-2">
                                    <dt class="text-ink-500">{{ $label }}</dt>
                                    <dd class="num text-ink-800">{{ \App\Support\Rupiah::format((float) $value) }}</dd>
                                </div>
                            @endif
                        @endforeach
                        <div class="flex justify-between gap-2 border-t border-ink-100 pt-1.5 font-bold">
                            <dt>Total</dt>
                            <dd class="num">Rp{{ \App\Support\Rupiah::format((float) ($e['total'] ?? 0)) }}</dd>
                        </div>
                    </dl>

                    @if (filled($e['notes'] ?? null))
                        <p class="mt-3 border-t border-ink-100 pt-3 text-xs text-ink-500">{{ $e['notes'] }}</p>
                    @endif
                </div>
            @endif
        </div>

        {{-- ── Kolom kanan: rincian biaya + usulan jurnal ─────────────────── --}}
        <div class="space-y-5 lg:col-span-2">
            @if ($e && ($e['items'] ?? []))
                <div class="card overflow-hidden">
                    <p class="border-b border-ink-200 bg-ink-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-ink-500">
                        Rincian biaya yang dibaca AI ({{ count($e['items']) }} baris)
                    </p>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr>
                                    <th class="th">Keterangan</th>
                                    <th class="th num">Qty</th>
                                    <th class="th num">Nominal</th>
                                    <th class="th">Usulan akun</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($e['items'] as $item)
                                    <tr>
                                        <td class="td">{{ $item['description'] }}</td>
                                        <td class="td num text-xs text-ink-500">
                                            {{ $item['qty'] ? \App\Support\Rupiah::format((float) $item['qty']).' '.($item['unit'] ?: '') : '—' }}
                                        </td>
                                        <td class="td num">{{ \App\Support\Rupiah::format((float) $item['amount']) }}</td>
                                        <td class="td">
                                            <span class="font-mono text-xs">{{ $item['account_code'] }}</span>
                                            @if (($item['confidence'] ?? 1) < $lowConfidence)
                                                <span class="badge-warn ml-1">ragu {{ round(($item['confidence'] ?? 0) * 100) }}%</span>
                                            @endif
                                            @if (filled($item['account_reason'] ?? null))
                                                <p class="text-xs text-ink-400">{{ $item['account_reason'] }}</p>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if ($postedJournals->isNotEmpty())
                <div class="card overflow-hidden">
                    <p class="border-b border-ink-200 bg-emerald-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-emerald-700">
                        Sudah masuk buku — {{ $postedJournals->count() }} jurnal
                    </p>
                    @foreach ($postedJournals as $journal)
                        <div class="border-b border-ink-100 p-4 last:border-0">
                            <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span class="font-semibold text-ink-800">
                                    #{{ $journal->id }} · {{ $journal->date->format('d/m/Y') }} · {{ $journal->description ?: '—' }}
                                </span>
                                <span class="{{ ['posted' => 'badge-ok', 'draft' => 'badge-warn', 'void' => 'badge-bad'][$journal->status] }}">{{ $journal->status }}</span>
                            </div>
                            <table class="w-full text-sm">
                                @foreach ($journal->lines as $line)
                                    <tr>
                                        <td class="py-0.5 text-ink-600">
                                            <span class="font-mono text-xs">{{ $line->account->code }}</span> {{ $line->account->name }}
                                            @if ($line->memo)<span class="text-xs text-ink-400"> — {{ $line->memo }}</span>@endif
                                        </td>
                                        <td class="num py-0.5 w-32">{{ (float) $line->debit ? \App\Support\Rupiah::format((float) $line->debit) : '' }}</td>
                                        <td class="num py-0.5 w-32">{{ (float) $line->credit ? \App\Support\Rupiah::format((float) $line->credit) : '' }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($drafts)
                <form method="POST" action="{{ route('documents.post', [$client, $document]) }}" class="space-y-4">
                    @csrf
                    <div class="rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900">
                        <p class="font-semibold">{{ count($drafts) }} usulan jurnal siap diposting.</p>
                        <p>Periksa akun & nominalnya. Yang kamu ubah di sini itulah yang masuk buku — bukan tebakan AI.</p>
                    </div>

                    @foreach ($drafts as $i => $draft)
                        <div class="card p-4" data-journal>
                            <input type="hidden" name="drafts[{{ $i }}][fingerprint]" value="{{ $draft['fingerprint'] }}">
                            <input type="hidden" name="drafts[{{ $i }}][type]" value="{{ $draft['type'] }}">

                            <div class="mb-3 flex flex-wrap items-end gap-3">
                                <label class="flex items-center gap-2 pb-2 text-sm font-semibold">
                                    <input type="checkbox" name="drafts[{{ $i }}][post]" value="1" checked class="rounded border-ink-300">
                                    Posting
                                </label>
                                <div>
                                    <label class="label">Tanggal</label>
                                    <input type="date" name="drafts[{{ $i }}][date]" value="{{ $draft['date'] }}" class="field w-40">
                                </div>
                                <div class="grow min-w-48">
                                    <label class="label">Keterangan</label>
                                    <input type="text" name="drafts[{{ $i }}][description]" value="{{ $draft['description'] }}" class="field">
                                </div>
                                <div class="w-36">
                                    <label class="label">Referensi</label>
                                    <input type="text" name="drafts[{{ $i }}][reference]" value="{{ $draft['reference'] }}" class="field">
                                </div>
                                <button type="button" data-reverse class="btn-ghost btn-sm mb-0.5"
                                        title="Tukar sisi debit dan kredit seluruh baris — untuk bukti transfer yang arahnya kebalik">
                                    ⇄ Balik debit/kredit
                                </button>
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
                                    <tbody>
                                        @foreach ($draft['lines'] as $j => $line)
                                            <tr data-line>
                                                <td class="td">
                                                    <select name="drafts[{{ $i }}][lines][{{ $j }}][account_id]" class="field">
                                                        @foreach ($accounts as $account)
                                                            <option value="{{ $account->id }}" @selected($account->id === $line['account_id'])>{{ $account->label() }}</option>
                                                        @endforeach
                                                    </select>
                                                    @if (($line['confidence'] ?? 1) !== null && ($line['confidence'] ?? 1) < $lowConfidence)
                                                        <p class="mt-1 text-xs text-amber-700">AI ragu ({{ round(($line['confidence'] ?? 0) * 100) }}%) — pastikan akunnya benar.</p>
                                                    @endif
                                                    @if (filled($line['reason'] ?? null))
                                                        <p class="mt-0.5 text-xs text-ink-400">{{ $line['reason'] }}</p>
                                                    @endif
                                                </td>
                                                <td class="td">
                                                    <input type="text" name="drafts[{{ $i }}][lines][{{ $j }}][memo]" value="{{ $line['memo'] }}" class="field">
                                                </td>
                                                <td class="td">
                                                    <input type="number" step="0.01" min="0" data-money="debit"
                                                           name="drafts[{{ $i }}][lines][{{ $j }}][debit]"
                                                           value="{{ $line['debit'] ?: '' }}" class="field num">
                                                </td>
                                                <td class="td">
                                                    <input type="number" step="0.01" min="0" data-money="credit"
                                                           name="drafts[{{ $i }}][lines][{{ $j }}][credit]"
                                                           value="{{ $line['credit'] ?: '' }}" class="field num">
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="bg-ink-50 font-semibold">
                                            <td class="td" colspan="2">
                                                Total <span data-balance-state class="badge-mute ml-1">—</span>
                                            </td>
                                            <td class="td num" data-total="debit">0</td>
                                            <td class="td num" data-total="credit">0</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    @endforeach

                    <div class="flex items-center gap-2">
                        <button class="btn-primary">Posting jurnal yang dicentang</button>
                        <a href="{{ route('documents.index', $client) }}" class="btn-ghost">Nanti saja</a>
                    </div>
                </form>
            @elseif ($document->status === \App\Models\Document::STATUS_EXTRACTED)
                <div class="card p-8 text-center text-sm">
                    <p class="font-semibold text-ink-800">AI tidak menemukan transaksi yang bisa dijurnal.</p>
                    <p class="mt-1 text-ink-500">Mungkin dokumennya bukan bukti transaksi, atau terlalu buram.
                        Coba "Baca ulang", atau catat lewat
                        <a href="{{ route('journals.create', $client) }}" class="text-brand-700 font-medium hover:underline">jurnal manual</a>.</p>
                </div>
            @endif
        </div>
    </div>
@endsection
