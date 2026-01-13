(function() {
    if (window.AIChatExternalWidgetLoaded) return;
    window.AIChatExternalWidgetLoaded = true;

    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function getScriptEl() {
        if (document.currentScript) return document.currentScript;
        var scripts = document.getElementsByTagName('script');
        return scripts[scripts.length - 1];
    }

    function escapeHtmlRaw(text) {
        return String(text || '').replace(/[&<>"']/g, function(m) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[m];
        });
    }

    function parseInline(text) {
        var safe = escapeHtmlRaw(text);
        var withBold = safe.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        return withBold.replace(/\*(.+?)\*/g, '<em>$1</em>');
    }

    function parseInlineStreaming(text) {
        var out = '';
        var bold = false;
        var italic = false;
        var i = 0;
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

    function parseMarkdown(text, inlineParser) {
        if (!text) return '';
        var parser = inlineParser || parseInline;
        var lines = String(text).replace(/\r\n?/g, '\n').split('\n');
        var blocks = [];
        var paragraph = [];
        var listItems = [];

        function flushParagraph() {
            if (!paragraph.length) return;
            var content = paragraph.map(parser).join('<br>');
            blocks.push('<p>' + content + '</p>');
            paragraph = [];
        }

        function flushList() {
            if (!listItems.length) return;
            var items = listItems.map(function(item) {
                return '<li>' + parser(item) + '</li>';
            }).join('');
            blocks.push('<ul>' + items + '</ul>');
            listItems = [];
        }

        lines.forEach(function(line) {
            if (line.trim() === '') {
                flushParagraph();
                return;
            }
            var listMatch = line.match(/^\s*[-*]\s+(.+)/);
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

    function createElement(tag, className) {
        var el = document.createElement(tag);
        if (className) el.className = className;
        return el;
    }

    function init() {
        var config = window.AIChatWidgetConfig || {};
        var scriptEl = getScriptEl();
        var scriptSrc = scriptEl && scriptEl.src ? scriptEl.src : '';
        var scriptUrl = scriptSrc ? new URL(scriptSrc, window.location.href) : null;

        var restBase = config.restBase || (scriptUrl ? scriptUrl.origin + '/wp-json/ai-chat/v1' : '');
        if (!restBase) return;
        restBase = restBase.replace(/\/$/, '');

        var key = config.key || (scriptUrl && scriptUrl.searchParams.get('key')) || '';
        var cssUrl = config.cssUrl || '';
        var badgeTitle = config.badgeTitle || 'Welcome to AI Assistant';
        var badgeSubtitle = config.badgeSubtitle || 'How can we help you?';
        var badgeIcon = config.badgeIcon || 'AI';
        var welcomeMessage = config.welcomeMessage || '';
        var widgetTitle = config.widgetTitle || 'AI Chat Support';

        if (document.getElementById('ai-chat-external-root')) return;

        if (cssUrl && !document.querySelector('link[data-ai-chat-widget-css]')) {
            var css = document.createElement('link');
            css.rel = 'stylesheet';
            css.href = cssUrl;
            css.setAttribute('data-ai-chat-widget-css', '1');
            document.head.appendChild(css);
        }

        var host = document.createElement('div');
        host.id = 'ai-chat-external-root';

        var shadow = null;
        var mount = host;
        if (host.attachShadow) {
            shadow = host.attachShadow({ mode: 'open' });
            mount = shadow;
            if (cssUrl) {
                var cssShadow = document.createElement('link');
                cssShadow.rel = 'stylesheet';
                cssShadow.href = cssUrl;
                cssShadow.setAttribute('data-ai-chat-widget-css', '1');
                mount.appendChild(cssShadow);
            }
        }

        var container = document.createElement('div');
        container.innerHTML = [
            '<div id="ai-chat-welcome-badge" role="button" tabindex="0" aria-label="Open chat">',
            '  <span class="welcome-badge-icon"></span>',
            '  <div class="welcome-badge-text">',
            '    <h4 class="welcome-badge-title"></h4>',
            '    <p class="welcome-badge-subtitle"></p>',
            '  </div>',
            '  <button type="button" class="welcome-badge-close" aria-label="Dismiss chat prompt">&times;</button>',
            '</div>',
            '<button id="ai-chat-button" type="button" aria-label="Open chat">',
            '  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">',
            '    <path d="M4 12a8 8 0 0 1 16 0"></path>',
            '    <path d="M4 12v5a2 2 0 0 0 2 2h2v-7H6a2 2 0 0 0-2 2"></path>',
            '    <path d="M20 12v5a2 2 0 0 1-2 2h-2v-7h2a2 2 0 0 1 2 2"></path>',
            '  </svg>',
            '</button>',
            '<div id="ai-chat-window" class="show-prechat" role="region" aria-label="AI chat" style="display:none;">',
            '  <div class="ai-chat-header">',
            '    <div class="ai-chat-title">',
            '      <div class="ai-chat-title-text">',
            '        <span class="ai-chat-title-name"></span>',
            '        <span class="ai-chat-title-status">Online</span>',
            '      </div>',
            '    </div>',
            '    <div class="ai-chat-controls">',
            '      <button type="button" class="ai-chat-control ai-chat-minimize" aria-label="Minimize chat">',
            '        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">',
            '          <path d="M6 9l6 6 6-6"></path>',
            '        </svg>',
            '      </button>',
            '      <button type="button" class="ai-chat-control ai-chat-close-chat" aria-label="End chat">',
            '        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">',
            '          <path d="M6 6l12 12M18 6l-12 12"></path>',
            '        </svg>',
            '      </button>',
            '    </div>',
            '  </div>',
            '  <div id="ai-chat-prechat" class="ai-chat-prechat">',
            '    <div class="ai-chat-prechat-card">',
            '      <div class="ai-chat-prechat-header">',
            '        <h3 class="ai-chat-prechat-title">Start Chat</h3>',
            '        <p class="ai-chat-prechat-subtitle">Tell us a bit about you to begin.</p>',
            '      </div>',
            '      <form id="ai-chat-user-form" class="ai-chat-prechat-form">',
            '        <label class="ai-chat-field">',
            '          <span class="ai-chat-field-label">Name</span>',
            '          <input type="text" id="chat-name" placeholder="Your name" autocomplete="name" required>',
            '        </label>',
            '        <label class="ai-chat-field">',
            '          <span class="ai-chat-field-label">Email</span>',
            '          <input type="email" id="chat-email" placeholder="you@example.com" autocomplete="email" required>',
            '        </label>',
            '        <label class="ai-chat-field">',
            '          <span class="ai-chat-field-label">Phone Number</span>',
            '          <input type="tel" id="chat-phone" placeholder="Phone number (optional)" autocomplete="tel" inputmode="tel">',
            '        </label>',
            '        <button type="submit">Start Chat</button>',
            '      </form>',
            '    </div>',
            '  </div>',
            '  <div id="ai-chat-messages" aria-live="polite"></div>',
            '  <div class="ai-chat-input-area">',
            '    <div id="ai-chat-emoji-picker" style="display:none;"></div>',
            '    <div class="ai-chat-left-actions">',
            '      <button type="button" id="ai-chat-emoji-toggle" class="icon-btn emoji" title="Emojis" aria-label="Insert emoji">',
            '        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">',
            '          <circle cx="12" cy="12" r="9"></circle>',
            '          <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>',
            '          <circle cx="9" cy="10" r="1"></circle>',
            '          <circle cx="15" cy="10" r="1"></circle>',
            '        </svg>',
            '      </button>',
            '    </div>',
            '    <textarea id="ai-chat-input" rows="1" placeholder="Type a message..." autocomplete="off" aria-label="Message"></textarea>',
            '    <div class="ai-chat-right-actions">',
            '      <button type="button" id="ai-chat-voice-toggle" class="icon-btn voice" title="Voice Input" aria-label="Voice input">',
            '        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">',
            '          <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path>',
            '          <path d="M19 10v2a7 7 0 0 1-14 0v-2"></path>',
            '          <line x1="12" y1="19" x2="12" y2="23"></line>',
            '          <line x1="8" y1="23" x2="16" y2="23"></line>',
            '        </svg>',
            '      </button>',
            '      <button id="ai-chat-send" type="button" aria-label="Send message">',
            '        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"></path></svg>',
            '      </button>',
            '    </div>',
            '  </div>',
            '</div>'
        ].join('');

        mount.appendChild(container);
        document.body.appendChild(host);

        var scope = shadow || host;
        var chatButton = scope.querySelector('#ai-chat-button');
        var badge = scope.querySelector('#ai-chat-welcome-badge');
        var badgeIconEl = badge.querySelector('.welcome-badge-icon');
        var badgeTitleEl = badge.querySelector('.welcome-badge-title');
        var badgeSubtitleEl = badge.querySelector('.welcome-badge-subtitle');
        var badgeClose = badge.querySelector('.welcome-badge-close');
        var windowEl = scope.querySelector('#ai-chat-window');
        var minimizeBtn = windowEl.querySelector('.ai-chat-minimize');
        var closeBtn = windowEl.querySelector('.ai-chat-close-chat');
        var messagesEl = scope.querySelector('#ai-chat-messages');
        var inputEl = scope.querySelector('#ai-chat-input');
        var emojiToggle = scope.querySelector('#ai-chat-emoji-toggle');
        var emojiPicker = scope.querySelector('#ai-chat-emoji-picker');
        var voiceToggle = scope.querySelector('#ai-chat-voice-toggle');
        var sendBtn = scope.querySelector('#ai-chat-send');
        var prechatForm = scope.querySelector('#ai-chat-user-form');
        var prechatSection = scope.querySelector('#ai-chat-prechat');
        var inputArea = scope.querySelector('.ai-chat-input-area');
        var titleEl = windowEl.querySelector('.ai-chat-title-name');

        badgeIconEl.textContent = badgeIcon || 'AI';
        badgeTitleEl.textContent = badgeTitle;
        badgeSubtitleEl.textContent = badgeSubtitle;
        titleEl.textContent = widgetTitle;

        var storageKey = 'aiChatExternalSession:' + restBase;
        function getStoredSession() {
            try { return localStorage.getItem(storageKey) || ''; } catch (e) { return ''; }
        }
        function setStoredSession(value) {
            try { localStorage.setItem(storageKey, value); } catch (e) {}
        }
        function clearStoredSession() {
            try { localStorage.removeItem(storageKey); } catch (e) {}
        }

        var sessionId = getStoredSession();
        var isMinimized = false;
        var isTyping = false;
        var badgeDismissed = false;
        var recognition = null;
        var isListening = false;

        var emojiList = [
            '&#x1F600;','&#x1F603;','&#x1F604;','&#x1F606;','&#x1F607;','&#x1F609;','&#x1F60A;','&#x1F60B;',
            '&#x1F60E;','&#x1F60D;','&#x1F618;','&#x1F617;','&#x1F619;','&#x1F61A;','&#x1F642;','&#x1F643;',
            '&#x1F61C;','&#x1F61D;','&#x1F61B;','&#x1F911;','&#x1F913;','&#x1F60F;','&#x1F612;','&#x1F614;',
            '&#x1F613;','&#x1F633;','&#x1F92A;','&#x1F914;','&#x1F634;','&#x1F637;','&#x1F912;','&#x1F915;',
            '&#x1F922;','&#x1F92E;','&#x1F927;','&#x1F635;','&#x1F62A;','&#x1F62D;','&#x1F631;','&#x1F628;',
            '&#x1F62C;','&#x1F620;','&#x1F621;','&#x1F92C;','&#x1F917;','&#x1F64C;','&#x1F44D;','&#x1F44E;',
            '&#x1F64F;','&#x1F91D;','&#x1F4AA;','&#x1F389;','&#x1F380;','&#x1F381;','&#x1F525;','&#x1F4AF;',
            '&#x1F31F;','&#x1F308;','&#x1F340;','&#x1F33C;','&#x1F337;','&#x1F34E;','&#x1F350;','&#x1F355;',
            '&#x1F354;','&#x1F35F;','&#x1F363;','&#x1F37B;','&#x1F37A;','&#x1F3C6;','&#x1F680;','&#x1F4A1;',
            '&#x1F4AC;','&#x1F5A5;','&#x1F4F1;','&#x1F50A;','&#x1F3B5;','&#x1F3AF;','&#x1F4CC;','&#x1F4DD;'
        ];

        function setPrechatVisible(visible) {
            windowEl.classList.toggle('show-prechat', visible);
            if (!prechatSection || !messagesEl || !inputArea) return;
            if (visible) {
                prechatSection.style.display = 'flex';
                messagesEl.style.display = 'none';
                inputArea.style.display = 'none';
            } else {
                prechatSection.style.display = 'none';
                messagesEl.style.display = 'flex';
                inputArea.style.display = 'flex';
            }
        }

        function setWindowVisible(visible) {
            if (visible) {
                windowEl.style.display = 'flex';
                windowEl.style.visibility = 'visible';
                windowEl.style.opacity = '1';
                windowEl.style.pointerEvents = 'auto';
            } else {
                windowEl.style.display = 'none';
                windowEl.style.removeProperty('visibility');
                windowEl.style.removeProperty('opacity');
                windowEl.style.removeProperty('pointer-events');
            }
        }

        function openChat() {
            chatButton.style.display = 'none';
            badge.style.display = 'none';
            windowEl.classList.remove('ai-chat-booting', 'active');
            setPrechatVisible(!sessionId);
            setWindowVisible(true);
            autoResizeInput();
            if (sessionId) {
                inputEl.focus();
                scrollToBottom(true);
            } else {
                var nameInput = scope.querySelector('#chat-name');
                if (nameInput) nameInput.focus();
            }
        }

        function closeChat() {
            setWindowVisible(false);
            chatButton.style.display = '';
            if (!badgeDismissed) badge.style.display = '';
            resetMinimize();
        }

        function resetMinimize() {
            isMinimized = false;
            windowEl.classList.remove('is-minimized');
            minimizeBtn.classList.remove('is-minimized');
            minimizeBtn.setAttribute('aria-label', 'Minimize chat');
        }

        function resetSession() {
            clearStoredSession();
            sessionId = '';
            messagesEl.innerHTML = '';
            if (prechatForm) prechatForm.reset();
            if (inputEl) inputEl.value = '';
            autoResizeInput();
            setPrechatVisible(true);
        }

        function autoResizeInput() {
            if (!inputEl) return;
            inputEl.style.height = 'auto';
            inputEl.style.height = inputEl.scrollHeight + 'px';
        }

        function addMessage(role, text, animate) {
            var time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            var msg = createElement('div', 'chat-message ' + role);
            var bubble = createElement('div', 'message-bubble');
            var textEl = createElement('div', 'message-text');
            textEl.innerHTML = parseMarkdown(text);
            var timeEl = createElement('div', 'message-time');
            timeEl.textContent = time;
            bubble.appendChild(textEl);
            bubble.appendChild(timeEl);
            msg.appendChild(bubble);
            messagesEl.appendChild(msg);
            if (animate) scrollToBottom(true);
        }

        function typeMessage(text) {
            var time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            var msg = createElement('div', 'chat-message assistant');
            var bubble = createElement('div', 'message-bubble');
            var textEl = createElement('div', 'message-text typing-target');
            var timeEl = createElement('div', 'message-time');
            timeEl.textContent = time;
            bubble.appendChild(textEl);
            bubble.appendChild(timeEl);
            msg.appendChild(bubble);
            messagesEl.appendChild(msg);

            text = text || '';
            var i = 0;
            var buffer = '';
            function typeChar() {
                if (i < text.length) {
                    buffer += text.charAt(i);
                    textEl.innerHTML = parseMarkdownStreaming(buffer);
                    i += 1;
                    scrollToBottom(false);
                    setTimeout(typeChar, 10);
                } else {
                    textEl.innerHTML = parseMarkdown(text);
                    scrollToBottom(true);
                }
            }
            typeChar();
        }

        function showTyping() {
            var typing = createElement('div', 'chat-message assistant typing-notif');
            var bubble = createElement('div', 'message-bubble');
            var dots = createElement('span', 'typing-dots');
            dots.innerHTML = '<span></span><span></span><span></span>';
            bubble.appendChild(dots);
            typing.appendChild(bubble);
            messagesEl.appendChild(typing);
            scrollToBottom(true);
        }

        function removeTyping() {
            var typing = messagesEl.querySelector('.typing-notif');
            if (typing) typing.remove();
        }

        function scrollToBottom(force) {
            var element = messagesEl;
            var isNearBottom = element.scrollHeight - element.scrollTop - element.clientHeight < 100;
            if (force || isNearBottom) {
                element.scrollTop = element.scrollHeight;
            }
        }

        function buildEmojiPicker() {
            if (emojiPicker.children.length) return;
            emojiPicker.innerHTML = emojiList.map(function(em) {
                return '<button type="button" class="emoji-item">' + em + '</button>';
            }).join('');
        }

        function request(path, payload) {
            var headers = { 'Content-Type': 'application/json' };
            if (key) headers['X-AI-CHAT-KEY'] = key;
            payload = payload || {};
            if (key) payload.key = key;
            return fetch(restBase + path, {
                method: 'POST',
                headers: headers,
                body: JSON.stringify(payload)
            }).then(function(res) {
                if (!res.ok) throw new Error('Request failed');
                return res.json();
            });
        }

        function sendMessage() {
            if (isTyping) return;
            if (!sessionId) {
                setPrechatVisible(true);
                return;
            }
            var text = inputEl.value.trim();
            if (!text) return;
            inputEl.value = '';
            autoResizeInput();
            emojiPicker.classList.remove('show-picker');
            addMessage('user', text, true);
            showTyping();
            isTyping = true;

            request('/message', { session_id: sessionId, message: text })
                .then(function(data) {
                    removeTyping();
                    isTyping = false;
                    if (data && data.response !== undefined) {
                        typeMessage(data.response);
                    } else {
                        addMessage('assistant', 'Error: Unknown response', true);
                    }
                })
                .catch(function() {
                    removeTyping();
                    isTyping = false;
                    addMessage('assistant', 'Connection error.', true);
                });
        }

        function initVoice() {
            if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
                voiceToggle.style.display = 'none';
                return;
            }
            var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            recognition = new SpeechRecognition();
            recognition.continuous = false;
            recognition.interimResults = true;
            recognition.lang = 'en-US';
            recognition.onstart = function() {
                isListening = true;
                voiceToggle.classList.add('listening');
                inputEl.setAttribute('placeholder', 'Listening...');
            };
            recognition.onend = function() {
                isListening = false;
                voiceToggle.classList.remove('listening');
                inputEl.setAttribute('placeholder', 'Type a message...');
            };
            recognition.onresult = function(event) {
                var finalTranscript = '';
                for (var i = event.resultIndex; i < event.results.length; i += 1) {
                    if (event.results[i].isFinal) finalTranscript += event.results[i][0].transcript;
                }
                if (finalTranscript) {
                    var spacer = inputEl.value.length > 0 ? ' ' : '';
                    inputEl.value = inputEl.value + spacer + finalTranscript;
                    inputEl.focus();
                    autoResizeInput();
                }
            };
        }

        function toggleVoice(e) {
            e.preventDefault();
            if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
                alert('Voice input requires HTTPS.');
                return;
            }
            if (!recognition) return;
            if (isListening) recognition.stop();
            else recognition.start();
        }

        chatButton.addEventListener('click', openChat);
        badge.addEventListener('click', openChat);
        badge.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openChat();
            }
        });
        badgeClose.addEventListener('click', function(e) {
            e.stopPropagation();
            badgeDismissed = true;
            badge.style.display = 'none';
        });

        minimizeBtn.addEventListener('click', function() {
            isMinimized = !isMinimized;
            windowEl.classList.toggle('is-minimized', isMinimized);
            minimizeBtn.classList.toggle('is-minimized', isMinimized);
            minimizeBtn.setAttribute('aria-label', isMinimized ? 'Restore chat' : 'Minimize chat');
        });

        closeBtn.addEventListener('click', function() {
            if (!sessionId || confirm('End Chat?')) {
                resetSession();
                closeChat();
            }
        });

        prechatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var name = scope.querySelector('#chat-name').value.trim();
            var email = scope.querySelector('#chat-email').value.trim();
            var phone = scope.querySelector('#chat-phone').value.trim();
            if (!name || !email) return;

            var submitBtn = prechatForm.querySelector('button[type="submit"]');
            submitBtn.textContent = 'Connecting...';
            submitBtn.disabled = true;

            request('/session', { name: name, email: email, phone: phone })
                .then(function(data) {
                    sessionId = data.session_id;
                    setStoredSession(sessionId);
                    setPrechatVisible(false);
                    autoResizeInput();
                    if (welcomeMessage) {
                        setTimeout(function() { typeMessage(welcomeMessage); }, 300);
                    }
                    inputEl.focus();
                })
                .catch(function() {
                    alert('Unable to start chat.');
                })
                .finally(function() {
                    submitBtn.textContent = 'Start Chat';
                    submitBtn.disabled = false;
                });
        });

        emojiToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            buildEmojiPicker();
            emojiPicker.classList.toggle('show-picker');
        });

        function eventHasNode(node, event) {
            if (!node) return false;
            if (event.composedPath) {
                return event.composedPath().indexOf(node) !== -1;
            }
            return node.contains(event.target);
        }

        document.addEventListener('click', function(e) {
            if (!eventHasNode(emojiToggle, e) && !eventHasNode(emojiPicker, e)) {
                emojiPicker.classList.remove('show-picker');
            }
        });

        emojiPicker.addEventListener('click', function(e) {
            var target = e.target;
            if (target && target.classList.contains('emoji-item')) {
                e.preventDefault();
                inputEl.value = inputEl.value + target.textContent;
                inputEl.focus();
                autoResizeInput();
            }
        });

        sendBtn.addEventListener('click', sendMessage);
        inputEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        inputEl.addEventListener('input', autoResizeInput);

        voiceToggle.addEventListener('click', toggleVoice);
        initVoice();
    }

    onReady(init);
})();
