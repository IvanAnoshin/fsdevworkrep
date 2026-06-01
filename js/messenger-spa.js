(function() {
    // ---------- СОСТОЯНИЕ ----------
    const state = {
        userId: null,
        chats: [],
        groups: [],
        allItems: [],
        activeChatId: null,
        activeChatType: null,
        activeChatReceiverId: null,
        activeGroupId: null,
        messagesCache: {},
        ws: null,
        wsReconnectAttempts: 0,
        maxWsReconnectAttempts: 3,
        useWebSocket: false,
        pollActive: false,
        pollAbortController: null,
        pollRetryCount: 0,
        pollMaxRetries: 5,
        pollRetryDelay: 2000,
        editingMessageId: null,
        backgroundTimer: null,
        loadingMoreMessages: false,
        currentView: 'messages',
        searchDebounceTimer: null,
        localLastMessageUpdate: {},
        mediaType: 'photo',
        mediaPage: 1,
        mediaHasMore: true,
        mediaLoading: false,
        mediaItems: [],
        mediaObserver: null,
        groupMembers: [],
        membersLoading: false
    };

    // Утилита для уведомлений
    if (!window.kop) window.kop = {};
    if (!window.kop.flash) {
        window.kop.flash = function(message, duration = 3000) {
            let toast = document.querySelector('.global-toast');
            if (!toast) {
                toast = document.createElement('div');
                toast.className = 'global-toast';
                document.body.appendChild(toast);
            }
            toast.textContent = message;
            toast.classList.add('visible');
            setTimeout(() => toast.classList.remove('visible'), duration);
        };
    }

    // Стиль спиннера
    if (!document.getElementById('spinner-style')) {
        const style = document.createElement('style');
        style.id = 'spinner-style';
        style.textContent = `@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }`;
        document.head.appendChild(style);
    }

    const $ = sel => document.querySelector(sel);
    const chatListContainer = $('#chat-list-container');
    const chatViewPanel = $('#chat-view-panel');
    const searchInput = $('#messengerSearch');

    const esc = str => {
        if (window.kop && window.kop.esc) return window.kop.esc(str);
        if (str == null) return '';
        return String(str).replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    };

    const icons = {
        edit: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>',
        delete: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
        copy: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>'
    };

    // ---------- HTTP ЗАПРОСЫ (С ОТКЛЮЧЕНИЕМ КЕША) ----------
    function getCsrfHeader() { return { 'X-CSRF-Token': window.csrfToken }; }
    async function apiGet(url, options = {}) {
        const response = await fetch(url, {
            method: 'GET',
            headers: { 'Content-Type': 'application/json', ...getCsrfHeader(), 'Accept': 'application/json', 'Cache-Control': 'no-cache, no-store' },
            signal: options.signal,
            cache: 'no-store'
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
    }
    async function apiPost(url, data) {
        const headers = { 'Content-Type': 'application/json', ...getCsrfHeader() };
        const res = await fetch(url, { method: 'POST', headers, body: JSON.stringify(data) });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }
    async function apiPut(url, data) {
        const headers = { 'Content-Type': 'application/json', ...getCsrfHeader() };
        const res = await fetch(url, { method: 'PUT', headers, body: JSON.stringify(data) });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }
    async function apiDelete(url) {
        const headers = { ...getCsrfHeader() };
        const res = await fetch(url, { method: 'DELETE', headers });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    // ---------- УТИЛИТЫ ----------
    function getInitials(name) {
        if (!name) return '?';
        const parts = name.trim().split(/\s+/);
        if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
        return name.substring(0, 2).toUpperCase();
    }
    function formatTime(date) {
        return date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    }

    // ---------- ЗАГРУЗКА СПИСКА ЧАТОВ (БЕЗ ИКОНКИ ЗАКРЕПЛЕНИЯ) ----------
    async function loadChats() {
        try {
            const [chatsData, groupsData] = await Promise.all([
                apiGet('/api/chats').catch(() => ({ chats: [] })),
                apiGet('/api/groups').catch(() => ({ groups: [] }))
            ]);
            state.chats = chatsData.chats || [];
            state.groups = groupsData.groups || [];

            const newItems = [];
            state.chats.forEach(chat => {
                newItems.push({
                    type: 'private',
                    id: chat.chat_id,
                    name: `${chat.first_name} ${chat.last_name}`,
                    avatar: chat.avatar || null,
                    last_message: chat.last_message,
                    unread_count: chat.unread_count,
                    is_pinned: chat.is_pinned === 1,
                    raw: chat,
                    other_user_id: chat.other_user_id,
                    user1_id: chat.user1_id,
                    user2_id: chat.user2_id
                });
            });
            state.groups.forEach(group => {
                newItems.push({
                    type: 'group',
                    id: group.id,
                    name: group.name,
                    avatar: null,
                    last_message: group.last_message || 'Нет сообщений',
                    unread_count: group.unread_count || 0,
                    is_pinned: group.is_pinned === 1,
                    raw: group
                });
            });
            newItems.sort((a, b) => {
                if (a.is_pinned !== b.is_pinned) return (b.is_pinned ? 1 : 0) - (a.is_pinned ? 1 : 0);
                return 0;
            });
            state.allItems = newItems;
            updateChatListDOM(newItems);
        } catch(e) {
            console.error(e);
            if (chatListContainer) chatListContainer.innerHTML = '<p class="media-empty">Ошибка загрузки чатов</p>';
        }
    }

    // Генерация элемента списка чатов (без кнопки закрепления)
    function renderChatItem(item, isActive) {
        let avatarHtml = '';
        if (item.type === 'private' && item.avatar) {
            avatarHtml = `<img class="chatUnitAvatar" src="${esc(item.avatar)}" alt="">`;
        } else {
            avatarHtml = `<div class="chatUnitAvatar chatUnitAvatar--placeholder">${getInitials(item.name)}</div>`;
        }
        return `
            <div class="chatUnit ${isActive ? 'active' : ''}" data-type="${item.type}" data-id="${item.id}">
                ${avatarHtml}
                <div class="chatUnitContent">
                    <div class="chatUnitUsername"><p>${esc(item.name)}</p></div>
                    <div class="chatUnitPreview"><p>${esc(item.last_message || 'Нет сообщений')} <span class="unread-badge" style="display:${item.unread_count > 0 ? 'inline' : 'none'}">(${item.unread_count})</span></p></div>
                </div>
            </div>
        `;
    }

    function updateChatListDOM(newItems) {
        if (!chatListContainer) return;
        if (!newItems.length) {
            chatListContainer.innerHTML = '<p class="media-empty">Нет активных чатов</p>';
            return;
        }
        const existingNodes = new Map();
        document.querySelectorAll('.chatUnit').forEach(node => {
            const type = node.dataset.type, id = node.dataset.id;
            if (type && id) existingNodes.set(`${type}|${id}`, node);
        });
        const newSet = new Set(newItems.map(i => `${i.type}|${i.id}`));
        for (let [key, node] of existingNodes.entries()) if (!newSet.has(key)) node.remove();
        for (const item of newItems) {
            const key = `${item.type}|${item.id}`;
            const existing = existingNodes.get(key);
            const isActive = (item.type === 'private' && item.id == state.activeChatId && state.activeChatType === 'private') ||
                             (item.type === 'group' && item.id == state.activeGroupId && state.activeChatType === 'group');
            const newHtml = renderChatItem(item, isActive);
            if (existing) existing.outerHTML = newHtml;
            else chatListContainer.insertAdjacentHTML('beforeend', newHtml);
        }
        initChatSearch();
    }

    function updateChatPreview(id, type, last_message, unread_count) {
        const node = document.querySelector(`.chatUnit[data-type="${type}"][data-id="${id}"]`);
        if (!node) return;
        const previewP = node.querySelector('.chatUnitPreview p');
        if (previewP) {
            const badge = previewP.querySelector('.unread-badge');
            const escaped = esc(last_message || 'Нет сообщений');
            previewP.innerHTML = badge
                ? `${escaped} <span class="unread-badge" style="display:${unread_count > 0 ? 'inline' : 'none'}">(${unread_count})</span>`
                : escaped;
        }
        const isActive = (type === 'private' && id == state.activeChatId && state.activeChatType === 'private') ||
                         (type === 'group' && id == state.activeGroupId && state.activeChatType === 'group');
        if (isActive) node.classList.add('active');
        else node.classList.remove('active');
    }

    function updateUnreadBadge(id, type, increment = false, reset = false) {
        const node = document.querySelector(`.chatUnit[data-type="${type}"][data-id="${id}"]`);
        if (!node) return;
        const badge = node.querySelector('.unread-badge');
        if (!badge) return;
        if (reset) { badge.style.display = 'none'; badge.textContent = ''; return; }
        let count = parseInt(badge.textContent.replace(/[()]/g, '')) || 0;
        if (increment) count++;
        badge.textContent = count > 0 ? `(${count})` : '';
        badge.style.display = count > 0 ? 'inline' : 'none';
    }

    // ---------- ФУНКЦИЯ ЗАКРЕПЛЕНИЯ (ВЫЗЫВАЕТСЯ ИЗ МЕНЮ) ----------
    async function togglePin(chatId, type, currentPinned) {
        const newPinned = !currentPinned;
        const endpoint = type === 'private' ? '/api/chats/pin' : '/api/groups/pin';
        const payload = type === 'private' ? { chat_id: chatId, pin: newPinned } : { group_id: chatId, pin: newPinned };
        try {
            await apiPost(endpoint, payload);
            const item = state.allItems.find(i => i.id === chatId && i.type === type);
            if (item) {
                item.is_pinned = newPinned;
                state.allItems.sort((a, b) => {
                    if (a.is_pinned !== b.is_pinned) return (b.is_pinned ? 1 : 0) - (a.is_pinned ? 1 : 0);
                    return 0;
                });
                updateChatListDOM(state.allItems);
            }
            window.kop.flash(newPinned ? 'Чат закреплён' : 'Чат откреплён');
        } catch(e) { window.kop.flash('Ошибка изменения закрепления'); }
    }

    // ---------- ФОНОВОЕ ОБНОВЛЕНИЕ С ЗАЩИТОЙ LAST_MESSAGE ----------
    async function refreshChatList() {
        try {
            const [chatsData, groupsData] = await Promise.all([
                apiGet('/api/chats').catch(() => ({ chats: [] })),
                apiGet('/api/groups').catch(() => ({ groups: [] }))
            ]);
            state.chats = chatsData.chats || [];
            state.groups = groupsData.groups || [];

            const newItemsMap = new Map();
            state.chats.forEach(c => newItemsMap.set(`private|${c.chat_id}`, {
                last_message: c.last_message,
                unread_count: c.unread_count,
                name: `${c.first_name} ${c.last_name}`,
                other_user_id: c.other_user_id,
                is_pinned: c.is_pinned === 1
            }));
            state.groups.forEach(g => newItemsMap.set(`group|${g.id}`, {
                last_message: g.last_message,
                unread_count: g.unread_count || 0,
                name: g.name,
                is_pinned: g.is_pinned === 1
            }));

            let changed = false;
            for (const item of state.allItems) {
                const key = `${item.type}|${item.id}`;
                const newData = newItemsMap.get(key);
                if (newData) {
                    const chatKey = `${item.type}_${item.id}`;
                    const lastLocalUpdate = state.localLastMessageUpdate[chatKey] || 0;
                    const timeSinceLocalUpdate = Date.now() - lastLocalUpdate;
                    const shouldUpdateLastMessage = (item.last_message !== newData.last_message) && (timeSinceLocalUpdate > 10000);
                    if (shouldUpdateLastMessage || item.unread_count !== newData.unread_count || item.is_pinned !== newData.is_pinned) {
                        if (shouldUpdateLastMessage) item.last_message = newData.last_message;
                        item.unread_count = newData.unread_count;
                        item.is_pinned = newData.is_pinned;
                        updateChatPreview(item.id, item.type, item.last_message, item.unread_count);
                        changed = true;
                    }
                    if (item.name !== newData.name) {
                        item.name = newData.name;
                        const node = document.querySelector(`.chatUnit[data-type="${item.type}"][data-id="${item.id}"] .chatUnitUsername p`);
                        if (node) node.textContent = esc(item.name);
                        changed = true;
                    }
                    if (item.type === 'private' && newData.other_user_id) item.other_user_id = newData.other_user_id;
                } else {
                    const node = document.querySelector(`.chatUnit[data-type="${item.type}"][data-id="${item.id}"]`);
                    if (node) node.remove();
                    changed = true;
                }
            }
            const existingKeys = new Set(state.allItems.map(i => `${i.type}|${i.id}`));
            for (const c of state.chats) {
                const key = `private|${c.chat_id}`;
                if (!existingKeys.has(key)) {
                    state.allItems.push({
                        type: 'private', id: c.chat_id, name: `${c.first_name} ${c.last_name}`,
                        avatar: c.avatar || null, last_message: c.last_message, unread_count: c.unread_count,
                        is_pinned: c.is_pinned === 1, raw: c, other_user_id: c.other_user_id
                    });
                    changed = true;
                }
            }
            for (const g of state.groups) {
                const key = `group|${g.id}`;
                if (!existingKeys.has(key)) {
                    state.allItems.push({
                        type: 'group', id: g.id, name: g.name, avatar: null,
                        last_message: g.last_message, unread_count: g.unread_count || 0,
                        is_pinned: g.is_pinned === 1, raw: g
                    });
                    changed = true;
                }
            }
            if (changed) {
                state.allItems.sort((a, b) => {
                    if (a.is_pinned !== b.is_pinned) return (b.is_pinned ? 1 : 0) - (a.is_pinned ? 1 : 0);
                    return 0;
                });
                updateChatListDOM(state.allItems);
            }
        } catch(e) { console.error(e); }
    }
    function startBackgroundRefresh() {
        if (state.backgroundTimer) clearInterval(state.backgroundTimer);
        state.backgroundTimer = setInterval(() => {
            if (!state.useWebSocket) refreshChatList();
        }, 30000);
    }

    function initChatSearch() {
        let searchInputEl = $('#messengerSearch');
        if (!searchInputEl) return;
        const newSearch = searchInputEl.cloneNode(true);
        searchInputEl.parentNode.replaceChild(newSearch, searchInputEl);
        if (typeof window.searchInput !== 'undefined') window.searchInput = newSearch;
        newSearch.addEventListener('input', (e) => {
            if (state.searchDebounceTimer) clearTimeout(state.searchDebounceTimer);
            state.searchDebounceTimer = setTimeout(() => {
                const query = e.target.value.trim().toLowerCase();
                document.querySelectorAll('.chatUnit').forEach(unit => {
                    const name = unit.querySelector('.chatUnitUsername p')?.textContent.toLowerCase() || '';
                    unit.style.display = (query === '' || name.includes(query)) ? '' : 'none';
                });
            }, 250);
        });
    }

    // ---------- РОУТИНГ ----------
    function initRouter() {
        chatListContainer.addEventListener('click', (e) => {
            const unit = e.target.closest('.chatUnit');
            if (!unit) return;
            const type = unit.dataset.type;
            const id = parseInt(unit.dataset.id);
            if (type === 'private') navigateTo('private', id);
            else if (type === 'group') navigateTo('group', id);
        });
        chatViewPanel.addEventListener('click', (e) => {
            const backBtn = e.target.closest('.chatHeaderQuit');
            if (backBtn) {
                e.preventDefault();
                handleBackButton();
            }
        });
        window.addEventListener('popstate', () => handlePopState());
        const url = new URL(window.location);
        const chatId = url.searchParams.get('chat_id');
        const groupId = url.searchParams.get('group_id');
        if (chatId) openPrivateChat(parseInt(chatId), false);
        else if (groupId) openGroupChat(parseInt(groupId), false);
        else showChatList();
    }

    function handleBackButton() {
        if (state.currentView === 'mediahub') {
            switchToMessages();
        } else if (state.currentView === 'members') {
            switchToMessages();
        } else {
            navigateTo('list');
        }
    }

    function handlePopState() {
        const url = new URL(window.location);
        const chatId = url.searchParams.get('chat_id');
        const groupId = url.searchParams.get('group_id');
        const view = url.searchParams.get('view');
        if (chatId) {
            if (state.activeChatId !== chatId || state.activeChatType !== 'private') {
                openPrivateChat(parseInt(chatId), false);
            }
            if (view === 'media') switchToMediaHub();
            else if (view === 'members') switchToMembers();
            else if (state.currentView !== 'messages') switchToMessages();
        } else if (groupId) {
            if (state.activeGroupId !== groupId || state.activeChatType !== 'group') {
                openGroupChat(parseInt(groupId), false);
            }
            if (view === 'media') switchToMediaHub();
            else if (view === 'members') switchToMembers();
            else if (state.currentView !== 'messages') switchToMessages();
        } else {
            showChatList();
        }
    }

    function navigateTo(type, id, push = true) {
        if (type === 'list') { showChatList(); if (push) history.pushState(null, '', '/messenger.php'); }
        else if (type === 'private') { openPrivateChat(id, push); }
        else if (type === 'group') { openGroupChat(id, push); }
    }

    function showChatList() {
        state.activeChatId = null;
        state.activeChatType = null;
        state.activeGroupId = null;
        state.currentView = 'messages';
        stopPolling();
        chatViewPanel.innerHTML = '<div class="chat-placeholder"><p>Выберите чат слева</p></div>';
    }

    // ---------- ПОЛЛИНГ ЧЕРЕЗ setInterval (СТАБИЛЬНО, С ОТЛАДКОЙ) ----------
    let pollingIntervals = {};

    function stopPolling() {
        for (let id in pollingIntervals) {
            clearInterval(pollingIntervals[id]);
            delete pollingIntervals[id];
        }
    }

    function startPollingForPrivate(chatId) {
        if (state.useWebSocket) return;
        if (pollingIntervals[`private_${chatId}`]) clearInterval(pollingIntervals[`private_${chatId}`]);
        pollingIntervals[`private_${chatId}`] = setInterval(async () => {
            if (state.currentView !== 'messages' || state.activeChatId !== chatId || state.activeChatType !== 'private') return;
            const cache = state.messagesCache['private_'+chatId];
            if (!cache) {
                console.warn(`Polling: cache not ready for chat ${chatId}, retrying later`);
                return;
            }
            const lastId = cache.lastPolledId || 0;
            try {
                const response = await fetch(`/api/messages/${chatId}/poll?after=${lastId}`, {
                    headers: { 'X-CSRF-Token': window.csrfToken, 'Accept': 'application/json' }
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();
                if (data.messages && data.messages.length) {
                    console.log(`Polling: got ${data.messages.length} new messages for chat ${chatId}`);
                    const container = document.querySelector('#messages-container');
                    if (container) {
                        for (const msg of data.messages) {
                            if (document.querySelector(`[data-msg-id="${msg.id}"]`)) continue;
                            const isMine = msg.sender_id == state.userId;
                            const date = new Date(msg.created_at);
                            container.insertAdjacentHTML('beforeend', `
                                <div class="${isMine ? 'myMessageBubble' : 'receivedMessage'} message animate-in" data-msg-id="${msg.id}" data-is-mine="${isMine}">
                                    <p>${esc(msg.content)}</p>
                                    <div class="messageInfo"><p>${msg.is_read ? 'прочитано' : ''}</p><p>${formatTime(date)}</p></div>
                                </div>
                            `);
                            if (msg.id > cache.lastPolledId) cache.lastPolledId = msg.id;
                        }
                        container.scrollTop = container.scrollHeight;
                        const lastMsg = data.messages[data.messages.length - 1];
                        if (lastMsg) {
                            updateChatPreview(chatId, 'private', lastMsg.content, 0);
                            if (document.hidden || state.activeChatId != chatId) updateUnreadBadge(chatId, 'private', true);
                        }
                    }
                } else {
                    // нет новых сообщений – ничего не делаем
                }
            } catch(e) {
                console.error('Poll error:', e);
            }
        }, 2500);
    }

    function startPollingForGroup(groupId) {
        if (state.useWebSocket) return;
        if (pollingIntervals[`group_${groupId}`]) clearInterval(pollingIntervals[`group_${groupId}`]);
        pollingIntervals[`group_${groupId}`] = setInterval(async () => {
            if (state.currentView !== 'messages' || state.activeGroupId !== groupId || state.activeChatType !== 'group') return;
            const cache = state.messagesCache['group_'+groupId];
            if (!cache) {
                console.warn(`Group polling: cache not ready for group ${groupId}, retrying later`);
                return;
            }
            const lastId = cache.lastPolledId || 0;
            try {
                const response = await fetch(`/api/groups/${groupId}/messages/poll?after=${lastId}`, {
                    headers: { 'X-CSRF-Token': window.csrfToken, 'Accept': 'application/json' }
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();
                if (data.messages && data.messages.length) {
                    console.log(`Group polling: got ${data.messages.length} new messages for group ${groupId}`);
                    const container = document.querySelector('#messages-container');
                    if (container) {
                        for (const msg of data.messages) {
                            if (document.querySelector(`[data-msg-id="${msg.id}"]`)) continue;
                            const isMine = msg.sender_id == state.userId;
                            const senderName = `${msg.first_name} ${msg.last_name}`;
                            const date = new Date(msg.created_at);
                            container.insertAdjacentHTML('beforeend', `
                                <div class="${isMine ? 'myMessageBubble' : 'receivedMessage'} message animate-in" data-msg-id="${msg.id}" data-is-mine="${isMine}">
                                    ${!isMine ? `<div class="message-sender" style="font-size:0.75rem;color:#8b8fa3;margin-bottom:4px;">${esc(senderName)}</div>` : ''}
                                    <p>${esc(msg.content)}</p>
                                    <div class="messageInfo"><p></p><p>${formatTime(date)}</p></div>
                                </div>
                            `);
                            if (msg.id > cache.lastPolledId) cache.lastPolledId = msg.id;
                        }
                        container.scrollTop = container.scrollHeight;
                        const lastMsg = data.messages[data.messages.length - 1];
                        if (lastMsg) {
                            updateChatPreview(groupId, 'group', lastMsg.content, 0);
                            if (document.hidden || state.activeGroupId != groupId) updateUnreadBadge(groupId, 'group', true);
                        }
                    }
                }
            } catch(e) {
                console.error('Group poll error:', e);
            }
        }, 2500);
    }

    // ---------- ЗАГРУЗКА СООБЩЕНИЙ ----------
    async function loadPrivateMessages(chatId, append = false) {
        const container = document.querySelector('#messages-container');
        if (!container) return;
        if (append && state.loadingMoreMessages) return;
        if (append) state.loadingMoreMessages = true;
        const cacheKey = 'private_'+chatId;
        if (!state.messagesCache[cacheKey]) state.messagesCache[cacheKey] = { messages: [], lastPolledId: 0, hasMore: true, page: 1 };
        const cache = state.messagesCache[cacheKey];
        if (append && !cache.hasMore) { state.loadingMoreMessages = false; return; }
        const page = append ? cache.page + 1 : 1;
        try {
            const data = await apiGet(`/api/messages/${chatId}?page=${page}`);
            const messages = data.messages || [];
            if (messages.length < 20) cache.hasMore = false;
            if (append) {
                const fragment = document.createDocumentFragment();
                let lastDate = '';
                const existingIds = new Set([...document.querySelectorAll('.message')].map(el => el.dataset.msgId));
                for (const msg of messages) {
                    if (existingIds.has(String(msg.id))) continue;
                    const date = new Date(msg.created_at);
                    const dateStr = date.toLocaleDateString('ru-RU');
                    if (dateStr !== lastDate) {
                        const dateDiv = document.createElement('div');
                        dateDiv.className = 'chatDateBubble';
                        dateDiv.innerHTML = `<p>${dateStr}</p>`;
                        fragment.appendChild(dateDiv);
                        lastDate = dateStr;
                    }
                    const isMine = msg.sender_id == state.userId;
                    const msgDiv = document.createElement('div');
                    msgDiv.className = `${isMine ? 'myMessageBubble' : 'receivedMessage'} message animate-in`;
                    msgDiv.dataset.msgId = msg.id;
                    msgDiv.dataset.isMine = isMine;
                    msgDiv.innerHTML = `<p>${esc(msg.content)}</p><div class="messageInfo"><p>${msg.is_read ? 'прочитано' : ''}</p><p>${formatTime(date)}</p></div>`;
                    fragment.appendChild(msgDiv);
                }
                container.insertBefore(fragment, container.firstChild);
                cache.messages = messages.concat(cache.messages);
                cache.page = page;
                if (messages.length) cache.lastPolledId = Math.max(cache.lastPolledId, messages[messages.length-1].id);
            } else {
                cache.messages = messages;
                cache.page = 1;
                cache.hasMore = true;
                if (messages.length) cache.lastPolledId = messages[messages.length-1].id;
                renderMessages(container, messages);
                updateUnreadBadge(chatId, 'private', false, true);
            }
        } catch(e) {
            console.error(e);
            if (!append) container.innerHTML = '<p class="media-empty">Ошибка загрузки сообщений</p>';
        } finally {
            if (append) state.loadingMoreMessages = false;
        }
    }

    async function loadGroupMessages(groupId, append = false) {
        const container = document.querySelector('#messages-container');
        if (!container) return;
        if (append && state.loadingMoreMessages) return;
        if (append) state.loadingMoreMessages = true;
        const cacheKey = 'group_'+groupId;
        if (!state.messagesCache[cacheKey]) state.messagesCache[cacheKey] = { messages: [], lastPolledId: 0, hasMore: true, page: 1 };
        const cache = state.messagesCache[cacheKey];
        if (append && !cache.hasMore) { state.loadingMoreMessages = false; return; }
        const page = append ? cache.page + 1 : 1;
        try {
            const data = await apiGet(`/api/groups/${groupId}/messages?page=${page}`);
            const messages = data.messages || [];
            if (messages.length < 20) cache.hasMore = false;
            if (append) {
                const fragment = document.createDocumentFragment();
                let lastDate = '';
                const existingIds = new Set([...document.querySelectorAll('.message')].map(el => el.dataset.msgId));
                for (const msg of messages) {
                    if (existingIds.has(String(msg.id))) continue;
                    const date = new Date(msg.created_at);
                    const dateStr = date.toLocaleDateString('ru-RU');
                    if (dateStr !== lastDate) {
                        const dateDiv = document.createElement('div');
                        dateDiv.className = 'chatDateBubble';
                        dateDiv.innerHTML = `<p>${dateStr}</p>`;
                        fragment.appendChild(dateDiv);
                        lastDate = dateStr;
                    }
                    const isMine = msg.sender_id == state.userId;
                    const senderName = `${msg.first_name} ${msg.last_name}`;
                    const msgDiv = document.createElement('div');
                    msgDiv.className = `${isMine ? 'myMessageBubble' : 'receivedMessage'} message animate-in`;
                    msgDiv.dataset.msgId = msg.id;
                    msgDiv.dataset.isMine = isMine;
                    msgDiv.innerHTML = `${!isMine ? `<div class="message-sender" style="font-size:0.75rem;color:#8b8fa3;margin-bottom:4px;">${esc(senderName)}</div>` : ''}<p>${esc(msg.content)}</p><div class="messageInfo"><p></p><p>${formatTime(date)}</p></div>`;
                    fragment.appendChild(msgDiv);
                }
                container.insertBefore(fragment, container.firstChild);
                cache.messages = messages.concat(cache.messages);
                cache.page = page;
                if (messages.length) cache.lastPolledId = Math.max(cache.lastPolledId, messages[messages.length-1].id);
            } else {
                cache.messages = messages;
                cache.page = 1;
                cache.hasMore = true;
                if (messages.length) cache.lastPolledId = messages[messages.length-1].id;
                renderMessages(container, messages, true);
                updateUnreadBadge(groupId, 'group', false, true);
            }
        } catch(e) { if (!append) container.innerHTML = '<p class="media-empty">Ошибка загрузки</p>'; }
        finally { if (append) state.loadingMoreMessages = false; }
    }

    function renderMessages(container, messages, isGroup = false) {
        let html = '', lastDate = '';
        for (const msg of messages) {
            const date = new Date(msg.created_at);
            const dateStr = date.toLocaleDateString('ru-RU');
            if (dateStr !== lastDate) { html += `<div class="chatDateBubble"><p>${dateStr}</p></div>`; lastDate = dateStr; }
            const isMine = msg.sender_id == state.userId;
            const senderName = isGroup && !isMine ? `${msg.first_name} ${msg.last_name}` : '';
            html += `
                <div class="${isMine ? 'myMessageBubble' : 'receivedMessage'} message animate-in" data-msg-id="${msg.id}" data-is-mine="${isMine}">
                    ${senderName ? `<div class="message-sender" style="font-size:0.75rem;color:#8b8fa3;margin-bottom:4px;">${esc(senderName)}</div>` : ''}
                    <p>${esc(msg.content)}</p>
                    <div class="messageInfo"><p>${msg.is_read ? 'прочитано' : ''}</p><p>${formatTime(date)}</p></div>
                </div>
            `;
        }
        container.innerHTML = html || '<p class="media-empty">Нет сообщений</p>';
        container.scrollTop = container.scrollHeight;
        attachMessageMenuEvents(container);
    }

    function attachMessageMenuEvents(container) {
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

    // ---------- МЕНЮ СООБЩЕНИЙ ----------
    const msgMenu = document.createElement('div');
    msgMenu.className = 'msg-actions-menu';
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
        try {
            await apiDelete(`/api/messages/${msgId}`);
            const msgEl = document.querySelector(`.message[data-msg-id="${msgId}"]`);
            if (msgEl) msgEl.remove();
            const cacheKey = state.activeChatType === 'private' ? 'private_'+state.activeChatId : 'group_'+state.activeGroupId;
            if (state.messagesCache[cacheKey]) state.messagesCache[cacheKey].messages = state.messagesCache[cacheKey].messages.filter(m => m.id != msgId);
        } catch(e) { window.kop.flash('Ошибка удаления'); }
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
        try {
            const res = await apiPut(`/api/messages/${state.editingMessageId}`, { content: newContent });
            if (res.message) {
                const msgEl = document.querySelector(`.message[data-msg-id="${state.editingMessageId}"]`);
                if (msgEl) msgEl.querySelector('p').textContent = res.message.content;
                const cacheKey = state.activeChatType === 'private' ? 'private_'+state.activeChatId : 'group_'+state.activeGroupId;
                if (state.messagesCache[cacheKey]) {
                    const idx = state.messagesCache[cacheKey].messages.findIndex(m => m.id == state.editingMessageId);
                    if (idx !== -1) state.messagesCache[cacheKey].messages[idx].content = res.message.content;
                }
            }
        } catch(e) { window.kop.flash('Ошибка редактирования'); }
        finally { cancelEdit(); }
    }

    function cancelEdit() {
        state.editingMessageId = null;
        $('#typing-input').value = '';
        $('#typing-input').placeholder = 'Сообщение...';
        $('#send-msg-btn').style.display = '';
        $('#cancel-edit-btn').style.display = 'none';
    }

    function copyText(msgId) {
        const text = document.querySelector(`.message[data-msg-id="${msgId}"] p`)?.textContent || '';
        navigator.clipboard.writeText(text).then(() => window.kop.flash('Скопировано'));
    }

    // ---------- ОТПРАВКА ФАЙЛОВ (ИСПРАВЛЕНА) ----------
    function initPrivateChatEvents(chatId) {
        const sendBtn = $('#send-msg-btn');
        const input = $('#typing-input');
        const container = document.querySelector('#messages-container');
        const attachBtn = $('#attach-file-btn');
        const fileInput = $('#attach-file-input');

        if (attachBtn && fileInput) {
            attachBtn.onclick = () => fileInput.click();
            fileInput.onchange = async (e) => {
                const file = e.target.files[0];
                if (!file) return;
                if (file.size > 10 * 1024 * 1024) {
                    window.kop.flash('Файл >10 МБ');
                    return;
                }
                const originalHtml = attachBtn.innerHTML;
                attachBtn.innerHTML = '<div class="spinner" style="width:20px;height:20px;border:2px solid #ccc;border-top-color:#3b5dd3;border-radius:50%;animation:spin 0.6s linear infinite;"></div>';
                attachBtn.disabled = true;
                const formData = new FormData();
                formData.append('file', file);
                formData.append('receiver_id', state.activeChatReceiverId);
                try {
                    const response = await fetch('/api/messages/send-file', {
                        method: 'POST',
                        headers: { 'X-CSRF-Token': window.csrfToken },
                        body: formData
                    });
                    if (!response.ok) throw new Error('Upload failed');
                    const resp = await response.json();
                    container.insertAdjacentHTML('beforeend', `<div class="myMessageBubble message" data-msg-id="${resp.message_id}"><p>📎 <a href="${resp.file_url}" target="_blank">${esc(file.name)}</a></p><div class="messageInfo"><p></p><p>${formatTime(new Date())}</p></div></div>`);
                    container.scrollTop = container.scrollHeight;
                    const cacheKey = 'private_' + chatId;
                    if (state.messagesCache[cacheKey]) {
                        state.messagesCache[cacheKey].messages.push({
                            id: resp.message_id,
                            sender_id: state.userId,
                            content: `📎 ${file.name}`,
                            file_url: resp.file_url,
                            created_at: new Date().toISOString()
                        });
                        if (resp.message_id > state.messagesCache[cacheKey].lastPolledId)
                            state.messagesCache[cacheKey].lastPolledId = resp.message_id;
                    }
                    const chatItem = state.allItems.find(i => i.id === chatId && i.type === 'private');
                    if (chatItem) {
                        chatItem.last_message = `📎 ${file.name}`;
                        updateChatPreview(chatId, 'private', chatItem.last_message, chatItem.unread_count);
                        state.localLastMessageUpdate[`private_${chatId}`] = Date.now();
                    } else {
                        setTimeout(() => loadChats(), 2000);
                    }
                    setTimeout(() => refreshChatList(), 10000);
                } catch(err) {
                    window.kop.flash('Ошибка загрузки файла');
                } finally {
                    attachBtn.innerHTML = originalHtml;
                    attachBtn.disabled = false;
                    fileInput.value = '';
                }
            };
        }
        $('#cancel-edit-btn')?.addEventListener('click', cancelEdit);
        if (container) {
            container.addEventListener('scroll', () => {
                if (container.scrollTop <= 50 && !state.loadingMoreMessages) {
                    const cache = state.messagesCache['private_' + chatId];
                    if (cache?.hasMore) loadPrivateMessages(chatId, true);
                }
            });
        }
        sendBtn.addEventListener('click', async () => {
            if (state.editingMessageId) { await submitEdit(); return; }
            const content = input.value.trim();
            if (!content) return;
            const tempId = 'temp_' + Date.now();
            container.insertAdjacentHTML('beforeend', `<div class="myMessageBubble message" data-msg-id="${tempId}"><p>${esc(content)}</p><div class="messageInfo"><p></p><p>${formatTime(new Date())}</p></div></div>`);
            container.scrollTop = container.scrollHeight;
            input.value = '';
            try {
                const response = await apiPost('/api/messages/send', { receiver_id: state.activeChatReceiverId, content });
                if (response.message_id) {
                    const tempEl = document.querySelector(`[data-msg-id="${tempId}"]`);
                    if (tempEl) tempEl.dataset.msgId = response.message_id;
                    const cache = state.messagesCache['private_' + chatId];
                    if (cache) {
                        cache.messages.push({ id: response.message_id, sender_id: state.userId, content, is_read: 0, created_at: new Date().toISOString() });
                        cache.lastPolledId = Math.max(cache.lastPolledId, response.message_id);
                    }
                    updateChatPreview(chatId, 'private', content, 0);
                }
            } catch(e) { window.kop.flash('Ошибка отправки'); document.querySelector(`[data-msg-id="${tempId}"]`)?.classList.add('error-message'); }
        });
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendBtn.click(); } });
    }

    function initGroupChatEvents(groupId) {
        const sendBtn = $('#send-msg-btn');
        const input = $('#typing-input');
        const container = document.querySelector('#messages-container');
        const attachBtn = $('#attach-file-btn');
        const fileInput = $('#attach-file-input');

        if (attachBtn && fileInput) {
            attachBtn.onclick = () => fileInput.click();
            fileInput.onchange = async (e) => {
                const file = e.target.files[0];
                if (!file) return;
                if (file.size > 10 * 1024 * 1024) {
                    window.kop.flash('Файл >10 МБ');
                    return;
                }
                const originalHtml = attachBtn.innerHTML;
                attachBtn.innerHTML = '<div class="spinner" style="width:20px;height:20px;border:2px solid #ccc;border-top-color:#3b5dd3;border-radius:50%;animation:spin 0.6s linear infinite;"></div>';
                attachBtn.disabled = true;
                const formData = new FormData();
                formData.append('file', file);
                formData.append('group_id', groupId);
                try {
                    const response = await fetch('/api/groups/send-file', {
                        method: 'POST',
                        headers: { 'X-CSRF-Token': window.csrfToken },
                        body: formData
                    });
                    if (!response.ok) throw new Error('Upload failed');
                    const resp = await response.json();
                    container.insertAdjacentHTML('beforeend', `<div class="myMessageBubble message" data-msg-id="${resp.message_id}"><p>📎 <a href="${resp.file_url}" target="_blank">${esc(file.name)}</a></p><div class="messageInfo"><p></p><p>${formatTime(new Date())}</p></div></div>`);
                    container.scrollTop = container.scrollHeight;
                    const cacheKey = 'group_' + groupId;
                    if (state.messagesCache[cacheKey]) {
                        state.messagesCache[cacheKey].messages.push({
                            id: resp.message_id,
                            sender_id: state.userId,
                            content: `📎 ${file.name}`,
                            file_url: resp.file_url,
                            created_at: new Date().toISOString()
                        });
                        if (resp.message_id > state.messagesCache[cacheKey].lastPolledId)
                            state.messagesCache[cacheKey].lastPolledId = resp.message_id;
                    }
                    const chatItem = state.allItems.find(i => i.id === groupId && i.type === 'group');
                    if (chatItem) {
                        chatItem.last_message = `📎 ${file.name}`;
                        updateChatPreview(groupId, 'group', chatItem.last_message, chatItem.unread_count);
                        state.localLastMessageUpdate[`group_${groupId}`] = Date.now();
                    } else {
                        setTimeout(() => loadChats(), 2000);
                    }
                    setTimeout(() => refreshChatList(), 10000);
                } catch(err) {
                    window.kop.flash('Ошибка загрузки файла');
                } finally {
                    attachBtn.innerHTML = originalHtml;
                    attachBtn.disabled = false;
                    fileInput.value = '';
                }
            };
        }
        $('#cancel-edit-btn')?.addEventListener('click', cancelEdit);
        if (container) {
            container.addEventListener('scroll', () => {
                if (container.scrollTop <= 50 && !state.loadingMoreMessages) {
                    const cache = state.messagesCache['group_' + groupId];
                    if (cache?.hasMore) loadGroupMessages(groupId, true);
                }
            });
        }
        sendBtn.addEventListener('click', async () => {
            if (state.editingMessageId) { await submitEdit(); return; }
            const content = input.value.trim();
            if (!content) return;
            const tempId = 'temp_' + Date.now();
            container.insertAdjacentHTML('beforeend', `<div class="myMessageBubble message" data-msg-id="${tempId}"><p>${esc(content)}</p><div class="messageInfo"><p></p><p>${formatTime(new Date())}</p></div></div>`);
            container.scrollTop = container.scrollHeight;
            input.value = '';
            try {
                const response = await apiPost(`/api/groups/${groupId}/messages`, { content });
                if (response.message_id) {
                    const tempEl = document.querySelector(`[data-msg-id="${tempId}"]`);
                    if (tempEl) tempEl.dataset.msgId = response.message_id;
                    const cache = state.messagesCache['group_' + groupId];
                    if (cache) {
                        cache.messages.push({ id: response.message_id, sender_id: state.userId, content, created_at: new Date().toISOString() });
                        cache.lastPolledId = Math.max(cache.lastPolledId, response.message_id);
                    }
                    updateChatPreview(groupId, 'group', content, 0);
                }
            } catch(e) { window.kop.flash('Ошибка отправки'); document.querySelector(`[data-msg-id="${tempId}"]`)?.classList.add('error-message'); }
        });
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendBtn.click(); } });
    }

    // ---------- МЕДИАХАБ ----------
    async function switchToMediaHub() {
        if (state.currentView === 'mediahub') return;
        state.currentView = 'mediahub';
        const messagesPanel = document.querySelector('.chat-messages-panel');
        const mediaPanel = document.querySelector('.mediahub-panel');
        const membersPanel = document.querySelector('.members-panel');
        if (messagesPanel) messagesPanel.style.display = 'none';
        if (mediaPanel) mediaPanel.style.display = 'flex';
        if (membersPanel) membersPanel.style.display = 'none';
        const url = new URL(window.location);
        url.searchParams.set('view', 'media');
        history.pushState(null, '', url);
        if (state.mediaItems.length === 0) await loadMediaHub(true);
        setupMediaInfiniteScroll();
    }

    async function switchToMembers() {
        if (state.currentView === 'members') return;
        state.currentView = 'members';
        const messagesPanel = document.querySelector('.chat-messages-panel');
        const mediaPanel = document.querySelector('.mediahub-panel');
        const membersPanel = document.querySelector('.members-panel');
        if (messagesPanel) messagesPanel.style.display = 'none';
        if (mediaPanel) mediaPanel.style.display = 'none';
        if (membersPanel) membersPanel.style.display = 'flex';
        const url = new URL(window.location);
        url.searchParams.set('view', 'members');
        history.pushState(null, '', url);
        await loadMembersList();
    }

    async function switchToMessages() {
        if (state.currentView === 'messages') return;
        state.currentView = 'messages';
        const messagesPanel = document.querySelector('.chat-messages-panel');
        const mediaPanel = document.querySelector('.mediahub-panel');
        const membersPanel = document.querySelector('.members-panel');
        if (messagesPanel) messagesPanel.style.display = 'flex';
        if (mediaPanel) mediaPanel.style.display = 'none';
        if (membersPanel) membersPanel.style.display = 'none';
        const url = new URL(window.location);
        url.searchParams.delete('view');
        history.pushState(null, '', url);
        if (state.mediaObserver) {
            state.mediaObserver.disconnect();
            state.mediaObserver = null;
        }
        // Запускаем polling для активного чата, если он есть
        if (state.activeChatType === 'private' && state.activeChatId) {
            startPollingForPrivate(state.activeChatId);
        } else if (state.activeChatType === 'group' && state.activeGroupId) {
            startPollingForGroup(state.activeGroupId);
        }
    }

    async function loadMediaHub(reset = true) {
        if (!state.activeChatId && !state.activeGroupId) return;
        const isGroup = state.activeChatType === 'group';
        const id = isGroup ? state.activeGroupId : state.activeChatId;
        if (!id) return;
        if (reset) {
            state.mediaPage = 1;
            state.mediaHasMore = true;
            state.mediaItems = [];
            const gridContainer = document.querySelector('.media-grid-container');
            if (gridContainer) gridContainer.innerHTML = '';
        }
        if (state.mediaLoading) return;
        state.mediaLoading = true;
        const endpoint = isGroup ? `/api/media/group/${id}` : `/api/media/private/${id}`;
        try {
            const data = await apiGet(`${endpoint}?type=${state.mediaType}&page=${state.mediaPage}&per_page=24`);
            const items = data.items || [];
            state.mediaHasMore = data.has_more === true;
            if (reset) state.mediaItems = items;
            else state.mediaItems = state.mediaItems.concat(items);
            renderMediaGrid(items, reset);
            if (state.mediaHasMore) state.mediaPage++;
        } catch(e) {
            console.error(e);
            const gridContainer = document.querySelector('.media-grid-container');
            if (gridContainer && reset) gridContainer.innerHTML = '<div class="media-empty">Ошибка загрузки медиа</div>';
        } finally {
            state.mediaLoading = false;
        }
    }

    function renderMediaGrid(items, reset) {
        const gridContainer = document.querySelector('.media-grid-container');
        if (!gridContainer) return;
        if (reset) gridContainer.innerHTML = '';
        if (!items.length && reset) {
            gridContainer.innerHTML = '<div class="media-empty">Нет файлов в этой категории</div>';
            return;
        }
        let html = '<div class="media-grid">';
        for (const item of items) {
            if (item.type === 'photo') {
                html += `
                    <div class="media-item" data-message-id="${item.message_id}">
                        <img src="${esc(item.url)}" loading="lazy" alt="photo">
                        <div class="media-item__info">
                            <div>${esc(item.sender_name)}</div>
                            <div>${new Date(item.created_at).toLocaleDateString()}</div>
                        </div>
                    </div>`;
            } else if (item.type === 'video') {
                html += `
                    <div class="media-item" data-message-id="${item.message_id}">
                        <video src="${esc(item.url)}" preload="metadata"></video>
                        <div class="media-item__info">
                            <div>${esc(item.sender_name)}</div>
                            <div>${new Date(item.created_at).toLocaleDateString()}</div>
                        </div>
                    </div>`;
            } else {
                html += `
                    <div class="media-item" data-message-id="${item.message_id}">
                        <div style="background:#f0f2f5; border-radius:12px; padding:20px; text-align:center;">
                            📄 ${esc(item.name)}
                        </div>
                        <div class="media-item__info">
                            <div>${esc(item.sender_name)}</div>
                            <div>${new Date(item.created_at).toLocaleDateString()}</div>
                        </div>
                    </div>`;
            }
        }
        html += '</div>';
        if (reset) gridContainer.innerHTML = html;
        else gridContainer.insertAdjacentHTML('beforeend', html);
        document.querySelectorAll('.media-item').forEach(el => {
            el.removeEventListener('click', mediaItemClickHandler);
            el.addEventListener('click', mediaItemClickHandler);
        });
    }

    async function mediaItemClickHandler(e) {
        const msgId = this.dataset.messageId;
        if (msgId) {
            await switchToMessages();
            setTimeout(() => {
                const msgEl = document.querySelector(`.message[data-msg-id="${msgId}"]`);
                if (msgEl) {
                    msgEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    msgEl.style.backgroundColor = '#fff3cd';
                    setTimeout(() => msgEl.style.backgroundColor = '', 2000);
                } else {
                    window.kop.flash('Сообщение не найдено');
                }
            }, 300);
        }
    }

    function setupMediaInfiniteScroll() {
        if (state.mediaObserver) state.mediaObserver.disconnect();
        const sentinel = document.createElement('div');
        sentinel.className = 'media-sentinel';
        sentinel.style.height = '20px';
        const container = document.querySelector('.media-grid-container');
        if (container) container.appendChild(sentinel);
        state.mediaObserver = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting && state.mediaHasMore && !state.mediaLoading && state.currentView === 'mediahub') {
                loadMediaHub(false);
            }
        }, { threshold: 0.1 });
        state.mediaObserver.observe(sentinel);
    }

    function initMediaTabs() {
        const tabs = document.querySelectorAll('.media-tab');
        tabs.forEach(tab => {
            tab.addEventListener('click', async (e) => {
                const type = tab.dataset.type;
                if (type === state.mediaType) return;
                state.mediaType = type;
                state.mediaPage = 1;
                state.mediaHasMore = true;
                state.mediaItems = [];
                await loadMediaHub(true);
            });
        });
        document.querySelectorAll('.media-tab').forEach(tab => {
            if (tab.dataset.type === state.mediaType) tab.classList.add('active');
            else tab.classList.remove('active');
        });
    }

    // ---------- УЧАСТНИКИ ГРУППЫ ----------
    async function loadMembersList() {
        if (!state.activeGroupId) return;
        if (state.membersLoading) return;
        state.membersLoading = true;
        const container = document.querySelector('.members-list-container');
        if (container) container.innerHTML = '<div class="media-loading">Загрузка участников...</div>';
        try {
            const data = await apiGet(`/api/groups/${state.activeGroupId}/members`);
            const members = data.members || [];
            state.groupMembers = members;
            renderMembersList(members);
        } catch(e) {
            console.error(e);
            if (container) container.innerHTML = '<div class="media-empty">Ошибка загрузки участников</div>';
        } finally {
            state.membersLoading = false;
        }
    }

    function renderMembersList(members) {
        const container = document.querySelector('.members-list-container');
        if (!container) return;
        if (!members.length) {
            container.innerHTML = '<div class="media-empty">Нет участников</div>';
            return;
        }
        let html = '<div class="members-grid">';
        for (const member of members) {
            const avatarHtml = member.avatar
                ? `<img class="member-avatar" src="${esc(member.avatar)}" alt="">`
                : `<div class="member-avatar member-avatar--placeholder">${getInitials(member.first_name + ' ' + member.last_name)}</div>`;
            const roleBadge = member.role === 'admin' ? '<span class="member-role-badge">Создатель</span>' : '';
            html += `
                <div class="member-item">
                    ${avatarHtml}
                    <div class="member-info">
                        <div class="member-name">${esc(member.first_name)} ${esc(member.last_name)}</div>
                        ${roleBadge}
                    </div>
                </div>
            `;
        }
        html += '</div>';
        container.innerHTML = html;
    }

    // ---------- МЕНЮ ТРОЕТОЧИЯ (ДОБАВЛЕН ПУНКТ ЗАКРЕПЛЕНИЯ) ----------
    function attachChatOptionsMenu(id, type, name, otherUserId = null, isPinned = false) {
        const btn = document.getElementById('chat-options-btn');
        if (!btn) return;
        const newBtn = btn.cloneNode(true);
        btn.parentNode.replaceChild(newBtn, btn);
        newBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            showChatOptionsMenu(e, id, type, name, otherUserId, isPinned);
        });
    }

    async function showChatOptionsMenu(e, id, type, name, otherUserId, isPinned) {
        e.preventDefault();
        const existing = document.getElementById('chat-actions-menu');
        if (existing) existing.remove();

        const menu = document.createElement('div');
        menu.id = 'chat-actions-menu';
        menu.className = 'msg-actions-menu';
        menu.style.minWidth = '220px';
        const items = [
            { icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="2.18"/><circle cx="8.5" cy="8.5" r="2.5"/><polyline points="21 15 16 10 5 21"/></svg>', label: 'Медиахаб', action: 'media' },
            { icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>', label: 'Удалить чат', action: 'delete', danger: true },
            { icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>', label: 'Заблокировать', action: 'block', danger: true },
            { icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4M12 16h.01"/><circle cx="12" cy="12" r="10"/></svg>', label: 'Пожаловаться', action: 'report' },
            { icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>', label: 'Архивировать', action: 'archive' },
            { icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>', label: 'Скачать историю', action: 'export' }
        ];
        // Вставляем пункт закрепления/открепления вторым (после медиахаба)
        const pinLabel = isPinned ? 'Открепить чат' : 'Закрепить чат';
        const pinIcon = isPinned
            ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>'
            : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
        items.splice(1, 0, { icon: pinIcon, label: pinLabel, action: 'pin' });

        let html = '';
        for (const item of items) {
            html += `<div class="msg-actions-menu__item ${item.danger ? 'msg-actions-menu__item--danger' : ''}" data-action="${item.action}">${item.icon} ${item.label}</div>`;
        }
        menu.innerHTML = html;
        document.body.appendChild(menu);

        const rect = e.target.getBoundingClientRect();
        menu.style.top = (rect.bottom + window.scrollY + 4) + 'px';
        menu.style.left = (rect.right + window.scrollX - 200) + 'px';
        menu.classList.add('active');

        const handleClick = async (event) => {
            const target = event.target.closest('.msg-actions-menu__item');
            if (!target) return;
            const action = target.dataset.action;
            menu.classList.remove('active');
            setTimeout(() => menu.remove(), 200);
            document.removeEventListener('click', outsideClick);
            switch (action) {
                case 'media': await switchToMediaHub(); break;
                case 'pin': await togglePin(id, type, isPinned); break;
                case 'delete':
                    if (confirm(`Очистить всю историю чата "${name}"? Действие необратимо.`)) {
                        await clearChatHistory(id, type);
                    }
                    break;
                case 'block':
                    if (type !== 'private') { window.kop.flash('Блокировка только для личных чатов'); return; }
                    if (confirm(`Заблокировать пользователя ${name}? Вы не сможете писать ему, и он вам.`)) {
                        await blockUser(otherUserId);
                        window.kop.flash('Пользователь заблокирован');
                    }
                    break;
                case 'report':
                    if (type !== 'private') { window.kop.flash('Жалоба только для личных чатов'); return; }
                    showReportModal(otherUserId, name);
                    break;
                case 'archive':
                    await archiveChatLocally(id, type);
                    window.kop.flash('Чат архивирован (локально)');
                    break;
                case 'export':
                    await exportChat(id, type, name);
                    break;
            }
        };
        const outsideClick = (event) => {
            if (!menu.contains(event.target)) {
                menu.classList.remove('active');
                setTimeout(() => menu.remove(), 200);
                document.removeEventListener('click', outsideClick);
            }
        };
        menu.addEventListener('click', handleClick);
        setTimeout(() => document.addEventListener('click', outsideClick), 10);
    }

    async function clearChatHistory(id, type) {
        try {
            if (type === 'private') await apiDelete(`/api/chats/${id}/clear`);
            else await apiDelete(`/api/groups/${id}/clear`);
            const cacheKey = type === 'private' ? 'private_'+id : 'group_'+id;
            state.messagesCache[cacheKey] = { messages: [], lastPolledId: 0, hasMore: true, page: 1 };
            if ((type === 'private' && state.activeChatId === id) || (type === 'group' && state.activeGroupId === id)) {
                const container = document.querySelector('#messages-container');
                if (container && state.currentView === 'messages') container.innerHTML = '<p class="media-empty">История очищена</p>';
            }
            updateChatPreview(id, type, null, 0);
            window.kop.flash('История чата очищена');
        } catch(e) { window.kop.flash('Ошибка очистки'); }
    }

    async function blockUser(userId) {
        try { await apiPost('/api/block', { user_id: userId }); }
        catch(e) { window.kop.flash('Ошибка блокировки'); }
    }

    function showReportModal(userId, userName) {
        const modal = document.getElementById('report-modal');
        const reasonText = document.getElementById('report-reason');
        if (!modal || !reasonText) return;
        reasonText.value = '';
        modal.style.display = 'flex';
        setTimeout(() => modal.classList.add('active'), 10);
        const submitBtn = document.getElementById('report-submit-btn');
        const closeModal = () => {
            modal.classList.remove('active');
            setTimeout(() => modal.style.display = 'none', 300);
            if (submitBtn) submitBtn.disabled = false;
        };
        document.getElementById('report-modal-close').onclick = closeModal;
        document.getElementById('report-cancel-btn').onclick = closeModal;
        if (submitBtn) {
            submitBtn.onclick = async () => {
                const reason = reasonText.value.trim();
                if (!reason) { window.kop.flash('Введите причину жалобы'); return; }
                submitBtn.disabled = true;
                try {
                    await apiPost('/api/report', { user_id: userId, reason });
                    window.kop.flash('Жалоба отправлена');
                    closeModal();
                } catch(e) { window.kop.flash('Ошибка отправки жалобы'); submitBtn.disabled = false; }
            };
        }
    }

    async function archiveChatLocally(id, type) {
        let archived = JSON.parse(localStorage.getItem('archived_chats') || '[]');
        archived.push({ id, type, archived_at: new Date().toISOString() });
        localStorage.setItem('archived_chats', JSON.stringify(archived));
        const node = document.querySelector(`.chatUnit[data-type="${type}"][data-id="${id}"]`);
        if (node) node.remove();
        state.allItems = state.allItems.filter(i => !(i.type === type && i.id == id));
    }

    async function exportChat(id, type, name) {
        let allMessages = [];
        let page = 1;
        const perPage = 100;
        let hasMore = true;
        try {
            while (hasMore) {
                const endpoint = type === 'private'
                    ? `/api/messages/${id}?page=${page}&per_page=${perPage}`
                    : `/api/groups/${id}/messages?page=${page}&per_page=${perPage}`;
                const data = await apiGet(endpoint);
                const msgs = data.messages || [];
                allMessages = allMessages.concat(msgs);
                if (msgs.length < perPage) hasMore = false;
                page++;
                await new Promise(r => setTimeout(r, 100));
            }
        } catch(e) {
            window.kop.flash('Ошибка загрузки истории: ' + e.message);
            return;
        }
        if (!allMessages.length) { window.kop.flash('Нет сообщений для экспорта'); return; }
        allMessages.sort((a,b) => new Date(a.created_at) - new Date(b.created_at));
        let html = `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>История чата: ${esc(name)}</title>
        <style>body{font-family:system-ui;margin:20px;background:#fafafa;} h1{color:#3b5dd3;} .date{background:#e0e7ff;padding:6px 12px;margin-top:20px;font-weight:bold;border-radius:20px;display:inline-block;} .msg{margin:12px 0;padding:8px 12px;background:white;border-radius:16px;} .sender{font-weight:600;} .time{color:#8b8fa3;font-size:0.75rem;margin-left:8px;}</style>
        </head><body><h1>📁 История чата: ${esc(name)}</h1>`;
        let lastDate = '';
        for (const msg of allMessages) {
            const date = new Date(msg.created_at);
            const dateStr = date.toLocaleDateString('ru-RU', { day:'numeric', month:'long', year:'numeric' });
            if (dateStr !== lastDate) {
                html += `<div class="date">${dateStr}</div>`;
                lastDate = dateStr;
            }
            const sender = msg.sender_id == state.userId ? 'Я' : (msg.first_name ? `${msg.first_name} ${msg.last_name}` : 'Собеседник');
            const time = formatTime(date);
            html += `<div class="msg"><span class="sender">${esc(sender)}</span> <span class="time">${time}</span><div>${esc(msg.content)}</div>`;
            if (msg.file_url) html += `<div><a href="${msg.file_url}">📎 Скачать файл</a></div>`;
            html += `</div>`;
        }
        html += `</body></html>`;
        const blob = new Blob([html], { type: 'text/html' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `chat_${name}_${new Date().toISOString().slice(0,19)}.html`;
        link.click();
        URL.revokeObjectURL(link.href);
        window.kop.flash(`Экспорт завершён (${allMessages.length} сообщений)`);
    }

    // ---------- СОЗДАНИЕ ГРУПП ----------
    async function initGroupCreation() {
        const createBtn = document.getElementById('create-group-btn');
        if (!createBtn) return;
        createBtn.addEventListener('click', async () => {
            const friendsListEl = document.getElementById('group-friends-list');
            if (!friendsListEl) return;
            const data = await apiGet('/api/friends').catch(() => ({ friends: [] }));
            const friends = data.friends || [];
            if (!friends.length) { window.kop.flash('Нет друзей для создания группы'); return; }
            friendsListEl.innerHTML = friends.map(f => `
                <label style="display:flex; align-items:center; gap:8px; padding:8px; cursor:pointer; border-bottom:1px solid #f0f2f5;">
                    <input type="checkbox" value="${f.id}">
                    <span>${esc(f.first_name)} ${esc(f.last_name)}</span>
                </label>
            `).join('');
            document.getElementById('group-name').value = '';
            const modal = document.getElementById('group-modal');
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('active'), 10);
        });
        document.getElementById('group-modal-close').onclick = () => closeGroupModal();
        document.getElementById('group-cancel-btn').onclick = () => closeGroupModal();
        document.getElementById('group-create-btn').onclick = async () => {
            const name = document.getElementById('group-name').value.trim();
            if (!name) { window.kop.flash('Введите название'); return; }
            const checkboxes = document.querySelectorAll('#group-friends-list input[type="checkbox"]:checked');
            const memberIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
            if (memberIds.length === 0) { window.kop.flash('Выберите хотя бы одного друга'); return; }
            const btn = document.getElementById('group-create-btn');
            btn.disabled = true;
            try {
                await apiPost('/api/groups/create', { name, member_ids: memberIds });
                window.kop.flash('Группа создана');
                closeGroupModal();
                await loadChats();
            } catch(e) { window.kop.flash('Ошибка создания'); }
            finally { btn.disabled = false; }
        };
        function closeGroupModal() {
            const modal = document.getElementById('group-modal');
            modal.classList.remove('active');
            setTimeout(() => modal.style.display = 'none', 300);
        }
    }

    // ---------- ОТКРЫТИЕ ЧАТОВ ----------
    async function openPrivateChat(chatId, push = true) {
        if (state.activeChatId === chatId && state.activeChatType === 'private') return;
        state.activeChatId = chatId;
        state.activeChatType = 'private';
        state.activeGroupId = null;
        state.currentView = 'messages';
        stopPolling();
        const chat = state.chats.find(c => c.chat_id == chatId);
        if (!chat) return;
        if (push) history.pushState(null, '', `?chat_id=${chatId}`);
        state.activeChatReceiverId = chat.other_user_id;

        let profileLinkId = chat.other_user_id;
        if (profileLinkId == state.userId) {
            profileLinkId = (chat.user1_id == state.userId) ? chat.user2_id : chat.user1_id;
        }

        const avatarHtml = (chat.avatar && chat.avatar.trim())
            ? `<img class="chatHeaderAvatar" src="${esc(chat.avatar)}" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">`
            : `<div class="chatHeaderAvatar" style="width:40px;height:40px;border-radius:50%;background:#e0e7ff;color:#3b5dd3;display:flex;align-items:center;justify-content:center;font-weight:600;">${getInitials(chat.first_name+' '+chat.last_name)}</div>`;

        chatViewPanel.innerHTML = `
            <div class="chatModule">
                <div class="chatHeader">
                    <button class="chatHeaderQuit">Назад</button>
                    ${avatarHtml}
                    <a href="/profile.php?id=${profileLinkId}" class="chatHeaderUsername" style="text-decoration:none; color:inherit;">${esc(chat.first_name)} ${esc(chat.last_name)}</a>
                    <button class="chatHeaderOptions" id="chat-options-btn" title="Действия"><div class="multiDot"></div><div class="multiDot"></div><div class="multiDot"></div></button>
                </div>
                <div class="chat-messages-panel">
                    <div class="chatBody" id="messages-container"></div>
                    <div class="chatTyping">
                        <input type="file" id="attach-file-input" style="display:none" accept="image/*,video/mp4,application/pdf">
                        <button class="chatTypingPin" id="attach-file-btn" title="Прикрепить файл"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></button>
                        <textarea id="typing-input" class="chatTypingInput" placeholder="Сообщение..."></textarea>
                        <button class="sendMessageButton" id="send-msg-btn" title="Отправить"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg></button>
                        <button class="sendMessageButton" id="cancel-edit-btn" style="display:none" title="Отмена">✕</button>
                    </div>
                </div>
                <div class="mediahub-panel" style="display: none;">
                    <div class="media-tabs">
                        <button class="media-tab" data-type="photo">Фото</button>
                        <button class="media-tab" data-type="video">Видео</button>
                        <button class="media-tab" data-type="file">Файлы</button>
                    </div>
                    <div class="media-grid-container"></div>
                </div>
                <div class="members-panel" style="display: none;">
                    <div class="members-header">Участники группы</div>
                    <div class="members-list-container"></div>
                </div>
            </div>
        `;
        initPrivateChatEvents(chatId);
        attachChatOptionsMenu(chatId, 'private', `${chat.first_name} ${chat.last_name}`, profileLinkId, chat.is_pinned);
        await loadPrivateMessages(chatId);
        startPollingForPrivate(chatId);
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('view') === 'media') await switchToMediaHub();
        else if (urlParams.get('view') === 'members') await switchToMembers();
    }

    async function openGroupChat(groupId, push = true) {
        if (state.activeGroupId === groupId && state.activeChatType === 'group') return;
        state.activeGroupId = groupId;
        state.activeChatType = 'group';
        state.activeChatId = null;
        state.currentView = 'messages';
        stopPolling();
        const group = state.groups.find(g => g.id == groupId);
        if (!group) return;
        if (push) history.pushState(null, '', `?group_id=${groupId}`);

        chatViewPanel.innerHTML = `
            <div class="chatModule">
                <div class="chatHeader">
                    <button class="chatHeaderQuit">Назад</button>
                    <div class="chatHeaderAvatar" style="background:#e8e0fc; color:#7c3aed; display:flex; align-items:center; justify-content:center; font-weight:600; width:40px; height:40px; border-radius:50%;">${group.name.charAt(0).toUpperCase()}</div>
                    <button class="chatHeaderGroupName" id="group-name-btn" style="background:none; border:none; font-weight:600; font-size:1.05rem; cursor:pointer; color:inherit;">${esc(group.name)}</button>
                    <button class="chatHeaderOptions" id="chat-options-btn" title="Действия"><div class="multiDot"></div><div class="multiDot"></div><div class="multiDot"></div></button>
                </div>
                <div class="chat-messages-panel">
                    <div class="chatBody" id="messages-container"></div>
                    <div class="chatTyping">
                        <input type="file" id="attach-file-input" style="display:none" accept="image/*,video/mp4,application/pdf">
                        <button class="chatTypingPin" id="attach-file-btn" title="Прикрепить файл"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></button>
                        <textarea id="typing-input" class="chatTypingInput" placeholder="Сообщение в группу..."></textarea>
                        <button class="sendMessageButton" id="send-msg-btn" title="Отправить"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg></button>
                        <button class="sendMessageButton" id="cancel-edit-btn" style="display:none" title="Отмена">✕</button>
                    </div>
                </div>
                <div class="mediahub-panel" style="display: none;">
                    <div class="media-tabs">
                        <button class="media-tab" data-type="photo">Фото</button>
                        <button class="media-tab" data-type="video">Видео</button>
                        <button class="media-tab" data-type="file">Файлы</button>
                    </div>
                    <div class="media-grid-container"></div>
                </div>
                <div class="members-panel" style="display: none;">
                    <div class="members-header">Участники группы</div>
                    <div class="members-list-container"></div>
                </div>
            </div>
        `;
        initGroupChatEvents(groupId);
        attachChatOptionsMenu(groupId, 'group', group.name, null, group.is_pinned);
        initMediaTabs();
        await loadGroupMessages(groupId);
        startPollingForGroup(groupId);
        const groupNameBtn = document.getElementById('group-name-btn');
        if (groupNameBtn) groupNameBtn.addEventListener('click', () => switchToMembers());
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('view') === 'media') await switchToMediaHub();
        else if (urlParams.get('view') === 'members') await switchToMembers();
    }

    // ---------- ИНИЦИАЛИЗАЦИЯ ----------
    function requestNotificationPermission() {
        if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
    }

    function initEscape() {
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (state.currentView === 'mediahub') {
                    switchToMessages();
                } else if (state.currentView === 'members') {
                    switchToMessages();
                } else {
                    const backBtn = document.querySelector('.chatHeaderQuit');
                    if (backBtn) backBtn.click();
                }
            }
        });
    }

    async function init() {
        state.userId = window.currentUserId;
        if (!state.userId) return;
        await loadChats();
        initRouter();
        initGroupCreation();
        startBackgroundRefresh();
        requestNotificationPermission();
        initEscape();
    }

    init();
})();