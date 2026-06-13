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
        /* Дополнительные стили для гарантии */
        .chatHeaderOptions {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 4px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            background: none;
            transition: background 0.2s;
            margin-left: auto;
        }
        .chatHeaderOptions:hover { background: #f5f6fa; }
        .multiDot {
            width: 4px;
            height: 4px;
            background-color: #5a6072;
            border-radius: 50%;
        }
        .chatTyping {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            background: #fff;
            border-top: 1px solid #f0f2f5;
        }
        .chatTypingPin, .sendMessageButton, #cancel-edit-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
            flex-shrink: 0;
        }
        .chatTypingPin:hover, .sendMessageButton:hover, #cancel-edit-btn:hover {
            background: #f0f2f5;
        }
        #cancel-edit-btn {
            background: #fee2e2;
            color: #b91c1c;
        }
        #cancel-edit-btn:hover { background: #fecaca; }
        .error-message { border: 1px solid #b91c1c; background: #fee2e2; }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .message.animate-in { animation: fadeInUp 0.3s ease forwards; }
        .chatUnitAvatar--placeholder {
            background: #e0e7ff;
            color: #3b5dd3;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        #create-group-btn {
            margin-top: 12px !important;
        }
        /* Стили для превью вложений (под строкой ввода) */
        .attachments-preview {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 4px 20px 12px 20px;
            background: #fff;
            border-top: none;
        }
        .attachment-item {
            position: relative;
            width: 80px;
            height: 80px;
            border-radius: 8px;
            overflow: hidden;
            background: #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 1px solid #e0e0e0;
        }
        .attachment-item img,
        .attachment-item video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .attachment-item .file-icon {
            font-size: 32px;
        }
        .remove-attachment {
            position: absolute;
            top: 2px;
            right: 2px;
            background: rgba(0,0,0,0.6);
            color: white;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .remove-attachment:hover {
            background: rgba(220, 38, 38, 0.9);
        }
        /* Стили для модального окна поста */
        #post-modal .modal-container {
            max-width: 520px;
            padding: 0;
            overflow-y: auto;
            border-radius: 20px;
        }
        #post-modal .post {
            margin: 0;
            border-radius: 0;
            box-shadow: none;
        }
        #post-modal .carousel-container {
            border-radius: 0;
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
                <div class="chat-placeholder"><p>Выберите чат слева</p></div>
            </div>
        </div>
    </div>

    <!-- Модальное окно создания группы -->
    <div id="group-modal" class="modal-overlay" style="display: none;">
        <div class="modal-container" style="max-width: 560px; padding: 24px;">
            <span class="modal-close" id="group-modal-close">&times;</span>
            <h3 style="margin-top: 0; margin-bottom: 20px;">Создать группу</h3>
            
            <!-- Название группы -->
            <input type="text" id="group-name" placeholder="Название группы" style="width:100%; padding:12px; margin-bottom: 20px; border-radius:12px; border:1px solid #e0e0e0; box-sizing:border-box; font-size:1rem;">
            
            <!-- Поиск друзей -->
            <div style="margin-bottom: 16px;">
                <input type="text" id="group-friends-search" placeholder="Поиск друзей..." style="width:100%; padding:10px 12px; border-radius:20px; border:1px solid #e0e0e0; box-sizing:border-box; outline:none;">
            </div>
            
            <!-- Счётчик выбранных -->
            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px;">
                <label style="font-weight:500;">Участники:</label>
                <span id="selected-count" style="background:#eef1f8; padding:2px 10px; border-radius:20px; font-size:0.85rem;">0 выбрано</span>
            </div>
            
            <!-- Список друзей с аватарками -->
            <div id="group-friends-list" style="max-height: 300px; overflow-y: auto; border:1px solid #f0f2f5; border-radius:16px; padding:8px 0; background:#fff;"></div>
            
            <!-- Кнопки действий -->
            <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                <button id="group-cancel-btn" class="btn btn--secondary" style="padding: 8px 20px;">Отмена</button>
                <button id="group-create-btn" class="btn btn--primary" style="padding: 8px 20px;">Создать</button>
            </div>
        </div>
    </div>

    <!-- Модальное окно для жалобы -->
    <div id="report-modal" class="modal-overlay" style="display: none;">
        <div class="modal-container" style="max-width: 400px; padding: 24px;">
            <span class="modal-close" id="report-modal-close">&times;</span>
            <h3 style="margin-top: 0; margin-bottom: 16px;">Пожаловаться на пользователя</h3>
            <textarea id="report-reason" placeholder="Опишите причину жалобы..." style="width:100%; padding:12px; border-radius:12px; border:1px solid #e0e0e0; margin-bottom: 20px; min-height: 100px; box-sizing: border-box; font-family: inherit;"></textarea>
            <div style="display: flex; gap: 12px; justify-content: center;">
                <button id="report-cancel-btn" class="btn btn--secondary" style="padding: 8px 20px;">Отмена</button>
                <button id="report-submit-btn" class="btn btn--primary" style="padding: 8px 20px;">Отправить</button>
            </div>
        </div>
    </div>

    <!-- Модальное окно для просмотра поста -->
    <div id="post-modal" class="modal-overlay" style="display: none;">
        <div class="modal-container">
            <span class="modal-close" id="post-modal-close">&times;</span>
            <div id="post-modal-content"></div>
        </div>
    </div>

    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <script>
        window.currentUserId = <?= (int)$_SESSION['user_id'] ?>;
        window.csrfToken = document.querySelector('input[name="_csrf"]').value;
    </script>
    <script src="/js/messenger-spa.js"></script>
</body>
</html>