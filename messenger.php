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
        /* Гарантированные стили мессенджера */
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

        .chatTypingPin,
        .sendMessageButton,
        #cancel-edit-btn {
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

        .chatTypingPin:hover,
        .sendMessageButton:hover,
        #cancel-edit-btn:hover {
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

        /* Превью вложений */
        .attachments-preview {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            background: #fff;
            border-top: none;
        }

        .attachment-item {
            position: relative;
            width: 80px;
            height: 80px;
            border-radius: 8px;
            margin: 10px;
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

        /* Модальное окно поста */
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

        /* Бейдж непрочитанных */
        .chatUnitPreview {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .chatUnitPreview p {
            flex: 1;
            min-width: 0;
            margin: 0;
            font-size: 0.85rem;
            color: #8b8fa3;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .chat-unread-badge {
            display: none;
            min-width: 22px;
            height: 22px;
            padding: 0 6px;
            box-sizing: border-box;
            background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
            color: #fff;
            border-radius: 11px;
            font-size: 0.72em;
            font-weight: 700;
            font-family: "Google Sans Flex", sans-serif;
            letter-spacing: 0;
            line-height: 1;
            align-items: center;
            justify-content: center;
            border: 2px solid #fff;
            box-shadow: 0 2px 6px rgba(185, 28, 28, 0.4), 0 1px 2px rgba(0, 0, 0, 0.1);
            transform: scale(0);
            transform-origin: center;
            transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
            user-select: none;
            flex-shrink: 0;
            pointer-events: none;
        }
        .chat-unread-badge.visible {
            display: inline-flex;
            transform: scale(1);
        }

        @keyframes chat-badge-pulse {
            0%   { box-shadow: 0 2px 6px rgba(185, 28, 28, 0.4), 0 0 0 0 rgba(239, 68, 68, 0.7); }
            50%  { box-shadow: 0 2px 6px rgba(185, 28, 28, 0.4), 0 0 0 8px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 2px 6px rgba(185, 28, 28, 0.4), 0 0 0 0 rgba(239, 68, 68, 0); }
        }
        .chat-unread-badge.pulse {
            animation: chat-badge-pulse 0.8s ease-out;
        }
        .chat-unread-badge[data-digits="2"] {
            min-width: 26px;
            padding: 0 7px;
        }
        .chat-unread-badge[data-digits="3"] {
            min-width: 30px;
            padding: 0 7px;
            font-size: 0.68em;
        }
        .chatUnit.pinned .chat-unread-badge {
            margin-right: 24px;
        }

        /* Скрываем мобильное меню, когда открыт чат */
        @media (max-width: 767px) {
            .messengerMainArea.chat-open ~ .mobile-nav {
                display: none !important;
            }
        }
        .attachments-preview:not(:has(.attachment-item)) {
            display: none;
        }

        /* Улучшенная типографика чата */
.chatBody {
    font-family: "Google Sans Flex", "Inter", system-ui, -apple-system, sans-serif;
}

.receivedMessage,
.myMessageBubble {
    font-size: 1rem;
    line-height: 1.55;
    color: #1e1e2f;
    padding: 12px 16px;
}

.receivedMessage {
    background-color: #ffffff;
    border: 1px solid #e4e7ed;
    color: #1a1a2e;
}

.myMessageBubble {
    background: linear-gradient(135deg, #e8e0ff 0%, #ede9fe 100%);
    color: #1e1e2f;
}

.messageInfo {
    color: #8b8fa3;
    font-size: 0.75rem;
    margin-top: 8px;
}

.messageInfo p:first-child {
    color: #10b981; /* «прочитано» зелёным */
}

.chatDateBubble {
    background-color: #e8ecf5;
    color: #4a4f63;
    font-size: 0.8rem;
    font-weight: 500;
    padding: 6px 18px;
    border-radius: 20px;
    margin: 10px 0;
    letter-spacing: 0.3px;
}

/* Имя отправителя в групповых чатах */
.message-sender {
    font-size: 0.85rem;
    color: #3b5dd3;
    font-weight: 600;
    margin-bottom: 6px;
}

/* Ссылки внутри сообщений */
.receivedMessage a,
.myMessageBubble a {
    color: #3b5dd3;
    text-decoration: underline;
    text-underline-offset: 2px;
}

.receivedMessage a:hover,
.myMessageBubble a:hover {
    color: #2f4bb8;
}

/* Скрываем скроллбар в списке чатов */
.chatList .messengerArea {
    overflow-y: auto;
    scrollbar-width: none;          /* Firefox */
    -ms-overflow-style: none;       /* IE и Edge */
}

.chatList .messengerArea::-webkit-scrollbar {
    display: none;                  /* Chrome, Safari, Opera */
}

@media (max-width: 767px) {
    /* Контейнер со списком чатов */
    #chat-list-container {
        height: calc(100vh - 160px);   /* вся высота экрана минус шапка и меню */
        overflow-y: auto;
        padding-bottom: 70px;          /* чтобы последний чат не прилипал к низу */
        box-sizing: border-box;
    }

    /* Сама область скролла (messengerArea) пусть занимает всё доступное */
    .chatList .messengerArea {
        height: 100%;
        overflow: visible;
    }
}
    </style>
</head>
<body class="messenger-page">
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

        <!-- Мобильное меню -->
        <nav class="mobile-nav">
            <a href="profile.php">
                <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </span>
                <span class="mobile-nav-label">Профиль</span>
            </a>
            <a href="notifications.php">
                <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                </span>
                <span class="mobile-nav-label">Уведомления</span>
            </a>
            <a href="messenger.php" class="active">
                <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                </span>
                <span class="mobile-nav-label">Чаты</span>
            </a>
            <a href="search.php">
                <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </span>
                <span class="mobile-nav-label">Люди</span>
            </a>
            <a href="feed.php">
                <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="15" rx="2" ry="2"/><path d="M17 2l-5 5-5-5"/></svg>
                </span>
                <span class="mobile-nav-label">Лента</span>
            </a>
            <a href="settings.php">
                <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                </span>
                <span class="mobile-nav-label">Настройки</span>
            </a>
        </nav>
    </div>

    <!-- Модальные окна -->
    <div id="group-modal" class="modal-overlay" style="display: none;">
        <div class="modal-container" style="max-width: 560px; padding: 24px;">
            <span class="modal-close" id="group-modal-close">&times;</span>
            <h3 style="margin-top: 0; margin-bottom: 20px;">Создать группу</h3>
            <input type="text" id="group-name" placeholder="Название группы" style="width:100%; padding:12px; margin-bottom: 20px; border-radius:12px; border:1px solid #e0e0e0; box-sizing:border-box; font-size:1rem;">
            <div style="margin-bottom: 16px;">
                <input type="text" id="group-friends-search" placeholder="Поиск друзей..." style="width:100%; padding:10px 12px; border-radius:20px; border:1px solid #e0e0e0; box-sizing:border-box; outline:none;">
            </div>
            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px;">
                <label style="font-weight:500;">Участники:</label>
                <span id="selected-count" style="background:#eef1f8; padding:2px 10px; border-radius:20px; font-size:0.85rem;">0 выбрано</span>
            </div>
            <div id="group-friends-list" style="max-height: 300px; overflow-y: auto; border:1px solid #f0f2f5; border-radius:16px; padding:8px 0; background:#fff;"></div>
            <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                <button id="group-cancel-btn" class="btn btn--secondary" style="padding: 8px 20px;">Отмена</button>
                <button id="group-create-btn" class="btn btn--primary" style="padding: 8px 20px;">Создать</button>
            </div>
        </div>
    </div>

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