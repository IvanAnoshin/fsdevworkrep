<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';
require_auth();

$user = find('users', $_SESSION['user_id']);
if (!$user) {
    header('Location: register.php');
    exit;
}

$errors = [];

// Если форма отправлена
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF проверка
    if (!hash_equals(csrf_token(), $_POST['_csrf'] ?? '')) {
        $errors['csrf'] = 'Недействительный CSRF-токен';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'finish') {
        // Шаг 2: завершение — скрываем паспорт и переходим в профиль
        unset($_SESSION['show_passport']);
        header('Location: profile.php');
        exit;
    } else {
        // Шаг 1: сохранение секретного вопроса
        $secret = $_POST['secret_question'] ?? '';

        if ($secret === '') {
            $errors['secret_question'] = 'Введите секретный вопрос';
        } elseif (mb_strlen($secret) < 4) {
            $errors['secret_question'] = 'Секретный вопрос должен содержать минимум 4 символа';
        }

        if (empty($errors)) {
            $hashedSecret = password_hash($secret, PASSWORD_DEFAULT);
            update('users', $_SESSION['user_id'], ['secret_question' => $hashedSecret]);

            // Успех: устанавливаем флаг для показа паспорта
            $_SESSION['show_passport'] = true;
            // Редирект на эту же страницу (PRG)
            header('Location: 2fa.php');
            exit;
        }
    }

    // Если были ошибки — сохраняем их во флеш и редиректим
    flash('errors', $errors);
    header('Location: 2fa.php');
    exit;
}

// Получаем ошибки из флеша
$errors = flash('errors') ?? [];

// Проверяем, нужно ли показывать паспорт (шаг 2)
$showPassport = $_SESSION['show_passport'] ?? false;

// Получаем сырой паспорт из базы (для показа)
$rawPassport = 'Недоступен';
$stmt = db()->prepare("SELECT passport_raw FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$row = $stmt->fetch();
if ($row && !empty($row['passport_raw'])) {
    $rawPassport = $row['passport_raw'];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Двухфакторная авторизация — Friendscape</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .passportNotice {
            max-width: 380px;
            width: 100%;
            margin: 0 auto 24px;
            padding: 28px 24px;
            background: linear-gradient(135deg, #f0f4ff 0%, #ffffff 100%);
            border: 2px solid #d0d7f0;
            border-radius: 20px;
            box-shadow: 0 12px 30px rgba(59, 93, 211, 0.1);
            text-align: center;
            box-sizing: border-box;
        }
        .passportNotice__label {
            margin: 0 0 14px;
            font-weight: 600;
            font-size: 1.05em;
            color: #3b5dd3;
        }
        .passportNotice__value {
            margin: 0 0 18px;
            padding: 14px;
            background: #f9fafb;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
            font-size: 1.25em;
            letter-spacing: 2px;
            color: #1e1e2f;
            word-break: break-all;
        }
        .passportNotice__hint {
            margin: 0 0 18px;
            font-size: 0.85em;
            color: #6b7280;
            line-height: 1.4;
        }
        .btn-action {
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            border: none;
            border-radius: 10px;
            background: #3b5dd3;
            color: #fff;
            font-weight: 500;
            font-size: 0.95em;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-action:hover {
            background: #2f4bb5;
        }
        .btn-action.copied {
            background: #10b981;
        }
        .btn-finish {
            background: #10b981;
            margin-top: 10px;
        }
        .btn-finish:hover {
            background: #059669;
        }
        .step-container {
            width: 100%;
            max-width: 380px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
    </style>
</head>
<body>
    <div class="twoFactorsMainArea">
        <?php if (!$showPassport): ?>
            <!-- ================= ШАГ 1: Секретный вопрос ================= -->
            <p style="font-size: 1.2em; color: black; text-align: center; margin-bottom: 10px;">Мой секрет, который я не выдам никому</p>
            
            <form class="secretAnswerForm step-container" method="post" action="2fa.php">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input 
                    type="password" 
                    name="secret_question" 
                    placeholder="Секретный вопрос"
                    class="<?= isset($errors['secret_question']) ? 'input-error' : '' ?>"
                    style="width: 100%; box-sizing: border-box;"
                >
                <?php if (isset($errors['secret_question'])): ?>
                    <span class="error-message"><?= esc($errors['secret_question']) ?></span>
                <?php endif; ?>
                <?php if (isset($errors['csrf'])): ?>
                    <span class="error-message"><?= esc($errors['csrf']) ?></span>
                <?php endif; ?>
                <button type="submit" class="btn-action">Далее</button>
            </form>

        <?php else: ?>
            <!-- ================= ШАГ 2: Паспорт и Завершение ================= -->
            <div class="passportNotice">
                <p class="passportNotice__label">🔑 Ваш Friendscape Паспорт</p>
                <p id="passport-display" class="passportNotice__value"><?= esc($rawPassport) ?></p>
                <p class="passportNotice__hint">Сохраните его в надёжном месте — он понадобится для восстановления доступа к аккаунту</p>
                <button type="button" onclick="copyPassport()" class="btn-action" id="copy-passport-btn">Скопировать</button>
            </div>
            
            <form method="post" action="2fa.php" class="step-container">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="finish">
                <button type="submit" class="btn-action btn-finish">Завершить</button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        function copyPassport() {
            const passportElement = document.getElementById('passport-display');
            if (!passportElement) return;

            const passportText = passportElement.textContent.trim();
            const btn = document.getElementById('copy-passport-btn');
            const originalText = btn.textContent;

            navigator.clipboard.writeText(passportText).then(() => {
                btn.textContent = 'Скопировано ✓';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.textContent = originalText;
                    btn.classList.remove('copied');
                }, 2000);
            }).catch(err => {
                console.error('Ошибка копирования: ', err);
                btn.textContent = 'Не удалось скопировать';
                setTimeout(() => { btn.textContent = originalText; }, 2000);
            });
        }
    </script>
</body>
</html>