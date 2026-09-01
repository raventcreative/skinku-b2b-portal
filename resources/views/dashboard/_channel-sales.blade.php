@if(($channelSales ?? null))
    @php
        $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $cs = collect($channelSales);
        $sumConfirmed = $cs->sum('confirmed');
        $sumConfirmedN = $cs->sum('confirmed_n');
        $sumPipeline = $cs->sum('pipeline');
        $sumPipelineN = $cs->sum('pipeline_n');
        $sumCancelled = $cs->sum('cancelled');
        $sumCancelledN = $cs->sum('cancelled_n');
        $sumUnpaid = $cs->sum('unpaid');
        $sumUnpaidN = $cs->sum('unpaid_n');
        $estimasi = $sumConfirmed + $sumPipeline;
        $allOrders = $cs->sum('orders_n');
        $cancelRate = $allOrders > 0 ? round($sumCancelledN / $allOrders * 100, 1) : 0;
        // Rentang aktif untuk section ini (default = bulan; ?ch_dari/?ch_sampai override).
        $chActive = ($chFrom ?? null) && ($chSampai ?? null);
        $chLabel = $chActive
            ? ($chFrom->isSameDay($chSampai) ? $chFrom->translatedFormat('d M Y') : $chFrom->translatedFormat('d M').' – '.$chSampai->translatedFormat('d M Y'))
            : $bulan->translatedFormat('F Y');
        $chBulan = request('bulan');
    @endphp
    <div class="bg-white rounded-2xl border border-stone-200 p-5 mb-6">
        <div class="mb-4">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-bold text-stone-800">Penjualan per Channel</h3>
                <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full {{ $chActive ? 'bg-red-50 text-red-700' : 'bg-stone-100 text-stone-500' }}">{{ $chLabel }}</span>
                <span class="ml-auto text-[11px] text-stone-400">berdasarkan tanggal order masuk</span>
            </div>
            {{-- Filter tanggal khusus section ini (preset cepat + rentang bebas). Bulan Periode tetap dijaga. --}}
            <div class="flex flex-wrap items-center gap-1.5 mt-2.5">
                @php
                    $chPreset = fn ($d1, $d2) => route('dashboard', array_filter(['bulan' => $chBulan, 'ch_dari' => $d1, 'ch_sampai' => $d2]));
                    $isToday = $chActive && $chFrom->isToday() && $chSampai->isToday();
                    $isYest = $chActive && $chFrom->isYesterday() && $chSampai->isYesterday();
                    $is7 = $chActive && $chFrom->isSameDay(now()->subDays(6)) && $chSampai->isToday();
                @endphp
                <a href="{{ $chPreset(now()->toDateString(), now()->toDateString()) }}" class="ch-preset px-2.5 py-1 text-[11px] rounded-lg border {{ $isToday ? 'bg-red-600 text-white border-red-600' : 'bg-white border-stone-300 text-stone-600 hover:bg-stone-50' }}">Hari ini</a>
                <a href="{{ $chPreset(now()->subDay()->toDateString(), now()->subDay()->toDateString()) }}" class="ch-preset px-2.5 py-1 text-[11px] rounded-lg border {{ $isYest ? 'bg-red-600 text-white border-red-600' : 'bg-white border-stone-300 text-stone-600 hover:bg-stone-50' }}">Kemarin</a>
                <a href="{{ $chPreset(now()->subDays(6)->toDateString(), now()->toDateString()) }}" class="ch-preset px-2.5 py-1 text-[11px] rounded-lg border {{ $is7 ? 'bg-red-600 text-white border-red-600' : 'bg-white border-stone-300 text-stone-600 hover:bg-stone-50' }}">7 hari</a>
                <a href="{{ $chPreset(null, null) }}" class="ch-preset px-2.5 py-1 text-[11px] rounded-lg border{{ ! $chActive ? 'bg-stone-800 text-white border-stone-800' : 'bg-white border-stone-300 text-stone-600 hover:bg-stone-50' }}">Bulan ini</a>

                <form method="GET" action="{{ route('dashboard') }}" class="ch-form flex items-center gap-1 ml-auto text-[11px]">
                    <input type="hidden" name="bulan" value="{{ $chBulan }}">
                    <input type="date" name="ch_dari" value="{{ optional($chFrom ?? null)->toDateString() }}" class="px-2 py-1 border border-stone-300 rounded-lg">
                    <span class="text-stone-400">–</span>
                    <input type="date" name="ch_sampai" value="{{ optional($chSampai ?? null)->toDateString() }}" class="px-2 py-1 border border-stone-300 rounded-lg">
                    <button class="px-2.5 py-1 bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">Terapkan</button>
                </form>
            </div>
        </div>

        {{-- Ringkasan: sudah jadi + masih jalan = estimasi; batal/belum-bayar dipisah --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
            <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-3">
                <p class="text-[10px] uppercase tracking-wide text-emerald-700 font-semibold">Terealisasi</p>
                <p class="text-lg font-bold text-emerald-800 mt-1">{{ $rp($sumConfirmed) }}</p>
                <p class="text-[10px] text-emerald-600">{{ $sumConfirmedN }} order selesai</p>
            </div>
            <div class="rounded-xl bg-amber-50 border border-amber-200 p-3">
                <p class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold">Masih Berjalan</p>
                <p class="text-lg font-bold text-amber-800 mt-1">{{ $rp($sumPipeline) }}</p>
                <p class="text-[10px] text-amber-600">{{ $sumPipelineN }} order jalan</p>
            </div>
            <div class="rounded-xl bg-stone-800 p-3">
                <p class="text-[10px] uppercase tracking-wide text-stone-300 font-semibold">Estimasi {{ $chActive ? $chLabel : $bulan->translatedFormat('M Y') }}</p>
                <p class="text-lg font-bold text-white mt-1">{{ $rp($estimasi) }}</p>
                <p class="text-[10px] text-stone-400">terealisasi + berjalan</p>
            </div>
            <div class="rounded-xl bg-rose-50 border border-rose-200 p-3">
                <p class="text-[10px] uppercase tracking-wide text-rose-700 font-semibold">Batal &amp; Belum Bayar</p>
                <p class="text-lg font-bold text-rose-800 mt-1">{{ $rp($sumCancelled + $sumUnpaid) }}</p>
                <p class="text-[10px] text-rose-600">
                    cancel rate <b>{{ $cancelRate }}%</b> · {{ $sumCancelledN }} batal, {{ $sumUnpaidN }} blm bayar
                </p>
            </div>
        </div>

        {{-- Kiri: proporsi channel dari yang sudah cair.
             Kanan: SEMUA (cair + berjalan) — warna tua = cair, muda = berjalan,
             jadi komposisi total terbaca dalam satu lingkaran. --}}
        <div class="grid sm:grid-cols-2 gap-4 mb-4">
            <div class="rounded-xl border border-stone-100 p-3">
                <p class="text-[11px] font-semibold text-stone-600 text-center mb-2">Terealisasi · {{ $rp($sumConfirmed) }}</p>
                {{-- Canvas dibiarkan selebar panel, tinggi yang mengunci ukuran donat
                     (radius = min(lebar,tinggi)/2, jadi donatnya tetap 170px dan
                     terpusat). Dulu dikurung max-width:170px — tooltip Chart.js
                     digambar DI ATAS canvas, jadi teks yang lebih lebar dari 170px
                     terpotong dan angkanya tak terbaca. --}}
                @if($sumConfirmed > 0)
                    <div style="height:170px"><canvas id="channelChart-confirmed"></canvas></div>
                @else
                    <p class="text-[11px] text-stone-300 text-center py-10">belum ada</p>
                @endif
            </div>
            <div class="rounded-xl border border-stone-100 p-3">
                <p class="text-[11px] font-semibold text-stone-600 text-center mb-2">Semua (cair + berjalan) · {{ $rp($estimasi) }}</p>
                @if($estimasi > 0)
                    <div style="height:170px"><canvas id="channelChart-all"></canvas></div>
                    <div class="flex flex-wrap justify-center gap-x-3 gap-y-1 mt-3">
                        @foreach($channelSales as $ch)
                            @if($ch['confirmed'] > 0)
                                <span class="flex items-center gap-1 text-[10px] text-stone-500">
                                    <span class="w-2 h-2 rounded-full inline-block" style="background:{{ $ch['color'] }}"></span>{{ $ch['label'] }} cair
                                </span>
                            @endif
                            @if($ch['pipeline'] > 0)
                                <span class="flex items-center gap-1 text-[10px] text-stone-500">
                                    <span class="w-2 h-2 rounded-full inline-block" style="background:{{ $ch['color_light'] }}"></span>{{ $ch['label'] }} berjalan
                                </span>
                            @endif
                        @endforeach
                    </div>
                @else
                    <p class="text-[11px] text-stone-300 text-center py-10">belum ada</p>
                @endif
            </div>
        </div>

        {{-- Rincian per channel --}}
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="text-stone-400 uppercase text-[10px]">
                    <tr class="border-b border-stone-100">
                        <th class="text-left py-2">Channel</th>
                        <th class="text-right">Terealisasi</th>
                        <th class="text-right">Masih Berjalan</th>
                        <th class="text-right">Estimasi</th>
                        <th class="text-right">Batal</th>
                        <th class="text-right">Blm Bayar</th>
                        <th class="text-right w-24">Porsi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($channelSales as $ch)
                        @php
                            $est = $ch['confirmed'] + $ch['pipeline'];
                            $pct = $estimasi > 0 ? round($est / $estimasi * 100, 1) : 0;
                        @endphp
                        <tr class="border-b border-stone-50">
                            <td class="py-2">
                                <span class="flex items-center gap-2 font-semibold text-stone-700">
                                    <span class="w-2.5 h-2.5 rounded-full inline-block" style="background:{{ $ch['color'] }}"></span>
                                    {{ $ch['label'] }}
                                </span>
                            </td>
                            <td class="text-right text-emerald-700">{{ $ch['confirmed'] ? $rp($ch['confirmed']) : '·' }}</td>
                            <td class="text-right text-amber-700">{{ $ch['pipeline'] ? $rp($ch['pipeline']) : '·' }}</td>
                            <td class="text-right font-bold text-stone-800">{{ $est ? $rp($est) : '·' }}</td>
                            <td class="text-right text-rose-600">
                                @if($ch['cancelled_n'])
                                    {{ $rp($ch['cancelled']) }}
                                    <span class="block text-[10px] text-rose-400">{{ $ch['cancelled_n'] }} order · {{ $ch['cancel_rate'] }}%</span>
                                @else
                                    <span class="text-stone-300">·</span>
                                @endif
                            </td>
                            <td class="text-right text-stone-500">
                                @if($ch['unpaid_n'])
                                    {{ $rp($ch['unpaid']) }}
                                    <span class="block text-[10px] text-stone-400">{{ $ch['unpaid_n'] }} order</span>
                                @else
                                    <span class="text-stone-300">·</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <span class="text-stone-500">{{ $pct }}%</span>
                                <div class="h-1 rounded-full bg-stone-100 overflow-hidden mt-1">
                                    <div class="h-full rounded-full" style="width:{{ $pct }}%; background:{{ $ch['color'] }}"></div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($estimasi == 0)
            <p class="text-[11px] text-stone-400 pt-3">Belum ada order bulan ini.</p>
        @else
            <p class="text-[11px] text-stone-400 pt-3">
                ℹ️ Order <b>belum dibayar</b> &amp; <b>batal</b> tidak dihitung — belum tentu jadi uang, biar estimasi tidak menggelembung.
            </p>
        @endif
    </div>
    {{-- Data untuk re-render chart saat filter via AJAX (dibaca renderChannelCharts). --}}
    <script type="application/json" id="channelData">{!! json_encode($channelSales) !!}</script>
@endif
