<?php

session_start();

if (!isset($_SESSION['login_user_id'])) {
    header('Location: login.php');
    exit;
}

$dbHost = 'localhost';
$dbName = 'fsdb';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $secret = $_POST['secret_question'] ?? '';

    if ($secret === '') {
        $errors['secret_question'] = 'Введите секретный ответ';
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
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );

            $stmt = $pdo->prepare("SELECT secret_question FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['login_user_id']]);
            $user = $stmt->fetch();

            if ($user && password_verify($secret, $user['secret_question'])) {
                $_SESSION['authenticated'] = true;
                $_SESSION['user_id'] = $_SESSION['login_user_id'];
                unset($_SESSION['login_user_id']);

                header('Location: profile.php');
                exit;
            } else {
                $errors['secret_question'] = 'Неверный секретный ответ';
            }
        } catch (PDOException $e) {
            $errors['db'] = 'Ошибка базы данных. Попопробуйте позже.';
        }
    }

    $_SESSION['errors'] = $errors;
    header('Location: 2faauth.php');
    exit;
}

$errors = $_SESSION['errors'] ?? [];
unset($_SESSION['errors']);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Двухфакторная авторизация</title>
    <link rel="stylesheet" href="css/main.css">
</head>
<body>
    <div class="twoFactorsMainArea">
        <p>Подтвердите вход</p>
        <form class="secretAnswerForm" method="post" action="2faauth.php">
            <input
                type="password"
                name="secret_question"
                placeholder="Секретный ответ"
                class="<?= isset($errors['secret_question']) ? 'input-error' : '' ?>"
            >
            <?php if (isset($errors['secret_question'])): ?>
                <span class="error-message"><?= htmlspecialchars($errors['secret_question']) ?></span>
            <?php endif; ?>

            <?php if (isset($errors['db'])): ?>
                <span class="error-message"><?= htmlspecialchars($errors['db']) ?></span>
            <?php endif; ?>

            <button type="submit">Войти</button>

        </form>
    </div>
</body>
</html>