<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';

if (is_logged_in()) {
    header('Location: /profile.php');
} else {
    header('Location: /feed.php');
}
exit;