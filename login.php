<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';
require_guest();

$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF проверка
    if (!hash_equals(csrf_token(), $_POST['_csrf'] ?? '')) {
        $errors['csrf'] = 'Недействительный CSRF-токен';
    } else {
        $rateKey = 'login_' . ($_SERVER['REMOTE_ADDR'] ?? 'global');
        if (!check_rate_limit($rateKey, 5, 600)) {
            $errors['rate'] = 'Слишком много попыток. Попробуйте позже.';
        } else {
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName  = trim($_POST['last_name'] ?? '');
            $password  = $_POST['password'] ?? '';

            if ($firstName === '' || $lastName === '' || $password === '') {
                $errors['auth'] = 'Неверное имя, фамилия или пароль';
                record_attempt($rateKey);
            } else {
                $stmt = db()->prepare("SELECT id, password FROM users WHERE first_name = ? AND last_name = ?");
                $stmt->execute([$firstName, $lastName]);
                $candidates = $stmt->fetchAll();

                $matched = [];
                foreach ($candidates as $user) {
                    if (password_verify($password, $user['password'])) {
                        $matched[] = $user['id'];
                    }
                }

                if (count($matched) === 1) {
                    session_regenerate_id(true);
                    $_SESSION['login_user_id'] = $matched[0];
                    header('Location: /2faauth.php');
                    exit;
                } else {
                    $errors['auth'] = 'Неверное имя, фамилия или пароль';
                    record_attempt($rateKey);
                }
            }
        }
    }

    flash('errors', $errors);
    set_old($_POST);
    header('Location: /login.php');
    exit;
}

$errors = flash('errors') ?? [];
$old    = $_SESSION['_old'] ?? [];
clear_old();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Friendscape — Вход</title>
    <link rel="stylesheet" href="css/main.css">
</head>
<body>
    <div class="loginPageArea">
        <p class="welcome">Добро пожаловать</p>
        <p class="welcome">в Friendscape</p>
        <div class="authLoginSection">
            <form method="post" action="login.php">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

                <input type="text" name="first_name" placeholder="Имя" value="<?= esc($old['first_name'] ?? '') ?>" class="<?= isset($errors['first_name']) ? 'input-error' : '' ?>">
                <input type="text" name="last_name" placeholder="Фамилия" value="<?= esc($old['last_name'] ?? '') ?>" class="<?= isset($errors['last_name']) ? 'input-error' : '' ?>">
                <input type="password" name="password" placeholder="Пароль" class="<?= isset($errors['password']) ? 'input-error' : '' ?>">

                <?php if (isset($errors['rate'])): ?>
                    <span class="error-message"><?= esc($errors['rate']) ?></span>
                <?php elseif (isset($errors['auth'])): ?>
                    <span class="error-message"><?= esc($errors['auth']) ?></span>
                <?php elseif (isset($errors['csrf'])): ?>
                    <span class="error-message"><?= esc($errors['csrf']) ?></span>
                <?php endif; ?>

                <button type="submit">Войти</button>
            </form>
        </div>

        <div class="helpLinks">
            <a href="register.php">Создать аккаунт</a> |
            <a href="recovery.php">Забыли пароль?</a>
        </div>
        <a href="about.html" class="aboutUs">О нас</a>
    </div>
</body>
</html>