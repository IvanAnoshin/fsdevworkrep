<?php
// management.php – Административная панель Friendscape (без Chart.js)
declare(strict_types=1);

require_once __DIR__ . '/kopilot/kopilot_init.php';
require_auth();

$currentUser = find('users', $_SESSION['user_id']);
$isAdmin = scalar("SELECT COUNT(*) FROM admins WHERE user_id = ?", [$_SESSION['user_id']]) > 0;

if (!$isAdmin && ($currentUser['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Доступ запрещён');
}

// flash-сообщения
if (!isset($_SESSION['admin_flash'])) {
    $_SESSION['admin_flash'] = [];
}
function admin_flash(string $type, string $message): void {
    $_SESSION['admin_flash'][] = ['type' => $type, 'message' => $message];
}
function get_flash_messages(): array {
    $messages = $_SESSION['admin_flash'] ?? [];
    $_SESSION['admin_flash'] = [];
    return $messages;
}

// Маршрутизация разделов
$section = $_GET['section'] ?? 'dashboard';
$allowedSections = ['dashboard', 'users', 'content', 'messages', 'reports', 'logs', 'system'];
if (!in_array($section, $allowedSections)) {
    $section = 'dashboard';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель управления — Friendscape</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .admin-wrapper { display: flex; min-height: 100vh; }
        .admin-sidebar { width: 250px; background: #1e1e2f; color: #fff; padding: 20px; }
        .admin-sidebar h3 { margin-top: 0; color: #fff; }
        .admin-sidebar a { color: #c0c0d0; text-decoration: none; display: block; padding: 8px 0; }
        .admin-sidebar a:hover { color: #fff; }
        .admin-main { flex: 1; padding: 24px; background: #f5f7fb; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; }
        .stat-card { background: #fff; padding: 20px; border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .stat-value { font-size: 2.2em; font-weight: 600; color: #3b5dd3; }
        .stat-label { font-size: 0.9em; color: #6c757d; margin-top: 8px; }
        .flash-message { padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        .flash-success { background: #d1fae5; color: #059669; }
        .flash-error { background: #fee2e2; color: #b91c1c; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; }
        th { background: #f0f2f5; padding: 10px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #f0f2f5; }
        .btn { padding: 8px 16px; border-radius: 8px; border: none; cursor: pointer; font-size: 0.9em; }
        .btn-primary { background: #3b5dd3; color: #fff; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-success { background: #10b981; color: #fff; }
        .btn-secondary { background: #e5e7eb; color: #111; }
        .pagination { display: flex; gap: 8px; margin-top: 20px; justify-content: center; }
        .pagination button { min-width: 36px; }
        /* Простые графики */
        .chart-bar-wrap { display: flex; align-items: center; margin-bottom: 4px; }
        .chart-label { width: 40px; font-size: 0.8em; color: #555; }
        .chart-bar { height: 20px; background: #3b5dd3; border-radius: 4px; min-width: 2px; transition: width 0.3s; }
        .chart-value { margin-left: 6px; font-size: 0.8em; color: #111; }
        /* Модалка подтверждения */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000;
        }
        .modal-overlay.active { display: flex; }
        .modal-container {
            background: #fff; border-radius: 20px; max-width: 600px; width: 90%;
            max-height: 80vh; overflow-y: auto; padding: 24px; position: relative;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <nav class="admin-sidebar">
        <h3>Управление</h3>
        <a href="?section=dashboard" class="<?= $section === 'dashboard' ? 'active' : '' ?>">📊 Дашборд</a>
        <a href="?section=users" class="<?= $section === 'users' ? 'active' : '' ?>">👥 Пользователи</a>
        <a href="?section=content" class="<?= $section === 'content' ? 'active' : '' ?>">📝 Контент</a>
        <a href="?section=messages" class="<?= $section === 'messages' ? 'active' : '' ?>">💬 Сообщения</a>
        <a href="?section=reports" class="<?= $section === 'reports' ? 'active' : '' ?>">🚩 Жалобы</a>
        <a href="?section=logs" class="<?= $section === 'logs' ? 'active' : '' ?>">📋 Логи</a>
        <a href="?section=system" class="<?= $section === 'system' ? 'active' : '' ?>">⚙️ Система</a>
        <hr style="border-color:#333;">
        <a href="/profile.php">← На сайт</a>
    </nav>
    <main class="admin-main">
        <?php foreach (get_flash_messages() as $msg): ?>
            <div class="flash-message flash-<?= esc($msg['type']) ?>"><?= esc($msg['message']) ?></div>
        <?php endforeach; ?>

        <!-- Дашборд -->
        <?php if ($section === 'dashboard'): ?>
            <h2>📊 Дашборд</h2>
            <div class="stats-grid" id="dashboard-stats"></div>
            <div style="margin-top:20px; background:#fff; border-radius:16px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
                <h3 style="margin:0 0 12px;">Регистрации за 30 дней</h3>
                <div id="registrations-chart"></div>
            </div>
            <div style="margin-top:20px; background:#fff; border-radius:16px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
                <h3 style="margin:0 0 12px;">Посты за 30 дней</h3>
                <div id="posts-chart"></div>
            </div>
        <?php endif; ?>

        <!-- Пользователи -->
        <?php if ($section === 'users'): ?>
            <h2>👥 Пользователи</h2>
            <div class="filters">
                <input type="text" id="search-input" placeholder="Поиск по имени, фамилии или ID" style="width:280px;">
                <button id="search-btn" class="btn btn-primary">🔍 Найти</button>
            </div>
            <div id="users-table-container"></div>
            <div id="users-pagination" class="pagination"></div>
        <?php endif; ?>

        <!-- Контент -->
        <?php if ($section === 'content'): ?>
            <h2>📝 Контент</h2>
            <div class="filters">
                <input type="text" id="search-content-input" placeholder="Поиск по тексту поста" style="width:280px;">
                <button id="search-content-btn" class="btn btn-primary">🔍 Найти</button>
            </div>
            <div id="content-table-container"></div>
            <div id="content-pagination" class="pagination"></div>
        <?php endif; ?>

        <!-- Сообщения -->
        <?php if ($section === 'messages'): ?>
            <h2>💬 Сообщения</h2>
            <div class="filters">
                <input type="text" id="search-msg-input" placeholder="Поиск по тексту сообщения" style="width:280px;">
                <select id="msg-type-filter">
                    <option value="all">Все</option>
                    <option value="private">Личные</option>
                    <option value="group">Групповые</option>
                </select>
                <button id="search-msg-btn" class="btn btn-primary">🔍 Найти</button>
            </div>
            <div id="messages-table-container"></div>
            <div id="messages-pagination" class="pagination"></div>
        <?php endif; ?>

        <!-- Жалобы -->
        <?php if ($section === 'reports'): ?>
            <h2>🚩 Жалобы</h2>
            <div class="filters">
                <select id="filter-status">
                    <option value="all">Все</option>
                    <option value="open">Открытые</option>
                    <option value="resolved">Закрытые</option>
                </select>
                <select id="filter-type">
                    <option value="">Все типы</option>
                    <option value="user">Пользователи</option>
                    <option value="post">Посты</option>
                    <option value="message">Сообщения</option>
                </select>
                <button id="apply-filters-btn" class="btn btn-primary">🔍 Применить</button>
            </div>
            <div id="reports-table-container"></div>
            <div id="reports-pagination" class="pagination"></div>
        <?php endif; ?>

        <!-- Логи -->
        <?php if ($section === 'logs'): ?>
            <h2>📋 Логи администратора</h2>
            <div id="logs-table-container"></div>
            <div id="logs-pagination" class="pagination"></div>
        <?php endif; ?>

        <!-- Система -->
        <?php if ($section === 'system'): ?>
            <h2>⚙️ Система</h2>
            <div class="stats-grid" id="system-stats">
                <div class="stat-card"><div class="stat-value" id="php-version">—</div><div class="stat-label">PHP версия</div></div>
                <div class="stat-card"><div class="stat-value" id="server-software">—</div><div class="stat-label">Веб-сервер</div></div>
                <div class="stat-card"><div class="stat-value" id="db-version">—</div><div class="stat-label">MySQL версия</div></div>
                <div class="stat-card"><div class="stat-value" id="upload-max">—</div><div class="stat-label">Макс. загрузка</div></div>
                <div class="stat-card"><div class="stat-value" id="total-sessions">—</div><div class="stat-label">Активных сессий (час)</div></div>
                <div class="stat-card"><div class="stat-value" id="cache-hits">—</div><div class="stat-label">Кэш-попаданий</div></div>
                <div class="stat-card"><div class="stat-value" id="error-count">—</div><div class="stat-label">Ошибок за 24ч</div></div>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- Модальное окно подтверждения (кастомный confirm) -->
<div id="confirm-modal" class="modal-overlay" style="display:none;">
    <div class="modal-container" style="max-width:400px; padding:20px;">
        <h3 id="confirm-title" style="margin-top:0;">Подтверждение</h3>
        <p id="confirm-message"></p>
        <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:20px;">
            <button id="confirm-cancel-btn" class="btn btn-secondary">Отмена</button>
            <button id="confirm-ok-btn" class="btn btn-danger">Да</button>
        </div>
    </div>
</div>

<script src="/kopilot/js/kopilot.js"></script>
<script>
    const csrfToken = '<?= csrf_token() ?>';
    function esc(str) { return kop.esc ? kop.esc(str) : String(str).replace(/[&<>]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;'})[m]); }

    async function apiGet(url) {
        const res = await fetch(url, { headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' } });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    async function apiPost(url, data = {}) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(data)
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    function flash(type, msg) {
        const div = document.createElement('div');
        div.className = `flash-message flash-${type}`;
        div.textContent = msg;
        const main = document.querySelector('.admin-main');
        main.insertBefore(div, main.firstChild);
        setTimeout(() => div.remove(), 3000);
    }

    // Кастомное подтверждение
    function showConfirm(title, message, onConfirm, onCancel) {
        const modal = document.getElementById('confirm-modal');
        document.getElementById('confirm-title').textContent = title;
        document.getElementById('confirm-message').textContent = message;
        modal.style.display = 'flex';
        setTimeout(() => modal.classList.add('active'), 10);

        const close = () => {
            modal.classList.remove('active');
            setTimeout(() => { modal.style.display = 'none'; }, 300);
        };

        // Убираем старые обработчики
        const okBtn = document.getElementById('confirm-ok-btn');
        const cancelBtn = document.getElementById('confirm-cancel-btn');
        okBtn.replaceWith(okBtn.cloneNode(true));
        cancelBtn.replaceWith(cancelBtn.cloneNode(true));

        document.getElementById('confirm-ok-btn').addEventListener('click', () => {
            close();
            if (onConfirm) onConfirm();
        });
        document.getElementById('confirm-cancel-btn').addEventListener('click', () => {
            close();
            if (onCancel) onCancel();
        });
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                close();
                if (onCancel) onCancel();
            }
        });
    }

    // ========== ДАШБОРД ==========
    <?php if ($section === 'dashboard'): ?>
    (async function() {
        const stats = await apiGet('/api/admin/dashboard-stats');
        document.getElementById('dashboard-stats').innerHTML = `
            <div class="stat-card"><div class="stat-value">${stats.total_users}</div><div class="stat-label">Пользователей</div></div>
            <div class="stat-card"><div class="stat-value">${stats.new_users_today}</div><div class="stat-label">Новых сегодня</div></div>
            <div class="stat-card"><div class="stat-value">${stats.active_users_24h}</div><div class="stat-label">Активных за 24ч</div></div>
            <div class="stat-card"><div class="stat-value">${stats.total_posts}</div><div class="stat-label">Постов</div></div>
            <div class="stat-card"><div class="stat-value">${stats.posts_today}</div><div class="stat-label">Постов сегодня</div></div>
            <div class="stat-card"><div class="stat-value">${stats.total_comments}</div><div class="stat-label">Комментариев</div></div>
            <div class="stat-card"><div class="stat-value">${stats.messages_today}</div><div class="stat-label">Сообщений сегодня</div></div>
            <div class="stat-card"><div class="stat-value">${stats.total_messages}</div><div class="stat-label">Сообщений всего</div></div>
            <div class="stat-card"><div class="stat-value">${stats.total_endorsements}</div><div class="stat-label">Поручительств</div></div>
            <div class="stat-card"><div class="stat-value">${stats.open_reports}</div><div class="stat-label">Открытых жалоб</div></div>
            <div class="stat-card"><div class="stat-value">${stats.db_size_mb} МБ</div><div class="stat-label">Размер БД</div></div>
        `;

        // Простые графики (бары)
        const regData = await apiGet('/api/admin/registrations-daily');
        const maxReg = Math.max(...regData.values, 1);
        let regHtml = '';
        for (let i = 0; i < regData.labels.length; i++) {
            const pct = (regData.values[i] / maxReg) * 100;
            regHtml += `<div class="chart-bar-wrap"><span class="chart-label">${regData.labels[i]}</span><div class="chart-bar" style="width:${pct}%"></div><span class="chart-value">${regData.values[i]}</span></div>`;
        }
        document.getElementById('registrations-chart').innerHTML = regHtml;

        const postsData = await apiGet('/api/admin/posts-daily');
        const maxPost = Math.max(...postsData.values, 1);
        let postHtml = '';
        for (let i = 0; i < postsData.labels.length; i++) {
            const pct = (postsData.values[i] / maxPost) * 100;
            postHtml += `<div class="chart-bar-wrap"><span class="chart-label">${postsData.labels[i]}</span><div class="chart-bar" style="width:${pct}%"></div><span class="chart-value">${postsData.values[i]}</span></div>`;
        }
        document.getElementById('posts-chart').innerHTML = postHtml;
    })();
    <?php endif; ?>

    // ========== ПОЛЬЗОВАТЕЛИ ==========
    <?php if ($section === 'users'): ?>
    let usersPage = 1;
    async function loadUsers(search = '') {
        const container = document.getElementById('users-table-container');
        container.innerHTML = '<p>Загрузка...</p>';
        const data = await apiGet(`/api/admin/users?search=${encodeURIComponent(search)}&page=${usersPage}`);
        let html = `<table><tr><th>ID</th><th>Имя</th><th>Создан</th><th>Активность</th><th>Роль</th><th>Статус</th><th>Вес</th><th>Действия</th></tr>`;
        data.users.forEach(u => {
            html += `<tr>
                <td>${u.id}</td>
                <td>${esc(u.first_name)} ${esc(u.last_name)}</td>
                <td>${u.created_at ? new Date(u.created_at).toLocaleDateString('ru') : '—'}</td>
                <td>${u.last_active ? new Date(u.last_active).toLocaleString('ru') : '—'}</td>
                <td>${esc(u.role || 'user')}</td>
                <td>${u.status === 'blocked' ? '🔒' : '✅'}</td>
                <td>${Number(u.w_trust).toFixed(2)} / ${Number(u.w_activity).toFixed(2)} / ${Number(u.w_expert).toFixed(2)}</td>
                <td>
                    ${u.id !== <?= $_SESSION['user_id'] ?> ? `
                        <button class="btn btn-secondary" onclick="editWeight(${u.id}, ${u.w_trust}, ${u.w_activity}, ${u.w_expert})">⚖️ Вес</button>
                        ${u.status === 'blocked' 
                            ? `<button class="btn btn-success" onclick="unblockUser(${u.id})">🔓 Разблокировать</button>`
                            : `<button class="btn btn-danger" onclick="blockUser(${u.id})">🔒 Заблокировать</button>`
                        }
                    ` : ''}
                </td>
            </tr>`;
        });
        html += '</table>';
        container.innerHTML = html;
        renderUsersPagination(data.page, data.lastPage, search);
    }

    function renderUsersPagination(current, last, search) {
        const pag = document.getElementById('users-pagination');
        if (last <= 1) { pag.innerHTML = ''; return; }
        let html = '';
        for (let i = 1; i <= last; i++) {
            html += `<button class="btn ${i === current ? 'btn-primary' : 'btn-secondary'}" data-page="${i}">${i}</button> `;
        }
        pag.innerHTML = html;
        pag.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => {
                usersPage = parseInt(btn.dataset.page);
                loadUsers(search);
            });
        });
    }

    async function blockUser(userId) {
        showConfirm('Заблокировать пользователя', 'Пользователь потеряет доступ к аккаунту. Вы уверены?', async () => {
            await apiPost('/api/admin/users/block', { user_id: userId });
            flash('success', 'Пользователь заблокирован');
            loadUsers(document.getElementById('search-input').value);
        });
    }

    async function unblockUser(userId) {
        await apiPost('/api/admin/users/unblock', { user_id: userId });
        flash('success', 'Пользователь разблокирован');
        loadUsers(document.getElementById('search-input').value);
    }

    async function editWeight(userId, trust, activity, expert) {
        const newTrust = prompt('Вес доверия', trust); if (newTrust === null) return;
        const newActivity = prompt('Вес активности', activity); if (newActivity === null) return;
        const newExpert = prompt('Вес экспертности', expert); if (newExpert === null) return;
        await apiPost('/api/admin/users/update-weight', { user_id: userId, w_trust: parseFloat(newTrust), w_activity: parseFloat(newActivity), w_expert: parseFloat(newExpert) });
        flash('success', 'Веса обновлены');
        loadUsers(document.getElementById('search-input').value);
    }

    document.getElementById('search-btn').addEventListener('click', () => {
        usersPage = 1;
        loadUsers(document.getElementById('search-input').value);
    });
    loadUsers();
    <?php endif; ?>

    // ========== КОНТЕНТ ==========
    <?php if ($section === 'content'): ?>
    let contentPage = 1;
    async function loadContent(search = '') {
        const container = document.getElementById('content-table-container');
        container.innerHTML = '<p>Загрузка...</p>';
        const data = await apiGet(`/api/admin/posts?page=${contentPage}&search=${encodeURIComponent(search)}`);
        let html = `<table><tr><th>ID</th><th>Автор</th><th>Текст</th><th>Дата</th><th>Статус</th><th>Действия</th></tr>`;
        data.posts.forEach(p => {
            html += `<tr>
                <td>${p.id}</td>
                <td>${esc(p.first_name)} ${esc(p.last_name)}</td>
                <td>${esc(p.content?.substring(0, 80)) || '—'}</td>
                <td>${new Date(p.created_at).toLocaleString('ru')}</td>
                <td>${p.status === 'hidden' ? 'Скрыт' : 'Видим'}</td>
                <td>
                    ${p.status !== 'hidden' 
                        ? `<button class="btn btn-danger" onclick="hidePost(${p.id})">Скрыть</button>`
                        : `<button class="btn btn-success" onclick="unhidePost(${p.id})">Показать</button>`
                    }
                    <button class="btn btn-secondary" onclick="deletePost(${p.id})">🗑️</button>
                </td>
            </tr>`;
        });
        html += '</table>';
        container.innerHTML = html;
        renderContentPagination(data.page, data.lastPage, search);
    }

    function renderContentPagination(current, last, search) {
        const pag = document.getElementById('content-pagination');
        if (last <= 1) { pag.innerHTML = ''; return; }
        let html = '';
        for (let i = 1; i <= last; i++) {
            html += `<button class="btn ${i === current ? 'btn-primary' : 'btn-secondary'}" data-page="${i}">${i}</button> `;
        }
        pag.innerHTML = html;
        pag.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => {
                contentPage = parseInt(btn.dataset.page);
                loadContent(search);
            });
        });
    }

    async function hidePost(postId) {
        showConfirm('Скрыть пост', 'Пост станет невидимым для всех. Продолжить?', async () => {
            await apiPost('/api/admin/posts/hide', { post_id: postId });
            flash('success', 'Пост скрыт');
            loadContent(document.getElementById('search-content-input').value);
        });
    }

    async function unhidePost(postId) {
        await apiPost('/api/admin/posts/unhide', { post_id: postId });
        flash('success', 'Пост открыт');
        loadContent(document.getElementById('search-content-input').value);
    }

    async function deletePost(postId) {
        showConfirm('Удалить пост', 'Это действие нельзя отменить. Продолжить?', async () => {
            await apiPost('/api/admin/posts/delete', { post_id: postId });
            flash('success', 'Пост удалён');
            loadContent(document.getElementById('search-content-input').value);
        });
    }

    document.getElementById('search-content-btn').addEventListener('click', () => {
        contentPage = 1;
        loadContent(document.getElementById('search-content-input').value);
    });
    loadContent();
    <?php endif; ?>

    // ========== СООБЩЕНИЯ ==========
    <?php if ($section === 'messages'): ?>
    let msgPage = 1;
    async function loadMessages(search = '', type = 'all') {
        const container = document.getElementById('messages-table-container');
        container.innerHTML = '<p>Загрузка...</p>';
        const data = await apiGet(`/api/admin/messages?page=${msgPage}&search=${encodeURIComponent(search)}&type=${type}`);
        let html = `<table><tr><th>ID</th><th>Тип</th><th>Отправитель</th><th>Получатель/Группа</th><th>Текст</th><th>Дата</th></tr>`;
        data.messages.forEach(m => {
            html += `<tr>
                <td>${m.id}</td>
                <td>${m.type === 'private' ? 'Личное' : 'Групповое'}</td>
                <td>${esc(m.sender_name)}</td>
                <td>${esc(m.receiver_name || m.group_name)}</td>
                <td>${esc(m.content?.substring(0, 60)) || '—'}</td>
                <td>${new Date(m.created_at).toLocaleString('ru')}</td>
            </tr>`;
        });
        html += '</table>';
        container.innerHTML = html;
        renderMessagesPagination(data.page, data.lastPage, search, type);
    }

    function renderMessagesPagination(current, last, search, type) {
        const pag = document.getElementById('messages-pagination');
        if (last <= 1) { pag.innerHTML = ''; return; }
        let html = '';
        for (let i = 1; i <= last; i++) {
            html += `<button class="btn ${i === current ? 'btn-primary' : 'btn-secondary'}" data-page="${i}">${i}</button> `;
        }
        pag.innerHTML = html;
        pag.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => {
                msgPage = parseInt(btn.dataset.page);
                loadMessages(search, type);
            });
        });
    }

    document.getElementById('search-msg-btn').addEventListener('click', () => {
        msgPage = 1;
        loadMessages(
            document.getElementById('search-msg-input').value,
            document.getElementById('msg-type-filter').value
        );
    });
    loadMessages();
    <?php endif; ?>

    // ========== ЖАЛОБЫ ==========
    <?php if ($section === 'reports'): ?>
    let reportsPage = 1;
    let reportsFilter = { status: 'all', type: '' };

    async function loadReports() {
        const container = document.getElementById('reports-table-container');
        container.innerHTML = '<p>Загрузка...</p>';
        try {
            const params = new URLSearchParams({ page: reportsPage, status: reportsFilter.status, type: reportsFilter.type });
            const data = await apiGet(`/api/admin/reports?${params}`);
            if (!data.reports || !Array.isArray(data.reports)) throw new Error('Некорректный ответ сервера');
            let html = `<table><tr><th>ID</th><th>Тип</th><th>Объект</th><th>Отправитель</th><th>Причина</th><th>Статус</th><th>Дата</th><th>Действия</th></tr>`;
            data.reports.forEach(r => {
                html += `<tr>
                    <td>${r.id}</td>
                    <td>${r.type}</td>
                    <td>${esc(r.target_summary || r.target_id)}</td>
                    <td>${r.reporter_id}</td>
                    <td>${esc(r.reason)}</td>
                    <td>${r.status === 'open' ? 'Открыта' : 'Закрыта'}</td>
                    <td>${new Date(r.created_at).toLocaleString('ru')}</td>
                    <td>
                        ${r.status === 'open' ? `
                            <button class="btn btn-primary" onclick="resolveReport(${r.id}, 'resolved')">Решить</button>
                            <button class="btn btn-secondary" onclick="resolveReport(${r.id}, 'dismissed')">Отклонить</button>
                            ${r.type === 'post' ? `<button class="btn btn-danger" onclick="hidePost(${r.target_id})">Скрыть пост</button>` : ''}
                            ${r.type === 'user' ? `<button class="btn btn-danger" onclick="blockUserFromReport(${r.target_id})">Бан</button>` : ''}
                        ` : 'Обработана'}
                    </td>
                </tr>`;
            });
            html += '</table>';
            container.innerHTML = html;
            renderReportsPagination(data.page, data.lastPage);
        } catch (e) {
            container.innerHTML = `<p style="color:#b91c1c;">Ошибка загрузки: ${esc(e.message)}</p>`;
        }
    }

    function renderReportsPagination(current, last) {
        const pag = document.getElementById('reports-pagination');
        if (last <= 1) { pag.innerHTML = ''; return; }
        let html = '';
        for (let i = 1; i <= last; i++) {
            html += `<button class="btn ${i === current ? 'btn-primary' : 'btn-secondary'}" data-page="${i}">${i}</button> `;
        }
        pag.innerHTML = html;
        pag.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => {
                reportsPage = parseInt(btn.dataset.page);
                loadReports();
            });
        });
    }

    async function resolveReport(reportId, resolution) {
        await apiPost('/api/admin/reports/resolve', { report_id: reportId, resolution });
        flash('success', 'Жалоба обработана');
        loadReports();
    }

    async function hidePost(postId) {
        showConfirm('Скрыть пост', 'Пост станет невидимым для всех. Продолжить?', async () => {
            await apiPost('/api/admin/posts/hide', { post_id: postId });
            flash('success', 'Пост скрыт');
            loadReports();
        });
    }

    async function blockUserFromReport(userId) {
        showConfirm('Заблокировать пользователя', 'Пользователь потеряет доступ к аккаунту. Вы уверены?', async () => {
            await apiPost('/api/admin/users/block', { user_id: userId });
            flash('success', 'Пользователь заблокирован');
            loadReports();
        });
    }

    document.getElementById('apply-filters-btn').addEventListener('click', () => {
        reportsFilter.status = document.getElementById('filter-status').value;
        reportsFilter.type = document.getElementById('filter-type').value;
        reportsPage = 1;
        loadReports();
    });
    loadReports();
    <?php endif; ?>

    // ========== ЛОГИ ==========
    <?php if ($section === 'logs'): ?>
    let logPage = 1;
    async function loadLogs() {
        const container = document.getElementById('logs-table-container');
        container.innerHTML = '<p>Загрузка...</p>';
        const data = await apiGet(`/api/admin/logs?page=${logPage}`);
        let html = `<table><tr><th>ID</th><th>Админ</th><th>Действие</th><th>Цель</th><th>Детали</th><th>Дата</th></tr>`;
        data.logs.forEach(l => {
            html += `<tr>
                <td>${l.id}</td>
                <td>${esc(l.admin_name)}</td>
                <td>${esc(l.action)}</td>
                <td>${l.target_user_id || '—'}</td>
                <td>${esc(l.details || '')}</td>
                <td>${new Date(l.created_at).toLocaleString('ru')}</td>
            </tr>`;
        });
        html += '</table>';
        container.innerHTML = html;
        renderLogsPagination(data.page, data.lastPage);
    }

    function renderLogsPagination(current, last) {
        const pag = document.getElementById('logs-pagination');
        if (last <= 1) { pag.innerHTML = ''; return; }
        let html = '';
        for (let i = 1; i <= last; i++) {
            html += `<button class="btn ${i === current ? 'btn-primary' : 'btn-secondary'}" data-page="${i}">${i}</button> `;
        }
        pag.innerHTML = html;
        pag.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => {
                logPage = parseInt(btn.dataset.page);
                loadLogs();
            });
        });
    }
    loadLogs();
    <?php endif; ?>

    // ========== СИСТЕМА ==========
    <?php if ($section === 'system'): ?>
    (async function() {
        const stats = await apiGet('/api/admin/system-stats');
        document.getElementById('php-version').textContent = stats.php_version;
        document.getElementById('server-software').textContent = stats.server_software;
        document.getElementById('db-version').textContent = stats.db_version;
        document.getElementById('upload-max').textContent = stats.upload_max_filesize;
        document.getElementById('total-sessions').textContent = stats.total_sessions;
        document.getElementById('cache-hits').textContent = stats.cache_hits;
        document.getElementById('error-count').textContent = stats.error_count_24h;
    })();
    <?php endif; ?>
</script>
</body>
</html>