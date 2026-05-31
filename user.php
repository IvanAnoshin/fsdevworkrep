<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';
require_auth();

$currentUser = find('users', $_SESSION['user_id']);
$profileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($profileId <= 0 || $profileId === $currentUser['id']) {
    header('Location: profile.php');
    exit;
}

$profileUser = find('users', $profileId);
if (!$profileUser) {
    echo '<p>Пользователь не найден</p>';
    exit;
}

// Статус дружбы
$stmt = db()->prepare(
    "SELECT * FROM friendships WHERE 
        (requester_id = ? AND addressee_id = ?) OR 
        (requester_id = ? AND addressee_id = ?)"
);
$stmt->execute([$currentUser['id'], $profileUser['id'], $profileUser['id'], $currentUser['id']]);
$friendship = $stmt->fetch();
$isFriend = $friendship && $friendship['status'] === 'accepted';

// Приватность
$privacyPosts = $profileUser['privacy_posts'] ?? 'all';
$showPosts = $privacyPosts === 'friends' ? $isFriend : ($privacyPosts !== 'self');

// Безопасная выборка постов
$posts = select(
    "SELECT * FROM posts WHERE user_id = ? ORDER BY created_at DESC LIMIT 10",
    [$profileId]
);

// Онлайн-статус
$onlineStatus = '○ был(а) давно';
if ($profileUser['last_active']) {
    $diff = time() - strtotime($profileUser['last_active']);
    if ($diff < 300) {
        $onlineStatus = '● в сети';
    } elseif ($diff < 3600) {
        $onlineStatus = '○ был(а) недавно';
    } elseif (date('Y-m-d') == date('Y-m-d', strtotime($profileUser['last_active']))) {
        $onlineStatus = '○ был(а) сегодня';
    }
}
$displayOnline = ($profileUser['show_online'] ?? true) ? $onlineStatus : '○ был(а) недавно';

$pageTitle = esc($profileUser['first_name'] . ' ' . $profileUser['last_name']) . ' - Friendscape';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="css/main.css">
</head>
<body>
    <div class="sidebar"><?php require_once "components/header.php"; ?></div>

    <div class="mainArea">
        <div class="profileContainer">
            <div class="profileCard">
                <div class="profileAvatar">
                    <?php if (!empty($profileUser['avatar'])): ?>
                        <img src="<?= esc($profileUser['avatar']) ?>" alt="">
                    <?php else: ?>
                        <span class="accountAvatarPlaceholder">
                            <?= esc(mb_substr($profileUser['first_name'] ?? '', 0, 1) . mb_substr($profileUser['last_name'] ?? '', 0, 1)) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="profileCardInfo">
                    <div class="profileName">
                        <span class="profileNameText"><?= esc($profileUser['first_name'] . ' ' . $profileUser['last_name']) ?></span>
                        <span class="profileStatus"><?= $displayOnline ?></span>
                    </div>
                    <div class="bio">
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            </span>
                            <span class="bioLabel">О себе:</span>
                            <span class="bioValue"><?= esc($profileUser['about'] ?? '') ?></span>
                        </div>
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            </span>
                            <span class="bioLabel">Город:</span>
                            <span class="bioValue"><?= esc($profileUser['city'] ?? '') ?></span>
                        </div>
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </span>
                            <span class="bioLabel">Статус отношений:</span>
                            <span class="bioValue"><?= esc($profileUser['relationship'] ?? '') ?></span>
                        </div>
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            </span>
                            <span class="bioLabel">Интересы:</span>
                            <span class="bioValue"><?= esc($profileUser['interests'] ?? '') ?></span>
                        </div>
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                            </span>
                            <span class="bioLabel">Мне не нравится:</span>
                            <span class="bioValue"><?= esc($profileUser['dislikes'] ?? '') ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Кнопки дружбы и сообщение -->
            <div class="profileActionsUnderAvatar" style="margin-top: 16px; display: flex; gap: 8px; justify-content: center;">
                <?php if (!$friendship): ?>
                    <button class="btn btn--primary" id="add-friend-btn" data-user-id="<?= $profileUser['id'] ?>">
                        <span class="Menu__icon" style="background: #e8e0fc; color: #7c3aed;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                        </span>
                        Добавить в друзья
                    </button>
                <?php elseif ($friendship['status'] === 'pending'): ?>
                    <?php if ($friendship['requester_id'] == $currentUser['id']): ?>
                        <span class="statusBadge">Заявка отправлена</span>
                    <?php else: ?>
                        <button class="btn btn--success" id="accept-friend-btn" data-user-id="<?= $profileUser['id'] ?>">Принять</button>
                        <button class="btn btn--danger" id="decline-friend-btn" data-user-id="<?= $profileUser['id'] ?>">Отклонить</button>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="statusBadge statusBadge--friend">Вы друзья</span>
                <?php endif; ?>

                <button class="btn btn--secondary" onclick="window.location='messenger.php?chat_id=<?= getOrCreateChat($currentUser['id'], $profileUser['id']) ?>'">
                    <span class="Menu__icon" style="background: #d1fae5; color: #059669;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    </span>
                    Написать сообщение
                </button>
            </div>

            <div class="profileNavigation">
                <button class="profileNavigation__btn profileNavigation__btn--active" data-act="posts">Публикации</button>
                <button class="profileNavigation__btn" data-act="photos">Фотоальбомы</button>
                <button class="profileNavigation__btn" data-act="info">Личная информация</button>
            </div>

            <div class="profileContent" id="posts-container">
                <?php if (!$showPosts): ?>
                    <div style="text-align:center;padding:40px 20px;"><p style="color:#8b8fa3;">Публикации ограничены</p></div>
                <?php elseif (empty($posts)): ?>
                    <div style="text-align:center;padding:40px 20px;"><p style="color:#8b8fa3;">Нет публикаций</p></div>
                <?php else: ?>
                    <?php foreach ($posts as $post): ?>
                        <div class="post" data-post-id="<?= $post['id'] ?>" data-author-id="<?= $post['user_id'] ?>">
                            <!-- Точно такая же структура, как в profile.php -->
                            <div class="postHeader">
                                <img class="opPicture" src="<?= esc($profileUser['avatar'] ?? '') ?>" alt="">
                                <div class="opLabel">
                                    <a href=""><?= esc($profileUser['first_name'] . ' ' . $profileUser['last_name']) ?></a>
                                </div>
                                <div class="postOptions">
                                    <button>
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="postBody">
                                <?php if (!empty($post['image'])): ?>
                                    <?php if (preg_match('/\.(mp4|webm|mov)$/i', $post['image'])): ?>
                                        <video class="postBodyImage" controls src="<?= esc($post['image']) ?>"></video>
                                    <?php else: ?>
                                        <img class="postBodyImage" src="<?= esc($post['image']) ?>" alt="Изображение поста">
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($post['content'])): ?>
                                    <div class="postBodyText"><?= esc($post['content']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="postFooter">
                                <div class="postReactions">
                                    <button class="likeButton" data-post-id="<?= $post['id'] ?>">
                                        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                                            </svg>
                                        </span>
                                    </button>
                                    <p class="positiveCounter"><?= $post['likes_count'] ?></p>
                                    <button class="dislikeButton" data-post-id="<?= $post['id'] ?>">
                                        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <line x1="5" y1="12" x2="19" y2="12"/>
                                            </svg>
                                        </span>
                                    </button>
                                    <p class="negativeCounter"><?= $post['dislikes_count'] ?></p>
                                </div>
                                <div class="postActions">
                                    <button class="commentSheet" data-post-id="<?= $post['id'] ?>">
                                        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                                            </svg>
                                        </span>
                                    </button>
                                    <button class="sharePost" data-post-id="<?= $post['id'] ?>">
                                        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98"/><path d="M15.41 6.51l-6.82 3.98"/>
                                            </svg>
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="facebookSection" style="display: none;">
                <div class="facebookLabel"><p>Фото <?= esc($profileUser['first_name']) ?></p></div>
                <div class="facebook">
                    <?php for ($i = 0; $i < 9; $i++): ?>
                        <img class="facebookPicture" src="" alt="">
                    <?php endfor; ?>
                </div>
            </div>
            <div class="personalInfoSection" style="display: none;">
                <div class="editCard">
                    <h3 class="accountTitle">Личная информация</h3>
                    <div class="accountGroup">
                        <?php
                        $fields = [
                            'hometown' => ['label' => 'Родной город', 'icon' => '🌍'],
                            'city' => ['label' => 'Город', 'icon' => '🏙️'],
                            'country' => ['label' => 'Страна', 'icon' => '🌐'],
                            'languages' => ['label' => 'Языки', 'icon' => '🗣️'],
                            'job' => ['label' => 'Работа', 'icon' => '💼'],
                            'education' => ['label' => 'Обучение', 'icon' => '🎓'],
                            'military' => ['label' => 'Служба', 'icon' => '🎖️'],
                            'religion' => ['label' => 'Вера', 'icon' => '🕊️'],
                            'personality' => ['label' => 'Характер', 'icon' => '🧠'],
                            'dreams' => ['label' => 'Мечты', 'icon' => '✨'],
                            'intentions' => ['label' => 'Намерения', 'icon' => '🎯'],
                            'values' => ['label' => 'Ценю в людях', 'icon' => '💎'],
                            'quotes' => ['label' => 'Любимые цитаты', 'icon' => '📜'],
                            'idols' => ['label' => 'Кумиры', 'icon' => '🌟'],
                            'gadgets' => ['label' => 'Мои гаджеты', 'icon' => '📱']
                        ];
                        foreach ($fields as $key => $info):
                            $value = $profileUser[$key] ?? '';
                            $displayValue = $value !== '' ? esc($value) : '<span style="color:#8b8fa3;">Не указано</span>';
                        ?>
                            <div class="bioItem">
                                <span class="bioIcon" style="background: #fef3c7; color: #d97706;"><?= $info['icon'] ?></span>
                                <span class="bioLabel"><?= $info['label'] ?>:</span>
                                <span class="bioValue"><?= $displayValue ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальные окна (точно как в profile.php) -->
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
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
            <span class="modal-close" id="share-modal-close" style="top: 12px; right: 16px;">&times;</span>
            <h3 style="margin:0 0 16px;font-size:1.2em;">Отправить пост</h3>
            <div id="share-chat-list" style="max-height: 300px; overflow-y: auto;"></div>
        </div>
    </div>

    <script src="/kopilot/js/kopilot.js"></script>
    <script>
        // Код ИДЕНТИЧЕН profile.php, только без postingSection и с фиксированным меню для чужих постов
        const currentUserId = <?= (int)$_SESSION['user_id'] ?>;
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

            // Всегда меню для чужого поста
            let itemsHTML = `
                <div class="post-actions-menu__item" data-action="copy-link" data-post-id="${postId}">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    Скопировать ссылку
                </div>
                <div class="post-actions-menu__item" data-action="report" data-post-id="${postId}">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
                    Пожаловаться
                </div>
            `;
            postMenu.innerHTML = itemsHTML;
            postMenu.classList.add('active');

            postMenu.querySelectorAll('.post-actions-menu__item').forEach(item => {
                item.addEventListener('click', (e) => {
                    const action = item.dataset.action;
                    const pid = item.dataset.postId;
                    if (action === 'copy-link') {
                        navigator.clipboard.writeText(`${window.location.origin}/post.php?id=${pid}`)
                            .then(() => kop.flash('Ссылка скопирована'))
                            .catch(() => kop.flash('Не удалось скопировать ссылку'));
                    } else if (action === 'report') {
                        kop.flash('Жалоба отправлена');
                    }
                    hidePostMenu();
                });
            });
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
                    } catch (e) {}
                });
            });
        }

        // ---------- КОММЕНТАРИИ ----------
        function attachCommentHandler() {
            document.querySelectorAll('.commentSheet').forEach(btn => {
                if (btn.dataset.commentHandlerAttached) return;
                btn.dataset.commentHandlerAttached = '1';
                btn.addEventListener('click', async function() {
                    const postId = this.dataset.postId;
                    openCommentsModal(postId);
                });
            });
        }

        async function openCommentsModal(postId) {
            const modal = document.getElementById('comments-modal');
            const postContainer = document.getElementById('modal-post-container');
            const commentsList = document.getElementById('comments-list');
            const originalPost = document.querySelector(`.post[data-post-id="${postId}"]`);
            if (originalPost) {
                const body = originalPost.querySelector('.postBody');
                if (body) {
                    postContainer.innerHTML = body.outerHTML;
                } else {
                    postContainer.innerHTML = '<p>Пост не найден</p>';
                }
            } else {
                postContainer.innerHTML = '<p>Пост не найден</p>';
            }
            try {
                const data = await kop.get(`/api/posts/${postId}/comments`);
                if (data.comments && data.comments.length > 0) {
                    commentsList.innerHTML = data.comments.map(c => {
                        const initials = (c.first_name?.charAt(0) || '') + (c.last_name?.charAt(0) || '');
                        return `
                        <div class="comment-item">
                            <div class="comment-avatar">
                                ${c.avatar ? `<img src="${c.avatar}" alt="">` : `<span class="comment-avatar-placeholder">${initials}</span>`}
                            </div>
                            <div class="comment-content">
                                <div class="comment-author"><a href="user.php?id=${c.user_id}">${c.first_name} ${c.last_name}</a></div>
                                <div class="comment-text">${c.content}</div>
                                <div class="comment-date">${new Date(c.created_at).toLocaleString('ru-RU')}</div>
                            </div>
                        </div>`;
                    }).join('');
                } else {
                    commentsList.innerHTML = '<p class="no-comments">Нет комментариев. Будьте первым.</p>';
                }
            } catch (e) {
                commentsList.innerHTML = '<p class="error">Ошибка загрузки комментариев</p>';
            }

            document.getElementById('comment-send-btn').onclick = async function() {
                const input = document.getElementById('comment-input');
                const content = input.value.trim();
                if (!content) return;
                try {
                    const response = await kop.post(`/api/posts/${postId}/comments`, { content });
                    if (response.success) {
                        const c = response.comment;
                        const initials = (c.first_name?.charAt(0) || '') + (c.last_name?.charAt(0) || '');
                        const newCommentHTML = `
                            <div class="comment-item">
                                <div class="comment-avatar">
                                    ${c.avatar ? `<img src="${c.avatar}" alt="">` : `<span class="comment-avatar-placeholder">${initials}</span>`}
                                </div>
                                <div class="comment-content">
                                    <div class="comment-author"><a href="user.php?id=${c.user_id}">${c.first_name} ${c.last_name}</a></div>
                                    <div class="comment-text">${c.content}</div>
                                    <div class="comment-date">${new Date(c.created_at).toLocaleString('ru-RU')}</div>
                                </div>
                            </div>
                        `;
                        if (commentsList.querySelector('.no-comments')) {
                            commentsList.innerHTML = '';
                        }
                        commentsList.insertAdjacentHTML('afterbegin', newCommentHTML);
                        input.value = '';
                    }
                } catch (e) {}
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
        document.getElementById('comments-modal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // ---------- ПОДЕЛИТЬСЯ ----------
        function attachShareButtons() {
            document.querySelectorAll('.sharePost').forEach(btn => {
                if (btn.dataset.shareAttached) return;
                btn.dataset.shareAttached = '1';
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const postDiv = this.closest('.post');
                    const postId = postDiv.dataset.postId;
                    openShareModal(postId);
                });
            });
        }

        async function openShareModal(postId) {
            const modal = document.getElementById('share-modal');
            const chatList = document.getElementById('share-chat-list');
            let chats = [];
            try { const data = await kop.get('/api/chats'); chats = data.chats || []; } catch(e) {}
            if (chats.length === 0) {
                chatList.innerHTML = '<p style="color:#8b8fa3;text-align:center;padding:20px;">Нет активных чатов</p>';
            } else {
                chatList.innerHTML = chats.map(chat => `
                    <div class="share-chat-item" data-chat-id="${chat.chat_id}" data-other-user="${chat.other_user_id}"
                         style="display:flex;align-items:center;gap:12px;padding:12px;cursor:pointer;border-radius:12px;transition:background 0.2s;"
                         onmouseover="this.style.background='#f5f6fa'" onmouseout="this.style.background=''">
                        <img src="${chat.avatar || ''}" style="width:40px;height:40px;border-radius:50%;object-fit:cover;background:#f0f0f0;">
                        <span style="font-weight:500;">${chat.first_name} ${chat.last_name}</span>
                    </div>
                `).join('');
                chatList.querySelectorAll('.share-chat-item').forEach(item => {
                    item.addEventListener('click', async () => {
                        const otherUserId = item.dataset.otherUser;
                        await sendPostLink(postId, otherUserId);
                        modal.style.display = 'none';
                        modal.classList.remove('active');
                    });
                });
            }
            modal.style.display = 'flex';
            modal.classList.add('active');
        }

        async function sendPostLink(postId, receiverId) {
            try {
                const postUrl = `${window.location.origin}/post.php?id=${postId}`;
                await kop.post('/api/messages/send', { receiver_id: receiverId, content: postUrl });
                kop.flash('Пост отправлен');
            } catch(e) { kop.flash('Ошибка'); }
        }

        document.getElementById('share-modal-close')?.addEventListener('click', () => {
            const modal = document.getElementById('share-modal');
            modal.classList.remove('active');
            modal.style.display = 'none';
        });
        document.getElementById('share-modal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
                this.style.display = 'none';
            }
        });

        // ---------- НАВИГАЦИЯ ----------
        const navBtns = document.querySelectorAll('.profileNavigation__btn');
        navBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                navBtns.forEach(b => b.classList.remove('profileNavigation__btn--active'));
                this.classList.add('profileNavigation__btn--active');
                const act = this.dataset.act;
                document.querySelectorAll('.post').forEach(p => p.style.display = 'none');
                document.querySelector('.facebookSection').style.display = 'none';
                document.querySelector('.personalInfoSection').style.display = 'none';
                if (act === 'posts') document.querySelectorAll('.post').forEach(p => p.style.display = '');
                else if (act === 'photos') document.querySelector('.facebookSection').style.display = '';
                else if (act === 'info') document.querySelector('.personalInfoSection').style.display = '';
                closeModal();
            });
        });

        // ---------- ДРУЖБА ----------
        const addBtn = document.getElementById('add-friend-btn');
        if (addBtn) addBtn.addEventListener('click', async () => {
            try { await kop.post('/api/friends/add', { addressee_id: addBtn.dataset.userId }); location.reload(); }
            catch(e) { kop.flash('Ошибка'); }
        });
        const acceptBtn = document.getElementById('accept-friend-btn');
        if (acceptBtn) acceptBtn.addEventListener('click', async () => {
            try { await kop.post('/api/friends/accept', { requester_id: acceptBtn.dataset.userId }); location.reload(); }
            catch(e) { kop.flash('Ошибка'); }
        });
        const declineBtn = document.getElementById('decline-friend-btn');
        if (declineBtn) declineBtn.addEventListener('click', async () => {
            try { await kop.post('/api/friends/decline', { requester_id: declineBtn.dataset.userId }); location.reload(); }
            catch(e) { kop.flash('Ошибка'); }
        });

        // ---------- ИНИЦИАЛИЗАЦИЯ ----------
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

        attachReactionHandlers();
        attachCommentHandler();
        attachPostMenu();
        attachShareButtons();

        // Скрываем неактивные вкладки
        document.querySelector('.facebookSection').style.display = 'none';
        document.querySelector('.personalInfoSection').style.display = 'none';
    </script>
</body>
</html>