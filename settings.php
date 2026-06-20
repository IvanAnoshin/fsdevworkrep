<?php
ob_start();
require_once __DIR__ . '/components/header.php';

// Гость не должен быть здесь — require_auth() уже должен был перенаправить,
// но на всякий случай делаем проверку
if (!is_logged_in()) {
    redirect('/feed.php');
    exit;
}

$user = find('users', $_SESSION['user_id']);
if (!$user) {
    echo '<p>Пользователь не найден</p>';
    exit;
}
$pageTitle = 'Настройки - Friendscape';

// Проверка, является ли текущий пользователь администратором
$isAdmin = ($user['role'] ?? '') === 'admin' || scalar("SELECT COUNT(*) FROM admins WHERE user_id = ?", [$_SESSION['user_id']]) > 0;

// ---------- ОБРАБОТКА ФОРМ ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Сохранение профиля
    if ($action === 'update_profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');

        if ($firstName === '' || $lastName === '') {
            flash('errors', ['profile' => 'Имя и фамилия обязательны']);
        } else {
            $updateData = [
                'first_name' => $firstName,
                'last_name'  => $lastName,
            ];

            // Загрузка аватара
            if (!empty($_FILES['avatar']['tmp_name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $realMime = finfo_file($finfo, $_FILES['avatar']['tmp_name']);
                finfo_close($finfo);

                if (in_array($realMime, $allowedTypes)) {
                    $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
                    $filename = 'avatar_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
                    $uploadDir = __DIR__ . '/uploads/avatars/';

                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    $dest = $uploadDir . $filename;
                    if (move_uploaded_file($_FILES['avatar']['tmp_name'], $dest)) {
                        if (!empty($user['avatar']) && file_exists(__DIR__ . '/' . $user['avatar'])) {
                            unlink(__DIR__ . '/' . $user['avatar']);
                        }
                        $updateData['avatar'] = 'uploads/avatars/' . $filename;
                        flash('success', 'Аватар обновлён');
                    } else {
                        flash('error', 'Не удалось сохранить файл. Проверьте права на папку uploads/avatars/');
                    }
                } else {
                    flash('error', 'Недопустимый тип файла. Разрешены JPG, PNG, GIF');
                }
            } else {
                flash('success', 'Профиль обновлён');
            }

            update('users', $_SESSION['user_id'], $updateData);
            require_once __DIR__ . '/dfsn.php';
            $dfsn = new DFSN();
            $profileWords = [];
            $fields = ['hometown', 'city', 'country', 'languages', 'interests', 'about',
                    'job', 'education', 'religion', 'personality', 'dreams', 'intentions',
                    'values', 'quotes', 'idols', 'gadgets'];
            foreach ($fields as $field) {
                if (!empty($user[$field])) {
                    $profileWords[] = $user[$field];
                }
            }
            $dfsn->updateProfileVector($_SESSION['user_id'], $profileWords);
        }

        ob_end_clean();
        redirect('/settings.php?act=account-info');
    }

    // Сохранение личных данных
    elseif ($action === 'edit') {
        $fields = ['hometown','city','country','languages','interests','about','dislikes',
            'relationship','job','education','military','religion','personality',
            'dreams','intentions','values','quotes','idols','gadgets'];

        $updateData = [];
        foreach ($fields as $f) {
            $updateData[$f] = trim($_POST[$f] ?? '');
        }

        update('users', $_SESSION['user_id'], $updateData);
        flash('success', 'Личные данные сохранены');
        ob_end_clean();
        redirect('/settings.php?act=edit');
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($pageTitle) ?></title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        /* ---------- Стили обертки паспорта ---------- */
        .passportWrapper {
            background: #fff;
            border-radius: 20px;
            padding: 24px 20px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.04);
            max-width: 480px;
            width: 100%;
            margin: 0 auto;
            margin-bottom: 20px;
            box-sizing: border-box;
        }

        .passportWrapper .escapeButton {
            align-self: flex-start;
            margin-bottom: 16px;
        }

        .passportWrapper .accountTitle {
            margin: 0 0 8px;
            font-size: 1.3rem;
            font-weight: 600;
            color: #1e1e2f;
            text-align: left;
        }

        .passportWrapper .passport-description {
            color: #6b7280;
            margin: 0 0 24px;
            font-size: 0.9em;
            line-height: 1.5;
            text-align: left;
        }

        /* ---------- Стили паспорта ---------- */
        .passportSection {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
        }

        .passportCard {
            background: linear-gradient(135deg, #f0f4ff 0%, #ffffff 100%);
            border: 2px solid #d0d7f0;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 12px 30px rgba(59, 93, 211, 0.1);
            position: relative;
            overflow: hidden;
            max-width: 360px;
            width: 100%;
            margin: 0 auto;
            box-sizing: border-box;
        }

        .passportLogo {
            font-size: 1.8em;
            font-weight: 600;
            color: #3b5dd3;
            text-align: center;
            margin-bottom: 20px;
            letter-spacing: -0.5px;
        }

        .passportPhoto {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 16px;
            display: block;
            border: 3px solid #3b5dd3;
        }

        .passportPhotoPlaceholder {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #e0e7ff;
            color: #3b5dd3;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 600;
            margin: 0 auto 16px;
            border: 3px solid #3b5dd3;
        }

        .passportName {
            text-align: center;
            font-size: 1.3em;
            font-weight: 600;
            color: #1e1e2f;
            margin-bottom: 12px;
        }

        .passportNumber {
            text-align: center;
            font-family: 'Courier New', monospace;
            font-size: 1.2em;
            letter-spacing: 2px;
            background: #f9fafb;
            padding: 10px;
            border-radius: 8px;
            color: #1e1e2f;
            margin-bottom: 20px;
            word-break: break-all;
        }

        .passportActions {
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 8px;
            margin-top: 16px;
        }

        .btn-passport {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            border-radius: 10px;
            font-size: 0.9em;
            font-weight: 500;
            cursor: pointer;
            border: none;
            text-align: center;
            transition: background 0.2s;
        }

        .btn-unlock {
            background: #3b5dd3;
            color: white;
        }

        .btn-unlock:hover {
            background: #2f4bb5;
        }

        .btn-generate {
            background: #f59e0b;
            color: white;
        }

        .btn-generate:hover {
            background: #d97706;
        }

        .btn-copy {
            background: #10b981;
            color: white;
        }

        .btn-copy:hover {
            background: #059669;
        }

        .passportBlurOverlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            backdrop-filter: blur(12px);
            background: rgba(255,255,255,0.4);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 2;
            transition: opacity 0.5s ease;
        }

        .passportBlurOverlay span {
            background: #3b5dd3;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="settingsMainArea">
        <div class="accountHeader">
            <p><?= esc($user['first_name'] . ' ' . $user['last_name']) ?></p>
        </div><br>

        <?php if ($msg = flash('success')): ?>
            <div class="alert alert-success" style="background:#d1fae5;color:#059669;padding:10px;border-radius:8px;margin-bottom:10px;"><?= esc($msg) ?></div>
        <?php endif; ?>

        <?php if ($err = flash('error')): ?>
            <div class="alert alert-error" style="background:#fee2e2;color:#b91c1c;padding:10px;border-radius:8px;margin-bottom:10px;"><?= esc($err) ?></div>
        <?php endif; ?>

        <div class="settingsList">
            <a href="?act=account-info">
                <span class="settingsList__icon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                </span>
                Об аккаунте
            </a>
            <a href="?act=edit">
                <span class="settingsList__icon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                </span>
                Личное
            </a>
            <a href="?act=passport">
                <span class="settingsList__icon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                        <rect x="2" y="15" width="20" height="5" rx="1"/>
                    </svg>
                </span>
                Паспорт
            </a>
            <a href="?act=privacy">
                <span class="settingsList__icon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                </span>
                Приватность
            </a>
            <a href="?act=notifications">
                <span class="settingsList__icon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                </span>
                Уведомления
            </a>
            <?php if ($isAdmin): ?>
            <a href="management.php">
                <span class="settingsList__icon" style="background: #fef9c3; color: #b45309;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                </span>
                Управление
            </a>
            <?php endif; ?>
            <a href="logout.php">
                <span class="settingsList__icon" style="background: #f0f0f0; color: #b91c1c;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                </span>
                Выйти
            </a>
        </div>

        <!-- ОБ АККАУНТЕ -->
        <div class="account-info">
            <div class="accountCard">
                <button class="escapeButton" data-act="escape">Назад</button>
                <h3 class="accountTitle">Об аккаунте</h3>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

                    <div class="accountGroup">
                        <p class="accountGroupHeader">Профиль</p>
                        <div class="accountProfileRow">
                            <div class="accountAvatar" id="avatar-preview">
                                <?php
                                $initials = mb_substr($user['first_name'] ?? '', 0, 1) . mb_substr($user['last_name'] ?? '', 0, 1);
                                if (!empty($user['avatar'])): ?>
                                    <img src="<?= esc($user['avatar']) ?>?t=<?= time() ?>" alt="Аватар" style="width:100%;height:100%;object-fit:cover;"
                                         onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                                    <span class="accountAvatarPlaceholder" style="display:none;"><?= esc($initials) ?></span>
                                <?php else: ?>
                                    <span class="accountAvatarPlaceholder"><?= esc($initials) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="accountUpload">
                                <input type="file" name="avatar" id="avatar-upload" accept="image/*" style="display: none;">
                                <button type="button" class="accountBtn accountBtn--small" onclick="document.getElementById('avatar-upload').click()">Изменить фото</button>
                            </div>

                            <div class="accountProfileFields">
                                <div class="editRow">
                                    <label class="editLabel">
                                        <span class="editIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/></svg>
                                        </span> Имя
                                    </label>
                                    <input type="text" name="first_name" placeholder="Введите имя" value="<?= esc($user['first_name']) ?>">
                                </div>

                                <div class="editRow">
                                    <label class="editLabel">
                                        <span class="editIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/></svg>
                                        </span> Фамилия
                                    </label>
                                    <input type="text" name="last_name" placeholder="Введите фамилию" value="<?= esc($user['last_name']) ?>">
                                </div>

                                <button class="saveAccountChanges" type="submit">Сохранить</button>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Безопасность -->
                <div class="accountGroup">
                    <p class="accountGroupHeader">Безопасность</p>
                    <a href="change-password.php" class="accountRow accountRow--action">
                        <span class="accountIcon" style="background: #f0f0f0; color: #3b5dd3;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </span>
                        <span class="accountLabel">Сменить пароль</span>
                        <span class="accountArrow">›</span>
                    </a>
                    <a href="change-secret.php" class="accountRow accountRow--action">
                        <span class="accountIcon" style="background: #f0f0f0; color: #3b5dd3;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                        </span>
                        <span class="accountLabel">Сменить секретное слово</span>
                        <span class="accountArrow">›</span>
                    </a>
                </div>

                <!-- АКТИВНЫЕ СЕССИИ -->
                <div class="accountGroup">
                    <p class="accountGroupHeader">Активные сессии</p>
                    <div id="sessions-list">
                        <p style="color:#8b8fa3; text-align:center; padding:10px;">Загрузка...</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ЛИЧНОЕ -->
        <div class="editSection">
            <form action="" method="post">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

                <div class="editCard">
                    <button class="escapeButton" data-act="escape">Назад</button>

                    <!-- Родной город -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            </span> Родной город
                        </label>
                        <input type="text" name="hometown" placeholder="Укажите город" value="<?= esc($user['hometown'] ?? '') ?>">
                    </div>

                    <!-- Город -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="9" x2="15" y2="15"/><line x1="15" y1="9" x2="9" y2="15"/></svg>
                            </span> Город
                        </label>
                        <input type="text" name="city" placeholder="Ваш город" value="<?= esc($user['city'] ?? '') ?>">
                    </div>

                    <!-- Страна -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            </span> Страна
                        </label>
                        <input type="text" name="country" placeholder="Страна проживания" value="<?= esc($user['country'] ?? '') ?>">
                    </div>

                    <!-- Языки -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            </span> Языки
                        </label>
                        <input type="text" name="languages" placeholder="Русский, English..." value="<?= esc($user['languages'] ?? '') ?>">
                    </div>

                    <!-- Интересы -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            </span> Интересы
                        </label>
                        <textarea name="interests" placeholder="Музыка, спорт..."><?= esc($user['interests'] ?? '') ?></textarea>
                    </div>

                    <!-- Обо мне -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            </span> Обо мне
                        </label>
                        <textarea name="about" placeholder="Расскажите о себе"><?= esc($user['about'] ?? '') ?></textarea>
                    </div>

                    <!-- Мне не нравится -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                            </span> Мне не нравится
                        </label>
                        <textarea name="dislikes" placeholder="Что вам не по душе"><?= esc($user['dislikes'] ?? '') ?></textarea>
                    </div>

                    <!-- Статус отношений -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </span> Статус отношений
                        </label>
                        <select name="relationship">
                            <option value="">Интересуюсь</option>
                            <option value="Не интересуюсь" <?= ($user['relationship'] ?? '') === 'Не интересуюсь' ? 'selected' : '' ?>>Не интересуюсь</option>
                            <option value="Трудно" <?= ($user['relationship'] ?? '') === 'Трудно' ? 'selected' : '' ?>>Трудно</option>
                            <option value="Помолвлен(а)" <?= ($user['relationship'] ?? '') === 'Помолвлен(а)' ? 'selected' : '' ?>>Помолвлен(а)</option>
                            <option value="В браке" <?= ($user['relationship'] ?? '') === 'В браке' ? 'selected' : '' ?>>В браке</option>
                            <option value="В гражданском браке" <?= ($user['relationship'] ?? '') === 'В гражданском браке' ? 'selected' : '' ?>>В гражданском браке</option>
                            <option value="В разводе" <?= ($user['relationship'] ?? '') === 'В разводе' ? 'selected' : '' ?>>В разводе</option>
                            <option value="Вдовец / Вдова" <?= ($user['relationship'] ?? '') === 'Вдовец / Вдова' ? 'selected' : '' ?>>Вдовец / Вдова</option>
                        </select>
                    </div>

                    <!-- Работа -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="13" rx="2"/><path d="M6 7V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2"/></svg>
                            </span> Работа
                        </label>
                        <input type="text" name="job" placeholder="Место работы" value="<?= esc($user['job'] ?? '') ?>">
                    </div>

                    <!-- Обучение -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                            </span> Обучение
                        </label>
                        <input type="text" name="education" placeholder="Учебное заведение" value="<?= esc($user['education'] ?? '') ?>">
                    </div>

                    <!-- Служба -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="13" rx="2"/><path d="M6 7V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2"/></svg>
                            </span> Служба
                        </label>
                        <input type="text" name="military" placeholder="Военная служба" value="<?= esc($user['military'] ?? '') ?>">
                    </div>

                    <!-- Вера -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                            </span> Вера
                        </label>
                        <input type="text" name="religion" placeholder="Религиозные взгляды" value="<?= esc($user['religion'] ?? '') ?>">
                    </div>

                    <!-- Характер -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-6"/></svg>
                            </span> Характер
                        </label>
                        <textarea name="personality" placeholder="Особенности характера"><?= esc($user['personality'] ?? '') ?></textarea>
                    </div>

                    <!-- Мечты -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                            </span> Мечты
                        </label>
                        <textarea name="dreams" placeholder="Ваши мечты"><?= esc($user['dreams'] ?? '') ?></textarea>
                    </div>

                    <!-- Намерения -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                            </span> Намерения
                        </label>
                        <textarea name="intentions" placeholder="Ваши планы и цели"><?= esc($user['intentions'] ?? '') ?></textarea>
                    </div>

                    <!-- Ценю в людях -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            </span> Ценю в людях
                        </label>
                        <textarea name="values" placeholder="Качества, которые цените"><?= esc($user['values'] ?? '') ?></textarea>
                    </div>

                    <!-- Любимые цитаты -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
                            </span> Любимые цитаты
                        </label>
                        <textarea name="quotes" placeholder="Ваши любимые цитаты"><?= esc($user['quotes'] ?? '') ?></textarea>
                    </div>

                    <!-- Интересные личности, кумиры -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </span> Интересные личности, кумиры
                        </label>
                        <textarea name="idols" placeholder="Кто вас вдохновляет"><?= esc($user['idols'] ?? '') ?></textarea>
                    </div>

                    <!-- Мои гаджеты -->
                    <div class="editRow">
                        <label class="editLabel">
                            <span style="background: #f0f0f0; color: #3b5dd3; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                            </span> Мои гаджеты
                        </label>
                        <textarea name="gadgets" placeholder="Телефон, планшет, ноутбук..."><?= esc($user['gadgets'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="saveBtn">Сохранить изменения</button>
                </div>
            </form>
        </div>

        <!-- ПАСПОРТ -->
        <div class="passportSection">
            <div class="passportWrapper">
                <button class="escapeButton" data-act="escape">Назад</button>
                <h3 class="accountTitle">Friendscape Паспорт</h3>
                <p class="passport-description">
                    Уникальный идентификатор вашего аккаунта. Используйте его для восстановления доступа.
                </p>

                <div class="passportCard" id="passport-card">
                    <div class="passportBlurOverlay" id="passport-blur-overlay" onclick="unlockPassport()">
                        <span>🔒 Разблокировать</span>
                    </div>

                    <div class="passportLogo">Friendscape</div>
                    <?php if (!empty($user['avatar'])): ?>
                        <img src="<?= esc($user['avatar']) ?>" alt="Фото" class="passportPhoto">
                    <?php else: ?>
                        <div class="passportPhotoPlaceholder"><?= esc(mb_substr($user['first_name'],0,1).mb_substr($user['last_name'],0,1)) ?></div>
                    <?php endif; ?>

                    <div class="passportName"><?= esc($user['first_name'] . ' ' . $user['last_name']) ?></div>

                    <div class="passportNumber" id="passport-value-display">
                        Заблокировано
                    </div>

                    <div class="passportActions">
                        <button class="btn-passport btn-unlock" onclick="unlockPassport()">Разблокировать паспорт</button>
                        <button class="btn-passport btn-copy" onclick="copyPassport()">Скопировать номер</button>
                        <button class="btn-passport btn-generate" onclick="generateNewPassport()">Сгенерировать новый паспорт</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ПРИВАТНОСТЬ -->
        <div class="privacySection">
            <button class="escapeButton" data-act="escape">Назад</button>
            <h3 class="accountTitle" style="margin-bottom:16px;">Приватность</h3>

            <div class="privacyRow">
                <span class="privacyIcon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </span>
                <label class="privacyLabel">Разрешение на личные сообщения</label>
                <select name="privacy_messages" class="privacySelect">
                    <option value="all" <?= ($user['privacy_messages'] ?? 'all') === 'all' ? 'selected' : '' ?>>Всем</option>
                    <option value="friends" <?= ($user['privacy_messages'] ?? '') === 'friends' ? 'selected' : '' ?>>Друзьям</option>
                    <option value="nobody" <?= ($user['privacy_messages'] ?? '') === 'nobody' ? 'selected' : '' ?>>Никому</option>
                </select>
            </div>

            <div class="privacyRow">
                <span class="privacyIcon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </span>
                <label class="privacyLabel">Возможность смотреть публикации</label>
                <select name="privacy_posts" class="privacySelect">
                    <option value="public" <?= ($user['privacy_posts'] ?? 'public') === 'public' ? 'selected' : '' ?>>Всем</option>
                    <option value="friends" <?= ($user['privacy_posts'] ?? '') === 'friends' ? 'selected' : '' ?>>Друзьям</option>
                    <option value="self" <?= ($user['privacy_posts'] ?? '') === 'self' ? 'selected' : '' ?>>Только мне</option>
                </select>
            </div>

            <div class="privacyRow">
                <span class="privacyIcon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                </span>
                <label class="privacyLabel">Возможность смотреть фотоальбомы</label>
                <select name="privacy_albums" class="privacySelect">
                    <option value="public" <?= ($user['privacy_albums'] ?? 'public') === 'public' ? 'selected' : '' ?>>Всем</option>
                    <option value="friends" <?= ($user['privacy_albums'] ?? '') === 'friends' ? 'selected' : '' ?>>Друзьям</option>
                    <option value="self" <?= ($user['privacy_albums'] ?? '') === 'self' ? 'selected' : '' ?>>Только мне</option>
                </select>
            </div>

            <div class="privacyRow">
                <span class="privacyIcon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </span>
                <label class="privacyLabel">Возможность комментировать</label>
                <select name="privacy_comments" class="privacySelect">
                    <option value="public" <?= ($user['privacy_comments'] ?? 'public') === 'public' ? 'selected' : '' ?>>Всем</option>
                    <option value="friends" <?= ($user['privacy_comments'] ?? '') === 'friends' ? 'selected' : '' ?>>Друзьям</option>
                    <option value="self" <?= ($user['privacy_comments'] ?? '') === 'self' ? 'selected' : '' ?>>Только мне</option>
                </select>
            </div>

            <div class="privacyRow">
                <span class="privacyIcon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </span>
                <label class="privacyLabel">Возможность смотреть личную информацию</label>
                <select name="privacy_info" class="privacySelect">
                    <option value="public" <?= ($user['privacy_info'] ?? 'public') === 'public' ? 'selected' : '' ?>>Всем</option>
                    <option value="friends" <?= ($user['privacy_info'] ?? '') === 'friends' ? 'selected' : '' ?>>Друзьям</option>
                    <option value="self" <?= ($user['privacy_info'] ?? '') === 'self' ? 'selected' : '' ?>>Только мне</option>
                </select>
            </div>

            <div class="privacyRow">
                <span class="privacyIcon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                </span>
                <div class="privacyLabelWithDesc">
                    <label class="privacyLabel">Нежелательное общение</label>
                    <p class="privacyDesc">Вы получаете сначала запрос на общение, если написал неизвестный вам человек</p>
                </div>
                <input type="checkbox" name="unwanted_communication" class="privacyCheckbox" <?= ($user['unwanted_communication'] ?? 0) ? 'checked' : '' ?>>
            </div>

            <div class="privacyRow">
                <span class="privacyIcon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </span>
                <label class="privacyLabel">Читать сообщение без пометки "Прочитано"</label>
                <input type="checkbox" name="read_receipt" class="privacyCheckbox" <?= ($user['read_receipt'] ?? 0) ? 'checked' : '' ?>>
            </div>

            <div class="privacyRow">
                <span class="privacyIcon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg>
                </span>
                <label class="privacyLabel">Показывать статус онлайн</label>
                <input type="checkbox" name="show_online" class="privacyCheckbox" <?= ($user['show_online'] ?? 1) ? 'checked' : '' ?>>
            </div>

            <div class="privacyRow">
                <span class="privacyIcon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/></svg>
                </span>
                <label class="privacyLabel">Черный список</label>
                <a href="#" class="privacyLink">Управление списком</a>
            </div>

            <p style="color:#8b8fa3;font-size:0.85em;">Изменения сохраняются автоматически</p>
        </div>

        <!-- УВЕДОМЛЕНИЯ -->
        <div class="notifications">
            <div class="notificationsCard">
                <button class="escapeButton" data-act="escape">Назад</button>
                <h3 class="notificationsTitle">Настройки уведомлений</h3>

                <div class="notificationsGroup">
                    <p class="notificationsGroupHeader">Сообщения</p>
                    <div class="notificationsRow">
                        <span class="notificationsIcon" style="background: #f0f0f0; color: #3b5dd3;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        </span>
                        <span class="notificationsLabel">Звук при новом сообщении</span>
                        <input type="checkbox" name="notify_sound" class="notificationsCheckbox" <?= ($user['notify_sound'] ?? 0) ? 'checked' : '' ?>>
                    </div>
                    <div class="notificationsRow">
                        <span class="notificationsIcon" style="background: #f0f0f0; color: #3b5dd3;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="9" x2="15" y2="15"/><line x1="15" y1="9" x2="9" y2="15"/></svg>
                        </span>
                        <span class="notificationsLabel">Показывать всплывающее уведомление</span>
                        <input type="checkbox" name="notify_push" class="notificationsCheckbox" <?= ($user['notify_push'] ?? 0) ? 'checked' : '' ?>>
                    </div>
                </div>

                <div class="notificationsGroup">
                    <p class="notificationsGroupHeader">Активность</p>
                    <div class="notificationsRow">
                        <span class="notificationsIcon" style="background: #f0f0f0; color: #3b5dd3;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </span>
                        <span class="notificationsLabel">Новые подписчики / заявки в друзья</span>
                        <input type="checkbox" name="notify_followers" class="notificationsCheckbox" <?= ($user['notify_followers'] ?? 1) ? 'checked' : '' ?>>
                    </div>
                    <div class="notificationsRow">
                        <span class="notificationsIcon" style="background: #f0f0f0; color: #3b5dd3;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                        </span>
                        <span class="notificationsLabel">Лайки и реакции</span>
                        <input type="checkbox" name="notify_likes" class="notificationsCheckbox" <?= ($user['notify_likes'] ?? 1) ? 'checked' : '' ?>>
                    </div>
                    <div class="notificationsRow">
                        <span class="notificationsIcon" style="background: #f0f0f0; color: #3b5dd3;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2H19a2 2 0 0 1 2 2z"/></svg>
                        </span>
                        <span class="notificationsLabel">Комментарии к моим постам</span>
                        <input type="checkbox" name="notify_comments" class="notificationsCheckbox" <?= ($user['notify_comments'] ?? 1) ? 'checked' : '' ?>>
                    </div>
                    <div class="notificationsRow">
                        <span class="notificationsIcon" style="background: #f0f0f0; color: #3b5dd3;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3v2a2 2 0 0 0-2 2v5a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 0-2-2V3"/><rect x="4" y="3" width="16" height="18" rx="2" ry="2"/></svg>
                        </span>
                        <span class="notificationsLabel">Упоминания (теги)</span>
                        <input type="checkbox" name="notify_mentions" class="notificationsCheckbox" <?= ($user['notify_mentions'] ?? 1) ? 'checked' : '' ?>>
                    </div>
                </div>

                <div class="notificationsGroup">
                    <p class="notificationsGroupHeader">Безопасность</p>
                    <div class="notificationsRow">
                        <span class="notificationsIcon" style="background: #f0f0f0; color: #3b5dd3;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </span>
                        <span class="notificationsLabel">Попытки входа с нового устройства</span>
                        <input type="checkbox" name="notify_new_login" class="notificationsCheckbox" <?= ($user['notify_new_login'] ?? 1) ? 'checked' : '' ?>>
                    </div>
                    <div class="notificationsRow">
                        <span class="notificationsIcon" style="background: #f0f0f0; color: #3b5dd3;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </span>
                        <span class="notificationsLabel">Изменение настроек безопасности</span>
                        <input type="checkbox" name="notify_security_changes" class="notificationsCheckbox" <?= ($user['notify_security_changes'] ?? 1) ? 'checked' : '' ?>>
                    </div>
                </div>

                <p style="color:#8b8fa3;font-size:0.85em;">Изменения сохраняются автоматически</p>
            </div>
        </div>
    </div>

    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

    <script>
        window.csrfToken = document.querySelector('input[name="_csrf"]').value;

        function hideAllSections() {
            kop.hide('.account-info');
            kop.hide('.editSection');
            kop.hide('.passportSection');
            kop.hide('.privacySection');
            kop.hide('.notifications');
        }
        hideAllSections();

        // Автооткрытие по URL (без автоматического клика)
        const urlParams = new URLSearchParams(window.location.search);
        const act = urlParams.get('act');
        if (act === 'account-info') {
            kop.hide('.settingsList');
            kop.show('.account-info');
            setTimeout(loadSessions, 100);
        } else if (act === 'edit') {
            kop.hide('.settingsList');
            kop.show('.editSection');
        } else if (act === 'passport') {
            kop.hide('.settingsList');
            kop.show('.passportSection');
        } else if (act === 'privacy') {
            kop.hide('.settingsList');
            kop.show('.privacySection');
        } else if (act === 'notifications') {
            kop.hide('.settingsList');
            kop.show('.notifications');
        } else {
            kop.show('.settingsList');
        }

        // Загрузка сессий
        async function loadSessions() {
            const container = document.getElementById('sessions-list');
            if (!container) return;

            container.innerHTML = '<p style="color:#8b8fa3; text-align:center; padding:10px;">Загрузка...</p>';

            try {
                const res = await fetch('/api/user/sessions', {
                    headers: { 'X-CSRF-Token': window.csrfToken, 'Accept': 'application/json' }
                });
                if (!res.ok) throw new Error('Ошибка');
                const data = await res.json();

                if (data.sessions && data.sessions.length > 0) {
                    container.innerHTML = data.sessions.map(s => {
                        const date = new Date(s.login_time.replace(' ', 'T') + 'Z');
                        const isCurrent = s.is_current;
                        return `<div class="accountRow">
                            <span class="accountIcon" style="background: #f0f0f0; color: #3b5dd3;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                            </span>
                            <div class="accountLabel">
                                <span>${isCurrent ? 'Текущая сессия' : 'Сессия'}</span>
                                <span class="accountValue">${date.toLocaleDateString('ru-RU')}, ${date.toLocaleTimeString('ru-RU', {hour:'2-digit', minute:'2-digit'})}</span>
                            </div>
                            ${isCurrent ? '<span class="accountBadge accountBadge--active">Активна</span>' : ''}
                        </div>`;
                    }).join('');
                } else {
                    container.innerHTML = '<p style="color:#8b8fa3; text-align:center; padding:10px;">Нет данных о сессиях</p>';
                }
            } catch(e) {
                container.innerHTML = '<p style="color:#b91c1c; text-align:center; padding:10px;">Ошибка загрузки</p>';
            }
        }

        // ========== КАСТОМНАЯ МОДАЛКА ДЛЯ ВВОДА ПАРОЛЯ ==========
        function showPasswordModal(title, placeholder) {
            return new Promise((resolve) => {
                const overlay = document.createElement('div');
                overlay.className = 'modal-overlay';
                overlay.style.display = 'flex';
                overlay.innerHTML = `
                    <div class="modal-container" style="max-width:400px; padding:28px 24px;">
                        <span class="modal-close" style="float:right; cursor:pointer; font-size:1.8em; line-height:1; color:#888;">&times;</span>
                        <h3 style="margin:0 0 8px; font-size:1.3em; display:flex; align-items:center; gap:10px;">
                            <span style="background:#e0e7ff; color:#3b5dd3; padding:6px; border-radius:8px; display:inline-flex;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                            </span>
                            ${title}
                        </h3>
                        <p style="color:#6b7280; margin:0 0 20px; font-size:0.9em;">
                            Для подтверждения введите ваш пароль от аккаунта
                        </p>
                        <input type="password" id="unlock-password-input" placeholder="${placeholder}"
                            style="width:100%; padding:12px 16px; border:1px solid #e0e0e0; border-radius:12px;
                                   font-size:1em; font-family:inherit; outline:none; box-sizing:border-box;
                                   margin-bottom:20px; background:#fafafa; transition:border-color 0.2s;"
                            onfocus="this.style.borderColor='#3b5dd3';this.style.background='#fff';"
                            onblur="this.style.borderColor='#e0e0e0';this.style.background='#fafafa';">
                        <div style="display:flex; gap:12px; justify-content:flex-end;">
                            <button class="btn btn--secondary" id="unlock-cancel"
                                style="padding:10px 20px; border-radius:10px; background:#f3f4f6; color:#333; border:none; cursor:pointer; font-weight:500;">
                                Отмена
                            </button>
                            <button class="btn btn--primary" id="unlock-submit"
                                style="padding:10px 20px; border-radius:10px; background:#3b5dd3; color:#fff; border:none; cursor:pointer; font-weight:500;">
                                Разблокировать
                            </button>
                        </div>
                    </div>
                `;
                document.body.appendChild(overlay);

                requestAnimationFrame(() => overlay.classList.add('active'));

                const close = (value) => {
                    overlay.classList.remove('active');
                    setTimeout(() => overlay.remove(), 300);
                    resolve(value);
                };

                const input = overlay.querySelector('#unlock-password-input');
                const submitBtn = overlay.querySelector('#unlock-submit');
                const cancelBtn = overlay.querySelector('#unlock-cancel');
                const closeBtn = overlay.querySelector('.modal-close');

                closeBtn.addEventListener('click', () => close(null));
                cancelBtn.addEventListener('click', () => close(null));

                submitBtn.addEventListener('click', () => {
                    const val = input.value.trim();
                    if (!val) {
                        kop.flash('Введите пароль');
                        input.focus();
                        input.style.borderColor = '#ef4444';
                        setTimeout(() => input.style.borderColor = '#3b5dd3', 1500);
                        return;
                    }
                    close(val);
                });

                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') submitBtn.click();
                    if (e.key === 'Escape') close(null);
                });

                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) close(null);
                });

                setTimeout(() => input.focus(), 150);
            });
        }

        // ========== ПАСПОРТ (серверная логика через API) ==========

        async function unlockPassport() {
            const password = await showPasswordModal('Разблокировка паспорта', 'Введите пароль');
            if (!password) return;

            try {
                const resp = await fetch('/api/user/get-passport', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ password })
                });
                const data = await resp.json();

                if (resp.ok && data.passport) {
                    const overlay = document.getElementById('passport-blur-overlay');
                    if (overlay) {
                        overlay.style.opacity = '0';
                        setTimeout(() => overlay?.remove(), 500);
                    }
                    const unlockBtn = document.querySelector('.btn-unlock');
                    if (unlockBtn) unlockBtn.style.display = 'none';

                    document.getElementById('passport-value-display').textContent = data.passport;
                    kop.flash('Паспорт разблокирован');
                } else {
                    kop.flash(data.error || 'Неверный пароль или паспорт не найден');
                }
            } catch (e) {
                kop.flash('Ошибка соединения');
            }
        }

        function copyPassport() {
            const display = document.getElementById('passport-value-display');
            const passportText = display.textContent.trim();

            if (passportText === 'Заблокировано' || passportText === 'Недоступен') {
                kop.flash('Сначала разблокируйте паспорт');
                return;
            }

            navigator.clipboard.writeText(passportText).then(() => {
                kop.flash('Паспорт скопирован');
            }).catch(err => {
                console.error(err);
                kop.flash('Не удалось скопировать');
            });
        }

        async function generateNewPassport() {
            // Запрашиваем пароль перед генерацией
            const password = await showPasswordModal('Новый паспорт', 'Введите пароль');
            if (!password) return;

            if (!confirm('Вы уверены, что хотите сгенерировать новый паспорт? Старый станет недействительным.')) return;

            try {
                const resp = await fetch('/api/user/generate-passport', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
                    body: JSON.stringify({ password })
                });
                const data = await resp.json();

                if (resp.ok && data.new_passport) {
                    const overlay = document.getElementById('passport-blur-overlay');
                    if (overlay) {
                        overlay.style.opacity = '0';
                        setTimeout(() => overlay?.remove(), 500);
                    }
                    const unlockBtn = document.querySelector('.btn-unlock');
                    if (unlockBtn) unlockBtn.style.display = 'none';

                    document.getElementById('passport-value-display').textContent = data.new_passport;
                    kop.flash('Новый паспорт сгенерирован');
                } else {
                    kop.flash(data.error || 'Ошибка генерации');
                }
            } catch (e) {
                kop.flash('Ошибка соединения');
            }
        }

        // ========== НАВИГАЦИЯ ==========
        kop.on('.settingsList a', 'click', function(e) {
            // Для ссылок без параметра act (Выйти, Управление) не отменяем стандартный переход
            const url = new URL(this.href);
            const act = url.searchParams.get('act');
            if (!act) return; // даём ссылке отработать как обычно

            e.preventDefault();

            kop.hide('.account-info');
            kop.hide('.editSection');
            kop.hide('.passportSection');
            kop.hide('.privacySection');
            kop.hide('.notifications');

            // Скрываем плашку с именем, когда заходим в любую вкладку
            const header = document.querySelector('.accountHeader');
            if (header) header.style.display = 'none';

            if (act === 'account-info') {
                kop.show('.account-info');
                kop.hide('.settingsList');
                loadSessions();
            } else if (act === 'edit') {
                kop.show('.editSection');
                kop.hide('.settingsList');
            } else if (act === 'passport') {
                kop.show('.passportSection');
                kop.hide('.settingsList');
            } else if (act === 'privacy') {
                kop.show('.privacySection');
                kop.hide('.settingsList');
            } else if (act === 'notifications') {
                kop.show('.notifications');
                kop.hide('.settingsList');
            }
        });

        kop.on('.escapeButton', 'click', function(e) {
            e.preventDefault();
            hideAllSections();
            kop.show('.settingsList');

            // Показываем плашку с именем обратно
            const header = document.querySelector('.accountHeader');
            if (header) header.style.display = '';
        });

        // Автосохранение приватности
        kop.on('.privacySection select, .privacySection input[type="checkbox"]', 'change', async function() {
            const name = this.name;
            const value = this.tagName === 'SELECT' ? this.value : this.checked;

            try {
                await kop.post('/api/user/update-privacy', { [name]: value });
                kop.flash('Настройки приватности обновлены');
            } catch (e) {
                kop.flash('Ошибка сохранения', 'error');
            }
        });

        // Автосохранение уведомлений
        kop.on('.notifications input[type="checkbox"]', 'change', async function() {
            try {
                await kop.post('/api/user/update-notifications', { [this.name]: this.checked });
                kop.flash('Настройки уведомлений обновлены');
            } catch (e) {
                kop.flash('Ошибка сохранения', 'error');
            }
        });

        // Предпросмотр аватара
        document.getElementById('avatar-upload').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const avatarContainer = document.querySelector('.accountAvatar');
                    avatarContainer.innerHTML = '';
                    const img = document.createElement('img');
                    img.src = event.target.result;
                    img.style.width = '100%';
                    img.style.height = '100%';
                    img.style.objectFit = 'cover';
                    avatarContainer.appendChild(img);
                };
                reader.readAsDataURL(file);
            }
        });

        if (window.location.search.includes('act=account-info')) {
            setTimeout(loadSessions, 100);
        }
    </script>
</body>
</html>