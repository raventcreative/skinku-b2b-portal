<?php

namespace Tests\Feature\ReportBot;

use App\Services\ReportBot\TelegramClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TelegramClientTest extends TestCase
{
    private function client(string $token = 'TOK1'): TelegramClient
    {
        config()->set('services.telegram.token', $token);

        return app(TelegramClient::class);
    }

    /** Cari nilai field non-file dalam body multipart (bentuknya list {name,contents}, BUKAN peta assoc). */
    private function multipartValue($request, string $name)
    {
        foreach ($request->data() as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $name) {
                return $part['contents'] ?? null;
            }
        }

        return null;
    }

    public function test_send_message_posts_chat_id_and_text(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $this->client()->sendMessage(123, 'halo');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/botTOK1/sendMessage')
            && $r['chat_id'] === 123
            && $r['text'] === 'halo');
    }

    public function test_get_file_parses_result_file_path(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => [
            'file_id' => 'ABC', 'file_unique_id' => 'U1', 'file_size' => 10, 'file_path' => 'documents/file_1.pdf',
        ]], 200)]);

        $result = $this->client()->getFile('ABC');

        $this->assertSame('documents/file_1.pdf', $result['result']['file_path']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/botTOK1/getFile') && $r['file_id'] === 'ABC');
    }

    public function test_download_file_hits_file_endpoint_and_returns_raw_bytes(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response('RAW-BYTES-XYZ', 200)]);

        $bytes = $this->client()->downloadFile('documents/file_1.pdf');

        $this->assertSame('RAW-BYTES-XYZ', $bytes);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.telegram.org/file/botTOK1/documents/file_1.pdf');
    }

    public function test_send_document_posts_multipart_document_chat_id_and_caption(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->client()->sendDocument(456, 'laporan.xlsx', 'BINARYDATA', 'Laporan Juli');

        Http::assertSent(function ($r) {
            return str_contains($r->url(), '/botTOK1/sendDocument')
                && $r->hasFile('document', 'BINARYDATA', 'laporan.xlsx')
                // chat_id di-cast ke string sebelum masuk body multipart (lihat komentar di TelegramClient::sendDocument)
                && $this->multipartValue($r, 'chat_id') === '456'
                && $this->multipartValue($r, 'caption') === 'Laporan Juli';
        });
    }

    public function test_send_document_allows_null_caption(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->client()->sendDocument(456, 'laporan.xlsx', 'BINARYDATA');

        Http::assertSent(fn ($r) => $r->hasFile('document', 'BINARYDATA', 'laporan.xlsx'));
    }

    public function test_send_message_throws_when_response_not_successful(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request'], 400)]);

        $this->expectException(RuntimeException::class);
        $this->client()->sendMessage(123, 'halo');
    }

    public function test_get_file_throws_when_response_not_successful(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Not Found'], 404)]);

        $this->expectException(RuntimeException::class);
        $this->client()->getFile('BAD');
    }

    public function test_download_file_throws_when_response_not_successful(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response('', 404)]);

        $this->expectException(RuntimeException::class);
        $this->client()->downloadFile('bad/path');
    }

    public function test_send_document_throws_when_response_not_successful(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request'], 400)]);

        $this->expectException(RuntimeException::class);
        $this->client()->sendDocument(456, 'file.xlsx', 'BYTES');
    }

    /**
     * FINDING 1 (final review, security): pada kegagalan level KONEKSI (timeout/
     * DNS/TLS/refused — beda dari status HTTP biasa spt 400/404 di atas), Laravel
     * melempar Illuminate\Http\Client\ConnectionException yang PESANNYA memuat
     * URL request LENGKAP apa adanya, termasuk token bot di path
     * (".../bot{token}/...") — Guzzle cuma me-redact "user:pass@", tidak pernah
     * path. Tanpa TelegramClient::send() menangkap ini, token bocor verbatim ke
     * log (TelegramWebhookController::handle()) & ke chat user (flow2, sebelum
     * Finding 2). Simulasikan persis skenario itu: fake Http:: melempar
     * ConnectionException berisi token di URL-nya, pastikan RuntimeException
     * yang benar2 keluar dari sendMessage() GENERIK & TIDAK memuat token.
     */
    public function test_send_message_wraps_connection_exception_without_leaking_token(): void
    {
        $leakyMessage = 'cURL error 28: Failed to connect to api.telegram.org port 443 after 10000 ms: '
            .'Timeout was reached for https://api.telegram.org/bot8553766151:AAH_SECRET_TOKEN_VALUE/getFile';

        Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException($leakyMessage)]);

        $thrown = null;

        try {
            $this->client()->sendMessage(123, 'halo');
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'sendMessage() harus tetap melempar RuntimeException saat koneksi gagal.');
        $this->assertSame('Gagal menghubungi Telegram (koneksi).', $thrown->getMessage());
        $this->assertStringNotContainsString('AAH_SECRET_TOKEN_VALUE', $thrown->getMessage());
        $this->assertStringNotContainsString('bot8553766151', $thrown->getMessage());
        // Exception asli (berisi URL/token) TETAP disimpan sbg $previous utk
        // debugging via exception chain di level KODE, bukan lewat teks log/chat.
        $this->assertInstanceOf(ConnectionException::class, $thrown->getPrevious());
        $this->assertStringContainsString('AAH_SECRET_TOKEN_VALUE', $thrown->getPrevious()->getMessage());
    }
}
