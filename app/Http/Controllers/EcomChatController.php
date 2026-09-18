<?php

namespace App\Http\Controllers;

use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use App\Models\ShopeeProduct;
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
        $valid = ['perlu', 'belum_dibaca', 'ditandai', 'terbalas', 'ditutup', 'semua'];
        $tab = in_array($request->query('tab'), $valid, true) ? $request->query('tab') : 'perlu';
        $channels = ['tiktok', 'shopee'];
        $channel = in_array($request->query('channel'), $channels, true) ? $request->query('channel') : 'tiktok';
        $user = $request->user();

        // Inbox dipisah per channel (TikTok / Shopee) — tab channel di UI.
        $scoped = fn () => EcomChatConversation::query()->where('channel', $channel);

        $query = $scoped()->orderByDesc('last_message_at');
        match ($tab) {
            'perlu' => $query->needsReply(),
            'belum_dibaca' => $query->unreadFor($user),
            'ditandai' => $query->where('flagged', true),
            'terbalas' => $query->where('status', EcomChatConversation::STATUS_REPLIED),
            'ditutup' => $query->where('status', EcomChatConversation::STATUS_CLOSED),
            default => $query,
        };

        $data = [
            'conversations' => $query->limit(100)->get(),
            'autosend' => $this->chat->autosendEnabled($channel),
            'tab' => $tab,
            'channel' => $channel,
            'perluCount' => $scoped()->needsReply()->count(),
            'unreadCount' => $scoped()->unreadFor($user)->count('ecom_chat_conversations.id'),
            'flaggedCount' => $scoped()->where('flagged', true)->count(),
            // Badge "perlu dibalas" per channel utk tab channel.
            'tiktokPerlu' => EcomChatConversation::query()->where('channel', 'tiktok')->needsReply()->count(),
            'shopeePerlu' => EcomChatConversation::query()->where('channel', 'shopee')->needsReply()->count(),
        ];

        // Ganti tab = swap daftar via AJAX (tanpa reload halaman penuh).
        if ($request->hasHeader('X-Requested-With')) {
            return view('ecom-chat._list', $data);
        }

        return view('ecom-chat.index', $data);
    }

    public function sync(Request $request): RedirectResponse
    {
        $channel = $request->input('channel') === 'shopee' ? 'shopee' : 'tiktok';
        try {
            $res = $channel === 'shopee' ? $this->chat->importFromShopee() : $this->chat->importFromTikTok();

            return redirect()->route('ecom-chat.index', ['channel' => $channel])->with('status', "Tarik chat selesai: {$res['conversations']} percakapan.");
        } catch (\Throwable $e) {
            return redirect()->route('ecom-chat.index', ['channel' => $channel])->with('error', 'Gagal tarik chat: '.$e->getMessage());
        }
    }

    public function show(Request $request, EcomChatConversation $conversation)
    {
        $this->chat->markRead($request->user(), $conversation);

        return view('ecom-chat.show', $this->threadData($conversation) + ['autosend' => $this->chat->autosendEnabled($conversation->channel)]);
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
        $productIds = $conversation->messages
            ->map(fn ($m) => $m->meta['product_id'] ?? null)
            ->filter()->unique()->values();

        if ($conversation->channel === 'shopee') {
            // Shopee: kartu produk dari cache ShopeeProduct; tak ada kartu pesanan.
            $products = $productIds->isNotEmpty()
                ? ShopeeProduct::whereIn('item_id', $productIds)->get()->keyBy('item_id')
                : collect();

            return ['conversation' => $conversation, 'orders' => collect(), 'products' => $products];
        }

        // TikTok: kartu pesanan (order TikTok tersinkron) + kartu produk via Products API.
        $orderIds = $conversation->messages
            ->map(fn ($m) => $m->meta['order_id'] ?? null)
            ->filter()->unique()->values();
        $orders = $orderIds->isNotEmpty()
            ? TiktokOrder::whereIn('tiktok_order_id', $orderIds)->get()->keyBy('tiktok_order_id')
            : collect();
        $products = $this->chat->resolveProducts($productIds);

        return ['conversation' => $conversation, 'orders' => $orders, 'products' => $products];
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->chat->unreadCountFor($request->user())]);
    }

    public function send(Request $request, EcomChatConversation $conversation)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:4000']]);

        $channelName = $conversation->channel === 'shopee' ? 'Shopee' : 'TikTok';
        try {
            $this->chat->send($conversation, $data['text'], EcomChatMessage::VIA_STAFF);
        } catch (\Throwable $e) {
            // Jangan gagal senyap: tampilkan alasan (mis. error API channel) ke staf.
            if ($request->hasHeader('X-Requested-With')) {
                return response("Gagal kirim ke {$channelName}: ".$e->getMessage(), 422);
            }

            return redirect()->route('ecom-chat.show', $conversation)->with('error', "Gagal kirim ke {$channelName}: ".$e->getMessage());
        }

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

    public function close(Request $request, EcomChatConversation $conversation)
    {
        $conversation->update(['status' => EcomChatConversation::STATUS_CLOSED]);

        if ($request->hasHeader('X-Requested-With')) {
            return view('ecom-chat._thread', $this->threadData($conversation->fresh()));
        }

        return redirect()->route('ecom-chat.show', $conversation)->with('status', 'Percakapan ditutup.');
    }

    public function toggleFlag(Request $request, EcomChatConversation $conversation)
    {
        $conversation->update(['flagged' => ! $conversation->flagged]);

        if ($request->hasHeader('X-Requested-With')) {
            return view('ecom-chat._thread', $this->threadData($conversation->fresh()));
        }

        return redirect()->route('ecom-chat.show', $conversation)->with('status', $conversation->flagged ? 'Chat ditandai.' : 'Tanda dilepas.');
    }

    public function reopen(Request $request, EcomChatConversation $conversation)
    {
        // Buka lagi → open; kalau pembeli memang menunggu, otomatis balik ke "Perlu dibalas".
        $conversation->update(['status' => EcomChatConversation::STATUS_OPEN]);

        if ($request->hasHeader('X-Requested-With')) {
            return view('ecom-chat._thread', $this->threadData($conversation->fresh()));
        }

        return redirect()->route('ecom-chat.show', $conversation)->with('status', 'Percakapan dibuka lagi.');
    }

    public function toggleAutosend(Request $request): RedirectResponse
    {
        $channel = $request->input('channel') === 'shopee' ? 'shopee' : 'tiktok';
        $on = $request->input('on') === '1';
        $this->chat->setAutosend($channel, $on);
        $name = $channel === 'shopee' ? 'Shopee' : 'TikTok';

        return redirect()->route('ecom-chat.index', ['channel' => $channel])
            ->with('status', $on ? "Auto-send {$name} DINYALAKAN." : "Auto-send {$name} DIMATIKAN.");
    }
}
