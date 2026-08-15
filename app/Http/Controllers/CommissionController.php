<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use App\Models\Withdrawal;
use App\Services\CommissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Saldo Komisi" — halaman mitra: lihat saldo tersedia + riwayat komisi, dan
 * ajukan penarikan. Commissions APPEND-ONLY: penarikan tidak pernah mengubah
 * Commission.status, hanya menambah baris Withdrawal yang mengunci saldo
 * (lihat CommissionService::availableBalance).
 */
class CommissionController extends Controller
{
    public function __construct(private CommissionService $commissions) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isPartner(), 403, 'Hanya mitra yang memiliki saldo komisi.');

        $riwayatKomisi = Commission::where('user_id', $user->id)
            ->latest()->limit(30)->get();
        $riwayatPenarikan = Withdrawal::where('user_id', $user->id)
            ->latest()->limit(30)->get();

        return view('commissions.index', [
            'user' => $user,
            'balance' => $this->commissions->balance($user),
            'available' => $this->commissions->availableBalance($user),
            'riwayatKomisi' => $riwayatKomisi,
            'riwayatPenarikan' => $riwayatPenarikan,
        ]);
    }

    public function withdraw(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isPartner(), 403, 'Hanya mitra yang memiliki saldo komisi.');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:100000'],
        ]);
        $amount = (float) $data['amount'];

        if (! $user->no_rekening) {
            return back()->with('error', 'Isi rekening dulu di menu Rekening.');
        }

        if ($amount > $this->commissions->availableBalance($user)) {
            return back()->with('error', 'Saldo tersedia tidak cukup untuk pengajuan ini.');
        }

        Withdrawal::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'bank' => $user->bank,
            'no_rekening' => $user->no_rekening,
            'atas_nama' => $user->atas_nama,
            'status' => 'diajukan',
            'requested_at' => now(),
        ]);

        return redirect()->route('commissions.index')
            ->with('status', 'Pengajuan penarikan terkirim, menunggu diproses HQ.');
    }

    /** Batalkan pengajuan sendiri selama masih 'diajukan' (lepas kunci saldo). */
    public function cancel(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isPartner(), 403, 'Hanya mitra yang memiliki saldo komisi.');
        abort_unless($withdrawal->user_id === $user->id, 403, 'Bukan pengajuan Anda.');

        if ($withdrawal->status !== 'diajukan') {
            return back()->with('error', 'Pengajuan ini sudah diproses, tak bisa dibatalkan.');
        }

        $withdrawal->update(['status' => 'ditolak', 'note' => 'dibatalkan mitra']);

        return back()->with('status', 'Pengajuan penarikan dibatalkan.');
    }
}
