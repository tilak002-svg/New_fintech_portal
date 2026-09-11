(function () {
    'use strict';
    const { apiFetch, showToast, setButtonLoading, escapeHtml, formatIST } = window.Verapay;

    const POLL_INTERVAL_MS = 4000;
    let activeConversationId = null;
    let lastMessageId = 0;
    let pollTimer = null;

    function timeLabel(iso) {
        return formatIST(iso, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function avatarMarkup(id, gender, fallbackInitial) {
        const g = (gender === 'male' || gender === 'female') ? gender : (Number(id) % 2 === 0 ? 'female' : 'male');
        const folder = g === 'male' ? 'men' : 'women';
        const idx = Number(id) % 100;
        const initial = escapeHtml(fallbackInitial || '?');
        return `<span class="avatar-chip-sm !bg-transparent relative shrink-0">
            <img src="https://randomuser.me/api/portraits/${folder}/${idx}.jpg" alt="" class="absolute inset-0 w-full h-full rounded-full object-cover" loading="lazy" referrerpolicy="no-referrer"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <span class="hidden absolute inset-0 rounded-full items-center justify-center bg-brand-muted text-brand-emphasis">${initial}</span>
        </span>`;
    }

    function messageBubble(m) {
        const isMine = m.sender_role !== 'customer';
        return `
            <div class="flex items-end gap-2.5 ${isMine ? 'justify-end' : 'justify-start'}">
                ${!isMine ? avatarMarkup(m.sender_id, m.sender_gender, (m.sender_name || 'C').slice(0, 1)) : ''}
                <div class="max-w-[80%] rounded-md px-4 py-2.5 ${isMine ? 'bg-brand text-white' : 'bg-surface-muted text-text-primary'}">
                    <span class="block text-xs font-semibold ${isMine ? 'text-white/80' : 'text-text-secondary'} mb-0.5">${escapeHtml(m.sender_name)}${isMine ? '' : ' · Customer'}</span>
                    <span class="block text-md whitespace-pre-wrap break-words">${escapeHtml(m.message)}</span>
                    <span class="block text-xs mt-1 ${isMine ? 'text-white/70' : 'text-text-secondary'}">${timeLabel(m.created_at)}</span>
                </div>
            </div>`;
    }

    async function loadConversations() {
        const list = document.getElementById('conversation-list');
        const { success, data } = await apiFetch('/api/support/conversations.php');
        if (!success || !data.conversations.length) {
            list.innerHTML = `<li class="empty-state !py-10">
                <span class="empty-state-icon"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5h16v10H9.5L5 19v-3.5H4Z"/><path d="M8 9.5h8M8 12.5h5"/></svg></span>
                <p class="empty-state-body !mb-0">No conversations yet.</p>
            </li>`;
            return;
        }
        list.innerHTML = data.conversations.map((c) => `
            <li>
                <button type="button" data-conversation-id="${c.id}" class="conversation-item w-full text-left px-5 py-4 hover:bg-surface-muted ${c.id === activeConversationId ? 'bg-brand-muted/40' : ''}">
                    <span class="flex items-center gap-3">
                        ${avatarMarkup(c.user_id, c.user_gender, (c.user_name || '?').slice(0, 1))}
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center justify-between gap-2">
                                <span class="text-md font-semibold text-text-primary truncate">${escapeHtml(c.user_name)}</span>
                                ${c.unread_count > 0 ? `<span class="badge-dot text-brand shrink-0" aria-label="${c.unread_count} unread"></span>` : ''}
                            </span>
                            <span class="block text-sm text-text-primary truncate mt-0.5">${escapeHtml(c.subject)}</span>
                            <span class="block text-sm text-text-secondary truncate">${escapeHtml(c.last_message || '')}</span>
                        </span>
                    </span>
                </button>
            </li>`).join('');

        list.querySelectorAll('.conversation-item').forEach((btn) => {
            btn.addEventListener('click', () => {
                const conv = data.conversations.find((c) => c.id === parseInt(btn.dataset.conversationId, 10));
                if (conv) selectConversation(conv);
            });
        });
    }

    function selectConversation(conv) {
        activeConversationId = conv.id;
        lastMessageId = 0;
        document.getElementById('thread-empty').classList.add('hidden');
        document.getElementById('thread-active').classList.remove('hidden');
        document.getElementById('thread-subject').textContent = conv.subject;
        document.getElementById('thread-customer').textContent = `${conv.user_name} · ${conv.user_email}`;
        document.getElementById('thread-messages').innerHTML = '<p class="text-sm text-text-secondary text-center py-6">Loading messages…</p>';

        document.querySelectorAll('.conversation-item').forEach((btn) => {
            btn.classList.toggle('bg-brand-muted/40', parseInt(btn.dataset.conversationId, 10) === conv.id);
        });

        loadMessages(true);
        restartPolling();
    }

    async function loadMessages(replace = false) {
        if (!activeConversationId) return;
        const { success, data } = await apiFetch(`/api/support/messages.php?conversation_id=${activeConversationId}&after_id=${replace ? 0 : lastMessageId}`);
        if (!success) return;

        const container = document.getElementById('thread-messages');
        if (replace) container.innerHTML = '';
        if (!data.messages.length && replace) {
            container.innerHTML = '<p class="text-sm text-text-secondary text-center py-6">No messages yet.</p>';
            return;
        }
        if (data.messages.length) {
            container.innerHTML += data.messages.map(messageBubble).join('');
            lastMessageId = data.messages[data.messages.length - 1].id;
            container.scrollTop = container.scrollHeight;
        }
    }

    function restartPolling() {
        clearInterval(pollTimer);
        pollTimer = setInterval(() => {
            if (document.visibilityState === 'visible') loadMessages(false);
        }, POLL_INTERVAL_MS);
    }
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') loadMessages(false);
    });

    document.getElementById('reply-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const textarea = document.getElementById('reply-message');
        const message = textarea.value.trim();
        if (!message || !activeConversationId) return;

        const submitBtn = document.getElementById('reply-submit');
        setButtonLoading(submitBtn, true);
        const { success, message: msg } = await apiFetch('/api/support/messages.php', {
            method: 'POST',
            body: { conversation_id: activeConversationId, message },
        });
        setButtonLoading(submitBtn, false);

        if (!success) {
            showToast(msg || 'Unable to send reply.', 'error');
            return;
        }
        textarea.value = '';
        loadMessages(false);
        loadConversations();
    });

    loadConversations();
    setInterval(loadConversations, 15000);
})();
