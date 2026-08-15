<?php

namespace App\Http\Controllers;

use App\Models\Withdrawal;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HQ memproses antrean penarikan komisi mitra (pola KolDealController):
 * index = daftar/filter, process = setujui/tolak/cairkan satu withdrawal.
 * Gated izin process_withdrawal lewat route. Menolak ('ditolak') otomatis
 * melepas kunci saldo mitra — CommissionService::availableBalance
 * mengecualikan withdrawal berstatus 'ditolak', jadi TIDAK perlu menyentuh
 * Commission (append-only, tak pernah diflip).
 */
class WithdrawalController extends Controller
{
    public function index(Request $request)
    {
        $withdrawals = Withdrawal::query()
            ->with('mitra')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('withdrawals.index', ['withdrawals' => $withdrawals]);
    }

    /**
     * Transisi valid: diajukan→disetujui/ditolak; disetujui→cair/ditolak.
     * Longgar di luar itu, TAPI 'cair' selalu final — tak boleh diproses lagi
     * (dana sudah keluar sungguhan).
     */
    public function process(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['disetujui', 'ditolak', 'cair'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($withdrawal->status === 'cair') {
            return back()->with('error', "Penarikan #{$withdrawal->id} sudah cair — tidak bisa diproses ulang.");
        }

        $before = ['status' => $withdrawal->status];
        $note = $data['note'] ?? null;

        $withdrawal->update([
            'status' => $data['status'],
            'processed_by' => $request->user()->id,
            'processed_at' => now(),
            'note' => filled($note) ? $note : $withdrawal->note,
        ]);

        AuditService::log(
            action: 'process_withdrawal',
            targetType: 'withdrawal',
            targetId: $withdrawal->id,
            before: $before,
            after: ['status' => $withdrawal->status, 'mitra_id' => $withdrawal->user_id, 'amount' => (string) $withdrawal->amount],
        );

        return back()->with('status', "Penarikan #{$withdrawal->id} diubah ke status \"{$data['status']}\".");
    }
}
