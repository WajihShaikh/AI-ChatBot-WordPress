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
                        html += `
                            <div class="ai-chat-admin-msg ${roleClass}">
                                <div class="ai-chat-admin-bubble">
                                    <div class="ai-chat-admin-text">${escapeHtml(msg.message)}</div>
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
    
    function escapeHtml(text) {
        return text ? String(text).replace(/[&<>"']/g, function(m) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]; }) : '';
    }
});
