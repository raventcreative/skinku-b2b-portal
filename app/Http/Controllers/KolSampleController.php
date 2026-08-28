<?php

namespace App\Http\Controllers;

use App\Models\KolDeal;
use App\Models\KolSample;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sampel produk untuk deal KOL: catat kirim (pending/shipped/received),
 * ubah status, hapus. Opsi "tambah HPP ke biaya deal" (units × unit_cost →
 * total_biaya) hanya berlaku untuk pemegang kol.deal.finance.
 */
class KolSampleController extends Controller
{
    public function store(Request $request, KolDeal $deal): RedirectResponse
    {
        $data = $request->validate([
            'product' => ['required', 'string', 'max:255'],
            'units' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['nullable', 'integer', 'min:0'],
            'courier' => ['nullable', 'string', 'max:100'],
            'tracking_no' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(KolSample::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'add_to_deal' => ['nullable', 'boolean'],
        ]);

        $today = now()->toDateString();
        $sample = KolSample::create([
            'kol_deal_id' => $deal->id, 'kol_id' => $deal->kol_id,
            'product' => $data['product'], 'units' => $data['units'], 'unit_cost' => $data['unit_cost'] ?? 0,
            'courier' => $data['courier'] ?? null, 'tracking_no' => $data['tracking_no'] ?? null,
            'status' => $data['status'],
            'shipped_at' => $data['status'] === 'pending' ? null : $today,
            'received_at' => $data['status'] === 'received' ? $today : null,
            'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->id,
        ]);

        // Tambah HPP ke biaya deal (finance-only).
        $msg = 'Sampel dicatat.';
        if (! empty($data['add_to_deal']) && $sample->subtotal > 0 && $request->user()->canDo('kol.deal.finance')) {
            $deal->increment('total_biaya', $sample->subtotal);
            $msg = 'Sampel dicatat — HPP Rp '.number_format($sample->subtotal, 0, ',', '.').' ditambahkan ke biaya deal.';
        }

        AuditService::log(action: 'create_kol_sample', targetType: 'kol_sample', targetId: $sample->id,
            after: ['deal' => $deal->kode, 'product' => $sample->product, 'subtotal' => $sample->subtotal]);

        return redirect()->route('kol-deals.edit', $deal)->with('status', $msg);
    }

    /** Ubah status kirim; shipped_at/received_at menyesuaikan status. */
    public function updateStatus(Request $request, KolSample $sample): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(KolSample::STATUSES)],
            'tracking_no' => ['nullable', 'string', 'max:100'],
        ]);

        $today = now()->toDateString();
        $set = ['status' => $data['status']];
        if ($data['status'] === 'pending') {
            $set['shipped_at'] = null;
            $set['received_at'] = null;
        } elseif ($data['status'] === 'shipped') {
            $set['shipped_at'] = optional($sample->shipped_at)->toDateString() ?? $today;
            $set['received_at'] = null;
        } else { // received
            $set['shipped_at'] = optional($sample->shipped_at)->toDateString() ?? $today;
            $set['received_at'] = optional($sample->received_at)->toDateString() ?? $today;
        }
        if (! empty($data['tracking_no'])) {
            $set['tracking_no'] = $data['tracking_no'];
        }
        $sample->update($set);

        return redirect()->route('kol-deals.edit', $sample->kol_deal_id)->with('status', 'Status sampel diperbarui.');
    }

    public function destroy(KolSample $sample): RedirectResponse
    {
        $dealId = $sample->kol_deal_id;
        AuditService::log(action: 'delete_kol_sample', targetType: 'kol_sample', targetId: $sample->id,
            before: ['product' => $sample->product, 'subtotal' => $sample->subtotal]);
        $sample->delete();

        return redirect()->route('kol-deals.edit', $dealId)->with('status', 'Sampel dihapus.');
    }
}
