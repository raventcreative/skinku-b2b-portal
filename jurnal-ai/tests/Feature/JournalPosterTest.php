<?php

namespace Tests\Feature;

use App\Exceptions\AccountingException;
use App\Models\Client;
use App\Models\Journal;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\JournalPoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalPosterTest extends TestCase
{
    use RefreshDatabase;

    private JournalPoster $poster;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->poster = app(JournalPoster::class);
        $this->client = $this->klien();
    }

    private function klien(string $name = 'Klien Uji'): Client
    {
        $client = Client::create(['name' => $name, 'type' => Client::TYPE_EXTERNAL]);
        app(ChartOfAccounts::class)->seedFor($client);

        return $client;
    }

    private function akun(Client $client, string $code): int
    {
        return $client->accounts()->where('code', $code)->value('id');
    }

    public function test_jurnal_balance_tersimpan_dengan_period_dari_tanggal(): void
    {
        $journal = $this->poster->record([
            'client_id' => $this->client->id,
            'date' => '2026-10-08',
            'description' => 'Bayar iklan Meta',
        ], [
            ['account_id' => $this->akun($this->client, '6102'), 'debit' => 500000],
            ['account_id' => $this->akun($this->client, '1102'), 'credit' => 500000],
        ]);

        $this->assertSame('2026-10', $journal->period);
        $this->assertSame(Journal::STATUS_POSTED, $journal->status);
        $this->assertTrue($journal->isBalanced());
        $this->assertCount(2, $journal->lines);
    }

    public function test_jurnal_tidak_balance_ditolak(): void
    {
        $this->expectException(AccountingException::class);
        $this->expectExceptionMessageMatches('/tidak balance/');

        $this->poster->record([
            'client_id' => $this->client->id, 'date' => '2026-10-08',
        ], [
            ['account_id' => $this->akun($this->client, '6102'), 'debit' => 500000],
            ['account_id' => $this->akun($this->client, '1102'), 'credit' => 400000],
        ]);
    }

    public function test_satu_baris_tidak_boleh_debit_dan_kredit_sekaligus(): void
    {
        $this->expectException(AccountingException::class);
        $this->expectExceptionMessageMatches('/debit dan kredit sekaligus/');

        $this->poster->record([
            'client_id' => $this->client->id, 'date' => '2026-10-08',
        ], [
            ['account_id' => $this->akun($this->client, '6102'), 'debit' => 500000, 'credit' => 500000],
            ['account_id' => $this->akun($this->client, '1102'), 'credit' => 500000],
        ]);
    }

    public function test_baris_kosong_dilewati_tanpa_error(): void
    {
        // Form selalu mengirim baris kosong di bawah — tidak boleh bikin gagal.
        $journal = $this->poster->record([
            'client_id' => $this->client->id, 'date' => '2026-10-08',
        ], [
            ['account_id' => $this->akun($this->client, '6102'), 'debit' => 500000],
            ['account_id' => $this->akun($this->client, '1102'), 'credit' => 500000],
            ['account_id' => null, 'debit' => null, 'credit' => null],
            ['account_id' => '', 'debit' => 0, 'credit' => 0],
        ]);

        $this->assertCount(2, $journal->lines);
    }

    public function test_jurnal_kurang_dari_dua_baris_ditolak(): void
    {
        $this->expectException(AccountingException::class);
        $this->expectExceptionMessageMatches('/minimal 2 baris/');

        $this->poster->record([
            'client_id' => $this->client->id, 'date' => '2026-10-08',
        ], [
            ['account_id' => $this->akun($this->client, '6102'), 'debit' => 500000],
        ]);
    }

    public function test_akun_milik_klien_lain_ditolak(): void
    {
        // Pagar isolasi buku: kalau lolos, laporan dua klien bakal tercampur.
        $lain = $this->klien('Klien Lain');

        $this->expectException(AccountingException::class);
        $this->expectExceptionMessageMatches('/bukan milik klien ini/');

        $this->poster->record([
            'client_id' => $this->client->id, 'date' => '2026-10-08',
        ], [
            ['account_id' => $this->akun($this->client, '6102'), 'debit' => 500000],
            ['account_id' => $this->akun($lain, '1102'), 'credit' => 500000],
        ]);
    }

    public function test_jurnal_tanpa_klien_atau_tanggal_ditolak(): void
    {
        $lines = [
            ['account_id' => $this->akun($this->client, '6102'), 'debit' => 1],
            ['account_id' => $this->akun($this->client, '1102'), 'credit' => 1],
        ];

        try {
            $this->poster->record(['client_id' => 0, 'date' => '2026-10-08'], $lines);
            $this->fail('Jurnal tanpa klien seharusnya ditolak.');
        } catch (AccountingException $e) {
            $this->assertStringContainsString('klien', $e->getMessage());
        }

        $this->expectException(AccountingException::class);
        $this->expectExceptionMessageMatches('/tanggal/');
        $this->poster->record(['client_id' => $this->client->id, 'date' => ''], $lines);
    }

    public function test_void_mengeluarkan_jurnal_dari_saldo(): void
    {
        $iklan = $this->akun($this->client, '6102');
        $journal = $this->poster->record([
            'client_id' => $this->client->id, 'date' => '2026-10-08',
        ], [
            ['account_id' => $iklan, 'debit' => 500000],
            ['account_id' => $this->akun($this->client, '1102'), 'credit' => 500000],
        ]);

        $this->assertSame(500000.0, $this->poster->balanceOf($iklan));

        $this->poster->void($journal);

        $this->assertSame(0.0, $this->poster->balanceOf($iklan));
        $this->assertSame(Journal::STATUS_VOID, $journal->fresh()->status);
    }

    public function test_draft_tidak_dihitung_sampai_diposting(): void
    {
        $iklan = $this->akun($this->client, '6102');
        $journal = $this->poster->record([
            'client_id' => $this->client->id, 'date' => '2026-10-08',
        ], [
            ['account_id' => $iklan, 'debit' => 500000],
            ['account_id' => $this->akun($this->client, '1102'), 'credit' => 500000],
        ], Journal::STATUS_DRAFT);

        $this->assertSame(0.0, $this->poster->balanceOf($iklan));

        $this->poster->post($journal);

        $this->assertSame(500000.0, $this->poster->balanceOf($iklan));
    }

    public function test_jurnal_void_tidak_bisa_diposting_ulang(): void
    {
        $journal = $this->poster->record([
            'client_id' => $this->client->id, 'date' => '2026-10-08',
        ], [
            ['account_id' => $this->akun($this->client, '6102'), 'debit' => 1000],
            ['account_id' => $this->akun($this->client, '1102'), 'credit' => 1000],
        ]);
        $this->poster->void($journal);

        $this->expectException(AccountingException::class);
        $this->expectExceptionMessageMatches('/sudah void/');
        $this->poster->post($journal->fresh());
    }

    public function test_sidik_jari_stabil_dan_mendeteksi_dobel(): void
    {
        $a = JournalPoster::fingerprint(1, '2026-10-08', 500000, 'Meta Ads');
        // Spasi berlebih & huruf besar tidak boleh mengubah sidik jari —
        // kalau berubah, struk yang difoto ulang lolos jadi dobel.
        $b = JournalPoster::fingerprint(1, '2026-10-08', 500000, '  META   ADS ');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, JournalPoster::fingerprint(2, '2026-10-08', 500000, 'Meta Ads'));
        $this->assertNotSame($a, JournalPoster::fingerprint(1, '2026-10-09', 500000, 'Meta Ads'));

        $this->assertFalse($this->poster->alreadyPosted($this->client->id, $a));

        $this->poster->record([
            'client_id' => $this->client->id, 'date' => '2026-10-08', 'fingerprint' => $a,
        ], [
            ['account_id' => $this->akun($this->client, '6102'), 'debit' => 500000],
            ['account_id' => $this->akun($this->client, '1102'), 'credit' => 500000],
        ]);

        $this->assertTrue($this->poster->alreadyPosted($this->client->id, $a));
        // Klien lain dengan sidik jari sama tetap boleh — bukunya terpisah.
        $this->assertFalse($this->poster->alreadyPosted($this->klien('Klien C')->id, $a));
    }

    public function test_jurnal_void_tidak_memblokir_posting_ulang(): void
    {
        $fingerprint = JournalPoster::fingerprint($this->client->id, '2026-10-08', 1000, 'uji');
        $journal = $this->poster->record([
            'client_id' => $this->client->id, 'date' => '2026-10-08', 'fingerprint' => $fingerprint,
        ], [
            ['account_id' => $this->akun($this->client, '6102'), 'debit' => 1000],
            ['account_id' => $this->akun($this->client, '1102'), 'credit' => 1000],
        ]);

        $this->poster->void($journal);

        // Setelah di-void, transaksi yang sama harus bisa dicatat ulang.
        $this->assertFalse($this->poster->alreadyPosted($this->client->id, $fingerprint));
    }

    public function test_coa_template_terpasang_lengkap_dan_idempoten(): void
    {
        $jumlah = count(ChartOfAccounts::template());
        $this->assertSame($jumlah, $this->client->accounts()->count());

        // Dipanggil ulang tidak boleh menggandakan akun.
        $this->assertSame(0, app(ChartOfAccounts::class)->seedFor($this->client));
        $this->assertSame($jumlah, $this->client->accounts()->count());
    }

    public function test_akun_kontra_punya_saldo_normal_terbalik(): void
    {
        // Akumulasi penyusutan bertipe aset tapi saldo normalnya kredit.
        $this->assertSame('credit', $this->client->accounts()->where('code', '1502')->value('normal_balance'));
        // Retur penjualan bertipe pendapatan tapi saldo normalnya debit.
        $this->assertSame('debit', $this->client->accounts()->where('code', '4104')->value('normal_balance'));
        $this->assertSame('debit', $this->client->accounts()->where('code', '1101')->value('normal_balance'));
        $this->assertSame('credit', $this->client->accounts()->where('code', '2101')->value('normal_balance'));
    }
}
