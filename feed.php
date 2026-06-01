<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';
require_auth();

$pageTitle = 'Лента - Friendscape';
$currentUserId = $_SESSION['user_id'];

// Получаем первую страницу постов
$friends = select(
    "SELECT u.id FROM friendships f
     JOIN users u ON u.id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END
     WHERE (f.requester_id = ? OR f.addressee_id = ?) AND f.status = 'accepted'",
    [$currentUserId, $currentUserId, $currentUserId]
);
$friendIds = array_column($friends, 'id');
$friendIds[] = $currentUserId;

$placeholders = implode(',', array_fill(0, count($friendIds), '?'));
$stmt = db()->prepare("
    SELECT p.*, u.first_name, u.last_name, u.avatar
    FROM posts p
    JOIN users u ON u.id = p.user_id
    WHERE 
        (p.user_id = ?) OR
        (p.user_id IN ($placeholders) AND u.privacy_posts IN ('friends', 'public')) OR
        (u.privacy_posts = 'public')
    ORDER BY p.created_at DESC
    LIMIT 10
");
$params = array_merge([$currentUserId], $friendIds);
$stmt->execute($params);
$posts = $stmt->fetchAll();

foreach ($posts as &$post) {
    $stmt2 = db()->prepare("SELECT reaction FROM post_reactions WHERE post_id = ? AND user_id = ?");
    $stmt2->execute([$post['id'], $currentUserId]);
    $react = $stmt2->fetch();
    $post['user_reaction'] = $react ? $react['reaction'] : null;
}

function renderPostHTML($post, $currentUserId) {
    $fullName = htmlspecialchars($post['first_name'] . ' ' . $post['last_name']);
    $profileUrl = ($post['user_id'] == $currentUserId) ? '/profile.php' : '/user.php?id=' . $post['user_id'];
    $isLiked = ($post['user_reaction'] === 'like');
    $isDisliked = ($post['user_reaction'] === 'dislike');

    $mediaHtml = '';
    if (!empty($post['image'])) {
        if (preg_match('/\.(mp4|webm|mov)$/i', $post['image'])) {
            $mediaHtml = '<video class="postBodyImage" controls src="' . htmlspecialchars($post['image']) . '"></video>';
        } else {
            $mediaHtml = '<img class="postBodyImage" src="' . htmlspecialchars($post['image']) . '" alt="Изображение поста">';
        }
    }
    $textHtml = !empty($post['content']) ? '<div class="postBodyText">' . htmlspecialchars($post['content']) . '</div>' : '';

    $avatarHtml = '';
    if (!empty($post['avatar'])) {
        $avatarHtml = '<img class="opPicture" src="' . htmlspecialchars($post['avatar']) . '" alt="" onerror="this.onerror=null;this.src=\'\';this.style.display=\'none\';this.nextSibling.style.display=\'flex\';">';
        $avatarHtml .= '<div class="opPicture-placeholder" style="display:none;">' . htmlspecialchars(mb_substr($post['first_name'], 0, 1) . mb_substr($post['last_name'], 0, 1)) . '</div>';
    } else {
        $avatarHtml = '<div class="opPicture-placeholder">' . htmlspecialchars(mb_substr($post['first_name'], 0, 1) . mb_substr($post['last_name'], 0, 1)) . '</div>';
    }

    $likeBtnClass = $isLiked ? 'likeButton active' : 'likeButton';
    $dislikeBtnClass = $isDisliked ? 'dislikeButton active' : 'dislikeButton';

    return <<<HTML
    <div class="post" data-post-id="{$post['id']}" data-author-id="{$post['user_id']}">
        <div class="postHeader">
            {$avatarHtml}
            <div class="opLabel">
                <a href="{$profileUrl}">{$fullName}</a>
            </div>
            <div class="postOptions">
                <button>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                </button>
            </div>
        </div>
        <div class="postBody">
            {$mediaHtml}
            {$textHtml}
        </div>
        <div class="postFooter">
            <div class="postReactions">
                <button class="{$likeBtnClass}" data-post-id="{$post['id']}">
                    <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                    </span>
                </button>
                <p class="positiveCounter">{$post['likes_count']}</p>
                <button class="{$dislikeBtnClass}" data-post-id="{$post['id']}">
                    <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                    </span>
                </button>
                <p class="negativeCounter">{$post['dislikes_count']}</p>
            </div>
            <div class="postActions">
                <button class="commentSheet" data-post-id="{$post['id']}">
                    <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                        </svg>
                    </span>
                </button>
                <button class="sharePost" data-post-id="{$post['id']}">
                    <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98"/><path d="M15.41 6.51l-6.82 3.98"/>
                        </svg>
                    </span>
                </button>
            </div>
        </div>
    </div>
HTML;
}

$initialPostsHTML = '';
foreach ($posts as $post) {
    $initialPostsHTML .= renderPostHTML($post, $currentUserId);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        /* Механика TikTok: вертикальная привязка */
        .mainArea {
            margin-left: 20%;
            height: 100vh;
            overflow-y: auto;
            scroll-snap-type: y mandatory;
            background: #f0f0f0;
            scrollbar-width: none;
        }
        .feed-container {
            display: flex;
            flex-direction: column;
        }
        .post {
            scroll-snap-align: start;
            min-height: 100vh;
            width: 100%;
            margin: 0;
            border-radius: 0;
            box-shadow: none;
            background: #fff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            border-bottom: 2px solid #eef1f8;
        }
        .postBodyImage {
            max-height: 70vh;
        }
        @media (max-width: 768px) {
            .mainArea {
                margin-left: 70px;
            }
        }
        .loader {
            text-align: center;
            padding: 20px;
            color: #8b8fa3;
        }
    </style>
</head>
<body>
    <div class="sidebar"><?php require_once "components/header.php"; ?></div>
    <div class="mainArea" id="mainArea">
        <div class="feed-container" id="feed-container">
            <?= $initialPostsHTML ?>
        </div>
        <div id="loader" class="loader" style="display: none;">Загрузка...</div>
    </div>

    <!-- Модальные окна (комментарии, поделиться) -->
    <div class="modal-overlay" id="comments-modal" style="display: none;">
        <div class="modal-container">
            <span class="modal-close" id="modal-close">&times;</span>
            <div id="modal-post-container"></div>
            <div class="comments-block">
                <div class="comments-list" id="comments-list"></div>
                <div class="comment-form">
                    <textarea id="comment-input" placeholder="Написать комментарий..."></textarea>
                    <button id="comment-send-btn">
                        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="22" y1="2" x2="11" y2="13"/>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                            </svg>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="share-modal" style="display: none;">
        <div class="modal-container" style="padding: 20px; max-width: 400px;">
            <span class="modal-close" id="share-modal-close">&times;</span>
            <h3 style="margin:0 0 16px;">Отправить пост</h3>
            <div id="share-chat-list" style="max-height: 300px; overflow-y: auto;"></div>
        </div>
    </div>

    <script src="/kopilot/js/kopilot.js"></script>
    <script>
        const currentUserId = <?= json_encode($currentUserId) ?>;
        let currentPage = 2;
        let isLoading = false;
        let hasMore = true;
        const feedContainer = document.getElementById('feed-container');
        const loader = document.getElementById('loader');
        const mainArea = document.getElementById('mainArea');

        // ---------- МЕНЮ ПОСТА ----------
        const postMenu = document.createElement('div');
        postMenu.className = 'post-actions-menu';
        document.body.appendChild(postMenu);

        function hidePostMenu() {
            postMenu.classList.remove('active');
        }

        document.addEventListener('click', (e) => {
            if (!postMenu.contains(e.target) && !e.target.closest('.postOptions button')) {
                hidePostMenu();
            }
        });

        function showPostMenu(button, postId, authorId) {
            const rect = button.getBoundingClientRect();
            postMenu.style.top = (rect.bottom + window.scrollY + 4) + 'px';
            postMenu.style.left = (rect.right + window.scrollX - 200) + 'px';
            const isOwn = (authorId == currentUserId);
            let itemsHTML = '';

            // Для всех постов: "Скрыть" + "Копировать ссылку"
            itemsHTML += `
                <div class="post-actions-menu__item" data-action="hide" data-post-id="${postId}">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    Скрыть
                </div>
                <div class="post-actions-menu__item" data-action="copy-link" data-post-id="${postId}">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    Скопировать ссылку
                </div>`;

            // Для чужих постов добавляем "Пожаловаться"
            if (!isOwn) {
                itemsHTML += `
                    <div class="post-actions-menu__item" data-action="report" data-post-id="${postId}">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
                        Пожаловаться
                    </div>`;
            }

            postMenu.innerHTML = itemsHTML;
            postMenu.classList.add('active');
            postMenu.querySelectorAll('.post-actions-menu__item').forEach(item => {
                item.addEventListener('click', (e) => {
                    const action = item.dataset.action;
                    const pid = item.dataset.postId;
                    handleMenuAction(action, pid);
                    hidePostMenu();
                });
            });
        }

        async function handleMenuAction(action, postId) {
            switch(action) {
                case 'copy-link':
                    const url = `${window.location.origin}/post.php?id=${postId}`;
                    try { await navigator.clipboard.writeText(url); kop.flash('Ссылка скопирована'); }
                    catch(e) { kop.flash('Не удалось скопировать'); }
                    break;
                case 'hide':
                    hidePost(postId);
                    break;
                case 'report':
                    kop.flash('Жалоба отправлена');
                    break;
            }
        }

        // ---------- СКРЫТИЕ ПОСТА ----------
        async function hidePost(postId) {
            const postDiv = document.querySelector(`.post[data-post-id="${postId}"]`);
            if (!postDiv) return;
            try {
                await kop.post(`/api/posts/${postId}/hide`, {});
                postDiv.style.transition = 'opacity 0.3s, transform 0.3s';
                postDiv.style.opacity = '0';
                postDiv.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    postDiv.remove();
                    showUndoHideToast(postId);
                }, 300);
            } catch(e) { kop.flash('Не удалось скрыть пост'); }
        }

        function showUndoHideToast(postId) {
            const toast = document.createElement('div');
            toast.className = 'undo-hide-toast';
            toast.innerHTML = '<span>Пост скрыт</span><button class="undo-hide-btn">Отменить</button>';
            Object.assign(toast.style, {
                position: 'fixed', bottom: '20px', left: '50%', transform: 'translateX(-50%)',
                background: '#1e1e2f', color: '#fff', padding: '12px 20px', borderRadius: '12px',
                display: 'flex', alignItems: 'center', gap: '16px', zIndex: '1000',
                boxShadow: '0 8px 24px rgba(0,0,0,0.2)', fontSize: '0.95em',
                transition: 'opacity 0.3s'
            });
            const undoBtn = toast.querySelector('.undo-hide-btn');
            undoBtn.style.cssText = 'background:none;border:1px solid rgba(255,255,255,0.5);color:#fff;padding:6px 12px;border-radius:8px;cursor:pointer;font-size:0.9em';
            undoBtn.addEventListener('click', async () => {
                try {
                    await fetch(`/api/posts/${postId}/hide`, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-Token': document.querySelector('input[name="_csrf"]').value }
                    });
                    window.location.reload();
                } catch(e) { kop.flash('Ошибка отмены'); }
                toast.remove();
            });
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 5000);
        }

        // ---------- РЕАКЦИИ ----------
        function attachReactionHandlers() {
            document.querySelectorAll('.likeButton, .dislikeButton').forEach(btn => {
                if (btn.dataset.handlerAttached) return;
                btn.dataset.handlerAttached = '1';
                btn.addEventListener('click', async function() {
                    const postId = this.dataset.postId;
                    const reactionType = this.classList.contains('likeButton') ? 'like' : 'dislike';
                    const endpoint = reactionType === 'like' ? '/api/posts/like' : '/api/posts/dislike';
                    try {
                        const response = await kop.post(endpoint, { post_id: postId });
                        if (response.success) {
                            const postDiv = this.closest('.post');
                            postDiv.querySelector('.positiveCounter').textContent = response.likes_count;
                            postDiv.querySelector('.negativeCounter').textContent = response.dislikes_count;
                            const likeBtn = postDiv.querySelector('.likeButton');
                            const dislikeBtn = postDiv.querySelector('.dislikeButton');
                            likeBtn.classList.remove('active');
                            dislikeBtn.classList.remove('active');
                            if (response.user_liked) likeBtn.classList.add('active');
                            if (response.user_disliked) dislikeBtn.classList.add('active');
                        }
                    } catch(e) {}
                });
            });
        }

        // ---------- КОММЕНТАРИИ ----------
        function attachCommentHandler() {
            document.querySelectorAll('.commentSheet').forEach(btn => {
                if (btn.dataset.commentHandlerAttached) return;
                btn.dataset.commentHandlerAttached = '1';
                btn.addEventListener('click', () => openCommentsModal(btn.dataset.postId));
            });
        }

        async function openCommentsModal(postId) {
            const modal = document.getElementById('comments-modal');
            const postContainer = document.getElementById('modal-post-container');
            const commentsList = document.getElementById('comments-list');
            const originalPost = document.querySelector(`.post[data-post-id="${postId}"]`);
            if (originalPost) {
                const body = originalPost.querySelector('.postBody');
                postContainer.innerHTML = body ? body.outerHTML : '<p>Пост не найден</p>';
            } else {
                postContainer.innerHTML = '<p>Пост не найден</p>';
            }
            try {
                const data = await kop.get(`/api/posts/${postId}/comments`);
                if (data.comments && data.comments.length) {
                    commentsList.innerHTML = data.comments.map(c => {
                        const initials = (c.first_name?.charAt(0)||'')+(c.last_name?.charAt(0)||'');
                        return `
                        <div class="comment-item">
                            <div class="comment-avatar">
                                ${c.avatar ? `<img src="${c.avatar}" alt="">` : `<span class="comment-avatar-placeholder">${initials}</span>`}
                            </div>
                            <div class="comment-content">
                                <div class="comment-author"><a href="user.php?id=${c.user_id}">${c.first_name} ${c.last_name}</a></div>
                                <div class="comment-text">${c.content}</div>
                                <div class="comment-date">${new Date(c.created_at).toLocaleString()}</div>
                            </div>
                        </div>`;
                    }).join('');
                } else {
                    commentsList.innerHTML = '<p class="no-comments">Нет комментариев</p>';
                }
            } catch(e) { commentsList.innerHTML = '<p class="error">Ошибка загрузки</p>'; }
            document.getElementById('comment-send-btn').onclick = async () => {
                const input = document.getElementById('comment-input');
                const content = input.value.trim();
                if (!content) return;
                try {
                    const response = await kop.post(`/api/posts/${postId}/comments`, { content });
                    if (response.success) {
                        const c = response.comment;
                        const initials = (c.first_name?.charAt(0)||'')+(c.last_name?.charAt(0)||'');
                        const newComment = `
                            <div class="comment-item">
                                <div class="comment-avatar">
                                    ${c.avatar ? `<img src="${c.avatar}" alt="">` : `<span class="comment-avatar-placeholder">${initials}</span>`}
                                </div>
                                <div class="comment-content">
                                    <div class="comment-author"><a href="user.php?id=${c.user_id}">${c.first_name} ${c.last_name}</a></div>
                                    <div class="comment-text">${c.content}</div>
                                    <div class="comment-date">${new Date(c.created_at).toLocaleString()}</div>
                                </div>
                            </div>
                        `;
                        if (commentsList.querySelector('.no-comments')) commentsList.innerHTML = '';
                        commentsList.insertAdjacentHTML('afterbegin', newComment);
                        input.value = '';
                    }
                } catch(e) { kop.flash('Ошибка'); }
            };
            modal.style.display = 'flex';
            modal.classList.add('active');
            document.body.classList.add('no-scroll');
        }

        function closeModal() {
            const modal = document.getElementById('comments-modal');
            if (!modal) return;
            modal.classList.remove('active');
            modal.style.display = 'none';
            document.body.classList.remove('no-scroll');
        }
        document.getElementById('modal-close').addEventListener('click', closeModal);
        document.getElementById('comments-modal').addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeModal();
        });

        // ---------- ПОДЕЛИТЬСЯ ----------
        function attachShareButtons() {
            document.querySelectorAll('.sharePost').forEach(btn => {
                if (btn.dataset.shareAttached) return;
                btn.dataset.shareAttached = '1';
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    openShareModal(btn.dataset.postId);
                });
            });
        }

        async function openShareModal(postId) {
            const modal = document.getElementById('share-modal');
            const chatList = document.getElementById('share-chat-list');
            let chats = [];
            try { const data = await kop.get('/api/chats'); chats = data.chats || []; } catch(e) {}
            if (!chats.length) {
                chatList.innerHTML = '<p style="color:#8b8fa3;text-align:center;padding:20px;">Нет активных чатов</p>';
            } else {
                chatList.innerHTML = chats.map(chat => `
                    <div class="share-chat-item" data-chat-id="${chat.chat_id}" data-other-user="${chat.other_user_id}"
                         style="display:flex;align-items:center;gap:12px;padding:12px;cursor:pointer;border-radius:12px;">
                        <img src="${chat.avatar || ''}" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">
                        <span>${chat.first_name} ${chat.last_name}</span>
                    </div>
                `).join('');
                chatList.querySelectorAll('.share-chat-item').forEach(item => {
                    item.addEventListener('click', async () => {
                        const receiverId = item.dataset.otherUser;
                        const postUrl = `${window.location.origin}/post.php?id=${postId}`;
                        await kop.post('/api/messages/send', { receiver_id: receiverId, content: postUrl });
                        kop.flash('Пост отправлен');
                        modal.classList.remove('active');
                        modal.style.display = 'none';
                    });
                });
            }
            modal.style.display = 'flex';
            modal.classList.add('active');
        }
        document.getElementById('share-modal-close')?.addEventListener('click', () => {
            const modal = document.getElementById('share-modal');
            modal.classList.remove('active');
            modal.style.display = 'none';
        });
        document.getElementById('share-modal')?.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) {
                modal.classList.remove('active');
                modal.style.display = 'none';
            }
        });

        // ---------- МЕНЮ ПОСТА (кнопка с тремя точками) ----------
        function attachPostMenu() {
            document.querySelectorAll('.postOptions button').forEach(btn => {
                if (btn.dataset.menuAttached) return;
                btn.dataset.menuAttached = '1';
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const postDiv = this.closest('.post');
                    const postId = postDiv.dataset.postId;
                    const authorId = postDiv.dataset.authorId;
                    showPostMenu(this, postId, authorId);
                });
            });
        }

        // ---------- БЕСКОНЕЧНАЯ ПОДГРУЗКА ----------
        async function loadMorePosts() {
            if (isLoading || !hasMore) return;
            isLoading = true;
            loader.style.display = 'block';
            try {
                const response = await fetch(`/api/feed/posts-html?page=${currentPage}&limit=10`, {
                    headers: { 'X-CSRF-Token': window.csrfToken, 'Accept': 'application/json' }
                });
                const data = await response.json();
                if (data.html && data.html.trim() !== '') {
                    feedContainer.insertAdjacentHTML('beforeend', data.html);
                    hasMore = data.has_more;
                    currentPage++;
                    attachReactionHandlers();
                    attachCommentHandler();
                    attachShareButtons();
                    attachPostMenu();
                } else {
                    hasMore = false;
                }
            } catch(e) {
                console.error('Ошибка подгрузки', e);
            } finally {
                isLoading = false;
                loader.style.display = 'none';
            }
        }

        // Бесконечный скролл
        function initInfiniteScroll() {
            let scrollTimeout = null;
            mainArea.addEventListener('scroll', () => {
                if (scrollTimeout) clearTimeout(scrollTimeout);
                scrollTimeout = setTimeout(() => {
                    if (mainArea.scrollTop + mainArea.clientHeight >= mainArea.scrollHeight - 300) {
                        loadMorePosts();
                    }
                }, 200);
            });
        }

        // Инициализация
        attachReactionHandlers();
        attachCommentHandler();
        attachShareButtons();
        attachPostMenu();
        initInfiniteScroll();

        if (!window.csrfToken) {
            const csrfInput = document.querySelector('input[name="_csrf"]');
            if (csrfInput) window.csrfToken = csrfInput.value;
        }
    </script>
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
</body>
</html>