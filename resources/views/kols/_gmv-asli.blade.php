{{-- Sel "GMV Asli": isian manual screening → GMV 30 hari TikTok Creator Marketplace
     (0 tetap tampil "Rp 0", bukan "—") → rentang GMV bila TikTok tak memberi angka pasti. --}}
@php $tpp = $kol->tiktokProfile; @endphp
@if($manual)
    <span class="font-semibold text-emerald-700">{{ $rp($manual) }}</span>
@elseif($tpp && $tpp->gmv_idr !== null)
    <span class="font-semibold {{ $tpp->gmv_idr > 0 ? 'text-emerald-700' : 'text-stone-500' }}" title="Dari profil TikTok Creator Marketplace (30 hari)">{{ $rp($tpp->gmv_idr) }}</span>
@elseif($tpp && $tpp->gmv_range)
    <span class="font-semibold text-stone-600" title="TikTok hanya memberi rentang GMV 30 hari (angka pasti disembunyikan)">{{ $tpp->gmv_range }}</span>
@elseif($tpp)
    <span class="text-stone-400" title="Sudah dicek ke TikTok Marketplace, tapi TikTok tidak memberi data GMV untuk kreator ini">tak ada data</span>
@else
    <span class="text-stone-300" title="Belum tersinkron dari TikTok Marketplace — buka Cek Performa TikTok untuk tarik sekarang">—</span>
@endif
