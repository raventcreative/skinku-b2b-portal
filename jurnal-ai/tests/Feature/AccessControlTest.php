<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\User;
use App\Services\Accounting\ChartOfAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::create(['name' => 'SKINKU', 'type' => Client::TYPE_INTERNAL]);
        app(ChartOfAccounts::class)->seedFor($this->client);
    }

    private function user(string $role = User::ROLE_STAFF, bool $active = true): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@test.id',
            'password' => 'secret123',
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    public function test_tamu_dialihkan_ke_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('clients.index'))->assertRedirect(route('login'));
        $this->get(route('documents.create', $this->client))->assertRedirect(route('login'));
    }

    public function test_login_berhasil_dan_salah_password_ditolak(): void
    {
        $user = $this->user();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'salah'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'secret123'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_nonaktif_tidak_bisa_login(): void
    {
        $user = $this->user(User::ROLE_STAFF, active: false);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'secret123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_user_yang_dinonaktifkan_di_tengah_sesi_langsung_keluar(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->update(['is_active' => false]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_staff_boleh_input_dan_posting_tapi_tak_boleh_ubah_struktur(): void
    {
        $staff = $this->user(User::ROLE_STAFF);

        // Boleh: lihat & input.
        $this->actingAs($staff)->get(route('clients.show', $this->client))->assertOk();
        $this->actingAs($staff)->get(route('documents.create', $this->client))->assertOk();
        $this->actingAs($staff)->get(route('journals.create', $this->client))->assertOk();
        $this->actingAs($staff)->get(route('accounts.index', $this->client))->assertOk();
        $this->actingAs($staff)->get(route('reports.income-statement', $this->client))->assertOk();

        // Tidak boleh: kelola klien & COA.
        $this->actingAs($staff)->get(route('clients.create'))->assertForbidden();
        $this->actingAs($staff)->post(route('clients.store'), ['name' => 'X', 'type' => 'external'])->assertForbidden();
        $this->actingAs($staff)->get(route('clients.edit', $this->client))->assertForbidden();
        $this->actingAs($staff)->delete(route('clients.destroy', $this->client))->assertForbidden();
        $this->actingAs($staff)->post(route('accounts.store', $this->client), [
            'code' => '6999', 'name' => 'Beban Nakal', 'type' => 'expense',
        ])->assertForbidden();

        $this->assertSame(1, Client::count());
    }

    public function test_admin_bisa_kelola_klien_dan_coa(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->post(route('clients.store'), [
            'name' => 'Rave Tailor', 'type' => Client::TYPE_INTERNAL,
        ])->assertRedirect();

        $baru = Client::where('name', 'Rave Tailor')->sole();
        $this->assertSame('rave-tailor', $baru->slug);
        // Klien baru otomatis dapat COA standar — tidak boleh mulai dari buku kosong.
        $this->assertSame(count(ChartOfAccounts::template()), $baru->accounts()->count());

        $this->actingAs($admin)->post(route('accounts.store', $baru), [
            'code' => '6999', 'name' => 'Beban Riset', 'type' => 'expense', 'subtype' => 'opex', 'is_active' => '1',
        ])->assertRedirect();

        $akun = $baru->accounts()->where('code', '6999')->sole();
        $this->assertSame('debit', $akun->normal_balance, 'Saldo normal diturunkan otomatis dari tipe.');
    }

    public function test_kode_akun_duplikat_dalam_satu_klien_ditolak_tapi_lintas_klien_boleh(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->post(route('accounts.store', $this->client), [
            'code' => '1101', 'name' => 'Kas Dobel', 'type' => 'asset',
        ])->assertSessionHasErrors('code');

        // Klien lain pakai kode yang sama: wajar, bukunya terpisah.
        $lain = Client::create(['name' => 'Klien Lain', 'type' => Client::TYPE_EXTERNAL]);
        $this->actingAs($admin)->post(route('accounts.store', $lain), [
            'code' => '1101', 'name' => 'Kas', 'type' => 'asset',
        ])->assertRedirect();

        $this->assertSame(1, $lain->accounts()->where('code', '1101')->count());
    }

    public function test_klien_dengan_jurnal_posted_dinonaktifkan_bukan_dihapus(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $akun = fn (string $code) => $this->client->accounts()->where('code', $code)->value('id');

        $this->actingAs($admin)->post(route('journals.store', $this->client), [
            'date' => '2026-10-08',
            'description' => 'Uji',
            'lines' => [
                ['account_id' => $akun('6102'), 'debit' => 100000],
                ['account_id' => $akun('1101'), 'credit' => 100000],
            ],
        ])->assertRedirect();

        $this->actingAs($admin)->delete(route('clients.destroy', $this->client))->assertRedirect();

        $this->assertDatabaseHas('clients', ['id' => $this->client->id, 'is_active' => false]);
    }

    public function test_akun_yang_sudah_dipakai_dinonaktifkan_bukan_dihapus(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $iklan = $this->client->accounts()->where('code', '6102')->sole();
        $kas = $this->client->accounts()->where('code', '1101')->sole();

        $this->actingAs($admin)->post(route('journals.store', $this->client), [
            'date' => '2026-10-08',
            'lines' => [
                ['account_id' => $iklan->id, 'debit' => 100000],
                ['account_id' => $kas->id, 'credit' => 100000],
            ],
        ]);

        $this->actingAs($admin)->delete(route('accounts.destroy', [$this->client, $iklan]))->assertRedirect();

        $this->assertDatabaseHas('accounts', ['id' => $iklan->id, 'is_active' => false]);
    }

    public function test_akun_klien_lain_tidak_bisa_diubah_lewat_url_silang(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $lain = Client::create(['name' => 'Klien Lain', 'type' => Client::TYPE_EXTERNAL]);
        app(ChartOfAccounts::class)->seedFor($lain);
        $akunLain = $lain->accounts()->where('code', '1101')->sole();

        $this->actingAs($admin)
            ->put(route('accounts.update', [$this->client, $akunLain]), [
                'code' => '1101', 'name' => 'Dibajak', 'type' => 'asset',
            ])->assertNotFound();

        $this->assertSame('Kas', $akunLain->fresh()->name);
    }

    public function test_jurnal_manual_tidak_balance_ditolak_dengan_pesan_jelas(): void
    {
        $staff = $this->user(User::ROLE_STAFF);
        $akun = fn (string $code) => $this->client->accounts()->where('code', $code)->value('id');

        $this->actingAs($staff)->post(route('journals.store', $this->client), [
            'date' => '2026-10-08',
            'lines' => [
                ['account_id' => $akun('6102'), 'debit' => 100000],
                ['account_id' => $akun('1101'), 'credit' => 90000],
            ],
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, $this->client->journals()->count());
    }

    public function test_staff_boleh_void_tapi_tak_boleh_hapus_jurnal(): void
    {
        $staff = $this->user(User::ROLE_STAFF);
        $akun = fn (string $code) => $this->client->accounts()->where('code', $code)->value('id');

        $this->actingAs($staff)->post(route('journals.store', $this->client), [
            'date' => '2026-10-08',
            'lines' => [
                ['account_id' => $akun('6102'), 'debit' => 100000],
                ['account_id' => $akun('1101'), 'credit' => 100000],
            ],
        ]);
        $journal = $this->client->journals()->sole();

        $this->actingAs($staff)->delete(route('journals.destroy', [$this->client, $journal]))->assertForbidden();
        $this->actingAs($staff)->post(route('journals.void', [$this->client, $journal]))->assertRedirect();

        $this->assertSame('void', $journal->fresh()->status);
    }

    public function test_slug_klien_dibuat_unik_walau_nama_sama(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        foreach (['Toko Maju', 'Toko Maju', 'Toko Maju'] as $name) {
            $this->actingAs($admin)->post(route('clients.store'), ['name' => $name, 'type' => 'external']);
        }

        $slugs = Client::where('name', 'Toko Maju')->pluck('slug')->sort()->values()->all();
        $this->assertSame(['toko-maju', 'toko-maju-2', 'toko-maju-3'], $slugs);
    }
}
