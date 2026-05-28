<?php
// ТОЧКА ПОДКЛЮЧЕНИЯ Kopilot

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../kopilot/php/kopilot.php'; // Подключение Kopilot PHP

if (isset($_SESSION['user_id'])) {
    db()->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['user_id']]);
}