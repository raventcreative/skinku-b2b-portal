<?php

namespace App\Http\Controllers;

use App\Models\AccAccount;
use App\Models\AccTemplate;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccTemplateController extends Controller
{
    public function index()
    {
        $templates = AccTemplate::with('lines.account')->orderBy('name')->get();
        $accounts = AccAccount::active()->orderBy('code')->get(['id', 'code', 'name']);

        return view('accounting.templates', compact('templates', 'accounts'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->save(new AccTemplate, $data, $request->user()->id);

        return back()->with('status', "Template \"{$data['name']}\" disimpan.");
    }

    public function update(Request $request, AccTemplate $template): RedirectResponse
    {
        $data = $this->validated($request);
        $this->save($template, $data);

        return back()->with('status', "Template \"{$data['name']}\" diperbarui.");
    }

    public function destroy(AccTemplate $template): RedirectResponse
    {
        $name = $template->name;
        $template->delete();
        AuditService::log(action: 'delete_acc_template', targetType: 'acc_template', targetId: $template->id, after: ['name' => $name]);

        return back()->with('status', "Template \"{$name}\" dihapus.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['nullable', 'integer', 'exists:acc_accounts,id'], // null = pilih saat input
            'lines.*.side' => ['required', 'in:debit,credit'],
        ]);
    }

    private function save(AccTemplate $template, array $data, ?int $creatorId = null): void
    {
        DB::transaction(function () use ($template, $data, $creatorId) {
            $template->name = $data['name'];
            $template->description = $data['description'] ?? null;
            $template->is_active = (bool) ($data['is_active'] ?? true);
            if ($creatorId && ! $template->exists) {
                $template->created_by = $creatorId;
            }
            $template->save();

            $template->lines()->delete();
            foreach (array_values($data['lines']) as $i => $line) {
                $template->lines()->create([
                    'account_id' => $line['account_id'] ?? null,
                    'side' => $line['side'],
                    'sort_order' => $i,
                ]);
            }

            AuditService::log(action: 'save_acc_template', targetType: 'acc_template', targetId: $template->id, after: ['name' => $template->name]);
        });
    }
}
