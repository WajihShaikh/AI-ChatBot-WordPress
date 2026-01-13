jQuery(document).ready(function($) {
    const modal = $('#chat-modal');
    const modalContent = $('#chat-messages-content');
    const modalMeta = $('#ai-chat-admin-modal-meta');
    const closeButton = $('#close-modal');

    function openModal() {
        modal.css('display', 'flex').hide().fadeIn(150).attr('aria-hidden', 'false');
        closeButton.trigger('focus');
    }

    function closeModal() {
        modal.fadeOut(150).attr('aria-hidden', 'true');
        modalContent.empty();
        modalMeta.empty();
    }

    function buildTag(label, value) {
        if (!value) return '';
        return `<span class="ai-chat-admin-tag"><span class="ai-chat-admin-tag-label">${label}:</span> ${escapeHtml(value)}</span>`;
    }

    function buildMetaHtml(button) {
        const meta = [];
        meta.push(buildTag('Name', button.data('name')));
        meta.push(buildTag('Email', button.data('email')));
        meta.push(buildTag('Purpose', button.data('purpose')));
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
                    modalContent.html('<div class="ai-chat-admin-empty">Unable to load messages.</div>');
                    alert('Error: ' + res.data);
                }
            },
            error: function() {
                modalContent.html('<div class="ai-chat-admin-empty">Connection error.</div>');
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
                } else {
                    alert('Error deleting');
                    btn.prop('disabled', false).text('Delete');
                }
            },
            error: function() {
                alert('Connection error');
                btn.prop('disabled', false).text('Delete');
            }
        });
    });

    closeButton.click(closeModal);
    modal.on('click', function(e) { if (e.target === this) closeModal(); });
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && modal.is(':visible')) closeModal();
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