<?php

session_start();

if (isset($_SESSION['authenticated'])) {
    header('Location: profile.php');
    exit;
}

$dbHost = 'localhost';
$dbName = 'fsdb';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $password = $_POST['password'] ?? '';

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
        try {
            $pdo = new PDO(
                "mysql:host=$dbHost;dbname=$dbName;charset=$dbCharset",
                $dbUser,
                $dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            $stmt = $pdo->prepare("SELECT id, password FROM users WHERE first_name = ? AND last_name = ?");
            $stmt->execute([$firstName, $lastName]);
            $users = $stmt->fetchAll();

            $matched = [];
            foreach ($users as $user) {
                if (password_verify($password, $user['password'])) {
                    $matched[] = $user['id'];
                }
            }

            if (count($matched) === 1) {
                $_SESSION['login_user_id'] = $matched[0];
                header('Location: 2faauth.php');
                exit;
            } elseif (count($matched) > 1) {
                $errors['auth'] = 'Неоднозначность данных. Свяжитесь с поддержкой.';
            } else {
                $errors['auth'] = 'Неверное имя, фамилия или пароль';
            }
        } catch (PDOException $e) {
            $errors['db'] = 'Ошибка базы данных. Попробуйте позже.';
        }
    }

    // Сохраняем ошибки
    $_SESSION['errors'] = $errors;
    $_SESSION['old'] = [
        'first_name' => $firstName,
        'last_name' => $lastName,
    ];
    header('Location: login.php');
    exit;
}

$errors = $_SESSION['errors'] ?? [];
$old = $_SESSION ['old'] ?? [];
unset($_SESSION['errors'], $_SESSION['old']);

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Friendscape</title>
    <link rel="stylesheet" href="css/main.css">
</head>
<body>
    <div class= "loginPageArea">
        <p class="welcome">Добро пожаловать в Friendscape</p>
        <div class="authLoginSection">
            <form method="post" action="login.php">
                <input
                    type="text"
                    name="first_name"
                    placeholder="Имя"
                    value="<?= htmlspecialchars($old['first_name'] ?? '') ?>"
                    class="<?= isset($errors['first_name']) ? 'input-error' : '' ?>"
                >
                <?php if (isset($errors['first_name'])): ?>
                    <span class="error-message"><?= htmlspecialchars($errors['first_name']) ?></span>
                <?php endif; ?>

                <input
                    type="text"
                    name="last_name"
                    placeholder="Фамилия"
                    value="<?= htmlspecialchars($old['last_name'] ?? '') ?>"
                    class="<?= isset($errors['last_name']) ? 'input-error' : '' ?>"
                >
                <?php if (isset($errors['last_name'])): ?>
                    <span class="error-message"><?= htmlspecialchars($errors['last_name']) ?></span>
                <?php endif; ?>

                <input
                    type="password"
                    name="password"
                    placeholder="Пароль"
                    class="<?= isset($errors['password']) ? 'input-error' : '' ?>"
                >
                <?php if(isset($errors['password'])): ?>
                    <span class="error-message"><?= htmlspecialchars($errors['password']) ?></span>
                <?php endif; ?>

                <?php if (isset($errors['auth'])): ?>
                    <span class="error-message"><?= htmlspecialchars($errors['auth']) ?></span>
                <?php endif; ?>

                <?php if(isset($errors['db'])): ?>
                    <span class="error-message"><?= htmlspecialchars($errors['db']) ?></span>
                <?php endif; ?>

                <button type="submit">Войти</button>
            </form>
        </div>

        <div class="helpLinks">
            <a href="register.php">Создать аккаунт / </a>
            <a href="recovery.php">Забыли пароль?</a>
        </div>
        <a href="about.html" class="aboutUs">О нас</a>
    </div>
</body>
</html>