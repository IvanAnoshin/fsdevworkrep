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
$showPosts = !($privacyPosts === 'friends' && !$isFriend);

$privacyAlbums = $profileUser['privacy_albums'] ?? 'all';
$showAlbums = !($privacyAlbums === 'friends' && !$isFriend);

$privacyInfo = $profileUser['privacy_info'] ?? 'all';
$showInfo = !($privacyInfo === 'friends' && !$isFriend);

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
                <!-- Левая часть: аватар + кнопки -->
                <div class="profileLeft">
                    <div class="profileAvatar">
                        <?php if (!empty($profileUser['avatar'])): ?>
                            <img src="<?= esc($profileUser['avatar']) ?>" alt="">
                        <?php else: ?>
                            <span class="accountAvatarPlaceholder">
                                <?= esc(mb_substr($profileUser['first_name'] ?? '', 0, 1) . mb_substr($profileUser['last_name'] ?? '', 0, 1)) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Кнопки под аватаркой -->
                    <div class="profileActionsUnderAvatar">
                        <?php if (!$friendship): ?>
                            <button class="btn btn--primary" id="add-friend-btn" data-user-id="<?= $profileUser['id'] ?>">
                                <span class="Menu__icon" style="background: #e8e0fc; color: #7c3aed;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                                </span>
                                
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
                            
                        </button>
                    </div>
                </div>

                <!-- Правая часть: имя, статус, био (как в profile.php) -->
                <div class="profileCardInfo">
                    <div class="profileName">
                        <span class="profileNameText"><?= esc($profileUser['first_name'] . ' ' . $profileUser['last_name']) ?></span>
                        <span class="profileStatus"><?= ($profileUser['show_online'] ?? true) ? '● в сети' : '○ был(а) недавно' ?></span>
                    </div>

                    <?php if ($showInfo): ?>
                    <div class="bio">
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #fee2e2; color: #b91c1c;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                                </svg>
                            </span>
                            <span class="bioLabel">О себе:</span>
                            <span class="bioValue"><?= esc($profileUser['about'] ?? '') ?></span>
                        </div>
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #e0f2fe; color: #0284c7;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                                </svg>
                            </span>
                            <span class="bioLabel">Город:</span>
                            <span class="bioValue"><?= esc($profileUser['city'] ?? '') ?></span>
                        </div>
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #fef3c7; color: #d97706;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                                </svg>
                            </span>
                            <span class="bioLabel">Статус отношений:</span>
                            <span class="bioValue"><?= esc($profileUser['relationship'] ?? '') ?></span>
                        </div>
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #e8e0fc; color: #7c3aed;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                                </svg>
                            </span>
                            <span class="bioLabel">Интересы:</span>
                            <span class="bioValue"><?= esc($profileUser['interests'] ?? '') ?></span>
                        </div>
                        <div class="bioItem">
                            <span class="bioIcon" style="background: #fce7f3; color: #db2777;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/>
                                </svg>
                            </span>
                            <span class="bioLabel">Мне не нравится:</span>
                            <span class="bioValue"><?= esc($profileUser['dislikes'] ?? '') ?></span>
                        </div>
                    </div>
                    <?php else: ?>
                        <p style="color:#8b8fa3;text-align:center;padding:20px;">Информация скрыта</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="profileNavigation">
                <button class="profileNavigation__btn profileNavigation__btn--active" data-act="posts">Публикации</button>
                <button class="profileNavigation__btn" data-act="photos">Фотоальбомы</button>
                <button class="profileNavigation__btn" data-act="info">Личная информация</button>
            </div>

            <div class="profileContent">
                <!-- Публикации -->
                <div id="section-posts">
                    <?php if ($showPosts): ?>
                        <?php
                        $posts = db()->query("SELECT * FROM posts WHERE user_id = $profileId ORDER BY created_at DESC LIMIT 10")->fetchAll();
                        foreach ($posts as $post) {
                            echo renderPost($post, $profileUser);
                        }
                        if (empty($posts)) {
                            echo '<div style="text-align:center;padding:40px 20px;">';
                            echo '<span style="display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:12px;background:#f5f6fa;color:#8b8fa3;margin-bottom:12px;">';
                            echo '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
                            echo '</span>';
                            echo '<p style="color:#8b8fa3;font-size:0.95em;margin:0;">Нет публикаций</p>';
                            echo '<p style="color:#c0c4cc;font-size:0.85em;margin:4px 0 0 0;">Пользователь пока ничего не опубликовал</p>';
                            echo '</div>';
                        }
                        ?>
                    <?php else: ?>
                        <div style="text-align:center;padding:40px 20px;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:12px;background:#f5f6fa;color:#8b8fa3;margin-bottom:12px;">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            </span>
                            <p style="color:#8b8fa3;font-size:0.95em;margin:0;">Публикации ограничены</p>
                            <p style="color:#c0c4cc;font-size:0.85em;margin:4px 0 0 0;">Пользователь ограничил доступ к публикациям</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Фотоальбомы -->
                <div class="facebookSection" id="section-photos">
                    <?php if ($showAlbums): ?>
                        <div class="facebookLabel"><p>Фото <?= esc($profileUser['first_name']) ?></p></div>
                        <div class="facebook">
                            <?php for ($i = 0; $i < 9; $i++): ?>
                                <img class="facebookPicture" src="" alt="">
                            <?php endfor; ?>
                        </div>
                    <?php else: ?>
                        <p style="color:#8b8fa3;text-align:center;padding:40px;">Фотоальбомы скрыты</p>
                    <?php endif; ?>
                </div>

                <!-- Личная информация -->
                <div class="personalInfoSection" id="section-info">
                    <?php if ($showInfo): ?>
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
                    <?php else: ?>
                        <p style="color:#8b8fa3;text-align:center;padding:40px;">Личная информация скрыта</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        const navBtns = document.querySelectorAll('.profileNavigation__btn');
        navBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                navBtns.forEach(b => b.classList.remove('profileNavigation__btn--active'));
                this.classList.add('profileNavigation__btn--active');
                const act = this.dataset.act;
                kop.hide('#section-posts');
                kop.hide('#section-photos');
                kop.hide('#section-info');
                if (act === 'posts') kop.show('#section-posts');
                else if (act === 'photos') kop.show('#section-photos');
                else if (act === 'info') kop.show('#section-info');
            });
        });

        const addBtn = document.getElementById('add-friend-btn');
        if (addBtn) {
            addBtn.addEventListener('click', async function() {
                const userId = this.dataset.userId;
                try {
                    await kop.post('/api/friends/add', { addressee_id: userId });
                    location.reload();
                } catch(e) { kop.flash('Ошибка', 'error'); }
            });
        }

        const acceptBtn = document.getElementById('accept-friend-btn');
        if (acceptBtn) {
            acceptBtn.addEventListener('click', async function() {
                const userId = this.dataset.userId;
                try {
                    await kop.post('/api/friends/accept', { requester_id: userId });
                    location.reload();
                } catch(e) { kop.flash('Ошибка', 'error'); }
            });
        }

        const declineBtn = document.getElementById('decline-friend-btn');
        if (declineBtn) {
            declineBtn.addEventListener('click', async function() {
                const userId = this.dataset.userId;
                try {
                    await kop.post('/api/friends/decline', { requester_id: userId });
                    location.reload();
                } catch(e) { kop.flash('Ошибка', 'error'); }
            });
        }

        kop.hide('#section-photos');
        kop.hide('#section-info');
    </script>
</body>
</html>