<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use App\Models\TiktokOrder;
use App\Services\EcomChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class EcomChatController extends Controller
{
    public function __construct(private EcomChatService $chat) {}

    public function index(Request $request)
    {
        $tab = $request->query('tab') === 'perlu' ? 'perlu' : 'semua';

        $query = EcomChatConversation::query()->orderByDesc('last_message_at');
        if ($tab === 'perlu') {
            // "Perlu dibalas" = ada pesan pembeli asli (last_incoming_at terisi) & belum ditutup.
            $query->whereNotNull('last_incoming_at')->where('status', '!=', EcomChatConversation::STATUS_CLOSED);
        }

        return view('ecom-chat.index', [
            'conversations' => $query->limit(100)->get(),
            'autosend' => $this->chat->autosendEnabled(),
            'tab' => $tab,
            'perluCount' => EcomChatConversation::whereNotNull('last_incoming_at')
                ->where('status', '!=', EcomChatConversation::STATUS_CLOSED)->count(),
        ]);
    }

    public function sync(): RedirectResponse
    {
        try {
            $res = $this->chat->importFromTikTok();

            return redirect()->route('ecom-chat.index')->with('status', "Tarik chat selesai: {$res['conversations']} percakapan, {$res['messages']} pesan baru.");
        } catch (\Throwable $e) {
            return redirect()->route('ecom-chat.index')->with('error', 'Gagal tarik chat: '.$e->getMessage());
        }
    }

    public function show(Request $request, EcomChatConversation $conversation)
    {
        $this->chat->markRead($request->user(), $conversation);

        return view('ecom-chat.show', $this->threadData($conversation) + ['autosend' => $this->chat->autosendEnabled()]);
    }

    /** Panel thread (AJAX) — dipakai layout 2-panel di inbox tanpa reload halaman. */
    public function thread(Request $request, EcomChatConversation $conversation)
    {
        $this->chat->markRead($request->user(), $conversation);

        return view('ecom-chat._thread', $this->threadData($conversation));
    }

    /**
     * Data thread: pesan urut + order TikTok tersinkron (kartu kaya), tanpa N+1.
     *
     * @return array{conversation: EcomChatConversation, orders: Collection}
     */
    private function threadData(EcomChatConversation $conversation): array
    {
        $conversation->load(['messages' => fn ($q) => $q->orderBy('id')]);
        $orderIds = $conversation->messages
            ->map(fn ($m) => $m->meta['order_id'] ?? null)
            ->filter()->unique()->values();
        $orders = $orderIds->isNotEmpty()
            ? TiktokOrder::whereIn('tiktok_order_id', $orderIds)->get()->keyBy('tiktok_order_id')
            : collect();

        return ['conversation' => $conversation, 'orders' => $orders];
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->chat->unreadCountFor($request->user())]);
    }

    public function send(Request $request, EcomChatConversation $conversation)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:4000']]);
        $this->chat->send($conversation, $data['text'], EcomChatMessage::VIA_STAFF);

        if ($request->hasHeader('X-Requested-With')) {
            $this->chat->markRead($request->user(), $conversation);

            return view('ecom-chat._thread', $this->threadData($conversation->fresh()));
        }

        return redirect()->route('ecom-chat.show', $conversation)->with('status', 'Balasan terkirim.');
    }

    public function redraft(Request $request, EcomChatConversation $conversation)
    {
        $this->chat->processDraft($conversation);

        if ($request->hasHeader('X-Requested-With')) {
            return view('ecom-chat._thread', $this->threadData($conversation->fresh()));
        }

        return redirect()->route('ecom-chat.show', $conversation)->with('status', 'Draft dibuat ulang.');
    }

    public function toggleAutosend(Request $request): RedirectResponse
    {
        $on = $request->input('on') === '1' ? '1' : '0';
        AppSetting::put(AppSetting::ECOM_CHAT_AUTOSEND, $on);

        return redirect()->back()->with('status', $on === '1' ? 'Auto-send DINYALAKAN.' : 'Auto-send DIMATIKAN.');
    }
}
