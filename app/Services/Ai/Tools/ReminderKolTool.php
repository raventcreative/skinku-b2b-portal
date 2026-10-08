<?php

namespace App\Services\Ai\Tools;

use App\Models\KolDeal;
use App\Models\KolPipelineCard;
use App\Models\KolSample;
use App\Models\User;
use App\Services\KolReminderService;

/**
 * Alat BACA: Reminder KOL (menu KOL → Reminder), izin kol.view sama dgn halamannya. Daftar dari KolReminderService
 * (sumber yang sama dgn halaman), bagian per izin: sampel tertahan = kol.deal.manage, affiliate berhenti posting =
 * kol.affiliate.view, tagihan deal = kol.deal.finance. Nomor resi, catatan & rekening tidak dikirim.
 */
class ReminderKolTool extends BaseTool
{
    public function __construct(private KolReminderService $svc) {}

    public function name(): string
    {
        return 'reminder_kol';
    }

    public function permission(): ?string
    {
        return 'kol.view';
    }

    public function description(): string
    {
        return 'Daftar pengingat KOL hari ini (menu KOL → Reminder): kartu pipeline yang next action-nya terlambat, hari '
            .'ini, besok (H-1), atau belum punya next action; deal berjalan yang tenggat posting ≤3 hari & belum ada '
            .'konten; plus (sesuai izin) sampel produk tertahan, affiliate berhenti posting ≥2 minggu, dan tagihan deal '
            .'belum lunas. Pakai untuk "apa yang harus di-follow up hari ini / siapa yang telat / tugas KOL saya".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Jumlah baris per bagian, 1-30. Default 15.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $r = $this->svc->untuk($user);
        $today = $r['today'];
        $limit = max(1, min(30, (int) ($args['limit'] ?? 15)));
        $kartu = $r['late']->concat($r['due'])->concat($r['besok'])->concat($r['none']);

        $out = [
            'hari_ini' => $today->toDateString(),
            'pipeline' => [
                'terlambat' => $r['late']->count(),
                'hari_ini' => $r['due']->count(),
                'besok' => $r['besok']->count(),
                'tanpa_next_action' => $r['none']->count(),
                // Urutan sama dgn halaman: terlambat → hari ini → besok → tanpa next action.
                'daftar' => $kartu->take($limit)->map(fn (KolPipelineCard $c) => array_filter([
                    'kategori' => match (true) {
                        ! $c->next_action_at => 'tanpa next action',
                        $c->next_action_at->lt($today) => 'terlambat',
                        $c->next_action_at->isSameDay($today) => 'hari ini',
                        default => 'besok',
                    },
                    'username' => $c->kol ? '@'.$c->kol->handle() : '?',
                    'papan' => $c->track === KolPipelineCard::TRACK_AFFILIATE ? 'Affiliate' : 'KOL',
                    'tahap' => $c->stageLabel(),
                    'next_action' => $c->next_action ? mb_substr($c->next_action, 0, 100) : null,
                    'tanggal' => $c->next_action_at?->toDateString(),
                    'terlambat_hari' => $c->next_action_at?->lt($today) ? (int) $c->next_action_at->diffInDays($today) : null,
                ], fn ($v) => $v !== null))->values()->all(),
            ],
            'deadline_posting' => [
                'jumlah' => $r['postingDue']->count(),
                'daftar' => $r['postingDue']->take($limit)->map(fn (KolDeal $d) => [
                    'kode' => $d->kode,
                    'kreator' => $d->kol ? '@'.$d->kol->handle() : '?',
                    'jenis' => strtoupper((string) $d->jenis),
                    'tenggat' => $d->posting_deadline_effective?->toDateString(),
                    'lewat_tenggat' => (bool) $d->posting_deadline_effective?->lt($today),
                ])->values()->all(),
            ],
        ];

        $tanpa = [];
        if ($user->canDo('kol.deal.manage')) {
            $out['sampel_tertahan'] = [
                'jumlah' => $r['stuckSamples']->count(),
                'daftar' => $r['stuckSamples']->take($limit)->map(function (KolSample $s) {
                    $pending = $s->status === 'pending';

                    return array_filter([
                        'produk' => $s->product,
                        'kreator' => $s->kol ? '@'.$s->kol->handle() : '?',
                        'deal' => $s->deal?->kode,
                        'status' => $pending ? 'belum dikirim' : 'dikirim, belum diterima',
                        'hari' => (int) ($pending ? $s->created_at : $s->shipped_at)?->diffInDays(now()),
                    ], fn ($v) => $v !== null);
                })->values()->all(),
            ];
        } else {
            $tanpa[] = 'sampel tertahan (izin "Kelola Deal KOL")';
        }
        if ($user->canDo('kol.affiliate.view')) {
            $out['affiliate_berhenti_posting'] = [
                'keterangan' => 'Ada pesanan affiliate 30 hari terakhir tapi tak ada konten 14 hari terakhir.',
                'jumlah' => $r['churn']->count(),
                'kreator' => $r['churn']->take($limit)->map(fn ($k) => '@'.$k->handle())->values()->all(),
            ];
        } else {
            $tanpa[] = 'affiliate berhenti posting (izin "Lihat Affiliate & GMV")';
        }
        if ($user->canDo('kol.deal.finance')) {
            $out['tagihan_belum_lunas'] = [
                'jumlah' => $r['payments']->count(),
                'total_sisa' => (int) $r['payments']->sum(fn (KolDeal $d) => $d->remainingUnpaid()),
                'daftar' => $r['payments']->take($limit)->map(fn (KolDeal $d) => [
                    'kode' => $d->kode,
                    'kreator' => $d->kol ? '@'.$d->kol->handle() : '?',
                    'status_bayar' => $d->status_bayar,
                    'total_biaya' => $d->total_biaya,
                    'sisa_tagihan' => $d->remainingUnpaid(),
                    'tenggat' => $d->periode_selesai?->toDateString(),
                    'lewat_tenggat' => (bool) $d->periode_selesai?->lt($today),
                ])->values()->all(),
            ];
        } else {
            $tanpa[] = 'tagihan deal belum lunas (izin "Finansial Deal KOL")';
        }
        if ($tanpa) {
            $out['catatan_akses'] = 'Tidak ditampilkan sesuai hak akses user: '.implode('; ', $tanpa).'.';
        }

        return $out;
    }
}
