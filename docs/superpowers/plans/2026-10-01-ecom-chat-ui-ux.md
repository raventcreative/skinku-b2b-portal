# Chat E-commerce UI/UX Implementation Plan

> **For agentic workers:** Implement inline in this session. Do not delegate to subagents.

**Goal:** Make E-commerce Chat easier to identify and use on desktop and mobile, using SKINKU maroon as the primary action color.

**Architecture:** Keep the existing Blade inbox, AJAX thread loading, and chat actions. Update the existing layout SVG icon and ecom-chat Blade views; add a small delegated mobile back action to the existing page script.

**Tech Stack:** Laravel Blade, Tailwind CSS v4, inline SVG, browser JavaScript already in `resources/views/ecom-chat/index.blade.php`.

## Global Constraints

- Keep all existing chat routes, permission checks, and message operations unchanged.
- Reuse existing SKINKU `brand-maroon`, `brand-dark`, `brand-cream`, and `brand-gold` colors.
- Keep the desktop inbox and thread side by side.
- On mobile, selecting a thread hides the inbox; “Kembali ke inbox” restores it without changing conversation state.
- Keep decorative SVGs hidden from assistive technology; interactive controls use native buttons and visible focus styles.
- Build assets with `npm run build` and compile Blade views with `php artisan view:cache`.

---

### Task 1: Give E-commerce Chat a distinct, consistent icon

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

**Interfaces:**
- Keep `navIcon('ecom-chat.index')` as the sidebar icon entry point.
- Keep the header shortcut named “Chat E-commerce” and preserve `#ecomChatBadge`.

- [x] Replace the generic sidebar bubble outline with a compact marketplace-chat SVG motif in the existing `navIcon` map.
- [x] Draw the same motif in the header shortcut, with `aria-hidden="true"`, maroon focus/active styling, and the existing unread badge behavior.
- [x] Confirm there are no route, unread count, or mute-control changes.

### Task 2: Improve inbox scanning and mobile thread navigation

**Files:**
- Modify: `resources/views/ecom-chat/index.blade.php`
- Modify: `resources/views/ecom-chat/_list.blade.php`
- Modify: `resources/views/ecom-chat/_thread.blade.php`

**Interfaces:**
- Existing `window.ecomOpen(element)` continues loading the selected thread.
- Add a delegated `[data-inbox-back]` button handler on `#ecomChatPane`.
- Existing `#ecomList` swap behavior remains available for channel and status filters.

- [x] Add an inbox wrapper id so the script can hide only the left panel on mobile.
- [x] Make the selected row visibly distinct using a white surface, maroon leading border, and current `aria-pressed` state.
- [x] Add a visible TikTok/Shopee channel label to the thread header and keep status labels legible.
- [x] Add a mobile-only “Kembali ke inbox” button in the thread header.
- [x] When `ecomOpen` succeeds on a mobile viewport, hide the inbox and show the thread. When the back button is used, restore the inbox and empty-state panel. Keep the two-panel desktop behavior.
- [x] Preserve the existing message composer at the bottom of the thread and ensure focus styles remain visible.

### Task 3: Verify the view and asset output

**Files:**
- Verify: `resources/views/layouts/app.blade.php`
- Verify: `resources/views/ecom-chat/index.blade.php`
- Verify: `resources/views/ecom-chat/_list.blade.php`
- Verify: `resources/views/ecom-chat/_thread.blade.php`

- [x] Run `php artisan view:cache` and confirm Blade compilation succeeds.
- [x] Run `npm run build` and confirm the Vite build exits successfully.
- [x] Run `git diff --check` and inspect that only chat UI markup/behavior and the required generated assets changed.
- [x] Review responsive styles and inbox/thread transitions against the approved spec: icon identity, maroon active state, channel/status labels, open thread, return to inbox, and composer position.
