<?php

namespace App\Http\Controllers;

use App\Models\KolCampaign;
use App\Models\KolContent;
use App\Models\KolDeal;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Campaign KOL: payung beberapa deal. Menampilkan rollup — jumlah deal, total
 * biaya, views tercapai (dari konten deal tertaut) + % vs target views/GMV.
 */
class KolCampaignController extends Controller
{
    public function index(Request $request)
    {
        $campaigns = KolCampaign::all()
            ->sortBy(fn ($c) => KolCampaign::STATUS_ORDER[$c->status] ?? 9)->values();

        // Rollup: deal (non-batal) per campaign → jumlah, total biaya, views konten.
        $deals = KolDeal::whereNotNull('kol_campaign_id')->where('status', '!=', 'batal')
            ->get(['id', 'kol_campaign_id', 'total_biaya']);
        $viewsByDeal = KolContent::whereIn('kol_deal_id', $deals->pluck('id'))->with('latestSnapshot')->get()
            ->groupBy('kol_deal_id')->map(fn ($cs) => $cs->sum(fn ($c) => (int) ($c->latestSnapshot->views ?? 0)));

        $agg = [];
        foreach ($deals as $d) {
            $a = $agg[$d->kol_campaign_id] ?? ['deals' => 0, 'cost' => 0, 'views' => 0];
            $a['deals']++;
            $a['cost'] += (int) $d->total_biaya;
            $a['views'] += (int) ($viewsByDeal[$d->id] ?? 0);
            $agg[$d->kol_campaign_id] = $a;
        }

        $editing = null;
        if (ctype_digit((string) $request->query('edit'))) {
            $editing = KolCampaign::find((int) $request->query('edit'));
        }

        return view('kol_deals.campaigns', [
            'campaigns' => $campaigns,
            'agg' => $agg,
            'editing' => $editing,
            'platformLabels' => KolCampaign::PLATFORM_LABELS,
            'statusLabels' => KolCampaign::STATUS_LABELS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $campaign = KolCampaign::create($this->validated($request) + ['created_by' => $request->user()->id]);

        AuditService::log(action: 'create_kol_campaign', targetType: 'kol_campaign', targetId: $campaign->id,
            after: ['name' => $campaign->name]);

        return redirect()->route('kol-campaigns.index')->with('status', 'Campaign dibuat.');
    }

    public function update(Request $request, KolCampaign $campaign): RedirectResponse
    {
        $campaign->update($this->validated($request));

        return redirect()->route('kol-campaigns.index')->with('status', 'Campaign diperbarui.');
    }

    public function destroy(KolCampaign $campaign): RedirectResponse
    {
        AuditService::log(action: 'delete_kol_campaign', targetType: 'kol_campaign', targetId: $campaign->id,
            before: ['name' => $campaign->name]);
        $campaign->delete(); // deal.kol_campaign_id → null (FK nullOnDelete)

        return redirect()->route('kol-campaigns.index')->with('status', 'Campaign dihapus — deal tertaut dilepas.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(KolCampaign::PLATFORMS)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'target_views' => ['nullable', 'integer', 'min:0'],
            'target_gmv' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::in(KolCampaign::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
