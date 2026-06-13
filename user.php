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

// Функция склонения для счётчика друзей
function declension($number, $titles) {
    $cases = [2, 0, 1, 1, 1, 2];
    return $titles[($number % 100 > 4 && $number % 100 < 20) ? 2 : $cases[($number % 10 < 5) ? $number % 10 : 5]];
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
$friendshipRequesterId = $isFriend ? $friendship['requester_id'] : null;

// Приватность постов
$privacyPosts = $profileUser['privacy_posts'] ?? 'all';
$showPosts = $privacyPosts === 'friends' ? $isFriend : ($privacyPosts !== 'self');

// Выборка постов
$posts = select(
    "SELECT * FROM posts WHERE user_id = ? ORDER BY created_at DESC LIMIT 10",
    [$profileId]
);

// Получаем список друзей просматриваемого пользователя
$friends = select(
    "SELECT u.id, u.first_name, u.last_name, u.avatar
     FROM friendships f
     JOIN users u ON u.id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END
     WHERE (f.requester_id = ? OR f.addressee_id = ?) AND f.status = 'accepted'
     ORDER BY u.first_name ASC",
    [$profileId, $profileId, $profileId]
);

// Онлайн-статус (начальное значение, будет перезаписан JavaScript)
$onlineText = 'Загрузка...';
$onlineClass = 'profileStatus--offline';

// Проверка, поручился ли текущий пользователь за просматриваемого
$alreadyEndorsed = false;
if ($isFriend) {
    $tableExists = scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'dfsn_endorsements'");
    if ($tableExists) {
        $endorsement = scalar(
            "SELECT COUNT(*) FROM dfsn_endorsements WHERE from_user_id = ? AND to_user_id = ?",
            [$currentUser['id'], $profileUser['id']]
        );
        $alreadyEndorsed = $endorsement > 0;
    }
}

$pageTitle = esc($profileUser['first_name'] . ' ' . $profileUser['last_name']) . ' - Friendscape';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        #report-reason:focus {
            border-color: #3b5dd3;
            background: #fff;
        }
        .profileStatus { font-size:0.85rem; font-weight:500; padding:4px 12px; border-radius:20px; display:inline-flex; align-items:center; gap:6px; }
        .profileStatus--online { background:#ecfdf5; color:#10b981; }
        .profileStatus--recent { background:#fff7ed; color:#ea580c; }
        .profileStatus--offline { background:#f3f4f6; color:#6b7280; }
    </style>
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
                        <span id="profile-status-badge" class="profileStatus <?= $onlineClass ?>"><?= $onlineText ?></span>
                    </div>
                    <div class="bio">
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                            </span>
                            <span class="bioLabel">О себе:</span>
                            <span class="bioValue"><?= esc($profileUser['about'] ?? '') ?></span>
                        </div>
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 2c-3.3 0-6 2.7-6 6 0 5 6 12 6 12s6-7 6-12c0-3.3-2.7-6-6-6z"/>
                                    <circle cx="12" cy="8" r="2"/>
                                </svg>
                            </span>
                            <span class="bioLabel">Город:</span>
                            <span class="bioValue"><?= esc($profileUser['city'] ?? '') ?></span>
                        </div>
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                                    <path d="M2 17l10 5 10-5"/>
                                    <path d="M2 12l10 5 10-5"/>
                                </svg>
                            </span>
                            <span class="bioLabel">Статус отношений:</span>
                            <span class="bioValue"><?= esc($profileUser['relationship'] ?? '') ?></span>
                        </div>
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M8 14s1.5 2 4 2 4-2 4-2"/>
                                    <line x1="9" y1="9" x2="9.01" y2="9"/>
                                    <line x1="15" y1="9" x2="15.01" y2="9"/>
                                </svg>
                            </span>
                            <span class="bioLabel">Интересы:</span>
                            <span class="bioValue"><?= esc($profileUser['interests'] ?? '') ?></span>
                        </div>
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="8" y1="12" x2="16" y2="12"/>
                                </svg>
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
                <?php elseif ($friendship['status'] === 'accepted'): ?>
                    <button class="btn btn--friend" id="friend-actions-btn" data-friend-id="<?= $profileUser['id'] ?>" data-requester-id="<?= $friendshipRequesterId ?>" data-already-endorsed="<?= $alreadyEndorsed ? '1' : '0' ?>">
                        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                            </svg>
                        </span>
                        Вы друзья
                    </button>
                <?php endif; ?>

                <button class="btn btn--secondary" onclick="window.location='messenger.php?chat_id=<?= getOrCreateChat($currentUser['id'], $profileUser['id']) ?>'">
                    <span class="Menu__icon" style="background: white; color: #3b5dd3;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    </span>
                    Написать сообщение
                </button>
            </div>

            <div class="profileNavigation">
                <button class="profileNavigation__btn profileNavigation__btn--active" data-act="posts">Публикации</button>
                <button class="profileNavigation__btn" data-act="friends">Друзья</button>
                <button class="profileNavigation__btn" data-act="photos">Фотоальбомы</button>
                <button class="profileNavigation__btn" data-act="info">Личная информация</button>
            </div>

            <!-- Посты -->
            <div class="profileContent" id="posts-container">
                <?php if (!$showPosts): ?>
                    <div style="text-align:center;padding:40px 20px;"><p style="color:#8b8fa3;">Публикации ограничены</p></div>
                <?php elseif (empty($posts)): ?>
                    <div style="text-align:center;padding:40px 20px;"><p style="color:#8b8fa3;">Нет публикаций</p></div>
                <?php else: ?>
                    <?php foreach ($posts as $post): ?>
                        <div class="post" data-post-id="<?= $post['id'] ?>" data-author-id="<?= $post['user_id'] ?>">
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

            <!-- Секция Друзья -->
            <div id="section-friends" style="display: none;">
                <div class="friends-header">
                    <h3 style="margin:0; font-size:1.2rem;">Друзья <?= esc($profileUser['first_name']) ?></h3>
                    <span class="friends-count"><?= count($friends) ?> <?= declension(count($friends), ['друг', 'друга', 'друзей']) ?></span>
                </div>
                <?php if (empty($friends)): ?>
                    <div style="text-align:center;padding:40px 20px;"><p style="color:#8b8fa3;">Нет друзей</p></div>
                <?php else: ?>
                    <div class="friends-grid">
                        <?php foreach ($friends as $friend): ?>
                            <div class="friend-card" data-friend-id="<?= $friend['id'] ?>">
                                <?php if (!empty($friend['avatar'])): ?>
                                    <img class="friend-avatar" src="<?= esc($friend['avatar']) ?>" alt="">
                                <?php else: ?>
                                    <div class="friend-avatar-placeholder"><?= esc(mb_substr($friend['first_name']??'',0,1).mb_substr($friend['last_name']??'',0,1)) ?></div>
                                <?php endif; ?>
                                <div class="friend-info">
                                    <a href="user.php?id=<?= $friend['id'] ?>" class="friend-name"><?= esc($friend['first_name'].' '.$friend['last_name']) ?></a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Фотоальбом -->
            <div class="facebookSection" style="display: none;">
                <div class="facebookLabel">
                    <p>Фото <?= esc($profileUser['first_name']) ?></p>
                </div>
                <div id="photos-grid" class="facebook"></div>
            </div>

            <!-- Личная информация -->
            <div class="personalInfoSection" style="display: none;">
                <div class="editCard">
                    <h3 class="accountTitle">Личная информация</h3>
                    <div class="accountGroup">
                        <?php
                        $fields = [
                            'hometown'   => 'Родной город',
                            'city'       => 'Город',
                            'country'    => 'Страна',
                            'languages'  => 'Языки',
                            'job'        => 'Работа',
                            'education'  => 'Обучение',
                            'military'   => 'Служба',
                            'religion'   => 'Вера',
                            'personality' => 'Характер',
                            'dreams'     => 'Мечты',
                            'intentions' => 'Намерения',
                            'values'     => 'Ценю в людях',
                            'quotes'     => 'Любимые цитаты',
                            'idols'      => 'Кумиры',
                            'gadgets'    => 'Мои гаджеты'
                        ];
                        $icons = [
                            'hometown'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
                            'city'       => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><line x1="8" y1="6" x2="16" y2="6"/><line x1="8" y1="10" x2="16" y2="10"/><line x1="8" y1="14" x2="16" y2="14"/><line x1="8" y1="18" x2="12" y2="18"/></svg>',
                            'country'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
                            'languages'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>',
                            'job'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
                            'education'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
                            'military'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
                            'religion'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
                            'personality'=> '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>',
                            'dreams'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
                            'intentions' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
                            'values'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 7l10-5 10 5-10 15z"/><path d="M2 7l10 5 10-5"/><line x1="12" y1="12" x2="12" y2="22"/></svg>',
                            'quotes'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 11h-4v4h4v-4z"/><path d="M18 11h-4v4h4v-4z"/><path d="M21 6v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
                            'idols'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
                            'gadgets'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>'
                        ];
                        $hasData = false;
                        foreach ($fields as $key => $label):
                            $value = $profileUser[$key] ?? '';
                            if ($value !== '') $hasData = true;
                            $icon = $icons[$key] ?? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>';
                            $displayValue = $value !== '' ? esc($value) : '<span style="color:#8b8fa3;">Не указано</span>';
                        ?>
                            <div class="bioItem">
                                <span class="bioIcon" style="background: #f0f2f5; color: #4b5563;"><?= $icon ?></span>
                                <span class="bioLabel"><?= $label ?>:</span>
                                <span class="bioValue"><?= $displayValue ?></span>
                            </div>
                        <?php endforeach;
                        if (!$hasData): ?>
                            <p style="color:#8b8fa3;text-align:center;padding:20px;">Пользователь не заполнил личную информацию.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальные окна (комментарии, поделиться) -->
    <div class="modal-overlay" id="comments-modal" style="display: none;">
        <div class="modal-container">
            <span class="modal-close" id="modal-close">&times;</span>
            <div id="modal-post-container"></div>
            <div class="comments-block">
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
                <div class="comments-list" id="comments-list"></div>
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

    <!-- kopilot.js уже загружен в header.php -->
    <script>
        // CSRF-токен из скрытого поля
        window.csrfToken = document.querySelector('input[name="_csrf"]').value;

        // ID просматриваемого пользователя
        const profileUserId = <?= (int)$profileId ?>;

        // Глобальная функция экранирования
        function esc(str) {
            if (str == null) return '';
            return String(str).replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        const currentUserId = <?= (int)$_SESSION['user_id'] ?>;
        const postMenu = document.createElement('div');
        postMenu.className = 'post-actions-menu';
        document.body.appendChild(postMenu);

        function hidePostMenu() { postMenu.classList.remove('active'); }
        document.addEventListener('click', (e) => {
            if (!postMenu.contains(e.target) && !e.target.closest('.postOptions button')) hidePostMenu();
        });

        function showPostMenu(button, postId, authorId) {
            const rect = button.getBoundingClientRect();
            postMenu.style.top = (rect.bottom + window.scrollY + 4) + 'px';
            postMenu.style.left = (rect.right + window.scrollX - 200) + 'px';
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
                        navigator.clipboard.writeText(`${location.origin}/post.php?id=${pid}`)
                            .then(() => kop.flash('Ссылка скопирована'))
                            .catch(() => kop.flash('Ошибка'));
                    } else if (action === 'report') {
                        showReportModal(pid, 'post');
                    }
                    hidePostMenu();
                });
            });
        }

        // Кастомная модалка жалобы
        function showReportModal(targetId, type) {
            const existing = document.querySelector('.report-modal-overlay');
            if (existing) existing.remove();

            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay report-modal-overlay';
            overlay.style.display = 'flex';

            overlay.innerHTML = `
                <div class="modal-container" style="max-width:440px; padding:28px 24px;">
                    <span class="modal-close" style="float:right; cursor:pointer; font-size:1.5em; line-height:1;">&times;</span>
                    <h3 style="margin:0 0 12px; font-size:1.3em; display:flex; align-items:center; gap:8px;">
                        <span style="background:#fee2e2; color:#b91c1c; padding:6px; border-radius:8px;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
                        </span>
                        Жалоба
                    </h3>
                    <p style="color:#4b5563; margin:0 0 16px;">Опишите причину. Мы рассмотрим обращение в ближайшее время.</p>
                    <textarea id="report-reason" placeholder="Что случилось?" style="width:100%; padding:14px; border-radius:14px; border:1px solid #e5e7eb; background:#f9fafb; font-family:inherit; font-size:0.95em; resize:vertical; min-height:110px; box-sizing:border-box; outline:none; transition:border-color 0.2s;"></textarea>
                    <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:20px;">
                        <button class="btn btn--secondary" id="report-cancel" style="padding:10px 20px; border-radius:10px; background:#f3f4f6; border:none; cursor:pointer;">Отмена</button>
                        <button class="btn btn--primary" id="report-submit" style="padding:10px 20px; border-radius:10px; background:#3b5dd3; color:#fff; border:none; cursor:pointer;">Отправить</button>
                    </div>
                </div>
            `;

            document.body.appendChild(overlay);
            requestAnimationFrame(() => overlay.classList.add('active'));

            const close = () => {
                overlay.classList.remove('active');
                setTimeout(() => overlay.remove(), 300);
            };

            overlay.querySelector('.modal-close').addEventListener('click', close);
            overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
            overlay.querySelector('#report-cancel').addEventListener('click', close);

            overlay.querySelector('#report-submit').addEventListener('click', async () => {
                const reason = overlay.querySelector('#report-reason').value.trim();
                if (!reason) {
                    kop.flash('Укажите причину жалобы');
                    return;
                }
                try {
                    const res = await fetch('/api/report', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': window.csrfToken
                        },
                        body: JSON.stringify({ target_id: targetId, type: type, reason: reason })
                    });
                    const data = await res.json();
                    if (res.ok) {
                        kop.flash('Жалоба отправлена');
                    } else {
                        kop.flash(data.error || 'Ошибка при отправке жалобы');
                    }
                } catch (e) {
                    kop.flash('Ошибка соединения');
                }
                close();
            });

            setTimeout(() => overlay.querySelector('#report-reason').focus(), 100);
        }

        // ---------- РЕАКЦИИ ----------
        function attachReactionHandlers() {
            document.querySelectorAll('.likeButton, .dislikeButton').forEach(btn => {
                if (btn.dataset.handlerAttached) return;
                btn.dataset.handlerAttached = '1';
                btn.addEventListener('click', async function() {
                    const postId = this.dataset.postId;
                    const type = this.classList.contains('likeButton') ? 'like' : 'dislike';
                    const res = await kop.post(`/api/posts/${type}`, { post_id: postId });
                    if (res.success) {
                        const postDiv = this.closest('.post');
                        postDiv.querySelector('.positiveCounter').textContent = res.likes_count;
                        postDiv.querySelector('.negativeCounter').textContent = res.dislikes_count;
                        postDiv.querySelector('.likeButton').classList.toggle('active', res.user_liked);
                        postDiv.querySelector('.dislikeButton').classList.toggle('active', res.user_disliked);
                    }
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
            } else postContainer.innerHTML = '<p>Пост не найден</p>';
            try {
                const data = await kop.get(`/api/posts/${postId}/comments`);
                if (data.comments && data.comments.length) {
                    commentsList.innerHTML = data.comments.map(c => {
                        const initials = (c.first_name?.charAt(0)||'')+(c.last_name?.charAt(0)||'');
                        return `
                        <div class="comment-item">
                            <div class="comment-avatar">${c.avatar ? `<img src="${c.avatar}">` : `<span>${initials}</span>`}</div>
                            <div class="comment-content">
                                <div class="comment-author"><a href="user.php?id=${c.user_id}">${c.first_name} ${c.last_name}</a></div>
                                <div class="comment-text">${c.content}</div>
                                <div class="comment-date">${new Date(c.created_at).toLocaleString()}</div>
                            </div>
                        </div>`;
                    }).join('');
                } else commentsList.innerHTML = '<p class="no-comments">Нет комментариев</p>';
            } catch(e) { commentsList.innerHTML = '<p class="error">Ошибка загрузки</p>'; }
            document.getElementById('comment-send-btn').onclick = async () => {
                const input = document.getElementById('comment-input');
                const content = input.value.trim();
                if (!content) return;
                const resp = await kop.post(`/api/posts/${postId}/comments`, { content });
                if (resp.success) {
                    const c = resp.comment;
                    const initials = (c.first_name?.charAt(0)||'')+(c.last_name?.charAt(0)||'');
                    const newComment = `
                        <div class="comment-item">
                            <div class="comment-avatar">${c.avatar ? `<img src="${c.avatar}">` : `<span>${initials}</span>`}</div>
                            <div class="comment-content">
                                <div class="comment-author"><a href="user.php?id=${c.user_id}">${c.first_name} ${c.last_name}</a></div>
                                <div class="comment-text">${c.content}</div>
                                <div class="comment-date">${new Date(c.created_at).toLocaleString()}</div>
                            </div>
                        </div>`;
                    if (commentsList.querySelector('.no-comments')) commentsList.innerHTML = '';
                    commentsList.insertAdjacentHTML('afterbegin', newComment);
                    input.value = '';
                }
            };
            modal.style.display = 'flex';
            modal.classList.add('active');
            document.body.classList.add('no-scroll');
        }
        function closeModal() {
            const modal = document.getElementById('comments-modal');
            if (modal) { modal.classList.remove('active'); modal.style.display = 'none'; document.body.classList.remove('no-scroll'); }
        }
        document.getElementById('modal-close').addEventListener('click', closeModal);
        document.getElementById('comments-modal').addEventListener('click', e => { if (e.target === e.currentTarget) closeModal(); });

        // ---------- ПОДЕЛИТЬСЯ ----------
        function attachShareButtons() {
            document.querySelectorAll('.sharePost').forEach(btn => {
                if (btn.dataset.shareAttached) return;
                btn.dataset.shareAttached = '1';
                btn.addEventListener('click', e => {
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
            if (!chats.length) chatList.innerHTML = '<p style="color:#8b8fa3;text-align:center;padding:20px;">Нет активных чатов</p>';
            else {
                chatList.innerHTML = chats.map(chat => `
                    <div class="share-chat-item" data-chat-id="${chat.chat_id}" data-other-user="${chat.other_user_id}"
                         style="display:flex;align-items:center;gap:12px;padding:12px;cursor:pointer;border-radius:12px;">
                        <img src="${chat.avatar||''}" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">
                        <span>${chat.first_name} ${chat.last_name}</span>
                    </div>
                `).join('');
                chatList.querySelectorAll('.share-chat-item').forEach(item => {
                    item.addEventListener('click', async () => {
                        const receiverId = item.dataset.otherUser;
                        const postUrl = `${location.origin}/post.php?id=${postId}`;
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
        document.getElementById('share-modal')?.addEventListener('click', e => {
            if (e.target === e.currentTarget) {
                modal.classList.remove('active');
                modal.style.display = 'none';
            }
        });

        // ---------- МОДАЛКА ДЛЯ КНОПКИ "ВЫ ДРУЗЬЯ" ----------
        function showFriendActionsModal(friendId, requesterId) {
            const alreadyEndorsed = document.getElementById('friend-actions-btn')?.dataset.alreadyEndorsed === '1';
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.style.display = 'flex';

            let buttonsHtml = '';
            if (alreadyEndorsed) {
                buttonsHtml = `
                    <span style="color:#059669;font-weight:500;">✓ Вы уже поручились</span>
                    <button class="btn btn--danger" id="remove-friend-btn">Удалить из друзей</button>
                `;
            } else {
                buttonsHtml = `
                    <button class="btn btn--success" id="vouch-btn">Поручиться</button>
                    <button class="btn btn--danger" id="remove-friend-btn">Удалить из друзей</button>
                `;
            }

            overlay.innerHTML = `
                <div class="modal-container" style="max-width:400px; padding:20px;">
                    <span class="modal-close" style="float:right; cursor:pointer;">&times;</span>
                    <h3 style="margin-top:0;">Управление дружбой</h3>
                    <p>Что вы хотите сделать?</p>
                    <div style="display:flex; gap:12px; justify-content:center; margin-top:20px;">
                        ${buttonsHtml}
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);
            setTimeout(() => overlay.classList.add('active'), 10);
            const close = () => {
                overlay.classList.remove('active');
                setTimeout(() => overlay.remove(), 300);
            };
            overlay.querySelector('.modal-close').addEventListener('click', close);
            overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

            const vouchBtn = overlay.querySelector('#vouch-btn');
            if (vouchBtn) {
                vouchBtn.addEventListener('click', async () => {
                    try {
                        const resp = await fetch('/api/dfsn/endorse', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': window.csrfToken
                            },
                            body: JSON.stringify({ user_id: friendId })
                        });
                        const data = await resp.json();
                        if (resp.ok) {
                            kop.flash(data.message || 'Поручительство принято');
                            const friendActionsBtn = document.getElementById('friend-actions-btn');
                            if (friendActionsBtn) friendActionsBtn.dataset.alreadyEndorsed = '1';
                            close();
                        } else {
                            kop.flash(data.error || 'Ошибка поручительства');
                        }
                    } catch (e) {
                        kop.flash('Не удалось поручиться');
                    }
                });
            }

            const removeBtn = overlay.querySelector('#remove-friend-btn');
            if (removeBtn) {
                removeBtn.addEventListener('click', async () => {
                    try {
                        const resp = await fetch('/api/friends/decline', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': window.csrfToken
                            },
                            body: JSON.stringify({ friend_id: friendId, requester_id: requesterId })
                        });
                        const data = await resp.json();
                        if (resp.ok) {
                            kop.flash('Пользователь удалён из друзей');
                            close();
                            replaceFriendButtonWithAdd(friendId);
                        } else {
                            kop.flash(data.error || 'Ошибка при удалении');
                        }
                    } catch (e) {
                        kop.flash('Ошибка соединения');
                    }
                });
            }
        }

        function replaceFriendButtonWithAdd(userId) {
            const container = document.querySelector('.profileActionsUnderAvatar');
            const oldBtn = document.getElementById('friend-actions-btn');
            if (!oldBtn) return;
            
            const newBtnHTML = `
                <button class="btn btn--primary" id="add-friend-btn" data-user-id="${userId}">
                    <span class="Menu__icon" style="background: #e8e0fc; color: #7c3aed;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                    </span>
                    Добавить в друзья
                </button>
            `;
            oldBtn.insertAdjacentHTML('afterend', newBtnHTML);
            oldBtn.remove();
            
            const newBtn = document.getElementById('add-friend-btn');
            if (newBtn) {
                newBtn.addEventListener('click', async function() {
                    try {
                        await kop.post('/api/friends/add', { addressee_id: this.dataset.userId });
                        kop.flash('Заявка отправлена');
                        this.outerHTML = '<span class="statusBadge">Заявка отправлена</span>';
                    } catch(e) {
                        kop.flash('Ошибка');
                    }
                });
            }
        }

        // ---------- ДРУЖБА (остальные кнопки) ----------
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
        const friendActionsBtn = document.getElementById('friend-actions-btn');
        if (friendActionsBtn) {
            friendActionsBtn.addEventListener('click', () => {
                showFriendActionsModal(friendActionsBtn.dataset.friendId, friendActionsBtn.dataset.requesterId);
            });
        }

        // ---------- НАВИГАЦИЯ ПО ВКЛАДКАМ ----------
        const navBtns = document.querySelectorAll('.profileNavigation__btn');
        function setActiveTab(act) {
            document.querySelectorAll('.post').forEach(p => p.style.display = 'none');
            document.querySelector('.facebookSection').style.display = 'none';
            document.getElementById('section-friends').style.display = 'none';
            document.querySelector('.personalInfoSection').style.display = 'none';
            if (act === 'posts') document.querySelectorAll('.post').forEach(p => p.style.display = '');
            else if (act === 'friends') document.getElementById('section-friends').style.display = '';
            else if (act === 'photos') document.querySelector('.facebookSection').style.display = '';
            else if (act === 'info') document.querySelector('.personalInfoSection').style.display = '';
            closeModal();
        }
        navBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                navBtns.forEach(b => b.classList.remove('profileNavigation__btn--active'));
                this.classList.add('profileNavigation__btn--active');
                setActiveTab(this.dataset.act);
            });
        });
        setActiveTab('posts');

        // ---------- ИНИЦИАЛИЗАЦИЯ ----------
        function attachPostMenu() {
            document.querySelectorAll('.postOptions button').forEach(btn => {
                if (btn.dataset.menuAttached) return;
                btn.dataset.menuAttached = '1';
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const postDiv = this.closest('.post');
                    showPostMenu(this, postDiv.dataset.postId, postDiv.dataset.authorId);
                });
            });
        }
        attachReactionHandlers();
        attachCommentHandler();
        attachPostMenu();
        attachShareButtons();

        // Скрываем неактивные секции
        document.querySelector('.facebookSection').style.display = 'none';
        document.getElementById('section-friends').style.display = 'none';
        document.querySelector('.personalInfoSection').style.display = 'none';

        // ---------------------- ДИНАМИЧЕСКОЕ ОБНОВЛЕНИЕ СТАТУСА ----------------------
        async function updateStatus() {
            try {
                const resp = await fetch(`/api/users/${profileUserId}/status`, {
                    headers: { 'X-CSRF-Token': window.csrfToken, 'Accept': 'application/json' }
                });

                if (!resp.ok) {
                    const errText = await resp.text();
                    console.error('Ошибка получения статуса:', resp.status, errText);
                    return;
                }

                const data = await resp.json();
                const badge = document.getElementById('profile-status-badge');
                if (badge) {
                    badge.textContent = data.text;
                    badge.className = 'profileStatus ' + data.class;
                }
            } catch (e) {
                console.error('updateStatus error:', e);
            }
        }

        // Запускаем немедленно и каждые 2 минуты
        updateStatus();
        setInterval(updateStatus, 120000);
        // ---------------------------------------------------------------------------
    </script>

    <script>
    // Фотоальбом (только просмотр)
    (function() {
        const profileUserId = <?= (int)$profileId ?>;
        const photosGrid = document.getElementById('photos-grid');
        if (!photosGrid) return;
        function loadUserPhotos() {
            fetch(`/api/get-user-photos?user_id=${profileUserId}`, {
                headers: { 'Accept': 'application/json', 'X-CSRF-Token': window.csrfToken }
            })
            .then(r => r.json())
            .then(data => {
                if (data.photos && data.photos.length) {
                    photosGrid.innerHTML = '';
                    data.photos.forEach(photo => {
                        const div = document.createElement('div'); div.className = 'facebookPicture'; div.style.position = 'relative';
                        const img = document.createElement('img'); img.src = photo.url; img.style.cssText = 'width:100%;height:100%;object-fit:cover;cursor:pointer';
                        img.addEventListener('click', () => {
                            const viewer = document.createElement('div'); viewer.className = 'image-viewer';
                            viewer.innerHTML = `<img src="${photo.url}">`;
                            viewer.addEventListener('click', () => viewer.remove());
                            document.body.appendChild(viewer);
                        });
                        div.appendChild(img);
                        photosGrid.appendChild(div);
                    });
                } else photosGrid.innerHTML = '<p style="width:100%;text-align:center;color:#8b8fa3;">Нет фото</p>';
            })
            .catch(err => { console.error(err); photosGrid.innerHTML = '<p style="text-align:center;color:#8b8fa3;">Не удалось загрузить фото</p>'; });
        }
        let loaded = false;
        const observer = new MutationObserver(() => {
            const fbSection = document.querySelector('.facebookSection');
            if (fbSection && fbSection.style.display !== 'none' && !loaded) {
                loaded = true;
                loadUserPhotos();
            }
        });
        observer.observe(document.body, { attributes: true, subtree: true, attributeFilter: ['style'] });
    })();
    </script>
</body>
</html>