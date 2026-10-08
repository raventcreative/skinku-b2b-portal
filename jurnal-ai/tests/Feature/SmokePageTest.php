<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\JournalPoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Setiap halaman harus merender tanpa error — baik dengan data maupun kosong.
 * Pagar murah untuk kelas bug yang tidak tertangkap unit test (route() kurang
 * parameter, variabel view hilang, null di Blade).
 */
class SmokePageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@test.id', 'password' => 'secret123',
            'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
        $this->client = Client::create(['name' => 'SKINKU', 'type' => Client::TYPE_INTERNAL]);
        app(ChartOfAccounts::class)->seedFor($this->client);
    }

    /** @return array<int,string> */
    private function halaman(): array
    {
        return [
            route('dashboard'),
            route('clients.index'),
            route('clients.create'),
            route('clients.show', $this->client),
            route('clients.edit', $this->client),
            route('accounts.index', $this->client),
            route('documents.index', $this->client),
            route('documents.create', $this->client),
            route('journals.index', $this->client),
            route('journals.create', $this->client),
            route('reports.trial-balance', $this->client),
            route('reports.income-statement', $this->client),
            route('reports.balance-sheet', $this->client),
        ];
    }

    public function test_semua_halaman_render_saat_buku_masih_kosong(): void
    {
        foreach ($this->halaman() as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk("Gagal render (kosong): {$url}");
        }
    }

    public function test_semua_halaman_render_saat_sudah_ada_data(): void
    {
        $akun = fn (string $code) => $this->client->accounts()->where('code', $code)->value('id');

        app(JournalPoster::class)->record([
            'client_id' => $this->client->id, 'date' => now()->toDateString(),
            'description' => 'Bayar iklan', 'type' => 'expense',
        ], [
            ['account_id' => $akun('6102'), 'debit' => 500000, 'memo' => 'Meta Ads'],
            ['account_id' => $akun('1102'), 'credit' => 500000, 'memo' => 'Transfer'],
        ]);

        $this->client->documents()->create([
            'kind' => Document::KIND_RECEIPT,
            'title' => 'Struk uji',
            'status' => Document::STATUS_EXTRACTED,
            'raw_text' => 'belanja',
            'extraction' => [
                'flow' => 'expense', 'vendor' => 'Toko Uji', 'document_date' => now()->toDateString(),
                'payment_method' => 'cash', 'total' => 100000, 'tax' => 0, 'discount' => 0,
                'items' => [['description' => 'Barang', 'amount' => 100000, 'account_code' => '6190', 'confidence' => 0.5, 'qty' => null, 'unit' => null, 'unit_price' => null, 'account_reason' => 'Tidak jelas']],
                'transactions' => [], 'warnings' => ['Tulisan agak buram'],
            ],
        ]);

        $urls = [...$this->halaman(), route('documents.show', [$this->client, $this->client->documents()->sole()])];

        foreach ($urls as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk("Gagal render (berisi): {$url}");
        }
    }

    public function test_halaman_dokumen_gagal_baca_tetap_render(): void
    {
        $document = $this->client->documents()->create([
            'kind' => Document::KIND_RECEIPT,
            'status' => Document::STATUS_FAILED,
            'error' => 'Kuota habis',
        ]);

        $this->actingAs($this->admin)->get(route('documents.show', [$this->client, $document]))
            ->assertOk()
            ->assertSee('Kuota habis');
    }

    public function test_halaman_render_untuk_staff_tanpa_tombol_admin(): void
    {
        $staff = User::create([
            'name' => 'Staff', 'email' => 'staff@test.id', 'password' => 'secret123',
            'role' => User::ROLE_STAFF, 'is_active' => true,
        ]);

        $this->actingAs($staff)->get(route('accounts.index', $this->client))
            ->assertOk()
            ->assertDontSee('Sinkron dari template');
    }
}
