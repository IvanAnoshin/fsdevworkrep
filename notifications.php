<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';
require_auth();

$user = find('users', $_SESSION['user_id']);
$pageTitle = 'Уведомления - Friendscape';

// Получаем фильтр
$filter = $_GET['filter'] ?? 'all'; // all, unread

// Получаем уведомления
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

if ($filter === 'unread') {
    $where = "WHERE n.user_id = ? AND n.is_read = 0";
    $total = scalar("SELECT COUNT(*) FROM notifications n $where", [$user['id']]);
} else {
    $where = "WHERE n.user_id = ?";
    $total = scalar("SELECT COUNT(*) FROM notifications n $where", [$user['id']]);
}

$lastPage = max(1, (int)ceil($total / $perPage));

$notifications = select(
    "SELECT n.*,
    CONCAT(u.first_name, ' ', u.last_name) AS actor_name,
    u.avatar AS actor_avatar
    FROM notifications n
    LEFT JOIN users u ON u.id = n.actor_id
    $where
    ORDER BY n.is_read ASC, n.created_at DESC
    LIMIT $perPage OFFSET $offset",
    [$user['id']]
);

// Считаем непрочитанные
$unreadCount = scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [$user['id']]);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageTitle ?></title>
<link rel="stylesheet" href="css/main.css">
<style>
.notifications-page {
    max-width: 700px;
    margin: 20px auto;
    padding: 0 16px;
}
.notifications-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid #eef1f8;
}
.notifications-title {
    font-size: 1.8em;
    font-weight: 600;
    color: #1e1e2f;
    margin: 0;
}
.notifications-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
}
.notification-tab {
    padding: 8px 16px;
    border-radius: 20px;
    border: none;
    background: #f0f2f5;
    color: #5a6072;
    font-size: 0.95em;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.notification-tab.active {
    background: #3b5dd3;
    color: white;
}
.notification-tab:hover:not(.active) {
    background: #e4e6eb;
}
.notification-tab .badge {
    background: #ef4444;
    color: white;
    font-size: 0.75em;
    padding: 2px 8px;
    border-radius: 10px;
    min-width: 20px;
    text-align: center;
}
.notification-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.notification-item {
    background: white;
    border-radius: 16px;
    padding: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    display: flex;
    gap: 12px;
    align-items: flex-start;
    transition: background 0.2s;
    position: relative;
}
.notification-item.unread {
    background: #f8f9ff;
    border-left: 3px solid #3b5dd3;
}
.notification-item:hover {
    background: #fafbfc;
}
.notification-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4em;
    flex-shrink: 0;
}
.notification-content {
    flex: 1;
    min-width: 0;
}
.notification-text {
    font-size: 0.95em;
    color: #1e1e2f;
    line-height: 1.5;
    margin-bottom: 4px;
}
.notification-text a {
    color: #3b5dd3;
    text-decoration: none;
    font-weight: 500;
}
.notification-text a:hover {
    text-decoration: underline;
}
.notification-time {
    font-size: 0.8em;
    color: #8b8fa3;
}
.notification-actions {
    display: flex;
    gap: 8px;
    margin-top: 10px;
}
.notification-actions button {
    padding: 6px 12px;
    border-radius: 8px;
    border: none;
    font-size: 0.85em;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.2s;
}
.btn-accept {
    background: #10b981;
    color: white;
}
.btn-accept:hover {
    background: #059669;
}
.btn-decline {
    background: #ef4444;
    color: white;
}
.btn-decline:hover {
    background: #dc2626;
}
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #8b8fa3;
}
.empty-state-icon {
    font-size: 4em;
    margin-bottom: 16px;
    opacity: 0.5;
}
.pagination {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 30px;
    padding-bottom: 30px;
}
.pagination a,
.pagination span {
    padding: 8px 14px;
    border-radius: 8px;
    text-decoration: none;
    color: #3b5dd3;
    background: white;
    border: 1px solid #eef1f8;
    transition: all 0.2s;
}
.pagination a:hover {
    background: #f0f2f5;
}
.pagination .current {
    background: #3b5dd3;
    color: white;
    border-color: #3b5dd3;
}
.mark-all-read {
    padding: 8px 16px;
    background: none;
    border: 1px solid #3b5dd3;
    color: #3b5dd3;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.9em;
    font-weight: 500;
    transition: all 0.2s;
}
.mark-all-read:hover {
    background: #3b5dd3;
    color: white;
}

.notifications-page {
    max-width: 700px;
    margin: 20px auto;
    padding: 0 16px;
    text-align: left; /* ← добавлено */
}

.notifications-header {
    display: flex;
    justify-content: flex-start; /* ← изменено с space-between */
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid #eef1f8;
}

.notifications-title {
    font-size: 1.8em;
    font-weight: 600;
    color: #1e1e2f;
    margin: 0;
    text-align: left; /* ← добавлено */
}

.mark-all-read {
    margin-left: auto; /* ← добавлено, чтобы кнопка была справа */
}
</style>
</head>
<body>
<?php require_once "components/header.php"; ?>
<div class="mainArea">
    <div class="notifications-page">
        <div class="notifications-header">
            <h1 class="notifications-title">Уведомления</h1>
            <?php if ($unreadCount > 0): ?>
            <button class="mark-all-read" onclick="markAllAsRead()">
                Отметить все как прочитанные
            </button>
            <?php endif; ?>
        </div>
        
        <div class="notifications-tabs">
            <a href="?filter=all" class="notification-tab <?= $filter === 'all' ? 'active' : '' ?>">
                Все
            </a>
            <a href="?filter=unread" class="notification-tab <?= $filter === 'unread' ? 'active' : '' ?>">
                Непрочитанные
                <?php if ($unreadCount > 0): ?>
                <span class="badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                <?php endif; ?>
            </a>
        </div>
        
        <div class="notification-list">
            <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🔔</div>
                <p>Уведомлений пока нет</p>
            </div>
            <?php else: ?>
            <?php foreach ($notifications as $notif): ?>
            <?php
            // Определяем иконку и текст
            $icon = '🔔';
            $text = '';
            $actorName = esc($notif['actor_name'] ?? 'Система');
            $actorId = $notif['actor_id'];
            $profileUrl = $actorId ? "user.php?id=$actorId" : '#';
            
            switch ($notif['type']) {
                case 'like':
                    $icon = '❤️';
                    $text = "<a href=\"$profileUrl\">$actorName</a> оценил(а) ваш пост";
                    break;
                case 'friend_request':
                    $icon = '👋';
                    $text = "<a href=\"$profileUrl\">$actorName</a> хочет добавиться в друзья";
                    break;
                case 'friend_accept':
                    $icon = '✅';
                    $text = "<a href=\"$profileUrl\">$actorName</a> принял(а) вашу заявку в друзья";
                    break;
                case 'comment':
                    $icon = '💬';
                    $text = "<a href=\"$profileUrl\">$actorName</a> оставил(а) комментарий";
                    break;
                case 'mention':
                    $icon = '📢';
                    $text = "<a href=\"$profileUrl\">$actorName</a> упомянул(а) вас";
                    break;
                default:
                    $text = "Новое уведомление";
            }
            
            $timeString = $notif['created_at'];
            $dateObj = new DateTime($timeString);
            $formattedTime = $dateObj->format('d.m.Y H:i');
            
            $unreadClass = $notif['is_read'] ? '' : 'unread';
            ?>
            <div class="notification-item <?= $unreadClass ?>" data-id="<?= $notif['id'] ?>">
                <div class="notification-icon"><?= $icon ?></div>
                <div class="notification-content">
                    <div class="notification-text"><?= $text ?></div>
                    <div class="notification-time"><?= $formattedTime ?></div>
                    
                    <?php if ($notif['type'] === 'friend_request' && !$notif['is_read']): ?>
                    <div class="notification-actions">
                        <button class="btn-accept" onclick="acceptFriendRequest(<?= $actorId ?>, <?= $notif['id'] ?>)">
                            Принять
                        </button>
                        <button class="btn-decline" onclick="declineFriendRequest(<?= $actorId ?>, <?= $notif['id'] ?>)">
                            Отклонить
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php if ($lastPage > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?filter=<?= $filter ?>&page=<?= $page - 1 ?>">← Назад</a>
            <?php endif; ?>
            
            <?php for ($i = 1; $i <= $lastPage; $i++): ?>
            <?php if ($i == 1 || $i == $lastPage || abs($i - $page) <= 2): ?>
            <a href="?filter=<?= $filter ?>&page=<?= $i ?>" class="<?= $i == $page ? 'current' : '' ?>">
                <?= $i ?>
            </a>
            <?php elseif (abs($i - $page) == 3): ?>
            <span>...</span>
            <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($page < $lastPage): ?>
            <a href="?filter=<?= $filter ?>&page=<?= $page + 1 ?>">Вперёд →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
window.csrfToken = document.querySelector('input[name="_csrf"]')?.value;

async function acceptFriendRequest(actorId, notifId) {
    try {
        const response = await fetch('/api/friends/accept', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken
            },
            body: JSON.stringify({ requester_id: actorId })
        });
        
        if (response.ok) {
            // Помечаем уведомление как прочитанное
            await markAsRead(notifId);
            // Удаляем элемент из DOM
            const item = document.querySelector(`.notification-item[data-id="${notifId}"]`);
            if (item) {
                item.style.transition = 'opacity 0.3s, transform 0.3s';
                item.style.opacity = '0';
                item.style.transform = 'translateX(-20px)';
                setTimeout(() => item.remove(), 300);
            }
            kop.flash('Заявка принята');
        } else {
            kop.flash('Ошибка при принятии заявки');
        }
    } catch(e) {
        kop.flash('Ошибка соединения');
    }
}

async function declineFriendRequest(actorId, notifId) {
    try {
        const response = await fetch('/api/friends/decline', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken
            },
            body: JSON.stringify({ friend_id: actorId })
        });
        
        if (response.ok) {
            await markAsRead(notifId);
            const item = document.querySelector(`.notification-item[data-id="${notifId}"]`);
            if (item) {
                item.style.transition = 'opacity 0.3s, transform 0.3s';
                item.style.opacity = '0';
                item.style.transform = 'translateX(-20px)';
                setTimeout(() => item.remove(), 300);
            }
            kop.flash('Заявка отклонена');
        } else {
            kop.flash('Ошибка при отклонении заявки');
        }
    } catch(e) {
        kop.flash('Ошибка соединения');
    }
}

async function markAsRead(notifId) {
    try {
        await fetch('/api/notifications/read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken
            },
            body: JSON.stringify({ ids: [notifId] })
        });
    } catch(e) {}
}

async function markAllAsRead() {
    try {
        const response = await fetch('/api/notifications/read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken
            },
            body: JSON.stringify({ all: true })
        });
        
        if (response.ok) {
            // Перезагружаем страницу
            window.location.reload();
        } else {
            kop.flash('Ошибка при отметке всех уведомлений');
        }
    } catch(e) {
        kop.flash('Ошибка соединения');
    }
}

// Отмечаем уведомление как прочитанное при клике
document.querySelectorAll('.notification-item').forEach(item => {
    item.addEventListener('click', function(e) {
        // Если клик не по кнопкам действий
        if (!e.target.closest('.notification-actions')) {
            const notifId = this.dataset.id;
            if (this.classList.contains('unread')) {
                markAsRead(notifId);
                this.classList.remove('unread');
            }
        }
    });
});
</script>

<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
</body>
</html>