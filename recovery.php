<?php
// recovery.php — финальная версия (обновлённая для надёжного алгоритма)
require_once __DIR__ . '/kopilot/kopilot_init.php';
require_guest();

// Очистка просроченных заявок из сессии
if (!empty($_SESSION['pending_request_id'])) {
    $status = scalar("SELECT status FROM recovery_requests WHERE id = ?", [$_SESSION['pending_request_id']]);
    if (!$status || in_array($status, ['completed', 'rejected', 'cancelled_by_owner', 'cancelled_by_friend'])) {
        unset($_SESSION['pending_request_id'], $_SESSION['pending_request_token']);
    }
}

// ---------------------------------------------------------
// АВТОМАТИЧЕСКОЕ ИСПРАВЛЕНИЕ СТРУКТУРЫ ТАБЛИЦ (один раз)
// ---------------------------------------------------------
$tableExists = scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'recovery_requests'");
if ($tableExists) {
    $hasScheduled = scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'recovery_requests' AND column_name = 'scheduled_at'");
    if (!$hasScheduled) {
        db()->exec("ALTER TABLE recovery_requests ADD COLUMN scheduled_at DATETIME NULL DEFAULT NULL");
    }
    $hasFriendId = scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'recovery_requests' AND column_name = 'friend_id'");
    if (!$hasFriendId) {
        db()->exec("ALTER TABLE recovery_requests ADD COLUMN friend_id INT DEFAULT NULL");
    }
    // Добавляем новые статусы
    $currentEnum = scalar("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'recovery_requests' AND column_name = 'status'");
    if ($currentEnum && strpos($currentEnum, 'awaiting_owner_after_friend') === false) {
        // Добавляем новые статусы к существующему ENUM
        $newEnum = str_replace(')', ", 'awaiting_owner_after_friend', 'cancelled_by_owner', 'cancelled_by_friend')", $currentEnum);
        $newEnum = str_replace("ENUM(", "ENUM(", $newEnum);
        db()->exec("ALTER TABLE recovery_requests MODIFY COLUMN status $newEnum NOT NULL DEFAULT 'pending'");
    }
    // Добавляем новые столбцы для отметок времени
    $hasFriendConfirmedAt = scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'recovery_requests' AND column_name = 'friend_confirmed_at'");
    if (!$hasFriendConfirmedAt) {
        db()->exec("ALTER TABLE recovery_requests ADD COLUMN friend_confirmed_at DATETIME NULL DEFAULT NULL");
    }
    $hasOwnerConfirmedAt = scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'recovery_requests' AND column_name = 'owner_confirmed_at'");
    if (!$hasOwnerConfirmedAt) {
        db()->exec("ALTER TABLE recovery_requests ADD COLUMN owner_confirmed_at DATETIME NULL DEFAULT NULL");
    }
    $hasOwnerRejectedAt = scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'recovery_requests' AND column_name = 'owner_rejected_at'");
    if (!$hasOwnerRejectedAt) {
        db()->exec("ALTER TABLE recovery_requests ADD COLUMN owner_rejected_at DATETIME NULL DEFAULT NULL");
    }
} else {
    db()->exec("CREATE TABLE IF NOT EXISTS recovery_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        friend_id INT DEFAULT NULL,
        token VARCHAR(64) NOT NULL,
        status ENUM('pending','approved','rejected','completed','awaiting_owner_after_friend', 'cancelled_by_owner', 'cancelled_by_friend') DEFAULT 'pending',
        new_password_plain VARCHAR(255) DEFAULT NULL,
        new_passport_plain VARCHAR(255) DEFAULT NULL,
        scheduled_at DATETIME NULL DEFAULT NULL,
        friend_confirmed_at DATETIME NULL DEFAULT NULL,
        owner_confirmed_at DATETIME NULL DEFAULT NULL,
        owner_rejected_at DATETIME NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
}

// passport_hash в users (должно быть уже создано)
$hasPassport = scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'passport_hash'");
if (!$hasPassport) {
    db()->exec("ALTER TABLE users ADD COLUMN passport_hash VARCHAR(255) DEFAULT NULL");
}

// ---------------------------------------------------------
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ---------------------------------------------------------
function check_rate_limit(string $key, int $max = 5, int $lock = 300): bool {
    $a = $_SESSION[$key] ?? ['count' => 0, 'last' => 0];
    return !($a['count'] >= $max && time() - $a['last'] < $lock);
}
function record_attempt(string $key): void {
    if (!isset($_SESSION[$key])) $_SESSION[$key] = ['count' => 0, 'last' => 0];
    $_SESSION[$key]['count']++;
    $_SESSION[$key]['last'] = time();
}

function get_top_interlocutor(int $userId): ?array {
    $sql = "SELECT u.id, u.first_name, u.last_name, COUNT(*) as cnt
            FROM messages m
            JOIN chats c ON c.id = m.chat_id
            JOIN users u ON u.id = CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END
            WHERE (c.user1_id = ? OR c.user2_id = ?)
              AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY u.id ORDER BY cnt DESC LIMIT 1";
    return select($sql, [$userId, $userId, $userId])[0] ?? null;
}

function get_all_interlocutors(int $userId): array {
    $sql = "SELECT u.id, u.first_name, u.last_name, u.avatar
            FROM messages m
            JOIN chats c ON c.id = m.chat_id
            JOIN users u ON u.id = CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END
            WHERE (c.user1_id = ? OR c.user2_id = ?)
              AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY u.id ORDER BY u.first_name ASC";
    return select($sql, [$userId, $userId, $userId]);
}

// ---------------------------------------------------------
// ИНИЦИАЛИЗАЦИЯ
// ---------------------------------------------------------
$errors = [];
$user = null;
$newPassword = null;
$newPassport = null;
$pendingId = $_SESSION['pending_request_id'] ?? 0;
$pendingToken = $_SESSION['pending_request_token'] ?? '';

$sessionToken = $_SESSION['recovery_token'] ?? null;
$requestToken = $_POST['recovery_token'] ?? $_GET['token'] ?? null;

if ($sessionToken && (!$requestToken || $requestToken !== $sessionToken)) {
    unset($_SESSION['recovery_user_id'], $_SESSION['recovery_step'], $_SESSION['candidates'], $_SESSION['recovery_token']);
    $sessionToken = null;
    $step = 1;
}

if ($sessionToken && !empty($_SESSION['recovery_user_id'])) {
    $user = find('users', (int)$_SESSION['recovery_user_id']);
    if (!$user) {
        unset($_SESSION['recovery_user_id'], $_SESSION['recovery_step'], $_SESSION['candidates'], $_SESSION['recovery_token']);
        $sessionToken = null;
        $step = 1;
    }
}

$step = $sessionToken ? ($_SESSION['recovery_step'] ?? 1) : 1;
if ($step >= 2 && !$user) { $step = 1; unset($_SESSION['recovery_step']); }

$rateKey = 'recovery_' . ($_SERVER['REMOTE_ADDR'] ?? 'global');
if (!check_rate_limit($rateKey)) {
    $errors['rate'] = 'Слишком много попыток. Попробуйте через 5 минут.';
    $step = 0;
}

// ---------------------------------------------------------
// ОБРАБОТКА POST
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step !== 0) {
    if (!hash_equals(csrf_token(), $_POST['_csrf'] ?? '')) {
        $errors['csrf'] = 'Недействительный CSRF-токен';
    } else {
        $action = $_POST['action'] ?? '';

        // Шаг 1: поиск пользователя
        if ($action === 'check_user') {
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName  = trim($_POST['last_name'] ?? '');
            if ($firstName === '') $errors['first_name'] = 'Введите имя';
            if ($lastName === '')  $errors['last_name']  = 'Введите фамилию';
            if (empty($errors)) {
                $stmt = db()->prepare("SELECT id, first_name, last_name, avatar, city, hometown, country FROM users WHERE first_name LIKE ? AND last_name LIKE ?");
                $stmt->execute(["%$firstName%", "%$lastName%"]);
                $candidates = $stmt->fetchAll();
                if (count($candidates) === 0) {
                    $errors['auth'] = 'Пользователь не найден';
                    record_attempt($rateKey);
                } elseif (count($candidates) === 1) {
                    $_SESSION['recovery_token'] = bin2hex(random_bytes(16));
                    $_SESSION['recovery_user_id'] = $candidates[0]['id'];
                    $_SESSION['recovery_step'] = 2;
                    header('Location: /recovery.php?token=' . $_SESSION['recovery_token']);
                    exit;
                } else {
                    $_SESSION['recovery_token'] = bin2hex(random_bytes(16));
                    $_SESSION['candidates'] = $candidates;
                    $_SESSION['recovery_step'] = 2;
                    header('Location: /recovery.php?token=' . $_SESSION['recovery_token']);
                    exit;
                }
            } else { record_attempt($rateKey); }
        }

        // Выбор из нескольких аккаунтов
        elseif ($action === 'select_user' && $sessionToken) {
            if ($_POST['recovery_token'] !== $sessionToken) { $errors['token'] = 'Недействительный токен'; }
            else {
                $selectedId = (int)($_POST['user_id'] ?? 0);
                $candidates = $_SESSION['candidates'] ?? [];
                $found = false;
                foreach ($candidates as $c) if ($c['id'] === $selectedId) { $found = true; break; }
                if ($found) {
                    $_SESSION['recovery_user_id'] = $selectedId;
                    $_SESSION['recovery_step'] = 2;
                    unset($_SESSION['candidates']);
                    header('Location: /recovery.php?token=' . $sessionToken);
                    exit;
                } else { $errors['auth'] = 'Аккаунт не найден'; record_attempt($rateKey); }
            }
        }

        // Шаг 2: секретный ответ
        elseif ($action === 'verify_secret' && $user && $sessionToken) {
            if ($_POST['recovery_token'] !== $sessionToken) { $errors['token'] = 'Недействительный токен'; }
            else {
                $answer = $_POST['secret_answer'] ?? '';
                if (!empty($user['secret_question']) && password_verify($answer, $user['secret_question'])) {
                    $newPassword = bin2hex(random_bytes(4));
                    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                    db()->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $user['id']]);
                    unset($_SESSION['recovery_user_id'], $_SESSION['recovery_step'], $_SESSION['candidates'], $_SESSION['recovery_token']);
                    $step = 10;
                } else { $errors['secret_answer'] = 'Неверный секретный ответ'; record_attempt($rateKey); }
            }
        }

        // Пропуск секретного ответа
        elseif ($action === 'skip_secret' && $user && $sessionToken) {
            if ($_POST['recovery_token'] !== $sessionToken) { $errors['token'] = 'Недействительный токен'; }
            else {
                $_SESSION['recovery_step'] = 3;
                header('Location: /recovery.php?token=' . $sessionToken);
                exit;
            }
        }

// Шаг 3: проверка паспорта
elseif ($action === 'verify_passport' && $user && $sessionToken) {
    if ($_POST['recovery_token'] !== $sessionToken) { 
        $errors['token'] = 'Недействительный токен'; 
    } else {
        $passport = $_POST['passport'] ?? '';
        if (!empty($user['passport_hash']) && password_verify($passport, $user['passport_hash'])) {
            // ✅ УСПЕХ: генерируем новый пароль
            $newPassword = bin2hex(random_bytes(4));
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            db()->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $user['id']]);
            
            // ✅ Генерируем новый паспорт (старый скомпрометирован)
            $newPassport = bin2hex(random_bytes(8));
            $newPassportHash = password_hash($newPassport, PASSWORD_DEFAULT);
            db()->prepare("UPDATE users SET passport_hash = ? WHERE id = ?")->execute([$newPassportHash, $user['id']]);
            
            // ✅ Сохраняем для отображения на шаге 10
            $_SESSION['recovery_new_password'] = $newPassword;
            $_SESSION['recovery_new_passport'] = $newPassport;
            
            unset($_SESSION['recovery_user_id'], $_SESSION['recovery_step'], $_SESSION['candidates'], $_SESSION['recovery_token']);
            $step = 10;
        } else { 
            $errors['passport'] = 'Неверный номер паспорта'; 
            record_attempt($rateKey); 
        }
    }
}

        // Пропуск паспорта
        elseif ($action === 'skip_passport' && $user && $sessionToken) {
            if ($_POST['recovery_token'] !== $sessionToken) { $errors['token'] = 'Недействительный токен'; }
            else {
                $_SESSION['recovery_step'] = 5;
                header('Location: /recovery.php?token=' . $sessionToken);
                exit;
            }
        }

        // Шаг 5: вопрос о собеседнике (ОБНОВЛЕНО)
        elseif ($action === 'dynamic_question' && $user) {
            $selectedId = (int)($_POST['interlocutor_id'] ?? 0);
            $top = get_top_interlocutor($user['id']);
            if ($top && $top['id'] === $selectedId) {
                $token = bin2hex(random_bytes(16));
                db()->prepare("INSERT INTO recovery_requests (user_id, friend_id, token, status) VALUES (?, ?, ?, 'pending')")
                    ->execute([$user['id'], $top['id'], $token]);
                $requestId = db()->lastInsertId();

                // --- ОБНОВЛЁННАЯ ЛОГИКА: Отправка уведомлений и владельцу, и другу ---
                $requestDetails = json_encode([
                    'request_id' => $requestId,
                    'token' => $token,
                    'initiator_name' => $user['first_name'] . ' ' . $user['last_name']
                ]);

                // Уведомление владельцу (новый тип)
                db()->prepare("INSERT INTO notifications (user_id, type, actor_id, extra, is_read, created_at)
                    VALUES (?, 'recovery_request_initial', NULL, ?, 0, NOW())")
                    ->execute([$user['id'], $requestDetails]);

                // Уведомление другу
                db()->prepare("INSERT INTO notifications (user_id, type, actor_id, extra, is_read, created_at)
                    VALUES (?, 'recovery_alert', NULL, ?, 0, NOW())")
                    ->execute([
                        $top['id'],
                        json_encode([
                            'request_id' => $requestId,
                            'token' => $token,
                            'owner_name' => $user['first_name'] . ' ' . $user['last_name'],
                            'requires_owner_confirmation' => true // Флаг для интерфейса
                        ])
                    ]);

                // --- КОНЕЦ ОБНОВЛЁННОЙ ЛОГИКИ ---

                $_SESSION['pending_request_id'] = $requestId;
                $_SESSION['pending_request_token'] = $token;
                $_SESSION['recovery_step'] = 6; // Ожидание
                header('Location: /recovery.php?token=' . $_SESSION['recovery_token']);
                exit;
            } else {
                $_SESSION['recovery_step'] = 7;
                header('Location: /recovery.php?token=' . $_SESSION['recovery_token']);
                exit;
            }
        }
    }
}

if ($sessionToken && isset($_SESSION['recovery_step'])) { $step = $_SESSION['recovery_step']; }
$candidates = $_SESSION['candidates'] ?? [];

$allInterlocutors = [];
if ($step === 5 && $user) {
    $allInterlocutors = get_all_interlocutors($user['id']);
    if (empty($allInterlocutors)) {
        $_SESSION['recovery_no_interlocutors'] = true; // Флаг для шага 7
        $_SESSION['recovery_step'] = 7;
        header('Location: /recovery.php?token=' . $sessionToken);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Восстановление пароля – Friendscape</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        /* ... (стили остаются прежними) ... */
        body { background: #f0f2f5; }
        .loginPageArea { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .welcome { color: #3b5dd3; font-size: 2.5em; font-weight: 600; margin-bottom: 30px; }
        .authLoginSection { width: 100%; max-width: 420px; background: #fff; border-radius: 20px; padding: 40px 30px; box-shadow: 0 12px 40px rgba(0,0,0,0.08); text-align: center; display: flex; flex-direction: column; gap: 16px; }
        .authLoginSection p { margin: 0; color: #1e1e2f; font-size: 0.95em; }
        .authLoginSection form { display: flex; flex-direction: column; gap: 12px; }
        .authLoginSection input[type="text"], .authLoginSection input[type="password"] { width: 100%; padding: 12px 16px; border: 1px solid #e0e0e0; border-radius: 12px; font-size: 1em; background: #fafafa; outline: none; transition: border-color 0.2s; box-sizing: border-box; }
        .authLoginSection input:focus { border-color: #7c3aed; background: #fff; }
        .authLoginSection button { background: #3b5dd3; color: white; border: none; padding: 12px 24px; border-radius: 12px; font-size: 1em; font-weight: 500; cursor: pointer; transition: background 0.2s; }
        .authLoginSection button:hover { background: #2f4bb5; }
        .authLoginSection .btn--secondary { background: transparent; color: #3b5dd3; border: 1px solid #3b5dd3; }
        .authLoginSection .btn--secondary:hover { background: #f0f4ff; }
        .error-message { color: #e53e3e; font-size: 0.85em; }
        .input-error { border-color: #e53e3e !important; }
        .helpLinks { margin-top: 24px; font-size: 0.95em; color: #5a6072; }
        .helpLinks a { color: #3b5dd3; text-decoration: none; }
        .candidate-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px; margin-bottom: 10px; display: flex; align-items: center; gap: 12px; transition: background 0.2s; }
        .candidate-card:hover { background: #f0f2f5; }
        .autocomplete-wrapper { position: relative; }
        .autocomplete-items { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; max-height: 220px; overflow-y: auto; z-index: 10; box-shadow: 0 8px 24px rgba(0,0,0,0.1); display: none; }
        .autocomplete-item { padding: 12px 16px; cursor: pointer; border-bottom: 1px solid #f0f2f5; display: flex; align-items: center; gap: 12px; transition: background 0.1s; }
        .autocomplete-item:last-child { border-bottom: none; }
        .autocomplete-item:hover { background: #f0f4ff; }
        .autocomplete-item img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; background: #f0f0f0; }
        .autocomplete-item .placeholder-avatar { width: 40px; height: 40px; border-radius: 50%; background: #e0e7ff; color: #3b5dd3; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 1rem; }
        #pending-screen { text-align: center; }
        #pending-screen button { background: #3b5dd3; color: white; border: none; padding: 10px 20px; border-radius: 8px; margin-top: 10px; cursor: pointer; }
        .secret-question-text { font-weight: 500; margin-bottom: 10px; }
        .loader-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.6); backdrop-filter: blur(2px); display: flex; align-items: center; justify-content: center; z-index: 9999; }
        .loader-spinner { width: 48px; height: 48px; border: 5px solid rgba(59,93,211,0.2); border-top: 5px solid #3b5dd3; border-radius: 50%; animation: spin 0.7s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="loginPageArea">
    <p class="welcome">Восстановление пароля</p>
    <div class="authLoginSection">

<?php if ($step === 0): ?>
    <p class="error-message"><?= esc($errors['rate'] ?? '') ?></p>

<?php elseif ($step === 1): ?>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="check_user">
        <input type="text" name="first_name" placeholder="Имя" value="<?= esc($_POST['first_name'] ?? '') ?>" class="<?= isset($errors['first_name']) ? 'input-error' : '' ?>">
        <?php if (isset($errors['first_name'])): ?><span class="error-message"><?= esc($errors['first_name']) ?></span><?php endif; ?>
        <input type="text" name="last_name" placeholder="Фамилия" value="<?= esc($_POST['last_name'] ?? '') ?>" class="<?= isset($errors['last_name']) ? 'input-error' : '' ?>">
        <?php if (isset($errors['last_name'])): ?><span class="error-message"><?= esc($errors['last_name']) ?></span><?php endif; ?>
        <?php if (isset($errors['auth'])): ?><span class="error-message"><?= esc($errors['auth']) ?></span><?php endif; ?>
        <button type="submit">Продолжить</button>
    </form>

<?php elseif ($step === 2 && empty($user) && !empty($candidates)): ?>
    <p>Найдено несколько аккаунтов. Выберите ваш:</p>
    <?php foreach ($candidates as $c): ?>
        <div class="candidate-card">
            <?php if (!empty($c['avatar'])): ?><img src="<?= esc($c['avatar']) ?>" style="width:48px;height:48px;border-radius:50%;object-fit:cover;"><?php else: ?><span style="width:48px;height:48px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#f0f2f5;font-weight:600;color:#3b5dd3;"><?= esc(mb_substr($c['first_name'],0,1).mb_substr($c['last_name'],0,1)) ?></span><?php endif; ?>
            <div><strong><?= esc($c['first_name'].' '.$c['last_name']) ?></strong><?php if (!empty($c['city'])): ?><br><small><?= esc($c['city']) ?></small><?php endif; ?><?php if (!empty($c['country'])): ?><small> (<?= esc($c['country']) ?>)</small><?php endif; ?></div>
            <form method="post" style="margin-left:auto;">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="select_user">
                <input type="hidden" name="user_id" value="<?= $c['id'] ?>">
                <input type="hidden" name="recovery_token" value="<?= esc($sessionToken) ?>">
                <button type="submit">Это я</button>
            </form>
        </div>
    <?php endforeach; ?>

<?php elseif ($step === 2 && $user): ?>
    <p>Аккаунт: <strong><?= esc($user['first_name'].' '.$user['last_name']) ?></strong></p>
    <?php if (!empty($user['secret_question'])): ?>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="verify_secret">
            <input type="hidden" name="recovery_token" value="<?= esc($sessionToken) ?>">
            <input type="password" name="secret_answer" placeholder="Секретный ответ" class="<?= isset($errors['secret_answer']) ? 'input-error' : '' ?>">
            <?php if (isset($errors['secret_answer'])): ?><span class="error-message"><?= esc($errors['secret_answer']) ?></span><?php endif; ?>
            <button type="submit">Восстановить</button>
        </form>
        <p style="text-align:center;margin-top:12px;"><button type="submit" form="skip-secret-form" style="background:none;border:none;color:#3b5dd3;cursor:pointer;text-decoration:underline;">Не помню секретный ответ</button></p>
    <?php else: ?>
        <p>У вас не установлен секретный ответ.</p>
        <form method="post" id="skip-secret-form" style="margin-top:16px;">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="skip_secret">
            <input type="hidden" name="recovery_token" value="<?= esc($sessionToken) ?>">
            <button type="submit" class="btn--secondary">Продолжить без секретного ответа</button>
        </form>
    <?php endif; ?>
    <form method="post" id="skip-secret-form">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="skip_secret">
        <input type="hidden" name="recovery_token" value="<?= esc($sessionToken) ?>">
    </form>

<?php elseif ($step === 3 && $user): ?>
    <p>Аккаунт: <strong><?= esc($user['first_name'].' '.$user['last_name']) ?></strong></p>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="verify_passport">
        <input type="hidden" name="recovery_token" value="<?= esc($sessionToken) ?>">
        <input type="text" name="passport" placeholder="Friendscape Паспорт" value="<?= esc($_POST['passport'] ?? '') ?>" class="<?= isset($errors['passport']) ? 'input-error' : '' ?>">
        <?php if (isset($errors['passport'])): ?><span class="error-message"><?= esc($errors['passport']) ?></span><?php endif; ?>
        <button type="submit">Продолжить</button>
    </form>
    <p style="text-align:center;margin-top:12px;"><button type="submit" form="skip-passport-form" style="background:none;border:none;color:#3b5dd3;cursor:pointer;text-decoration:underline;">Не знаю номер Friendscape Паспорта</button></p>
    <form method="post" id="skip-passport-form">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="skip_passport">
        <input type="hidden" name="recovery_token" value="<?= esc($sessionToken) ?>">
    </form>

<?php elseif ($step === 5 && $user): ?>
    <p>Аккаунт: <strong><?= esc($user['first_name'].' '.$user['last_name']) ?></strong></p>
    <p>Введите имя собеседника, с которым вы общались чаще всего за последние 30 дней:</p>
    <form method="post" id="dynamic-form">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="dynamic_question">
        <input type="hidden" name="recovery_token" value="<?= esc($sessionToken) ?>">
        <div class="autocomplete-wrapper">
            <input type="text" id="interlocutor-search" placeholder="Начните вводить имя..." autocomplete="off" class="<?= isset($errors['dynamic']) ? 'input-error' : '' ?>">
            <input type="hidden" name="interlocutor_id" id="interlocutor-id">
            <div id="autocomplete-list" class="autocomplete-items"></div>
        </div>
        <?php if (isset($errors['dynamic'])): ?><span class="error-message"><?= esc($errors['dynamic']) ?></span><?php endif; ?>
        <button type="submit" style="display:none;" id="dynamic-submit-btn">Подтвердить</button>
    </form>
    <script>
    const interlocutors = <?= json_encode(array_map(function($u) {
        return ['id' => $u['id'], 'name' => $u['first_name'] . ' ' . $u['last_name'], 'avatar' => $u['avatar'] ?? ''];
    }, $allInterlocutors)) ?>;
    const searchInput = document.getElementById('interlocutor-search');
    const hiddenInput = document.getElementById('interlocutor-id');
    const list = document.getElementById('autocomplete-list');
    const submitBtn = document.getElementById('dynamic-submit-btn');
    const form = document.getElementById('dynamic-form');

    searchInput.addEventListener('input', function() {
        const val = this.value.trim().toLowerCase();
        list.innerHTML = '';
        if (!val) { list.style.display = 'none'; return; }
        const matches = interlocutors.filter(u => u.name.toLowerCase().includes(val));
        if (matches.length === 0) { list.style.display = 'none'; return; }
        matches.forEach(u => {
            const div = document.createElement('div');
            div.className = 'autocomplete-item';
            if (u.avatar) {
                const img = document.createElement('img');
                img.src = u.avatar;
                div.appendChild(img);
            } else {
                const placeholder = document.createElement('span');
                placeholder.className = 'placeholder-avatar';
                placeholder.textContent = u.name.charAt(0).toUpperCase();
                div.appendChild(placeholder);
            }
            const span = document.createElement('span');
            span.textContent = u.name;
            div.appendChild(span);
            div.addEventListener('click', function() {
                searchInput.value = u.name;
                hiddenInput.value = u.id;
                list.style.display = 'none';
                const overlay = document.createElement('div');
                overlay.className = 'loader-overlay';
                overlay.id = 'recovery-loader';
                const spinner = document.createElement('div');
                spinner.className = 'loader-spinner';
                overlay.appendChild(spinner);
                document.body.appendChild(overlay);
                setTimeout(() => { submitBtn.click(); }, 100);
            });
            list.appendChild(div);
        });
        list.style.display = 'block';
    });

    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !list.contains(e.target)) { list.style.display = 'none'; }
    });

    form.addEventListener('submit', function(e) {
        if (!hiddenInput.value) { e.preventDefault(); alert('Пожалуйста, выберите собеседника из списка.'); }
    });
    </script>

<?php elseif ($step === 6): ?>
    <div id="pending-screen">
        <p>Запрос отправлен. Ожидайте подтверждения от вас или вашего друга.</p>
        <p>После подтверждения доступ будет восстановлен.</p>
        <p id="pending-status">Статус: ожидание...</p>
        <button onclick="checkStatus()">Проверить статус</button>
    </div>
    <script>
        async function checkStatus() {
            let resp = await fetch('/api/recovery/status?request_id=<?= $pendingId ?>&token=<?= $pendingToken ?>', {
                headers: {'X-CSRF-Token': '<?= csrf_token() ?>'}
            });
            let data = await resp.json();
            const screen = document.getElementById('pending-screen');
            if (data.status === 'completed') {
                screen.innerHTML = `<p style="color:#059669;">Доступ восстановлен!</p><p>Новый пароль: <strong>${esc(data.password)}</strong></p><p>Новый цифровой паспорт: <strong>${esc(data.passport)}</strong></p><p><a href="login.php">Войти</a></p>`;
            } else if (data.status === 'awaiting_owner_after_friend') {
                screen.innerHTML = `<p>Ваш друг подтвердил запрос. Доступ будет восстановлен через 24 часа, если вы не отмените.</p><p>Если это не вы, отмените восстановление через уведомления (колокольчик в меню).</p><button onclick="if(window.toggleNotificationsDropdown) toggleNotificationsDropdown();" style="background:#3b5dd3; color:#fff; border:none; padding:10px 20px; border-radius:8px; margin-top:10px; cursor:pointer;">Открыть уведомления</button>`;
            } else if (data.status === 'rejected' || data.status === 'cancelled_by_owner' || data.status === 'cancelled_by_friend') {
                screen.innerHTML = `<p style="color:#b91c1c;">Запрос отклонён или отменён.</p><a href="login.php">← Назад</a>`;
            } else {
                document.getElementById('pending-status').textContent = 'Статус: ожидание подтверждения.';
            }
        }
        function esc(s) { return s.replace(/[&<>]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;'})[m]); }
    </script>

<?php elseif ($step === 7): ?>
<div style="text-align:center;padding:20px;">
    <?php if (!empty($_SESSION['recovery_no_interlocutors'])): ?>
        <p style="color:#b91c1c;font-size:1.1em;margin-bottom:12px;">Не удалось подтвердить личность</p>
        <p style="color:#5a6072;margin-bottom:16px;">
            У нас недостаточно данных для восстановления доступа этим способом. 
            У вас нет недавних собеседников, которых мы могли бы спросить.
        </p>
        <p style="color:#6c757d;font-size:0.9em;">
            Попробуйте восстановить доступ через <strong>Friendscape Паспорт</strong> или <strong>секретный ответ</strong>.
        </p>
        <?php unset($_SESSION['recovery_no_interlocutors']); ?>
    <?php else: ?>
        <p style="color:#b91c1c;">Мы не можем быть уверены, что этот аккаунт принадлежит вам.</p>
    <?php endif; ?>
    <p style="margin-top:20px;"><a href="recovery.php" style="color:#3b5dd3;">← Начать восстановление заново</a></p>
</div>

<?php elseif ($step === 10): ?>
<div style="text-align:center;padding:20px;">
    <p style="color:#059669;font-size:1.3em;font-weight:600;margin-bottom:20px;">✓ Доступ восстановлен!</p>
    
    <p style="color:#5a6072;margin-bottom:8px;">Ваш новый пароль:</p>
    <p style="font-size:1.5em;letter-spacing:2px;background:#f0f0f0;padding:12px;border-radius:8px;font-family:'Courier New',monospace;font-weight:600;">
        <?= esc($_SESSION['recovery_new_password'] ?? $newPassword ?? '') ?>
    </p>
    
    <?php if (!empty($_SESSION['recovery_new_passport'])): ?>
    <p style="color:#5a6072;margin:20px 0 8px;">Ваш новый Friendscape Паспорт:</p>
    <p style="font-size:1.2em;letter-spacing:2px;background:#e0e7ff;color:#3b5dd3;padding:12px;border-radius:8px;font-family:'Courier New',monospace;font-weight:600;">
        <?= esc($_SESSION['recovery_new_passport']) ?>
    </p>
    <p style="color:#6c757d;font-size:0.85em;margin-top:8px;">
        🔒 Сохраните его в надёжном месте — он понадобится для восстановления доступа
    </p>
    <?php endif; ?>
    
    <p style="margin-top:24px;">
        <a href="login.php" style="display:inline-block;background:#3b5dd3;color:white;padding:12px 32px;border-radius:12px;text-decoration:none;font-weight:500;">Войти в аккаунт</a>
    </p>
</div>
<?php 
unset($_SESSION['recovery_new_password'], $_SESSION['recovery_new_passport']);
?>

<?php else: ?>
    <p>Что-то пошло не так. <a href="recovery.php">Начать заново</a></p>
<?php endif; ?>

    </div>
    <div class="helpLinks">
        <a href="login.php">Войти</a> |
        <a href="register.php">Регистрация</a>
    </div>
</div>
</body>
</html>