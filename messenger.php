<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';
require_auth();
$pageTitle = 'Мессенджер - Friendscape';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($pageTitle) ?></title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .message.animate-in {
            animation: fadeInUp 0.3s ease forwards;
        }
    </style>
</head>
<body>
    <div id="container">
        <div class="sidebar"><?php require_once "components/header.php"; ?></div>
        <div class="messengerMainArea">
            <div class="chatList" id="chat-list-panel">
                <div class="messengerArea">
                    <div class="messengerHeader">
                        <p class="messengerLogo">Мессенджер</p>
                        <input type="text" placeholder="Поиск" id="messengerSearch">
                        <button id="create-group-btn" class="btn btn--secondary">+ Создать группу</button>
                    </div>
                    <div id="chat-list-container"></div>
                </div>
            </div>
            <div class="chatArea" id="chat-view-panel">
                <div class="chat-placeholder">
                    <p>Выберите чат слева, чтобы начать общение</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.currentUserId = <?= (int)$_SESSION['user_id'] ?>;
    </script>
    <script src="/js/messenger-spa.js"></script>
</body>
</html>