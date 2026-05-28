<?php

session_start();

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    header('Location: register.php');
    exit;
}

$dbHost = 'localhost';
$dbName = 'fsdb';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

$errors = [];

// Если форма отправлена
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $secret = $_POST['secret_question'] ?? '';

    // Валидация
    if ($secret === '') {
        $errors['secret_question'] = 'Введите секретный вопрос';
    } elseif (mb_strlen($secret) < 4) {
        $errors['secret_question'] = 'Секретный вопрос должен содержать минимум 4 символа';
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

            $hashedSecret = password_hash($secret, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("UPDATE users SET secret_question = ? WHERE id = ?");
            $stmt->execute([$hashedSecret, $_SESSION['user_id']]);

            // Успех
            header('Location: profile.php');
            exit;
        } catch (PDOException $e) {
            $errors['db'] = 'Ошибка базы данных. Попробуйте позже.';
        }
    }

    // Сохраняем ошибки
    $_SESSION['errors'] = $errors;
    header('Location: 2fa.php');
    exit;
}

$errors = $_SESSION['errors'] ?? [];
unset($_SESSION['errors']);

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Двухфакторная авторизация</title>
    <link rel="stylesheet" href="css/main.css">
</head>
<body>
    <div class= "twoFactorsMainArea">
        <p>Мой секрет, который я не выдам никому</p>
        <form class="secretAnswerForm" method="post" action="2fa.php">
            <input
                type="password"
                name="secret_question"
                placeholder="Секретный вопрос"
                class="<?= isset($errors['secret_question']) ? 'input-error' : '' ?>"
            >
            <?php if (isset($errors['secret_question'])): ?>
                <span class="error-message"><?= htmlspecialchars($errors['secret_question']) ?></span>
            <?php endif; ?>

            <?php if (isset($errors['db'])): ?>
                <span class="error-message"><?= htmlspecialchars($errors['db']) ?></span>
            <?php endif; ?>
            
            <button type="submit">Завершить</button>
        </form>
    </div>
</body>
</html>