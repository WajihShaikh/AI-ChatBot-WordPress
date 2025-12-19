jQuery(document).ready(function($) {
    let sessionId = null;
    let isMinimized = false;
    let isTyping = false;
    let isListening = false;
    let recognition = null;
    
    // Standard Emoji List
    const emojiList = [
        '😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃','😉','😊','😇',
        '🥰','😍','🤩','😘','😗','😙','😚','😋','😛','😜','🤪','😝','🤑',
        '🤗','🤭','🤫','🤔','🤐','🤨','😐','😑','😶','😏','😒','🙄',
        '😬','🤥','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮','🤧',
        '🤠','🥳','😎','🤓','🧐','😕','😟','🙁','😮','😯','😲','😳','🥺',
        '😦','😧','😨','😰','😥','😢','😭','😱','😖','😣','😞','😓','😩',
        '😫','🥱','😤','😡','😠','🤬','😈','👿','💀','☠️','💩','🤡','👻',
        '👽','👾','🤖','😺','😸','😹','😻','😼','😽','🙀','😿','😾','👍',
        '👎','👊','✊','🤛','🤜','🤞','✌️','🤟','🤘','👌','🤏','👈','👉',
        '👆','👇','☝️','✋','🤚','🖐','🖖','👋','🤙','💪','✍️','🙏','🤝'
    ];

    const sessionStorageKey = 'aiChatSession';

    // Initialize
    restoreSession();
    initVoice();

    // ================= EVENTS =================

    // Open Chat
    $('#ai-chat-button, #ai-chat-welcome-badge').click(openChatOrForm);
    $('#ai-chat-welcome-badge').on('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openChatOrForm();
        }
    });

    // Close Badge
    $('.welcome-badge-close').click(function(e) {
        e.stopPropagation();
        $('#ai-chat-welcome-badge').fadeOut();
    });

    // Form Submission
    $('#ai-chat-user-form').submit(function(e) {
        e.preventDefault();
        const name = $('#chat-name').val().trim();
        const email = $('#chat-email').val().trim();
        const purpose = $('#chat-purpose').val();
        
        if (!name || !email || !purpose) return;
        
        $(this).find('button').text('Connecting...').prop('disabled', true);

        $.ajax({
            url: aiChat.ajax_url,
            method: 'POST',
            data: { action: 'ai_chat_save_user', nonce: aiChat.nonce, name: name, email: email, purpose: purpose },
            success: function(response) {
                if (response.success) {
                    sessionId = response.data.session_id;
                    persistSession({ session_id: sessionId, name: name, email: email, purpose: purpose });
                    $('#ai-chat-modal').fadeOut();
                    openChatWindow();
                    setTimeout(() => typeMessage(aiChat.welcome_message), 500);
                } else {
                     alert('Error saving user');
                     $('#ai-chat-user-form button').text('Start Chat').prop('disabled', false);
                }
            }
        });
    });

    // Minimize Logic
    $('.ai-chat-minimize').click(function() {
        isMinimized = !isMinimized;
        const windowEl = $('#ai-chat-window');
        const btn = $(this);

        windowEl.toggleClass('is-minimized', isMinimized);
        btn.toggleClass('is-minimized', isMinimized);
        btn.attr('aria-label', isMinimized ? 'Restore chat' : 'Minimize chat');
    });

    // Close Chat
    $('.ai-chat-close-chat').click(function() {
        if(confirm('End Chat?')) {
            clearStoredSession();
            $('#ai-chat-window').fadeOut();
            $('#ai-chat-messages').empty();
            $('#ai-chat-user-form')[0].reset();
            $('#ai-chat-user-form button').text('Start Chat').prop('disabled', false);
            $('#ai-chat-button').delay(300).fadeIn();
            resetMinimize();
        }
    });

    $('.ai-chat-close').click(function() { 
        $('#ai-chat-modal').fadeOut(); 
        $('#ai-chat-button').fadeIn(); 
    });

    $('#ai-chat-send').click(sendMessage);
    $('#ai-chat-input').keypress(function(e) { if(e.which === 13) sendMessage(); });

    // --- EMOJI LOGIC ---
    $('#ai-chat-emoji-toggle').click(function(e) {
        e.preventDefault(); e.stopPropagation();
        const picker = $('#ai-chat-emoji-picker');
        if(picker.children().length === 0) {
            const items = emojiList.map(em => `<button type="button" class="emoji-item">${em}</button>`).join('');
            picker.html(items);
            if(window.wp && window.wp.emoji && window.wp.emoji.parse) window.wp.emoji.parse(picker[0]);
        }
        picker.toggleClass('show-picker');
    });

    $(document).on('click', '.emoji-item', function(e) {
        e.preventDefault(); e.stopPropagation();
        let emoji = $(this).text();
        const img = $(this).find('img');
        if (img.length > 0) emoji = img.attr('alt'); // Grab alt text if WP made it an image
        const input = $('#ai-chat-input');
        input.val(input.val() + emoji).focus();
    });

    $(document).click(function(e) {
        if (!$(e.target).closest('#ai-chat-emoji-toggle, #ai-chat-emoji-picker').length) {
            $('#ai-chat-emoji-picker').removeClass('show-picker');
        }
    });

    // Voice Logic
    $('#ai-chat-voice-toggle').click(toggleVoice);
    function initVoice() {
        if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
            $('#ai-chat-voice-toggle').hide(); return;
        }
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        recognition = new SpeechRecognition();
        recognition.continuous = false; recognition.interimResults = true; recognition.lang = 'en-US';
        recognition.onstart = function() { isListening = true; $('#ai-chat-voice-toggle').addClass('listening'); $('#ai-chat-input').attr('placeholder', 'Listening...'); };
        recognition.onend = function() { isListening = false; $('#ai-chat-voice-toggle').removeClass('listening'); $('#ai-chat-input').attr('placeholder', 'Type a message...'); };
        recognition.onresult = function(event) {
            let finalTranscript = '';
            for (let i = event.resultIndex; i < event.results.length; ++i) if (event.results[i].isFinal) finalTranscript += event.results[i][0].transcript;
            if (finalTranscript) { const input = $('#ai-chat-input'); const spacer = input.val().length > 0 ? ' ' : ''; input.val(input.val() + spacer + finalTranscript).focus(); }
        };
    }
    function toggleVoice(e) {
        e.preventDefault();
        if (location.protocol !== 'https:' && location.hostname !== 'localhost') { alert("Voice input requires HTTPS."); return; }
        if(!recognition) return;
        if(isListening) recognition.stop(); else recognition.start();
    }

    // Helpers
    function resetMinimize() {
        isMinimized = false;
        $('#ai-chat-window').removeClass('is-minimized');
        $('.ai-chat-minimize').removeClass('is-minimized').attr('aria-label', 'Minimize chat');
    }

    function openChatOrForm() {
        $('#ai-chat-button').fadeOut(200);
        $('#ai-chat-welcome-badge').fadeOut(200);
        
        if (sessionId) {
            openChatWindow();
            scrollToBottom(true);
        } else {
            $('#ai-chat-modal').css('display', 'flex').hide().fadeIn(function() {
                $('#chat-name').trigger('focus');
            });
        }
    }

    function openChatWindow() { 
        resetMinimize();
        $('#ai-chat-window').css('display', 'flex').hide().fadeIn(function() {
            $('#ai-chat-input').trigger('focus');
        });
        scrollToBottom(true); 
    }

    function sendMessage() {
        if (isTyping) return;
        const input = $('#ai-chat-input');
        const message = input.val().trim();
        if (!message) return;
        input.val('').focus();
        $('#ai-chat-emoji-picker').removeClass('show-picker');
        
        addMessage('user', message);
        showTyping();
        isTyping = true;
        
        $.ajax({
            url: aiChat.ajax_url, method: 'POST',
            data: { action: 'ai_chat_send_message', nonce: aiChat.nonce, session_id: sessionId, message: message },
            success: function(res) { 
                removeTyping(); 
                isTyping = false; 
                if(res.success) {
                    typeMessage(res.data.response); 
                } else {
                    addMessage('assistant', 'Error: ' + (res.data || 'Unknown')); 
                }
            },
            error: function() { 
                removeTyping(); 
                isTyping = false; 
                addMessage('assistant', 'Connection error.'); 
            }
        });
    }

    function addMessage(role, text, animate = true) {
        const time = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        const msgDiv = $(`<div class="chat-message ${role}"><div class="message-bubble"><div class="message-text">${escapeHtml(text)}</div><div class="message-time">${time}</div></div></div>`);
        $('#ai-chat-messages').append(msgDiv); 
        if(animate) scrollToBottom(true);
    }

    // Typing effect
    function typeMessage(text) {
        const time = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        const msgDiv = $(`<div class="chat-message assistant"><div class="message-bubble"><div class="message-text typing-target"></div><div class="message-time">${time}</div></div></div>`);
        $('#ai-chat-messages').append(msgDiv);
        const target = msgDiv.find('.typing-target');
        
        let i = 0;
        function typeChar() { 
            if(i < text.length) { 
                target.append(escapeHtml(text.charAt(i))); 
                i++; 
                scrollToBottom(false); 
                setTimeout(typeChar, 10); 
            } else {
                scrollToBottom(true);
            }
        }
        typeChar();
    }

    function showTyping() { 
        const typingMarkup = `
            <div class="chat-message assistant typing-notif">
                <div class="message-bubble">
                    <span class="typing-dots"><span></span><span></span><span></span></span>
                </div>
            </div>`;
        $('#ai-chat-messages').append(typingMarkup); 
        scrollToBottom(true); 
    }
    
    function removeTyping() { 
        $('.typing-notif').remove(); 
    }

    // FIX: Smarter Scrolling
    function scrollToBottom(force = false) { 
        const d = $('#ai-chat-messages');
        const element = d[0];
        const isNearBottom = element.scrollHeight - element.scrollTop - element.clientHeight < 100;
        if (force || isNearBottom) {
            d.stop().animate({ scrollTop: element.scrollHeight }, 100); 
        }
    }

    // FIX: Restore Session retrieves History
    function restoreSession() { 
        const stored = localStorage.getItem(sessionStorageKey); 
        if(stored) { 
            try { 
                const data = JSON.parse(stored); 
                if(data.session_id) {
                    sessionId = data.session_id;
                    $.ajax({
                        url: aiChat.ajax_url,
                        method: 'POST',
                        data: { action: 'ai_chat_load_history', nonce: aiChat.nonce, session_id: sessionId },
                        success: function(res) {
                            if(res.success && res.data.length > 0) {
                                $('#ai-chat-messages').empty();
                                res.data.forEach(msg => {
                                    const time = new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                                    const msgDiv = $(`<div class="chat-message ${msg.role}"><div class="message-bubble"><div class="message-text">${escapeHtml(msg.message)}</div><div class="message-time">${time}</div></div></div>`);
                                    $('#ai-chat-messages').append(msgDiv);
                                });
                                setTimeout(() => scrollToBottom(true), 100);
                            }
                        }
                    });
                }
            } catch(e){} 
        } 
    }

    function persistSession(data) { localStorage.setItem(sessionStorageKey, JSON.stringify(data)); }
    function clearStoredSession() { localStorage.removeItem(sessionStorageKey); sessionId = null; }
    function escapeHtml(text) { return text ? String(text).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])).replace(/\n/g, '<br>') : ''; }
});
