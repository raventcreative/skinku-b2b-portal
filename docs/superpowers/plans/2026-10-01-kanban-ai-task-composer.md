# Kanban AI Task Composer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a per-column manual/AI switch that drafts editable Kanban tasks from a natural-language prompt.

**Architecture:** Add a JSON-only prompt endpoint scoped to the bound column and current board, plus an existing-controller save path for the user-reviewed draft. Reuse the configured `AiProvider`, active internal assignee list, and current Kanban route middleware.

**Tech Stack:** Laravel routes/controllers/services, Blade, Tailwind CSS, existing provider abstraction.

## Global Constraints

- Keep the existing manual one-line add-card flow.
- AI prompt generation must not persist a card.
- Validate and authorize all card fields server-side.
- Do not add dependencies or select model/provider in the UI.
- Keep controls keyboard-accessible and responsive.

---

### Task 1: Add scoped AI draft endpoint

**Files:**
- Create: `app/Services/KanbanTaskDraftService.php`
- Modify: `app/Http/Controllers/KanbanController.php`
- Modify: `routes/web.php`

**Interfaces:**
- `KanbanTaskDraftService::draft(BoardColumn $column, string $prompt, Collection $assignees): array`
- Return `title`, `description`, `assignee_user_id`, `due_date`, `priority` or throw a validation-friendly exception.
- `KanbanController::draftCard()` returns JSON and uses the route-bound column's board/column names.

- [x] Validate prompt length and pass only active internal assignees to the service.
- [x] Ask the provider for a JSON draft and reject malformed or out-of-scope assignee IDs.
- [x] Add `POST /kanban-columns/{column}/cards/draft` inside existing Kanban middleware.

### Task 2: Save reviewed draft fields

**Files:**
- Modify: `app/Http/Controllers/KanbanController.php`

- [x] Extend `storeCard()` to validate optional description, assignee, due date, and priority while preserving title-only manual submissions.
- [x] Persist validated fields and audit the same card creation event.

### Task 3: Add per-column composer UI

**Files:**
- Modify: `resources/views/kanban/show.blade.php`

- [x] Add accessible Manual / Agent AI mode buttons per column.
- [x] Keep the existing quick form as the manual panel.
- [x] Add the prompt, loading/error states, and editable draft form for AI mode.
- [x] Post the reviewed draft to the existing create-card route; use scoped data attributes for asynchronous generation.

### Task 4: Verify the feature

**Files:**
- Inspect: `routes/web.php`, `app/Http/Controllers/KanbanController.php`, `app/Services/KanbanTaskDraftService.php`, `resources/views/kanban/show.blade.php`

- [x] Run PHP syntax checks and `php artisan view:cache`.
- [x] Run the frontend build and `git diff --check`.
- [ ] Manually verify both modes, malformed/provider error handling, and that generating a draft alone creates no card.
