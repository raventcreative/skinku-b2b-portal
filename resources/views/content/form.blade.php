@extends('layouts.app')
@section('title', $post->exists ? 'Edit Konten' : 'Buat Konten')
@section('heading', $post->exists ? 'Edit Konten' : 'Buat Konten')

@section('content')
@php
    $platforms = config('content.platforms');
    $media = $post->exists ? $post->filesIn(\App\Models\ContentPost::MEDIA)->get() : collect();
    $selected = old('platforms', $selected);
    $tiktokConnection = $connections['tiktok'] ?? null;
    $tiktokApi = ($platforms['tiktok']['mode'] ?? null) === 'auto' && $tiktokConnection?->isActive();
    $privacyOptions = \App\Services\Social\TikTokContentClient::privacyOptions($tiktokInfo['privacy_level_options'] ?? null);
@endphp

<div class="mx-auto max-w-6xl space-y-5">
    <div class="flex items-center justify-between gap-3 border-b border-stone-200 pb-4">
        <div>
            <p class="text-[10px] font-bold uppercase tracking-[.18em] text-red-700">Studio Konten SKINKU</p>
            <p class="mt-1 text-sm text-stone-600">Unggah materi sekali, lalu sesuaikan caption dan waktu terbit per kanal.</p>
        </div>
        <a href="{{ route('content.index') }}" class="inline-flex min-h-9 items-center gap-2 rounded-lg px-3 text-xs font-semibold text-stone-600 hover:bg-stone-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-700">
            <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="m12.5 4.5-5 5.5 5 5.5"/></svg>Kembali ke pipeline
        </a>
    </div>

    @if($errors->any())
        <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <p class="font-semibold">Periksa kembali konten sebelum menyimpan.</p>
            <ul class="mt-1 list-inside list-disc text-xs">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" enctype="multipart/form-data" action="{{ $post->exists ? route('content.update', $post) : route('content.store') }}" class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_21rem]">
        @csrf
        @if($post->exists) @method('PUT') @endif

        <div class="space-y-5">
            <section class="rounded-2xl border border-stone-200 bg-white p-4 sm:p-6">
                <div class="mb-5 flex items-center gap-3 border-b border-stone-100 pb-4">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-red-50 text-red-700"><svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h14M3 9.5h9M3 14.5h6"/></svg></span>
                    <div><h2 class="text-sm font-bold text-stone-900">Materi konten</h2><p class="mt-0.5 text-[11px] text-stone-500">Tentukan identitas konten dan unggah media.</p></div>
                </div>
                <div class="space-y-4">
                    <fieldset>
                        <legend class="text-xs font-semibold text-stone-700">Format media <span class="text-red-700">*</span></legend>
                        <div class="mt-2 grid grid-cols-3 gap-2">
                            @foreach(\App\Models\ContentPost::TYPES as $key => $label)
                                <label class="flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-lg border border-stone-200 px-2 text-xs font-semibold text-stone-700 transition has-[:checked]:border-red-600 has-[:checked]:bg-red-50 has-[:checked]:text-red-800">
                                    <input type="radio" name="type" value="{{ $key }}" @checked(old('type', $post->type) === $key) class="accent-red-700">{{ $label }}
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-1.5 text-[11px] text-stone-500">Foto: 1 gambar · Video: 1 MP4/MOV · Carousel: {{ config('content.carousel_min') }}–{{ config('content.carousel_max') }} gambar.</p>
                    </fieldset>

                    <div>
                        <label for="contentMedia" class="text-xs font-semibold text-stone-700">{{ $post->exists ? 'Ganti media (opsional)' : 'File media' }} <span class="text-red-700">*</span></label>
                        <input id="contentMedia" type="file" name="media[]" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/x-m4v,.mp4,.mov,.m4v" data-video-max="{{ config('content.video_max_kb') * 1024 }}" data-image-max="{{ config('content.image_max_kb') * 1024 }}" class="mt-1.5 block min-h-12 w-full rounded-lg border border-dashed border-stone-300 bg-stone-50 p-2 text-xs file:mr-3 file:rounded-md file:border-0 file:bg-stone-900 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-white">
                        <p class="mt-1.5 text-[11px] text-stone-500">JPG/PNG/WEBP maks {{ intdiv(config('content.image_max_kb'), 1024) }} MB · video maks {{ intdiv(config('content.video_max_kb'), 1024) }} MB. Upload baru mengganti media lama.</p>
                        <p id="mediaError" role="alert" class="mt-1.5 text-[11px] font-semibold text-rose-700" hidden></p>
                        @if($media->isNotEmpty())
                            <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-5">
                                @foreach($media as $file)
                                    @if($file->isImage())<img src="{{ $file->url() }}" alt="Media tersimpan" class="aspect-square w-full rounded-lg border border-stone-200 object-cover">@else
                                        <video src="{{ $file->url() }}" controls class="aspect-square w-full rounded-lg border border-stone-200 bg-black object-cover"></video>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <label class="block">
                        <span class="flex items-center justify-between gap-2 text-xs font-semibold text-stone-700"><span>Caption utama</span><span class="text-[10px] font-normal text-stone-400"><span id="captionCount">{{ mb_strlen(old('caption', $post->caption ?? '')) }}</span> karakter</span></span>
                        <textarea name="caption" rows="6" id="mainCaption" class="mt-1.5 block w-full px-3 py-2.5 text-sm" placeholder="Tulis pesan utama untuk konten ini">{{ old('caption', $post->caption) }}</textarea>
                        <span class="mt-1 block text-[11px] text-stone-500">Batas caption berbeda per platform. Anda dapat membuat versi khusus setelah memilih kanal.</span>
                    </label>
                </div>
            </section>

            <section class="rounded-2xl border border-stone-200 bg-white p-4 sm:p-6">
                <div class="mb-4 flex items-center justify-between gap-3 border-b border-stone-100 pb-4">
                    <div><h2 class="text-sm font-bold text-stone-900">Tujuan publikasi</h2><p class="mt-0.5 text-[11px] text-stone-500">Pilih semua kanal yang akan menerima konten ini.</p></div>
                </div>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach($platforms as $key => $platform)
                        @php $connection = $connections[$key] ?? null; $manual = $platform['mode'] === 'manual' || ($platform['mode'] === 'auto' && ! $connection?->isActive()); @endphp
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-stone-200 p-3 transition hover:border-stone-300 has-[:checked]:border-red-300 has-[:checked]:bg-red-50/50">
                            <input type="checkbox" name="platforms[]" value="{{ $key }}" @checked(in_array($key, $selected, true)) class="mt-0.5 h-4 w-4 rounded border-stone-300 accent-red-700">
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center justify-between gap-2"><span class="text-sm font-semibold text-stone-800">{{ $platform['label'] }}</span><span class="rounded-md px-1.5 py-0.5 text-[9px] font-semibold {{ $manual ? 'bg-amber-50 text-amber-800' : ($connection?->isActive() ? 'bg-emerald-50 text-emerald-800' : 'bg-stone-100 text-stone-600') }}">{{ $manual ? 'Manual' : ($connection?->isActive() ? 'Terhubung' : 'API') }}</span></span>
                                <span class="mt-1 block text-[10px] leading-4 text-stone-500">{{ $manual ? 'Posting dilakukan manual, lalu catat tautannya.' : ($connection?->isActive() ? 'Konten masuk antrean publikasi otomatis.' : 'Perlu akun terhubung untuk publikasi otomatis.') }}</span>
                                @if($key === 'tiktok' && $tiktokApi)
                                    <span class="mt-2 block text-[10px] font-medium text-stone-600">{{ ! empty($tiktokInfo['creator_nickname']) ? 'Akun: '.$tiktokInfo['creator_nickname'] : 'Direct Post aktif' }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>

                @if($tiktokApi)
                    <div class="mt-4 rounded-xl border border-stone-200 bg-stone-50/70 p-4">
                        <h3 class="text-xs font-bold text-stone-800">Setelan TikTok Direct Post</h3>
                        @if(! empty($tiktokInfo['error']))<p role="alert" class="mt-2 text-[11px] text-rose-700">Info akun belum dapat dibaca: {{ $tiktokInfo['error'] }}</p>@endif
                        <label class="mt-3 block">
                            <span class="text-[11px] font-semibold text-stone-700">Privasi publikasi</span>
                            <select id="ttPrivacy" name="tiktok[privacy_level]" class="mt-1 block min-h-10 w-full px-3 text-sm">
                                <option value="">Pilih privasi</option>
                                @foreach($privacyOptions as $option)<option value="{{ $option }}" @selected(old('tiktok.privacy_level', $post->targets->firstWhere('platform', 'tiktok')?->options['privacy_level'] ?? '') === $option)>{{ \App\Services\Social\TikTokContentClient::PRIVACY_LABELS[$option] ?? $option }}</option>@endforeach
                            </select>
                        </label>
                        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-[11px] text-stone-700">
                            <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="tiktok[allow_comment]" value="1" @checked(old('tiktok.allow_comment')) @disabled(! empty($tiktokInfo['comment_disabled'])) class="accent-red-700">Izinkan komentar</label>
                            <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="tiktok[allow_duet]" value="1" @checked(old('tiktok.allow_duet')) @disabled(! empty($tiktokInfo['duet_disabled'])) class="accent-red-700">Izinkan duet</label>
                            <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="tiktok[allow_stitch]" value="1" @checked(old('tiktok.allow_stitch')) @disabled(! empty($tiktokInfo['stitch_disabled'])) class="accent-red-700">Izinkan stitch</label>
                        </div>
                        {{-- Pengungkapan konten komersial sesuai TikTok Content Sharing Guidelines. --}}
                        <div class="mt-3 rounded-lg border border-stone-200 bg-white p-3 text-[11px] text-stone-700">
                            <label class="flex items-start gap-2 font-semibold"><input type="checkbox" id="ttDisclose" name="tiktok[disclose]" value="1" @checked(old('tiktok.disclose')) class="mt-0.5 accent-red-700"><span>Ungkap konten komersial<span class="block font-normal text-stone-500">Aktifkan bila konten mempromosikan brand, produk, atau layanan.</span></span></label>
                            <div id="ttDiscloseOptions" class="mt-2 space-y-1.5 pl-6" @if(! old('tiktok.disclose')) hidden @endif>
                                <label class="flex items-start gap-2"><input type="checkbox" id="ttBrandOrganic" name="tiktok[brand_organic]" value="1" @checked(old('tiktok.brand_organic')) class="mt-0.5 accent-red-700"><span>Brand sendiri<span class="block text-stone-500">Mempromosikan diri atau bisnis sendiri.</span></span></label>
                                <label class="flex items-start gap-2"><input type="checkbox" id="ttBrandContent" name="tiktok[brand_content]" value="1" @checked(old('tiktok.brand_content')) @disabled(! config('services.tiktok_content.audited')) class="mt-0.5 accent-red-700"><span>Branded content<span class="block text-stone-500">{{ config('services.tiktok_content.audited') ? 'Kerja sama berbayar dengan brand pihak ketiga.' : 'Tersedia setelah app TikTok lolos audit (branded content tidak boleh private).' }}</span></span></label>
                                <p id="ttDiscloseLabel" class="font-semibold text-stone-800" aria-live="polite"></p>
                            </div>
                        </div>
                        <p class="mt-3 text-[11px] leading-5 text-stone-600"><span>Dengan menerbitkan, Anda menyetujui <span id="ttBcPolicy" @if(! old('tiktok.brand_content')) hidden @endif><a href="https://www.tiktok.com/legal/page/global/bc-policy/en" target="_blank" rel="noopener noreferrer" class="font-semibold text-red-700 underline underline-offset-2">Branded Content Policy</a> dan </span><a href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en" target="_blank" rel="noopener noreferrer" class="font-semibold text-red-700 underline underline-offset-2">Music Usage Confirmation</a> TikTok untuk konten ini.</span></p>
                    </div>
                @endif

                <div class="mt-4 border-t border-stone-100 pt-4">
                    <label class="block">
                        <span class="text-xs font-semibold text-stone-700">Catatan untuk tim <span class="font-normal text-stone-400">(opsional)</span></span>
                        <textarea name="creator_note" rows="2" maxlength="2000" class="mt-1.5 block w-full px-3 py-2 text-sm" placeholder="Catatan produksi atau konteks internal">{{ old('creator_note', $post->creator_note) }}</textarea>
                    </label>
                </div>
            </section>
        </div>

        <aside class="space-y-4">
            <section class="rounded-2xl border border-stone-200 bg-white p-4 sm:p-5">
                <div class="mb-4 flex items-center gap-3 border-b border-stone-100 pb-4">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-red-50 text-red-700"><svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 2v4M8 2v4M3 10h18"/></svg></span>
                    <div><h2 class="text-sm font-bold text-stone-900">Waktu terbit</h2><p class="mt-0.5 text-[11px] text-stone-500">Pilih sekarang atau atur kalender.</p></div>
                </div>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-700">Tanggal dan waktu</span>
                    <input id="scheduledAt" type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $post->scheduled_at?->format('Y-m-d\TH:i')) }}" class="mt-1.5 block min-h-11 w-full px-3 text-sm">
                    <span class="mt-1.5 block text-[11px] leading-4 text-stone-500">Kosongkan untuk memasukkan konten ke antrean sekarang. Waktu masa depan akan mengikuti kalender.</span>
                </label>
            </section>

            <section class="rounded-2xl border border-stone-200 bg-stone-50/70 p-4 sm:p-5">
                <h2 class="text-xs font-bold uppercase tracking-[.12em] text-stone-600">Sesudah disimpan</h2>
                <p class="mt-2 text-xs leading-5 text-stone-600">Draft tetap di pipeline. Terbitkan/jadwalkan akan memasukkan target ke antrean sesuai waktu pilihan, tanpa menunggu persetujuan.</p>
            </section>

            <div class="sticky bottom-3 flex flex-col gap-2 rounded-xl border border-stone-200 bg-white/95 p-3 shadow-sm backdrop-blur sm:static sm:border-0 sm:bg-transparent sm:p-0 sm:shadow-none">
                <button type="submit" name="intent" value="publish" id="publishButton" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-red-700 px-4 text-sm font-bold text-white shadow-sm hover:bg-red-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700">
                    <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10 17 3l-4 14-3.5-5.5L3 10Z"/><path stroke-linecap="round" stroke-linejoin="round" d="m9.5 11.5 4-4"/></svg><span id="publishLabel">{{ old('scheduled_at', $post->scheduled_at) ? 'Jadwalkan publikasi' : 'Terbitkan sekarang' }}</span>
                </button>
                <button type="submit" name="intent" value="draft" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-stone-200 bg-white px-4 text-sm font-semibold text-stone-700 hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700">
                    <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M4 3h10l3 3v11H4zM7 3v5h7V3M7 17v-6h7v6"/></svg>Simpan draft
                </button>
            </div>
        </aside>
    </form>

    @if($post->exists && $post->status === 'draft')
        <form method="POST" action="{{ route('content.destroy', $post) }}" onsubmit="return confirm('Hapus draft konten ini beserta media?')" class="border-t border-stone-200 pt-4">
            @csrf @method('DELETE')
            <button class="inline-flex min-h-9 items-center gap-2 rounded-lg border border-rose-200 bg-white px-3 text-xs font-semibold text-rose-700 hover:bg-rose-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-700">
                <svg aria-hidden="true" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h14M8 5V3h4v2m-7 0 1 12h8l1-12m-6 3v6m4-6v6"/></svg>Hapus draft
            </button>
        </form>
    @endif
</div>

<script>
    (function () {
        var caption = document.getElementById('mainCaption');
        var count = document.getElementById('captionCount');
        var schedule = document.getElementById('scheduledAt');
        var label = document.getElementById('publishLabel');
        if (caption && count) caption.addEventListener('input', function () { count.textContent = caption.value.length; });
        if (schedule && label) schedule.addEventListener('input', function () { label.textContent = schedule.value ? 'Jadwalkan publikasi' : 'Terbitkan sekarang'; });

        // Media: tolak file kebesaran sebelum upload (di atas batas PHP form terbuang tanpa pesan),
        // lalu tunjukkan sedang mengunggah agar upload video besar tidak terlihat macet.
        var media = document.getElementById('contentMedia'), mediaError = document.getElementById('mediaError');
        if (media && mediaError) media.addEventListener('change', function () {
            var tooBig = Array.prototype.find.call(media.files, function (f) {
                return f.size > Number(f.type.indexOf('image/') === 0 ? media.dataset.imageMax : media.dataset.videoMax);
            });
            mediaError.hidden = !tooBig;
            if (tooBig) {
                mediaError.textContent = tooBig.name + ' terlalu besar (' + Math.ceil(tooBig.size / 1048576) + ' MB). Maks video '
                    + Math.floor(media.dataset.videoMax / 1048576) + ' MB, gambar ' + Math.floor(media.dataset.imageMax / 1048576) + ' MB.';
                media.value = '';
            }
        });
        var form = media && media.form, sending = false;
        if (form) form.addEventListener('submit', function (e) {
            if (sending) { e.preventDefault(); return; } // cegah kirim ganda
            sending = true;
            var btn = e.submitter, text = btn && (btn.querySelector('span') || btn);
            if (btn) { btn.setAttribute('aria-busy', 'true'); btn.style.opacity = '.7'; btn.style.cursor = 'wait'; }
            if (!media.files.length || !window.FormData) { if (text) text.textContent = 'Menyimpan…'; return; }

            // Ada media → kirim lewat XHR agar persen upload terlihat. Halaman hasil (sukses atau
            // error validasi) ditampilkan apa adanya, sama seperti submit biasa.
            e.preventDefault();
            var data = new FormData(form);
            if (btn && btn.name) data.append(btn.name, btn.value);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', form.action);
            xhr.upload.onprogress = function (ev) {
                if (ev.lengthComputable && text) text.textContent = ev.loaded < ev.total
                    ? 'Mengunggah ' + Math.floor(ev.loaded / ev.total * 100) + '% — jangan tutup halaman'
                    : 'Memproses di server…';
            };
            xhr.onload = function () {
                history.replaceState(null, '', xhr.responseURL || form.action);
                document.open(); document.write(xhr.responseText); document.close();
            };
            xhr.onerror = function () {
                sending = false;
                if (btn) { btn.removeAttribute('aria-busy'); btn.style.opacity = ''; btn.style.cursor = ''; }
                if (text) text.textContent = 'Gagal mengunggah — cek koneksi lalu coba lagi';
            };
            xhr.send(data);
        });

        // TikTok: branded content tidak boleh privat; disclosure aktif wajib pilih minimal satu jenis.
        var disclose = document.getElementById('ttDisclose');
        if (disclose) {
            var opts = document.getElementById('ttDiscloseOptions'), organic = document.getElementById('ttBrandOrganic'),
                branded = document.getElementById('ttBrandContent'), note = document.getElementById('ttDiscloseLabel'),
                policy = document.getElementById('ttBcPolicy'), privacy = document.getElementById('ttPrivacy'),
                selfOnly = privacy && privacy.querySelector('option[value="SELF_ONLY"]'), publish = document.getElementById('publishButton');
            var sync = function () {
                var on = disclose.checked, bc = on && branded.checked, any = on && (organic.checked || branded.checked);
                opts.hidden = !on;
                policy.hidden = !bc;
                note.textContent = !on ? '' : bc ? 'Konten akan diberi label "Paid partnership".' : organic.checked ? 'Konten akan diberi label "Promotional content".' : 'Pilih minimal satu jenis konten komersial.';
                if (selfOnly) {
                    selfOnly.disabled = bc;
                    selfOnly.textContent = bc ? 'Hanya saya (tidak tersedia untuk branded content)' : 'Hanya saya (private)';
                    if (bc && privacy.value === 'SELF_ONLY') privacy.value = '';
                }
                publish.disabled = on && !any;
                publish.title = publish.disabled ? 'Pilih jenis konten komersial terlebih dahulu.' : '';
            };
            [disclose, organic, branded].forEach(function (el) { el.addEventListener('change', sync); });
            sync();
        }
    })();
</script>
@endsection
