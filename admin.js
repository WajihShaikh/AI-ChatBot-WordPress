jQuery(document).ready(function($) {
    const modal = $('#chat-modal');
    const modalContent = $('#chat-messages-content');
    const modalMeta = $('#ai-chat-admin-modal-meta');
    const closeButton = $('#close-modal');
    let lastFocusedElement = null;

    function responseMessage(payload, fallback) {
        if (payload && typeof payload === 'object' && payload.message) return payload.message;
        if (typeof payload === 'string' && payload) return payload;
        return fallback;
    }

    function showAdminNotice(message, type) {
        const noticeType = type === 'success' ? 'success' : 'error';
        let notice = $('#ai-chat-admin-live-notice');
        if (!notice.length) {
            notice = $('<div id="ai-chat-admin-live-notice" class="notice" role="status" aria-live="polite"><p></p></div>');
            const hero = $('.ai-chat-admin-hero').first();
            if (hero.length) notice.insertAfter(hero);
            else notice.prependTo('.ai-chat-admin');
        }
        notice.stop(true, true)
            .removeClass('notice-success notice-error')
            .addClass('notice-' + noticeType)
            .find('p').text(message).end()
            .show();
        window.setTimeout(function() { notice.fadeOut(200); }, 5000);
    }

    function openModal() {
        lastFocusedElement = document.activeElement;
        modal.css('display', 'flex').hide().fadeIn(150).attr('aria-hidden', 'false');
        closeButton.trigger('focus');
    }

    function closeModal() {
        modal.fadeOut(150).attr('aria-hidden', 'true');
        modalContent.empty();
        modalMeta.empty();
        if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
            lastFocusedElement.focus();
        }
    }

    function buildTag(label, value) {
        if (!value) return '';
        return `<span class="ai-chat-admin-tag"><span class="ai-chat-admin-tag-label">${label}:</span> ${escapeHtml(value)}</span>`;
    }

    function buildMetaHtml(button) {
        const meta = [];
        meta.push(buildTag('Name', button.data('name')));
        meta.push(buildTag('Email', button.data('email')));
        meta.push(buildTag('Phone', button.data('phone')));
        meta.push(buildTag('Date', button.data('date')));
        meta.push(buildTag('Session', button.data('session')));
        return meta.filter(Boolean).join('');
    }

    // View Chat
    $('.view-chat').click(function() {
        const btn = $(this);
        const id = btn.data('session');
        if (!id) return;

        modalMeta.html(buildMetaHtml(btn));
        modalContent.html('<div class="ai-chat-admin-loading">Loading conversation...</div>');
        openModal();

        $.ajax({
            url: aiChatAdmin.ajax_url,
            method: 'POST',
            data: { action: 'ai_chat_get_messages', nonce: aiChatAdmin.nonce, session_id: id },
            success: function(res) {
                if (res.success) {
                    if (!res.data.length) {
                        modalContent.html('<div class="ai-chat-admin-empty">No messages yet.</div>');
                        return;
                    }
                    let html = '';
                    res.data.forEach(msg => {
                        const roleClass = msg.role === 'user' ? 'ai-chat-admin-msg-user' : 'ai-chat-admin-msg-assistant';
                        const time = msg.created_at ? new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : '';
                        
                        // UPDATED: Use the parser function instead of just escaping
                        const formattedMessage = parseMarkdown(msg.message);

                        html += `
                            <div class="ai-chat-admin-msg ${roleClass}">
                                <div class="ai-chat-admin-bubble">
                                    <div class="ai-chat-admin-text">${formattedMessage}</div>
                                    ${time ? `<div class="ai-chat-admin-time">${time}</div>` : ''}
                                </div>
                            </div>`;
                    });
                    modalContent.html(html);
                } else {
                    const message = responseMessage(res.data, 'Unable to load messages.');
                    modalContent.html('<div class="ai-chat-admin-empty">' + escapeHtml(message) + '</div>');
                    showAdminNotice(message, 'error');
                }
            },
            error: function(xhr) {
                const payload = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null;
                const message = responseMessage(payload, 'Connection error. Please try again.');
                modalContent.html('<div class="ai-chat-admin-empty">' + escapeHtml(message) + '</div>');
                showAdminNotice(message, 'error');
            }
        });
    });

    // Delete Chat
    $('.delete-chat').click(function() {
        if (!confirm('Delete this chat permanently?')) return;
        const btn = $(this);
        const id = btn.data('session');
        btn.prop('disabled', true).text('Deleting...');
        
        $.ajax({
            url: aiChatAdmin.ajax_url,
            method: 'POST',
            data: { action: 'ai_chat_delete_chat', nonce: aiChatAdmin.nonce, session_id: id },
            success: function(res) {
                if (res.success) {
                    btn.closest('tr').fadeOut(200, function() { $(this).remove(); });
                    showAdminNotice('Chat deleted successfully.', 'success');
                } else {
                    showAdminNotice(responseMessage(res.data, 'Unable to delete the chat.'), 'error');
                    btn.prop('disabled', false).text('Delete');
                }
            },
            error: function(xhr) {
                const payload = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null;
                showAdminNotice(responseMessage(payload, 'Connection error. Please try again.'), 'error');
                btn.prop('disabled', false).text('Delete');
            }
        });
    });

    closeButton.click(closeModal);
    modal.on('click', function(e) { if (e.target === this) closeModal(); });
    $(document).on('keydown', function(e) {
        if (!modal.is(':visible')) return;
        if (e.key === 'Escape') {
            closeModal();
            return;
        }
        if (e.key === 'Tab') {
            const focusable = modal.find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible');
            if (!focusable.length) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });
    
    // --- Helper Functions ---

    // Basic sanitizer
    function escapeHtml(text) {
        return text ? String(text).replace(/[&<>"']/g, function(m) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]; }) : '';
    }

    // NEW: Markdown Parser
    function parseMarkdown(text) {
        if (!text) return '';

        // 1. Sanitize the HTML first (Security)
        let html = escapeHtml(text);

        // 2. Convert **Bold** text
        // Replaces **text** with <strong>text</strong>
        html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');

        // 3. Convert Bullet Points
        // Looks for a newline (or start of string) followed by * or - and a space
        // Replaces it with a break tag and a bullet character
        html = html.replace(/(^|\n)[ \t]*[\*\-][ \t]+/g, '<br>&bull;&nbsp;');

        // 4. Convert remaining Newlines to <br>
        html = html.replace(/\n/g, '<br>');
        
        // Clean up any leading <br> if it was the first line
        if (html.startsWith('<br>')) {
            html = html.substring(4);
        }

        return html;
    }
});