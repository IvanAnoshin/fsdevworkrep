<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';
require_guest(); // если уже залогинен, перенаправляем

$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF проверка
    if (!hash_equals(csrf_token(), $_POST['_csrf'] ?? '')) {
        $errors['csrf'] = 'Недействительный CSRF-токен';
    } else {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $password  = $_POST['password'] ?? '';

        if ($firstName === '') {
            $errors['first_name'] = 'Введите имя';
        }
        if ($lastName === '') {
            $errors['last_name'] = 'Введите фамилию';
        }
        if ($password === '') {
            $errors['password'] = 'Введите пароль';
        }

        if (empty($errors)) {
            // Ищем пользователя по имени и фамилии
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
                // Успешная аутентификация – переходим к двухфакторной проверке
                $_SESSION['login_user_id'] = $matched[0];
                header('Location: /2faauth.php');
                exit;
            } elseif (count($matched) > 1) {
                $errors['auth'] = 'Неоднозначность данных. Свяжитесь с поддержкой.';
            } else {
                $errors['auth'] = 'Неверное имя, фамилия или пароль';
            }
        }
    }

    // Сохраняем ошибки и старые значения
    flash('errors', $errors);
    set_old($_POST);
    header('Location: /login.php');
    exit;
}

// Извлекаем ошибки и старые значения из флеш/сессии
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

                <input
                    type="text"
                    name="first_name"
                    placeholder="Имя"
                    value="<?= esc($old['first_name'] ?? '') ?>"
                    class="<?= isset($errors['first_name']) ? 'input-error' : '' ?>"
                >
                <?php if (isset($errors['first_name'])): ?>
                    <span class="error-message"><?= esc($errors['first_name']) ?></span>
                <?php endif; ?>

                <input
                    type="text"
                    name="last_name"
                    placeholder="Фамилия"
                    value="<?= esc($old['last_name'] ?? '') ?>"
                    class="<?= isset($errors['last_name']) ? 'input-error' : '' ?>"
                >
                <?php if (isset($errors['last_name'])): ?>
                    <span class="error-message"><?= esc($errors['last_name']) ?></span>
                <?php endif; ?>

                <input
                    type="password"
                    name="password"
                    placeholder="Пароль"
                    class="<?= isset($errors['password']) ? 'input-error' : '' ?>"
                >
                <?php if (isset($errors['password'])): ?>
                    <span class="error-message"><?= esc($errors['password']) ?></span>
                <?php endif; ?>

                <?php if (isset($errors['auth'])): ?>
                    <span class="error-message"><?= esc($errors['auth']) ?></span>
                <?php endif; ?>
                <?php if (isset($errors['csrf'])): ?>
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