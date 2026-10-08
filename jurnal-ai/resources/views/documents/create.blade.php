@extends('layouts.app')
@section('title', 'Dokumen baru')

@section('content')
    @unless ($aiReady)
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Otak AI belum terpasang (API key kosong di <code class="font-mono">.env</code>). Dokumen akan tersimpan,
            tapi pembacaan otomatis gagal — kamu masih bisa catat lewat jurnal manual.
        </div>
    @endunless

    <form method="POST" action="{{ route('documents.store', $client) }}" enctype="multipart/form-data" class="grid gap-5 lg:grid-cols-3">
        @csrf

        <div class="card p-6 space-y-5 lg:col-span-2">
            <div>
                <label class="label" for="kind">Jenis dokumen <span class="text-rose-600">*</span></label>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (\App\Models\Document::KINDS as $value => $label)
                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-ink-200 p-3 text-sm hover:border-brand-500 has-checked:border-brand-600 has-checked:bg-brand-50">
                            <input type="radio" name="kind" value="{{ $value }}" required
                                   @checked(old('kind', \App\Models\Document::KIND_RECEIPT) === $value) class="mt-0.5">
                            <span>
                                <span class="font-medium text-ink-800">{{ $label }}</span>
                                <span class="block text-xs text-ink-500">
                                    {{ [
                                        'receipt' => 'Foto HP, nota kasir, bon tulis tangan.',
                                        'invoice' => 'PDF faktur vendor, tagihan resmi.',
                                        'bank' => 'Banyak baris mutasi dalam satu gambar.',
                                        'text' => 'Paste apa adanya, AI yang pecah.',
                                    ][$value] }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div>
                <label class="label" for="title">Judul (opsional)</label>
                <input type="text" id="title" name="title" value="{{ old('title') }}" class="field"
                       placeholder="Belanja bahan baku Oktober, Invoice vendor kemasan, …">
            </div>

            <div>
                <label class="label" for="file">Berkas</label>
                <input type="file" id="file" name="file" accept="image/*,.pdf" class="field file:mr-3 file:rounded file:border-0 file:bg-ink-100 file:px-3 file:py-1 file:text-sm">
                <p class="mt-1 text-xs text-ink-400">JPG / PNG / WEBP / HEIC / PDF, maksimal {{ number_format($maxKb / 1024, 0) }} MB.</p>
            </div>

            <div class="relative">
                <div class="absolute inset-x-0 -top-1 flex justify-center">
                    <span class="bg-white px-2 text-xs font-semibold uppercase tracking-wide text-ink-400">atau / dan</span>
                </div>
                <div class="border-t border-ink-200 pt-5">
                    <label class="label" for="raw_text">Teks tempel</label>
                    <textarea id="raw_text" name="raw_text" rows="7" class="field font-mono text-xs"
                              placeholder="bayar iklan meta 500rb&#10;gaji admin 3jt&#10;ongkir jne 85.000&#10;beli botol 500ml 200pcs @3500">{{ old('raw_text') }}</textarea>
                    <p class="mt-1 text-xs text-ink-400">
                        Boleh diisi bareng berkas — teks di sini dipakai AI sebagai konteks tambahan untuk membaca gambarnya.
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2 border-t border-ink-200 pt-4">
                <button class="btn-primary">Upload & baca dengan AI</button>
                <a href="{{ route('documents.index', $client) }}" class="btn-ghost">Batal</a>
            </div>
        </div>

        <aside class="card h-fit p-5 text-sm">
            <h2 class="mb-2 font-bold text-ink-900">Cara kerjanya</h2>
            <ol class="space-y-2.5 text-ink-600">
                <li><span class="badge-info mr-1">1</span> Dokumen diupload & disimpan apa adanya.</li>
                <li><span class="badge-info mr-1">2</span> AI baca: tarik vendor, tanggal, rincian biaya per baris, pajak, total — lalu usulkan akun COA tiap baris.</li>
                <li><span class="badge-info mr-1">3</span> <strong class="text-ink-800">Kamu review.</strong> Ganti akun/nominal yang salah. AI tidak pernah posting sendiri.</li>
                <li><span class="badge-info mr-1">4</span> Posting → masuk buku sebagai jurnal double-entry yang balance.</li>
            </ol>
            <p class="mt-4 border-t border-ink-100 pt-3 text-xs text-ink-400">
                Dokumen identik yang pernah diupload akan ditandai, dan sidik jari tiap transaksi menahan posting dobel.
            </p>
        </aside>
    </form>
@endsection
