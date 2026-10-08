<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Client;
use App\Services\Accounting\ChartOfAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function index(Client $client)
    {
        return view('accounts.index', [
            'client' => $client,
            'accounts' => $client->accounts()->orderBy('code')->get()->groupBy('type'),
            'types' => Account::TYPES,
        ]);
    }

    public function store(Client $client, Request $request): RedirectResponse
    {
        $data = $this->validated($client, $request);
        $client->accounts()->create($data);

        return back()->with('status', "Akun {$data['code']} ditambahkan.");
    }

    public function update(Client $client, Account $account, Request $request): RedirectResponse
    {
        $this->assertOwned($client, $account);
        $account->update($this->validated($client, $request, $account));

        return back()->with('status', "Akun {$account->code} diperbarui.");
    }

    public function destroy(Client $client, Account $account): RedirectResponse
    {
        $this->assertOwned($client, $account);

        // Akun yang sudah dipakai jurnal tidak boleh hilang — saldo historisnya
        // akan menguap dan laporan periode lalu jadi salah. Nonaktifkan saja.
        if ($account->lines()->exists()) {
            $account->update(['is_active' => false]);

            return back()->with('status', "Akun {$account->code} sudah dipakai di jurnal, jadi dinonaktifkan (bukan dihapus).");
        }

        $code = $account->code;
        $account->delete();

        return back()->with('status', "Akun {$code} dihapus.");
    }

    private function assertOwned(Client $client, Account $account): void
    {
        abort_unless($account->client_id === $client->id, 404);
    }

    private function validated(Client $client, Request $request, ?Account $account = null): array
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('accounts', 'code')->where('client_id', $client->id)->ignore($account?->id),
            ],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(array_keys(Account::TYPES))],
            'subtype' => ['nullable', 'string', 'max:40'],
            'normal_balance' => ['nullable', Rule::in(['debit', 'credit'])],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['normal_balance'] = $data['normal_balance']
            ?? ChartOfAccounts::normalBalanceFor($data['code'], $data['type']);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
