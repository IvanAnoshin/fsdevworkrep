<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/kopilot/kopilot_init.php';
echo 'Kopilot OK<br>';

// Проверим подключение к БД через простой запрос
$stmt = db()->query("SELECT 1");
echo 'DB connection OK<br>';

// Проверим DFSN без загрузки пользователя
require_once __DIR__ . '/dfsn.php';
$dfsn = new DFSN();
echo 'DFSN class loaded OK<br>';

// Попробуем создать таблицы DFSN
dfsn_install_tables();
echo 'DFSN tables created OK<br>';

phpinfo();
