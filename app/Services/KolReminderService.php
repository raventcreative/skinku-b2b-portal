<?php

namespace App\Services;

use App\Models\Kol;
use App\Models\KolAffiliateTransaction;
use App\Models\KolDeal;
use App\Models\KolPipelineCard;
use App\Models\KolSample;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Daftar Reminder KOL — satu sumber untuk halaman KOL → Reminder dan alat AI reminder_kol: kartu pipeline
 * (terlambat → hari ini → besok → tanpa next action), deadline posting deal, sampel tertahan, affiliate berhenti
 * posting, tagihan deal belum lunas. Bagian yang butuh izin lain jadi koleksi kosong bila user tak punya izinnya.
 */
class KolReminderService
{
    public function __construct(private KolBudgetService $budget) {}

    /**
     * @return array{today:Carbon,late:Collection,due:Collection,besok:Collection,none:Collection,
     *     postingDue:Collection,stuckSamples:Collection,churn:Collection,payments:Collection}
     */
    public function untuk(User $u): array
    {
        $today = now()->startOfDay();
        $besokDate = $today->copy()->addDay();
        $cards = KolPipelineCard::active()->with('kol')->get();

        return [
            'today' => $today,
            'late' => $cards->filter(fn ($c) => $c->next_action_at?->lt($today))->sortBy('next_action_at')->values(),
            'due' => $cards->filter(fn ($c) => $c->next_action_at?->isSameDay($today))->values(),
            // Lead time H-1: next action besok — supaya bisa disiapkan dari sekarang.
            'besok' => $cards->filter(fn ($c) => $c->next_action_at?->isSameDay($besokDate))->values(),
            'none' => $cards->filter(fn ($c) => ! $c->next_action_at)->values(),
            'postingDue' => $this->postingDue(),
            // Sampel tertahan — pengurus deal saja.
            'stuckSamples' => $u->canDo('kol.deal.manage') ? $this->stuckSamples() : collect(),
            // Affiliate berhenti posting — butuh angka affiliate.
            'churn' => $u->canDo('kol.affiliate.view') ? $this->churn() : collect(),
            // Tagihan deal belum lunas — finance only (uang).
            'payments' => $u->canDo('kol.deal.finance') ? $this->budget->unpaid() : collect(),
        ];
    }

    /**
     * Deadline posting: deal berjalan yang tenggatnya ≤ 3 hari lagi & belum ada konten. Tenggat = deadline posting
     * khusus bila diisi, jika tidak periode_selesai.
     */
    private function postingDue(): Collection
    {
        return KolDeal::with('kol')->where('status', 'berjalan')
            ->where(fn ($q) => $q->whereNotNull('posting_deadline')->orWhereNotNull('periode_selesai'))
            ->whereRaw('date(COALESCE(posting_deadline, periode_selesai)) <= ?', [now()->addDays(3)->toDateString()])
            ->whereDoesntHave('contents')
            ->orderByRaw('COALESCE(posting_deadline, periode_selesai) asc')->get();
    }

    /** Sampel tertahan: pending ≥ 3 hari, atau dikirim ≥ 7 hari belum diterima. */
    private function stuckSamples(): Collection
    {
        return KolSample::with(['kol', 'deal'])
            ->where(function ($q) {
                $q->where(fn ($w) => $w->where('status', 'pending')->where('created_at', '<=', now()->subDays(3)))
                    ->orWhere(fn ($w) => $w->where('status', 'shipped')->whereDate('shipped_at', '<=', now()->subDays(7)));
            })
            ->orderBy('created_at')->get();
    }

    /** Affiliate berhenti posting: punya order affiliate 30 hari terakhir tapi tak ada konten dalam 14 hari terakhir. */
    private function churn(): Collection
    {
        $activeIds = KolAffiliateTransaction::matched()->notCancelled()
            ->where('order_date', '>=', now()->subDays(30))->distinct()->pluck('kol_id');

        return Kol::whereIn('id', $activeIds)
            ->whereDoesntHave('contents', fn ($q) => $q->where('posted_at', '>=', now()->subDays(14)))
            ->orderBy('tiktok_username')->get(['id', 'tiktok_username']);
    }
}
