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
    autoResizeInput();
    startBadgeSequence();

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
        
        // 1. Get Values
        const name = $('#chat-name').val().trim();
        const email = $('#chat-email').val().trim();
        // Note: Using #chat-phone based on your PHP file
        const phone = $('#chat-phone').val().trim(); 

        // 2. Validate required fields
        if (!name || !email) {
            alert('Please enter your name and email.');
            return;
        }

        // 3. Phone Validation Regex (Only Numbers 0-9)
        const phoneRegex = /^[0-9]+$/;

        if (phone && !phoneRegex.test(phone)) {
            alert('Please enter a valid phone number (digits only, no spaces or dashes).');
            return;
        }
        
        // 4. Proceed to Save
        $(this).find('button').text('Connecting...').prop('disabled', true);

        $.ajax({
            url: aiChat.ajax_url,
            method: 'POST',
            data: { 
                action: 'ai_chat_save_user', 
                nonce: aiChat.nonce, 
                name: name, 
                email: email, 
                phone: phone // Sending as 'phone' matching the PHP handler
            },
            success: function(response) {
                if (response.success) {
                    sessionId = response.data.session_id;
                    // Save to local storage
                    persistSession({ 
                        session_id: sessionId, 
                        name: name, 
                        email: email, 
                        purpose: phone 
                    });
                    
                    setPrechatVisible(false);
                    $('#ai-chat-user-form button').text('Start Chat').prop('disabled', false);
                    $('#ai-chat-input').trigger('focus');
                    scrollToBottom(true);
                    setTimeout(() => typeMessage(aiChat.welcome_message), 300);
                } else {
                     alert('Error saving user');
                     $('#ai-chat-user-form button').text('Start Chat').prop('disabled', false);
                }
            },
            error: function() {
                alert('Connection error');
                $('#ai-chat-user-form button').text('Start Chat').prop('disabled', false);
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

    $('#ai-chat-send').click(sendMessage);
    $('#ai-chat-input').on('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
    $('#ai-chat-input').on('input', autoResizeInput);

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
        autoResizeInput();
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
            if (finalTranscript) { const input = $('#ai-chat-input'); const spacer = input.val().length > 0 ? ' ' : ''; input.val(input.val() + spacer + finalTranscript).focus(); autoResizeInput(); }
        };
    }
    function toggleVoice(e) {
        e.preventDefault();
        if (location.protocol !== 'https:' && location.hostname !== 'localhost') { alert("Voice input requires HTTPS."); return; }
        if(!recognition) return;
        if(isListening) recognition.stop(); else recognition.start();
    }

    // Helpers
    function startBadgeSequence() {
        const badge = $('#ai-chat-welcome-badge');
        if (!badge.length) return;
        const titleEl = badge.find('.welcome-badge-title');
        const subtitleEl = badge.find('.welcome-badge-subtitle');
        const textWrap = badge.find('.welcome-badge-text');
        if (!titleEl.length || !subtitleEl.length || !textWrap.length) return;

        const baseTitle = titleEl.text().trim() || 'Welcome to AI Assistant';
        const baseSubtitle = subtitleEl.text().trim() || 'How can we help you?';
        const sequence = [baseTitle, baseSubtitle, 'Feel free to ask anything!'];
        if (sequence.length < 2) return;

        let index = 0;
        function applySequence(nextIndex) {
            titleEl.text(sequence[nextIndex]);
            subtitleEl.text(sequence[(nextIndex + 1) % sequence.length]);
        }
        applySequence(index);

        function tick() {
            if (!badge.is(':visible')) return;
            const nextIndex = (index + 1) % sequence.length;
            textWrap.addClass('is-fading');
            setTimeout(() => {
                applySequence(nextIndex);
                textWrap.removeClass('is-fading');
            }, 260);
            index = nextIndex;
        }

        setInterval(tick, 3600);
    }

    function autoResizeInput() {
        const input = $('#ai-chat-input');
        if (!input.length) return;
        input.css('height', 'auto');
        input.css('height', input[0].scrollHeight + 'px');
    }

    function resetMinimize() {
        isMinimized = false;
        $('#ai-chat-window').removeClass('is-minimized');
        $('.ai-chat-minimize').removeClass('is-minimized').attr('aria-label', 'Minimize chat');
    }

    function resetChatUi() {
        clearStoredSession();
        $('#ai-chat-window').fadeOut();
        $('#ai-chat-messages').empty();
        $('#ai-chat-user-form')[0].reset();
        $('#ai-chat-input').val('');
        autoResizeInput();
        $('#ai-chat-user-form button').text('Start Chat').prop('disabled', false);
        $('#ai-chat-button').delay(300).fadeIn();
        resetMinimize();
        setPrechatVisible(true);
    }

    function setPrechatVisible(visible) {
        $('#ai-chat-window').toggleClass('show-prechat', visible);
        if (visible) {
            $('#ai-chat-prechat').css('display', 'flex');
            $('#ai-chat-messages').css('display', 'none');
            $('.ai-chat-input-area').css('display', 'none');
        } else {
            $('#ai-chat-prechat').css('display', 'none');
            $('#ai-chat-messages').css('display', 'flex');
            $('.ai-chat-input-area').css('display', 'flex');
            autoResizeInput();
        }
    }

    function setBooting(booting) {
        const windowEl = $('#ai-chat-window');
        if (booting) {
            windowEl.addClass('ai-chat-booting');
            windowEl.css('visibility', 'hidden');
        } else {
            windowEl.removeClass('ai-chat-booting');
            windowEl.css('visibility', '');
        }
    }

    function openChatOrForm() {
        $('#ai-chat-button').fadeOut(200);
        $('#ai-chat-welcome-badge').fadeOut(200);
        setBooting(true);
        
        const needsPrechat = !sessionId;
        setPrechatVisible(needsPrechat);
        openChatWindow(needsPrechat);
    }

  // Replace the existing openChatWindow function in script.js
function openChatWindow(focusPrechat = false) { 
    resetMinimize();
    const windowEl = $('#ai-chat-window');

    // FIX: Make sure the window is visible
    setBooting(false); 
    
    // 1. Force Flex (Override any jQuery display:block leftovers)
    windowEl.css('display', 'flex');
    
    // 2. Wait 10ms for browser to render 'display:flex', then fade in
    setTimeout(() => {
        windowEl.addClass('active');
        autoResizeInput();
        
        // 3. Handle Focus
        if (focusPrechat) {
            $('#chat-name').trigger('focus');
        } else {
            $('#ai-chat-input').trigger('focus');
        }
    }, 10);

    if (!focusPrechat) {
        scrollToBottom(true);
    }
}
// Close button should behave like minimize toggle
$('.ai-chat-close-chat').click(function() {
    isMinimized = !isMinimized;
    const windowEl = $('#ai-chat-window');
    const btn = $('.ai-chat-minimize');
    windowEl.toggleClass('is-minimized', isMinimized);
    btn.toggleClass('is-minimized', isMinimized);
    btn.attr('aria-label', isMinimized ? 'Restore chat' : 'Minimize chat');
});

    function sendMessage() {
        if (isTyping) return;
        if (!sessionId) {
            setPrechatVisible(true);
            return;
        }
        const input = $('#ai-chat-input');
        const message = input.val().trim();
        if (!message) return;
        input.val('').focus();
        autoResizeInput();
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
        const msgDiv = $(`<div class="chat-message ${role}"><div class="message-bubble"><div class="message-text">${parseMarkdown(text)}</div><div class="message-time">${time}</div></div></div>`);
        $('#ai-chat-messages').append(msgDiv); 
        if(animate) scrollToBottom(true);
    }

    // Typing effect
    function typeMessage(text) {
        text = text || '';
        const time = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        const msgDiv = $(`<div class="chat-message assistant"><div class="message-bubble"><div class="message-text typing-target"></div><div class="message-time">${time}</div></div></div>`);
        $('#ai-chat-messages').append(msgDiv);
        const target = msgDiv.find('.typing-target');
        
        let i = 0;
        let buffer = '';
        function typeChar() { 
            if(i < text.length) { 
                buffer += text.charAt(i);
                target.html(parseMarkdownStreaming(buffer));
                i++; 
                scrollToBottom(false); 
                setTimeout(typeChar, 10); 
            } else {
                target.html(parseMarkdown(text));
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
                                    const msgDiv = $(`<div class="chat-message ${msg.role}"><div class="message-bubble"><div class="message-text">${parseMarkdown(msg.message)}</div><div class="message-time">${time}</div></div></div>`);
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
    function escapeHtmlRaw(text) { return text ? String(text).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])) : ''; }
    function escapeHtml(text) { return text ? escapeHtmlRaw(text).replace(/\n/g, '<br>') : ''; }
    function parseInline(text) {
        const safe = escapeHtmlRaw(text);
        const withBold = safe.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        return withBold.replace(/\*(.+?)\*/g, '<em>$1</em>');
    }
    function parseInlineStreaming(text) {
        let out = '';
        let bold = false;
        let italic = false;
        let i = 0;
        while (i < text.length) {
            if (text[i] === '*' && text[i + 1] === '*') {
                bold = !bold;
                out += bold ? '<strong>' : '</strong>';
                i += 2;
                continue;
            }
            if (text[i] === '*') {
                italic = !italic;
                out += italic ? '<em>' : '</em>';
                i += 1;
                continue;
            }
            out += escapeHtmlRaw(text[i]);
            i += 1;
        }
        if (italic) out += '</em>';
        if (bold) out += '</strong>';
        return out;
    }

    function parseMarkdown(text, inlineParser = parseInline) {
        if (!text) return '';
        const lines = String(text).replace(/\r\n?/g, '\n').split('\n');
        const blocks = [];
        let paragraph = [];
        let listItems = [];

        function flushParagraph() {
            if (!paragraph.length) return;
            const content = paragraph.map(inlineParser).join('<br>');
            blocks.push(`<p>${content}</p>`);
            paragraph = [];
        }

        function flushList() {
            if (!listItems.length) return;
            const items = listItems.map(item => `<li>${inlineParser(item)}</li>`).join('');
            blocks.push(`<ul>${items}</ul>`);
            listItems = [];
        }

        lines.forEach(line => {
            if (line.trim() === '') {
                flushParagraph();
                return;
            }
            const listMatch = line.match(/^\s*[-*]\s+(.+)/);
            if (listMatch) {
                flushParagraph();
                listItems.push(listMatch[1]);
            } else {
                flushList();
                paragraph.push(line);
            }
        });

        flushParagraph();
        flushList();
        return blocks.join('');
    }

    function parseMarkdownStreaming(text) {
        return parseMarkdown(text, parseInlineStreaming);
    }
});
