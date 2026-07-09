<?php

namespace App\Http\Controllers;

use App\Models\AccAccount;
use App\Models\AccJournalLine;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccAccountController extends Controller
{
    public function index()
    {
        $accounts = AccAccount::orderBy('code')->get();

        return view('accounting.accounts', compact('accounts'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $account = AccAccount::create($data);
        AuditService::log(action: 'create_account', targetType: 'acc_account', targetId: $account->id, after: ['code' => $account->code, 'name' => $account->name]);

        return back()->with('status', "Akun {$account->code} · {$account->name} ditambahkan.");
    }

    public function update(Request $request, AccAccount $account): RedirectResponse
    {
        $account->update($this->validated($request, $account->id));
        AuditService::log(action: 'update_account', targetType: 'acc_account', targetId: $account->id, after: ['code' => $account->code, 'name' => $account->name]);

        return back()->with('status', "Akun {$account->code} diperbarui.");
    }

    public function destroy(AccAccount $account): RedirectResponse
    {
        // Lindungi integritas: akun yang sudah dipakai di jurnal tidak boleh dihapus.
        if (AccJournalLine::where('account_id', $account->id)->exists()) {
            return back()->withErrors(['account' => "Akun {$account->code} sudah dipakai di jurnal — tidak bisa dihapus. Non-aktifkan saja."]);
        }

        $label = "{$account->code} · {$account->name}";
        $account->delete();
        AuditService::log(action: 'delete_account', targetType: 'acc_account', targetId: $account->id, after: ['code' => $account->code]);

        return back()->with('status', "Akun {$label} dihapus.");
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('acc_accounts', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(AccAccount::TYPES)],
            'subtype' => ['nullable', 'string', 'max:40'],
            'normal_balance' => ['required', 'in:debit,credit'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['subtype'] = $data['subtype'] ?: null;

        return $data;
    }
}
