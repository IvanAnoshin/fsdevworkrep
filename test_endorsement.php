<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';

echo "<h2>Тест записи в dfsn_endorsements</h2>";

// Данные для теста: текущий пользователь и пользователь с id=2 (или любой другой)
$fromUserId = $_SESSION['user_id'] ?? 0;
$toUserId = 2; // замени на id другого существующего пользователя, если нужно

echo "<p>Пользователь: from=$fromUserId, to=$toUserId</p>";

if ($fromUserId <= 0) {
    die("<p style='color:red'>Вы не авторизованы</p>");
}

// Проверяем существование таблицы
$tableExists = scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'dfsn_endorsements'");
if (!$tableExists) {
    die("<p style='color:red'>Таблица dfsn_endorsements не существует! Создайте её через SQL.</p>");
}
echo "<p style='color:green'>Таблица существует.</p>";

// Проверяем, есть ли уже запись
$existing = scalar("SELECT COUNT(*) FROM dfsn_endorsements WHERE from_user_id = ? AND to_user_id = ?", [$fromUserId, $toUserId]);
echo "<p>Существующих записей: $existing</p>";

if ($existing == 0) {
    // Пытаемся вставить запись
    try {
        $stmt = db()->prepare("INSERT INTO dfsn_endorsements (from_user_id, to_user_id, coefficient, created_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$fromUserId, $toUserId, 0.05, time()]);
        $inserted = $stmt->rowCount();
        echo "<p style='color:blue'>Результат вставки: затронуто строк = $inserted</p>";
        
        // Повторная проверка
        $check = scalar("SELECT COUNT(*) FROM dfsn_endorsements WHERE from_user_id = ? AND to_user_id = ?", [$fromUserId, $toUserId]);
        if ($check > 0) {
            echo "<p style='color:green; font-weight:bold'>✅ Запись успешно сохранена в базе! Проблема в коде DFSN.</p>";
        } else {
            echo "<p style='color:red; font-weight:bold'>❌ Вставка выполнена без ошибок, но запись не найдена! Проблема с транзакциями или базой данных.</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>Ошибка вставки: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p>Запись уже существует, тест не требуется.</p>";
}