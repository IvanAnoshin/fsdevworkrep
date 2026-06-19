<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';
require_auth();

$user = find('users', $_SESSION['user_id']);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), $_POST['_csrf'] ?? '')) {
        $errors['csrf'] = 'Недействительный CSRF-токен';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newSecret = $_POST['new_secret'] ?? '';

        if (!password_verify($currentPassword, $user['password'])) {
            $errors['current_password'] = 'Неверный пароль';
        }
        if ($newSecret === '') {
            $errors['new_secret'] = 'Введите новый секретный ответ';
        } elseif (mb_strlen($newSecret) < 4) {
            $errors['new_secret'] = 'Минимум 4 символа';
        }

        if (empty($errors)) {
            $hashed = password_hash($newSecret, PASSWORD_DEFAULT);
            update('users', $_SESSION['user_id'], ['secret_question' => $hashed]);
            flash('success', 'Секретный ответ обновлён');
            redirect('/settings.php?act=account-info');
        }
    }

    flash('errors', $errors);
    redirect('/change-secret.php');
}

$errors = flash('errors') ?? [];
$success = flash('success');
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сменить секретное слово – Friendscape</title>
    <link rel="stylesheet" href="css/main.css">
</head>
<body>
    <div class="sidebar"><?php require_once "components/header.php"; ?></div>
    <div class="settingsMainArea">
        <div class="accountHeader">
            <p><?= esc($user['first_name'] . ' ' . $user['last_name']) ?></p>
        </div>
        <div class="accountCard" style="max-width:500px; margin: 20px auto;">
            <!-- Кнопка «Назад» -->
            <a href="settings.php?act=account-info" class="escapeButton" style="display:inline-flex; align-items:center; gap:6px; text-decoration:none; margin-bottom:10px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                Назад
            </a>
            <h3 class="accountTitle">Сменить секретный ответ</h3>
            <?php if ($success): ?>
                <div class="alert alert-success" style="background:#d1fae5;color:#059669;padding:10px;border-radius:8px;margin-bottom:10px;"><?= esc($success) ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <div class="editRow">
                    <label class="editLabel">Текущий пароль</label>
                    <input type="password" name="current_password" class="<?= isset($errors['current_password']) ? 'input-error' : '' ?>">
                    <?php if (isset($errors['current_password'])): ?>
                        <span class="error-message"><?= esc($errors['current_password']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="editRow">
                    <label class="editLabel">Новый секретный ответ</label>
                    <input type="password" name="new_secret" class="<?= isset($errors['new_secret']) ? 'input-error' : '' ?>">
                    <?php if (isset($errors['new_secret'])): ?>
                        <span class="error-message"><?= esc($errors['new_secret']) ?></span>
                    <?php endif; ?>
                </div>
                <button type="submit" class="saveBtn">Сохранить</button>
            </form>
        </div>
    </div>
</body>
</html>