<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';
require_auth();
echo "Профиль работает! ID пользователя: " . $_SESSION['user_id'];