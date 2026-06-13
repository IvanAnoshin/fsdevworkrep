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

// --- ПОЛУЧЕНИЕ ПОСТОВ С МЕДИАФАЙЛАМИ ---
$stmt = db()->prepare("
    SELECT p.*, 
           (SELECT GROUP_CONCAT(CONCAT(pm.id, '|', pm.file_url, '|', pm.media_type) SEPARATOR ',') 
            FROM post_media pm 
            WHERE pm.post_id = p.id) AS media_list
    FROM posts p
    WHERE p.user_id = ?
    ORDER BY p.created_at DESC
    LIMIT 10
");
$stmt->execute([$_SESSION['user_id']]);
$posts = $stmt->fetchAll();

foreach ($posts as &$post) {
    $post['media'] = [];
    if (!empty($post['media_list'])) {
        $parts = explode(',', $post['media_list']);
        foreach ($parts as $part) {
            list($id, $url, $type) = explode('|', $part);
            $post['media'][] = ['id' => $id, 'url' => $url, 'type' => $type];
        }
    } elseif (!empty($post['image'])) {
        // совместимость со старыми постами (поле image)
        $oldType = preg_match('/\.(mp4|webm|mov)$/i', $post['image']) ? 'video' : 'image';
        $post['media'][] = ['id' => 0, 'url' => $post['image'], 'type' => $oldType];
    }
    unset($post['media_list']);
    unset($post['image']);
}

$friends = select(
    "SELECT u.id, u.first_name, u.last_name, u.avatar, f.requester_id
     FROM friendships f
     JOIN users u ON u.id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END
     WHERE (f.requester_id = ? OR f.addressee_id = ?) AND f.status = 'accepted'",
    [$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]
);

$pageTitle = esc($user['first_name'] . ' ' . $user['last_name']) . ' - Friendscape';

function declension($number, $titles) {
    $cases = [2, 0, 1, 1, 1, 2];
    return $titles[($number % 100 > 4 && $number % 100 < 20) ? 2 : $cases[($number % 10 < 5) ? $number % 10 : 5]];
}

/**
 * Преобразует хештеги в тексте в кликабельные ссылки.
 * Безопасно: остальной текст экранируется, хештеги проверяются.
 */
function renderHashtags(string $text): string {
    $escaped = esc($text);
    return preg_replace(
        '/#([\w\p{L}]+)/u',
        '<a href="search.php?q=%23$1" class="hashtag-link">#$1</a>',
        $escaped
    );
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .hashtag-link {
            color: #3b5dd3;
            text-decoration: none;
            font-weight: 500;
        }
        .hashtag-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="sidebar"><?php require_once "components/header.php"; ?></div>
    <div class="mainArea">
        <div class="profileContainer">
            <!-- Карточка профиля -->
            <div class="profileCard">
                <div class="profileAvatar">
                    <?php if (!empty($user['avatar'])): ?>
                        <img src="<?= esc($user['avatar']) ?>" alt="">
                    <?php else: ?>
                        <span class="accountAvatarPlaceholder"><?= esc(mb_substr($user['first_name'], 0, 1) . mb_substr($user['last_name'], 0, 1)) ?></span>
                    <?php endif; ?>
                </div>
                <div class="profileCardInfo">
                    <div class="profileName">
                        <span class="profileNameText"><?= esc($user['first_name'] . ' ' . $user['last_name']) ?></span>
                        <span class="profileStatus"><?= $onlineStatus ?></span>
                    </div>
                    <div class="bio">
                        <div class="bioItem"><span class="bioIcon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span><span class="bioLabel">О себе:</span><span class="bioValue"><?= esc($user['about'] ?? '') ?></span></div>
                        <div class="bioItem"><span class="bioIcon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2c-3.3 0-6 2.7-6 6 0 5 6 12 6 12s6-7 6-12c0-3.3-2.7-6-6-6z"/><circle cx="12" cy="8" r="2"/></svg></span><span class="bioLabel">Город:</span><span class="bioValue"><?= esc($user['city'] ?? '') ?></span></div>
                        <div class="bioItem"><span class="bioIcon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg></span><span class="bioLabel">Статус отношений:</span><span class="bioValue"><?= esc($user['relationship'] ?? '') ?></span></div>
                        <div class="bioItem"><span class="bioIcon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg></span><span class="bioLabel">Интересы:</span><span class="bioValue"><?= esc($user['interests'] ?? '') ?></span></div>
                        <div class="bioItem"><span class="bioIcon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg></span><span class="bioLabel">Мне не нравится:</span><span class="bioValue"><?= esc($user['dislikes'] ?? '') ?></span></div>
                    </div>
                </div>
            </div>

            <!-- БЛОК ПОСТИНГА (с поддержкой множественных файлов) -->
            <div class="postingSection">
                <div class="postingRow">
                    <button class="profilePinButton" id="attach-btn" type="button">
                        <span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                        </span>
                    </button>
                    <textarea id="profilePostingInput" placeholder="Что нового?"></textarea>
                    <button class="sendButton" id="send-post-btn" type="button">
                        <span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        </span>
                    </button>
                </div>
                <div id="attachments-preview" class="attachments-preview"></div>
                <input type="file" id="file-input" multiple accept="image/*,video/*" style="display: none;">
            </div>

            <!-- Навигация и контент -->
            <div class="profileNavigationWrapper"><div class="profileNavigation">
                <button class="profileNavigation__btn profileNavigation__btn--active" data-act="posts">Публикации</button>
                <button class="profileNavigation__btn" data-act="friends">Друзья</button>
                <button class="profileNavigation__btn" data-act="facebook">Фотоальбомы</button>
                <button class="profileNavigation__btn" data-act="personal">Личная информация</button>
            </div></div>

            <div class="profileContent" id="posts-container">
                <?php if (empty($posts)): ?>
                    <div class="no-posts-placeholder" style="text-align:center;padding:40px 20px;"><span style="display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:12px;background:#f0f0f0;color:#6c757d;margin-bottom:12px;"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span><p style="color:#6c757d;font-size:0.95em;margin:0;">Нет публикаций</p></div>
                <?php else: ?>
                    <?php foreach ($posts as $post): ?>
                        <div class="post" data-post-id="<?= $post['id'] ?>" data-author-id="<?= $post['user_id'] ?>">
                            <div class="postHeader">
                                <img class="opPicture" src="<?= esc($user['avatar'] ?? '') ?>" alt="">
                                <div class="opLabel"><a href=""><?= esc($user['first_name'] . ' ' . $user['last_name']) ?></a></div>
                                <div class="postOptions"><button><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg></button></div>
                            </div>
                            <div class="postBody">
                                <?php if (!empty($post['media'])): ?>
                                    <div class="carousel-container">
                                        <div class="carousel-track">
                                            <?php foreach ($post['media'] as $media): ?>
                                                <div class="carousel-slide">
                                                    <?php if ($media['type'] === 'video'): ?>
                                                        <video controls src="<?= esc($media['url']) ?>" preload="metadata"></video>
                                                    <?php else: ?>
                                                        <img src="<?= esc($media['url']) ?>" alt="">
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($post['media']) > 1): ?>
                                            <button class="carousel-prev">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <polyline points="15 18 9 12 15 6"/>
                                                </svg>
                                            </button>
                                            <button class="carousel-next">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <polyline points="9 18 15 12 9 6"/>
                                                </svg>
                                            </button>
                                            <div class="carousel-dots"></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($post['content'])): ?>
                                    <div class="postBodyText"><?= renderHashtags($post['content']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="postFooter">
                                <div class="postReactions">
                                    <button class="likeButton" data-post-id="<?= $post['id'] ?>"><span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></span></button>
                                    <p class="positiveCounter"><?= $post['likes_count'] ?></p>
                                    <button class="dislikeButton" data-post-id="<?= $post['id'] ?>"><span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg></span></button>
                                    <p class="negativeCounter"><?= $post['dislikes_count'] ?></p>
                                </div>
                                <div class="postActions">
                                    <button class="commentSheet" data-post-id="<?= $post['id'] ?>"><span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></span></button>
                                    <button class="collectionButton" data-post-id="<?= $post['id'] ?>" title="В коллекцию"><span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg></span></button>
                                    <button class="sharePost" data-post-id="<?= $post['id'] ?>"><span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98"/><path d="M15.41 6.51l-6.82 3.98"/></svg></span></button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="facebookSection"><div class="facebookLabel"><p>Мои фото</p><button id="upload-photo-btn" class="btn btn--primary" style="margin-left:16px;">+ Загрузить</button><input type="file" id="photo-file-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;"></div><div id="photos-grid" class="facebook"></div></div>

            <!-- Личная информация -->
            <div class="personalInfoSection">
                <div class="editCard">
                    <h3 class="accountTitle">Личная информация</h3>
                    <div class="accountGroup">
                        <?php
                        $fields = [
                            'hometown'   => ['label' => 'Родной город', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2c-3.3 0-6 2.7-6 6 0 5 6 12 6 12s6-7 6-12c0-3.3-2.7-6-6-6z"/><circle cx="12" cy="8" r="2"/></svg>'],
                            'city'       => ['label' => 'Город', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><line x1="8" y1="6" x2="16" y2="6"/><line x1="8" y1="10" x2="16" y2="10"/><line x1="8" y1="14" x2="16" y2="14"/><line x1="8" y1="18" x2="12" y2="18"/></svg>'],
                            'country'    => ['label' => 'Страна', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>'],
                            'languages'  => ['label' => 'Языки', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>'],
                            'job'        => ['label' => 'Работа', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>'],
                            'education'  => ['label' => 'Обучение', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>'],
                            'military'   => ['label' => 'Служба', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>'],
                            'religion'   => ['label' => 'Вера', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/></svg>'],
                            'personality'=> ['label' => 'Характер', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>'],
                            'dreams'     => ['label' => 'Мечты', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>'],
                            'intentions' => ['label' => 'Намерения', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>'],
                            'values'     => ['label' => 'Ценю в людях', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 7l10-5 10 5-10 15z"/><path d="M2 7l10 5 10-5"/><line x1="12" y1="12" x2="12" y2="22"/></svg>'],
                            'quotes'     => ['label' => 'Любимые цитаты', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 11h-4v4h4v-4z"/><path d="M18 11h-4v4h4v-4z"/><path d="M21 6v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>'],
                            'idols'      => ['label' => 'Кумиры', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>'],
                            'gadgets'    => ['label' => 'Мои гаджеты', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>']
                        ];
                        $hasData = false;
                        foreach ($fields as $key => $info):
                            $value = $user[$key] ?? '';
                            if ($value !== '') $hasData = true;
                        ?>
                            <div class="bioItem">
                                <span class="bioIcon" style="background: #f0f2f5; color: #4b5563;"><?= $info['icon'] ?></span>
                                <span class="bioLabel"><?= $info['label'] ?>:</span>
                                <span class="bioValue"><?= esc($value) ?: '<span style="color:#8b8fa3;">Не указано</span>' ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$hasData): ?>
                            <p style="color:#8b8fa3;text-align:center;padding:20px;">Здесь пока ничего нет. Заполните раздел <a href="settings.php?act=edit">«Личное»</a> в настройках.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Вкладка Друзья -->
            <div id="section-friends">
                <div class="friends-header">
                    <h3 style="margin:0; font-size:1.2em;">Мои друзья</h3>
                    <span class="friends-count"><?= count($friends) ?> <?= declension(count($friends), ['друг', 'друга', 'друзей']) ?></span>
                </div>
                <?php if (empty($friends)): ?>
                    <div style="text-align:center;padding:40px 20px;"><p style="color:#8b8fa3;">Нет друзей</p></div>
                <?php else: ?>
                    <div class="friends-grid" id="friends-grid">
                        <?php foreach ($friends as $friend): ?>
                            <div class="friend-card" data-friend-id="<?= $friend['id'] ?>" data-requester-id="<?= $friend['requester_id'] ?>">
                                <?php if (!empty($friend['avatar'])): ?>
                                    <img class="friend-avatar" src="<?= esc($friend['avatar']) ?>" alt="">
                                <?php else: ?>
                                    <div class="friend-avatar-placeholder"><?= esc(mb_substr($friend['first_name']??'',0,1).mb_substr($friend['last_name']??'',0,1)) ?></div>
                                <?php endif; ?>
                                <div class="friend-info">
                                    <a href="user.php?id=<?= $friend['id'] ?>" class="friend-name"><?= esc($friend['first_name'].' '.$friend['last_name']) ?></a>
                                </div>
                                <button class="remove-friend-btn" title="Удалить из друзей">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Модальные окна -->
    <div class="modal-overlay" id="comments-modal" style="display:none;"><div class="modal-container"><span class="modal-close" id="modal-close">&times;</span><div id="modal-post-container"></div><div class="comments-block"><div class="comment-form"><textarea id="comment-input" placeholder="Написать комментарий..."></textarea><button id="comment-send-btn"><span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></span></button></div></div><div class="comments-list" id="comments-list"></div></div></div>
    <div class="modal-overlay" id="share-modal" style="display:none;"><div class="modal-container" style="padding:20px;max-width:400px;"><span class="modal-close" id="share-modal-close">&times;</span><h3 style="margin:0 0 16px;">Отправить пост</h3><div id="share-chat-list" style="max-height:300px;overflow-y:auto;"></div></div></div>

    <script>
        // ---------- Глобальная функция экранирования ----------
        function esc(str) {
            if (str == null) return '';
            return String(str).replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        // ---------- Функция для рендеринга хештегов в клиентском JS ----------
        function renderHashtagsInText(text) {
            if (!text) return '';
            const escaped = esc(text);
            return escaped.replace(/#([\w\p{L}]+)/gu, '<a href="search.php?q=%23$1" class="hashtag-link">#$1</a>');
        }

        // ---------- Модалка подтверждения ----------
        function showConfirm(title, message, onConfirm, onCancel) {
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
            const close = () => {
                overlay.classList.remove('active');
                setTimeout(() => overlay.remove(), 300);
            };
            overlay.querySelector('.modal-close').addEventListener('click', () => {
                if (onCancel) onCancel();
                close();
            });
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    if (onCancel) onCancel();
                    close();
                }
            });
            overlay.querySelector('#confirm-cancel').addEventListener('click', () => {
                if (onCancel) onCancel();
                close();
            });
            overlay.querySelector('#confirm-ok').addEventListener('click', () => {
                if (onConfirm) onConfirm();
                close();
            });
        }

        // ---------- ОБЩИЕ ПЕРЕМЕННЫЕ ----------
        const currentUserId = <?= (int)$_SESSION['user_id'] ?>;
        const postMenu = document.createElement('div'); postMenu.className = 'post-actions-menu'; document.body.appendChild(postMenu);
        function hidePostMenu() { postMenu.classList.remove('active'); }
        document.addEventListener('click', (e) => { if (!postMenu.contains(e.target) && !e.target.closest('.postOptions button')) hidePostMenu(); });
        function openImageViewer(url) { const viewer = document.createElement('div'); viewer.className = 'image-viewer'; viewer.innerHTML = `<img src="${url}" alt="">`; viewer.addEventListener('click', () => viewer.remove()); document.body.appendChild(viewer); }

        // ---------- ПОЛНОЭКРАННЫЙ ПРОСМОТР ОРИГИНАЛА (БЕЗ ОБРЕЗКИ) ----------
        function openFullMedia(src, type) {
            const overlay = document.createElement('div');
            overlay.className = 'media-fullscreen';
            
            const closeBtn = document.createElement('div');
            closeBtn.className = 'close-btn';
            closeBtn.innerHTML = '&times;';
            
            let media;
            if (type === 'image') {
                media = document.createElement('img');
                media.src = src;
                media.className = 'media-content';
            } else {
                media = document.createElement('video');
                media.src = src;
                media.controls = true;
                media.autoplay = true;
                media.className = 'media-content';
            }
            
            overlay.appendChild(media);
            overlay.appendChild(closeBtn);
            
            const removeOverlay = () => {
                overlay.classList.remove('visible');
                setTimeout(() => {
                    if (overlay.parentNode) overlay.remove();
                }, 300);
            };
            
            closeBtn.onclick = removeOverlay;
            overlay.onclick = (e) => {
                if (e.target === overlay) removeOverlay();
            };
            
            document.body.appendChild(overlay);
            // принудительный reflow, затем добавляем класс visible для анимации
            requestAnimationFrame(() => {
                overlay.classList.add('visible');
            });
        }

        // ---------- ПРИВЯЗКА КЛИКОВ НА МЕДИА В КАРУСЕЛИ ----------
        function bindMediaClicks() {
            document.querySelectorAll('.carousel-slide').forEach(slide => {
                const img = slide.querySelector('img');
                const video = slide.querySelector('video');
                if (img && !img.dataset.clickBound) {
                    img.dataset.clickBound = '1';
                    img.addEventListener('click', (e) => {
                        e.stopPropagation();
                        openFullMedia(img.src, 'image');
                    });
                }
                if (video && !video.dataset.clickBound) {
                    video.dataset.clickBound = '1';
                    video.addEventListener('click', (e) => {
                        e.stopPropagation();
                        openFullMedia(video.src, 'video');
                    });
                }
            });
        }

        // ---------- ИНИЦИАЛИЗАЦИЯ КАРУСЕЛИ ----------
        function initCarousel(container) {
            const track = container.querySelector('.carousel-track');
            const slides = track ? Array.from(track.children) : [];
            if (slides.length <= 1) return;

            const prevBtn = container.querySelector('.carousel-prev');
            const nextBtn = container.querySelector('.carousel-next');
            const dotsNav = container.querySelector('.carousel-dots');
            let currentIndex = 0;

            function updateCarouselTransform() {
                const slideWidth = slides[0].getBoundingClientRect().width;
                track.style.transform = 'translateX(-' + (currentIndex * slideWidth) + 'px)';
                if (dotsNav) {
                    Array.from(dotsNav.children).forEach((dot, i) => {
                        dot.classList.toggle('active', i === currentIndex);
                    });
                }
            }

            function goToSlide(index) {
                if (index < 0) index = 0;
                if (index >= slides.length) index = slides.length - 1;
                if (index === currentIndex) return;
                currentIndex = index;
                updateCarouselTransform();
            }

            if (prevBtn) prevBtn.addEventListener('click', () => goToSlide(currentIndex - 1));
            if (nextBtn) nextBtn.addEventListener('click', () => goToSlide(currentIndex + 1));

            // Свайпы (touch)
            let touchStartX = 0;
            container.addEventListener('touchstart', (e) => {
                touchStartX = e.changedTouches[0].screenX;
            });
            container.addEventListener('touchend', (e) => {
                const touchEndX = e.changedTouches[0].screenX;
                const diff = touchEndX - touchStartX;
                if (Math.abs(diff) > 50) {
                    if (diff > 0) goToSlide(currentIndex - 1);
                    else goToSlide(currentIndex + 1);
                }
            });

            // Создаём точки
            if (dotsNav && dotsNav.children.length === 0 && slides.length > 1) {
                for (let i = 0; i < slides.length; i++) {
                    const dot = document.createElement('button');
                    dot.classList.add('carousel-dot');
                    if (i === currentIndex) dot.classList.add('active');
                    dot.addEventListener('click', () => goToSlide(i));
                    dotsNav.appendChild(dot);
                }
            }

            window.addEventListener('resize', () => updateCarouselTransform());
            updateCarouselTransform();
            // После инициализации привязываем клики (на случай, если слайды уже есть)
            bindMediaClicks();
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

        // ---------- МЕНЮ ПОСТА ----------
        function showPostMenu(button, postId, authorId) {
            const rect = button.getBoundingClientRect();
            postMenu.style.top = (rect.bottom + window.scrollY + 4) + 'px';
            postMenu.style.left = (rect.right + window.scrollX - 200) + 'px';
            const isOwn = (authorId == currentUserId);
            let itemsHTML = '';
            if (isOwn) {
                itemsHTML += `<div class="post-actions-menu__item" data-action="edit" data-post-id="${postId}"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Редактировать</div>
                               <div class="post-actions-menu__item post-actions-menu__item--danger" data-action="delete" data-post-id="${postId}"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg> Удалить</div>`;
            } else {
                itemsHTML += `<div class="post-actions-menu__item" data-action="hide" data-post-id="${postId}"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg> Скрыть</div>`;
            }
            itemsHTML += `<div class="post-actions-menu__item" data-action="copy-link" data-post-id="${postId}"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> Скопировать ссылку</div>`;
            if (!isOwn) {
                itemsHTML += `<div class="post-actions-menu__item" data-action="report" data-post-id="${postId}"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg> Пожаловаться</div>`;
            }
            postMenu.innerHTML = itemsHTML;
            postMenu.classList.add('active');
            postMenu.querySelectorAll('.post-actions-menu__item').forEach(item => {
                item.addEventListener('click', function() {
                    const action = this.dataset.action;
                    const pid = this.dataset.postId;
                    handleMenuAction(action, pid);
                    hidePostMenu();
                });
            });
        }

        async function handleMenuAction(action, postId) {
            switch(action) {
                case 'copy-link':
                    const url = `${window.location.origin}/post.php?id=${postId}`;
                    await navigator.clipboard.writeText(url);
                    kop.flash('Ссылка скопирована');
                    break;
                case 'delete':
                    showConfirm('Подтверждение', 'Удалить пост? Это действие нельзя отменить.', async () => {
                        try {
                            const response = await fetch(`/api/posts/${postId}`, {
                                method: 'DELETE',
                                headers: { 'X-CSRF-Token': document.querySelector('input[name="_csrf"]').value }
                            });
                            if (response.ok) {
                                document.querySelector(`.post[data-post-id="${postId}"]`)?.remove();
                                kop.flash('Пост удалён');
                            } else {
                                const err = await response.json();
                                kop.flash(err.error || 'Ошибка удаления');
                            }
                        } catch(e) {
                            kop.flash('Ошибка соединения');
                        }
                    });
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
        async function startEditPost(postId) {
            const postDiv = document.querySelector(`.post[data-post-id="${postId}"]`);
            if (!postDiv || postDiv.classList.contains('editing')) return;
            postDiv.classList.add('editing');
            const postBody = postDiv.querySelector('.postBody');
            const postFooter = postDiv.querySelector('.postFooter');
            postDiv.dataset.originalBody = postBody.innerHTML;
            postDiv.dataset.originalFooter = postFooter.innerHTML;

            const textEl = postBody.querySelector('.postBodyText');
            const currentText = textEl ? textEl.textContent : '';
            const mediaEl = postBody.querySelector('.carousel-container');
            const currentMedia = mediaEl ? 'есть' : '';

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
            attachReactionHandlers();
            attachCommentHandler();
            attachPostMenu();
            attachCollectionButtons();
            attachShareButtons();
            const carousel = postDiv.querySelector('.carousel-container');
            if (carousel) initCarousel(carousel);
        }

        function updatePostDisplay(postDiv, postData) {
            window.location.reload();
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

        // ---------- КОЛЛЕКЦИЯ ----------
        function attachCollectionButtons() {
            document.querySelectorAll('.collectionButton').forEach(btn => {
                if (btn.dataset.collectionAttached) return;
                btn.dataset.collectionAttached = '1';
                btn.addEventListener('click', async function(e) {
                    e.stopPropagation();
                    const postId = this.dataset.postId;
                    try {
                        const resp = await kop.post('/api/collection/add', { post_id: postId });
                        if (resp.success) {
                            kop.flash('Добавлено в коллекцию');
                        } else {
                            kop.flash(resp.error || 'Ошибка');
                        }
                    } catch(e) {
                        kop.flash('Ошибка при добавлении');
                    }
                });
            });
        }

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
                        modal.classList.remove('active');
                        modal.style.display = 'none';
                    });
                });
            }
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('active'), 10);
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

        // ---------- КОММЕНТАРИИ ----------
        function attachCommentHandler() {
            document.querySelectorAll('.commentSheet').forEach(btn => {
                if (btn.dataset.commentHandlerAttached) return;
                btn.dataset.commentHandlerAttached = '1';
                btn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    const postId = btn.dataset.postId;
                    await openCommentsModal(postId);
                });
            });
        }

        async function openCommentsModal(postId) {
            const modal = document.getElementById('comments-modal');
            const postContainer = document.getElementById('modal-post-container');
            const commentsList = document.getElementById('comments-list');
            const commentInput = document.getElementById('comment-input');
            const sendBtn = document.getElementById('comment-send-btn');

            const originalPost = document.querySelector(`.post[data-post-id="${postId}"]`);
            if (originalPost) {
                const bodyClone = originalPost.querySelector('.postBody').cloneNode(true);
                postContainer.innerHTML = '';
                postContainer.appendChild(bodyClone);
                // Инициализация каруселей внутри модального окна
                postContainer.querySelectorAll('.carousel-container').forEach(initCarousel);
                // Привязка кликов на медиа внутри модалки
                postContainer.querySelectorAll('.carousel-slide').forEach(slide => {
                    const img = slide.querySelector('img');
                    const video = slide.querySelector('video');
                    if (img && !img.dataset.clickBound) {
                        img.dataset.clickBound = '1';
                        img.addEventListener('click', (e) => {
                            e.stopPropagation();
                            openFullMedia(img.src, 'image');
                        });
                    }
                    if (video && !video.dataset.clickBound) {
                        video.dataset.clickBound = '1';
                        video.addEventListener('click', (e) => {
                            e.stopPropagation();
                            openFullMedia(video.src, 'video');
                        });
                    }
                });
            } else {
                postContainer.innerHTML = '<p>Пост не найден</p>';
            }

            try {
                const data = await kop.get(`/api/posts/${postId}/comments`);
                if (data.comments && data.comments.length > 0) {
                    // Свежие комментарии первыми
                    data.comments.reverse();
                    commentsList.innerHTML = data.comments.map(c => {
                        const initials = (c.first_name?.charAt(0) || '') + (c.last_name?.charAt(0) || '');
                        const avatarHtml = c.avatar
                            ? `<img src="${c.avatar}" alt="">`
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
                } else {
                    commentsList.innerHTML = '<p class="no-comments">Нет комментариев. Будьте первым!</p>';
                }
            } catch(e) {
                console.error(e);
                commentsList.innerHTML = '<p class="error">Ошибка загрузки комментариев</p>';
            }

            const newSendBtn = sendBtn.cloneNode(true);
            sendBtn.parentNode.replaceChild(newSendBtn, sendBtn);
            newSendBtn.onclick = async () => {
                const content = commentInput.value.trim();
                if (!content) return;
                try {
                    const response = await kop.post(`/api/posts/${postId}/comments`, { content });
                    if (response.success) {
                        const c = response.comment;
                        const initials = (c.first_name?.charAt(0) || '') + (c.last_name?.charAt(0) || '');
                        const avatarHtml = c.avatar
                            ? `<img src="${c.avatar}" alt="">`
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
                        if (commentsList.querySelector('.no-comments')) commentsList.innerHTML = '';
                        commentsList.insertAdjacentHTML('afterbegin', newCommentHtml);
                        commentInput.value = '';
                    }
                } catch(e) {
                    console.error(e);
                    kop.flash('Ошибка отправки комментария');
                }
            };

            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('active'), 10);
            document.body.classList.add('no-scroll');

            const closeBtn = document.getElementById('modal-close');
            if (closeBtn) {
                const newCloseBtn = closeBtn.cloneNode(true);
                closeBtn.parentNode.replaceChild(newCloseBtn, closeBtn);
                newCloseBtn.addEventListener('click', closeModal);
            }
            modal.addEventListener('click', function onOverlayClick(e) {
                if (e.target === modal) {
                    closeModal();
                    modal.removeEventListener('click', onOverlayClick);
                }
            });
        }

        function closeModal() {
            const modal = document.getElementById('comments-modal');
            if (!modal) return;
            modal.classList.remove('active');
            setTimeout(() => { modal.style.display = 'none'; }, 300);
            document.body.classList.remove('no-scroll');
        }

        // ---------- УДАЛЕНИЕ ДРУГА ----------
        async function removeFriend(friendId, cardElement, friendName) {
            showConfirm('Удалить друга', `Вы уверены, что хотите удалить ${friendName} из друзей?`, async () => {
                try {
                    await kop.post('/api/friends/decline', { friend_id: friendId });
                    cardElement.remove();
                    const grid = document.getElementById('friends-grid');
                    const newCount = grid ? grid.children.length : 0;
                    const countSpan = document.querySelector('.friends-count');
                    if (countSpan) {
                        const word = newCount % 10 === 1 && newCount % 100 !== 11 ? 'друг' : (newCount % 10 >= 2 && newCount % 10 <= 4 && (newCount % 100 < 10 || newCount % 100 >= 20) ? 'друга' : 'друзей');
                        countSpan.textContent = newCount + ' ' + word;
                    }
                    kop.flash(`${friendName} удалён(а) из друзей`);
                    if (newCount === 0) {
                        document.getElementById('section-friends').innerHTML = '<div style="text-align:center;padding:40px 20px;"><p style="color:#8b8fa3;">Нет друзей</p></div>';
                    }
                } catch(e) { kop.flash('Не удалось удалить друга'); }
            });
        }

        function initFriendsActions() {
            document.querySelectorAll('.remove-friend-btn').forEach(btn => {
                if (btn.dataset.removeAttached) return;
                btn.dataset.removeAttached = '1';
                btn.addEventListener('click', (e) => {
                    e.preventDefault(); e.stopPropagation();
                    const card = btn.closest('.friend-card');
                    const friendId = card.dataset.friendId;
                    const friendName = card.querySelector('.friend-name').textContent;
                    removeFriend(friendId, card, friendName);
                });
            });
        }

        // ---------- ПРИКРЕПЛЕНИЕ ФАЙЛОВ И ПОСТИНГ (С ПОДДЕРЖКОЙ КАРУСЕЛИ) ----------
        const postInput = document.getElementById('profilePostingInput');
        const attachmentsContainer = document.getElementById('attachments-preview');
        let selectedFiles = [];

        function autoResize() { postInput.style.height = 'auto'; postInput.style.height = Math.max(postInput.scrollHeight, 20) + 'px'; }
        postInput.addEventListener('input', autoResize);
        autoResize();

        document.getElementById('attach-btn').addEventListener('click', () => {
            document.getElementById('file-input').click();
        });

        const videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'flv', '3gp', 'm4v', 'mpg', 'mpeg', 'wmv', 'ogv'];
        const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'];

        function getFileTypeByExtension(file) {
            const ext = file.name.split('.').pop().toLowerCase();
            if (videoExtensions.includes(ext)) return 'video';
            if (imageExtensions.includes(ext)) return 'image';
            return null;
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Б';
            const k = 1024;
            const sizes = ['Б', 'КБ', 'МБ', 'ГБ'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        }

        const MAX_VIDEO_SIZE_MB = 1024;
        document.getElementById('file-input').addEventListener('change', function(e) {
            const files = Array.from(e.target.files);
            if (selectedFiles.length + files.length > 20) {
                kop.flash('Можно прикрепить не более 20 файлов');
                return;
            }
            for (const file of files) {
                let fileType = getFileTypeByExtension(file);
                if (!fileType) {
                    kop.flash(`Файл ${file.name} не поддерживается`);
                    continue;
                }
                if (fileType === 'video' && file.size > MAX_VIDEO_SIZE_MB * 1024 * 1024) {
                    kop.flash(`Видео ${file.name} превышает 1 ГБ. Пожалуйста, выберите файл меньше.`, 5000);
                    continue;
                }
                const previewUrl = URL.createObjectURL(file);
                const newItem = { file, previewUrl, type: fileType };
                selectedFiles.push(newItem);
            }
            renderAttachments();
            this.value = '';
        });

        function removeAttachment(index) {
            if (selectedFiles[index]?.previewUrl) URL.revokeObjectURL(selectedFiles[index].previewUrl);
            selectedFiles.splice(index, 1);
            renderAttachments();
        }

        function renderAttachments() {
            if (!attachmentsContainer) return;
            attachmentsContainer.innerHTML = '';
            if (selectedFiles.length === 0) return;
            
            selectedFiles.forEach((item, idx) => {
                const div = document.createElement('div');
                div.className = 'attachment-item';
                div.setAttribute('data-idx', idx);
                
                if (item.type === 'image') {
                    const img = document.createElement('img');
                    img.src = item.previewUrl;
                    div.appendChild(img);
                } else {
                    const card = document.createElement('div');
                    card.className = 'video-info-card';
                    const sizeText = formatFileSize(item.file.size);
                    card.innerHTML = `
                        <div class="video-icon">🎬</div>
                        <div class="video-name" title="${esc(item.file.name)}">${esc(item.file.name)}</div>
                        <div class="video-size">${sizeText}</div>
                    `;
                    div.appendChild(card);
                    div.onclick = () => {
                        const viewer = document.createElement('div');
                        viewer.className = 'image-viewer';
                        viewer.innerHTML = `<video controls autoplay src="${item.previewUrl}" style="max-width:90%; max-height:90%;"></video>`;
                        viewer.onclick = () => viewer.remove();
                        document.body.appendChild(viewer);
                    };
                }
                
                const removeBtn = document.createElement('button');
                removeBtn.textContent = '✕';
                removeBtn.className = 'remove-attachment';
                removeBtn.onclick = (e) => {
                    e.stopPropagation();
                    removeAttachment(idx);
                };
                div.appendChild(removeBtn);
                attachmentsContainer.appendChild(div);
            });
        }

        // Функция рендеринга HTML поста с каруселью (с SVG-кнопками) – ОБНОВЛЕНО: поддержка хештегов
        function renderPostHTML(post, avatarSrc, fullName) {
            let mediaHtml = '';
            if (post.media && post.media.length) {
                mediaHtml = `
                    <div class="carousel-container">
                        <div class="carousel-track">
                            ${post.media.map(media => {
                                if (media.type === 'video') {
                                    return `<div class="carousel-slide"><video controls src="${esc(media.url)}" preload="metadata"></video></div>`;
                                } else {
                                    return `<div class="carousel-slide"><img src="${esc(media.url)}" alt=""></div>`;
                                }
                            }).join('')}
                        </div>
                        ${post.media.length > 1 ? `
                            <button class="carousel-prev">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="15 18 9 12 15 6"/>
                                </svg>
                            </button>
                            <button class="carousel-next">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="9 18 15 12 9 6"/>
                                </svg>
                            </button>
                            <div class="carousel-dots"></div>
                        ` : ''}
                    </div>
                `;
            }
            const textHtml = post.content ? `<div class="postBodyText">${renderHashtagsInText(post.content)}</div>` : '';
            const footerHtml = `<div class="postFooter"><div class="postReactions"><button class="likeButton" data-post-id="${post.id}"><span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></span></button><p class="positiveCounter">${post.likes_count}</p><button class="dislikeButton" data-post-id="${post.id}"><span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg></span></button><p class="negativeCounter">${post.dislikes_count}</p></div><div class="postActions"><button class="commentSheet" data-post-id="${post.id}"><span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></span></button><button class="collectionButton" data-post-id="${post.id}" title="В коллекцию"><span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg></span></button><button class="sharePost" data-post-id="${post.id}"><span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98"/><path d="M15.41 6.51l-6.82 3.98"/></svg></span></button></div></div>`;
            return `
                <div class="post" data-post-id="${post.id}" data-author-id="${post.user_id}">
                    <div class="postHeader"><img class="opPicture" src="${avatarSrc}" alt=""><div class="opLabel"><a href="">${fullName}</a></div><div class="postOptions"><button><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg></button></div></div>
                    <div class="postBody">${mediaHtml}${textHtml}</div>
                    ${footerHtml}
                </div>
            `;
        }

        // Отправка поста (хештеги в новом посте обработаются автоматически)
        document.getElementById('send-post-btn').addEventListener('click', async function() {
            const content = postInput.value.trim();
            if (!content && selectedFiles.length === 0) return;
            const formData = new FormData();
            formData.append('content', content);
            for (let i = 0; i < selectedFiles.length; i++) {
                formData.append('files[]', selectedFiles[i].file);
            }
            try {
                const response = await fetch('/api/posts/create', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': document.querySelector('input[name="_csrf"]').value },
                    body: formData
                });
                if (!response.ok) {
                    const errText = await response.text();
                    console.error('Server error:', errText);
                    kop.flash(`Ошибка ${response.status}: возможно, файл слишком большой.`);
                    return;
                }
                const data = await response.json();
                if (data.success && data.post) {
                    postInput.value = ''; autoResize();
                    selectedFiles.forEach(item => URL.revokeObjectURL(item.previewUrl));
                    selectedFiles = []; renderAttachments();
                    const container = document.getElementById('posts-container');
                    const placeholder = container.querySelector('.no-posts-placeholder');
                    if (placeholder) placeholder.remove();
                    const post = data.post;
                    const avatarSrc = "<?= esc($user['avatar'] ?? '') ?>";
                    const fullName = "<?= esc($user['first_name'] . ' ' . $user['last_name']) ?>";
                    const newPostHTML = renderPostHTML(post, avatarSrc, fullName);
                    container.insertAdjacentHTML('afterbegin', newPostHTML);
                    const newPostDiv = container.firstElementChild;
                    const carousel = newPostDiv.querySelector('.carousel-container');
                    if (carousel) initCarousel(carousel);
                    attachReactionHandlers(); attachCommentHandler(); attachPostMenu(); attachCollectionButtons(); attachShareButtons();
                } else {
                    kop.flash(data.error || 'Не удалось создать пост');
                }
            } catch(e) {
                console.error(e);
                kop.flash('Ошибка при создании поста');
            }
        });

        // ---------- МЕНЮ ПОСТА ----------
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

        // ---------- НАВИГАЦИЯ ПО ВКЛАДКАМ ----------
        kop.hide('.facebookSection');
        kop.hide('.personalInfoSection');
        kop.hide('#section-friends');

        const allNavBtns = () => document.querySelectorAll('.profileNavigation__btn');
        allNavBtns().forEach(btn => btn.classList.remove('profileNavigation__btn--active'));
        if (allNavBtns().length) allNavBtns()[0].classList.add('profileNavigation__btn--active');
        kop.on('.profileNavigation__btn', 'click', function(e) {
            e.preventDefault();
            const act = this.dataset.act;
            allNavBtns().forEach(btn => btn.classList.remove('profileNavigation__btn--active'));
            this.classList.add('profileNavigation__btn--active');
            document.querySelectorAll('.post').forEach(p => p.style.display = 'none');
            document.querySelector('.facebookSection').style.display = 'none';
            document.querySelector('.personalInfoSection').style.display = 'none';
            document.getElementById('section-friends').style.display = 'none';
            if (act === 'facebook') document.querySelector('.facebookSection').style.display = '';
            else if (act === 'personal') document.querySelector('.personalInfoSection').style.display = '';
            else if (act === 'friends') document.getElementById('section-friends').style.display = '';
            else document.querySelectorAll('.post').forEach(p => p.style.display = '');
            closeModal();
        });

        // Инициализация карусели и кликов для всех загруженных постов
        document.querySelectorAll('.carousel-container').forEach(initCarousel);
        bindMediaClicks(); // дополнительная гарантия

        // Инициализация всех обработчиков
        attachReactionHandlers();
        attachCommentHandler();
        attachPostMenu();
        attachCollectionButtons();
        attachShareButtons();
        initFriendsActions();

        // Добавим стили для хештегов динамически, если их нет в main.css
        const hashtagStyle = document.createElement('style');
        hashtagStyle.textContent = '.hashtag-link{color:#3b5dd3;text-decoration:none;font-weight:500}.hashtag-link:hover{text-decoration:underline}';
        document.head.appendChild(hashtagStyle);
    </script>
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <script>
    // Фотоальбом (без изменений)
    (function() {
        if (!window.csrfToken) window.csrfToken = document.querySelector('input[name="_csrf"]')?.value;
        const uploadBtn = document.getElementById('upload-photo-btn');
        const fileInput = document.getElementById('photo-file-input');
        const photosGrid = document.getElementById('photos-grid');
        if (!uploadBtn || !fileInput || !photosGrid) return;
        function loadPhotos() {
            fetch('/api/get-photos', { headers: { 'Accept': 'application/json', 'X-CSRF-Token': window.csrfToken } })
            .then(r => r.json())
            .then(data => {
                if (data.photos?.length) {
                    photosGrid.innerHTML = '';
                    data.photos.forEach(photo => {
                        const div = document.createElement('div');
                        div.className = 'facebookPicture';
                        div.style.position = 'relative';
                        const img = document.createElement('img');
                        img.src = photo.url || '';
                        img.style.cssText = 'width:100%;height:100%;object-fit:cover;cursor:pointer';
                        img.addEventListener('click', () => {
                            const viewer = document.createElement('div');
                            viewer.className = 'image-viewer';
                            viewer.innerHTML = `<img src="${photo.url || ''}" alt="">`;
                            viewer.addEventListener('click', () => viewer.remove());
                            document.body.appendChild(viewer);
                        });
                        const delBtn = document.createElement('button');
                        delBtn.innerHTML = '✕';
                        delBtn.style.cssText = 'position:absolute;top:4px;right:4px;background:rgba(0,0,0,0.6);color:#fff;border:none;border-radius:50%;width:24px;height:24px;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center';
                        delBtn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            showConfirm('Удалить фото', 'Вы уверены, что хотите удалить это фото?', () => {
                                fetch('/api/delete-photo', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
                                    body: JSON.stringify({ photo_id: photo.id })
                                })
                                .then(r => r.json())
                                .then(res => { if (res.success) loadPhotos(); else kop.flash('Ошибка удаления'); })
                                .catch(err => kop.flash('Ошибка соединения'));
                            });
                        });
                        div.appendChild(img);
                        div.appendChild(delBtn);
                        photosGrid.appendChild(div);
                    });
                } else {
                    photosGrid.innerHTML = '<p style="width:100%;text-align:center;color:#8b8fa3;grid-column:1/-1;">Нет фото. Загрузите первое!</p>';
                }
            })
            .catch(err => { console.error(err); kop.flash('Не удалось загрузить фото'); });
        }
        uploadBtn.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;
            const formData = new FormData();
            formData.append('photo', file);
            fetch('/api/upload-photo', {
                method: 'POST',
                headers: { 'X-CSRF-Token': window.csrfToken },
                body: formData
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    loadPhotos();
                    kop.flash('Фото загружено');
                } else kop.flash(res.error || 'Ошибка загрузки');
            })
            .catch(err => kop.flash('Ошибка соединения'));
            fileInput.value = '';
        });
        let loaded = false;
        const observer = new MutationObserver(() => {
            const fbSection = document.querySelector('.facebookSection');
            if (fbSection && fbSection.style.display !== 'none' && !loaded) {
                loaded = true;
                loadPhotos();
            }
        });
        observer.observe(document.body, { attributes: true, subtree: true, attributeFilter: ['style'] });
    })();
    </script>
</body>
</html>