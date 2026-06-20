<?php
// header.php — упрощённая версия для гостей, полное меню для авторизованных
require_once __DIR__ . '/../kopilot/kopilot_init.php';

$isLoggedIn = is_logged_in();
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Google+Sans+Flex:opsz,wght@6..144,600&display=swap');
@import url('https://fonts.googleapis.com/css2?family=Google+Sans+Flex:opsz@6..144&family=Roboto:ital,wght@0,100..900;1,100..900&display=swap');
</style>
<?= csrf_field() ?>
<div class="sidebar">
<script src="/kopilot/js/kopilot.js"></script>
<?php if ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1'): ?>
<script>kop.liveReload();</script>
<?php endif; ?>
<div class="header">
<a href="feed.php" class="mainLogo">Friendscape</a>
</div>
<div class="Menu">
<?php if ($isLoggedIn): ?>
    <!-- Полное меню для авторизованных -->
    <a href="profile.php">
        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
            </svg>
        </span>
        Профиль
    </a>
    <a href="#" id="notifications-menu-link" style="position:relative;" onclick="event.preventDefault(); toggleNotificationsDropdown();">
        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
        </span>
        Уведомления
        <span class="notif-badge" id="notifications-badge" style="position:absolute; top:2px; right:8px; background:#b91c1c; color:#fff; border-radius:50%; width:18px; height:18px; font-size:0.65em; display:none; align-items:center; justify-content:center;">0</span>
    </a>
    <a href="messenger.php" style="position:relative;">
        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
            </svg>
        </span>
        Чаты
        <span class="chats-badge" id="chats-badge" style="position:absolute; top:2px; right:8px; background:#b91c1c; color:#fff; border-radius:50%; width:18px; height:18px; font-size:0.65em; display:none; align-items:center; justify-content:center;">0</span>
    </a>
    <a href="search.php">
        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
        </span>
        Люди
    </a>
    <a href="feed.php">
        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="7" width="20" height="15" rx="2" ry="2"/><path d="M17 2l-5 5-5-5"/>
            </svg>
        </span>
        Лента
    </a>
    <a href="settings.php">
        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
            </svg>
        </span>
        Настройки
    </a>
    <a href="logout.php">
        <span class="Menu__icon" style="background: #f0f0f0; color: #b91c1c;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
        </span>
        Выйти
    </a>
<?php else: ?>
    <!-- Гостевое меню: только Лента и Войти -->
    <a href="feed.php">
        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="7" width="20" height="15" rx="2" ry="2"/><path d="M17 2l-5 5-5-5"/>
            </svg>
        </span>
        Лента
    </a>
    <a href="login.php">
        <span class="Menu__icon" style="background: #f0f0f0; color: #10b981;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                <polyline points="10 17 15 12 10 7"/>
                <line x1="15" y1="12" x2="3" y2="12"/>
            </svg>
        </span>
        Войти
    </a>
<?php endif; ?>
</div>
<!-- Dropdown для уведомлений (только для авторизованных) -->
<?php if ($isLoggedIn): ?>
<div id="notifications-dropdown" style="position:absolute; top:0; left:260px; width:340px; background:#fff; border-radius:12px; box-shadow:0 8px 24px rgba(0,0,0,0.12); display:none; z-index:10000; max-height:400px; overflow-y:auto; padding:10px;"></div>
<?php endif; ?>
<div id="global-toast" class="global-toast"></div>
</div>

<!-- Мобильное меню -->
<nav class="mobile-nav mobile-only">
<?php if ($isLoggedIn): ?>
    <a href="profile.php">
        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
            </svg>
        </span>
        <span class="mobile-nav-label">Профиль</span>
    </a>
    <a href="notifications.php" style="position: relative;">
        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
        </span>
        <span class="mobile-nav-label">Уведомления</span>
        <span class="notif-badge" id="mobile-notifications-badge" style="position:absolute; top:2px; right:8px; background:#b91c1c; color:#fff; border-radius:50%; width:18px; height:18px; font-size:0.65em; display:none; align-items:center; justify-content:center;">0</span>
    </a>
    <a href="messenger.php" style="position: relative;">
        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
            </svg>
        </span>
        <span class="mobile-nav-label">Чаты</span>
        <span class="chats-badge" id="mobile-chats-badge" style="position:absolute; top:2px; right:8px; background:#b91c1c; color:#fff; border-radius:50%; width:18px; height:18px; font-size:0.65em; display:none; align-items:center; justify-content:center;">0</span>
    </a>
    <a href="search.php">
        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
        </span>
        <span class="mobile-nav-label">Люди</span>
    </a>
    <a href="feed.php">
        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="7" width="20" height="15" rx="2" ry="2"/><path d="M17 2l-5 5-5-5"/>
            </svg>
        </span>
        <span class="mobile-nav-label">Лента</span>
    </a>
    <a href="settings.php">
        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
            </svg>
        </span>
        <span class="mobile-nav-label">Настройки</span>
    </a>
<?php else: ?>
    <a href="feed.php">
        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="7" width="20" height="15" rx="2" ry="2"/><path d="M17 2l-5 5-5-5"/>
            </svg>
        </span>
        <span class="mobile-nav-label">Лента</span>
    </a>
    <a href="login.php">
        <span class="Menu__icon" style="background: #f0f0f0; color: #10b981;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                <polyline points="10 17 15 12 10 7"/>
                <line x1="15" y1="12" x2="3" y2="12"/>
            </svg>
        </span>
        <span class="mobile-nav-label">Войти</span>
    </a>
<?php endif; ?>
</nav>

<script>
window.csrfToken = document.querySelector('input[name="_csrf"]')?.value;

// Глобальный перехват 401 для гостей
const originalFetch = window.fetch;
window.fetch = function(...args) {
    return originalFetch.apply(this, args).then(response => {
        if (response.status === 401) {
            response.clone().json().then(data => {
                window.location.href = data.redirect || '/login.php';
            }).catch(() => {
                window.location.href = '/login.php';
            });
        }
        return response;
    });
};

// Базовая функция обновления бейджа
window.updateBadge = function(badgeEl, count) {
    if (!badgeEl) return;
    if (count > 0) {
        badgeEl.textContent = count > 99 ? '99+' : count;
        badgeEl.style.display = 'flex';
    } else {
        badgeEl.style.display = 'none';
    }
};

window.currentNotifUnread = 0;
window.currentChatsUnread = 0;

<?php if ($isLoggedIn): ?>
(function() {
const menuLink = document.getElementById('notifications-menu-link');
const dropdown = document.getElementById('notifications-dropdown');
let unreadCount = 0;

async function loadNotifications() {
    try {
        const res = await fetch('/api/notifications?unread=1', {
            headers: { 'X-CSRF-Token': window.csrfToken || '', 'Accept': 'application/json' }
        });
        if (!res.ok) return;
        const data = await res.json();
        unreadCount = data.unread_count || 0;
        window.currentNotifUnread = unreadCount;
        // Обновляем все бейджи уведомлений
        document.querySelectorAll('.notif-badge').forEach(badge => {
            window.updateBadge(badge, unreadCount);
        });
    } catch(e) {}
}

async function loadChatsBadge() {
    try {
        const res = await fetch('/api/chats', {
            headers: { 'X-CSRF-Token': window.csrfToken || '', 'Accept': 'application/json' }
        });
        if (!res.ok) return;
        const data = await res.json();
        const chats = data.chats || [];
        const totalUnread = chats.reduce((sum, c) => sum + (parseInt(c.unread_count) || 0), 0);
        window.currentChatsUnread = totalUnread;
        // Обновляем все бейджи чатов
        document.querySelectorAll('.chats-badge').forEach(badge => {
            window.updateBadge(badge, totalUnread);
        });
    } catch(e) {}
}

function buildNotificationHTML(n) {
const name = n.actor_name || 'Система';
const profileUrl = n.actor_id ? `user.php?id=${n.actor_id}` : '#';
let text = '';
let icon = '👍';
let actionsHtml = '';
switch (n.type) {
case 'like':
text = `оценил(а) ваш пост`;
icon = '❤️';
break;
case 'friend_request':
text = `хочет добавиться в друзья`;
icon = '👋';
if (!n.is_read) {
actionsHtml = `<div style="display:flex; gap:6px; margin-top:6px;">
<button class="notif-accept-btn" data-actor="${n.actor_id}" data-notif-id="${n.id}" style="background:#10b981; color:#fff; border:none; padding:4px 10px; border-radius:6px; cursor:pointer; font-size:0.8em;">Принять</button>
<button class="notif-decline-btn" data-actor="${n.actor_id}" data-notif-id="${n.id}" style="background:#ef4444; color:#fff; border:none; padding:4px 10px; border-radius:6px; cursor:pointer; font-size:0.8em;">Отклонить</button>
</div>`;
}
break;
case 'friend_accept':
text = `принял(а) вашу заявку в друзья`;
icon = '✅';
break;
case 'recovery_request_initial':
let initRequestData = {};
try { initRequestData = JSON.parse(n.extra || '{}'); } catch(e) {}
const requestStatus = initRequestData.status;
text = `Кто-то пытается восстановить доступ к вашему аккаунту. Это были вы?`;
icon = '🔑';
if (initRequestData.request_id && initRequestData.token) {
if (requestStatus === 'pending' || requestStatus === 'awaiting_owner_after_friend') {
actionsHtml = `<div class="recovery-actions" data-notif-id="${n.id}">
<button class="notif-recovery-initial-approve-btn" data-request-id="${initRequestData.request_id}" data-token="${initRequestData.token}" data-notif-id="${n.id}" style="background:#10b981; color:#fff; border:none; padding:4px 10px; border-radius:6px; cursor:pointer; font-size:0.8em; margin-right:4px;">Да, это я</button>
<button class="notif-recovery-initial-reject-btn" data-request-id="${initRequestData.request_id}" data-token="${initRequestData.token}" data-notif-id="${n.id}" style="background:#ef4444; color:#fff; border:none; padding:4px 10px; border-radius:6px; cursor:pointer; font-size:0.8em;">Нет, заблокировать</button>
</div>`;
} else {
let statusMessage = 'Статус неизвестен';
if (requestStatus === 'completed') statusMessage = 'Восстановление завершено.';
else if (requestStatus === 'rejected') statusMessage = 'Заявка отклонена.';
else if (requestStatus === 'cancelled_by_owner') statusMessage = 'Заявка отменена владельцем.';
else if (requestStatus === 'cancelled_by_friend') statusMessage = 'Заявка отменена другом.';
else statusMessage = `Заявка в статусе: ${requestStatus}`;
actionsHtml = `<div style="font-size:0.8em; color:#8b8fa3; padding-top:4px;">${statusMessage}</div>`;
}
}
break;
case 'recovery_alert':
let alertData = {};
try { alertData = JSON.parse(n.extra || '{}'); } catch(e) {}
if (alertData.owner_name && alertData.request_id && alertData.token) {
icon = '⚠️';
const requestStatus = alertData.status;
if (alertData.can_cancel) {
text = `Вы подтвердили восстановление доступа для ${alertData.owner_name}. Если это ошибка, отмените.`;
if (requestStatus === 'awaiting_owner_after_friend') {
actionsHtml = `<div class="recovery-actions" data-notif-id="${n.id}">
<button class="notif-recovery-friend-cancel-btn" data-request-id="${alertData.request_id}" data-notif-id="${n.id}" style="background:#f59e0b; color:#fff; border:none; padding:4px 10px; border-radius:6px; cursor:pointer; font-size:0.8em;">Отменить подтверждение</button>
</div>`;
} else {
let statusMessage = 'Статус неизвестен';
if (requestStatus === 'completed') statusMessage = 'Восстановление завершено.';
else if (requestStatus === 'rejected') statusMessage = 'Заявка отклонена.';
else if (requestStatus === 'cancelled_by_owner') statusMessage = 'Заявка отменена владельцем.';
else if (requestStatus === 'cancelled_by_friend') statusMessage = 'Заявка отменена другом.';
else statusMessage = `Заявка в статусе: ${requestStatus}`;
actionsHtml = `<div style="font-size:0.8em; color:#8b8fa3; padding-top:4px;">${statusMessage}</div>`;
}
} else {
text = `${alertData.owner_name} пытается восстановить доступ и указал вас как частого собеседника. Подтвердите, что это он.`;
if (requestStatus === 'pending') {
actionsHtml = `<div class="recovery-actions" data-notif-id="${n.id}">
<button class="notif-recovery-friend-approve-btn" data-request-id="${alertData.request_id}" data-token="${alertData.token}" data-notif-id="${n.id}" style="background:#10b981; color:#fff; border:none; padding:4px 10px; border-radius:6px; cursor:pointer; font-size:0.8em; margin-right:4px;">Да, это он</button>
<button class="notif-recovery-friend-reject-btn" data-request-id="${alertData.request_id}" data-token="${alertData.token}" data-notif-id="${n.id}" style="background:#ef4444; color:#fff; border:none; padding:4px 10px; border-radius:6px; cursor:pointer; font-size:0.8em;">Нет, заблокировать</button>
</div>`;
} else {
let statusMessage = 'Статус неизвестен';
if (requestStatus === 'completed') statusMessage = 'Восстановление завершено.';
else if (requestStatus === 'rejected') statusMessage = 'Заявка отклонена.';
else if (requestStatus === 'cancelled_by_owner') statusMessage = 'Заявка отменена владельцем.';
else if (requestStatus === 'cancelled_by_friend') statusMessage = 'Заявка отменена другом.';
else if (requestStatus === 'awaiting_owner_after_friend') statusMessage = 'Ожидается подтверждение владельца.';
else statusMessage = `Заявка в статусе: ${requestStatus}`;
actionsHtml = `<div style="font-size:0.8em; color:#8b8fa3; padding-top:4px;">${statusMessage}</div>`;
}
}
}
break;
case 'passport_issue':
text = `Ваш Friendscape Паспорт был успешно создан.`;
icon = '📋';
break;
case 'passport_update':
text = `Ваш Friendscape Паспорт был обновлён.`;
icon = '🔄';
break;
default:
text = `проявил активность`;
}
const nameHtml = n.actor_id ? `<a href="${profileUrl}" style="color:#3b5dd3; text-decoration:none; font-weight:500;">${name}</a>` : `<span style="font-weight:500;">${name}</span>`;
const timeString = n.created_at.endsWith('Z') ? n.created_at : n.created_at + 'Z';
const dateObj = new Date(timeString);
const formattedTime = dateObj.toLocaleString();
return `<div class="notif-item" data-id="${n.id}" style="padding:10px 10px; border-bottom:1px solid #f0f2f5; display:flex; align-items:flex-start; gap:10px;">
<span style="font-size:1.2em;">${icon}</span>
<div style="flex:1;">
<div style="font-size:0.9em;">
${nameHtml} ${text}
</div>
${actionsHtml}
<div style="font-size:0.75em; color:#8b8fa3; margin-top:4px;">${formattedTime}</div>
</div>
</div>`;
}
async function openDropdown() {
try {
const res = await fetch('/api/notifications?page=1', {
headers: { 'X-CSRF-Token': window.csrfToken || '', 'Accept': 'application/json' }
});
const data = await res.json();
const items = data.notifications || [];
if (items.length === 0) {
dropdown.innerHTML = '<p style="padding:10px;color:#8b8fa3;">Нет уведомлений</p>';
} else {
const unreadItems = items.filter(n => !n.is_read);
const readItems = items.filter(n => n.is_read);
let html = '';
if (unreadItems.length > 0) {
html += '<div style="padding:8px 10px; font-weight:600; color:#111; font-size:0.85em;">Новые</div>';
html += unreadItems.map(buildNotificationHTML).join('');
}
if (readItems.length > 0) {
html += '<div style="padding:8px 10px; font-weight:600; color:#888; font-size:0.85em; margin-top:8px;">Прочитанные</div>';
html += readItems.map(buildNotificationHTML).join('');
}
dropdown.innerHTML = html;
function disableAndHideNotificationActions(notifId) {
const actionsDiv = dropdown.querySelector(`.recovery-actions[data-notif-id="${notifId}"]`);
if (actionsDiv) {
const buttons = actionsDiv.querySelectorAll('button');
buttons.forEach(btn => {
const newBtn = btn.cloneNode(true);
btn.parentNode.replaceChild(newBtn, btn);
newBtn.style.background = '#ccc';
newBtn.style.cursor = 'not-allowed';
newBtn.style.opacity = '0.5';
newBtn.disabled = true;
});
actionsDiv.innerHTML = '<div style="font-size:0.8em; color:#8b8fa3; padding-top:4px;">Обработка...</div>';
}
}
dropdown.querySelectorAll('.notif-accept-btn').forEach(btn => {
btn.addEventListener('click', async (e) => {
e.stopPropagation();
const actorId = btn.dataset.actor;
const notifId = parseInt(btn.dataset.notifId);
const notifItem = dropdown.querySelector(`.notif-item[data-id="${notifId}"]`);
try {
const resp = await fetch('/api/friends/accept', {
method: 'POST',
headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
body: JSON.stringify({ requester_id: actorId })
});
if (!resp.ok) { kop.flash('Ошибка при принятии заявки'); return; }
kop.flash('Заявка принята');
if (notifItem) notifItem.remove();
loadNotifications();
} catch (e) { kop.flash('Ошибка'); }
});
});
dropdown.querySelectorAll('.notif-decline-btn').forEach(btn => {
btn.addEventListener('click', async (e) => {
e.stopPropagation();
const actorId = btn.dataset.actor;
const notifId = parseInt(btn.dataset.notifId);
const notifItem = dropdown.querySelector(`.notif-item[data-id="${notifId}"]`);
try {
const resp = await fetch('/api/friends/decline', {
method: 'POST',
headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
body: JSON.stringify({ friend_id: actorId })
});
if (!resp.ok) { kop.flash('Ошибка при отклонении заявки'); return; }
kop.flash('Заявка отклонена');
if (notifItem) notifItem.remove();
loadNotifications();
} catch (e) { kop.flash('Ошибка'); }
});
});
dropdown.querySelectorAll('.notif-recovery-initial-approve-btn').forEach(btn => {
btn.addEventListener('click', async (e) => {
e.stopPropagation();
const requestId = btn.dataset.requestId;
const token = btn.dataset.token;
const notifId = parseInt(btn.dataset.notifId);
disableAndHideNotificationActions(notifId);
try {
const resp = await fetch('/api/recovery/approve', {
method: 'POST',
headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
body: JSON.stringify({ request_id: requestId, token: token })
});
const data = await resp.json();
if (resp.ok) {
kop.flash('Доступ восстановлен.');
loadNotifications();
} else {
loadNotifications();
kop.flash(data.error || 'Ошибка');
}
} catch (e) { loadNotifications(); kop.flash('Ошибка соединения'); }
});
});
dropdown.querySelectorAll('.notif-recovery-initial-reject-btn').forEach(btn => {
btn.addEventListener('click', async (e) => {
e.stopPropagation();
const requestId = btn.dataset.requestId;
const token = btn.dataset.token;
const notifId = parseInt(btn.dataset.notifId);
disableAndHideNotificationActions(notifId);
try {
const resp = await fetch('/api/recovery/reject', {
method: 'POST',
headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
body: JSON.stringify({ request_id: requestId, token: token })
});
const data = await resp.json();
if (resp.ok) {
kop.flash('Запрос отклонён.');
loadNotifications();
} else {
loadNotifications();
kop.flash(data.error || 'Ошибка');
}
} catch (e) { loadNotifications(); kop.flash('Ошибка соединения'); }
});
});
dropdown.querySelectorAll('.notif-recovery-friend-approve-btn').forEach(btn => {
btn.addEventListener('click', async (e) => {
e.stopPropagation();
const requestId = btn.dataset.requestId;
const token = btn.dataset.token;
const notifId = parseInt(btn.dataset.notifId);
disableAndHideNotificationActions(notifId);
try {
const resp = await fetch('/api/recovery/friend-approve', {
method: 'POST',
headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
body: JSON.stringify({ request_id: requestId, token: token })
});
const data = await resp.json();
if (resp.ok) {
kop.flash('Подтверждение принято.');
loadNotifications();
} else {
loadNotifications();
kop.flash(data.error || 'Ошибка');
}
} catch (e) { loadNotifications(); kop.flash('Ошибка соединения'); }
});
});
dropdown.querySelectorAll('.notif-recovery-friend-reject-btn').forEach(btn => {
btn.addEventListener('click', async (e) => {
e.stopPropagation();
const requestId = btn.dataset.requestId;
const token = btn.dataset.token;
const notifId = parseInt(btn.dataset.notifId);
disableAndHideNotificationActions(notifId);
try {
const resp = await fetch('/api/recovery/friend-reject', {
method: 'POST',
headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
body: JSON.stringify({ request_id: requestId, token: token })
});
const data = await resp.json();
if (resp.ok) {
kop.flash('Запрос отклонён.');
loadNotifications();
} else {
loadNotifications();
kop.flash(data.error || 'Ошибка');
}
} catch (e) { loadNotifications(); kop.flash('Ошибка соединения'); }
});
});
dropdown.querySelectorAll('.notif-recovery-friend-cancel-btn').forEach(btn => {
btn.addEventListener('click', async (e) => {
e.stopPropagation();
const requestId = btn.dataset.requestId;
const notifId = parseInt(btn.dataset.notifId);
disableAndHideNotificationActions(notifId);
try {
const resp = await fetch('/api/recovery/friend-cancel', {
method: 'POST',
headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
body: JSON.stringify({ request_id: requestId })
});
const data = await resp.json();
if (resp.ok) {
kop.flash('Подтверждение отменено.');
loadNotifications();
} else {
loadNotifications();
kop.flash(data.error || 'Ошибка');
}
} catch (e) { loadNotifications(); kop.flash('Ошибка соединения'); }
});
});
}
// Позиционирование dropdown — используем getBoundingClientRect + scrollY
const rect = menuLink.getBoundingClientRect();
dropdown.style.top = (rect.top + window.scrollY) + 'px';
dropdown.style.left = (rect.right + 10) + 'px';
dropdown.style.display = 'block';
const unreadIds = items.filter(n => !n.is_read).map(n => n.id);
if (unreadIds.length > 0) {
fetch('/api/notifications/read', {
method: 'POST',
headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken || '' },
body: JSON.stringify({ ids: unreadIds })
}).then(() => {
document.querySelectorAll('.notif-badge').forEach(badge => {
window.updateBadge(badge, 0);
});
window.currentNotifUnread = 0;
});
}
} catch(e) {}
}
window.toggleNotificationsDropdown = function() {
if (dropdown.style.display === 'block') {
dropdown.style.display = 'none';
} else {
openDropdown();
}
};
document.addEventListener('click', (e) => {
if (!menuLink.contains(e.target) && !dropdown.contains(e.target)) {
dropdown.style.display = 'none';
}
});

// Первоначальная загрузка
loadNotifications();
loadChatsBadge();

// Периодическое обновление
setInterval(() => {
    loadNotifications();
    loadChatsBadge();
}, 30000);

// Функция для принудительного обновления (вызывается из messenger-spa.js)
window.updateHeaderUnread = function(options) {
    loadNotifications();
    loadChatsBadge();
};
})();
<?php endif; ?>

// Глобальная функция форматирования счётчиков
window.formatCount = function(count) {
    count = parseInt(count) || 0;
    if (count < 1000) {
        return count.toString();
    } else if (count < 1000000) {
        const thousands = count / 1000;
        if (thousands === Math.floor(thousands)) {
            return thousands + 'К';
        } else {
            return thousands.toFixed(1) + 'К';
        }
    } else {
        const millions = count / 1000000;
        if (millions === Math.floor(millions)) {
            return millions + 'М';
        } else {
            return millions.toFixed(1) + 'М';
        }
    }
};
</script>