<?php
require_once __DIR__ . '/../kopilot/kopilot_init.php';
?>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Google+Sans+Flex:opsz,wght@6..144,600&display=swap');
    @import url('https://fonts.googleapis.com/css2?family=Google+Sans+Flex:opsz@6..144&family=Roboto:ital,wght@0,100..900;1,100..900&display=swap');
</style>

<?= csrf_field() ?>
<div class="sidebar">
    <script src="/kopilot/js/kopilot.js"></script>
    <script>kop.liveReload();</script>   <!-- ← вот эта строка была потеряна -->
    <div class="header">
        <a href="feed.php" class="mainLogo">Friendscape</a>
    </div>
    <div class="Menu">
        <a href="profile.php">
            <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                </svg>
            </span>
            Профиль
        </a>

        <!-- УВЕДОМЛЕНИЯ -->
        <a href="#" id="notifications-menu-link" style="position:relative;" onclick="event.preventDefault(); toggleNotificationsDropdown();">
            <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
            </span>
            Уведомления
            <span id="notifications-badge" style="position:absolute; top:2px; right:8px; background:#b91c1c; color:#fff; border-radius:50%; width:18px; height:18px; font-size:0.65em; display:none; align-items:center; justify-content:center;">0</span>
        </a>

        <a href="messenger.php">
            <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                </svg>
            </span>
            Чаты
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
        <a href="">
            <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
                </svg>
            </span>
            Видео
        </a>
        <a href="settings.php">
            <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
            </span>
            Настройки
        </a>

        <?php if (($currentUser['role'] ?? '') === 'admin' || scalar("SELECT COUNT(*) FROM admins WHERE user_id = ?", [$_SESSION['user_id']]) > 0): ?>
        <a href="management.php">
            <span class="Menu__icon" style="background: #fef9c3; color: #b45309;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
            </span>
            Управление
        </a>
        <?php endif; ?>

        <a href="logout.php">
            <span class="Menu__icon" style="background: #f0f0f0; color: #b91c1c;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
            </span>
            Выход
        </a>
    </div>

    <!-- Выпадающий список уведомлений -->
    <div id="notifications-dropdown" style="position:absolute; top:auto; left:260px; width:340px; background:#fff; border-radius:12px; box-shadow:0 8px 24px rgba(0,0,0,0.12); display:none; z-index:100; max-height:400px; overflow-y:auto; padding:10px;"></div>

    <!-- Toast-контейнер для всех уведомлений -->
    <div id="global-toast" class="global-toast"></div>
</div>

<script>
window.csrfToken = document.querySelector('input[name="_csrf"]')?.value;

(function() {
    const menuLink = document.getElementById('notifications-menu-link');
    const dropdown = document.getElementById('notifications-dropdown');
    const badge = document.getElementById('notifications-badge');
    let unreadCount = 0;

    async function loadNotifications() {
        try {
            const res = await fetch('/api/notifications?unread=1', {
                headers: { 'X-CSRF-Token': window.csrfToken || '', 'Accept': 'application/json' }
            });
            if (!res.ok) return;
            const data = await res.json();
            unreadCount = data.unread_count || 0;
            badge.textContent = unreadCount;
            badge.style.display = unreadCount > 0 ? 'flex' : 'none';
        } catch(e) {}
    }

    function buildNotificationHTML(n) {
        const name = n.actor_name || 'Пользователь';
        const profileUrl = `user.php?id=${n.actor_id}`;
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
            default:
                text = `проявил активность`;
        }

        return `<div class="notif-item" data-id="${n.id}" style="padding:10px 10px; border-bottom:1px solid #f0f2f5; display:flex; align-items:flex-start; gap:10px;">
            <span style="font-size:1.2em;">${icon}</span>
            <div style="flex:1;">
                <div style="font-size:0.9em;">
                    <a href="${profileUrl}" style="color:#3b5dd3; text-decoration:none; font-weight:500;">${name}</a> ${text}
                </div>
                ${actionsHtml}
                <div style="font-size:0.75em; color:#8b8fa3; margin-top:4px;">${new Date(n.created_at).toLocaleString()}</div>
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
                            if (!resp.ok) { const err = await resp.json(); console.error('Ошибка при принятии заявки:', err); kop.flash('Ошибка при принятии заявки'); return; }
                            kop.flash('Заявка принята');
                            if (notifItem) notifItem.remove();
                            loadNotifications();
                        } catch (e) { console.error(e); kop.flash('Ошибка'); }
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
                            if (!resp.ok) { const err = await resp.json(); console.error('Ошибка при отклонении заявки:', err); kop.flash('Ошибка при отклонении заявки'); return; }
                            kop.flash('Заявка отклонена');
                            if (notifItem) notifItem.remove();
                            loadNotifications();
                        } catch (e) { console.error(e); kop.flash('Ошибка'); }
                    });
                });
            }
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
                    badge.textContent = 0;
                    badge.style.display = 'none';
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

    loadNotifications();
    setInterval(loadNotifications, 30000);
})();
</script>