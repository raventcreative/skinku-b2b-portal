{{-- Status kirim terakhir satu listing (badge per jenis) + pesan error-nya. Dipakai halaman channel & respons simpan
     otomatis override (baris diperbarui tanpa reload). Butuh $lst (listing di channel itu, boleh null). --}}
@php
    $kirim = [
        ['stok', 'Stok', $lst?->last_status, $lst?->last_error, $lst?->last_pushed_at],
        ['harga', 'Harga', $lst?->last_price_status, $lst?->last_price_error, $lst?->last_price_pushed_at],
    ];
    // Konten & foto hanya didorong manual ("Dorong konten & foto") → badge-nya muncul bila pernah didorong.
    if ($lst?->last_content_status) { $kirim[] = ['konten', 'Konten', $lst->last_content_status, $lst->last_content_error, null]; }
    if ($lst?->last_photo_status) { $kirim[] = ['foto', 'Foto', $lst->last_photo_status, $lst->last_photo_error, null]; }
@endphp
<div class="flex flex-wrap gap-1">
    @foreach($kirim as [$kunci, $label, $status, $err, $waktu])
        @php
            [$warna, $tanda, $ket] = match ($status) {
                'ok' => ['bg-emerald-50 text-emerald-700', '✓', 'terkirim'.($waktu ? ' '.$waktu->translatedFormat('d M Y H:i') : '')],
                'failed' => ['bg-rose-50 text-rose-700', 'gagal', 'gagal dikirim — lihat pesan di bawah'],
                null => ['bg-stone-100 text-stone-400', '—', 'belum pernah dikirim'],
                default => ['bg-stone-100 text-stone-600', $status, $status],
            };
        @endphp
        <span data-kirim="{{ $kunci }}" class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap {{ $warna }}" title="{{ $label }}: {{ $ket }}">{{ $label }} {{ $tanda }}</span>
    @endforeach
</div>
@foreach($kirim as [$kunci, $label, $status, $err])
    @if($err)<div class="mt-1 text-[11px] text-rose-600 break-words max-w-[260px]" title="{{ $err }}">{{ $kunci }}: {{ \Illuminate\Support\Str::limit($err, 80) }}</div>@endif
@endforeach
