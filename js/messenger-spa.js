(function() {
    // ---------- СОСТОЯНИЕ ----------
    const state = {
        userId: null,
        chats: [],
        activeChatId: null,
        messagesCache: {},
        ws: null,
        wsReconnectAttempts: 0,
        maxWsReconnectAttempts: 3,
        useWebSocket: false,
        pollControllers: {},
        editingMessageId: null,
        backgroundTimer: null
    };

    const $ = (sel) => document.querySelector(sel);
    const chatListContainer = $('#chat-list-container');
    const chatViewPanel = $('#chat-view-panel');
    const searchInput = $('#messengerSearch');

    // ---------- МИНИМАЛИСТИЧНЫЕ SVG-ИКОНКИ ДЛЯ МЕНЮ ----------
    const icons = {
        edit: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>',
        delete: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
        copy: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>'
    };

    // ---------- СТИЛИ ДЛЯ МЕНЮ ----------
    const style = document.createElement('style');
    style.textContent = `
        .msg-actions-menu {
            position: absolute; background: #fff; border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12); min-width: 180px; z-index: 110;
            overflow: hidden; opacity: 0; visibility: hidden; transform: translateY(-8px);
            transition: opacity 0.2s, visibility 0.2s, transform 0.2s;
        }
        .msg-actions-menu.active { opacity: 1; visibility: visible; transform: translateY(0); }
        .msg-actions-menu__item {
            display: flex; align-items: center; gap: 10px; padding: 10px 14px;
            font-size: 0.9em; color: #1e1e2f; cursor: pointer; transition: background 0.15s;
            border: none; background: none; width: 100%; text-align: left;
        }
        .msg-actions-menu__item:hover { background: #f5f6fa; }
        .msg-actions-menu__item--danger { color: #b91c1c; }
    `;
    document.head.appendChild(style);

    // ---------- ИНИЦИАЛИЗАЦИЯ ----------
    async function init() {
        state.userId = window.currentUserId || null;
        if (!state.userId) return;
        await loadChats();
        initRouter();
        initSearch();
        connectWebSocket();
        requestNotificationPermission();
        startBackgroundRefresh();
    }

    // ---------- API-ЗАПРОСЫ ----------
    async function apiGet(url) { return kop.get(url); }
    async function apiPost(url, data) { return kop.post(url, data); }
    async function apiPut(url, data) {
        const csrf = document.querySelector('input[name="_csrf"]')?.value;
        const headers = { 'Content-Type': 'application/json' };
        if (csrf) headers['X-CSRF-Token'] = csrf;
        const res = await fetch(url, { method: 'PUT', headers, body: JSON.stringify(data) });
        return res.json();
    }
    async function apiDelete(url) {
        const csrf = document.querySelector('input[name="_csrf"]')?.value;
        const headers = {};
        if (csrf) headers['X-CSRF-Token'] = csrf;
        const res = await fetch(url, { method: 'DELETE', headers });
        return res.json();
    }

    // ---------- ЗАГРУЗКА ЧАТОВ (полная, только при старте) ----------
    async function loadChats() {
        try {
            const data = await apiGet('/api/chats');
            state.chats = data.chats || [];
        } catch (e) {
            console.error('Ошибка загрузки чатов:', e);
            state.chats = [];
        }
        renderChatList();
    }

    function renderChatList() {
        let html = '';
        if (state.chats.length) {
            html += state.chats.map(chat => `
                <div class="chatUnit ${chat.chat_id == state.activeChatId ? 'active' : ''}" data-chat-id="${chat.chat_id}">
                    <img class="chatUnitAvatar" src="${kop.esc(chat.avatar || '')}" alt="">
                    <div class="chatUnitContent">
                        <div class="chatUnitUsername"><p>${kop.esc(chat.first_name)} ${kop.esc(chat.last_name)}</p></div>
                        <div class="chatUnitPreview">
                            <p>${kop.esc(chat.last_message || 'Нет сообщений')} <span class="unread-badge" style="display:${chat.unread_count > 0 ? 'inline' : 'none'}">(${chat.unread_count})</span></p>
                        </div>
                    </div>
                </div>
            `).join('');
        }
        chatListContainer.innerHTML = html || '<p style="text-align:center;color:#8b8fa3;">Нет активных чатов</p>';
    }

    // ---------- ТОЧЕЧНОЕ ОБНОВЛЕНИЕ ПРЕВЬЮ ----------
    function updateChatLocally(content, chatId, otherUserId = null) {
        const unit = document.querySelector(`[data-chat-id="${chatId}"]`);
        if (!unit) {
            if (otherUserId) {
                apiGet(`/api/users/${otherUserId}`).then(user => {
                    const newChat = {
                        chat_id: chatId,
                        other_user_id: otherUserId,
                        first_name: user.first_name,
                        last_name: user.last_name,
                        avatar: user.avatar || '',
                        last_message: content,
                        unread_count: 0
                    };
                    state.chats.unshift(newChat);
                    // Добавляем только одну карточку, не трогая весь список
                    const html = `
                        <div class="chatUnit" data-chat-id="${chatId}">
                            <img class="chatUnitAvatar" src="${kop.esc(user.avatar || '')}" alt="">
                            <div class="chatUnitContent">
                                <div class="chatUnitUsername"><p>${kop.esc(user.first_name)} ${kop.esc(user.last_name)}</p></div>
                                <div class="chatUnitPreview">
                                    <p>${kop.esc(content)} <span class="unread-badge" style="display:none"></span></p>
                                </div>
                            </div>
                        </div>
                    `;
                    chatListContainer.insertAdjacentHTML('afterbegin', html);
                }).catch(() => {
                    const newChat = {
                        chat_id: chatId,
                        other_user_id: otherUserId,
                        first_name: 'Пользователь',
                        last_name: '',
                        avatar: '',
                        last_message: content,
                        unread_count: 0
                    };
                    state.chats.unshift(newChat);
                    const html = `
                        <div class="chatUnit" data-chat-id="${chatId}">
                            <div class="chatUnitAvatar" style="background:#f0f0f0;display:flex;align-items:center;justify-content:center;font-weight:600;color:#3b5dd3;">?</div>
                            <div class="chatUnitContent">
                                <div class="chatUnitUsername"><p>Пользователь</p></div>
                                <div class="chatUnitPreview">
                                    <p>${kop.esc(content)} <span class="unread-badge" style="display:none"></span></p>
                                </div>
                            </div>
                        </div>
                    `;
                    chatListContainer.insertAdjacentHTML('afterbegin', html);
                });
            }
            return;
        }
        const previewP = unit.querySelector('.chatUnitPreview p');
        if (previewP) {
            const badge = previewP.querySelector('.unread-badge');
            if (content === null) {
                previewP.innerHTML = 'Нет сообщений';
            } else {
                const escaped = kop.esc(content);
                previewP.innerHTML = badge
                    ? `${escaped} <span class="unread-badge" style="display:${badge.style.display}">${badge.textContent}</span>`
                    : escaped;
            }
        }
    }

    function updateUnreadBadge(chatId, increment = false, reset = false) {
        const badge = document.querySelector(`[data-chat-id="${chatId}"]`)?.querySelector('.unread-badge');
        if (!badge) return;
        if (reset) { badge.style.display = 'none'; badge.textContent = ''; return; }
        let count = parseInt(badge.textContent.replace(/[()]/g, '')) || 0;
        if (increment) count++;
        badge.textContent = count > 0 ? `(${count})` : '';
        badge.style.display = count > 0 ? 'inline' : 'none';
    }

    // ---------- ФОНОВОЕ ОБНОВЛЕНИЕ (только текст в превью, без перерисовки) ----------
    async function refreshChatList() {
        try {
            const data = await apiGet('/api/chats');
            const freshChats = data.chats || [];
            freshChats.forEach(fresh => {
                const unit = document.querySelector(`[data-chat-id="${fresh.chat_id}"]`);
                if (unit) {
                    const previewP = unit.querySelector('.chatUnitPreview p');
                    if (previewP) {
                        const badge = previewP.querySelector('.unread-badge');
                        const currentText = badge ? previewP.childNodes[0]?.textContent?.trim() : previewP.textContent;
                        const newText = fresh.last_message || 'Нет сообщений';
                        if (currentText !== newText) {
                            updateChatLocally(newText, fresh.chat_id);
                        }
                        // Обновляем счётчик
                        if (badge) {
                            badge.textContent = fresh.unread_count > 0 ? `(${fresh.unread_count})` : '';
                            badge.style.display = fresh.unread_count > 0 ? 'inline' : 'none';
                        }
                    }
                } else {
                    // Новый чат — добавляем в DOM (без перезагрузки всего списка)
                    const html = `
                        <div class="chatUnit" data-chat-id="${fresh.chat_id}">
                            <img class="chatUnitAvatar" src="${kop.esc(fresh.avatar || '')}" alt="">
                            <div class="chatUnitContent">
                                <div class="chatUnitUsername"><p>${kop.esc(fresh.first_name)} ${kop.esc(fresh.last_name)}</p></div>
                                <div class="chatUnitPreview">
                                    <p>${kop.esc(fresh.last_message || 'Нет сообщений')} <span class="unread-badge" style="display:${fresh.unread_count > 0 ? 'inline' : 'none'}">(${fresh.unread_count})</span></p>
                                </div>
                            </div>
                        </div>
                    `;
                    chatListContainer.insertAdjacentHTML('afterbegin', html);
                }
            });
            state.chats = freshChats;
        } catch (e) {
            console.error('Ошибка фонового обновления:', e);
        }
    }

    function startBackgroundRefresh() {
        if (state.backgroundTimer) clearInterval(state.backgroundTimer);
        state.backgroundTimer = setInterval(() => {
            if (!state.useWebSocket) {
                refreshChatList();
            }
        }, 15000);
    }

    // ---------- РОУТЕР ----------
    function initRouter() {
        chatListContainer.addEventListener('click', (e) => {
            const unit = e.target.closest('.chatUnit');
            if (!unit) return;
            e.preventDefault();
            const chatId = unit.dataset.chatId;
            if (chatId) navigateTo('chat', chatId);
        });
        chatViewPanel.addEventListener('click', (e) => {
            if (e.target.closest('.chatHeaderQuit')) { e.preventDefault(); navigateTo('list'); }
        });
        window.addEventListener('popstate', () => {
            const url = new URL(window.location);
            const chatId = url.searchParams.get('chat_id');
            if (chatId) openChat(chatId, false);
            else showChatList();
        });
        const initUrl = new URL(window.location);
        if (initUrl.searchParams.get('chat_id')) navigateTo('chat', initUrl.searchParams.get('chat_id'), false);
        else showChatList();
    }

    function navigateTo(type, id, push = true) {
        if (type === 'list') { showChatList(); if (push) history.pushState(null, '', '/messenger.php'); }
        else if (type === 'chat') openChat(id, push);
    }

    function showChatList() {
        state.activeChatId = null;
        stopCurrentPolling();
        chatViewPanel.innerHTML = '<div class="chat-placeholder"><p>Выберите чат слева</p></div>';
        // renderChatList не вызываем – список уже на месте
    }

    async function openChat(chatId, push = true) {
        if (state.activeChatId == chatId) return;
        state.activeChatId = chatId;
        stopCurrentPolling();
        if (push) history.pushState(null, '', `?chat_id=${chatId}`);

        const chat = state.chats.find(c => c.chat_id == chatId);
        if (!chat) return;

        chatViewPanel.innerHTML = `
            <div class="chatModule">
                <div class="chatHeader">
                    <button class="chatHeaderQuit">Назад</button>
                    <img class="chatHeaderAvatar" src="${kop.esc(chat.avatar || '')}">
                    <span class="chatHeaderUsername">${kop.esc(chat.first_name)} ${kop.esc(chat.last_name)}</span>
                </div>
                <div class="chatBody" id="messages-container"></div>
                <div class="chatTyping">
                    <textarea id="typing-input" class="chatTypingInput" placeholder="Здравствуйте!"></textarea>
                    <button class="sendMessageButton" id="send-msg-btn">➤</button>
                    <button class="sendMessageButton" id="cancel-edit-btn" style="display:none">✕</button>
                </div>
            </div>
        `;
        initChatEvents(chatId);
        await loadMessages(chatId);
        if (!state.useWebSocket) startPolling(chatId);
    }

    function stopCurrentPolling() {
        Object.values(state.pollControllers).forEach(c => c.abort());
        state.pollControllers = {};
    }

    // ---------- СООБЩЕНИЯ ----------
    async function loadMessages(chatId, append = false) {
        const container = $('#messages-container');
        if (!container) return;

        const cacheKey = chatId;
        if (!state.messagesCache[cacheKey]) {
            state.messagesCache[cacheKey] = { messages: [], lastPolledId: 0, hasMore: true, page: 1 };
        }
        const cache = state.messagesCache[cacheKey];

        if (append && !cache.hasMore) return;

        const page = append ? cache.page + 1 : 1;
        try {
            const data = await apiGet(`/api/messages/${chatId}?page=${page}`);
            const messages = data.messages || [];
            if (messages.length < 20) cache.hasMore = false;
            if (append) {
                cache.messages = messages.concat(cache.messages);
                cache.page = page;
                renderMessages(container, cache.messages, true);
            } else {
                cache.messages = messages;
                cache.page = 1;
                cache.hasMore = true;
                if (messages.length) cache.lastPolledId = messages[messages.length - 1].id;
                renderMessages(container, messages);
                updateUnreadBadge(chatId, false, true);
            }
        } catch (e) {
            if (!append) container.innerHTML = '<p style="text-align:center;color:#8b8fa3;">Ошибка загрузки</p>';
        }
    }

    function renderMessages(container, messages, append = false) {
        let html = '', lastDate = '';
        messages.forEach(msg => {
            const date = new Date(msg.created_at);
            const dateStr = date.toLocaleDateString('ru-RU');
            if (dateStr !== lastDate) { html += `<div class="chatDateBubble"><p>${dateStr}</p></div>`; lastDate = dateStr; }
            const isMine = msg.sender_id == state.userId;
            html += `
                <div class="${isMine ? 'myMessageBubble' : 'receivedMessage'} message animate-in" data-msg-id="${msg.id}" data-is-mine="${isMine}">
                    <p>${kop.esc(msg.content)}</p>
                    <div class="messageInfo"><p>${msg.is_read ? 'прочитано' : ''}</p><p>${date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}</p></div>
                </div>
            `;
        });

        if (append) {
            const prevScrollHeight = container.scrollHeight;
            container.insertAdjacentHTML('afterbegin', html);
            container.scrollTop = container.scrollHeight - prevScrollHeight;
        } else {
            container.innerHTML = html || '<p style="text-align:center;color:#8b8fa3;">Нет сообщений</p>';
            container.scrollTop = container.scrollHeight;
        }

        container.querySelectorAll('.message').forEach(msgDiv => {
            if (!msgDiv.dataset.menuAttached) {
                msgDiv.dataset.menuAttached = '1';
                msgDiv.addEventListener('click', (e) => {
                    e.stopPropagation();
                    showMsgMenu(e, msgDiv.dataset.msgId, msgDiv.dataset.isMine === 'true');
                });
            }
        });
    }

    // ---------- МЕНЮ ДЕЙСТВИЙ ----------
    const msgMenu = document.createElement('div');
    msgMenu.className = 'msg-actions-menu';
    msgMenu.id = 'msg-context-menu';
    document.body.appendChild(msgMenu);
    function hideMsgMenu() { msgMenu.classList.remove('active'); }
    document.addEventListener('click', (e) => { if (!msgMenu.contains(e.target)) hideMsgMenu(); });

    function showMsgMenu(e, messageId, isMine) {
        e.preventDefault();
        const rect = e.target.getBoundingClientRect();
        msgMenu.style.top = (rect.bottom + window.scrollY + 4) + 'px';
        msgMenu.style.left = (rect.right + window.scrollX - 200) + 'px';
        msgMenu.innerHTML = isMine
            ? `<div class="msg-actions-menu__item" data-action="edit" data-msg-id="${messageId}">${icons.edit} Редактировать</div>
               <div class="msg-actions-menu__item msg-actions-menu__item--danger" data-action="delete" data-msg-id="${messageId}">${icons.delete} Удалить</div>`
            : `<div class="msg-actions-menu__item" data-action="copy" data-msg-id="${messageId}">${icons.copy} Скопировать</div>`;
        msgMenu.classList.add('active');
        msgMenu.querySelectorAll('.msg-actions-menu__item').forEach(item => {
            item.addEventListener('click', async () => {
                const action = item.dataset.action, msgId = item.dataset.msgId;
                if (action === 'edit') startEdit(msgId);
                else if (action === 'delete') deleteMessage(msgId);
                else if (action === 'copy') copyText(msgId);
                hideMsgMenu();
            });
        });
    }

    async function deleteMessage(msgId) {
        if (!confirm('Удалить сообщение?')) return;
        await apiDelete(`/api/messages/${msgId}`);
        document.querySelector(`.message[data-msg-id="${msgId}"]`)?.remove();
    }

    function startEdit(msgId) {
        const el = document.querySelector(`.message[data-msg-id="${msgId}"]`);
        if (!el) return;
        state.editingMessageId = msgId;
        const input = $('#typing-input');
        input.value = el.querySelector('p').textContent;
        input.placeholder = 'Редактирование...';
        $('#send-msg-btn').style.display = 'none';
        $('#cancel-edit-btn').style.display = '';
    }

    async function submitEdit() {
        if (!state.editingMessageId) return;
        const newContent = $('#typing-input').value.trim();
        if (!newContent) return;
        const res = await apiPut(`/api/messages/${state.editingMessageId}`, { content: newContent });
        if (res.message) document.querySelector(`.message[data-msg-id="${state.editingMessageId}"] p`).textContent = res.message.content;
        cancelEdit();
    }

    function cancelEdit() {
        state.editingMessageId = null;
        $('#typing-input').value = '';
        $('#typing-input').placeholder = 'Здравствуйте!';
        $('#send-msg-btn').style.display = '';
        $('#cancel-edit-btn').style.display = 'none';
    }

    function copyText(msgId) {
        const text = document.querySelector(`.message[data-msg-id="${msgId}"] p`)?.textContent || '';
        navigator.clipboard.writeText(text).then(() => kop.flash('Скопировано'));
    }

    // ---------- ОТПРАВКА (HTTP) ----------
    function initChatEvents(chatId) {
        const sendBtn = $('#send-msg-btn');
        const input = $('#typing-input');
        const container = $('#messages-container');
        $('#cancel-edit-btn')?.addEventListener('click', cancelEdit);

        container.addEventListener('scroll', () => {
            if (container.scrollTop <= 50) {
                const cacheKey = chatId;
                if (state.messagesCache[cacheKey]?.hasMore) {
                    loadMessages(chatId, true);
                }
            }
        });

        sendBtn.addEventListener('click', async () => {
            if (state.editingMessageId) { await submitEdit(); return; }
            const content = input.value.trim();
            if (!content) return;

            const tempId = 'temp-' + Date.now();
            container.insertAdjacentHTML('beforeend', `
                <div class="myMessageBubble message animate-in" data-msg-id="${tempId}">
                    <p>${kop.esc(content)}</p>
                    <div class="messageInfo"><p>${new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}</p></div>
                </div>
            `);
            container.scrollTop = container.scrollHeight;
            input.value = '';

            try {
                const chat = state.chats.find(c => c.chat_id == chatId);
                const response = await apiPost('/api/messages/send', { receiver_id: chat.other_user_id, content });
                if (response.message_id) {
                    document.querySelector(`[data-msg-id="${tempId}"]`)?.setAttribute('data-msg-id', response.message_id);
                    updateChatLocally(content, response.chat_id || chatId, response.other_user_id || chat.other_user_id);
                    const cacheKey = chatId;
                    if (state.messagesCache[cacheKey]) {
                        state.messagesCache[cacheKey].lastPolledId = response.message_id;
                    }
                    if (!state.useWebSocket && state.pollControllers[cacheKey]) {
                        state.pollControllers[cacheKey].abort();
                        startPolling(chatId);
                    }
                }
            } catch (e) { kop.flash('Ошибка отправки'); }
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendBtn.click(); }
        });
    }

    // ---------- LONG POLLING ----------
    function startPolling(chatId) {
        if (state.useWebSocket) return;
        const cacheKey = chatId;
        if (state.pollControllers[cacheKey]) state.pollControllers[cacheKey].abort();
        const controller = new AbortController();
        state.pollControllers[cacheKey] = controller;

        const poll = async () => {
            if (!state.messagesCache[cacheKey]) return;
            const lastId = state.messagesCache[cacheKey].lastPolledId;
            try {
                const data = await kop.get(`/api/messages/${chatId}/poll?after=${lastId}`, { signal: controller.signal });
                if (data.messages?.length) {
                    const container = $('#messages-container');
                    data.messages.forEach(msg => {
                        if (msg.id <= lastId || document.querySelector(`[data-msg-id="${msg.id}"]`)) return;
                        const isMine = msg.sender_id == state.userId;
                        container.insertAdjacentHTML('beforeend', `
                            <div class="${isMine ? 'myMessageBubble' : 'receivedMessage'} message animate-in" data-msg-id="${msg.id}" data-is-mine="${isMine}">
                                <p>${kop.esc(msg.content)}</p>
                                <div class="messageInfo"><p>${msg.is_read ? 'прочитано' : ''}</p><p>${new Date(msg.created_at).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}</p></div>
                            </div>
                        `);
                        state.messagesCache[cacheKey].lastPolledId = msg.id;
                    });
                    container.scrollTop = container.scrollHeight;
                    const lastMsg = data.messages[data.messages.length - 1];
                    updateChatLocally(lastMsg.content, chatId, lastMsg.sender_id != state.userId ? lastMsg.sender_id : null);
                    if (document.hidden || state.activeChatId != chatId) updateUnreadBadge(chatId, true);
                }
            } catch (e) {
                if (e.name !== 'AbortError') console.error('Poll error:', e);
            }
            if (!controller.signal.aborted) poll();
        };
        poll();
    }

    // ---------- WEBSOCKET (ws://) ----------
    function connectWebSocket() {
        if (state.wsReconnectAttempts >= state.maxWsReconnectAttempts) {
            state.useWebSocket = false;
            if (state.activeChatId) startPolling(state.activeChatId);
            return;
        }
        try {
            const wsUrl = 'ws://localhost:8080';
            state.ws = new WebSocket(wsUrl);
            state.ws.onopen = () => {
                state.wsReconnectAttempts = 0;
                state.useWebSocket = true;
                state.ws.send(JSON.stringify({ type: 'auth', user_id: state.userId }));
                if (state.activeChatId) {
                    stopCurrentPolling();
                }
            };
            state.ws.onmessage = (event) => {
                const data = JSON.parse(event.data);
                if (data.type === 'new_message') {
                    const { chat_id, sender_id, content, message_id, created_at } = data;
                    updateChatLocally(content, chat_id, sender_id != state.userId ? sender_id : null);
                    if (sender_id != state.userId) updateUnreadBadge(chat_id, true);
                    if (state.activeChatId == chat_id) {
                        const container = $('#messages-container');
                        if (container && !document.querySelector(`[data-msg-id="${message_id}"]`)) {
                            const date = new Date(created_at);
                            container.insertAdjacentHTML('beforeend', `
                                <div class="receivedMessage message animate-in" data-msg-id="${message_id}" data-is-mine="false">
                                    <p>${kop.esc(content)}</p>
                                    <div class="messageInfo"><p>${date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}</p></div>
                                </div>
                            `);
                            container.scrollTop = container.scrollHeight;
                        }
                    }
                }
            };
            state.ws.onclose = () => {
                state.useWebSocket = false;
                state.wsReconnectAttempts++;
                if (state.wsReconnectAttempts < state.maxWsReconnectAttempts) {
                    setTimeout(connectWebSocket, 3000);
                } else if (state.activeChatId) {
                    startPolling(state.activeChatId);
                }
            };
            state.ws.onerror = () => {
                state.ws.close();
            };
        } catch (e) {
            state.useWebSocket = false;
        }
    }

    // ---------- ПОИСК ----------
    function initSearch() {
        searchInput.addEventListener('input', () => {
            const q = searchInput.value.trim().toLowerCase();
            document.querySelectorAll('.chatUnit').forEach(u => {
                const name = u.querySelector('.chatUnitUsername p')?.textContent.toLowerCase() || '';
                u.style.display = q === '' || name.includes(q) ? '' : 'none';
            });
        });
    }

    function requestNotificationPermission() {
        if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
    }

    $('#create-group-btn')?.addEventListener('click', () => kop.flash('Группы скоро появятся'));

    init();
})();