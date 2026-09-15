<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use App\Services\EcomChatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EcomChatController extends Controller
{
    public function __construct(private EcomChatService $chat) {}

    public function index()
    {
        $conversations = EcomChatConversation::orderByDesc('last_message_at')->limit(100)->get();

        return view('ecom-chat.index', [
            'conversations' => $conversations,
            'autosend' => $this->chat->autosendEnabled(),
        ]);
    }

    public function show(EcomChatConversation $conversation)
    {
        $conversation->load(['messages' => fn ($q) => $q->orderBy('id')]);

        return view('ecom-chat.show', [
            'conversation' => $conversation,
            'autosend' => $this->chat->autosendEnabled(),
        ]);
    }

    public function send(Request $request, EcomChatConversation $conversation): RedirectResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:4000']]);
        $this->chat->send($conversation, $data['text'], EcomChatMessage::VIA_STAFF);

        return redirect()->route('ecom-chat.show', $conversation)->with('status', 'Balasan terkirim.');
    }

    public function redraft(EcomChatConversation $conversation): RedirectResponse
    {
        $this->chat->processDraft($conversation);

        return redirect()->route('ecom-chat.show', $conversation)->with('status', 'Draft dibuat ulang.');
    }

    public function toggleAutosend(Request $request): RedirectResponse
    {
        $on = $request->input('on') === '1' ? '1' : '0';
        AppSetting::put(AppSetting::ECOM_CHAT_AUTOSEND, $on);

        return redirect()->back()->with('status', $on === '1' ? 'Auto-send DINYALAKAN.' : 'Auto-send DIMATIKAN.');
    }
}
