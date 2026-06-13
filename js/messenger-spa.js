(function() {
    // Полифилл для Array.prototype.findLastIndex (если отсутствует)
    if (!Array.prototype.findLastIndex) {
        Array.prototype.findLastIndex = function(predicate, thisArg) {
            for (let i = this.length - 1; i >= 0; i--) {
                if (predicate.call(thisArg, this[i], i, this)) return i;
            }
            return -1;
        };
    }

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
        editingMessageId: null,
        editingChatType: null,
        backgroundTimer: null,
        loadingMoreMessages: false,
        currentView: 'messages',
        localLastMessageUpdate: {},
        mediaType: 'photo',
        mediaPage: 1,
        mediaHasMore: true,
        mediaLoading: false,
        mediaItems: [],
        mediaObserver: null,
        groupMembers: [],
        membersLoading: false,
        chatScrollPositions: {},
        pendingFiles: [],
        pendingFilesContainer: null,
        currentPostId: null
    };

    // ---------- КАСТОМНОЕ МОДАЛЬНОЕ ОКНО ПОДТВЕРЖДЕНИЯ ----------
    function asyncConfirm(message, title = 'Подтверждение') {
        return new Promise((resolve) => {
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.style.display = 'flex';
            overlay.innerHTML = `
                <div class="modal-container" style="max-width:400px; padding:20px;">
                    <span class="modal-close" style="float:right; cursor:pointer;">&times;</span>
                    <h3 style="margin-top:0;">${esc(title)}</h3>
                    <p>${esc(message)}</p>
                    <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:20px;">
                        <button class="btn btn--secondary" id="confirm-cancel">Отмена</button>
                        <button class="btn btn--danger" id="confirm-ok">Удалить</button>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);
            setTimeout(() => overlay.classList.add('active'), 10);
            const close = (result) => {
                overlay.classList.remove('active');
                setTimeout(() => overlay.remove(), 300);
                resolve(result);
            };
            overlay.querySelector('.modal-close').addEventListener('click', () => close(false));
            overlay.addEventListener('click', (e) => { if (e.target === overlay) close(false); });
            overlay.querySelector('#confirm-cancel').addEventListener('click', () => close(false));
            overlay.querySelector('#confirm-ok').addEventListener('click', () => close(true));
        });
    }

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

    const esc = str => {
        if (window.kop && window.kop.esc) return window.kop.esc(str);
        if (str == null) return '';
        return String(str).replace(/[&<>]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[m] || m);
    };

    const icons = {
        edit: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>',
        delete: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
        copy: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>'
    };

    // ---------- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ----------
    function parseServerDate(dateStr) {
        if (!dateStr) return new Date();
        if (dateStr.includes('T') || dateStr.includes('Z')) return new Date(dateStr);
        return new Date(dateStr.replace(' ', 'T') + 'Z');
    }

    function getFileTypeFromUrl(url) {
        if (!url) return 'file';
        const ext = url.split('.').pop().toLowerCase();
        if (['jpg','jpeg','png','gif','webp','bmp','svg'].includes(ext)) return 'image';
        if (['mp4','webm','mov','avi','mkv'].includes(ext)) return 'video';
        if (['pdf'].includes(ext)) return 'pdf';
        return 'file';
    }

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Б';
        const k = 1024;
        const sizes = ['Б', 'КБ', 'МБ', 'ГБ'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function getFileTypeByExtension(file) {
        const ext = file.name.split('.').pop().toLowerCase();
        const videoExts = ['mp4','mov','avi','mkv','webm','flv','3gp','m4v','mpg','mpeg','wmv','ogv'];
        const imageExts = ['jpg','jpeg','png','gif','webp','bmp','svg','ico'];
        if (videoExts.includes(ext)) return 'video';
        if (imageExts.includes(ext)) return 'image';
        return 'file';
    }

    function renderFileMessage(fileUrl, fileName, isMine, msgId = null, createdAt = new Date()) {
        const type = getFileTypeFromUrl(fileUrl);
        const timeStr = formatTime(createdAt);
        const msgIdAttr = msgId ? `data-msg-id="${msgId}"` : '';
        if (type === 'image') {
            return `<div class="${isMine ? 'myMessageBubble' : 'receivedMessage'} message-file" ${msgIdAttr} data-is-mine="${isMine}">
                        <img class="message-image-preview" src="${esc(fileUrl)}" alt="${esc(fileName)}" loading="lazy" onclick="openImageViewer('${esc(fileUrl)}')">
                        <div class="messageInfo"><p></p><p>${timeStr}</p></div>
                    </div>`;
        } else if (type === 'video') {
            return `<div class="${isMine ? 'myMessageBubble' : 'receivedMessage'} message-file" ${msgIdAttr} data-is-mine="${isMine}">
                        <video class="message-video-preview" src="${esc(fileUrl)}" controls preload="metadata"></video>
                        <div class="messageInfo"><p></p><p>${timeStr}</p></div>
                    </div>`;
        } else {
            return `<div class="${isMine ? 'myMessageBubble' : 'receivedMessage'} message-file" ${msgIdAttr} data-is-mine="${isMine}">
                        <a href="${esc(fileUrl)}" target="_blank" class="file-attachment">📎 ${esc(fileName)}</a>
                        <div class="messageInfo"><p></p><p>${timeStr}</p></div>
                    </div>`;
        }
    }

    window.openImageViewer = function(url) {
        const viewer = document.createElement('div');
        viewer.className = 'image-viewer';
        viewer.innerHTML = `<img src="${url}" alt="">`;
        viewer.addEventListener('click', () => viewer.remove());
        document.body.appendChild(viewer);
    };

    function renderPostPreviewCard(previewData) {
        if (!previewData) return '';
        let p;
        try {
            p = typeof previewData === 'string' ? JSON.parse(previewData) : previewData;
            if (!p?.url) return '';
        } catch(e) { return ''; }
        const mediaHtml = p.media_url
            ? (p.media_type === 'video'
                ? `<video controls src="${esc(p.media_url)}" style="width:100%; max-height:150px; object-fit:cover; border-radius:8px;"></video>`
                : `<img src="${esc(p.media_url)}" style="width:100%; max-height:150px; object-fit:cover; border-radius:8px;">`)
            : '<div style="background:#eef1f8; height:120px; display:flex; align-items:center; justify-content:center; border-radius:8px;">📄 Пост без медиа</div>';
        return `
            <div class="post-preview-card" data-post-url="${esc(p.url)}" style="cursor:pointer; margin-top:8px; border:1px solid #e0e0e0; border-radius:12px; overflow:hidden; background:#fff; max-width:300px;">
                <div style="display:flex; align-items:center; gap:8px; padding:8px;">
                    <img src="${esc(p.author_avatar || '')}" style="width:28px; height:28px; border-radius:50%; object-fit:cover;" onerror="this.src=''">
                    <span style="font-weight:600; font-size:0.85rem;">${esc(p.author_name || 'Пользователь')}</span>
                </div>
                ${mediaHtml}
                <div style="padding:8px;">
                    <div style="font-size:0.85rem; color:#1e1e2f;">${esc(p.content || '')}</div>
                    <div style="font-size:0.7rem; color:#8b8fa3; margin-top:4px;">❤️ ${p.likes_count || 0} лайков</div>
                </div>
            </div>`;
    }

    // Превью вложений
    function renderPendingAttachments() {
        const container = state.pendingFilesContainer;
        if (!container) return;
        container.innerHTML = '';
        if (state.pendingFiles.length === 0) return;
        state.pendingFiles.forEach((item, idx) => {
            const div = document.createElement('div');
            div.className = 'attachment-item';
            div.setAttribute('data-idx', idx);
            if (item.type === 'image') {
                const img = document.createElement('img');
                img.src = item.previewUrl;
                div.appendChild(img);
            } else if (item.type === 'video') {
                const placeholder = document.createElement('div');
                placeholder.className = 'file-icon';
                placeholder.textContent = '🎬';
                div.appendChild(placeholder);
            } else {
                const placeholder = document.createElement('div');
                placeholder.className = 'file-icon';
                placeholder.textContent = '📄';
                div.appendChild(placeholder);
            }
            const infoSpan = document.createElement('div');
            infoSpan.className = 'attachment-info';
            infoSpan.textContent = `${item.file.name} (${formatFileSize(item.file.size)})`;
            infoSpan.style.cssText = 'position:absolute; bottom:0; left:0; right:0; background:rgba(0,0,0,0.7); color:white; font-size:10px; padding:2px 4px; text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;';
            div.appendChild(infoSpan);
            const removeBtn = document.createElement('button');
            removeBtn.textContent = '✕';
            removeBtn.className = 'remove-attachment';
            removeBtn.onclick = (e) => {
                e.stopPropagation();
                URL.revokeObjectURL(item.previewUrl);
                state.pendingFiles.splice(idx, 1);
                renderPendingAttachments();
            };
            div.appendChild(removeBtn);
            div.onclick = () => {
                if (item.type === 'image') window.openImageViewer(item.previewUrl);
                else {
                    const viewer = document.createElement('div');
                    viewer.className = 'image-viewer';
                    viewer.innerHTML = `<video controls autoplay src="${item.previewUrl}" style="max-width:90%; max-height:90%;"></video>`;
                    viewer.onclick = () => viewer.remove();
                    document.body.appendChild(viewer);
                }
            };
            container.appendChild(div);
        });
    }

    function clearPendingAttachments() {
        state.pendingFiles.forEach(item => URL.revokeObjectURL(item.previewUrl));
        state.pendingFiles = [];
        renderPendingAttachments();
    }

    function autoResizeTextarea() {
        const textarea = $('#typing-input');
        if (textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
        }
    }

    function createTempFileMessage(fileName, fileType) {
        const tempId = 'temp-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        return `<div class="myMessageBubble message-file" data-temp-id="${tempId}" style="opacity:0.6;">
            <div style="display:flex; align-items:center; gap:8px;">
                <div class="spinner" style="width:20px; height:20px; border:2px solid #ccc; border-top-color:#3b5dd3; border-radius:50%; animation: spin 0.8s linear infinite;"></div>
                <span>${esc(fileName)} загружается...</span>
            </div>
        </div>`;
    }

    function replaceTempMessage(container, tempId, finalHtml) {
        const tempEl = container.querySelector(`[data-temp-id="${tempId}"]`);
        if (tempEl) tempEl.outerHTML = finalHtml;
    }

    // ---------- ОТПРАВКА СООБЩЕНИЙ ----------
    async function sendMessageWithAttachments() {
        const input = $('#typing-input');
        const content = input.value.trim();
        const files = state.pendingFiles.map(f => f.file);

        if (!content && files.length === 0) return;

        const isGroup = state.activeChatType === 'group';
        const groupId = state.activeGroupId;
        const receiverId = state.activeChatReceiverId;

        const formData = new FormData();
        formData.append('content', content);
        if (isGroup) formData.append('group_id', groupId);
        else formData.append('receiver_id', receiverId);
        if (files.length > 0) formData.append('file', files[0]);

        const container = document.querySelector('#messages-container');
        const tempId = 'temp-' + Date.now();
        const tempHtml = `<div class="myMessageBubble message-file" data-temp-id="${tempId}" style="opacity:0.6;">
            <div class="spinner" style="width:20px; height:20px; border:2px solid #ccc; border-top-color:#3b5dd3; border-radius:50%; animation: spin 0.8s linear infinite;"></div>
            <span>${esc(content || 'Файл...')}</span>
        </div>`;
        container.insertAdjacentHTML('beforeend', tempHtml);
        container.scrollTop = container.scrollHeight;

        try {
            const endpoint = isGroup ? `/api/groups/${groupId}/messages` : '/api/messages/send';
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'X-CSRF-Token': window.csrfToken },
                body: formData
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();

            const tempEl = container.querySelector(`[data-temp-id="${tempId}"]`);
            if (tempEl) tempEl.remove();

            const date = parseServerDate(data.created_at);
            let msgHtml = data.file_url
                ? renderFileMessage(data.file_url, data.file_name || 'file', true, data.message_id, date)
                : `<div class="myMessageBubble message animate-in" data-msg-id="${data.message_id}" data-is-mine="true" data-chat-type="${isGroup ? 'group' : 'private'}">
                    <p>${esc(data.cleaned_content || data.content)}</p>
                    <div class="messageInfo"><p></p><p>${formatTime(date)}</p></div>
                </div>`;
            container.insertAdjacentHTML('beforeend', msgHtml);
            container.scrollTop = container.scrollHeight;

            const previewText = data.cleaned_content || (data.file_url ? '📎 Файл' : '');
            const previewType = isGroup ? 'group' : (state.activeChatType === 'collection' ? 'collection' : 'private');
            const previewId = isGroup ? groupId : state.activeChatId;
            updateChatPreview(previewId, previewType, previewText, 0);
            state.localLastMessageUpdate[`${previewType}_${previewId}`] = Date.now();

            // Обновляем last_message_at и поднимаем чат вверх
            const listItem = state.allItems.find(i => i.id == previewId && i.type === previewType);
            if (listItem) {
                listItem.last_message = previewText;
                listItem.last_message_at = data.created_at;
                moveChatToTop(listItem);
            }

            // Обновляем кэш
            const cacheKey = isGroup ? `group_${groupId}` : `private_${state.activeChatId}`;
            if (state.messagesCache[cacheKey]) {
                state.messagesCache[cacheKey].messages.push({
                    id: data.message_id, sender_id: state.userId, content: previewText,
                    file_url: data.file_url, created_at: data.created_at
                });
                state.messagesCache[cacheKey].messages.sort((a, b) => a.id - b.id);
                if (data.message_id > state.messagesCache[cacheKey].lastPolledId)
                    state.messagesCache[cacheKey].lastPolledId = data.message_id;
            }

            clearPendingAttachments();
            input.value = '';
            autoResizeTextarea();
        } catch (e) {
            window.kop.flash('Ошибка отправки');
            const tempEl = container.querySelector(`[data-temp-id="${tempId}"]`);
            if (tempEl) tempEl.innerHTML = '<span style="color:#b91c1c;">❌ Ошибка</span>';
        }
    }

    // ---------- ПЕРЕМЕЩЕНИЕ ЧАТА ВВЕРХ ----------
    function moveChatToTop(item) {
        if (item.is_pinned) return;
        const idx = state.allItems.indexOf(item);
        if (idx <= 0) return;
        state.allItems.splice(idx, 1);
        const lastPinnedIdx = state.allItems.findLastIndex(i => i.is_pinned);
        state.allItems.splice(lastPinnedIdx + 1, 0, item);
        updateChatListDOM(state.allItems);
    }

    // ---------- HTTP ЗАПРОСЫ ----------
    function getCsrfHeader() { return { 'X-CSRF-Token': window.csrfToken }; }
    async function apiGet(url, options = {}) {
        const res = await fetch(url, {
            method: 'GET',
            headers: { 'Content-Type': 'application/json', ...getCsrfHeader(), 'Accept': 'application/json', 'Cache-Control': 'no-cache, no-store' },
            signal: options.signal, cache: 'no-store'
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }
    async function apiPost(url, data) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', ...getCsrfHeader() },
            body: JSON.stringify(data)
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }
    async function apiPut(url, data) {
        const res = await fetch(url, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', ...getCsrfHeader() },
            body: JSON.stringify(data)
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }
    async function apiDelete(url) {
        const res = await fetch(url, { method: 'DELETE', headers: { ...getCsrfHeader() } });
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
    function formatTime(date) { return new Date(date).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }); }

    // ---------- СОХРАНЕНИЕ ПОЗИЦИИ СКРОЛЛА ----------
    let scrollSaveTimer;
    function saveCurrentChatScrollPosition() {
        if (!state.activeChatId && !state.activeGroupId) return;
        const container = document.querySelector('#messages-container');
        if (!container) return;
        const key = state.activeChatType === 'private' ? `private_${state.activeChatId}` : `group_${state.activeGroupId}`;
        state.chatScrollPositions[key] = container.scrollTop;
    }

    function initScrollSaveOnScroll() {
        const container = document.querySelector('#messages-container');
        if (container) {
            container.addEventListener('scroll', () => {
                clearTimeout(scrollSaveTimer);
                scrollSaveTimer = setTimeout(saveCurrentChatScrollPosition, 500);
            });
        }
    }

    // ---------- ПОДГРУЗКА СТАРЫХ СООБЩЕНИЙ ----------
    async function appendMessages(container, newMessages, isGroup = false) {
        if (!newMessages.length) return;
        const oldScrollHeight = container.scrollHeight;
        const oldScrollTop = container.scrollTop;
        let fragmentHtml = '', lastDate = '';
        for (const msg of newMessages) {
            const date = parseServerDate(msg.created_at);
            const dateStr = date.toLocaleDateString('ru-RU');
            if (dateStr !== lastDate) {
                fragmentHtml += `<div class="chatDateBubble"><p>${dateStr}</p></div>`;
                lastDate = dateStr;
            }
            const isMine = msg.sender_id == state.userId;
            const senderName = isGroup && !isMine ? `${msg.first_name} ${msg.last_name}` : '';
            fragmentHtml += msg.file_url
                ? renderFileMessage(msg.file_url, msg.file_url.split('/').pop(), isMine, msg.id, date)
                : `<div class="${isMine ? 'myMessageBubble' : 'receivedMessage'} message animate-in" data-msg-id="${msg.id}" data-is-mine="${isMine}" data-chat-type="${isGroup ? 'group' : 'private'}">
                    ${senderName ? `<div class="message-sender" style="font-size:0.75rem;color:#8b8fa3;margin-bottom:4px;">${esc(senderName)}</div>` : ''}
                    ${msg.content ? `<p>${esc(msg.content)}</p>` : ''}
                    ${msg.post_preview ? renderPostPreviewCard(msg.post_preview) : ''}
                    <div class="messageInfo"><p>${msg.is_read ? 'прочитано' : ''}</p><p>${formatTime(date)}</p></div>
                </div>`;
        }
        container.insertAdjacentHTML('afterbegin', fragmentHtml);
        container.scrollTop = oldScrollTop + (container.scrollHeight - oldScrollHeight);
    }

    // ---------- ЗАГРУЗКА СПИСКА ЧАТОВ ----------
    async function loadChats() {
        try {
            const [chatsData, groupsData] = await Promise.all([
                apiGet('/api/chats').catch(() => ({ chats: [] })),
                apiGet('/api/groups').catch(() => ({ groups: [] }))
            ]);
            state.chats = chatsData.chats || [];
            state.groups = groupsData.groups || [];

            state.allItems = [
                ...state.chats.map(c => {
                    const isCollection = c.user1_id == state.userId && c.user2_id == state.userId;
                    return {
                        type: isCollection ? 'collection' : 'private',
                        id: c.chat_id,
                        name: isCollection ? 'Коллекция' : `${c.first_name} ${c.last_name}`,
                        avatar: c.avatar || null,
                        last_message: c.last_message || 'Нет сообщений',
                        unread_count: c.unread_count,
                        is_pinned: c.is_pinned === 1,
                        last_message_at: c.last_message_at,
                        other_user_id: c.other_user_id
                    };
                }),
                ...state.groups.map(g => ({
                    type: 'group',
                    id: g.id,
                    name: g.name,
                    avatar: null,
                    last_message: g.last_message || 'Нет сообщений',
                    unread_count: g.unread_count || 0,
                    is_pinned: g.is_pinned === 1,
                    last_message_at: g.last_message_at || g.created_at
                }))
            ];

            state.allItems.sort((a, b) => {
                if (a.is_pinned && !b.is_pinned) return -1;
                if (!a.is_pinned && b.is_pinned) return 1;
                return new Date(b.last_message_at || 0) - new Date(a.last_message_at || 0);
            });
            updateChatListDOM(state.allItems);
        } catch(e) {
            console.error(e);
            if (chatListContainer) chatListContainer.innerHTML = '<p class="media-empty">Ошибка загрузки чатов</p>';
        }
    }

    function renderChatItem(item, isActive) {
        let avatarHtml = '';
        if (item.type === 'collection') avatarHtml = `<div class="chatUnitAvatar chatUnitAvatar--placeholder" style="background:#fef9c3; color:#b45309;">📌</div>`;
        else if (item.avatar) avatarHtml = `<img class="chatUnitAvatar" src="${esc(item.avatar)}" alt="">`;
        else avatarHtml = `<div class="chatUnitAvatar chatUnitAvatar--placeholder">${getInitials(item.name)}</div>`;
        return `
            <div class="chatUnit ${isActive ? 'active' : ''} ${item.is_pinned ? 'pinned' : ''}" data-type="${item.type}" data-id="${item.id}">
                ${avatarHtml}
                <div class="chatUnitContent">
                    <div class="chatUnitUsername"><p>${esc(item.name)}</p></div>
                    <div class="chatUnitPreview"><p>${esc(item.last_message || 'Нет сообщений')} <span class="unread-badge" style="display:${item.unread_count > 0 ? 'inline' : 'none'}">(${item.unread_count})</span></p></div>
                </div>
            </div>`;
    }

    function updateChatListDOM(newItems) {
        if (!chatListContainer) return;
        if (!newItems.length) {
            chatListContainer.innerHTML = '<p class="media-empty">Нет активных чатов</p>';
            return;
        }
        chatListContainer.innerHTML = newItems.map(item => {
            const isActive = (item.type === 'private' && item.id == state.activeChatId && state.activeChatType === 'private') ||
                            (item.type === 'group' && item.id == state.activeGroupId && state.activeChatType === 'group') ||
                            (item.type === 'collection' && item.id == state.activeChatId && state.activeChatType === 'collection');
            return renderChatItem(item, isActive);
        }).join('');
    }

    function updateChatPreview(id, type, last_message, unread_count) {
        const node = document.querySelector(`.chatUnit[data-type="${type}"][data-id="${id}"]`);
        if (!node) return;
        const previewP = node.querySelector('.chatUnitPreview p');
        if (previewP) {
            const badge = previewP.querySelector('.unread-badge');
            previewP.innerHTML = badge
                ? `${esc(last_message || 'Нет сообщений')} <span class="unread-badge" style="display:${unread_count > 0 ? 'inline' : 'none'}">(${unread_count})</span>`
                : esc(last_message || 'Нет сообщений');
        }
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

    // ---------- ПОЛЛИНГ ----------
    let pollingIntervals = {};

    function stopPolling() {
        Object.values(pollingIntervals).forEach(clearInterval);
        pollingIntervals = {};
    }

    function startPollingForPrivate(chatId) {
        if (pollingIntervals[`private_${chatId}`]) clearInterval(pollingIntervals[`private_${chatId}`]);
        pollingIntervals[`private_${chatId}`] = setInterval(async () => {
            if (state.currentView !== 'messages' || state.activeChatId !== chatId || (state.activeChatType !== 'private' && state.activeChatType !== 'collection')) return;
            const cache = state.messagesCache['private_'+chatId];
            if (!cache) return;
            const lastId = cache.lastPolledId || 0;
            try {
                const response = await fetch(`/api/messages/${chatId}/poll?after=${lastId}`, {
                    headers: { 'X-CSRF-Token': window.csrfToken, 'Accept': 'application/json' }
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();
                if (data.messages?.length) {
                    const container = document.querySelector('#messages-container');
                    if (container) {
                        for (const msg of data.messages) {
                            if (document.querySelector(`[data-msg-id="${msg.id}"]`)) continue;
                            const date = parseServerDate(msg.created_at);
                            const msgHtml = msg.file_url
                                ? renderFileMessage(msg.file_url, msg.file_url.split('/').pop(), msg.sender_id == state.userId, msg.id, date)
                                : `<div class="${msg.sender_id == state.userId ? 'myMessageBubble' : 'receivedMessage'} message animate-in" data-msg-id="${msg.id}" data-is-mine="${msg.sender_id == state.userId}" data-chat-type="private">
                                    ${msg.content ? `<p>${esc(msg.content)}</p>` : ''}
                                    ${msg.post_preview ? renderPostPreviewCard(msg.post_preview) : ''}
                                    <div class="messageInfo"><p>${msg.is_read ? 'прочитано' : ''}</p><p>${formatTime(date)}</p></div>
                                </div>`;
                            container.insertAdjacentHTML('beforeend', msgHtml);
                            if (msg.id > cache.lastPolledId) cache.lastPolledId = msg.id;
                        }
                        container.scrollTop = container.scrollHeight;
                        const lastMsg = data.messages[data.messages.length - 1];
                        if (lastMsg) {
                            updateChatPreview(chatId, 'private', lastMsg.content || (lastMsg.post_preview ? '📎 Пост' : ''), 0);
                            const listItem = state.allItems.find(i => i.id == chatId && i.type === 'private');
                            if (listItem) {
                                listItem.last_message = lastMsg.content || (lastMsg.file_url ? '📎 Файл' : '');
                                listItem.last_message_at = lastMsg.created_at;
                                moveChatToTop(listItem);
                            }
                            if (document.hidden || state.activeChatId != chatId) updateUnreadBadge(chatId, 'private', true);
                        }
                    }
                }
            } catch(e) { console.error('Poll error:', e); }
        }, 2500);
    }

    function startPollingForGroup(groupId) {
        if (pollingIntervals[`group_${groupId}`]) clearInterval(pollingIntervals[`group_${groupId}`]);
        pollingIntervals[`group_${groupId}`] = setInterval(async () => {
            if (state.currentView !== 'messages' || state.activeGroupId !== groupId || state.activeChatType !== 'group') return;
            const cache = state.messagesCache['group_'+groupId];
            if (!cache) return;
            const lastId = cache.lastPolledId || 0;
            try {
                const response = await fetch(`/api/groups/${groupId}/messages/poll?after=${lastId}`, {
                    headers: { 'X-CSRF-Token': window.csrfToken, 'Accept': 'application/json' }
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();
                if (data.messages?.length) {
                    const container = document.querySelector('#messages-container');
                    if (container) {
                        for (const msg of data.messages) {
                            if (document.querySelector(`[data-msg-id="${msg.id}"]`)) continue;
                            const date = parseServerDate(msg.created_at);
                            const isMine = msg.sender_id == state.userId;
                            const senderName = `${msg.first_name} ${msg.last_name}`;
                            const msgHtml = msg.file_url
                                ? renderFileMessage(msg.file_url, msg.file_url.split('/').pop(), isMine, msg.id, date)
                                : `<div class="${isMine ? 'myMessageBubble' : 'receivedMessage'} message animate-in" data-msg-id="${msg.id}" data-is-mine="${isMine}" data-chat-type="group">
                                    ${!isMine ? `<div class="message-sender" style="font-size:0.75rem;color:#8b8fa3;margin-bottom:4px;">${esc(senderName)}</div>` : ''}
                                    ${msg.content ? `<p>${esc(msg.content)}</p>` : ''}
                                    ${msg.post_preview ? renderPostPreviewCard(msg.post_preview) : ''}
                                    <div class="messageInfo"><p></p><p>${formatTime(date)}</p></div>
                                </div>`;
                            container.insertAdjacentHTML('beforeend', msgHtml);
                            if (msg.id > cache.lastPolledId) cache.lastPolledId = msg.id;
                        }
                        container.scrollTop = container.scrollHeight;
                        const lastMsg = data.messages[data.messages.length - 1];
                        if (lastMsg) {
                            updateChatPreview(groupId, 'group', lastMsg.content || (lastMsg.post_preview ? '📎 Пост' : ''), 0);
                            const listItem = state.allItems.find(i => i.id == groupId && i.type === 'group');
                            if (listItem) {
                                listItem.last_message = lastMsg.content || (lastMsg.file_url ? '📎 Файл' : '');
                                listItem.last_message_at = lastMsg.created_at;
                                moveChatToTop(listItem);
                            }
                            if (document.hidden || state.activeGroupId != groupId) updateUnreadBadge(groupId, 'group', true);
                        }
                    }
                }
            } catch(e) { console.error('Group poll error:', e); }
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
                if (messages.length) {
                    await appendMessages(container, messages, false);
                    cache.messages = messages.concat(cache.messages);
                    cache.page = page;
                    const lastMsgId = messages[messages.length-1]?.id;
                    if (lastMsgId) cache.lastPolledId = Math.max(cache.lastPolledId, lastMsgId);
                }
            } else {
                cache.messages = messages;
                cache.page = 1;
                cache.hasMore = true;
                if (messages.length) cache.lastPolledId = messages[messages.length-1].id;
                renderMessages(container, messages);
                updateUnreadBadge(chatId, 'private', false, true);
            }
        } catch(e) {
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
                if (messages.length) {
                    cache.messages = messages.concat(cache.messages).sort((a,b) => a.id - b.id);
                    cache.page = page;
                    const lastMsgId = messages[messages.length-1]?.id;
                    if (lastMsgId) cache.lastPolledId = Math.max(cache.lastPolledId, lastMsgId);
                    await appendMessages(container, messages, true);
                }
            } else {
                cache.messages = messages.sort((a,b) => a.id - b.id);
                cache.page = 1;
                cache.hasMore = true;
                if (messages.length) cache.lastPolledId = messages[messages.length-1].id;
                renderMessages(container, cache.messages, true);
                updateUnreadBadge(groupId, 'group', false, true);
            }
        } catch(e) {
            if (!append) container.innerHTML = '<p class="media-empty">Ошибка загрузки</p>';
        } finally {
            if (append) state.loadingMoreMessages = false;
        }
    }

    function renderMessages(container, messages, isGroup = false) {
        messages.sort((a,b) => a.id - b.id);
        let html = '', lastDate = '';
        for (const msg of messages) {
            const date = parseServerDate(msg.created_at);
            const dateStr = date.toLocaleDateString('ru-RU');
            if (dateStr !== lastDate) { html += `<div class="chatDateBubble"><p>${dateStr}</p></div>`; lastDate = dateStr; }
            const isMine = msg.sender_id == state.userId;
            const senderName = isGroup && !isMine ? `${msg.first_name} ${msg.last_name}` : '';
            html += msg.file_url
                ? renderFileMessage(msg.file_url, msg.file_url.split('/').pop(), isMine, msg.id, date)
                : `<div class="${isMine ? 'myMessageBubble' : 'receivedMessage'} message animate-in" data-msg-id="${msg.id}" data-is-mine="${isMine}" data-chat-type="${isGroup ? 'group' : 'private'}">
                    ${senderName ? `<div class="message-sender" style="font-size:0.75rem;color:#8b8fa3;margin-bottom:4px;">${esc(senderName)}</div>` : ''}
                    ${msg.content ? `<p>${esc(msg.content)}</p>` : ''}
                    ${msg.post_preview ? renderPostPreviewCard(msg.post_preview) : ''}
                    <div class="messageInfo"><p>${msg.is_read ? 'прочитано' : ''}</p><p>${formatTime(date)}</p></div>
                </div>`;
        }
        container.innerHTML = html || '<p class="media-empty">Нет сообщений</p>';
        setTimeout(() => container.scrollTop = container.scrollHeight, 50);
        attachMessageMenuEvents(container);
    }

    function attachMessageMenuEvents(container) {
        container.querySelectorAll('.message').forEach(msgDiv => {
            if (!msgDiv.dataset.menuAttached) {
                msgDiv.dataset.menuAttached = '1';
                msgDiv.addEventListener('click', (e) => {
                    if (e.target.closest('.post-preview-card, .message-image-preview, .message-video-preview, .file-attachment')) return;
                    e.stopPropagation();
                    const chatType = msgDiv.dataset.chatType || state.activeChatType;
                    showMsgMenu(e, msgDiv.dataset.msgId, msgDiv.dataset.isMine === 'true', chatType);
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

    function showMsgMenu(e, messageId, isMine, chatType) {
        e.preventDefault();
        const rect = e.target.getBoundingClientRect();
        msgMenu.style.top = (rect.bottom + window.scrollY + 4) + 'px';
        msgMenu.style.left = (rect.right + window.scrollX - 200) + 'px';
        msgMenu.innerHTML = isMine
            ? `<div class="msg-actions-menu__item" data-action="edit" data-msg-id="${messageId}" data-chat-type="${chatType}">${icons.edit} Редактировать</div>
               <div class="msg-actions-menu__item msg-actions-menu__item--danger" data-action="delete" data-msg-id="${messageId}" data-chat-type="${chatType}">${icons.delete} Удалить</div>`
            : `<div class="msg-actions-menu__item" data-action="copy" data-msg-id="${messageId}">${icons.copy} Скопировать</div>`;
        msgMenu.classList.add('active');
        msgMenu.querySelectorAll('.msg-actions-menu__item').forEach(item => {
            item.addEventListener('click', async () => {
                const action = item.dataset.action, msgId = item.dataset.msgId, chatTypeParam = item.dataset.chatType;
                if (action === 'edit') startEdit(msgId, chatTypeParam);
                else if (action === 'delete') deleteMessage(msgId, chatTypeParam);
                else if (action === 'copy') copyText(msgId);
                hideMsgMenu();
            });
        });
    }

    async function deleteMessage(msgId, chatType) {
        const confirmed = await asyncConfirm('Удалить сообщение?');
        if (!confirmed) return;
        try {
            const endpoint = chatType === 'private' ? `/api/messages/${msgId}` : `/api/group-messages/${msgId}`;
            await apiDelete(endpoint);
            const msgEl = document.querySelector(`.message[data-msg-id="${msgId}"]`);
            if (msgEl) msgEl.remove();
            const cacheKey = state.activeChatType === 'private' ? 'private_'+state.activeChatId : 'group_'+state.activeGroupId;
            if (state.messagesCache[cacheKey]) {
                state.messagesCache[cacheKey].messages = state.messagesCache[cacheKey].messages.filter(m => m.id != msgId);
            }
        } catch(e) { window.kop.flash('Ошибка удаления'); }
    }

    function startEdit(msgId, chatType) {
        const el = document.querySelector(`.message[data-msg-id="${msgId}"]`);
        if (!el) return;
        state.editingMessageId = msgId;
        state.editingChatType = chatType;
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
        const endpoint = state.editingChatType === 'private'
            ? `/api/messages/${state.editingMessageId}`
            : `/api/group-messages/${state.editingMessageId}`;
        try {
            const res = await apiPut(endpoint, { content: newContent });
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
        state.editingChatType = null;
        $('#typing-input').value = '';
        $('#typing-input').placeholder = 'Сообщение...';
        $('#send-msg-btn').style.display = '';
        $('#cancel-edit-btn').style.display = 'none';
        autoResizeTextarea();
    }

    function copyText(msgId) {
        const text = document.querySelector(`.message[data-msg-id="${msgId}"] p`)?.textContent || '';
        navigator.clipboard.writeText(text).then(() => window.kop.flash('Скопировано'));
    }

    // ---------- ИНИЦИАЛИЗАЦИЯ ЧАТОВ (ОБРАБОТЧИКИ) ----------
    function initPrivateChatEvents(chatId) {
        const sendBtn = $('#send-msg-btn');
        const input = $('#typing-input');
        const container = document.querySelector('#messages-container');
        const attachBtn = $('#attach-file-btn');
        const fileInput = $('#attach-file-input');

        state.pendingFilesContainer = document.getElementById('attachments-preview');
        if (!state.pendingFilesContainer) {
            const typingDiv = document.querySelector('.chatTyping');
            if (typingDiv) {
                const newDiv = document.createElement('div');
                newDiv.id = 'attachments-preview';
                newDiv.className = 'attachments-preview';
                typingDiv.parentNode.insertBefore(newDiv, typingDiv);
                state.pendingFilesContainer = newDiv;
            }
        }

        if (attachBtn && fileInput) {
            attachBtn.onclick = () => fileInput.click();
            fileInput.onchange = async (e) => {
                const files = Array.from(e.target.files);
                for (const file of files) {
                    const MAX_FILE_SIZE_MB = 1024;
                    if (file.size > MAX_FILE_SIZE_MB * 1024 * 1024) {
                        window.kop.flash(`Файл больше ${MAX_FILE_SIZE_MB} МБ`);
                        continue;
                    }
                    const fileType = getFileTypeByExtension(file);
                    const previewUrl = URL.createObjectURL(file);
                    state.pendingFiles.push({ file, previewUrl, type: fileType });
                }
                renderPendingAttachments();
                fileInput.value = '';
            };
        }
        $('#cancel-edit-btn')?.addEventListener('click', cancelEdit);
        if (container) {
            let scrollTimeout;
            container.addEventListener('scroll', () => {
                clearTimeout(scrollTimeout);
                scrollTimeout = setTimeout(() => {
                    if (container.scrollTop <= 50 && !state.loadingMoreMessages) {
                        const cache = state.messagesCache['private_' + chatId];
                        if (cache?.hasMore) loadPrivateMessages(chatId, true);
                    }
                }, 150);
            });
        }
        sendBtn.addEventListener('click', async () => {
            if (state.editingMessageId) { await submitEdit(); return; }
            await sendMessageWithAttachments();
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendBtn.click();
            }
            autoResizeTextarea();
        });
        autoResizeTextarea();
    }

    function initGroupChatEvents(groupId) {
        const sendBtn = $('#send-msg-btn');
        const input = $('#typing-input');
        const container = document.querySelector('#messages-container');
        const attachBtn = $('#attach-file-btn');
        const fileInput = $('#attach-file-input');

        state.pendingFilesContainer = document.getElementById('attachments-preview');
        if (!state.pendingFilesContainer) {
            const typingDiv = document.querySelector('.chatTyping');
            if (typingDiv) {
                const newDiv = document.createElement('div');
                newDiv.id = 'attachments-preview';
                newDiv.className = 'attachments-preview';
                typingDiv.parentNode.insertBefore(newDiv, typingDiv);
                state.pendingFilesContainer = newDiv;
            }
        }

        if (attachBtn && fileInput) {
            attachBtn.onclick = () => fileInput.click();
            fileInput.onchange = async (e) => {
                const files = Array.from(e.target.files);
                for (const file of files) {
                    const MAX_FILE_SIZE_MB = 1024;
                    if (file.size > MAX_FILE_SIZE_MB * 1024 * 1024) {
                        window.kop.flash(`Файл больше ${MAX_FILE_SIZE_MB} МБ`);
                        continue;
                    }
                    const fileType = getFileTypeByExtension(file);
                    const previewUrl = URL.createObjectURL(file);
                    state.pendingFiles.push({ file, previewUrl, type: fileType });
                }
                renderPendingAttachments();
                fileInput.value = '';
            };
        }
        $('#cancel-edit-btn')?.addEventListener('click', cancelEdit);
        if (container) {
            let scrollTimeout;
            container.addEventListener('scroll', () => {
                clearTimeout(scrollTimeout);
                scrollTimeout = setTimeout(() => {
                    if (container.scrollTop <= 50 && !state.loadingMoreMessages) {
                        const cache = state.messagesCache['group_' + groupId];
                        if (cache?.hasMore) loadGroupMessages(groupId, true);
                    }
                }, 150);
            });
        }
        sendBtn.addEventListener('click', async () => {
            if (state.editingMessageId) { await submitEdit(); return; }
            await sendMessageWithAttachments();
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendBtn.click();
            }
            autoResizeTextarea();
        });
        autoResizeTextarea();
    }

    // ---------- ПАНЕЛЬ ПРОСМОТРА ПОСТА ----------
    const postCache = {};

    function renderPostForChat(post) {
        const fullName = esc(post.first_name) + ' ' + esc(post.last_name);
        const profileUrl = (post.user_id == state.userId) ? '/profile.php' : `/user.php?id=${post.user_id}`;
        const isLiked = (post.user_reaction === 'like');
        const isDisliked = (post.user_reaction === 'dislike');
        
        let mediaHtml = '';
        if (post.media && post.media.length) {
            mediaHtml = '<div class="carousel-container"><div class="carousel-track">';
            for (const media of post.media) {
                if (media.media_type === 'video') {
                    mediaHtml += `<div class="carousel-slide"><video controls src="${esc(media.file_url)}" preload="metadata"></video></div>`;
                } else {
                    mediaHtml += `<div class="carousel-slide"><img src="${esc(media.file_url)}" alt=""></div>`;
                }
            }
            mediaHtml += '</div>';
            if (post.media.length > 1) {
                mediaHtml += `<button class="carousel-prev"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg></button>
                             <button class="carousel-next"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></button>
                             <div class="carousel-dots"></div>`;
            }
            mediaHtml += '</div>';
        }
        const textHtml = post.content ? `<div class="postBodyText">${esc(post.content)}</div>` : '';
        let avatarHtml = '';
        if (post.avatar) {
            avatarHtml = `<img class="opPicture" src="${esc(post.avatar)}" alt="" onerror="this.onerror=null;this.src='';this.style.display='none';this.nextSibling.style.display='flex';">`;
            avatarHtml += `<div class="opPicture-placeholder" style="display:none;">${esc((post.first_name?.charAt(0)||'')+(post.last_name?.charAt(0)||''))}</div>`;
        } else {
            avatarHtml = `<div class="opPicture-placeholder">${esc((post.first_name?.charAt(0)||'')+(post.last_name?.charAt(0)||''))}</div>`;
        }
        
        return `
            <div class="post" data-post-id="${post.id}" data-author-id="${post.user_id}">
                <div class="postHeader">
                    ${avatarHtml}
                    <div class="opLabel"><a href="${profileUrl}">${fullName}</a></div>
                </div>
                <div class="postBody">${mediaHtml}${textHtml}</div>
                <div class="postFooter">
                    <div class="postReactions">
                        <button class="likeButton ${isLiked ? 'active' : ''}" data-post-id="${post.id}">
                            <span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            </span>
                        </button>
                        <p class="positiveCounter">${post.likes_count}</p>
                        <button class="dislikeButton ${isDisliked ? 'active' : ''}" data-post-id="${post.id}">
                            <span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            </span>
                        </button>
                        <p class="negativeCounter">${post.dislikes_count}</p>
                    </div>
                    <div class="postActions">
                        <button class="collectionButton" data-post-id="${post.id}" title="В коллекцию">
                            <span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
                            </span>
                        </button>
                        <button class="sharePost" data-post-id="${post.id}">
                            <span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98"/><path d="M15.41 6.51l-6.82 3.98"/></svg>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    async function addToCollection(postId) {
        try {
            const resp = await apiPost('/api/collection/add', { post_id: postId });
            if (resp.success) {
                kop.flash('Добавлено в коллекцию');
                await loadChats();
            } else {
                kop.flash(resp.error || 'Ошибка');
            }
        } catch(e) {
            kop.flash('Ошибка при добавлении');
        }
    }

    async function switchToPostView(postId) {
        saveCurrentChatScrollPosition();
        if (state.currentView === 'post' && state.currentPostId === postId) return;
        state.currentView = 'post';
        state.currentPostId = postId;

        const messagesPanel = document.querySelector('.chat-messages-panel');
        const mediaPanel = document.querySelector('.mediahub-panel');
        const membersPanel = document.querySelector('.members-panel');
        const postPanel = document.querySelector('.post-view-panel');
        if (messagesPanel) messagesPanel.style.display = 'none';
        if (mediaPanel) mediaPanel.style.display = 'none';
        if (membersPanel) membersPanel.style.display = 'none';
        if (postPanel) postPanel.style.display = 'flex';

        const url = new URL(window.location);
        url.searchParams.set('view', 'post');
        url.searchParams.set('post_id', postId);
        history.pushState(null, '', url);

        const postContainer = document.getElementById('post-detail-container');
        const commentsContainer = document.getElementById('post-comments-container');
        if (!postContainer || !commentsContainer) return;

        let postData = postCache[postId];
        if (!postData) {
            try {
                const response = await apiGet(`/api/posts/${postId}`);
                if (response.error) throw new Error(response.error);
                postData = response;
                postCache[postId] = postData;
            } catch (e) {
                postContainer.innerHTML = `<p class="media-empty">Ошибка загрузки поста</p>`;
                return;
            }
        }
        postContainer.innerHTML = renderPostForChat(postData);

        const carousel = postContainer.querySelector('.carousel-container');
        if (carousel && typeof window.initCarousel === 'function') {
            window.initCarousel(carousel);
        }

        postContainer.querySelectorAll('.likeButton, .dislikeButton').forEach(btn => {
            btn.addEventListener('click', async function() {
                const reactionType = this.classList.contains('likeButton') ? 'like' : 'dislike';
                const endpoint = reactionType === 'like' ? '/api/posts/like' : '/api/posts/dislike';
                try {
                    const resp = await apiPost(endpoint, { post_id: postId });
                    if (resp.success) {
                        const postDiv = this.closest('.post');
                        postDiv.querySelector('.positiveCounter').textContent = resp.likes_count;
                        postDiv.querySelector('.negativeCounter').textContent = resp.dislikes_count;
                        const likeBtn = postDiv.querySelector('.likeButton');
                        const dislikeBtn = postDiv.querySelector('.dislikeButton');
                        likeBtn.classList.toggle('active', resp.user_liked);
                        dislikeBtn.classList.toggle('active', resp.user_disliked);
                    }
                } catch (e) {}
            });
        });

        postContainer.querySelector('.collectionButton')?.addEventListener('click', () => {
            addToCollection(postId);
        });

        postContainer.querySelector('.sharePost')?.addEventListener('click', () => {
            openShareModal(postId);
        });

        await loadCommentsForPostView(postId, commentsContainer);
    }

    async function loadCommentsForPostView(postId, container) {
        try {
            const data = await apiGet(`/api/posts/${postId}/comments`);
            const comments = data.comments || [];
            comments.reverse();
            let html = '';
            if (comments.length === 0) {
                html = '<p class="no-comments">Нет комментариев. Будьте первым!</p>';
            } else {
                html = comments.map(c => {
                    const initials = (c.first_name?.charAt(0) || '') + (c.last_name?.charAt(0) || '');
                    const avatarHtml = c.avatar
                        ? `<img src="${esc(c.avatar)}" alt="">`
                        : `<span class="comment-avatar-placeholder">${initials}</span>`;
                    return `
                        <div class="comment-item">
                            <div class="comment-avatar">${avatarHtml}</div>
                            <div class="comment-content">
                                <div class="comment-author"><a href="user.php?id=${c.user_id}">${esc(c.first_name)} ${esc(c.last_name)}</a></div>
                                <div class="comment-text">${esc(c.content)}</div>
                                <div class="comment-date">${new Date(c.created_at).toLocaleString('ru-RU')}</div>
                            </div>
                        </div>
                    `;
                }).join('');
            }
            container.innerHTML = `
                <div class="comment-form">
                    <textarea id="post-comment-input" placeholder="Написать комментарий..."></textarea>
                    <button id="post-comment-send-btn">
                        <span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        </span>
                    </button>
                </div>
                <div class="comments-list">${html}</div>
            `;

            const sendBtn = container.querySelector('#post-comment-send-btn');
            const input = container.querySelector('#post-comment-input');
            sendBtn.addEventListener('click', async () => {
                const content = input.value.trim();
                if (!content) return;
                try {
                    const resp = await apiPost(`/api/posts/${postId}/comments`, { content });
                    if (resp.success) {
                        const c = resp.comment;
                        const initials = (c.first_name?.charAt(0) || '') + (c.last_name?.charAt(0) || '');
                        const avatarHtml = c.avatar
                            ? `<img src="${esc(c.avatar)}" alt="">`
                            : `<span class="comment-avatar-placeholder">${initials}</span>`;
                        const newCommentHtml = `
                            <div class="comment-item">
                                <div class="comment-avatar">${avatarHtml}</div>
                                <div class="comment-content">
                                    <div class="comment-author"><a href="user.php?id=${c.user_id}">${esc(c.first_name)} ${esc(c.last_name)}</a></div>
                                    <div class="comment-text">${esc(c.content)}</div>
                                    <div class="comment-date">${new Date(c.created_at).toLocaleString('ru-RU')}</div>
                                </div>
                            </div>
                        `;
                        const list = container.querySelector('.comments-list');
                        if (list.querySelector('.no-comments')) list.innerHTML = '';
                        list.insertAdjacentHTML('afterbegin', newCommentHtml);
                        input.value = '';
                    }
                } catch (e) { kop.flash('Ошибка отправки комментария'); }
            });
        } catch (e) {
            container.innerHTML = '<p class="error">Ошибка загрузки комментариев</p>';
        }
    }

    // ---------- ШЕРИНГ (модальное окно) ----------
    async function openShareModal(postId) {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'share-modal';
        overlay.style.display = 'flex';
        
        const container = document.createElement('div');
        container.className = 'modal-container';
        container.style.maxWidth = '400px';
        container.style.padding = '20px';
        container.innerHTML = `
            <span class="modal-close" id="share-modal-close">&times;</span>
            <h3 style="margin:0 0 16px;">Отправить пост</h3>
            <div id="share-chat-list" style="max-height:300px;overflow-y:auto;"></div>
        `;
        overlay.appendChild(container);
        document.body.appendChild(overlay);
        setTimeout(() => overlay.classList.add('active'), 10);

        const chatListDiv = container.querySelector('#share-chat-list');
        try {
            const data = await apiGet('/api/chats');
            const chats = data.chats || [];
            if (chats.length === 0) {
                chatListDiv.innerHTML = '<p style="color:#8b8fa3;text-align:center;padding:20px;">Нет активных чатов</p>';
            } else {
                chatListDiv.innerHTML = chats.map(chat => `
                    <div class="share-chat-item" data-chat-id="${chat.chat_id}" data-other-user="${chat.other_user_id}"
                         style="display:flex;align-items:center;gap:12px;padding:12px;cursor:pointer;border-radius:12px;transition:background 0.2s;"
                         onmouseover="this.style.background='#f5f6fa'" onmouseout="this.style.background=''">
                        ${chat.avatar ? `<img src="${esc(chat.avatar)}" style="width:40px;height:40px;border-radius:50%;object-fit:cover;background:#f0f0f0;">` 
                                    : `<div style="width:40px;height:40px;border-radius:50%;background:#e0e7ff;color:#3b5dd3;display:flex;align-items:center;justify-content:center;font-weight:600;">${esc(chat.first_name?.charAt(0)||'')}${esc(chat.last_name?.charAt(0)||'')}</div>`}
                        <span style="font-weight:500;">${esc(chat.first_name)} ${esc(chat.last_name)}</span>
                    </div>
                `).join('');
                
                chatListDiv.querySelectorAll('.share-chat-item').forEach(item => {
                    item.addEventListener('click', async () => {
                        const otherUserId = item.dataset.otherUser;
                        const postUrl = `${window.location.origin}/post.php?id=${postId}`;
                        try {
                            await apiPost('/api/messages/send', { receiver_id: otherUserId, content: postUrl });
                            kop.flash('Пост отправлен');
                        } catch (e) { kop.flash('Ошибка при отправке'); }
                        overlay.classList.remove('active');
                        setTimeout(() => overlay.remove(), 300);
                    });
                });
            }
        } catch (e) {
            chatListDiv.innerHTML = '<p style="color:#b91c1c;text-align:center;padding:20px;">Ошибка загрузки чатов</p>';
        }

        const closeModal = () => {
            overlay.classList.remove('active');
            setTimeout(() => overlay.remove(), 300);
        };
        container.querySelector('#share-modal-close').addEventListener('click', closeModal);
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal();
        });
    }

    // Обработчик клика по карточке репоста
    document.addEventListener('click', (e) => {
        const card = e.target.closest('.post-preview-card');
        if (card && card.dataset.postUrl) {
            e.preventDefault();
            const url = card.dataset.postUrl;
            const match = url.match(/id=(\d+)/);
            if (match && match[1]) {
                switchToPostView(parseInt(match[1]));
            } else {
                window.open(url, '_blank');
            }
        }
    });

    // ---------- МЕДИАХАБ ----------
    async function switchToMediaHub() {
        saveCurrentChatScrollPosition();
        if (state.currentView === 'mediahub') return;
        state.currentView = 'mediahub';

        const messagesPanel = document.querySelector('.chat-messages-panel');
        const mediaPanel = document.querySelector('.mediahub-panel');
        const membersPanel = document.querySelector('.members-panel');
        const postPanel = document.querySelector('.post-view-panel');

        if (messagesPanel) messagesPanel.style.display = 'none';
        if (mediaPanel) mediaPanel.style.display = 'flex';
        if (membersPanel) membersPanel.style.display = 'none';
        if (postPanel) postPanel.style.display = 'none';

        const url = new URL(window.location);
        url.searchParams.set('view', 'media');
        url.searchParams.delete('post_id');
        history.pushState(null, '', url);

        initMediaTabs();

        if (state.mediaItems.length === 0) {
            await loadMediaHub(true);
        }
        setupMediaInfiniteScroll();
    }

    async function switchToMembers() {
        saveCurrentChatScrollPosition();
        if (state.currentView === 'members') return;
        state.currentView = 'members';
        const messagesPanel = document.querySelector('.chat-messages-panel');
        const mediaPanel = document.querySelector('.mediahub-panel');
        const membersPanel = document.querySelector('.members-panel');
        const postPanel = document.querySelector('.post-view-panel');
        if (messagesPanel) messagesPanel.style.display = 'none';
        if (mediaPanel) mediaPanel.style.display = 'none';
        if (membersPanel) membersPanel.style.display = 'flex';
        if (postPanel) postPanel.style.display = 'none';
        const url = new URL(window.location);
        url.searchParams.set('view', 'members');
        url.searchParams.delete('post_id');
        history.pushState(null, '', url);
        await loadMembersList();
    }

    async function switchToMessages() {
        saveCurrentChatScrollPosition();
        if (state.currentView === 'messages') return;
        state.currentView = 'messages';
        state.currentPostId = null;
        const messagesPanel = document.querySelector('.chat-messages-panel');
        const mediaPanel = document.querySelector('.mediahub-panel');
        const membersPanel = document.querySelector('.members-panel');
        const postPanel = document.querySelector('.post-view-panel');
        if (messagesPanel) messagesPanel.style.display = 'flex';
        if (mediaPanel) mediaPanel.style.display = 'none';
        if (membersPanel) membersPanel.style.display = 'none';
        if (postPanel) postPanel.style.display = 'none';
        const url = new URL(window.location);
        url.searchParams.delete('view');
        url.searchParams.delete('post_id');
        history.pushState(null, '', url);
        if (state.mediaObserver) {
            state.mediaObserver.disconnect();
            state.mediaObserver = null;
        }
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

            if (reset) {
                state.mediaItems = items;
            } else {
                state.mediaItems = state.mediaItems.concat(items);
            }

            renderMediaGrid(items, reset);
            if (state.mediaHasMore) state.mediaPage++;

            if (state.activeChatType === 'collection' && items.length === 0) {
                console.warn('Медиахаб коллекции: API вернул 0 элементов. Возможно, нет файлов или серверный эндпоинт не поддерживает коллекцию.');
            }
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
        if (!msgId) return;

        const mediaItem = this;
        const img = mediaItem.querySelector('img');
        const video = mediaItem.querySelector('video');
        const fileLink = mediaItem.querySelector('a');

        if (img && img.src) {
            if (typeof openFullMedia === 'function') {
                openFullMedia(img.src, 'image');
            } else if (typeof window.openImageViewer === 'function') {
                window.openImageViewer(img.src);
            } else {
                window.open(img.src, '_blank');
            }
        } else if (video && video.src) {
            if (typeof openFullMedia === 'function') {
                openFullMedia(video.src, 'video');
            } else {
                window.open(video.src, '_blank');
            }
        } else if (fileLink && fileLink.href) {
            window.open(fileLink.href, '_blank');
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
            if (tab.dataset.type === state.mediaType) {
                tab.classList.add('active');
            } else {
                tab.classList.remove('active');
            }
        });

        tabs.forEach(tab => {
            tab.addEventListener('click', async (e) => {
                const type = tab.dataset.type;
                if (type === state.mediaType) return;

                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');

                state.mediaType = type;
                state.mediaPage = 1;
                state.mediaHasMore = true;
                state.mediaItems = [];
                await loadMediaHub(true);
            });
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
        let html = '';
        for (const member of members) {
            if (!member.id) continue;
            const avatarHtml = member.avatar
                ? `<img class="member-avatar" src="${esc(member.avatar)}" alt="">`
                : `<div class="member-avatar member-avatar--placeholder">${getInitials(member.first_name + ' ' + member.last_name)}</div>`;
            const roleBadge = member.role === 'admin' ? '<span class="member-role-badge">админ</span>' : '';
            const isSelf = member.id == state.userId;
            const profileUrl = isSelf ? `/profile.php` : `/user.php?id=${member.id}`;
            html += `
                <div class="member-item" data-user-id="${member.id}">
                    ${avatarHtml}
                    <div class="member-info">
                        <a href="${profileUrl}" class="member-name-link">${esc(member.first_name)} ${esc(member.last_name)}</a>
                        ${roleBadge}
                    </div>
                </div>
            `;
        }
        container.innerHTML = html;
    }

    // ---------- МЕНЮ ТРОЕТОЧИЯ ЧАТА ----------
    function attachChatOptionsMenu(id, type, name, otherUserId = null) {
        const btn = document.getElementById('chat-options-btn');
        if (!btn) return;
        const newBtn = btn.cloneNode(true);
        btn.parentNode.replaceChild(newBtn, btn);
        newBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const item = state.allItems.find(i => i.id === id && i.type === type);
            const currentIsPinned = item ? item.is_pinned : false;
            showChatOptionsMenu(e, id, type, name, otherUserId, currentIsPinned);
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
        { icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="2.18"/><circle cx="8.5" cy="8.5" r="2.5"/><polyline points="21 15 16 10 5 21"/></svg>', label: 'Медиахаб', action: 'media' }
    ];

    if (type === 'collection') {
        items.push({ icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>', label: 'Очистить коллекцию', action: 'clearCollection', danger: false });
    } else {
        items.push({ icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>', label: 'Удалить чат', action: 'delete', danger: true });
        if (type === 'private') {
            items.push({ icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>', label: 'Заблокировать', action: 'block', danger: true });
            items.push({ icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4M12 16h.01"/><circle cx="12" cy="12" r="10"/></svg>', label: 'Пожаловаться', action: 'report' });
        }
        // items.push({ icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>', label: 'Архивировать', action: 'archive' });
        items.push({ icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>', label: 'Скачать историю', action: 'export' });
    }

    const pinLabel = isPinned ? 'Открепить чат' : 'Закрепить чат';
    const pinIcon = isPinned
        ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>'
        : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    items.splice(1, 0, { icon: pinIcon, label: pinLabel, action: 'pin' });

    menu.innerHTML = items.map(item => 
        `<div class="msg-actions-menu__item ${item.danger ? 'msg-actions-menu__item--danger' : ''}" data-action="${item.action}">${item.icon} ${item.label}</div>`
    ).join('');
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
                if (await asyncConfirm(`Очистить всю историю чата "${name}"? Действие необратимо.`)) {
                    await clearChatHistory(id, type);
                }
                break;
            case 'clearCollection':
                if (await asyncConfirm('Очистить всю коллекцию? Все сообщения будут удалены.')) {
                    await clearChatHistory(id, type);
                }
                break;
            case 'block':
                if (type !== 'private') { window.kop.flash('Блокировка только для личных чатов'); return; }
                if (await asyncConfirm(`Заблокировать пользователя ${name}? Вы не сможете писать ему, и он вам.`)) {
                    await blockUser(otherUserId);
                    window.kop.flash('Пользователь заблокирован');
                }
                break;
            case 'report':
                if (type !== 'private') { window.kop.flash('Жалоба только для личных чатов'); return; }
                showReportModal(otherUserId, name);
                break;
            // case 'archive':
            //     await archiveChatLocally(id, type);
            //     window.kop.flash('Чат архивирован (локально)');
            //     break;
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
                    if (a.is_pinned && !b.is_pinned) return -1;
                    if (!a.is_pinned && b.is_pinned) return 1;
                    return 0;
                });
                updateChatListDOM(state.allItems);
            }
            window.kop.flash(newPinned ? 'Чат закреплён' : 'Чат откреплён');
        } catch(e) { window.kop.flash('Ошибка изменения закрепления'); }
    }

    async function clearChatHistory(id, type) {
        if (type === 'collection') {
            try {
                await apiDelete(`/api/chats/${id}/clear`);
                const cacheKey = 'private_' + id;
                state.messagesCache[cacheKey] = { messages: [], lastPolledId: 0, hasMore: true, page: 1 };
                if (state.activeChatId == id && state.currentView === 'messages') {
                    const container = document.querySelector('#messages-container');
                    if (container) container.innerHTML = '<p class="media-empty">Коллекция очищена</p>';
                }
                updateChatPreview(id, 'collection', null, 0);
                window.kop.flash('Коллекция очищена');
            } catch(e) {
                window.kop.flash('Ошибка очистки коллекции');
            }
            return;
        }

        try {
            const clearEndpoint = type === 'private' ? `/api/chats/${id}/clear` : `/api/groups/${id}/clear`;
            await apiDelete(clearEndpoint);
        } catch(e) {
            window.kop.flash('Ошибка очистки сообщений');
            return;
        }

        try {
            const deleteEndpoint = type === 'private' ? `/api/chats/${id}` : `/api/groups/${id}`;
            await apiDelete(deleteEndpoint);
        } catch(e) {
            window.kop.flash('Ошибка удаления чата');
            return;
        }

        const cacheKey = (type === 'private' ? 'private_' : 'group_') + id;
        delete state.messagesCache[cacheKey];
        state.allItems = state.allItems.filter(item => !(item.id == id && item.type === type));

        const node = document.querySelector(`.chatUnit[data-type="${type}"][data-id="${id}"]`);
        if (node) node.remove();

        if ((type === 'private' && state.activeChatId == id) || (type === 'group' && state.activeGroupId == id)) {
            state.activeChatId = null;
            state.activeGroupId = null;
            state.activeChatType = null;
            chatViewPanel.innerHTML = '<div class="chat-placeholder"><p>Выберите чат слева</p></div>';
        }

        window.kop.flash(type === 'group' ? 'Группа удалена' : 'Чат удалён');
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
        const modal = document.getElementById('group-modal');
        const groupNameInput = document.getElementById('group-name');
        const friendsListDiv = document.getElementById('group-friends-list');
        const searchInput = document.getElementById('group-friends-search');
        const selectedCountSpan = document.getElementById('selected-count');
        const createGroupBtn = document.getElementById('group-create-btn');
        const cancelBtn = document.getElementById('group-cancel-btn');
        const closeSpan = document.getElementById('group-modal-close');
        let allFriends = [];
        async function loadFriends() {
            try {
                const data = await kop.get('/api/friends');
                allFriends = data.friends || [];
                renderFriendsList(allFriends);
            } catch (e) {
                friendsListDiv.innerHTML = '<p style="padding:20px;text-align:center;color:#b91c1c;">Ошибка загрузки друзей</p>';
            }
        }
        function renderFriendsList(friends) {
            if (!friends.length) {
                friendsListDiv.innerHTML = '<p style="padding:20px;text-align:center;color:#8b8fa3;">Нет друзей для добавления</p>';
                updateSelectedCount();
                return;
            }
            friendsListDiv.innerHTML = friends.map(friend => {
                const initials = (friend.first_name?.charAt(0) || '') + (friend.last_name?.charAt(0) || '');
                const avatarHtml = friend.avatar
                    ? `<img src="${esc(friend.avatar)}" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">`
                    : `<div class="member-avatar--placeholder" style="width:40px;height:40px;background:#e0e7ff;color:#3b5dd3;display:flex;align-items:center;justify-content:center;font-weight:600;border-radius:50%;">${initials}</div>`;
                return `
                    <label class="friend-item" style="display:flex; align-items:center; gap:12px; padding:10px 16px; cursor:pointer; border-bottom:1px solid #f0f2f5; transition:background 0.2s;"
                        onmouseover="this.style.background='#f8f9ff'" onmouseout="this.style.background=''">
                        <input type="checkbox" value="${friend.id}" class="friend-checkbox" style="width:18px;height:18px;cursor:pointer;">
                        ${avatarHtml}
                        <span style="flex:1; font-weight:500;">${esc(friend.first_name)} ${esc(friend.last_name)}</span>
                    </label>
                `;
            }).join('');
            updateSelectedCount();
            document.querySelectorAll('#group-friends-list .friend-checkbox').forEach(cb => {
                cb.addEventListener('change', updateSelectedCount);
            });
        }
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('#group-friends-list .friend-checkbox:checked');
            selectedCountSpan.textContent = `${checkboxes.length} выбрано`;
        }
        function filterFriends() {
            const query = searchInput.value.trim().toLowerCase();
            if (!query) {
                renderFriendsList(allFriends);
                return;
            }
            const filtered = allFriends.filter(f =>
                f.first_name.toLowerCase().includes(query) || f.last_name.toLowerCase().includes(query)
            );
            renderFriendsList(filtered);
        }
        createBtn.addEventListener('click', async () => {
            groupNameInput.value = '';
            searchInput.value = '';
            await loadFriends();
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('active'), 10);
        });
        function closeGroupModal() {
            modal.classList.remove('active');
            setTimeout(() => modal.style.display = 'none', 300);
        }
        closeSpan.addEventListener('click', closeGroupModal);
        cancelBtn.addEventListener('click', closeGroupModal);
        modal.addEventListener('click', (e) => { if (e.target === modal) closeGroupModal(); });
        let debounceTimer;
        searchInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(filterFriends, 300);
        });
        createGroupBtn.addEventListener('click', async () => {
            const name = groupNameInput.value.trim();
            if (!name) { kop.flash('Введите название группы'); return; }
            const checkboxes = document.querySelectorAll('#group-friends-list .friend-checkbox:checked');
            const memberIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
            if (memberIds.length === 0) { kop.flash('Выберите хотя бы одного друга'); return; }
            createGroupBtn.disabled = true;
            try {
                await kop.post('/api/groups/create', { name, member_ids: memberIds });
                kop.flash('Группа создана');
                closeGroupModal();
                await loadChats();
            } catch (e) {
                kop.flash('Ошибка создания группы');
            } finally {
                createGroupBtn.disabled = false;
            }
        });
    }

    // ---------- РОУТИНГ И ОТКРЫТИЕ ЧАТОВ ----------
    function initRouter() {
        chatListContainer.addEventListener('click', (e) => {
            const unit = e.target.closest('.chatUnit');
            if (!unit) return;
            const type = unit.dataset.type;
            const id = parseInt(unit.dataset.id);
            if (type === 'private') navigateTo('private', id);
            else if (type === 'group') navigateTo('group', id);
            else if (type === 'collection') navigateTo('collection', id);
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
        saveCurrentChatScrollPosition();
        if (state.currentView === 'mediahub' || state.currentView === 'members' || state.currentView === 'post') {
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
            const chat = state.chats.find(c => c.chat_id == chatId);
            if (chat && chat.user1_id == chat.user2_id) {
                if (state.activeChatType !== 'collection' || state.activeChatId !== parseInt(chatId)) {
                    openCollectionChat(parseInt(chatId), false);
                }
            } else {
                if (state.activeChatId !== parseInt(chatId) || state.activeChatType !== 'private') {
                    openPrivateChat(parseInt(chatId), false);
                }
            }
            if (view === 'media') switchToMediaHub();
            else if (view === 'members') switchToMembers();
            else if (view === 'post') {
                const postId = url.searchParams.get('post_id');
                if (postId) switchToPostView(parseInt(postId));
            } else if (state.currentView !== 'messages') switchToMessages();
        } else if (groupId) {
            if (state.activeGroupId !== parseInt(groupId) || state.activeChatType !== 'group') {
                openGroupChat(parseInt(groupId), false);
            }
            if (view === 'media') switchToMediaHub();
            else if (view === 'members') switchToMembers();
            else if (view === 'post') {
                const postId = url.searchParams.get('post_id');
                if (postId) switchToPostView(parseInt(postId));
            } else if (state.currentView !== 'messages') switchToMessages();
        } else {
            showChatList();
        }
    }

    function navigateTo(type, id, push = true) {
        if (type === 'list') { showChatList(); if (push) history.pushState(null, '', '/messenger.php'); }
        else if (type === 'private') { openPrivateChat(id, push); }
        else if (type === 'group') { openGroupChat(id, push); }
        else if (type === 'collection') { openCollectionChat(id, push); }
    }

    function showChatList() {
        saveCurrentChatScrollPosition();
        state.activeChatId = null;
        state.activeChatType = null;
        state.activeGroupId = null;
        state.currentView = 'messages';
        stopPolling();
        chatViewPanel.innerHTML = '<div class="chat-placeholder"><p>Выберите чат слева</p></div>';
    }

    async function openPrivateChat(chatId, push = true) {
        saveCurrentChatScrollPosition();
        const chat = state.chats.find(c => c.chat_id == chatId);
        if (!chat) return;
        const isCollectionChat = (chat.user1_id == state.userId && chat.user2_id == state.userId);
        const chatType = isCollectionChat ? 'collection' : 'private';
        if (state.activeChatId === chatId && state.activeChatType === chatType) return;

        state.activeChatId = chatId;
        state.activeChatType = chatType;
        state.activeGroupId = null;
        state.currentView = 'messages';
        stopPolling();
        state.mediaItems = [];
        state.mediaPage = 1;
        state.mediaHasMore = true;

        if (push) history.pushState(null, '', `?chat_id=${chatId}`);
        state.activeChatReceiverId = isCollectionChat ? state.userId : chat.other_user_id;

        let avatarHtml, headerContent, profileLinkId, chatName;
        if (isCollectionChat) {
            avatarHtml = `<div class="chatHeaderAvatar" style="background:#fef9c3; color:#b45309; display:flex; align-items:center; justify-content:center; font-weight:600; width:40px; height:40px; border-radius:50%;">📌</div>`;
            headerContent = `<span class="chatHeaderUsername" style="flex:1; font-weight:600;">Коллекция</span>`;
            profileLinkId = null;
            chatName = 'Коллекция';
        } else {
            profileLinkId = chat.other_user_id;
            if (profileLinkId == state.userId) profileLinkId = chat.user1_id == state.userId ? chat.user2_id : chat.user1_id;
            const isSelf = profileLinkId == state.userId;
            const profileUrl = isSelf ? '/profile.php' : `/user.php?id=${profileLinkId}`;
            avatarHtml = (chat.avatar && chat.avatar.trim())
                ? `<img class="chatHeaderAvatar" src="${esc(chat.avatar)}" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">`
                : `<div class="chatHeaderAvatar" style="width:40px;height:40px;border-radius:50%;background:#e0e7ff;color:#3b5dd3;display:flex;align-items:center;justify-content:center;font-weight:600;">${getInitials(chat.first_name+' '+chat.last_name)}</div>`;
            headerContent = `<a href="${profileUrl}" class="chatHeaderUsername" style="text-decoration:none; color:inherit;">${esc(chat.first_name)} ${esc(chat.last_name)}</a>`;
            chatName = `${chat.first_name} ${chat.last_name}`;
        }

        chatViewPanel.innerHTML = `
            <div class="chatModule">
                <div class="chatHeader">
                    <button class="chatHeaderQuit">Назад</button>
                    ${avatarHtml}
                    ${headerContent}
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
                <div class="post-view-panel" style="display: none; flex-direction: column; overflow-y: auto; background: #fff;">
                    <div id="post-detail-container" style="padding: 20px;"></div>
                    <div id="post-comments-container" style="padding: 20px; border-top: 1px solid #f0f2f5;"></div>
                </div>
            </div>
        `;

        initPrivateChatEvents(chatId);
        attachChatOptionsMenu(chatId, chatType, chatName, profileLinkId);
        await loadPrivateMessages(chatId);
        if (!isCollectionChat) startPollingForPrivate(chatId);

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('view') === 'media') await switchToMediaHub();
        else if (urlParams.get('view') === 'members') await switchToMembers();
    }

    async function openGroupChat(groupId, push = true) {
        saveCurrentChatScrollPosition();
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
                <div class="post-view-panel" style="display: none; flex-direction: column; overflow-y: auto; background: #fff;">
                    <div id="post-detail-container" style="padding: 20px;"></div>
                    <div id="post-comments-container" style="padding: 20px; border-top: 1px solid #f0f2f5;"></div>
                </div>
            </div>
        `;
        initGroupChatEvents(groupId);
        attachChatOptionsMenu(groupId, 'group', group.name, null);
        initMediaTabs();
        await loadGroupMessages(groupId);
        startPollingForGroup(groupId);
        const groupNameBtn = document.getElementById('group-name-btn');
        if (groupNameBtn) groupNameBtn.addEventListener('click', () => switchToMembers());
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('view') === 'media') await switchToMediaHub();
        else if (urlParams.get('view') === 'members') await switchToMembers();
        else if (urlParams.get('view') === 'post') {
            const postId = urlParams.get('post_id');
            if (postId) await switchToPostView(parseInt(postId));
        }
    }

    async function openCollectionChat(chatId, push = true) {
        saveCurrentChatScrollPosition();
        if (state.activeChatId === chatId && state.activeChatType === 'collection') return;
        state.activeChatId = chatId;
        state.activeChatType = 'collection';
        state.activeGroupId = null;
        state.currentView = 'messages';
        stopPolling();
        const collectionItem = state.allItems.find(i => i.type === 'collection' && i.id == chatId);
        if (!collectionItem) return;
        if (push) history.pushState(null, '', `?chat_id=${chatId}`);
        state.activeChatReceiverId = state.userId;

        chatViewPanel.innerHTML = `
            <div class="chatModule">
                <div class="chatHeader">
                    <button class="chatHeaderQuit">Назад</button>
                    <div class="chatHeaderAvatar" style="background:#fef9c3; color:#b45309; display:flex; align-items:center; justify-content:center; font-weight:600; width:40px; height:40px; border-radius:50%;">📌</div>
                    <span class="chatHeaderUsername" style="flex:1; font-weight:600;">Коллекция</span>
                    <button class="chatHeaderOptions" id="chat-options-btn" title="Действия"><div class="multiDot"></div><div class="multiDot"></div><div class="multiDot"></div></button>
                </div>
                <div class="chat-messages-panel">
                    <div class="chatBody" id="messages-container"></div>
                    <div class="chatTyping">
                        <input type="file" id="attach-file-input" style="display:none" accept="image/*,video/mp4,application/pdf">
                        <button class="chatTypingPin" id="attach-file-btn" title="Прикрепить файл"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></button>
                        <textarea id="typing-input" class="chatTypingInput" placeholder="Заметка..."></textarea>
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
                <div class="post-view-panel" style="display: none; flex-direction: column; overflow-y: auto; background: #fff;">
                    <div id="post-detail-container" style="padding: 20px;"></div>
                    <div id="post-comments-container" style="padding: 20px; border-top: 1px solid #f0f2f5;"></div>
                </div>
            </div>
        `;

        const headerName = chatViewPanel.querySelector('.chatHeaderUsername');
        if (headerName) headerName.textContent = 'Коллекция';

        initPrivateChatEvents(chatId);
        attachChatOptionsMenu(chatId, 'collection', 'Коллекция', null);
        await loadPrivateMessages(chatId);
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('view') === 'media') await switchToMediaHub();
    }

    // ---------- ФОНОВОЕ ОБНОВЛЕНИЕ ПРЕВЬЮ ----------
    function startBackgroundRefresh() {
        if (state.backgroundTimer) clearInterval(state.backgroundTimer);
        state.backgroundTimer = setInterval(pollPreviews, 6000);
    }

    async function pollPreviews() {
        try {
            const data = await apiGet('/api/chats/preview');
            if (!data.previews) return;
            for (const item of data.previews) {
                updateChatPreview(item.chat_id, item.type, item.last_message || 'Нет сообщений', item.unread_count || 0);
                const listItem = state.allItems.find(i => i.id == item.chat_id && i.type === item.type);
                if (listItem && item.last_message_at) {
                    if (listItem.last_message_at !== item.last_message_at) {
                        listItem.last_message = item.last_message;
                        listItem.last_message_at = item.last_message_at;
                        moveChatToTop(listItem);
                    }
                }
            }
        } catch(e) {}
    }

    // ---------- ИНИЦИАЛИЗАЦИЯ ----------
    function requestNotificationPermission() {
        if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
    }

    function initEscape() {
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (state.currentView === 'mediahub' || state.currentView === 'members' || state.currentView === 'post') {
                    switchToMessages();
                } else {
                    const backBtn = document.querySelector('.chatHeaderQuit');
                    if (backBtn) backBtn.click();
                }
            }
        });
    }

    window.initCarousel = window.initCarousel || function(container) {
        const track = container.querySelector('.carousel-track');
        const slides = track ? Array.from(track.children) : [];
        if (slides.length <= 1) return;
        const prevBtn = container.querySelector('.carousel-prev');
        const nextBtn = container.querySelector('.carousel-next');
        const dotsNav = container.querySelector('.carousel-dots');
        let currentIndex = 0;
        function updateCarousel() {
            const slideWidth = slides[0].getBoundingClientRect().width;
            track.style.transform = 'translateX(-' + (currentIndex * slideWidth) + 'px)';
            if (dotsNav) {
                Array.from(dotsNav.children).forEach((dot, i) => dot.classList.toggle('active', i === currentIndex));
            }
        }
        function goToSlide(index) {
            if (index < 0) index = 0;
            if (index >= slides.length) index = slides.length - 1;
            if (index === currentIndex) return;
            currentIndex = index;
            updateCarousel();
        }
        if (prevBtn) prevBtn.addEventListener('click', () => goToSlide(currentIndex - 1));
        if (nextBtn) nextBtn.addEventListener('click', () => goToSlide(currentIndex + 1));
        let touchStartX = 0;
        container.addEventListener('touchstart', e => touchStartX = e.changedTouches[0].screenX);
        container.addEventListener('touchend', e => {
            const diff = e.changedTouches[0].screenX - touchStartX;
            if (Math.abs(diff) > 50) diff > 0 ? goToSlide(currentIndex - 1) : goToSlide(currentIndex + 1);
        });
        if (dotsNav && dotsNav.children.length === 0 && slides.length > 1) {
            for (let i = 0; i < slides.length; i++) {
                const dot = document.createElement('button');
                dot.classList.add('carousel-dot');
                if (i === currentIndex) dot.classList.add('active');
                dot.addEventListener('click', () => goToSlide(i));
                dotsNav.appendChild(dot);
            }
        }
        window.addEventListener('resize', updateCarousel);
        updateCarousel();
    };

    window.openFullMedia = window.openFullMedia || function(src, type) {
        const viewer = document.createElement('div');
        viewer.className = 'media-fullscreen';
        viewer.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0); z-index:10001; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:background 0.3s ease;';
        const closeBtn = document.createElement('div');
        closeBtn.innerHTML = '&times;';
        closeBtn.style.cssText = 'position:absolute; top:20px; right:30px; font-size:40px; color:#fff; cursor:pointer; font-family:sans-serif; z-index:10002; opacity:0; transition:opacity 0.2s ease 0.1s;';
        let media;
        if (type === 'image') {
            media = document.createElement('img');
            media.src = src;
            media.style.cssText = 'max-width:90%; max-height:90%; object-fit:contain; opacity:0; transform:scale(0.9); transition:opacity 0.3s ease, transform 0.3s ease;';
        } else {
            media = document.createElement('video');
            media.src = src;
            media.controls = true;
            media.autoplay = true;
            media.style.cssText = 'max-width:90%; max-height:90%; opacity:0; transform:scale(0.9); transition:opacity 0.3s ease, transform 0.3s ease;';
        }
        viewer.appendChild(media);
        viewer.appendChild(closeBtn);
        document.body.appendChild(viewer);
        requestAnimationFrame(() => {
            viewer.style.background = 'rgba(0,0,0,0.95)';
            media.style.opacity = '1';
            media.style.transform = 'scale(1)';
            closeBtn.style.opacity = '1';
        });
        const remove = () => {
            viewer.style.background = 'rgba(0,0,0,0)';
            media.style.opacity = '0';
            media.style.transform = 'scale(0.9)';
            closeBtn.style.opacity = '0';
            setTimeout(() => viewer.remove(), 300);
        };
        closeBtn.onclick = remove;
        viewer.onclick = e => { if (e.target === viewer) remove(); };
    };

    async function init() {
        state.userId = window.currentUserId;
        if (!state.userId) return;

        // Инициализируем коллекционный чат (если нет – создастся)
        try {
            const resp = await apiGet('/api/collection/chat');
            state.collectionChatId = resp.chat_id;
        } catch(e) {
            console.warn('Не удалось инициализировать коллекцию:', e);
        }

        await loadChats();
        initRouter();
        initGroupCreation();
        startBackgroundRefresh();
        requestNotificationPermission();
        initEscape();

        // Глобальный слушатель клавиш для быстрого ввода сообщений
        document.addEventListener('keydown', function(e) {
            // Если фокус уже на поле ввода, текстовой области или contenteditable – не вмешиваемся
            const tag = document.activeElement?.tagName?.toLowerCase();
            const isEditable = document.activeElement?.isContentEditable;
            if (tag === 'input' || tag === 'textarea' || isEditable) return;

            // Если нажата символьная клавиша (буква, цифра, пробел и т.д.) без модификаторов
            if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
                // Убедимся, что поле ввода существует и видимо (чат открыт)
                const typingInput = document.getElementById('typing-input');
                if (!typingInput || typingInput.offsetParent === null) return;

                e.preventDefault();
                typingInput.focus();
                typingInput.value += e.key;
                // Вызываем событие input, чтобы обновить авто-resize и другие обработчики
                typingInput.dispatchEvent(new Event('input', { bubbles: true }));
                return;
            }

            // Навигация по списку чатов (стрелки и Enter)
            if (state.currentView !== 'messages' || (state.activeChatId === null && state.activeGroupId === null)) {
                // Мы в списке чатов
                const visibleUnits = Array.from(document.querySelectorAll('.chatUnit')).filter(u => u.style.display !== 'none');
                if (visibleUnits.length === 0) return;

                let currentIndex = visibleUnits.findIndex(u => u.classList.contains('keyboard-highlight'));
                if (currentIndex === -1) {
                    // Если выделения ещё нет, начинаем с активного чата, если он есть
                    const activeUnit = visibleUnits.find(u => u.classList.contains('active'));
                    if (activeUnit) {
                        activeUnit.classList.add('keyboard-highlight');
                        currentIndex = visibleUnits.indexOf(activeUnit);
                    } else {
                        // иначе первый видимый
                        currentIndex = 0;
                        visibleUnits[0].classList.add('keyboard-highlight');
                    }
                }

                // Убираем предыдущее выделение
                visibleUnits.forEach(u => u.classList.remove('keyboard-highlight'));

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    currentIndex = (currentIndex + 1) % visibleUnits.length;
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    currentIndex = (currentIndex - 1 + visibleUnits.length) % visibleUnits.length;
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (currentIndex >= 0 && currentIndex < visibleUnits.length) {
                        const unit = visibleUnits[currentIndex];
                        const type = unit.dataset.type;
                        const id = parseInt(unit.dataset.id);
                        if (type === 'private') navigateTo('private', id);
                        else if (type === 'group') navigateTo('group', id);
                        else if (type === 'collection') navigateTo('collection', id);
                        // После открытия чата снимаем выделение
                        visibleUnits.forEach(u => u.classList.remove('keyboard-highlight'));
                    }
                    return;
                } else {
                    return; // любая другая клавиша сбрасывает выделение?
                }

                // Применяем выделение к текущему элементу
                visibleUnits[currentIndex].classList.add('keyboard-highlight');
                visibleUnits[currentIndex].scrollIntoView({ block: 'nearest' });
            }
        });

        // Динамический стиль для подсветки клавиатурного выделения
        const style = document.createElement('style');
        style.textContent = `
            .chatUnit.keyboard-highlight {
                outline-offset: -2px;
                background: rgba(59, 93, 211, 0.08);
            }
        `;
        document.head.appendChild(style);
    }

    init();
})();