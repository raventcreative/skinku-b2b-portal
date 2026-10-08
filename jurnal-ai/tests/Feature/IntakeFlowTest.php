<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\Journal;
use App\Models\User;
use App\Services\Accounting\ChartOfAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Alur ujung-ke-ujung: upload → AI baca (difake) → review → posting.
 * Panggilan AI sengaja difake supaya test tidak butuh API key & tidak bayar token.
 */
class IntakeFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.provider' => 'openai', 'ai.openai.key' => 'sk-test', 'ai.model' => 'gpt-4o-mini', 'ai.backup.key' => null, 'ai.backup.model' => null]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@test.id', 'password' => 'secret123',
            'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
        $this->client = Client::create(['name' => 'SKINKU', 'type' => Client::TYPE_INTERNAL]);
        app(ChartOfAccounts::class)->seedFor($this->client);
    }

    /** Palsukan balasan model dengan JSON ekstraksi tertentu. */
    private function fakeAi(array $extraction): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode($extraction)]]],
        ])]);
    }

    public function test_teks_tempel_dibaca_lalu_diposting_jadi_jurnal_balance(): void
    {
        $this->fakeAi([
            'doc_type' => 'note', 'flow' => 'expense',
            'vendor' => 'Meta Ads', 'document_date' => '2026-10-08',
            'payment_method' => 'bank', 'total' => 3585000,
            'tax' => 0, 'discount' => 0,
            'items' => [
                ['description' => 'Iklan Meta', 'amount' => 500000, 'account_code' => '6102', 'confidence' => 0.95],
                ['description' => 'Gaji admin', 'amount' => 3000000, 'account_code' => '6101', 'confidence' => 0.9],
                ['description' => 'Ongkir JNE', 'amount' => 85000, 'account_code' => '6106', 'confidence' => 0.85],
            ],
            'warnings' => [],
        ]);

        $response = $this->actingAs($this->admin)->post(route('documents.store', $this->client), [
            'kind' => Document::KIND_TEXT,
            'title' => 'Pengeluaran Oktober',
            'raw_text' => "bayar iklan meta 500rb\ngaji admin 3jt\nongkir jne 85.000",
        ]);

        $document = Document::sole();
        $response->assertRedirect(route('documents.show', [$this->client, $document]));
        $this->assertSame(Document::STATUS_EXTRACTED, $document->status);
        $this->assertSame('gpt-4o-mini', $document->model_used);
        $this->assertCount(3, $document->extraction['items']);

        // Layar review memuat usulan jurnal.
        $this->actingAs($this->admin)->get(route('documents.show', [$this->client, $document]))
            ->assertOk()
            ->assertSee('Iklan Meta')
            ->assertSee('Posting jurnal yang dicentang');

        // Posting dengan koreksi user: ongkir dipindah ke akun lain.
        $akun = fn (string $code) => $this->client->accounts()->where('code', $code)->value('id');

        $this->actingAs($this->admin)->post(route('documents.post', [$this->client, $document]), [
            'drafts' => [[
                'post' => '1',
                'date' => '2026-10-08',
                'description' => 'Pengeluaran Oktober',
                'type' => 'expense',
                'fingerprint' => 'sidikjari-uji',
                'lines' => [
                    ['account_id' => $akun('6102'), 'debit' => 500000, 'credit' => 0, 'memo' => 'Iklan Meta'],
                    ['account_id' => $akun('6101'), 'debit' => 3000000, 'credit' => 0, 'memo' => 'Gaji admin'],
                    ['account_id' => $akun('6116'), 'debit' => 85000, 'credit' => 0, 'memo' => 'Ongkir (dikoreksi user)'],
                    ['account_id' => $akun('1102'), 'debit' => 0, 'credit' => 3585000, 'memo' => 'Transfer bank'],
                ],
            ]],
        ])->assertRedirect();

        $journal = Journal::with('lines')->sole();
        $this->assertTrue($journal->isBalanced());
        $this->assertSame(3585000.0, $journal->totalDebit());
        $this->assertSame('2026-10', $journal->period);
        $this->assertSame($document->id, $journal->document_id);
        $this->assertSame(Document::STATUS_POSTED, $document->fresh()->status);
        // Koreksi user yang masuk buku, bukan usulan AI.
        $this->assertTrue($journal->lines->contains(fn ($l) => $l->account->code === '6116'));
    }

    public function test_posting_kedua_dengan_sidik_jari_sama_dilewati(): void
    {
        $this->fakeAi([
            'flow' => 'expense', 'document_date' => '2026-10-08', 'payment_method' => 'cash', 'total' => 100000,
            'items' => [['description' => 'Belanja', 'amount' => 100000, 'account_code' => '6190', 'confidence' => 0.9]],
        ]);

        $this->actingAs($this->admin)->post(route('documents.store', $this->client), [
            'kind' => Document::KIND_TEXT, 'raw_text' => 'belanja 100rb',
        ]);
        $document = Document::sole();
        $akun = fn (string $code) => $this->client->accounts()->where('code', $code)->value('id');

        $payload = ['drafts' => [[
            'post' => '1', 'date' => '2026-10-08', 'description' => 'Belanja', 'type' => 'expense',
            'fingerprint' => 'dobel-uji',
            'lines' => [
                ['account_id' => $akun('6190'), 'debit' => 100000, 'credit' => 0],
                ['account_id' => $akun('1101'), 'debit' => 0, 'credit' => 100000],
            ],
        ]]];

        $this->actingAs($this->admin)->post(route('documents.post', [$this->client, $document]), $payload);
        $this->actingAs($this->admin)->post(route('documents.post', [$this->client, $document]), $payload)
            ->assertSessionHas('status', fn ($msg) => str_contains($msg, 'anti-dobel'));

        $this->assertSame(1, Journal::count());
    }

    public function test_jurnal_tidak_balance_ditolak_dan_tidak_ada_yang_tersimpan(): void
    {
        $this->fakeAi([
            'flow' => 'expense', 'document_date' => '2026-10-08', 'total' => 100000,
            'items' => [['description' => 'X', 'amount' => 100000, 'account_code' => '6190']],
        ]);
        $this->actingAs($this->admin)->post(route('documents.store', $this->client), [
            'kind' => Document::KIND_TEXT, 'raw_text' => 'x 100rb',
        ]);
        $document = Document::sole();
        $akun = fn (string $code) => $this->client->accounts()->where('code', $code)->value('id');

        $this->actingAs($this->admin)->post(route('documents.post', [$this->client, $document]), [
            'drafts' => [[
                'post' => '1', 'date' => '2026-10-08', 'type' => 'expense',
                'lines' => [
                    ['account_id' => $akun('6190'), 'debit' => 100000, 'credit' => 0],
                    ['account_id' => $akun('1101'), 'debit' => 0, 'credit' => 90000],
                ],
            ]],
        ])->assertSessionHasErrors('posting');

        $this->assertSame(0, Journal::count());
        $this->assertSame(Document::STATUS_EXTRACTED, $document->fresh()->status);
    }

    public function test_satu_jurnal_gagal_membatalkan_seluruh_batch(): void
    {
        // Mutasi bank: 2 baris. Kalau baris ke-2 cacat, baris ke-1 pun tidak
        // boleh masuk — setengah mutasi di buku lebih berbahaya dari nol.
        $this->fakeAi([
            'flow' => 'expense', 'document_date' => '2026-10-01', 'payment_method' => 'bank',
            'transactions' => [
                ['date' => '2026-10-02', 'description' => 'Masuk', 'amount' => 1000000, 'direction' => 'in', 'account_code' => '4101'],
                ['date' => '2026-10-03', 'description' => 'Admin', 'amount' => 15000, 'direction' => 'out', 'account_code' => '7102'],
            ],
        ]);
        $this->actingAs($this->admin)->post(route('documents.store', $this->client), [
            'kind' => Document::KIND_BANK, 'raw_text' => 'mutasi',
        ]);
        $document = Document::sole();
        $akun = fn (string $code) => $this->client->accounts()->where('code', $code)->value('id');

        $this->actingAs($this->admin)->post(route('documents.post', [$this->client, $document]), [
            'drafts' => [
                ['post' => '1', 'date' => '2026-10-02', 'type' => 'bank', 'lines' => [
                    ['account_id' => $akun('1102'), 'debit' => 1000000, 'credit' => 0],
                    ['account_id' => $akun('4101'), 'debit' => 0, 'credit' => 1000000],
                ]],
                ['post' => '1', 'date' => '2026-10-03', 'type' => 'bank', 'lines' => [
                    ['account_id' => $akun('7102'), 'debit' => 15000, 'credit' => 0],
                    ['account_id' => $akun('1102'), 'debit' => 0, 'credit' => 99999],
                ]],
            ],
        ])->assertSessionHasErrors('posting');

        $this->assertSame(0, Journal::count());
    }

    public function test_upload_gambar_disimpan_dan_dikirim_sebagai_lampiran_vision(): void
    {
        Storage::fake('local');
        $this->fakeAi([
            'flow' => 'expense', 'document_date' => '2026-10-08', 'payment_method' => 'cash', 'total' => 45000,
            'items' => [['description' => 'Kopi', 'amount' => 45000, 'account_code' => '6111', 'confidence' => 0.8]],
        ]);

        $this->actingAs($this->admin)->post(route('documents.store', $this->client), [
            'kind' => Document::KIND_RECEIPT,
            'file' => UploadedFile::fake()->image('struk.jpg', 600, 900),
        ])->assertRedirect();

        $document = Document::sole();
        Storage::disk('local')->assertExists($document->path);
        $this->assertSame('struk.jpg', $document->original_name);
        $this->assertNotNull($document->content_hash);

        // Pastikan gambar benar-benar dikirim sebagai part image, bukan teks.
        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][1]['content'];
            $types = array_column($content, 'type');

            return in_array('image_url', $types, true);
        });
    }

    public function test_dokumen_identik_ditandai_sebagai_kemungkinan_dobel(): void
    {
        $this->fakeAi([
            'flow' => 'expense', 'document_date' => '2026-10-08', 'total' => 50000,
            'items' => [['description' => 'X', 'amount' => 50000, 'account_code' => '6190']],
        ]);

        $payload = ['kind' => Document::KIND_TEXT, 'raw_text' => 'belanja 50rb'];
        $this->actingAs($this->admin)->post(route('documents.store', $this->client), $payload);
        $this->actingAs($this->admin)->post(route('documents.store', $this->client), $payload)
            ->assertSessionHas('duplicate_of');

        $this->assertSame(2, Document::count());
    }

    public function test_upload_tanpa_berkas_dan_tanpa_teks_ditolak(): void
    {
        $this->actingAs($this->admin)->post(route('documents.store', $this->client), [
            'kind' => Document::KIND_RECEIPT,
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, Document::count());
    }

    public function test_ai_gagal_dokumen_tetap_tersimpan_dengan_status_gagal(): void
    {
        Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'insufficient_quota']], 429)]);

        $this->actingAs($this->admin)->post(route('documents.store', $this->client), [
            'kind' => Document::KIND_TEXT, 'raw_text' => 'belanja 50rb',
        ])->assertSessionHasErrors('ai');

        $document = Document::sole();
        $this->assertSame(Document::STATUS_FAILED, $document->status);
        $this->assertStringContainsString('Kuota', $document->error);
        // Isinya tidak hilang — user bisa "baca ulang" tanpa upload lagi.
        $this->assertSame('belanja 50rb', $document->raw_text);
    }

    public function test_api_key_kosong_ditandai_gagal_di_dokumen_bukan_cuma_flash(): void
    {
        // Alasannya harus menempel di dokumen supaya masih terbaca setelah
        // halaman di-refresh — flash message hilang sekali lihat.
        config(['ai.openai.key' => '']);

        $this->actingAs($this->admin)->post(route('documents.store', $this->client), [
            'kind' => Document::KIND_TEXT, 'raw_text' => 'belanja 50rb',
        ])->assertSessionHasErrors('ai');

        $document = Document::sole();
        $this->assertSame(Document::STATUS_FAILED, $document->status);
        $this->assertStringContainsString('OPENAI_API_KEY', $document->error);

        $this->actingAs($this->admin)->get(route('documents.show', [$this->client, $document]))
            ->assertOk()
            ->assertSee('OPENAI_API_KEY');
    }

    public function test_balasan_bukan_json_ditandai_gagal(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Maaf, saya tidak bisa membaca dokumen ini.']]],
        ])]);

        $this->actingAs($this->admin)->post(route('documents.store', $this->client), [
            'kind' => Document::KIND_TEXT, 'raw_text' => 'belanja 50rb',
        ])->assertSessionHasErrors('ai');

        $this->assertSame(Document::STATUS_FAILED, Document::sole()->status);
    }

    public function test_otak_cadangan_dipakai_saat_primary_kehabisan_kuota(): void
    {
        config(['ai.backup.key' => 'sk-backup', 'ai.backup.base' => 'https://openrouter.ai/api/v1', 'ai.backup.model' => 'model-cadangan']);

        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'insufficient_quota']], 429),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'flow' => 'expense', 'document_date' => '2026-10-08', 'total' => 50000,
                    'items' => [['description' => 'X', 'amount' => 50000, 'account_code' => '6190']],
                ])]]],
            ]),
        ]);

        $this->actingAs($this->admin)->post(route('documents.store', $this->client), [
            'kind' => Document::KIND_TEXT, 'raw_text' => 'belanja 50rb',
        ])->assertRedirect();

        $document = Document::sole();
        $this->assertSame(Document::STATUS_EXTRACTED, $document->status);
        $this->assertSame('model-cadangan', $document->model_used);
    }

    public function test_dokumen_klien_lain_tidak_bisa_diakses_lewat_url_silang(): void
    {
        $this->fakeAi([
            'flow' => 'expense', 'document_date' => '2026-10-08', 'total' => 50000,
            'items' => [['description' => 'X', 'amount' => 50000, 'account_code' => '6190']],
        ]);
        $this->actingAs($this->admin)->post(route('documents.store', $this->client), [
            'kind' => Document::KIND_TEXT, 'raw_text' => 'belanja 50rb',
        ]);
        $document = Document::sole();

        $lain = Client::create(['name' => 'Klien Lain', 'type' => Client::TYPE_EXTERNAL]);

        $this->actingAs($this->admin)->get(route('documents.show', [$lain, $document]))->assertNotFound();
        $this->actingAs($this->admin)->get(route('documents.file', [$lain, $document]))->assertNotFound();
    }
}
