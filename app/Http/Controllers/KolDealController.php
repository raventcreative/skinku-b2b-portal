<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\KolDeal;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KolDealController extends Controller
{
    public function index(Request $request)
    {
        $deals = KolDeal::query()
            ->with(['kol', 'pic'])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('kol_deals.index', ['deals' => $deals]);
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
        $kols = Kol::orderBy('tiktok_username')->get(['id', 'tiktok_username']);

        return [
            'deal' => $deal,
            'kols' => $kols,
            'kolMap' => $kols->mapWithKeys(fn ($k) => ['@'.$k->tiktok_username => $k->id])->all(),
            'pics' => User::whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, 'kol_specialist'])
                ->where('status', User::STATUS_ACTIVE)->orderBy('fullname')->get(['id', 'fullname']),
            'selectedKolId' => $selectedKolId,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['kode'] = KolDeal::generateKode();

        $deal = KolDeal::create($data);

        AuditService::log(
            action: 'create_kol_deal',
            targetType: 'kol_deal',
            targetId: $deal->id,
            after: ['kode' => $deal->kode, 'kol' => $deal->kol->tiktok_username, 'jenis' => $deal->jenis],
        );

        return redirect()->route('kol-deals.index')->with('status', "Deal {$deal->kode} dibuat.");
    }

    public function edit(KolDeal $deal)
    {
        return view('kol_deals.form', $this->formData($deal, $deal->kol_id));
    }

    public function update(Request $request, KolDeal $deal): RedirectResponse
    {
        $data = $this->validated($request);

        $before = $deal->only(array_keys($data));
        $deal->update($data);

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
     * Validasi + gerbang finansial. Tanpa kol.deal.finance, field finansial
     * DIBUANG dari input tervalidasi — bukan disabled di form saja. Form bisa
     * dilewati dengan POST langsung; menyembunyikan input di HTML bukan
     * pengamanan.
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kol_id' => ['required', 'integer', 'exists:kols,id'],
            'jenis' => ['required', Rule::in(KolDeal::JENIS)],
            'ratecard_deal' => ['required', 'integer', 'min:0'],
            'jumlah_slot' => ['required', 'integer', 'min:1'],
            'periode_mulai' => ['nullable', 'date'],
            'periode_selesai' => ['nullable', 'date', 'after_or_equal:periode_mulai'],
            'pic_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'link_mou' => ['nullable', 'url', 'max:255'],
            'status' => ['required', Rule::in(KolDeal::STATUSES)],
            // Finansial — hanya berlaku bila lolos gerbang di bawah.
            'total_biaya' => ['nullable', 'integer', 'min:0'],
            'status_bayar' => ['nullable', Rule::in(KolDeal::STATUS_BAYAR)],
            'no_rekening' => ['nullable', 'string', 'max:50'],
            'bank' => ['nullable', 'string', 'max:100'],
            'atas_nama' => ['nullable', 'string', 'max:150'],
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
        if (array_key_exists('status_bayar', $data)) {
            $data['status_bayar'] ??= 'belum';
        }

        return $data;
    }
}
