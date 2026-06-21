<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';

// Если не передан login_user_id — значит, пользователь не прошёл первый этап (логин/пароль)
if (!isset($_SESSION['login_user_id'])) {
    header('Location: /login.php');
    exit;
}

$userId = (int) $_SESSION['login_user_id'];
$errors = [];

// Получаем пользователя и проверяем, задан ли секретный вопрос
$user = find('users', $userId);
$hasSecret = $user && !empty($user['secret_question']);

// Если секретный вопрос не задан, сразу пропускаем
if (!$hasSecret) {
    // Завершаем аутентификацию без проверки
    $_SESSION['authenticated'] = true;
    $_SESSION['user_id'] = $userId;
    $_SESSION['login_time'] = date('Y-m-d H:i:s');
    unset($_SESSION['login_user_id']);

    // Сохраняем сессию в БД для отображения в настройках
    db()->prepare("INSERT INTO user_sessions (user_id, login_time) VALUES (?, ?)")
        ->execute([$userId, $_SESSION['login_time']]);

    header('Location: /preloader.php');
    exit;
}

// Если секретный вопрос задан — обрабатываем POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF проверка
    if (!hash_equals(csrf_token(), $_POST['_csrf'] ?? '')) {
        $errors['csrf'] = 'Недействительный CSRF-токен';
    } else {
        $secretAnswer = $_POST['secret_answer'] ?? '';

        if ($secretAnswer === '') {
            $errors['secret_answer'] = 'Введите секретный ответ';
        } else {
            // Проверяем секретный ответ
            if ($user && !empty($user['secret_question']) && password_verify($secretAnswer, $user['secret_question'])) {
                // Успешная двухфакторная аутентификация
                session_regenerate_id(true); // защита от session fixation
                $_SESSION['authenticated'] = true;
                $_SESSION['user_id'] = $userId;
                $_SESSION['login_time'] = date('Y-m-d H:i:s');
                unset($_SESSION['login_user_id']);

                // Сохраняем сессию в БД для отображения в настройках
                db()->prepare("INSERT INTO user_sessions (user_id, login_time) VALUES (?, ?)")
                    ->execute([$userId, $_SESSION['login_time']]);

                header('Location: /preloader.php');
                exit;
            } else {
                $errors['secret_answer'] = 'Неверный секретный ответ';
            }
        }
    }

    // Сохраняем ошибки во флеш и редиректим обратно на эту же страницу
    flash('errors', $errors);
    header('Location: /2faauth.php');
    exit;
}

// Извлекаем ошибки из флеш (если были)
$errors = flash('errors') ?? [];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Двухфакторная авторизация — Friendscape</title>
    <link rel="stylesheet" href="css/main.css">
</head>
<body>
    <div class="twoFactorsMainArea">
        <p>Подтвердите вход</p>
        <form class="secretAnswerForm" method="post" action="/2faauth.php">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input
                type="password"
                name="secret_answer"
                placeholder="Секретный ответ"
                class="<?= isset($errors['secret_answer']) ? 'input-error' : '' ?>"
                autocomplete="off"
            >
            <?php if (isset($errors['secret_answer'])): ?>
                <span class="error-message"><?= esc($errors['secret_answer']) ?></span>
            <?php endif; ?>
            <?php if (isset($errors['csrf'])): ?>
                <span class="error-message"><?= esc($errors['csrf']) ?></span>
            <?php endif; ?>
            <button type="submit">Войти</button>
        </form>
    </div>
</body>
</html>