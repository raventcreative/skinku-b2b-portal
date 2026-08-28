<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Kol;
use App\Models\KolCampaign;
use App\Models\KolDeal;
use App\Models\User;
use App\Services\AuditService;
use App\Services\KolBudgetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KolDealController extends Controller
{
    public function index(Request $request, KolBudgetService $budget)
    {
        $deals = KolDeal::query()
            ->with(['kol.latestScreening', 'pic', 'campaign'])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('kol_deals.index', [
            'deals' => $deals,
            // Panel budget hanya untuk pemegang finance (agregat uang).
            'budget' => $request->user()->canDo('kol.deal.finance') ? $budget->summary(now()) : null,
        ]);
    }

    /** Simpan cap budget bulanan + CPM anchor (finance-sensitive). */
    public function saveBudget(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'budget' => ['required', 'integer', 'min:0'],
            'anchor' => ['required', 'integer', 'min:0'],
        ]);
        AppSetting::put(KolBudgetService::KEY_BUDGET, (string) $data['budget']);
        AppSetting::put(KolBudgetService::KEY_ANCHOR, (string) $data['anchor']);

        return back()->with('status', 'Budget & CPM anchor disimpan.');
    }

    /**
     * Acc/Tolak/Selesai cepat — satu atau banyak deal sekaligus (pola PO
     * bulkStatus). Set status langsung ke pilihan (4 status datar, tanpa aturan
     * transisi rumit). Gated kol.deal.manage lewat route.
     */
    public function bulkStatus(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'status' => ['required', Rule::in(KolDeal::STATUSES)],
        ]);
        $this->guardApprovalStatus($data['status'], $request->user());

        $deals = KolDeal::whereIn('id', $data['ids'])->get();
        $diubah = 0;
        foreach ($deals as $deal) {
            if ($deal->status === $data['status']) {
                continue;
            }
            $deal->update(['status' => $data['status']]);
            $diubah++;
            AuditService::log(
                action: 'update_kol_deal_status',
                targetType: 'kol_deal',
                targetId: $deal->id,
                after: ['kode' => $deal->kode, 'status' => $data['status'], 'via' => 'massal'],
            );
        }

        return back()->with('status', "{$diubah} deal diubah ke status \"{$data['status']}\".");
    }

    /**
     * Ringkasan hasil endorse SEMUA deal yang sudah punya laporan — buat
     * membandingkan performa dalam satu layar (mirip sheet "KOL Overview").
     * Urut: verdict terbaik dulu, lalu terbaru. Baris total di view.
     */
    public function laporan(Request $request)
    {
        $tujuan = $request->query('tujuan');
        $status = $request->query('status');

        $deals = KolDeal::query()
            ->with('kol')
            ->whereNotNull('hasil_diisi_at')
            ->when($tujuan, fn ($q, $v) => $q->where('hasil_tujuan', $v))
            ->when($status, fn ($q, $v) => $q->where('status', $v))
            ->get();

        $rank = [KolDeal::VERDICT_BAGUS => 3, KolDeal::VERDICT_CUKUP => 2, KolDeal::VERDICT_JELEK => 1, KolDeal::VERDICT_BELUM => 0];
        $deals = $deals->sortByDesc(
            fn (KolDeal $d) => ($rank[$d->hasil_verdict] ?? 0) * 100_000_000_000 + (optional($d->hasil_diisi_at)->timestamp ?? 0)
        )->values();

        $totBiaya = (int) $deals->sum('total_biaya');
        $totViews = (int) $deals->sum('hasil_views');
        $totRevenue = (int) $deals->sum('hasil_revenue');

        return view('kol_deals.laporan', [
            'deals' => $deals,
            'tujuan' => $tujuan,
            'status' => $status,
            'totals' => [
                'biaya' => $totBiaya,
                'views' => $totViews,
                'revenue' => $totRevenue,
                'video_upload' => (int) $deals->sum('hasil_video_upload'),
                'video_fyp' => (int) $deals->sum('hasil_video_fyp'),
                'cpm' => $totViews > 0 ? (int) round($totBiaya / $totViews * 1000) : null,
                'romi' => $totBiaya > 0 ? round($totRevenue / $totBiaya, 2) : null,
            ],
        ]);
    }

    public function create(Request $request)
    {
        return view('kol_deals.form', $this->formData(new KolDeal, (int) $request->query('kol', 0)));
    }

    /**
     * Data form (create & edit). kolMap dibangun DI SINI (PHP), bukan lewat
     * array-literal di Blade echo — literal [...] di dalam {!! !!} bikin compile
     * error/500. Dipakai JS untuk memetakan "@username" ke kol_id.
     */
    private function formData(KolDeal $deal, int $selectedKolId): array
    {
        $kols = Kol::orderBy('tiktok_username')->get(['id', 'tiktok_username', 'phone']);

        return [
            'deal' => $deal,
            'kols' => $kols,
            // Peta "@username" -> {id, phone, wa} — JS pakai id untuk kol_id &
            // menampilkan No. HP KOL terpilih (kontak dealing).
            'kolMap' => $kols->mapWithKeys(fn ($k) => ['@'.$k->tiktok_username => [
                'id' => $k->id, 'phone' => $k->phone, 'wa' => $k->whatsappUrl(),
            ]])->all(),
            'pics' => User::whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, 'kol_specialist'])
                ->where('status', User::STATUS_ACTIVE)->orderBy('fullname')->get(['id', 'fullname']),
            'campaigns' => KolCampaign::orderBy('name')->get(['id', 'name']),
            'selectedKolId' => $selectedKolId,
            'sampleHppDefault' => (int) AppSetting::get('kol_sample_hpp', '0'),
            'cpmAnchor' => (int) AppSetting::get(KolBudgetService::KEY_ANCHOR, '5000'),
            'sampleSubtotal' => (int) $deal->samples->sum(fn ($s) => $s->subtotal),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $kolPhone = $data['kol_phone'] ?? null;
        unset($data['kol_phone']);   // bukan kolom deal
        $data['kode'] = KolDeal::generateKode();

        $deal = KolDeal::create($data);
        $this->syncKolPhone($deal->kol_id, $kolPhone);

        AuditService::log(
            action: 'create_kol_deal',
            targetType: 'kol_deal',
            targetId: $deal->id,
            after: ['kode' => $deal->kode, 'kol' => $deal->kol->tiktok_username, 'jenis' => $deal->jenis],
        );

        // Deal dari modal cepat di tabel KOL → balik ke Database KOL (tak pindah halaman).
        $redirect = $request->boolean('dari_kol')
            ? redirect()->route('kols.index')
            : redirect()->route('kol-deals.index');

        return $redirect->with('status', "Deal {$deal->kode} dibuat.");
    }

    /**
     * Simpan HANYA laporan hasil endorse (dari modal di daftar deal) — tanpa
     * validasi field deal lain. AJAX -> JSON {verdict} biar baris ter-update
     * tanpa reload; non-AJAX -> redirect balik (tetap jalan tanpa JS).
     */
    public function saveHasil(Request $request, KolDeal $deal)
    {
        $data = $request->validate([
            'hasil_tujuan' => ['nullable', Rule::in(KolDeal::TUJUAN)],
            'hasil_video_upload' => ['nullable', 'integer', 'min:0'],
            'hasil_video_fyp' => ['nullable', 'integer', 'min:0'],
            'hasil_views' => ['nullable', 'integer', 'min:0'],
            'hasil_revenue' => ['nullable', 'integer', 'min:0'],
            'hasil_catatan' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['hasil_diisi_at'] = now();
        $deal->update($data);

        AuditService::log(
            action: 'update_kol_deal_hasil',
            targetType: 'kol_deal',
            targetId: $deal->id,
            after: ['kode' => $deal->kode, 'verdict' => $deal->hasil_verdict, 'via' => 'modal'],
        );

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['ok' => true, 'verdict' => $deal->hasil_verdict]);
        }

        return back()->with('status', "Laporan hasil {$deal->kode} disimpan (verdict: {$deal->hasil_verdict}).");
    }

    public function edit(KolDeal $deal)
    {
        return view('kol_deals.form', $this->formData($deal, $deal->kol_id));
    }

    public function update(Request $request, KolDeal $deal): RedirectResponse
    {
        $data = $this->validated($request);
        $this->guardApprovalStatus($data['status'] ?? null, $request->user(), $deal->status);
        $kolPhone = $data['kol_phone'] ?? null;
        unset($data['kol_phone']);

        $before = $deal->only(array_keys($data));
        $deal->update($data);
        $this->syncKolPhone($deal->kol_id, $kolPhone);

        // Nilai finansial TIDAK ditulis ke log — nomor rekening tak boleh mengendap
        // di audit trail. Cukup nama field yang berubah + penanda "rekening diubah".
        $changed = array_keys(array_diff_assoc(
            array_map(fn ($v) => (string) $v, $data),
            array_map(fn ($v) => (string) $v, $before),
        ));
        $financeChanged = array_values(array_intersect($changed, KolDeal::FINANCE_FIELDS));
        $plainChanged = array_values(array_diff($changed, KolDeal::FINANCE_FIELDS));

        AuditService::log(
            action: 'update_kol_deal',
            targetType: 'kol_deal',
            targetId: $deal->id,
            after: array_filter([
                'kode' => $deal->kode,
                'berubah' => $plainChanged,
                'finansial_berubah' => $financeChanged ? array_map(
                    fn ($f) => $f === 'no_rekening' ? 'rekening diubah' : $f,
                    $financeChanged,
                ) : null,
            ]),
        );

        return redirect()->route('kol-deals.index')->with('status', "Deal {$deal->kode} diperbarui.");
    }

    public function destroy(Request $request, KolDeal $deal): RedirectResponse
    {
        // Keputusan Freddie: hapus deal cukup kol.deal.manage (gerbang route),
        // tidak dibatasi super admin. Soft delete + tercatat siapa & apa.
        $kode = $deal->kode;
        $deal->delete();

        AuditService::log(
            action: 'delete_kol_deal',
            targetType: 'kol_deal',
            targetId: $deal->id,
            before: ['kode' => $kode, 'kol' => $deal->kol->tiktok_username, 'status' => $deal->status],
        );

        return redirect()->route('kol-deals.index')->with('status', "Deal {$kode} dihapus (soft delete).");
    }

    /**
     * Simpan No. HP yang diisi di form deal ke data KOL (satu sumber kebenaran).
     * Hanya bila diisi — kosong TIDAK menghapus nomor lama (hindari terwipe tak
     * sengaja; penghapusan lewat Edit profil KOL).
     */
    private function syncKolPhone(int $kolId, ?string $phone): void
    {
        if (filled($phone)) {
            Kol::whereKey($kolId)->update(['phone' => $phone]);
        }
    }

    /**
     * Acc (berjalan) & Tolak (batal) = wewenang PENYETUJU, terpisah dari
     * pengaju. Pengaju (kol.deal.manage tanpa approve) tak boleh mengubah status
     * ke berjalan/batal di jalur mana pun (tombol, massal, atau form Edit).
     * "Selesai" & "draft" tetap boleh pengaju (operasional).
     */
    private function guardApprovalStatus(?string $status, User $user, ?string $current = null): void
    {
        if ($status === $current) {
            return; // status tak berubah — boleh (mis. edit field lain saat sudah berjalan)
        }
        if (in_array($status, ['berjalan', 'batal'], true) && ! $user->canDo('kol.deal.approve')) {
            abort(403, 'Hanya penyetuju (izin kol.deal.approve) yang bisa Acc/Tolak deal KOL.');
        }
    }

    /**
     * Validasi + gerbang finansial. Tanpa kol.deal.finance, field finansial
     * DIBUANG dari input tervalidasi — bukan disabled di form saja. Form bisa
     * dilewati dengan POST langsung; menyembunyikan input di HTML bukan
     * pengamanan.
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kol_id' => ['required', 'integer', 'exists:kols,id'],
            'kol_campaign_id' => ['nullable', 'integer', 'exists:kol_campaigns,id'],
            'jenis' => ['required', Rule::in(KolDeal::JENIS)],
            'deal_type' => ['nullable', Rule::in(KolDeal::DEAL_TYPES)],
            'ratecard_deal' => ['required', 'integer', 'min:0'],
            'jumlah_slot' => ['required', 'integer', 'min:1'],
            'periode_mulai' => ['nullable', 'date'],
            'periode_selesai' => ['nullable', 'date', 'after_or_equal:periode_mulai'],
            'pic_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'link_mou' => ['nullable', 'url', 'max:255'],
            'status' => ['required', Rule::in(KolDeal::STATUSES)],
            // Deliverables & jadwal (operasional, non-finansial).
            'deliverables' => ['nullable', 'string', 'max:2000'],
            'posting_deadline' => ['nullable', 'date'],
            'usage_rights' => ['nullable', 'string', 'max:255'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            // Finansial — hanya berlaku bila lolos gerbang di bawah.
            'total_biaya' => ['nullable', 'integer', 'min:0'],
            'other_cost' => ['nullable', 'integer', 'min:0'],
            'status_bayar' => ['nullable', Rule::in(KolDeal::STATUS_BAYAR)],
            'dp_percent' => ['nullable', 'integer', 'min:0', 'max:99'],
            'no_rekening' => ['nullable', 'string', 'max:50'],
            'bank' => ['nullable', 'string', 'max:100'],
            'atas_nama' => ['nullable', 'string', 'max:150'],
            'payment_note' => ['nullable', 'string', 'max:255'],
            // Laporan hasil endorse (evaluasi kinerja). CPM/ROMI/verdict dihitung di model.
            'hasil_tujuan' => ['nullable', Rule::in(KolDeal::TUJUAN)],
            'hasil_video_upload' => ['nullable', 'integer', 'min:0'],
            'hasil_video_fyp' => ['nullable', 'integer', 'min:0'],
            'hasil_views' => ['nullable', 'integer', 'min:0'],
            'hasil_revenue' => ['nullable', 'integer', 'min:0'],
            'hasil_catatan' => ['nullable', 'string', 'max:2000'],
            // Bukan kolom deal — No. HP KOL yang diisi di form deal, disimpan balik
            // ke data KOL (bukan finansial, jadi tak ikut gerbang finance).
            'kol_phone' => ['nullable', 'string', 'max:30'],
        ]);

        if (! $request->user()->canDo('kol.deal.finance')) {
            foreach (KolDeal::FINANCE_FIELDS as $field) {
                unset($data[$field]);
            }
        }

        // Kolom NOT NULL berdefault: input kosong jadi null (ConvertEmptyStringsToNull),
        // dan mengirim NULL ke kolom NOT NULL = 500. Pakai default kolomnya.
        if (array_key_exists('total_biaya', $data)) {
            $data['total_biaya'] ??= 0;
        }
        if (array_key_exists('other_cost', $data)) {
            $data['other_cost'] ??= 0;
        }
        if (array_key_exists('dp_percent', $data)) {
            $data['dp_percent'] ??= 0;
        }
        if (array_key_exists('status_bayar', $data)) {
            $data['status_bayar'] ??= 'belum';
        }
        if (array_key_exists('deal_type', $data)) {
            $data['deal_type'] ??= 'paid';
        }

        // Laporan hasil: tandai waktu diisi bila ada isian (tujuan/metrik).
        $hasilTerisi = filled($data['hasil_tujuan'] ?? null) || filled($data['hasil_views'] ?? null)
            || filled($data['hasil_revenue'] ?? null) || filled($data['hasil_video_upload'] ?? null);
        if ($hasilTerisi) {
            $data['hasil_diisi_at'] = now();
        }

        return $data;
    }
}
