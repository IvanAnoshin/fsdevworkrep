<?php
// ТОЧКА ПОДКЛЮЧЕНИЯ Kopilot

if (session_status() === PHP_SESSION_NONE) {
    // Время жизни сессии — 30 дней (в секундах)
    $sessionLifetime = 30 * 24 * 60 * 60;

    // Определяем, используется ли HTTPS (универсальный метод)
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
        (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https')
    );

    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    ini_set('session.gc_maxlifetime', $sessionLifetime);

    session_start();
    date_default_timezone_set('UTC');
}

require_once __DIR__ . '/../kopilot/php/kopilot.php';

// Обновляем время последней активности (не чаще раза в минуту)
if (isset($_SESSION['user_id'])) {
    $lastUpdate = $_SESSION['last_active_update'] ?? 0;
    if (time() - $lastUpdate > 60) {
        db()->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")
           ->execute([$_SESSION['user_id']]);
        $_SESSION['last_active_update'] = time();
    }
}

// =====================================================================
// ПОВЕДЕНЧЕСКИЙ СТРАЖ DFSN (обновление профиля + проверка аномалий)
// =====================================================================
if (isset($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/../dfsn.php';
        $dfsn = new DFSN();

        // Собираем простые поведенческие признаки
        $features = [
            $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),   // время запроса
            strlen($_SERVER['HTTP_USER_AGENT'] ?? ''),           // длина User-Agent
            strlen($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''),      // длина заголовка языка
        ];

        // Обновляем поведенческий профиль (экспоненциальное сглаживание, небольшое влияние)
        $dfsn->updateBehavioralProfile($_SESSION['user_id'], $features, 0.05);

        // Проверяем аномалию не при каждом запросе, а примерно раз в 20
        if (mt_rand(1, 20) === 1) {
            $isAnomaly = $dfsn->checkSession($_SESSION['user_id'], $features);
            if ($isAnomaly) {
                // Подозрительное поведение — завершаем сессию
                session_destroy();
                header('Location: /login.php');
                exit;
            }
        }
    } catch (\Exception $e) {
        // Ошибка DFSN не должна мешать работе сайта
        error_log("DFSN behavioral guard error: " . $e->getMessage());
    }
}
// =====================================================================

/**
 * Форматирует число реакций в компактный вид
 */
function formatCount($count) {
    $count = (int)$count;
    if ($count < 1000) {
        return (string)$count;
    } elseif ($count < 1000000) {
        $thousands = $count / 1000;
        if ($thousands == floor($thousands)) {
            return $thousands . 'К';
        } else {
            return number_format($thousands, 1, '.', '') . 'К';
        }
    } else {
        $millions = $count / 1000000;
        if ($millions == floor($millions)) {
            return $millions . 'М';
        } else {
            return number_format($millions, 1, '.', '') . 'М';
        }
    }
}