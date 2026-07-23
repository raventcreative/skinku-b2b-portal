<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiException;
use App\Services\Ai\AiProvider;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Halaman "Asisten AI" (di balik permission use_ai_assistant). Chat
 * server-rendered: percakapan disimpan di session (sementara, hilang saat
 * logout). Aksi tulis lewat konfirmasi 2 langkah (send -> confirm). Lihat
 * AI_ASSISTANT_SPEC.md.
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

    public function send(Request $request): RedirectResponse
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

            return redirect()->route('ai.index');
        }

        $request->session()->put(self::THREAD, $out['history']);
        if ($out['result']->type === 'confirm') {
            $request->session()->put(self::PENDING, $out['result']->pending);
        }

        AuditService::log(action: 'ai_message', targetType: 'ai_assistant', after: ['type' => $out['result']->type]);

        return redirect()->route('ai.index');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull(self::PENDING);   // ambil & hapus
        $user = $request->user();
        $thread = $request->session()->get(self::THREAD, []);

        if (! $pending) {
            return redirect()->route('ai.index');
        }

        // Batal.
        if ($request->input('setuju') !== 'ya') {
            $thread[] = ['role' => 'assistant', 'content' => 'Oke, dibatalkan. 👍'];
            $request->session()->put(self::THREAD, $thread);

            return redirect()->route('ai.index');
        }

        $tool = $this->tools->find($pending['tool'], $user);
        if (! $tool || ! $tool->isWrite()) {
            $thread[] = ['role' => 'assistant', 'content' => '⚠️ Aksi tak bisa dijalankan (alat tidak tersedia).'];
            $request->session()->put(self::THREAD, $thread);

            return redirect()->route('ai.index');
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

        return redirect()->route('ai.index');
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->session()->forget([self::THREAD, self::PENDING]);

        return redirect()->route('ai.index');
    }
}
