# Content Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild Konten as a permission-scoped publish pipeline and monthly calendar with direct publishing/scheduling and no approval flow.

**Architecture:** Keep the existing `ContentPost` and per-platform `ContentPostTarget` records and publisher scheduler. Replace approval transitions with explicit draft/publish actions, add a server-rendered calendar route, group status summaries in the workspace, and migrate legacy review states to safe drafts. Preserve current API/manual platform behavior, retry, audit history, and insights.

**Tech Stack:** Laravel Blade, Eloquent, Tailwind CSS, Carbon, existing Meta/TikTok clients and scheduler.

## Global Constraints

- No approval or rejection in the active content workflow.
- A draft is never publishable by the due-content scheduler.
- Existing creator ownership and admin/super-admin scope are enforced server-side.
- Migrate legacy `in_review` and `rejected` posts to draft without queuing targets.
- Preserve per-platform publishing, TikTok privacy/consent requirements, manual posting, retries, idempotency, and audit history.
- Add no frontend/calendar dependencies.
- Keep SKINKU red, Bahasa Indonesia, accessible labels, and responsive layout.

---

## File map

- Modify `app/Http/Controllers/ContentPostController.php`: pipeline/calendar queries, form data, ownership, direct publish, scoped retry/manual completion.
- Modify `app/Services/ContentPostService.php`: publish transition, validation, target status/options.
- Modify `app/Models/ContentPost.php` and `ContentPostTarget.php`: active workflow status labels/editability.
- Modify `app/Console/Commands/ContentPublishDueCommand.php`: ensure drafts cannot be pulled after the transition change; preserve existing lock/retry behavior.
- Modify `app/Support/Permissions.php`, `routes/web.php`, and `resources/views/layouts/app.blade.php`: replace reviewer access with content management access.
- Rewrite `resources/views/content/index.blade.php`, `dashboard.blade.php`, `_table.blade.php`, `form.blade.php`, and `show.blade.php`; add `calendar.blade.php`.
- Add a migration mapping legacy approval states to draft without mutating target publish state.
- Keep `content/social.blade.php`, insight calculations, and platform clients unless the new flow needs form-level TikTok creator settings.

## Task 1: Replace the product documents

**Files:** `docs/superpowers/specs/2026-09-30-content-pipeline/{BRD,PRD,FRD}.md`

- [ ] Review all three documents against the accepted decision: direct publish/schedule, monthly calendar, no approval, creator/admin scopes, per-platform manual/API behavior, safe legacy migration.
- [ ] Remove references to approval as an active requirement; retain legacy audit history only.

## Task 2: Add safe transition behavior

**Files:** `app/Models/ContentPost.php`, `app/Models/ContentPostTarget.php`, `app/Services/ContentPostService.php`, `app/Http/Controllers/ContentPostController.php`

- [ ] Make new saves draft-only unless the form submits the explicit publish intent.
- [ ] Validate media from uploads or stored media, selected targets, per-platform captions, schedule, and TikTok API options before queueing.
- [ ] On publish, set API targets to `queued`, manual targets to `manual_pending`, reset retry/container state, and recompute post status transactionally.
- [ ] Keep status scoped per target and ensure publishing failures do not alter successful targets.
- [ ] Allow editing only draft or not-yet-started scheduled posts; keep published/in-progress content immutable.
- [ ] Replace authorization that relies on `content.review` with `content.manage` or ownership checks.

## Task 3: Remove approval routes and update permissions

**Files:** `routes/web.php`, `app/Support/Permissions.php`, `resources/views/layouts/app.blade.php`, `app/Http/Controllers/ContentReviewController.php`

- [ ] Add `content.manage` as an admin capability; give `content.publish.manage` to creators for own targets and admins for all targets.
- [ ] Remove active approve/reject/submit/withdraw routes and reviewer navigation.
- [ ] Move retry/manual completion handlers from `ContentReviewController` into `ContentPostController`; keep their routes under authenticated content access and enforce owner-or-manager scope there.
- [ ] Replace the dashboard link with the main content pipeline; retain insight and social connection links.
- [ ] Delete the unused approval controller after verifying no active route references it.

## Task 4: Migrate old approval records safely

**Files:** new migration under `database/migrations`

- [ ] Update `content_posts.status` from `in_review` and `rejected` to `draft`.
- [ ] Do not set any `content_post_targets.status` to queued in this migration.
- [ ] Preserve reviewer fields, review notes, timestamps, and audit log records as historical data.
- [ ] Make the migration safe to roll back only if the old status mapping is still unambiguous; otherwise document a no-op rollback that does not overwrite newer changes.

## Task 5: Rebuild the Content workspace and calendar

**Files:** `resources/views/content/index.blade.php`, `_table.blade.php`, `dashboard.blade.php`, new `calendar.blade.php`, `app/Http/Controllers/ContentPostController.php`, `routes/web.php`

- [ ] Make `/content` the pipeline landing page with status groups, compact content rows/cards, filters and clear create/detail actions.
- [ ] Add `/content/calendar` with validated month navigation, server-rendered Monday-first month grid, and links from each item to detail.
- [ ] Keep creator results scoped to owner; show creator identity/filter only to managers.
- [ ] Retain insight page access separately and remove redundant approval dashboard cards.
- [ ] Make empty, loading/error, and overflow behavior clear at phone and desktop widths.

## Task 6: Rebuild compose and detail screens for direct publish

**Files:** `resources/views/content/form.blade.php`, `show.blade.php`, `ContentPostController.php`, `ContentPostService.php`, `ContentPublisher.php`

- [ ] Replace review wording and reviewer notes with creator scheduling/publishing language.
- [ ] Keep media/caption/platform validation and add TikTok creator privacy/consent inputs to the compose form when direct API posting is available.
- [ ] Provide explicit Save Draft and Publish/Schedule actions; derive queue time from `scheduled_at`.
- [ ] Remove approval/rejection UI from detail; expose owner-scoped retry/manual permalink completion and keep platform status, links, metrics, and audit history.
- [ ] Ensure no TikTok API target is queued without required privacy/consent options.

## Task 7: Inspect and verify the finished flow

**Files:** changed files above.

- [ ] Run `php artisan view:cache`, `bun run build`, and `git diff --check`.
- [ ] Review generated route list and search for active content approve/reject route references.
- [ ] Inspect diff for permission leaks, accidental scheduler eligibility of drafts, and responsive/accessibility regressions.
- [ ] Do not deploy, create a PR, or publish externally.

## Spec coverage review

- BRD direct workflow and legacy safety: Tasks 2–4.
- PRD workspace/calendar and creator/admin journeys: Tasks 3–6.
- FRD validation, per-platform publish, permissions, responsive/accessibility: Tasks 2–7.
