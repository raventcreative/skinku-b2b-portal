<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiException;
use App\Services\Ai\AiProvider;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asisten AI (di balik permission use_ai_assistant). Dipakai dua cara dengan
 * backend sama: widget mengambang (fetch → JSON) & halaman penuh /asisten
 * (form → redirect). Percakapan disimpan di session (sementara, hilang saat
 * logout). Aksi tulis lewat konfirmasi 2 langkah. Lihat AI_ASSISTANT_SPEC.md.
 */
class AiAssistantController extends Controller
{
    private const THREAD = 'ai_thread';

    private const PENDING = 'ai_pending';

    public function __construct(private ToolRegistry $tools) {}

    public function index(Request $request)
    {
        return view('ai.index', [
            'thread' => $request->session()->get(self::THREAD, []),
            'pending' => $request->session()->get(self::PENDING),
        ]);
    }

    /** Keadaan percakapan terkini — dipakai widget saat pertama dibuka. */
    public function state(Request $request): JsonResponse
    {
        return response()->json($this->snapshot($request));
    }

    public function send(Request $request): Response
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);
        $user = $request->user();
        $history = $request->session()->get(self::THREAD, []);
        $request->session()->forget(self::PENDING);

        try {
            $provider = app(AiProvider::class);
            $out = (new AiAgentService($provider, $this->tools))->run($user, $history, $data['message']);
        } catch (AiException $e) {
            // Simpan pesan user + catatan error sebagai balasan (bukan 500).
            $request->session()->put(self::THREAD, array_merge($history, [
                ['role' => 'user', 'content' => $data['message']],
                ['role' => 'assistant', 'content' => '⚠️ '.$e->getMessage()],
            ]));

            return $this->respond($request);
        }

        $request->session()->put(self::THREAD, $out['history']);
        if ($out['result']->type === 'confirm') {
            $request->session()->put(self::PENDING, $out['result']->pending);
        }

        AuditService::log(action: 'ai_message', targetType: 'ai_assistant', after: ['type' => $out['result']->type]);

        return $this->respond($request);
    }

    public function confirm(Request $request): Response
    {
        $pending = $request->session()->pull(self::PENDING);   // ambil & hapus
        $user = $request->user();
        $thread = $request->session()->get(self::THREAD, []);

        if (! $pending) {
            return $this->respond($request);
        }

        // Batal.
        if ($request->input('setuju') !== 'ya') {
            $thread[] = ['role' => 'assistant', 'content' => 'Oke, dibatalkan. 👍'];
            $request->session()->put(self::THREAD, $thread);

            return $this->respond($request);
        }

        $tool = $this->tools->find($pending['tool'], $user);
        if (! $tool || ! $tool->isWrite()) {
            $thread[] = ['role' => 'assistant', 'content' => '⚠️ Aksi tak bisa dijalankan (alat tidak tersedia).'];
            $request->session()->put(self::THREAD, $thread);

            return $this->respond($request);
        }

        try {
            $err = $tool->validate($pending['args'], $user);   // validasi ulang (defensif)
            if ($err !== null) {
                throw new \RuntimeException($err);
            }
            $result = $tool->run($pending['args'], $user);
            $msg = $result['pesan'] ?? '✅ Selesai.';
        } catch (\Throwable $e) {
            $msg = '⚠️ Gagal: '.$e->getMessage();
        }

        $thread[] = ['role' => 'assistant', 'content' => $msg];
        $request->session()->put(self::THREAD, $thread);

        AuditService::log(action: 'ai_confirm', targetType: 'ai_assistant', after: ['tool' => $pending['tool']]);

        return $this->respond($request);
    }

    public function reset(Request $request): Response
    {
        $request->session()->forget([self::THREAD, self::PENDING]);

        return $this->respond($request);
    }

    /** Ringkasan percakapan buat frontend (thread + preview konfirmasi bila ada). */
    private function snapshot(Request $request): array
    {
        $pending = $request->session()->get(self::PENDING);

        return [
            'thread' => array_values($request->session()->get(self::THREAD, [])),
            'pending' => $pending ? ['preview' => $pending['preview']] : null,
        ];
    }

    /** Widget (fetch) dapat JSON; halaman penuh (form) di-redirect. */
    private function respond(Request $request): Response
    {
        return $request->wantsJson()
            ? response()->json($this->snapshot($request))
            : redirect()->route('ai.index');
    }
}
