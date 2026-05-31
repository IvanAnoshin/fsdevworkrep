<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';
require_auth();

$user = find('users', $_SESSION['user_id']);
if (!$user) {
    echo '<p>Пользователь не найден</p>';
    exit;
}
db()->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['user_id']]);

$onlineStatus = '○ был(а) давно';
if ($user['last_active']) {
    $diff = time() - strtotime($user['last_active']);
    if ($diff < 300) {
        $onlineStatus = '● в сети';
    } elseif ($diff < 3600) {
        $onlineStatus = '○ был(а) недавно';
    } elseif (date('Y-m-d') == date('Y-m-d', strtotime($user['last_active']))) {
        $onlineStatus = '○ был(а) сегодня';
    }
}

$stmt = db()->prepare("SELECT * FROM posts WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$_SESSION['user_id']]);
$posts = $stmt->fetchAll();

// Получаем друзей (подтверждённых)
$friends = select(
    "SELECT u.id, u.first_name, u.last_name, u.avatar
     FROM friendships f
     JOIN users u ON u.id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END
     WHERE (f.requester_id = ? OR f.addressee_id = ?) AND f.status = 'accepted'
     ORDER BY u.first_name ASC",
    [$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]
);

$pageTitle = esc($user['first_name'] . ' ' . $user['last_name']) . ' - Friendscape';
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
                    <?php if (!empty($user['avatar'])): ?>
                        <img src="<?= esc($user['avatar']) ?>" alt="">
                    <?php else: ?>
                        <span class="accountAvatarPlaceholder">
                            <?= esc(mb_substr($user['first_name'], 0, 1) . mb_substr($user['last_name'], 0, 1)) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="profileCardInfo">
                    <div class="profileName">
                        <span class="profileNameText"><?= esc($user['first_name'] . ' ' . $user['last_name']) ?></span>
                        <span class="profileStatus"><?= $onlineStatus ?></span>
                    </div>
                    <div class="bio">
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            </span>
                            <span class="bioLabel">О себе:</span>
                            <span class="bioValue"><?= esc($user['about'] ?? '') ?></span>
                        </div>
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            </span>
                            <span class="bioLabel">Город:</span>
                            <span class="bioValue"><?= esc($user['city'] ?? '') ?></span>
                        </div>
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </span>
                            <span class="bioLabel">Статус отношений:</span>
                            <span class="bioValue"><?= esc($user['relationship'] ?? '') ?></span>
                        </div>
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            </span>
                            <span class="bioLabel">Интересы:</span>
                            <span class="bioValue"><?= esc($user['interests'] ?? '') ?></span>
                        </div>
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                            </span>
                            <span class="bioLabel">Мне не нравится:</span>
                            <span class="bioValue"><?= esc($user['dislikes'] ?? '') ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="postingSection">
                <button class="profilePinButton" id="attach-btn">
                    <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                        </svg>
                    </span>
                </button>
                <textarea id="profilePostingInput" placeholder="Что нового?"></textarea>
                <input type="file" id="file-input" accept="image/*,video/*" style="display: none;">
                <button class="sendButton" id="send-post-btn">
                    <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="22" y1="2" x2="11" y2="13"/>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                        </svg>
                    </span>
                </button>
            </div>

            <div id="preview-container" style="max-width: 600px; margin: 10px auto; display: none;"></div>

            <div class="profileNavigationWrapper">
                <div class="profileNavigation">
                    <button class="profileNavigation__btn profileNavigation__btn--active" data-act="posts">Публикации</button>
                    <button class="profileNavigation__btn" data-act="friends">Друзья</button>
                    <button class="profileNavigation__btn" data-act="facebook">Фотоальбомы</button>
                    <button class="profileNavigation__btn" data-act="personal">Личная информация</button>
                </div>
            </div>

            <div class="profileContent" id="posts-container">
                <?php if (empty($posts)): ?>
                    <div class="no-posts-placeholder" style="text-align:center;padding:40px 20px;">
                        <span style="display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:12px;background:#f0f0f0;color:#6c757d;margin-bottom:12px;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        </span>
                        <p style="color:#6c757d;font-size:0.95em;margin:0;">Нет публикаций</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($posts as $post): ?>
                        <div class="post" data-post-id="<?= $post['id'] ?>" data-author-id="<?= $post['user_id'] ?>">
                            <div class="postHeader">
                                <img class="opPicture" src="<?= esc($user['avatar'] ?? '') ?>" alt="">
                                <div class="opLabel">
                                    <a href=""><?= esc($user['first_name'] . ' ' . $user['last_name']) ?></a>
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
                <div class="facebookLabel"><p>Мои фото</p></div>
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
                        $hasData = false;
                        foreach ($fields as $key => $info):
                            if (!empty($user[$key])):
                                $hasData = true; ?>
                                <div class="bioItem">
                                    <span class="bioIcon" style="background: #fef3c7; color: #d97706;"><?= $info['icon'] ?></span>
                                    <span class="bioLabel"><?= $info['label'] ?>:</span>
                                    <span class="bioValue"><?= esc($user[$key]) ?></span>
                                </div>
                            <?php endif;
                        endforeach;
                        if (!$hasData): ?>
                            <p style="color:#8b8fa3;text-align:center;padding:20px;">Здесь пока ничего нет. Заполните раздел <a href="settings.php?act=edit">«Личное»</a> в настройках.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Вкладка Друзья -->
            <div id="section-friends" style="display: none;">
                <?php if (empty($friends)): ?>
                    <div style="text-align:center;padding:40px 20px;">
                        <p style="color:#8b8fa3;font-size:1em;">Нет друзей</p>
                    </div>
                <?php else: ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; padding: 20px 0;">
                        <?php foreach ($friends as $friend): ?>
                            <a href="user.php?id=<?= $friend['id'] ?>" style="display: flex; align-items: center; gap: 12px; padding: 12px; background: #fff; border-radius: 12px; text-decoration: none; color: inherit; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                                <?php if (!empty($friend['avatar'])): ?>
                                    <img src="<?= esc($friend['avatar']) ?>" alt="" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover; flex-shrink: 0;">
                                <?php else: ?>
                                    <span style="width: 48px; height: 48px; border-radius: 50%; background: #f0f0f0; display: flex; align-items: center; justify-content: center; font-size: 1.2em; color: #3b5dd3; flex-shrink: 0;">
                                        <?= esc(mb_substr($friend['first_name'] ?? '', 0, 1) . mb_substr($friend['last_name'] ?? '', 0, 1)) ?>
                                    </span>
                                <?php endif; ?>
                                <span style="font-weight: 500; font-size: 0.95em;"><?= esc($friend['first_name'] . ' ' . $friend['last_name']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Модальное окно комментариев -->
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

    <!-- Модальное окно отправки поста (поделиться) -->
    <div class="modal-overlay" id="share-modal" style="display: none;">
        <div class="modal-container" style="padding: 20px; max-width: 400px;">
            <span class="modal-close" id="share-modal-close" style="top: 12px; right: 16px;">&times;</span>
            <h3 style="margin:0 0 16px;font-size:1.2em;">Отправить пост</h3>
            <div id="share-chat-list" style="max-height: 300px; overflow-y: auto;"></div>
        </div>
    </div>

    <script src="/kopilot/js/kopilot.js"></script>
    <script>
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

            const isOwn = (authorId == currentUserId);
            let itemsHTML = '';
            if (isOwn) {
                itemsHTML += `
                    <div class="post-actions-menu__item" data-action="edit" data-post-id="${postId}">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Редактировать
                    </div>
                    <div class="post-actions-menu__item post-actions-menu__item--danger" data-action="delete" data-post-id="${postId}">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        Удалить
                    </div>`;
            } else {
                itemsHTML += `
                    <div class="post-actions-menu__item" data-action="hide" data-post-id="${postId}">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        Скрыть
                    </div>`;
            }
            itemsHTML += `
                <div class="post-actions-menu__item" data-action="copy-link" data-post-id="${postId}">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    Скопировать ссылку
                </div>`;
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
                item.addEventListener('click', function(e) {
                    const action = this.dataset.action;
                    const postId = this.dataset.postId;
                    handleMenuAction(action, postId);
                    hidePostMenu();
                });
            });
        }

        function handleMenuAction(action, postId) {
            switch(action) {
                case 'copy-link':
                    const url = `${window.location.origin}/post.php?id=${postId}`;
                    navigator.clipboard.writeText(url).then(() => kop.flash('Ссылка скопирована'))
                                                   .catch(() => kop.flash('Не удалось скопировать ссылку'));
                    break;
                case 'delete':
                    kop.modal('Удалить пост?', 'Это действие нельзя отменить.', [
                        { text: 'Отмена' },
                        { text: 'Удалить', handler: async () => {
                            try {
                                await kop.post(`/api/posts/${postId}/delete`, {});
                                document.querySelector(`.post[data-post-id="${postId}"]`)?.remove();
                                kop.flash('Пост удалён');
                            } catch(e) { kop.flash('Ошибка при удалении'); }
                        }}
                    ]);
                    break;
                case 'edit':
                    startEditPost(postId);
                    break;
                case 'hide':
                    hidePost(postId);
                    break;
                case 'report':
                    kop.flash('Жалоба отправлена');
                    break;
            }
        }

        // ---------- РЕДАКТИРОВАНИЕ ПОСТА ----------
        function startEditPost(postId) {
            const postDiv = document.querySelector(`.post[data-post-id="${postId}"]`);
            if (!postDiv || postDiv.classList.contains('editing')) return;
            postDiv.classList.add('editing');

            const postBody = postDiv.querySelector('.postBody');
            const postFooter = postDiv.querySelector('.postFooter');
            postDiv.dataset.originalBody = postBody.innerHTML;
            postDiv.dataset.originalFooter = postFooter.innerHTML;

            const textEl = postBody.querySelector('.postBodyText');
            const currentText = textEl ? textEl.textContent : '';
            const mediaEl = postBody.querySelector('.postBodyImage');
            const currentMedia = mediaEl ? (mediaEl.src || mediaEl.querySelector('source')?.src) : '';

            postBody.innerHTML = `
                <textarea class="edit-post-textarea">${currentText}</textarea>
                <div class="edit-post-toolbar">
                    <input type="file" accept="image/*,video/*" class="edit-post-file" style="display:none;">
                    <button type="button" class="btn btn--secondary edit-post-media-btn">📎 Изменить медиа</button>
                    <span class="edit-post-media-status">${currentMedia ? '(прикреплено)' : '(нет медиа)'}</span>
                </div>
            `;
            postFooter.innerHTML = `
                <button class="btn btn--primary save-edit-btn">Сохранить</button>
                <button class="btn btn--secondary cancel-edit-btn">Отмена</button>
            `;

            postBody.querySelector('.edit-post-media-btn').addEventListener('click', () => {
                postBody.querySelector('.edit-post-file').click();
            });

            postFooter.querySelector('.save-edit-btn').addEventListener('click', async () => {
                const newText = postBody.querySelector('.edit-post-textarea').value;
                const fileInput = postBody.querySelector('.edit-post-file');
                const file = fileInput.files[0] || null;
                const formData = new FormData();
                formData.append('content', newText);
                if (file) formData.append('file', file);
                try {
                    const response = await kop.post(`/api/posts/${postId}/edit`, formData);
                    if (response.success) {
                        updatePostDisplay(postDiv, response.post);
                        kop.flash('Пост обновлён');
                    } else {
                        kop.flash(response.error || 'Ошибка сохранения');
                    }
                } catch(e) {
                    kop.flash('Не удалось сохранить');
                }
            });

            postFooter.querySelector('.cancel-edit-btn').addEventListener('click', () => {
                cancelEditPost(postDiv);
            });
        }

        function cancelEditPost(postDiv) {
            if (!postDiv.classList.contains('editing')) return;
            postDiv.querySelector('.postBody').innerHTML = postDiv.dataset.originalBody || '';
            postDiv.querySelector('.postFooter').innerHTML = postDiv.dataset.originalFooter || '';
            postDiv.classList.remove('editing');
            closeModal();   // ← закрываем модалку, если была открыта
            attachReactionHandlers();
            attachCommentHandler();
            attachPostMenu();
        }

        function updatePostDisplay(postDiv, postData) {
            let newBody = '';
            if (postData.image) {
                if (/\.(mp4|webm|mov)$/i.test(postData.image)) {
                    newBody += `<video class="postBodyImage" controls src="${postData.image}"></video>`;
                } else {
                    newBody += `<img class="postBodyImage" src="${postData.image}" alt="">`;
                }
            }
            if (postData.content) {
                newBody += `<div class="postBodyText">${postData.content}</div>`;
            }
            postDiv.querySelector('.postBody').innerHTML = newBody;
            postDiv.querySelector('.postFooter').innerHTML = postDiv.dataset.originalFooter || '';
            postDiv.classList.remove('editing');
            closeModal();   // ← закрываем модалку, чтобы избежать белого блока
            attachReactionHandlers();
            attachCommentHandler();
            attachPostMenu();
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
            Object.assign(undoBtn.style, {
                background: 'none', border: '1px solid rgba(255,255,255,0.5)', color: '#fff',
                padding: '6px 12px', borderRadius: '8px', cursor: 'pointer', fontSize: '0.9em'
            });
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
            setTimeout(() => { if (toast.parentNode) toast.remove(); }, 5000);
        }

        // ---------- ПОДЕЛИТЬСЯ (ОТПРАВКА В ЧАТ) ----------
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
                        modal.classList.remove('active');
                        modal.style.display = 'none';   // скрываем модалку поделиться
                    });
                });
            }
            modal.style.display = 'flex';   // показываем
            modal.classList.add('active');
        }

        async function sendPostLink(postId, receiverId) {
            try {
                const postUrl = `${window.location.origin}/post.php?id=${postId}`;
                await kop.post('/api/messages/send', { receiver_id: receiverId, content: postUrl });
                kop.flash('Пост отправлен');
            } catch(e) { kop.flash('Ошибка при отправке'); }
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

        // ---------- ПРИКРЕПЛЕНИЕ ФАЙЛОВ И ПОСТИНГ ----------
        const postInput = document.getElementById('profilePostingInput');
        function autoResize() {
            postInput.style.height = 'auto';
            postInput.style.height = Math.max(postInput.scrollHeight, 20) + 'px';
        }
        postInput.addEventListener('input', autoResize);
        autoResize();

        let selectedFile = null;
        document.getElementById('attach-btn').addEventListener('click', () => {
            document.getElementById('file-input').click();
        });

        document.getElementById('file-input').addEventListener('change', function() {
            selectedFile = this.files[0] || null;
            const preview = document.getElementById('preview-container');
            preview.innerHTML = '';
            preview.style.display = 'none';
            if (!selectedFile) return;
            preview.style.display = 'block';
            const url = URL.createObjectURL(selectedFile);
            if (selectedFile.type.startsWith('image/')) {
                preview.innerHTML = `<img src="${url}" style="max-width:100%; max-height:200px; border-radius:12px;">`;
            } else if (selectedFile.type.startsWith('video/')) {
                preview.innerHTML = `<video controls src="${url}" style="max-width:100%; max-height:200px; border-radius:12px;"></video>`;
            }
        });

        document.getElementById('send-post-btn').addEventListener('click', async function() {
            const content = postInput.value.trim();
            if (!content && !selectedFile) return;

            const formData = new FormData();
            formData.append('content', content);
            if (selectedFile) {
                formData.append('file', selectedFile);
            }

            try {
                const data = await kop.post('/api/posts/create', formData);
                if (data.success) {
                    const post = data.post;
                    const container = document.getElementById('posts-container');
                    const placeholder = container.querySelector('.no-posts-placeholder');
                    if (placeholder) placeholder.remove();

                    const avatarSrc = "<?= esc($user['avatar'] ?? '') ?>";
                    const fullName = "<?= esc($user['first_name'] . ' ' . $user['last_name']) ?>";
                    let mediaHtml = '';
                    if (post.image) {
                        if (post.image.match(/\.(mp4|webm|mov)$/i)) {
                            mediaHtml = `<video class="postBodyImage" controls src="${post.image}"></video>`;
                        } else {
                            mediaHtml = `<img class="postBodyImage" src="${post.image}" alt="Изображение поста">`;
                        }
                    }
                    const textHtml = post.content ? `<div class="postBodyText">${post.content}</div>` : '';

                    const newPostHTML = `
                        <div class="post" data-post-id="${post.id}" data-author-id="${post.user_id}">
                            <div class="postHeader">
                                <img class="opPicture" src="${avatarSrc}" alt="">
                                <div class="opLabel"><a href="">${fullName}</a></div>
                                <div class="postOptions">
                                    <button>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="postBody">${mediaHtml}${textHtml}</div>
                            <div class="postFooter">
                                <div class="postReactions">
                                    <button class="likeButton" data-post-id="${post.id}">
                                        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                        </span>
                                    </button>
                                    <p class="positiveCounter">0</p>
                                    <button class="dislikeButton" data-post-id="${post.id}">
                                        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                        </span>
                                    </button>
                                    <p class="negativeCounter">0</p>
                                </div>
                                <div class="postActions">
                                    <button class="commentSheet" data-post-id="${post.id}">
                                        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                                        </span>
                                    </button>
                                    <button class="sharePost" data-post-id="${post.id}">
                                        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98"/><path d="M15.41 6.51l-6.82 3.98"/></svg>
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>`;

                    container.insertAdjacentHTML('afterbegin', newPostHTML);
                    postInput.value = '';
                    autoResize();
                    selectedFile = null;
                    document.getElementById('file-input').value = '';
                    document.getElementById('preview-container').style.display = 'none';
                    attachReactionHandlers();
                    attachCommentHandler();
                    attachPostMenu();
                    attachShareButtons();
                }
            } catch (e) {
                // ошибка не показывается
            }
        });

        // ---------- РЕАКЦИИ (ЛАЙК/ДИЗЛАЙК) ----------
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

            modal.style.display = 'flex';   // возвращаем видимость
            modal.classList.add('active');
            document.body.classList.add('no-scroll');
        }

        function closeModal() {
            const modal = document.getElementById('comments-modal');
            if (!modal) return;
            modal.classList.remove('active');
            modal.style.display = 'none';   // полностью убираем из потока
            document.body.classList.remove('no-scroll');
        }

        document.getElementById('modal-close').addEventListener('click', closeModal);
        document.getElementById('comments-modal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // ---------- НАВИГАЦИЯ ПО ВКЛАДКАМ ПРОФИЛЯ ----------
        kop.hide('.facebookSection');
        kop.hide('.personalInfoSection');
        kop.hide('#section-friends');

        const allNavBtns = () => document.querySelectorAll('.profileNavigation__btn');
        allNavBtns().forEach(btn => btn.classList.remove('profileNavigation__btn--active'));
        if (allNavBtns().length > 0) {
            allNavBtns()[0].classList.add('profileNavigation__btn--active');
        }

        kop.on('.profileNavigation__btn', 'click', function(e) {
            e.preventDefault();
            const act = this.dataset.act;
            allNavBtns().forEach(btn => btn.classList.remove('profileNavigation__btn--active'));
            this.classList.add('profileNavigation__btn--active');

            // Скрываем все секции
            document.querySelectorAll('.post').forEach(p => p.style.display = 'none');
            document.querySelector('.facebookSection').style.display = 'none';
            document.querySelector('.personalInfoSection').style.display = 'none';
            document.getElementById('section-friends').style.display = 'none';

            if (act === 'facebook') {
                document.querySelector('.facebookSection').style.display = '';
            } else if (act === 'personal') {
                document.querySelector('.personalInfoSection').style.display = '';
            } else if (act === 'friends') {
                document.getElementById('section-friends').style.display = '';
            } else {
                document.querySelectorAll('.post').forEach(p => p.style.display = '');
            }
            closeModal();
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
    </script>
</body>
</html>