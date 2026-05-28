<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';
require_auth();

$currentUser = find('users', $_SESSION['user_id']);

// Определяем активный чат (если передан в URL)
$activeChatId = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : null;
$activeChatUser = null;

if ($activeChatId) {
    $chat = find('chats', $activeChatId);
    if ($chat) {
        $otherUserId = ($chat['user1_id'] == $_SESSION['user_id']) ? $chat['user2_id'] : $chat['user1_id'];
        $activeChatUser = find('users', $otherUserId);
    }
}

$pageTitle = 'Мессенджер - Friendscape';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($pageTitle) ?></title>
    <link rel="stylesheet" href="css/main.css">
</head>
<body>
    <div id="container">
        <div class="sidebar"><?php require_once "components/header.php"; ?></div>

        <div class="messengerMainArea">
            <div class="chatList">
                <div class="messengerArea">
                    <div class="messengerHeader">
                        <p class="messengerLogo">Мессенджер</p>
                        <input type="text" placeholder="Поиск" id="messengerSearch">
                    </div>
                    
                    <div id="chat-list-container">
                        <!-- Чаты загружаются через JS -->
                    </div>
                </div>
            </div>

            <div class="chatArea">
                <?php if ($activeChatUser): ?>
                <!-- Активный чат -->
                <div class="chatModule">
                    <div class="chatHeader">
                        <button class="chatHeaderQuit" onclick="window.location='messenger.php'">Назад</button>
                        <img class="chatHeaderAvatar" src="<?= esc($activeChatUser['avatar'] ?? '') ?>" alt="">
                        <a href="" class="chatHeaderUsername"><?= esc($activeChatUser['first_name'] . ' ' . $activeChatUser['last_name']) ?></a>
                        <button class="chatHeaderOptions">
                            <div class="multiDot"></div>
                            <div class="multiDot"></div>
                            <div class="multiDot"></div>
                        </button>
                    </div>
                    
                    <div class="chatBody" id="messages-container">
                        <!-- Сообщения загружаются через JS -->
                    </div>

                    <div class="chatTyping" id="chat-typing-area">
                        <button class="chatTypingPin">
                            <span class="Menu__icon" style="background: #e0f2fe; color: #0284c7;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                                </svg>
                            </span>
                        </button>
                        <textarea id="typing-input" class="chatTypingInput" placeholder="Здравствуйте!"></textarea>
                        <button class="sendMessageButton" id="send-msg-btn">
                            <span class="Menu__icon" style="background: #d1fae5; color: #059669;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="22" y1="2" x2="11" y2="13"/>
                                    <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                                </svg>
                            </span>
                        </button>
                    </div>
                </div>
                <?php else: ?>
                <!-- Заглушка, когда чат не выбран -->
                <div class="chat-placeholder">
                    <p>Выберите чат слева, чтобы начать общение</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    // Загрузка списка чатов
    async function loadChats() {
        try {
            const data = await kop.get('/api/chats');
            const container = document.getElementById('chat-list-container');
            if (!container) return;

            if (!data.chats || data.chats.length === 0) {
                container.innerHTML = '<p style="text-align:center;color:#8b8fa3;padding:20px;font-size:0.9em;">Нет активных чатов</p>';
                return;
            }

            container.innerHTML = data.chats.map(chat => {
                const activeClass = (chat.chat_id == <?= $activeChatId ?? 0 ?>) ? 'active' : '';
                return `
                    <div class="chatUnit ${activeClass}" onclick="window.location='?chat_id=${chat.chat_id}'">
                        <img class="chatUnitAvatar" src="${chat.avatar || ''}" alt="">
                        <div class="chatUnitContent">
                            <div class="chatUnitUsername">
                                <p>${chat.first_name} ${chat.last_name}</p>
                            </div>
                            <div class="chatUnitPreview">
                                <p>${chat.last_message || 'Нет сообщений'} ${chat.unread_count > 0 ? `(${chat.unread_count})` : ''}</p>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        } catch (e) {
            console.error('Ошибка загрузки чатов:', e);
        }
    }

    <?php if ($activeChatId): ?>
    // Загрузка сообщений
    async function loadMessages() {
        try {
            const data = await kop.get(`/api/messages/${<?= $activeChatId ?>}`);
            const container = document.getElementById('messages-container');
            if (!container) return;
            const myId = <?= $_SESSION['user_id'] ?>;

            if (!data.messages || data.messages.length === 0) {
                container.innerHTML = '<p style="text-align:center;color:#8b8fa3;padding:20px;font-size:0.9em;">Напишите первое сообщение</p>';
                return;
            }

            let lastDate = '';
            container.innerHTML = data.messages.map(msg => {
                const date = new Date(msg.created_at);
                const dateStr = date.toLocaleDateString('ru-RU');
                let dateBubble = '';
                if (dateStr !== lastDate) {
                    dateBubble = `<div class="chatDateBubble"><p>${dateStr}</p></div>`;
                    lastDate = dateStr;
                }
                const isMine = msg.sender_id == myId;
                return `
                    ${dateBubble}
                    <div class="${isMine ? 'myMessageBubble' : 'receivedMessage'}">
                        <p>${msg.content}</p>
                        <div class="messageInfo">
                            <p>${msg.is_read ? 'прочитано' : ''}</p>
                            <p>${date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}</p>
                        </div>
                    </div>
                `;
            }).join('');

            container.scrollTop = container.scrollHeight;
        } catch (e) {
            console.error('Ошибка загрузки сообщений:', e);
        }
    }

    // Обработчик отправки сообщения
    document.addEventListener('DOMContentLoaded', function() {
        const sendBtn = document.getElementById('send-msg-btn');
        const typingInput = document.getElementById('typing-input');

        if (sendBtn && typingInput) {
            sendBtn.addEventListener('click', async function() {
                const content = typingInput.value.trim();
                if (!content) return;

                try {
                    await kop.post('/api/messages/send', {
                        receiver_id: <?= $activeChatUser['id'] ?? 0 ?>,
                        content: content
                    });
                    typingInput.value = '';
                    await loadMessages();
                    await loadChats();
                } catch (e) {
                    kop.flash('Ошибка отправки', 'error');
                }
            });

            // Отправка по Enter (без Shift)
            typingInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendBtn.click();
                }
            });
        }

        // Авторесайз textarea
        if (typingInput) {
            typingInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
            });
        }

        loadMessages();
    });
    <?php endif; ?>

    // Первичная загрузка чатов при загрузке страницы
    loadChats();

    // Автообновление каждые 5 секунд
    setInterval(function() {
        loadChats();
        <?php if ($activeChatId): ?>
        loadMessages();
        <?php endif; ?>
    }, 5000);
    </script>
</body>
</html>