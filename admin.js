jQuery(document).ready(function($) {
    // View Chat
    $('.view-chat').click(function() {
        const id = $(this).data('session');
        $.ajax({
            url: aiChatAdmin.ajax_url,
            method: 'POST',
            data: { action: 'ai_chat_get_messages', nonce: aiChatAdmin.nonce, session_id: id },
            success: function(res) {
                if (res.success) {
                    let html = '<div style="max-height:60vh; overflow-y:auto; padding:10px;">';
                    if (!res.data.length) html += '<p>No messages.</p>';
                    else {
                        res.data.forEach(msg => {
                            const bg = msg.role === 'user' ? '#0073aa' : '#f0f0f0';
                            const col = msg.role === 'user' ? '#fff' : '#333';
                            const align = msg.role === 'user' ? 'right' : 'left';
                            html += `<div style="text-align:${align}; margin-bottom:10px;">
                                <div style="display:inline-block; padding:10px; background:${bg}; color:${col}; border-radius:10px;">${escapeHtml(msg.message)}</div>
                            </div>`;
                        });
                    }
                    html += '</div>';
                    $('#chat-messages-content').html(html);
                    $('#chat-modal').fadeIn();
                } else alert('Error: ' + res.data);
            }
        });
    });

    // Delete Chat
    $('.delete-chat').click(function() {
        if (!confirm('Delete this chat permanently?')) return;
        const btn = $(this);
        const id = btn.data('session');
        btn.prop('disabled', true).text('...');
        
        $.ajax({
            url: aiChatAdmin.ajax_url,
            method: 'POST',
            data: { action: 'ai_chat_delete_chat', nonce: aiChatAdmin.nonce, session_id: id },
            success: function(res) {
                if (res.success) btn.closest('tr').fadeOut();
                else { alert('Error deleting'); btn.prop('disabled', false).text('Delete'); }
            }
        });
    });

    $('#close-modal').click(function() { $('#chat-modal').fadeOut(); });
    
    function escapeHtml(text) {
        return text ? String(text).replace(/[&<>"']/g, function(m) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]; }) : '';
    }
});