<?php
/** Customer-only. $user in scope. */
require_once __DIR__ . '/../includes/banner.php';
$extraScripts = ['/assets/js/pages/support.js'];

render_hero_banner(
    $user,
    'Support',
    "Message our support team directly. Replies appear here as soon as they're sent."
);
?>
<div class="mb-6">
    <p class="text-md text-text-secondary">Message our support team directly. Replies appear here as soon as they're sent.</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 min-h-[32rem]">
    <div class="card !p-0 flex flex-col lg:col-span-1">
        <div class="flex items-center justify-between px-5 py-4 border-b border-border">
            <h2 class="card-title">Conversations</h2>
            <button type="button" class="btn-tertiary !px-3 !py-1.5" data-modal-trigger="new-conversation-modal"><?= icon('plus', 'w-4 h-4') ?>New</button>
        </div>
        <ul id="conversation-list" class="flex-1 overflow-y-auto divide-y divide-border">
            <li class="px-5 py-6 text-sm text-text-secondary text-center">Loading…</li>
        </ul>
    </div>

    <div class="card !p-0 flex flex-col lg:col-span-2" id="thread-panel">
        <div id="thread-empty" class="empty-state flex-1">
            <span class="empty-state-icon"><?= icon('support', 'w-6 h-6') ?></span>
            <p class="empty-state-title">No conversation selected</p>
            <p class="empty-state-body">Start a new conversation to reach our support team.</p>
            <button type="button" class="btn-primary" data-modal-trigger="new-conversation-modal">Start a conversation</button>
        </div>

        <div id="thread-active" class="hidden flex-1 flex flex-col min-h-0">
            <div class="px-5 py-4 border-b border-border">
                <h2 id="thread-subject" class="text-lg font-semibold text-text-primary"></h2>
                <span id="thread-status" class="badge-neutral mt-1"></span>
            </div>
            <div id="thread-messages" class="flex-1 overflow-y-auto px-5 py-4 space-y-4" aria-live="polite"></div>
            <form id="reply-form" class="flex items-end gap-3 px-5 py-4 border-t border-border">
                <div class="flex-1">
                    <label for="reply-message" class="sr-only">Your message</label>
                    <textarea id="reply-message" name="message" class="field-input" rows="2" maxlength="4000" placeholder="Type your message…" required></textarea>
                </div>
                <button type="submit" id="reply-submit" class="btn-primary !px-4"><?= icon('send', 'w-4 h-4') ?><span class="sr-only">Send message</span></button>
            </form>
        </div>
    </div>
</div>

<dialog id="new-conversation-modal" class="rounded-md p-0 backdrop:bg-black/40 w-full max-w-md" aria-labelledby="new-conversation-title">
    <form id="new-conversation-form" class="flex flex-col">
        <div class="flex items-start justify-between gap-4 px-6 py-5 border-b border-border">
            <h2 id="new-conversation-title" class="text-3xl font-semibold text-text-primary">New conversation</h2>
            <button type="button" class="btn-icon" data-modal-close aria-label="Close dialog"><?= icon('close', 'w-5 h-5') ?></button>
        </div>
        <div class="px-6 py-5 space-y-4">
            <div>
                <label for="nc-subject" class="field-label">Subject<span class="text-danger" aria-hidden="true"> *</span></label>
                <input type="text" id="nc-subject" name="subject" class="field-input" maxlength="160" required>
            </div>
            <div>
                <label for="nc-message" class="field-label">Message<span class="text-danger" aria-hidden="true"> *</span></label>
                <textarea id="nc-message" name="message" class="field-input" rows="4" maxlength="4000" required></textarea>
            </div>
            <p id="nc-error" class="field-error hidden"></p>
        </div>
        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-border bg-surface-muted rounded-b-md">
            <button type="button" class="btn-secondary" data-modal-close>Cancel</button>
            <button type="submit" id="nc-submit" class="btn-primary">Start conversation</button>
        </div>
    </form>
</dialog>
