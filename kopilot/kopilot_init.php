<?php
// ТОЧКА ПОДКЛЮЧЕНИЯ Kopilot

if (session_status() === PHP_SESSION_NONE) {
    session_start();
    date_default_timezone_set('UTC');
}

require_once __DIR__ . '/../kopilot/php/kopilot.php'; // Подключение Kopilot PHP

if (isset($_SESSION['user_id'])) {
    db()->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['user_id']]);
}

/**
 * Форматирует число реакций в компактный вид
 * 999 -> "999"
 * 1000 -> "1К"
 * 1500 -> "1.5К"
 * 999999 -> "999К"
 * 1000000 -> "1М"
 * 1500000 -> "1.5М"
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