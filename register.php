<?php

session_start();

$dbHost = 'localhost';
$dbName = 'fsdb';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

$errors = []; //массив для ошибок

//Если форма отправлена
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Получаем и очищаем данные
    $firstName = mb_convert_case(trim($_POST['first_name'] ?? ''), MB_CASE_TITLE, "UTF-8");
    $lastName = mb_convert_case(trim($_POST['last_name'] ?? ''), MB_CASE_TITLE, "UTF-8");
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    $gender = $_POST['gender'] ?? '';

    // 2. Валидация
    if ($firstName === '') {
        $errors['first_name'] = 'Введите имя';
    }
    if ($lastName === '') {
        $errors['last_name'] = 'Введите фамилию';
    }
    if (!in_array($gender, ['male', 'female'])) {
        $errors['gender'] = 'Выберите пол';
    }
    if (mb_strlen($password) < 6) {
        $errors['password'] = 'Пароль должен быть не менее 6 символов';
    }
    if ($password !== $password2) {
        $errors['password2'] = 'Пароли не совпадают';
    }

    // 3. Если ошибок нет - записываем данные в базу

    if (empty($errors)) {
            try {
                $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=$dbCharset", $dbUser, $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // Хешируем пароль
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            // Защита от SQL-инъекций
            $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, password, gender) VALUES (?, ?, ?, ?)");
            $stmt->execute([$firstName, $lastName, $hashedPassword, $gender]);

            // Успех:
            $_SESSION['user_id'] = $pdo->lastInsertId();
            header('Location: 2fa.php'); // редирект на страницу с установкой двухфакторки
            exit;
        } catch (PDOException $e) {
            $errors['db'] = 'Ошибка базы данных. Попробуйте позже.';
        }
    }

    //Если были ошибки - сохраняем их и возвращаем на форму
    $_SESSION['errors'] = $errors;
    $_SESSION['old'] = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'gender' => $gender,
    ];
    header('Location: register.php');
    exit;
}

// После редиректа (или при первом заходе) подхватываем данные из сессии
$errors = $_SESSION['errors'] ?? [];
$old = $_SESSION['old'] ?? [];
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
    <div class= "registerPageArea">
        <p class="welcome">Регистрация</p>
        <div class="authLoginSection">
            <form method="post" action="register.php">
                <input
                    type="text"
                    name="first_name"
                    placeholder="Имя"
                    value="<?= htmlspecialchars($old['first_name'] ?? '') ?>"
                    class= "<?= isset($errors['first_name']) ? 'input-error' : '' ?>"
                >
                <?php if (isset($errors['first_name'])): ?>
                    <span class="error-message"><?= $errors['first_name'] ?></span>
                <?php endif; ?>
                
                <input
                    type="text"
                    name="last_name"
                    placeholder="Фамилия"
                    value="<?= htmlspecialchars($old['last_name'] ?? '') ?>"
                    class="<?= isset($errors['last_name']) ? 'input-error' : '' ?>"
                >
                <?php if (isset($errors['last_name'])): ?>
                    <span class="error-message"><?= $errors ['last_name'] ?></span>
                <?php endif; ?>
                
                <input
                    type="password"
                    name="password"
                    placeholder="Пароль"
                    class="<?= isset($errors['password']) ? 'input-error' : '' ?>"
                >
                <?php if (isset($errors['password'])): ?>
                    <span class="error-message"><?= $errors['password'] ?></span>
                <?php endif; ?>
                
                <input
                    type="password"
                    name="password2"
                    placeholder="Повторите пароль"
                    class="<?= isset($errors['password2']) ? 'input-error' : '' ?>"
                >
                <?php if (isset($errors['password2'])): ?>
                    <span class="error-message"><?= $errors['password2'] ?></span>
                <?php endif; ?>
                
                <div class="sex">
                    <fieldset>
                        <legend>Ваш пол: </legend>

                        <label>
                            <input
                                type="radio"
                                name="gender"
                                value="male"
                                required
                                <?= (isset($old['gender']) && $old['gender'] === 'male') ? 'checked': '' ?>
                            >
                            Мужчина
                        </label>
                    
                        <label>
                            <input
                                type="radio"
                                name="gender"
                                value="female"
                                <?= (isset($old['gender']) && $old['gender'] === 'female') ? 'checked' : '' ?>
                            >
                            Женщина
                        </label>
                    </fieldset>
                    <?php if (isset($errors['db'])): ?>
                        <p class="error-message"><?= $errors['db'] ?></p>
                    <?php endif; ?>

                </div>
                <button type="submit">Далее</button>                
            </form>

        </div>
        <div class="helpLinks">
            <a href="login.php">Войти / </a>
            <a href="recovery.php">Забыли пароль?</a>
        </div>
        <a href="about.html" class="aboutUs">О нас</a>
    </div>
</body>
</html>