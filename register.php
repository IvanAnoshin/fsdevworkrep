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
        $firstName = mb_convert_case(trim($_POST['first_name'] ?? ''), MB_CASE_TITLE, "UTF-8");
        $lastName = mb_convert_case(trim($_POST['last_name'] ?? ''), MB_CASE_TITLE, "UTF-8");
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';
        $gender = $_POST['gender'] ?? '';

        // Проверка уникальности имени и фамилии
        if ($firstName === '') $errors['first_name'] = 'Введите имя';
        if ($lastName === '') $errors['last_name'] = 'Введите фамилию';
        if (!in_array($gender, ['male', 'female'])) $errors['gender'] = 'Выберите пол';
        if (mb_strlen($password) < 6) $errors['password'] = 'Пароль должен быть не менее 6 символов';
        if ($password !== $password2) $errors['password2'] = 'Пароли не совпадают';

        if (empty($errors)) {
            $exists = scalar(
                "SELECT COUNT(*) FROM users WHERE first_name = ? AND last_name = ?",
                [$firstName, $lastName]
            );
            if ($exists > 0) {
                $errors['first_name'] = 'Пользователь с таким именем и фамилией уже существует';
            }
        }

        if (empty($errors)) {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $userId = insert('users', [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'password' => $hashedPassword,
                'gender' => $gender
            ]);

            // Генерация Friendscape Паспорта
            $newRawPassport = bin2hex(random_bytes(8));
            $newHashedPassport = password_hash($newRawPassport, PASSWORD_DEFAULT);
            update('users', $userId, [
                'passport_hash' => $newHashedPassport,
                'passport_raw' => $newRawPassport
            ]);

            // === ИНТЕГРАЦИЯ DFSN ===
            require_once __DIR__ . '/dfsn.php';
            $dfsn = new DFSN();
            // Инициализируем веса для нового пользователя
            $dfsn->calculateUserWeights($userId);
            // Также сразу строим начальный вектор интересов (пустой, но валидный)
            $dfsn->updateInterestVector($userId);
            // ========================

            $_SESSION['user_id'] = $userId;
            header('Location: 2fa.php');
            exit;
        }
    }

    // Сохраняем ошибки и старые значения
    flash('errors', $errors);
    set_old($_POST);
    header('Location: register.php');
    exit;
}

$errors = flash('errors') ?? [];
$old = $_SESSION['_old'] ?? [];
clear_old();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Friendscape — Регистрация</title>
    <link rel="stylesheet" href="css/main.css">
</head>
<body>
    <div class="registerPageArea">
        <p class="welcome">Регистрация</p>
        <div class="authLoginSection">
            <form method="post" action="register.php">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                
                <input type="text" name="first_name" placeholder="Имя" value="<?= esc($old['first_name'] ?? '') ?>" class="<?= isset($errors['first_name']) ? 'input-error' : '' ?>">
                <?php if (isset($errors['first_name'])): ?><span class="error-message"><?= esc($errors['first_name']) ?></span><?php endif; ?>
                
                <input type="text" name="last_name" placeholder="Фамилия" value="<?= esc($old['last_name'] ?? '') ?>" class="<?= isset($errors['last_name']) ? 'input-error' : '' ?>">
                <?php if (isset($errors['last_name'])): ?><span class="error-message"><?= esc($errors['last_name']) ?></span><?php endif; ?>
                
                <input type="password" name="password" placeholder="Пароль" class="<?= isset($errors['password']) ? 'input-error' : '' ?>">
                <?php if (isset($errors['password'])): ?><span class="error-message"><?= esc($errors['password']) ?></span><?php endif; ?>
                
                <input type="password" name="password2" placeholder="Повторите пароль" class="<?= isset($errors['password2']) ? 'input-error' : '' ?>">
                <?php if (isset($errors['password2'])): ?><span class="error-message"><?= esc($errors['password2']) ?></span><?php endif; ?>
                
                <div class="sex">
                    <fieldset>
                        <legend>Ваш пол: </legend>
                        <label><input type="radio" name="gender" value="male" <?= ($old['gender'] ?? '') === 'male' ? 'checked' : '' ?>> Мужчина</label>
                        <label><input type="radio" name="gender" value="female" <?= ($old['gender'] ?? '') === 'female' ? 'checked' : '' ?>> Женщина</label>
                    </fieldset>
                </div>
                
                <?php if (isset($errors['csrf'])): ?><span class="error-message"><?= esc($errors['csrf']) ?></span><?php endif; ?>
                <?php if (isset($errors['db'])): ?><span class="error-message"><?= esc($errors['db']) ?></span><?php endif; ?>
                
                <button type="submit">Далее</button>
            </form>
        </div>
        <div class="helpLinks">
            <a href="login.php">Войти</a> |
            <a href="recovery.php">Забыли пароль?</a>
        </div>
        <a href="about.html" class="aboutUs">О нас</a>
    </div>
</body>
</html>