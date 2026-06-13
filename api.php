<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});
require_once __DIR__ . '/kopilot/kopilot_init.php';

function is_admin(): bool {
    $userId = $_SESSION['user_id'] ?? 0;
    if (!$userId) return false;
    $isAdmin = scalar("SELECT COUNT(*) FROM admins WHERE user_id = ?", [$userId]) > 0;
    if (!$isAdmin) {
        $user = find('users', $userId);
        $isAdmin = ($user['role'] ?? '') === 'admin';
    }
    return $isAdmin;
}

function canInteractWithPost(int $postId, int $userId): bool {
    $post = find('posts', $postId);
    if (!$post) return false;
    if ($post['user_id'] == $userId) return true;
    $author = find('users', $post['user_id']);
    $privacy = $author['privacy_posts'] ?? 'all';
    if ($privacy === 'all' || $privacy === 'public') return true;
    if ($privacy === 'friends') {
        $stmt = db()->prepare(
            "SELECT id FROM friendships WHERE 
                ((requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)) 
                AND status = 'accepted'"
        );
        $stmt->execute([$userId, $post['user_id'], $post['user_id'], $userId]);
        return (bool) $stmt->fetch();
    }
    return false;
}

// ---------- ПОЛУЧЕНИЕ ПОСТА (ДЛЯ МОДАЛЬНОГО ОКНА В МЕССЕНДЖЕРЕ) ----------
$router->api('GET', '/api/posts/{postId}', function($postId) {
    require_auth();
    $postId = (int)$postId;
    $userId = $_SESSION['user_id'];
    
    $post = find('posts', $postId);
    if (!$post) {
        http_response_code(404);
        return ['error' => 'Пост не найден'];
    }
    if (!canInteractWithPost($postId, $userId)) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    
    $author = find('users', $post['user_id']);
    $stmtMedia = db()->prepare("SELECT file_url, media_type FROM post_media WHERE post_id = ? ORDER BY id ASC");
    $stmtMedia->execute([$postId]);
    $media = $stmtMedia->fetchAll();
    if (empty($media) && !empty($post['image'])) {
        $type = preg_match('/\.(mp4|webm|mov)$/i', $post['image']) ? 'video' : 'image';
        $media = [['file_url' => $post['image'], 'media_type' => $type]];
    }
    $stmtReact = db()->prepare("SELECT reaction FROM post_reactions WHERE post_id = ? AND user_id = ?");
    $stmtReact->execute([$postId, $userId]);
    $reaction = $stmtReact->fetch();
    
    return [
        'id' => $post['id'],
        'content' => $post['content'],
        'media' => $media,
        'likes_count' => (int)$post['likes_count'],
        'dislikes_count' => (int)$post['dislikes_count'],
        'user_reaction' => $reaction ? $reaction['reaction'] : null,
        'user_id' => $post['user_id'],
        'first_name' => $author['first_name'],
        'last_name' => $author['last_name'],
        'avatar' => $author['avatar'] ?? '',
        'created_at' => $post['created_at']
    ];
});

// ---------- ПОЛЬЗОВАТЕЛЬСКИЕ НАСТРОЙКИ ----------
$router->api('POST', '/api/user/update-name', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $firstName = trim($data['first_name'] ?? '');
    $lastName  = trim($data['last_name'] ?? '');
    if ($firstName === '' || $lastName === '') {
        throw new ValidationException(['first_name' => 'Имя обязательно', 'last_name' => 'Фамилия обязательна']);
    }
    update('users', $_SESSION['user_id'], ['first_name' => $firstName, 'last_name' => $lastName]);
    return ['first_name' => $firstName, 'last_name' => $lastName];
});

$router->api('GET', '/api/user/sessions', function () {
    require_auth();
    $userId = $_SESSION['user_id'];
    $currentSessionStart = $_SESSION['login_time'] ?? null;
    $sessions = select(
        "SELECT id, login_time FROM user_sessions WHERE user_id = ? ORDER BY login_time DESC LIMIT 20",
        [$userId]
    );
    $result = [];
    foreach ($sessions as $session) {
        $result[] = [
            'id' => $session['id'],
            'login_time' => $session['login_time'],
            'is_current' => $currentSessionStart && $session['login_time'] == $currentSessionStart
        ];
    }
    return ['sessions' => $result];
});

$router->api('POST', '/api/user/update-bio', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    update('users', $_SESSION['user_id'], ['bio' => trim($data['bio'] ?? '')]);
    return ['bio' => $data['bio']];
});

$router->api('POST', '/api/user/update-privacy', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $updates = [];
    if (isset($data['privacy_posts']) && in_array($data['privacy_posts'], ['public','friends','self'])) {
        $updates['privacy_posts'] = $data['privacy_posts'];
    }
    if (isset($data['privacy_messages']) && in_array($data['privacy_messages'], ['all','friends','nobody'])) {
        $updates['privacy_messages'] = $data['privacy_messages'];
    }
    if (isset($data['show_online'])) $updates['show_online'] = (bool)$data['show_online'] ? 1 : 0;
    if (!empty($updates)) update('users', $_SESSION['user_id'], $updates);
    return ['success' => true];
});

$router->api('POST', '/api/user/update-notifications', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $updates = [];
    if (isset($data['notify_sound'])) $updates['notify_sound'] = (bool)$data['notify_sound'] ? 1 : 0;
    if (isset($data['notify_push'])) $updates['notify_push'] = (bool)$data['notify_push'] ? 1 : 0;
    if (!empty($updates)) update('users', $_SESSION['user_id'], $updates);
    return ['success' => true];
});

// ---------- ДРУЗЬЯ ----------
$router->api('POST', '/api/friends/add', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $requester = $_SESSION['user_id'];
    $addressee = (int)($data['addressee_id'] ?? 0);
    if ($addressee <= 0) throw new ValidationException(['addressee_id' => 'Не указан']);
    db()->prepare("INSERT IGNORE INTO friendships (requester_id, addressee_id) VALUES (?, ?)")->execute([$requester, $addressee]);
    // уведомление получателю
    db()->prepare("INSERT INTO notifications (user_id, type, actor_id) VALUES (?, 'friend_request', ?)")
        ->execute([$addressee, $requester]);
    return ['success' => true];
});

$router->api('POST', '/api/friends/accept', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $requester = (int)($data['requester_id'] ?? 0);
    $currentUserId = $_SESSION['user_id'];

    // Принимаем заявку
    db()->prepare("UPDATE friendships SET status = 'accepted' WHERE requester_id = ? AND addressee_id = ?")
        ->execute([$requester, $currentUserId]);

    // Уведомление тому, кто подал заявку
    db()->prepare("INSERT INTO notifications (user_id, type, actor_id) VALUES (?, 'friend_accept', ?)")
        ->execute([$requester, $currentUserId]);

    // Помечаем все уведомления о заявке от этого пользователя как прочитанные
    db()->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND actor_id = ? AND type = 'friend_request'")
        ->execute([$currentUserId, $requester]);

    return ['success' => true];
});

$router->api('POST', '/api/friends/decline', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $friendId = (int)($data['friend_id'] ?? 0);
    $userId = $_SESSION['user_id'];
    if ($friendId <= 0) {
        http_response_code(422);
        return ['error' => 'Не указан друг'];
    }

    // Отклоняем / удаляем дружбу
    db()->prepare("DELETE FROM friendships WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)")
        ->execute([$userId, $friendId, $friendId, $userId]);

    // Помечаем все уведомления о заявке от этого пользователя как прочитанные
    db()->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND actor_id = ? AND type = 'friend_request'")
        ->execute([$userId, $friendId]);

    return ['success' => true];
});

$router->api('GET', '/api/friends', function() {
    require_auth();
    $userId = $_SESSION['user_id'];
    $friends = select(
        "SELECT u.id, u.first_name, u.last_name, u.avatar
         FROM friendships f
         JOIN users u ON u.id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END
         WHERE (f.requester_id = ? OR f.addressee_id = ?) AND f.status = 'accepted'
         ORDER BY u.first_name ASC",
        [$userId, $userId, $userId]
    );
    return ['friends' => $friends];
});

// ---------- МЕССЕНДЖЕР ----------
$router->api('GET', '/api/messages/{chatId}/poll', function($chatId) {
    require_auth();
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $userId = $_SESSION['user_id'];
    $chatId = (int)$chatId;
    $chat = find('chats', $chatId);
    if (!$chat || ($chat['user1_id'] != $userId && $chat['user2_id'] != $userId)) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    $lastId = isset($_GET['after']) ? (int)$_GET['after'] : 0;
    $stmt = db()->prepare("SELECT id, sender_id, content, file_url, post_preview, created_at FROM messages WHERE chat_id = ? AND id > ? ORDER BY id ASC");
    $stmt->execute([$chatId, $lastId]);
    $messages = $stmt->fetchAll();
    if (!empty($messages)) {
        db()->prepare("UPDATE messages SET is_read = 1 WHERE chat_id = ? AND sender_id != ?")->execute([$chatId, $userId]);
    }
    return ['messages' => $messages];
});

$router->api('GET', '/api/messages/{chatId}', function($chatId) {
    require_auth();
    $userId = $_SESSION['user_id'];
    $chatId = (int)$chatId;
    $chat = find('chats', $chatId);
    if (!$chat || ($chat['user1_id'] != $userId && $chat['user2_id'] != $userId)) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? min(200, max(1, (int)$_GET['per_page'])) : 20;
    $offset = ($page - 1) * $perPage;
    $stmt = db()->prepare("SELECT id, sender_id, content, file_url, post_preview, is_read, created_at FROM messages WHERE chat_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$chatId, $perPage, $offset]);
    $messages = $stmt->fetchAll();
    $messages = array_reverse($messages);
    db()->prepare("UPDATE messages SET is_read = 1 WHERE chat_id = ? AND sender_id != ?")->execute([$chatId, $userId]);
    return ['messages' => $messages];
});

$router->api('GET', '/api/chats', function() {
    require_auth();
    $userId = $_SESSION['user_id'];
    $chats = select(
        "SELECT c.id AS chat_id,
                c.user1_id, c.user2_id,
                CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END AS other_user_id,
                CASE WHEN c.user1_id = c.user2_id THEN 'Коллекция' ELSE u.first_name END AS first_name,
                CASE WHEN c.user1_id = c.user2_id THEN '' ELSE u.last_name END AS last_name,
                u.avatar,
                c.is_pinned,
                (SELECT 
                    CASE 
                        WHEN m.post_preview IS NOT NULL THEN 'Пост'
                        WHEN m.file_url IS NOT NULL THEN 'Файл'
                        ELSE m.content
                    END
                 FROM messages m 
                 WHERE m.chat_id = c.id 
                 ORDER BY m.created_at DESC 
                 LIMIT 1) AS last_message,
                (SELECT COUNT(*) FROM messages WHERE chat_id = c.id AND sender_id != ? AND is_read = 0) AS unread_count,
                c.last_message_at
         FROM chats c
         JOIN users u ON u.id = CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END
         WHERE c.user1_id = ? OR c.user2_id = ?
         ORDER BY c.is_pinned DESC, c.last_message_at DESC",
        [$userId, $userId, $userId, $userId, $userId]
    );
    return ['chats' => $chats];
});

$router->api('GET', '/api/groups/{groupId}/messages/poll', function($groupId) {
    require_auth();
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $userId = $_SESSION['user_id'];
    $groupId = (int)$groupId;
    $member = scalar("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?", [$groupId, $userId]);
    if (!$member) {
        http_response_code(403);
        return ['error' => 'Вы не участник группы'];
    }
    $lastId = isset($_GET['after']) ? (int)$_GET['after'] : 0;
    $stmt = db()->prepare("
        SELECT gm.id, gm.sender_id, gm.content, gm.file_url, gm.post_preview, gm.created_at, u.first_name, u.last_name, u.avatar
        FROM group_messages gm
        JOIN users u ON u.id = gm.sender_id
        WHERE gm.group_id = ? AND gm.id > ?
        ORDER BY gm.id ASC
    ");
    $stmt->execute([$groupId, $lastId]);
    $messages = $stmt->fetchAll();

    // Нормализация created_at
    foreach ($messages as &$msg) {
        if (empty($msg['created_at']) || $msg['created_at'] === '0000-00-00 00:00:00') {
            $msg['created_at'] = date('Y-m-d H:i:s');
        }
    }
    unset($msg);

    return ['messages' => $messages];
});

// ---------- ПОЛУЧЕНИЕ СООБЩЕНИЙ ГРУППЫ (ПАГИНАЦИЯ) ----------
$router->api('GET', '/api/groups/{groupId}/messages', function($groupId) {
    require_auth();
    $userId = $_SESSION['user_id'];
    $groupId = (int)$groupId;
    
    $member = scalar("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?", [$groupId, $userId]);
    if (!$member) {
        http_response_code(403);
        return ['error' => 'Вы не участник группы'];
    }
    
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? min(200, max(1, (int)$_GET['per_page'])) : 20;
    $offset = ($page - 1) * $perPage;
    
    $stmt = db()->prepare("
        SELECT gm.id, gm.sender_id, gm.content, gm.file_url, gm.post_preview, gm.created_at, 
               u.first_name, u.last_name, u.avatar
        FROM group_messages gm
        JOIN users u ON u.id = gm.sender_id
        WHERE gm.group_id = ?
        ORDER BY gm.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$groupId, $perPage, $offset]);
    $messages = $stmt->fetchAll();
    $messages = array_reverse($messages);
    
    // Нормализация created_at (защита от NULL)
    foreach ($messages as &$msg) {
        if (empty($msg['created_at']) || $msg['created_at'] === '0000-00-00 00:00:00') {
            $msg['created_at'] = date('Y-m-d H:i:s');
        }
    }
    unset($msg);
    
    db()->prepare("INSERT INTO group_reads (group_id, user_id, last_read) VALUES (?, ?, NOW()) 
                   ON DUPLICATE KEY UPDATE last_read = NOW()")->execute([$groupId, $userId]);
    
    return ['messages' => $messages];
});

// ---------- ОТПРАВКА ЛИЧНОГО СООБЩЕНИЯ ----------
$router->api('POST', '/api/messages/send', function() {
    require_auth();
    $now = date('Y-m-d H:i:s'); // фиксируем время до любых операций
    $senderId = $_SESSION['user_id'];
    $receiverId = (int)($_POST['receiver_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');

    if (!$receiverId) {
        throw new ValidationException(['receiver_id' => 'Не указан получатель']);
    }

    $receiver = find('users', $receiverId);
    if (!$receiver) {
        throw new ValidationException(['receiver_id' => 'Пользователь не найден']);
    }

    // Проверка приватности
    $privacyMessages = $receiver['privacy_messages'] ?? 'all';
    if ($privacyMessages === 'nobody') {
        http_response_code(403);
        return ['error' => 'Пользователь запретил личные сообщения'];
    }
    if ($privacyMessages === 'friends') {
        $areFriends = scalar(
            "SELECT COUNT(*) FROM friendships WHERE ((requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)) AND status = 'accepted'",
            [$senderId, $receiverId, $receiverId, $senderId]
        );
        if (!$areFriends) {
            http_response_code(403);
            return ['error' => 'Только друзья могут писать вам'];
        }
    }

    // Обработка файла (если прикреплён)
    $fileUrl = null;
    $originalFileName = null;
    if (!empty($_FILES['file']['tmp_name'])) {
        $file = $_FILES['file'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','application/pdf'];
        if (!in_array($realMime, $allowed)) {
            throw new ValidationException(['file' => 'Недопустимый тип файла (определён по содержимому)']);
        }
        $maxSize = 1024 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            throw new ValidationException(['file' => 'Файл слишком большой (макс. 1 ГБ)']);
        }
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'msg_' . bin2hex(random_bytes(8)) . '_' . $senderId . '.' . $ext;
        $uploadDir = __DIR__.'/uploads/messages/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $dest = $uploadDir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new ValidationException(['file' => 'Ошибка сохранения файла']);
        }
        $fileUrl = '/uploads/messages/'.$filename;
        $originalFileName = $file['name'];
    }

    // Если не передан ни текст, ни файл – ошибка
    if ($content === '' && !$fileUrl) {
        throw new ValidationException(['content' => 'Сообщение не может быть пустым']);
    }
    if (mb_strlen($content) > 5000) {
        throw new ValidationException(['content' => 'Сообщение слишком длинное (макс. 5000 символов)']);
    }

    // Ищем или создаём чат
    $user1 = min($senderId, $receiverId);
    $user2 = max($senderId, $receiverId);
    $stmt = db()->prepare("SELECT id FROM chats WHERE user1_id = ? AND user2_id = ?");
    $stmt->execute([$user1, $user2]);
    $chat = $stmt->fetch();
    if (!$chat) {
        db()->prepare("INSERT INTO chats (user1_id, user2_id, last_message_at) VALUES (?, ?, ?)")->execute([$user1, $user2, $now]);
        $chatId = db()->lastInsertId();
    } else {
        $chatId = $chat['id'];
        db()->prepare("UPDATE chats SET last_message_at = ? WHERE id = ?")->execute([$now, $chatId]);
    }

    // Формируем контент: если есть файл, добавляем его название
    $finalContent = $content;
    if ($fileUrl) {
        $finalContent = $content ?: "📎 Файл: " . $originalFileName;
    }

    // Сохраняем сообщение с фиксированным временем
    insert('messages', [
        'chat_id'   => $chatId,
        'sender_id' => $senderId,
        'content'   => $finalContent,
        'file_url'  => $fileUrl,
        'post_preview' => null,
        'is_read'   => 0,
        'created_at'=> $now   // <-- используем $now
    ]);
    $messageId = db()->lastInsertId();

    return [
        'success'        => true,
        'message_id'     => $messageId,
        'chat_id'        => $chatId,
        'other_user_id'  => $receiverId,
        'file_url'       => $fileUrl,
        'file_name'      => $originalFileName,
        'cleaned_content'=> $content,
        'created_at'     => $now
    ];
});

$router->api('DELETE', '/api/posts/{postId}', function($postId) {
    require_auth();
    $post = find('posts', (int)$postId);
    if (!$post || $post['user_id'] != $_SESSION['user_id']) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    if ($post['image'] && file_exists(__DIR__.'/'.$post['image'])) {
        unlink(__DIR__.'/'.$post['image']);
    }
    $mediaStmt = db()->prepare("SELECT file_url FROM post_media WHERE post_id = ?");
    $mediaStmt->execute([(int)$postId]);
    while ($media = $mediaStmt->fetch()) {
        if (file_exists(__DIR__.'/'.$media['file_url'])) {
            unlink(__DIR__.'/'.$media['file_url']);
        }
    }
    db()->prepare("DELETE FROM post_media WHERE post_id = ?")->execute([(int)$postId]);
    db()->prepare("DELETE FROM posts WHERE id = ?")->execute([(int)$postId]);
    return ['success' => true];
});

$router->api('PUT', '/api/messages/{messageId}', function($messageId) {
    require_auth();
    $msg = find('messages', (int)$messageId);
    if (!$msg || $msg['sender_id'] != $_SESSION['user_id']) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    if (time() - strtotime($msg['created_at']) > 300) {
        http_response_code(403);
        return ['error' => 'Время редактирования истекло'];
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $newContent = trim($data['content'] ?? '');
    if ($newContent === '') {
        http_response_code(422);
        return ['error' => 'Сообщение не может быть пустым'];
    }
    update('messages', (int)$messageId, ['content' => $newContent]);
    return ['success' => true, 'message' => find('messages', (int)$messageId)];
});

// ---------- ГРУППОВЫЕ ЧАТЫ ----------
$router->api('POST', '/api/groups/create', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        http_response_code(400);
        return ['error' => 'Неверный формат данных'];
    }

    $name = trim($data['name'] ?? '');
    $memberIds = $data['member_ids'] ?? [];
    if (!is_array($memberIds)) $memberIds = [];

    if ($name === '') {
        http_response_code(422);
        return ['error' => 'Название группы обязательно'];
    }
    if (count($memberIds) < 1) {
        http_response_code(422);
        return ['error' => 'Добавьте хотя бы одного участника'];
    }

    $memberIds[] = $_SESSION['user_id'];
    $memberIds = array_unique(array_map('intval', $memberIds));

    try {
        $db = db();
        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO chat_groups (name, created_by, created_at, last_message_at) VALUES (?, ?, NOW(), NOW())");
        $stmt->execute([$name, $_SESSION['user_id']]);
        $groupId = $db->lastInsertId();

        if (!$groupId) {
            throw new Exception("Не удалось создать группу");
        }

        $stmt = $db->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
        foreach ($memberIds as $uid) {
            $stmt->execute([$groupId, $uid]);
        }

        $db->commit();
        return ['success' => true, 'group_id' => $groupId];
    } catch (Exception $e) {
        if (isset($db)) $db->rollBack();
        error_log("Group create error: " . $e->getMessage());
        http_response_code(500);
        return ['error' => 'Ошибка создания группы: ' . $e->getMessage()];
    }
});

// ---------- ОТПРАВКА ФАЙЛА В ГРУППУ ----------
$router->api('POST', '/api/groups/send-file', function() {
    require_auth();
    $now = date('Y-m-d H:i:s');
    $userId = $_SESSION['user_id'];
    $groupId = (int)($_POST['group_id'] ?? 0);
    if (!$groupId) throw new ValidationException(['group_id' => 'Не указана группа']);
    $member = scalar("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?", [$groupId, $userId]);
    if (!$member) { http_response_code(403); return ['error' => 'Доступ запрещён']; }
    if (empty($_FILES['file'])) throw new ValidationException(['file' => 'Файл не загружен']);
    $file = $_FILES['file'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','application/pdf'];
    if (!in_array($realMime, $allowed)) {
        throw new ValidationException(['file' => 'Недопустимый тип файла (определён по содержимому)']);
    }
    $maxSize = 1024 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        throw new ValidationException(['file' => 'Файл слишком большой (макс. 1 ГБ)']);
    }
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'group_' . $groupId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $uploadDir = __DIR__.'/uploads/messages/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $dest = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new ValidationException(['file' => 'Ошибка сохранения файла']);
    }
    $fileUrl = '/uploads/messages/'.$filename;
    $content = "📎 Файл: {$file['name']}";
    $msgId = insert('group_messages', [
        'group_id' => $groupId,
        'sender_id' => $userId,
        'content' => $content,
        'file_url' => $fileUrl,
        'created_at' => $now
    ]);
    db()->prepare("UPDATE chat_groups SET last_message_at = ? WHERE id = ?")->execute([$now, $groupId]);

    return [
        'success' => true,
        'message_id' => $msgId,
        'file_url' => $fileUrl,
        'file_name' => $file['name'],
        'created_at' => $now
    ];
});

$router->api('GET', '/api/groups', function() {
    require_auth();
    $userId = $_SESSION['user_id'];
    $groups = select(
        "SELECT g.id, g.name, g.created_at, g.last_message_at, g.is_pinned,
                (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) AS members_count,
                (SELECT content FROM group_messages WHERE group_id = g.id ORDER BY created_at DESC LIMIT 1) AS last_message
         FROM group_members gm
         JOIN chat_groups g ON g.id = gm.group_id
         WHERE gm.user_id = ?
         GROUP BY g.id
         ORDER BY g.is_pinned DESC, g.last_message_at DESC",
        [$userId]
    );
    return ['groups' => $groups];
});

// ---------- ОТПРАВКА СООБЩЕНИЯ В ГРУППУ ----------
$router->api('POST', '/api/groups/{groupId}/messages', function($groupId) {
    require_auth();
    $now = date('Y-m-d H:i:s');
    $userId = $_SESSION['user_id'];
    $groupId = (int)$groupId;
    $member = scalar("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?", [$groupId, $userId]);
    if (!$member) {
        http_response_code(403);
        return ['error' => 'Вы не участник группы'];
    }

    $content = trim($_POST['content'] ?? '');

    // Обработка файла (если есть)
    $fileUrl = null;
    $originalFileName = null;
    if (!empty($_FILES['file']['tmp_name'])) {
        $file = $_FILES['file'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','application/pdf'];
        if (!in_array($realMime, $allowed)) {
            throw new ValidationException(['file' => 'Недопустимый тип файла']);
        }
        $maxSize = 1024 * 1024 * 1024; // 1 ГБ
        if ($file['size'] > $maxSize) {
            throw new ValidationException(['file' => 'Файл слишком большой (макс. 1 ГБ)']);
        }
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'group_' . $groupId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $uploadDir = __DIR__.'/uploads/messages/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $dest = $uploadDir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new ValidationException(['file' => 'Ошибка сохранения файла']);
        }
        $fileUrl = '/uploads/messages/'.$filename;
        $originalFileName = $file['name'];
    }

    if ($content === '' && !$fileUrl) {
        throw new ValidationException(['content' => 'Сообщение не может быть пустым']);
    }
    if (mb_strlen($content) > 5000) {
        throw new ValidationException(['content' => 'Сообщение слишком длинное (макс. 5000 символов)']);
    }

    $finalContent = $content ?: ($fileUrl ? "📎 Файл: " . $originalFileName : '');

    $msgId = insert('group_messages', [
        'group_id' => $groupId,
        'sender_id' => $userId,
        'content'   => $finalContent,
        'file_url'  => $fileUrl,
        'post_preview' => null,
        'created_at'=> $now
    ]);
    db()->prepare("UPDATE chat_groups SET last_message_at = ? WHERE id = ?")->execute([$now, $groupId]);

    $sender = find('users', $userId);
    return [
        'success'        => true,
        'message_id'     => $msgId,
        'sender_name'    => $sender['first_name'] . ' ' . $sender['last_name'],
        'file_url'       => $fileUrl,
        'file_name'      => $originalFileName,
        'cleaned_content'=> $content,
        'created_at'     => $now
    ];
});

// ---------- ПОИСК (с нечётким поиском) ----------
$router->api('GET', '/api/search/users', function() {
    require_auth();
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 1) return ['users' => []];

    // Разбиваем запрос на слова
    $words = preg_split('/[\s\-]+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($words)) return ['users' => []];

    // Порог для расстояния Левенштейна (чем меньше, тем строже)
    // Для коротких слов (<=4 символов) допустим расстояние 1, для длинных — 2
    $maxDist = function($word) {
        $len = mb_strlen($word);
        if ($len <= 4) return 1;
        return 2;
    };

    // Сначала получаем кандидатов, у которых первая буква имени или фамилии совпадает с первой буквой любого слова запроса
    $firstLetters = array_unique(array_map(function($w) { return mb_substr($w, 0, 1); }, $words));
    $likeConditions = [];
    $params = [];
    foreach ($firstLetters as $letter) {
        $likeConditions[] = "(first_name LIKE ? OR last_name LIKE ?)";
        $params[] = "$letter%";
        $params[] = "$letter%";
    }
    $where = implode(' OR ', $likeConditions);
    $candidates = select("SELECT id, first_name, last_name, avatar FROM users WHERE $where LIMIT 200", $params);

    // Функция для вычисления расстояния Левенштейна между двумя строками
    $levenshtein = function($s1, $s2) {
        $s1 = mb_strtolower($s1);
        $s2 = mb_strtolower($s2);
        return levenshtein($s1, $s2);
    };

    // Для каждого кандидата проверяем, насколько его имя или фамилия близки к каждому слову запроса
    $results = [];
    foreach ($candidates as $c) {
        $fullName = mb_strtolower($c['first_name'] . ' ' . $c['last_name']);
        $parts = [mb_strtolower($c['first_name']), mb_strtolower($c['last_name']), $fullName];

        $matches = true;
        foreach ($words as $word) {
            $wordLower = mb_strtolower($word);
            $found = false;
            foreach ($parts as $part) {
                // Если слово целиком содержится в части, сразу считаем совпадением
                if (mb_strpos($part, $wordLower) !== false) {
                    $found = true;
                    break;
                }
                // Иначе вычисляем расстояние Левенштейна между словом и частью
                // (для длинных частей можно проверять расстояние на подстроках, но для MVP допустим прямое сравнение)
                $dist = $levenshtein($wordLower, $part);
                if ($dist <= $maxDist($wordLower)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $matches = false;
                break;
            }
        }
        if ($matches) {
            $results[] = $c;
        }
    }

    return ['users' => array_slice($results, 0, 10)];
});

// ---------- ПОСТЫ ----------
$router->api('POST', '/api/posts/create', function() {
    require_auth();
    $content = trim($_POST['content'] ?? '');
    $hasFiles = isset($_FILES['files']) && is_array($_FILES['files']['tmp_name']) && count(array_filter($_FILES['files']['tmp_name'])) > 0;

    if ($content === '' && !$hasFiles) {
        http_response_code(422);
        return ['error' => 'Пост не может быть пустым'];
    }

    $postId = insert('posts', [
        'user_id' => $_SESSION['user_id'],
        'content' => $content,
        'likes_count' => 0,
        'dislikes_count' => 0
    ]);

    $mediaItems = [];
    if ($hasFiles) {
        $allowedMimes = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/webm','video/quicktime'];
        $uploadDir = __DIR__ . '/uploads/posts/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        foreach ($_FILES['files']['tmp_name'] as $i => $tmpPath) {
            if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmpPath)) {
                continue;
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $realMime = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);

            if (!in_array($realMime, $allowedMimes)) {
                continue;
            }

            $ext = pathinfo($_FILES['files']['name'][$i], PATHINFO_EXTENSION);
            $filename = 'post_' . $_SESSION['user_id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest = $uploadDir . $filename;

            if (move_uploaded_file($tmpPath, $dest)) {
                $fileUrl = 'uploads/posts/' . $filename;
                $mediaType = strpos($realMime, 'video/') === 0 ? 'video' : 'image';

                insert('post_media', [
                    'post_id' => $postId,
                    'file_url' => $fileUrl,
                    'media_type' => $mediaType
                ]);

                $mediaItems[] = ['url' => $fileUrl, 'type' => $mediaType];
            }
        }
    }

    db()->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['user_id']]);

    return [
        'success' => true,
        'post' => [
            'id' => $postId,
            'content' => $content,
            'media' => $mediaItems,
            'likes_count' => 0,
            'dislikes_count' => 0,
            'user_id' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d H:i:s')
        ]
    ];
});

$router->api('POST', '/api/posts/{postId}/edit', function($postId) {
    require_auth();
    $post = find('posts', (int)$postId);
    if (!$post || $post['user_id'] != $_SESSION['user_id']) { http_response_code(403); return ['error' => 'Доступ запрещён']; }
    if (time() - strtotime($post['created_at']) > 86400) { http_response_code(403); return ['error' => 'Время редактирования истекло']; }
    $content = $_POST['content'] ?? null;
    $imagePath = $post['image'];
    if (!empty($_FILES['file']['tmp_name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg','image/png','image/gif','video/mp4','video/webm','video/quicktime'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $_FILES['file']['tmp_name']);
        finfo_close($finfo);
        if (in_array($realMime, $allowed)) {
            $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
            $filename = 'post_'.$_SESSION['user_id'].'_'.time().'.'.$ext;
            $uploadDir = __DIR__.'/uploads/posts/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $dest = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                if ($post['image'] && file_exists(__DIR__.'/'.$post['image'])) unlink(__DIR__.'/'.$post['image']);
                $imagePath = 'uploads/posts/'.$filename;
            }
        }
    }
    $updateData = [];
    if ($content !== null) $updateData['content'] = trim($content);
    if ($imagePath !== $post['image']) $updateData['image'] = $imagePath;
    if (!empty($updateData)) update('posts', (int)$postId, $updateData);
    return ['success' => true, 'post' => find('posts', (int)$postId)];
});

$router->api('POST', '/api/posts/like', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $postId = (int)($data['post_id'] ?? 0);
    if ($postId <= 0) return ['error' => 'Неверный ID поста'];
    $userId = $_SESSION['user_id'];
    $post = find('posts', $postId);
    if (!$post) return ['error' => 'Пост не найден'];
    if (!canInteractWithPost($postId, $userId)) { http_response_code(403); return ['error' => 'Доступ запрещён']; }
    $stmt = db()->prepare("SELECT id FROM post_reactions WHERE post_id = ? AND user_id = ? AND reaction = 'like'");
    $stmt->execute([$postId, $userId]);
    $existing = $stmt->fetch();
    if ($existing) {
        db()->prepare("DELETE FROM post_reactions WHERE id = ?")->execute([$existing['id']]);
        db()->prepare("UPDATE posts SET likes_count = likes_count - 1 WHERE id = ?")->execute([$postId]);
        $userLiked = false;
    } else {
        db()->prepare("INSERT INTO post_reactions (post_id, user_id, reaction) VALUES (?, ?, 'like')")->execute([$postId, $userId]);
        db()->prepare("UPDATE posts SET likes_count = likes_count + 1 WHERE id = ?")->execute([$postId]);
        $userLiked = true;
        // уведомление автору поста
        if ($post['user_id'] != $userId) {
            db()->prepare("INSERT INTO notifications (user_id, type, actor_id, post_id) VALUES (?, 'like', ?, ?)")
                ->execute([$post['user_id'], $userId, $postId]);
        }
    }
    $updatedPost = find('posts', $postId);
    return ['success' => true, 'likes_count' => $updatedPost['likes_count'], 'dislikes_count' => $updatedPost['dislikes_count'], 'user_liked' => $userLiked, 'user_disliked' => false];
});

$router->api('POST', '/api/posts/dislike', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $postId = (int)($data['post_id'] ?? 0);
    if ($postId <= 0) return ['error' => 'Неверный ID поста'];
    $userId = $_SESSION['user_id'];
    $post = find('posts', $postId);
    if (!$post) return ['error' => 'Пост не найден'];
    if (!canInteractWithPost($postId, $userId)) { http_response_code(403); return ['error' => 'Доступ запрещён']; }
    $stmt = db()->prepare("SELECT id FROM post_reactions WHERE post_id = ? AND user_id = ? AND reaction = 'dislike'");
    $stmt->execute([$postId, $userId]);
    $existing = $stmt->fetch();
    if ($existing) {
        db()->prepare("DELETE FROM post_reactions WHERE id = ?")->execute([$existing['id']]);
        db()->prepare("UPDATE posts SET dislikes_count = dislikes_count - 1 WHERE id = ?")->execute([$postId]);
        $userDisliked = false;
    } else {
        db()->prepare("INSERT INTO post_reactions (post_id, user_id, reaction) VALUES (?, ?, 'dislike')")->execute([$postId, $userId]);
        db()->prepare("UPDATE posts SET dislikes_count = dislikes_count + 1 WHERE id = ?")->execute([$postId]);
        $userDisliked = true;
    }
    $updatedPost = find('posts', $postId);
    return ['success' => true, 'likes_count' => $updatedPost['likes_count'], 'dislikes_count' => $updatedPost['dislikes_count'], 'user_liked' => false, 'user_disliked' => $userDisliked];
});

$router->api('GET', '/api/posts/{postId}/comments', function($postId) {
    require_auth();
    $postId = (int)$postId;
    $comments = select("SELECT c.*, u.first_name, u.last_name, u.avatar FROM comments c JOIN users u ON u.id = c.user_id WHERE c.post_id = ? ORDER BY c.created_at ASC", [$postId]);
    return ['comments' => $comments];
});

$router->api('POST', '/api/posts/{postId}/comments', function($postId) {
    require_auth();
    $postId = (int)$postId;
    $userId = $_SESSION['user_id'];
    $post = find('posts', $postId);
    if (!$post) return ['error' => 'Пост не найден'];
    if (!canInteractWithPost($postId, $userId)) { http_response_code(403); return ['error' => 'Доступ запрещён']; }
    $data = json_decode(file_get_contents('php://input'), true);
    $content = trim($data['content'] ?? '');
    if ($content === '') { http_response_code(422); return ['error' => 'Комментарий не может быть пустым']; }
    $commentId = insert('comments', ['post_id' => $postId, 'user_id' => $userId, 'content' => $content]);
    $author = find('users', $userId);
    $comment = find('comments', $commentId);
    $comment['first_name'] = $author['first_name'];
    $comment['last_name']  = $author['last_name'];
    $comment['avatar']     = $author['avatar'] ?? '';
    return ['success' => true, 'comment' => $comment];
});

$router->api('POST', '/api/posts/{postId}/hide', function($postId) {
    require_auth();
    $post = find('posts', (int)$postId);
    if (!$post) return ['error' => 'Пост не найден'];
    if ($post['user_id'] == $_SESSION['user_id']) return ['error' => 'Нельзя скрыть свой пост'];
    db()->prepare("INSERT IGNORE INTO hidden_posts (user_id, post_id) VALUES (?, ?)")->execute([$_SESSION['user_id'], (int)$postId]);
    return ['success' => true];
});

$router->api('DELETE', '/api/posts/{postId}/hide', function($postId) {
    require_auth();
    db()->prepare("DELETE FROM hidden_posts WHERE user_id = ? AND post_id = ?")->execute([$_SESSION['user_id'], (int)$postId]);
    return ['success' => true];
});

$router->api('DELETE', '/api/posts/{postId}', function($postId) {
    require_auth();
    $post = find('posts', (int)$postId);
    if (!$post || $post['user_id'] != $_SESSION['user_id']) { http_response_code(403); return ['error' => 'Доступ запрещён']; }
    if ($post['image'] && file_exists(__DIR__.'/'.$post['image'])) unlink(__DIR__.'/'.$post['image']);
    db()->prepare("DELETE FROM posts WHERE id = ?")->execute([(int)$postId]);
    return ['success' => true];
});

$router->api('GET', '/api/users/{id}', function($id) {
    require_auth();
    $user = find('users', (int)$id);
    if (!$user) {
        http_response_code(404);
        return ['error' => 'Пользователь не найден'];
    }
    return [
        'id' => $user['id'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'avatar' => $user['avatar'] ?? ''
    ];
});

// ---------- МЕДИАХАБ ----------
$router->api('GET', '/api/media/private/{chatId}', function($chatId) {
    require_auth();
    $userId = $_SESSION['user_id'];
    $chatId = (int)$chatId;
    
    $chat = find('chats', $chatId);
    if (!$chat || ($chat['user1_id'] != $userId && $chat['user2_id'] != $userId)) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    
    $type = $_GET['type'] ?? 'photo';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(50, max(1, (int)($_GET['per_page'] ?? 24)));
    $offset = ($page - 1) * $perPage;
    
    $extCondition = '';
    if ($type === 'photo') {
        $extCondition = "AND LOWER(SUBSTRING_INDEX(m.file_url, '.', -1)) IN ('jpg', 'jpeg', 'png', 'gif', 'webp')";
    } elseif ($type === 'video') {
        $extCondition = "AND LOWER(SUBSTRING_INDEX(m.file_url, '.', -1)) IN ('mp4', 'webm', 'mov', 'avi', 'mkv')";
    } elseif ($type === 'file') {
        $extCondition = "AND LOWER(SUBSTRING_INDEX(m.file_url, '.', -1)) NOT IN ('jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov', 'avi', 'mkv')";
    }
    
    $sql = "SELECT m.id, m.file_url, m.content, m.created_at, m.sender_id,
                   u.first_name, u.last_name
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            WHERE m.chat_id = ? AND m.file_url IS NOT NULL AND m.file_url != ''
            $extCondition
            ORDER BY m.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = db()->prepare($sql);
    $stmt->execute([$chatId, $perPage, $offset]);
    $rows = $stmt->fetchAll();
    
    $items = [];
    foreach ($rows as $row) {
        $ext = strtolower(pathinfo($row['file_url'], PATHINFO_EXTENSION));
        $mediaType = 'file';
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) $mediaType = 'photo';
        elseif (in_array($ext, ['mp4','webm','mov','avi','mkv'])) $mediaType = 'video';
        
        $items[] = [
            'id' => $row['id'],
            'type' => $mediaType,
            'url' => $row['file_url'],
            'name' => basename($row['file_url']),
            'created_at' => $row['created_at'],
            'sender_id' => $row['sender_id'],
            'sender_name' => $row['first_name'] . ' ' . $row['last_name'],
            'message_id' => $row['id']
        ];
    }
    
    $hasMore = count($items) === $perPage;
    return ['items' => $items, 'has_more' => $hasMore];
});

$router->api('GET', '/api/media/group/{groupId}', function($groupId) {
    require_auth();
    $userId = $_SESSION['user_id'];
    $groupId = (int)$groupId;
    
    $member = scalar("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?", [$groupId, $userId]);
    if (!$member) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    
    $type = $_GET['type'] ?? 'photo';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(50, max(1, (int)($_GET['per_page'] ?? 24)));
    $offset = ($page - 1) * $perPage;
    
    $extCondition = '';
    if ($type === 'photo') {
        $extCondition = "AND LOWER(SUBSTRING_INDEX(gm.file_url, '.', -1)) IN ('jpg', 'jpeg', 'png', 'gif', 'webp')";
    } elseif ($type === 'video') {
        $extCondition = "AND LOWER(SUBSTRING_INDEX(gm.file_url, '.', -1)) IN ('mp4', 'webm', 'mov', 'avi', 'mkv')";
    } elseif ($type === 'file') {
        $extCondition = "AND LOWER(SUBSTRING_INDEX(gm.file_url, '.', -1)) NOT IN ('jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov', 'avi', 'mkv')";
    }
    
    $sql = "SELECT gm.id, gm.file_url, gm.content, gm.created_at, gm.sender_id,
                   u.first_name, u.last_name
            FROM group_messages gm
            JOIN users u ON u.id = gm.sender_id
            WHERE gm.group_id = ? AND gm.file_url IS NOT NULL AND gm.file_url != ''
            $extCondition
            ORDER BY gm.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = db()->prepare($sql);
    $stmt->execute([$groupId, $perPage, $offset]);
    $rows = $stmt->fetchAll();
    
    $items = [];
    foreach ($rows as $row) {
        $ext = strtolower(pathinfo($row['file_url'], PATHINFO_EXTENSION));
        $mediaType = 'file';
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) $mediaType = 'photo';
        elseif (in_array($ext, ['mp4','webm','mov','avi','mkv'])) $mediaType = 'video';
        
        $items[] = [
            'id' => $row['id'],
            'type' => $mediaType,
            'url' => $row['file_url'],
            'name' => basename($row['file_url']),
            'created_at' => $row['created_at'],
            'sender_id' => $row['sender_id'],
            'sender_name' => $row['first_name'] . ' ' . $row['last_name'],
            'message_id' => $row['id']
        ];
    }
    
    $hasMore = count($items) === $perPage;
    return ['items' => $items, 'has_more' => $hasMore];
});

// ---------- УЧАСТНИКИ ГРУППЫ ----------
$router->api('GET', '/api/groups/{groupId}/members', function($groupId) {
    require_auth();
    $currentUserId = $_SESSION['user_id'];
    $groupId = (int)$groupId;
    
    $member = scalar("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?", [$groupId, $currentUserId]);
    if (!$member) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    
    $group = find('chat_groups', $groupId);
    $creatorId = $group['created_by'] ?? null;
    
    $stmt = db()->prepare("
        SELECT 
            u.id, 
            u.first_name, 
            u.last_name, 
            u.avatar
        FROM group_members gm
        INNER JOIN users u ON u.id = gm.user_id
        WHERE gm.group_id = ?
        ORDER BY u.first_name ASC
    ");
    $stmt->execute([$groupId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($members as &$member) {
        $member['role'] = ($member['id'] == $creatorId) ? 'admin' : 'member';
    }
    
    return ['members' => $members];
});

// ---------- ЗАКРЕПЛЕНИЕ ЧАТОВ ----------
$router->api('POST', '/api/chats/pin', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $chatId = (int)($data['chat_id'] ?? 0);
    $pin = (bool)($data['pin'] ?? true);
    if (!$chatId) throw new ValidationException(['chat_id' => 'Не указан чат']);
    $chat = find('chats', $chatId);
    if (!$chat || ($chat['user1_id'] != $_SESSION['user_id'] && $chat['user2_id'] != $_SESSION['user_id'])) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    db()->prepare("UPDATE chats SET is_pinned = ? WHERE id = ?")->execute([$pin ? 1 : 0, $chatId]);
    return ['success' => true, 'pinned' => $pin];
});

$router->api('POST', '/api/groups/pin', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $groupId = (int)($data['group_id'] ?? 0);
    $pin = (bool)($data['pin'] ?? true);
    if (!$groupId) throw new ValidationException(['group_id' => 'Не указана группа']);
    $member = scalar("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?", [$groupId, $_SESSION['user_id']]);
    if (!$member) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    db()->prepare("UPDATE chat_groups SET is_pinned = ? WHERE id = ?")->execute([$pin ? 1 : 0, $groupId]);
    return ['success' => true, 'pinned' => $pin];
});

// ---------- ЛЕНТА НОВОСТЕЙ ----------
$router->api('GET', '/api/feed/posts', function() {
    require_auth();
    $userId = $_SESSION['user_id'];
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 10;
    $offset = ($page - 1) * $limit;

    $friends = select(
        "SELECT u.id FROM friendships f
         JOIN users u ON u.id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END
         WHERE (f.requester_id = ? OR f.addressee_id = ?) AND f.status = 'accepted'",
        [$userId, $userId, $userId]
    );
    $friendIds = array_column($friends, 'id');
    $friendIds[] = $userId;
    $placeholders = implode(',', array_fill(0, count($friendIds), '?'));

    $sql = "
        SELECT p.*, u.first_name, u.last_name, u.avatar,
               (SELECT GROUP_CONCAT(CONCAT(pm.id, '|', pm.file_url, '|', pm.media_type) SEPARATOR ',') 
                FROM post_media pm WHERE pm.post_id = p.id) AS media_list
        FROM posts p
        JOIN users u ON u.id = p.user_id
        WHERE 
            (p.user_id = ?) OR
            (p.user_id IN ($placeholders) AND u.privacy_posts IN ('friends', 'public')) OR
            (u.privacy_posts = 'public')
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $params = array_merge([$userId], $friendIds, [$limit, $offset]);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll();

    $result = [];
    foreach ($posts as $post) {
        $media = [];
        if (!empty($post['media_list'])) {
            $parts = explode(',', $post['media_list']);
            foreach ($parts as $part) {
                list($id, $url, $type) = explode('|', $part);
                $media[] = ['id' => $id, 'url' => $url, 'type' => $type];
            }
        } elseif (!empty($post['image'])) {
            $oldType = preg_match('/\.(mp4|webm|mov)$/i', $post['image']) ? 'video' : 'image';
            $media[] = ['id' => 0, 'url' => $post['image'], 'type' => $oldType];
        }
        unset($post['image'], $post['media_list']);
        $post['media'] = $media;
        
        $stmt2 = db()->prepare("SELECT reaction FROM post_reactions WHERE post_id = ? AND user_id = ?");
        $stmt2->execute([$post['id'], $userId]);
        $react = $stmt2->fetch();
        $post['user_reaction'] = $react ? $react['reaction'] : null;
        
        $result[] = $post;
    }

    return ['posts' => $result, 'has_more' => count($result) === $limit];
});

// ---------- ФОТОАЛЬБОМ ----------
$router->api('POST', '/api/upload-photo', function() {
    require_auth();
    $userId = $_SESSION['user_id'];
    if (empty($_FILES['photo'])) {
        http_response_code(400);
        return ['error' => 'Нет файла'];
    }
    $file = $_FILES['photo'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if (!in_array($realMime, $allowed)) {
        http_response_code(400);
        return ['error' => 'Недопустимый тип файла'];
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        http_response_code(400);
        return ['error' => 'Файл слишком большой (макс. 10 МБ)'];
    }
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'photo_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $uploadDir = __DIR__ . '/uploads/photos/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $dest = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500);
        return ['error' => 'Ошибка сохранения файла'];
    }
    $fileUrl = '/uploads/photos/' . $filename;
    db()->prepare("INSERT INTO user_photos (user_id, file_url, created_at) VALUES (?, ?, NOW())")->execute([$userId, $fileUrl]);
    $photoId = db()->lastInsertId();
    return ['success' => true, 'photo' => ['id' => $photoId, 'url' => $fileUrl]];
});

$router->api('GET', '/api/get-photos', function() {
    require_auth();
    $userId = $_SESSION['user_id'];
    $stmt = db()->prepare("SELECT id, file_url, created_at FROM user_photos WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($photos as &$photo) {
        $photo['url'] = $photo['file_url'];
        unset($photo['file_url']);
    }
    return ['photos' => $photos];
});

$router->api('POST', '/api/delete-photo', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $photoId = (int)($data['photo_id'] ?? 0);
    if (!$photoId) {
        http_response_code(400);
        return ['error' => 'Не указан ID фото'];
    }
    $stmt = db()->prepare("SELECT user_id, file_url FROM user_photos WHERE id = ?");
    $stmt->execute([$photoId]);
    $photo = $stmt->fetch();
    if (!$photo || $photo['user_id'] != $_SESSION['user_id']) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    $filePath = __DIR__ . $photo['file_url'];
    if (file_exists($filePath)) unlink($filePath);
    db()->prepare("DELETE FROM user_photos WHERE id = ?")->execute([$photoId]);
    return ['success' => true];
});

$router->api('GET', '/api/get-user-photos', function() {
    require_auth();
    $userId = (int)($_GET['user_id'] ?? 0);
    if (!$userId) {
        http_response_code(400);
        return ['error' => 'Не указан user_id'];
    }
    $user = find('users', $userId);
    if (!$user) {
        http_response_code(404);
        return ['error' => 'Пользователь не найден'];
    }
    $stmt = db()->prepare("SELECT id, file_url, created_at FROM user_photos WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($photos as &$photo) {
        $photo['url'] = $photo['file_url'];
    }
    return ['photos' => $photos];
});

// ---------- УДАЛЕНИЕ И РЕДАКТИРОВАНИЕ СООБЩЕНИЙ В ГРУППЕ ----------
$router->api('DELETE', '/api/group-messages/{messageId}', function($messageId) {
    require_auth();
    $userId = $_SESSION['user_id'];
    $stmt = db()->prepare("
        SELECT gm.*, cg.created_by 
        FROM group_messages gm 
        JOIN chat_groups cg ON cg.id = gm.group_id 
        WHERE gm.id = ?
    ");
    $stmt->execute([(int)$messageId]);
    $msg = $stmt->fetch();
    
    if (!$msg) {
        http_response_code(404);
        return ['error' => 'Сообщение не найдено'];
    }
    if ($msg['sender_id'] != $userId && $msg['created_by'] != $userId) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    if (!empty($msg['file_url']) && file_exists(__DIR__ . '/' . $msg['file_url'])) {
        unlink(__DIR__ . '/' . $msg['file_url']);
    }
    db()->prepare("DELETE FROM group_messages WHERE id = ?")->execute([(int)$messageId]);
    return ['success' => true];
});

$router->api('PUT', '/api/group-messages/{messageId}', function($messageId) {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $newContent = trim($data['content'] ?? '');
    if ($newContent === '') {
        http_response_code(422);
        return ['error' => 'Сообщение не может быть пустым'];
    }
    if (mb_strlen($newContent) > 5000) {
        http_response_code(422);
        return ['error' => 'Сообщение слишком длинное'];
    }
    $msg = find('group_messages', (int)$messageId);
    if (!$msg || $msg['sender_id'] != $_SESSION['user_id']) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    if (time() - strtotime($msg['created_at']) > 300) {
        http_response_code(403);
        return ['error' => 'Время редактирования истекло'];
    }
    update('group_messages', (int)$messageId, ['content' => $newContent]);
    $updated = find('group_messages', (int)$messageId);
    return ['success' => true, 'message' => $updated];
});

// Создаём таблицы, если их нет
db()->exec("CREATE TABLE IF NOT EXISTS user_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    file_url VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX(user_id)
)");

// ---------- КОЛЛЕКЦИЯ (Избранное) ----------
$router->api('POST', '/api/collection/add', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $postId = (int)($data['post_id'] ?? 0);
    $userId = $_SESSION['user_id'];
    
    $post = find('posts', $postId);
    if (!$post) {
        http_response_code(404);
        return ['error' => 'Пост не найден'];
    }
    
    // Автор поста
    $author = find('users', $post['user_id']);
    if (!$author) {
        http_response_code(500);
        return ['error' => 'Автор поста не найден'];
    }
    
    // Формируем post_preview
    $mediaUrl = null;
    $mediaType = null;
    if (!empty($post['image'])) {
        $mediaUrl = $post['image'];
        $mediaType = preg_match('/\.(mp4|webm|mov)$/i', $post['image']) ? 'video' : 'image';
    } else {
        $stmtMedia = db()->prepare("SELECT file_url, media_type FROM post_media WHERE post_id = ? LIMIT 1");
        $stmtMedia->execute([$postId]);
        $media = $stmtMedia->fetch();
        if ($media) {
            $mediaUrl = $media['file_url'];
            $mediaType = $media['media_type'];
        }
    }
    $postPreview = json_encode([
        'post_id'      => $postId,
        'author_name'  => $author['first_name'] . ' ' . $author['last_name'],
        'author_avatar'=> $author['avatar'] ?? '',
        'content'      => mb_substr($post['content'] ?? '', 0, 150),
        'media_url'    => $mediaUrl,
        'media_type'   => $mediaType,
        'likes_count'  => $post['likes_count'],
        'url'          => "/post.php?id={$postId}"
    ], JSON_UNESCAPED_SLASHES);
    
    // Ищем или создаём коллекционный чат (чат с самим собой)
    $stmt = db()->prepare("SELECT id FROM chats WHERE user1_id = ? AND user2_id = ?");
    $stmt->execute([$userId, $userId]);
    $chat = $stmt->fetch();
    if (!$chat) {
        db()->prepare("INSERT INTO chats (user1_id, user2_id, last_message_at) VALUES (?, ?, NOW())")
           ->execute([$userId, $userId]);
        $chatId = db()->lastInsertId();
    } else {
        $chatId = $chat['id'];
    }
    
    // Вставляем сообщение в коллекцию
    insert('messages', [
        'chat_id'      => $chatId,
        'sender_id'    => $userId,
        'content'      => '',
        'post_preview' => $postPreview,
        'is_read'      => 1
    ]);
    
    return ['success' => true, 'chat_id' => $chatId];
});

// ---------- ОЧИСТКА ИСТОРИИ ЛИЧНОГО ЧАТА ----------
$router->api('DELETE', '/api/chats/{chatId}/clear', function($chatId) {
    require_auth();
    $userId = $_SESSION['user_id'];
    $chatId = (int)$chatId;
    
    // Проверяем, что пользователь является участником чата
    $chat = find('chats', $chatId);
    if (!$chat || ($chat['user1_id'] != $userId && $chat['user2_id'] != $userId)) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    
    db()->prepare("DELETE FROM messages WHERE chat_id = ?")->execute([$chatId]);
    return ['success' => true];
});

// ---------- ОЧИСТКА ИСТОРИИ ГРУППОВОГО ЧАТА ----------
$router->api('DELETE', '/api/groups/{groupId}/clear', function($groupId) {
    require_auth();
    $userId = $_SESSION['user_id'];
    $groupId = (int)$groupId;
    
    // Проверяем, что пользователь состоит в группе
    $member = scalar("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?", [$groupId, $userId]);
    if (!$member) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    
    db()->prepare("DELETE FROM group_messages WHERE group_id = ?")->execute([$groupId]);
    return ['success' => true];
});

// ---------- УДАЛЕНИЕ ЛИЧНОГО ЧАТА ----------
$router->api('DELETE', '/api/chats/{chatId}', function($chatId) {
    require_auth();
    $userId = $_SESSION['user_id'];
    $chat = find('chats', (int)$chatId);
    if (!$chat || ($chat['user1_id'] != $userId && $chat['user2_id'] != $userId)) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    db()->prepare("DELETE FROM chats WHERE id = ?")->execute([(int)$chatId]);
    return ['success' => true];
});

// ---------- УДАЛЕНИЕ ГРУППОВОГО ЧАТА ----------
$router->api('DELETE', '/api/groups/{groupId}', function($groupId) {
    require_auth();
    $userId = $_SESSION['user_id'];
    $member = scalar("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?", [(int)$groupId, $userId]);
    if (!$member) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    db()->prepare("DELETE FROM chat_groups WHERE id = ?")->execute([(int)$groupId]);
    return ['success' => true];
});

// ---------- УДАЛЕНИЕ ЛИЧНОГО СООБЩЕНИЯ ----------
$router->api('DELETE', '/api/messages/{messageId}', function($messageId) {
    require_auth();
    $userId = $_SESSION['user_id'];
    $msg = find('messages', (int)$messageId);
    if (!$msg) {
        http_response_code(404);
        return ['error' => 'Сообщение не найдено'];
    }
    // Проверяем, что пользователь — отправитель сообщения
    if ($msg['sender_id'] != $userId) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    // Удаляем файл, если был прикреплён
    if (!empty($msg['file_url']) && file_exists(__DIR__ . '/' . $msg['file_url'])) {
        unlink(__DIR__ . '/' . $msg['file_url']);
    }
    db()->prepare("DELETE FROM messages WHERE id = ?")->execute([(int)$messageId]);
    return ['success' => true];
});

// ---------- КОЛЛЕКЦИЯ: получение или создание чата ----------
$router->api('GET', '/api/collection/chat', function() {
    require_auth();
    $userId = $_SESSION['user_id'];
    $stmt = db()->prepare("SELECT id FROM chats WHERE user1_id = ? AND user2_id = ?");
    $stmt->execute([$userId, $userId]);
    $chat = $stmt->fetch();
    if (!$chat) {
        db()->prepare("INSERT INTO chats (user1_id, user2_id, last_message_at) VALUES (?, ?, NOW())")
           ->execute([$userId, $userId]);
        $chatId = db()->lastInsertId();
    } else {
        $chatId = $chat['id'];
    }
    return ['chat_id' => $chatId];
});

// ---------- ОТПРАВКА ФАЙЛА В ЛИЧНЫЙ ЧАТ ----------
$router->api('POST', '/api/messages/send-file', function() {
    require_auth();
    $now = date('Y-m-d H:i:s');
    $senderId = $_SESSION['user_id'];
    $receiverId = (int)($_POST['receiver_id'] ?? 0);
    if (!$receiverId) throw new ValidationException(['receiver_id' => 'Не указан получатель']);
    
    $receiver = find('users', $receiverId);
    if (!$receiver) throw new ValidationException(['receiver_id' => 'Пользователь не найден']);
    
    $privacyMessages = $receiver['privacy_messages'] ?? 'all';
    if ($privacyMessages === 'nobody') {
        http_response_code(403);
        return ['error' => 'Пользователь запретил личные сообщения'];
    }
    if ($privacyMessages === 'friends') {
        $areFriends = scalar(
            "SELECT COUNT(*) FROM friendships WHERE ((requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)) AND status = 'accepted'",
            [$senderId, $receiverId, $receiverId, $senderId]
        );
        if (!$areFriends) {
            http_response_code(403);
            return ['error' => 'Только друзья могут писать вам'];
        }
    }
    
    if (empty($_FILES['file'])) throw new ValidationException(['file' => 'Файл не загружен']);
    $file = $_FILES['file'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','application/pdf'];
    if (!in_array($realMime, $allowed)) {
        throw new ValidationException(['file' => 'Недопустимый тип файла (определён по содержимому)']);
    }
    $maxSize = 1024 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        throw new ValidationException(['file' => 'Файл слишком большой (макс. 1 ГБ)']);
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'msg_' . bin2hex(random_bytes(8)) . '_' . $senderId . '.' . $ext;
    $uploadDir = __DIR__.'/uploads/messages/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $dest = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new ValidationException(['file' => 'Ошибка сохранения файла']);
    }
    $fileUrl = '/uploads/messages/'.$filename;
    $content = "📎 Файл: {$file['name']}";
    
    $user1 = min($senderId, $receiverId);
    $user2 = max($senderId, $receiverId);
    $stmt = db()->prepare("SELECT id FROM chats WHERE user1_id = ? AND user2_id = ?");
    $stmt->execute([$user1, $user2]);
    $chat = $stmt->fetch();
    if (!$chat) {
        db()->prepare("INSERT INTO chats (user1_id, user2_id, last_message_at) VALUES (?, ?, ?)")->execute([$user1, $user2, $now]);
        $chatId = db()->lastInsertId();
    } else {
        $chatId = $chat['id'];
        db()->prepare("UPDATE chats SET last_message_at = ? WHERE id = ?")->execute([$now, $chatId]);
    }
    
    $msgId = insert('messages', [
        'chat_id' => $chatId,
        'sender_id' => $senderId,
        'content' => $content,
        'file_url' => $fileUrl,
        'is_read' => 0,
        'created_at' => $now
    ]);

    return [
        'success' => true,
        'message_id' => $msgId,
        'file_url' => $fileUrl,
        'file_name' => $file['name'],
        'chat_id' => $chatId,
        'other_user_id' => $receiverId,
        'created_at' => $now
    ];
});

// ---------- ПРЕВЬЮ ВСЕХ ЧАТОВ (безопасный поллинг) ----------
$router->api('GET', '/api/chats/preview', function() {
    require_auth();
    $userId = $_SESSION['user_id'];

    // Приватные чаты
    $stmt = db()->prepare("
        SELECT c.id AS chat_id, 'private' AS type,
               (SELECT CASE
                           WHEN m.post_preview IS NOT NULL THEN '📎 Пост'
                           WHEN m.file_url IS NOT NULL THEN '📎 Файл'
                           ELSE m.content
                       END
                FROM messages m
                WHERE m.chat_id = c.id
                ORDER BY m.created_at DESC
                LIMIT 1) AS last_message,
               (SELECT m2.created_at
                FROM messages m2
                WHERE m2.chat_id = c.id
                ORDER BY m2.created_at DESC
                LIMIT 1) AS last_message_at,
               (SELECT COUNT(*) FROM messages WHERE chat_id = c.id AND sender_id != ? AND is_read = 0) AS unread_count
        FROM chats c
        WHERE c.user1_id = ? OR c.user2_id = ?
    ");
    $stmt->execute([$userId, $userId, $userId]);
    $privateChats = $stmt->fetchAll();

    // Группы
    $stmt = db()->prepare("
        SELECT cg.id AS chat_id, 'group' AS type,
               (SELECT CASE
                           WHEN gm.post_preview IS NOT NULL THEN '📎 Пост'
                           WHEN gm.file_url IS NOT NULL THEN '📎 Файл'
                           ELSE gm.content
                       END
                FROM group_messages gm
                WHERE gm.group_id = cg.id
                ORDER BY gm.created_at DESC
                LIMIT 1) AS last_message,
               (SELECT gm2.created_at
                FROM group_messages gm2
                WHERE gm2.group_id = cg.id
                ORDER BY gm2.created_at DESC
                LIMIT 1) AS last_message_at,
               0 AS unread_count
        FROM group_members gm_user
        JOIN chat_groups cg ON cg.id = gm_user.group_id
        WHERE gm_user.user_id = ?
    ");
    $stmt->execute([$userId]);
    $groupChats = $stmt->fetchAll();

    return [
        'previews' => array_merge($privateChats, $groupChats)
    ];
});

// ---------- ПОРУЧИТЕЛЬСТВО (DFSN – ПОЛНОСТЬЮ РАБОЧИЙ) ----------
$router->api('POST', '/api/dfsn/endorse', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $fromUserId = $_SESSION['user_id'];
    $toUserId = (int)($data['user_id'] ?? 0);

    if ($toUserId <= 0) {
        http_response_code(422);
        return ['error' => 'Не указан пользователь'];
    }

    require_once __DIR__ . '/dfsn.php';
    $dfsn = new DFSN();

    try {
        $result = $dfsn->processEndorsement($fromUserId, $toUserId);
        switch ($result) {
            case 'success':
                return ['success' => true, 'message' => 'Поручительство принято'];
            case 'self_endorsement_denied':
                http_response_code(422);
                return ['error' => 'Нельзя поручиться за самого себя'];
            case 'low_activity_denied':
                http_response_code(403);
                return ['error' => 'Недостаточная активность для поручительства'];
            case 'daily_limit_reached':
                http_response_code(429);
                return ['error' => 'Достигнут дневной лимит поручительств'];
            case 'total_limit_reached':
                http_response_code(429);
                return ['error' => 'Достигнут общий лимит поручительств'];
            case 'already_endorsed':
                http_response_code(409);
                return ['error' => 'Вы уже поручились за этого пользователя'];
            default:
                http_response_code(500);
                return ['error' => 'Неизвестная ошибка'];
        }
    } catch (\InvalidArgumentException $e) {
        http_response_code(404);
        return ['error' => $e->getMessage()];
    } catch (\Exception $e) {
        error_log("DFSN Endorse exception: " . $e->getMessage());
        http_response_code(500);
        return ['error' => 'Внутренняя ошибка'];
    }
});

$router->api('GET', '/api/check-endorsement', function() {
    require_auth();
    $from = (int)($_GET['from'] ?? 0);
    $to = (int)($_GET['to'] ?? 0);
    $count = scalar("SELECT COUNT(*) FROM dfsn_endorsements WHERE from_user_id = ? AND to_user_id = ?", [$from, $to]);
    return ['exists' => $count > 0];
});

// ---------- ПОИСК КОНТЕНТА (ПОСТЫ / ХЕШТЕГИ) ----------
$router->api('GET', '/api/search/content', function() {
    require_auth();
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 1) return ['posts' => []];

    $words = preg_split('/[\s\-]+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($words)) return ['posts' => []];

    $conditions = [];
    $params = [];

    foreach ($words as $word) {
        if (str_starts_with($word, '#')) {
            $hashtag = '%' . substr($word, 1) . '%';
            $conditions[] = "(p.content LIKE ?)";
            $params[] = $hashtag;
        } else {
            $like = "%{$word}%";
            $conditions[] = "(p.content LIKE ?)";
            $params[] = $like;
        }
    }

    $where = implode(' AND ', $conditions);

    $sql = "SELECT p.id, p.content, p.image, p.user_id, p.created_at, p.likes_count, p.dislikes_count,
                   u.first_name, u.last_name, u.avatar,
                   (SELECT GROUP_CONCAT(CONCAT(pm.file_url, '|', pm.media_type) SEPARATOR ',')
                    FROM post_media pm WHERE pm.post_id = p.id) AS media_list
            FROM posts p
            JOIN users u ON u.id = p.user_id
            WHERE {$where}
            ORDER BY p.created_at DESC
            LIMIT 30";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $posts = [];
    foreach ($rows as $row) {
        $media = [];
        // сначала собираем из media_list
        if (!empty($row['media_list'])) {
            foreach (explode(',', $row['media_list']) as $item) {
                $parts = explode('|', $item);
                if (count($parts) >= 2) {
                    $media[] = ['url' => $parts[0], 'type' => $parts[1]];
                }
            }
        }
        // если нет media_list, но есть старое поле image
        if (empty($media) && !empty($row['image'])) {
            $type = preg_match('/\.(mp4|webm|mov)$/i', $row['image']) ? 'video' : 'image';
            $media[] = ['url' => $row['image'], 'type' => $type];
        }
        unset($row['image'], $row['media_list']);
        $posts[] = [
            'id' => $row['id'],
            'content' => $row['content'],
            'user_id' => $row['user_id'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'avatar' => $row['avatar'],
            'likes_count' => $row['likes_count'],
            'dislikes_count' => $row['dislikes_count'],
            'created_at' => $row['created_at'],
            'media' => $media
        ];
    }

    return ['posts' => $posts];
});

// ---------- УВЕДОМЛЕНИЯ ----------
$router->api('GET', '/api/notifications', function () {
    require_auth();
    $userId = $_SESSION['user_id'];

    // Удаляем уведомления старше 24 часов
    db()->prepare("DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)")
        ->execute();

    $unread = isset($_GET['unread']) ? (bool)$_GET['unread'] : false;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $where = "WHERE n.user_id = ?";
    $params = [$userId];
    if ($unread) {
        $where .= " AND n.is_read = 0";
    }

    $total = scalar("SELECT COUNT(*) FROM notifications n $where", $params);
    $lastPage = max(1, (int)ceil($total / $perPage));

    $notifications = select(
        "SELECT n.*, 
                CONCAT(u.first_name, ' ', u.last_name) AS actor_name,
                u.avatar AS actor_avatar
         FROM notifications n
         LEFT JOIN users u ON u.id = n.actor_id
         $where
         ORDER BY n.is_read ASC, n.created_at DESC
         LIMIT $perPage OFFSET $offset",
        $params
    );

    return [
        'notifications' => $notifications,
        'page' => $page,
        'lastPage' => $lastPage,
        'total' => $total,
        'unread_count' => (int)scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [$userId])
    ];
});

$router->api('POST', '/api/notifications/read', function () {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $ids = $data['ids'] ?? null;
    $all = $data['all'] ?? false;
    $userId = $_SESSION['user_id'];

    if ($all) {
        db()->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$userId]);
    } elseif (is_array($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN ($placeholders)")
            ->execute(array_merge([$userId], $ids));
    }
    return ['success' => true];
});

// ==================== АДМИН-ПАНЕЛЬ (единый блок) ====================

// ---------- ПОСТЫ (админ) ----------
$router->api('GET', '/api/admin/posts', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }

    $search  = trim($_GET['search'] ?? '');
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;

    $where  = '';
    $params = [];
    if ($search !== '') {
        $where = "AND (p.content LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR p.id = ?)";
        $params = ["%$search%", "%$search%", (int)$search];
    }

    $total    = scalar("SELECT COUNT(*) FROM posts p JOIN users u ON u.id = p.user_id WHERE 1=1 $where", $params);
    $lastPage = max(1, (int)ceil($total / $perPage));
    $page     = min($page, $lastPage);
    $offset   = ($page - 1) * $perPage;

    $posts = select(
        "SELECT p.id, p.content, p.status, p.created_at, p.user_id,
                u.first_name, u.last_name
         FROM posts p
         JOIN users u ON u.id = p.user_id
         WHERE 1=1 $where
         ORDER BY p.created_at DESC
         LIMIT $perPage OFFSET $offset",
        $params
    );

    return [
        'posts'    => $posts,
        'page'     => $page,
        'lastPage' => $lastPage,
        'total'    => $total
    ];
});

$router->api('POST', '/api/admin/posts/hide', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }
    $data   = json_decode(file_get_contents('php://input'), true);
    $postId = (int)($data['post_id'] ?? 0);
    if ($postId <= 0) {
        http_response_code(422);
        return ['error' => 'Неверный ID поста'];
    }
    db()->prepare("UPDATE posts SET status = 'hidden' WHERE id = ?")->execute([$postId]);
    log_admin_action('hide_post', null, "post $postId");
    return ['success' => true];
});

$router->api('POST', '/api/admin/posts/unhide', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }
    $data   = json_decode(file_get_contents('php://input'), true);
    $postId = (int)($data['post_id'] ?? 0);
    if ($postId <= 0) {
        http_response_code(422);
        return ['error' => 'Неверный ID поста'];
    }
    db()->prepare("UPDATE posts SET status = 'visible' WHERE id = ?")->execute([$postId]);
    log_admin_action('unhide_post', null, "post $postId");
    return ['success' => true];
});

$router->api('POST', '/api/admin/posts/delete', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }
    $data   = json_decode(file_get_contents('php://input'), true);
    $postId = (int)($data['post_id'] ?? 0);
    if ($postId <= 0) {
        http_response_code(422);
        return ['error' => 'Неверный ID поста'];
    }

    // Удаление связанных файлов
    $post = find('posts', $postId);
    if ($post && !empty($post['image']) && file_exists(__DIR__ . '/' . $post['image'])) {
        unlink(__DIR__ . '/' . $post['image']);
    }
    $mediaStmt = db()->prepare("SELECT file_url FROM post_media WHERE post_id = ?");
    $mediaStmt->execute([$postId]);
    while ($media = $mediaStmt->fetch()) {
        if (!empty($media['file_url']) && file_exists(__DIR__ . '/' . $media['file_url'])) {
            unlink(__DIR__ . '/' . $media['file_url']);
        }
    }

    db()->prepare("DELETE FROM post_media WHERE post_id = ?")->execute([$postId]);
    db()->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);

    log_admin_action('delete_post', null, "post $postId");
    return ['success' => true];
});

// ---------- ГРАФИКИ (дашборд) ----------
$router->api('GET', '/api/admin/registrations-daily', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }
    $days = [];
    for ($i = 29; $i >= 0; $i--) {
        $date  = date('Y-m-d', strtotime("-$i days"));
        $count = scalar("SELECT COUNT(*) FROM users WHERE DATE(created_at) = ?", [$date]);
        $days[] = ['label' => date('d.m', strtotime($date)), 'value' => (int)$count];
    }
    return [
        'labels' => array_column($days, 'label'),
        'values' => array_column($days, 'value')
    ];
});

$router->api('GET', '/api/admin/posts-daily', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }
    $days = [];
    for ($i = 29; $i >= 0; $i--) {
        $date  = date('Y-m-d', strtotime("-$i days"));
        $count = scalar("SELECT COUNT(*) FROM posts WHERE DATE(created_at) = ?", [$date]);
        $days[] = ['label' => date('d.m', strtotime($date)), 'value' => (int)$count];
    }
    return [
        'labels' => array_column($days, 'label'),
        'values' => array_column($days, 'value')
    ];
});

// ---------- ПОЛЬЗОВАТЕЛИ (админ) ----------
$router->api('GET', '/api/admin/users', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }
    $search  = trim($_GET['search'] ?? '');
    $status  = $_GET['status'] ?? 'all';
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;

    $where  = "WHERE 1=1";
    $params = [];
    if ($search !== '') {
        $where .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.id = ?)";
        $params = array_merge($params, ["%$search%", "%$search%", (int)$search]);
    }
    if ($status === 'active') {
        $where .= " AND (u.status IS NULL OR u.status != 'blocked')";
    } elseif ($status === 'blocked') {
        $where .= " AND u.status = 'blocked'";
    }

    $total    = scalar("SELECT COUNT(*) FROM users u $where", $params);
    $lastPage = max(1, (int)ceil($total / $perPage));
    $page     = min($page, $lastPage);
    $offset   = ($page - 1) * $perPage;

    $users = select(
        "SELECT u.id, u.first_name, u.last_name, u.email, u.created_at, u.last_active,
                u.role, u.status,
                COALESCE(w.w_trust, 1.0) AS w_trust,
                COALESCE(w.w_activity, 1.0) AS w_activity,
                COALESCE(w.w_expert, 1.0) AS w_expert
         FROM users u
         LEFT JOIN dfsn_weights w ON u.id = w.user_id
         $where
         ORDER BY u.id DESC
         LIMIT $perPage OFFSET $offset",
        $params
    );

    return [
        'users'    => $users,
        'page'     => $page,
        'lastPage' => $lastPage,
        'total'    => $total
    ];
});

$router->api('POST', '/api/admin/users/block', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }
    $data   = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($data['user_id'] ?? 0);
    if ($userId <= 0 || $userId == $_SESSION['user_id']) {
        http_response_code(422);
        return ['error' => 'Недопустимый пользователь'];
    }
    db()->prepare("UPDATE users SET status = 'blocked' WHERE id = ?")->execute([$userId]);
    log_admin_action('block_user', $userId);
    return ['success' => true];
});

$router->api('POST', '/api/admin/users/unblock', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }
    $data   = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($data['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(422);
        return ['error' => 'Недопустимый пользователь'];
    }
    db()->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$userId]);
    log_admin_action('unblock_user', $userId);
    return ['success' => true];
});

$router->api('POST', '/api/admin/users/update-weight', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }
    $data      = json_decode(file_get_contents('php://input'), true);
    $userId    = (int)($data['user_id'] ?? 0);
    $wTrust    = (float)($data['w_trust'] ?? 1.0);
    $wActivity = (float)($data['w_activity'] ?? 1.0);
    $wExpert   = (float)($data['w_expert'] ?? 1.0);
    if ($userId <= 0) {
        http_response_code(422);
        return ['error' => 'Недопустимый пользователь'];
    }
    db()->prepare("UPDATE dfsn_weights SET w_trust = ?, w_activity = ?, w_expert = ?, updated_at = ? WHERE user_id = ?")
        ->execute([$wTrust, $wActivity, $wExpert, time(), $userId]);
    log_admin_action('update_weight', $userId, "trust=$wTrust act=$wActivity exp=$wExpert");
    return ['success' => true];
});

// ---------- ЖАЛОБЫ (админ) ----------
$router->api('GET', '/api/admin/reports', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }

    try {
        $status  = $_GET['status'] ?? 'all';
        $type    = $_GET['type'] ?? '';
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;

        $where  = [];
        $params = [];
        if ($status === 'open') {
            $where[] = "r.status = 'open'";
        } elseif ($status === 'resolved') {
            $where[] = "r.status IN ('resolved','dismissed')";
        }
        if (in_array($type, ['user','post','message'])) {
            $where[] = "r.type = ?";
            $params[] = $type;
        }
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total       = scalar("SELECT COUNT(*) FROM reports r $whereClause", $params);
        $lastPage    = max(1, (int)ceil($total / $perPage));
        $page        = min($page, $lastPage);
        $offset      = ($page - 1) * $perPage;

        $reports = select(
            "SELECT r.*, 
                    CASE 
                        WHEN r.type = 'user' THEN (SELECT CONCAT(u.first_name,' ',u.last_name) FROM users u WHERE u.id = r.target_id)
                        WHEN r.type = 'post' THEN (SELECT LEFT(p.content, 100) FROM posts p WHERE p.id = r.target_id)
                        WHEN r.type = 'message' THEN (SELECT LEFT(m.content, 100) FROM messages m WHERE m.id = r.target_id)
                    END AS target_summary
             FROM reports r
             $whereClause
             ORDER BY r.created_at DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        return [
            'reports'  => $reports,
            'page'     => $page,
            'lastPage' => $lastPage,
            'total'    => $total
        ];
    } catch (\Throwable $e) {
        http_response_code(500);
        return ['error' => $e->getMessage(), 'reports' => []];
    }
});

$router->api('POST', '/api/admin/reports/resolve', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }
    $data       = json_decode(file_get_contents('php://input'), true);
    $reportId   = (int)($data['report_id'] ?? 0);
    $resolution = $data['resolution'] === 'resolved' ? 'resolved' : 'dismissed';
    if ($reportId <= 0) {
        http_response_code(422);
        return ['error' => 'Неверный ID жалобы'];
    }
    try {
        db()->prepare("UPDATE reports SET status = ?, resolved_at = NOW(), resolved_by = ? WHERE id = ?")
            ->execute([$resolution, $_SESSION['user_id'], $reportId]);
        try {
            db()->prepare("INSERT INTO admin_log (admin_id, action, target_user_id, details, created_at) VALUES (?, ?, ?, ?, NOW())")
                ->execute([$_SESSION['user_id'], 'resolve_report', null, "report $reportId -> $resolution"]);
        } catch (\Throwable $e) {
            error_log("admin_log write failed: " . $e->getMessage());
        }
        return ['success' => true];
    } catch (\Throwable $e) {
        http_response_code(500);
        return ['error' => $e->getMessage()];
    }
});

// ---------- СООБЩЕНИЯ (админ) ----------
$router->api('GET', '/api/admin/messages', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }

    $search  = trim($_GET['search'] ?? '');
    $type    = $_GET['type'] ?? 'all'; // all, private, group
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;

    $messages = [];

    // Приватные сообщения
    if ($type === 'all' || $type === 'private') {
        $wherePrivate = '';
        $params = [];
        if ($search !== '') {
            $like = "%$search%";
            $wherePrivate = "AND (m.content LIKE ? OR CONCAT(u1.first_name, ' ', u1.last_name) LIKE ? OR CONCAT(u2.first_name, ' ', u2.last_name) LIKE ?)";
            $params = [$like, $like, $like];
        }
        $privateSql = "
            SELECT m.id, 'private' AS type, m.content, m.created_at,
                   u1.first_name AS sender_first, u1.last_name AS sender_last,
                   u2.first_name AS receiver_first, u2.last_name AS receiver_last
            FROM messages m
            JOIN users u1 ON u1.id = m.sender_id
            JOIN chats c ON c.id = m.chat_id
            JOIN users u2 ON u2.id = CASE WHEN c.user1_id = m.sender_id THEN c.user2_id ELSE c.user1_id END
            WHERE 1=1 $wherePrivate
            ORDER BY m.created_at DESC
        ";
        $privateMessages = select($privateSql, $params);
        foreach ($privateMessages as $row) {
            $messages[] = [
                'id'            => $row['id'],
                'type'          => 'private',
                'content'       => $row['content'],
                'created_at'    => $row['created_at'],
                'sender_name'   => $row['sender_first'] . ' ' . $row['sender_last'],
                'receiver_name' => $row['receiver_first'] . ' ' . $row['receiver_last'],
            ];
        }
    }

    // Групповые сообщения
    if ($type === 'all' || $type === 'group') {
        $whereGroup = '';
        $params = [];
        if ($search !== '') {
            $like = "%$search%";
            $whereGroup = "AND (gm.content LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR cg.name LIKE ?)";
            $params = [$like, $like, $like];
        }
        $groupSql = "
            SELECT gm.id, 'group' AS type, gm.content, gm.created_at,
                   u.first_name AS sender_first, u.last_name AS sender_last,
                   cg.name AS group_name
            FROM group_messages gm
            JOIN users u ON u.id = gm.sender_id
            JOIN chat_groups cg ON cg.id = gm.group_id
            WHERE 1=1 $whereGroup
            ORDER BY gm.created_at DESC
        ";
        $groupMessages = select($groupSql, $params);
        foreach ($groupMessages as $row) {
            $messages[] = [
                'id'         => $row['id'],
                'type'       => 'group',
                'content'    => $row['content'],
                'created_at' => $row['created_at'],
                'sender_name'=> $row['sender_first'] . ' ' . $row['sender_last'],
                'group_name' => $row['group_name'],
            ];
        }
    }

    // Общая сортировка
    usort($messages, function($a, $b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });
    $total    = count($messages);
    $lastPage = max(1, (int)ceil($total / $perPage));
    $page     = min($page, $lastPage);
    $offset   = ($page - 1) * $perPage;
    $messages = array_slice($messages, $offset, $perPage);

    return [
        'messages' => $messages,
        'page'     => $page,
        'lastPage' => $lastPage,
        'total'    => $total
    ];
});

// ---------- ЛОГИ АДМИНИСТРАТОРА ----------
$router->api('GET', '/api/admin/logs', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }

    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 30;
    $offset  = ($page - 1) * $perPage;

    $total    = scalar("SELECT COUNT(*) FROM admin_log");
    $lastPage = max(1, (int)ceil($total / $perPage));

    $logs = select(
        "SELECT al.*, CONCAT(u.first_name, ' ', u.last_name) AS admin_name
         FROM admin_log al
         JOIN users u ON u.id = al.admin_id
         ORDER BY al.created_at DESC
         LIMIT $perPage OFFSET $offset"
    );

    return [
        'logs'     => $logs,
        'page'     => $page,
        'lastPage' => $lastPage,
        'total'    => $total
    ];
});

// ---------- СИСТЕМНАЯ ИНФОРМАЦИЯ ----------
$router->api('GET', '/api/admin/system-stats', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }

    return [
        'php_version'         => phpversion(),
        'server_software'     => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
        'db_version'          => scalar("SELECT VERSION()"),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'total_sessions'      => scalar("SELECT COUNT(*) FROM user_sessions WHERE login_time > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 HOUR))"),
        'cache_hits'          => scalar("SELECT COUNT(*) FROM dfsn_recommendations_cache"),
        'error_count_24h'     => scalar("SELECT COUNT(*) FROM dfsn_log WHERE event_type = 'error' AND created_at > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 DAY))"),
    ];
});

// ---------- СТАТИСТИКА DFSN ----------
$router->api('GET', '/api/admin/dfsn-stats', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }

    $totalEndorsements = scalar("SELECT COUNT(*) FROM dfsn_endorsements");
    $avgTrust          = round((float)scalar("SELECT AVG(w_trust) FROM dfsn_weights"), 2);
    $anomalyCount24h   = scalar("SELECT COUNT(*) FROM dfsn_log WHERE event_type = 'behavioral_anomaly' AND created_at > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 DAY))");
    $datasetSize       = scalar("SELECT COUNT(*) FROM dfsn_dataset");
    $lastDump          = scalar("SELECT MAX(created_at) FROM dfsn_model_dumps");
    $modelLastDump     = $lastDump ? date('Y-m-d H:i:s', (int)$lastDump) : 'никогда';

    return [
        'total_endorsements' => $totalEndorsements,
        'avg_trust'          => $avgTrust,
        'anomaly_count_24h'  => $anomalyCount24h,
        'dataset_size'       => $datasetSize,
        'model_last_dump'    => $modelLastDump,
    ];
});

// ---------- РАСПРЕДЕЛЕНИЕ ВЕСОВ ДОВЕРИЯ (ГИСТОГРАММА) ----------
$router->api('GET', '/api/admin/dfsn-trust-distribution', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }

    $buckets = [
        '0.0-0.5' => "SELECT COUNT(*) FROM dfsn_weights WHERE w_trust >= 0 AND w_trust < 0.5",
        '0.5-1.0' => "SELECT COUNT(*) FROM dfsn_weights WHERE w_trust >= 0.5 AND w_trust < 1.0",
        '1.0-1.5' => "SELECT COUNT(*) FROM dfsn_weights WHERE w_trust >= 1.0 AND w_trust < 1.5",
        '1.5-2.0' => "SELECT COUNT(*) FROM dfsn_weights WHERE w_trust >= 1.5 AND w_trust < 2.0",
        '2.0+'    => "SELECT COUNT(*) FROM dfsn_weights WHERE w_trust >= 2.0",
    ];

    $labels = [];
    $values = [];
    foreach ($buckets as $label => $sql) {
        $labels[] = $label;
        $values[] = (int)scalar($sql);
    }

    return [
        'labels' => $labels,
        'values' => $values,
    ];
});

// ---------- ДЕТАЛЬНАЯ ИНФОРМАЦИЯ О ПОЛЬЗОВАТЕЛЕ ----------
$router->api('GET', '/api/admin/users/{id}', function ($id) {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }

    $userId = (int)$id;
    $user = find('users', $userId);
    if (!$user) {
        http_response_code(404);
        return ['error' => 'Пользователь не найден'];
    }

    $weights = find('dfsn_weights', $userId) ?? [
        'w_trust' => 1.0, 'w_activity' => 1.0, 'w_expert' => 1.0
    ];

    $postsCount           = scalar("SELECT COUNT(*) FROM posts WHERE user_id = ?", [$userId]);
    $friendsCount         = scalar("SELECT COUNT(*) FROM friendships WHERE (requester_id = ? OR addressee_id = ?) AND status = 'accepted'", [$userId, $userId]);
    $endorsementsGiven    = scalar("SELECT COUNT(*) FROM dfsn_endorsements WHERE from_user_id = ?", [$userId]);
    $endorsementsReceived = scalar("SELECT COUNT(*) FROM dfsn_endorsements WHERE to_user_id = ?", [$userId]);

    return [
        'id'                 => $user['id'],
        'first_name'         => $user['first_name'],
        'last_name'          => $user['last_name'],
        'email'              => $user['email'] ?? '',
        'status'             => $user['status'] ?? 'active',
        'created_at'         => $user['created_at'],
        'last_active'        => $user['last_active'],
        'w_trust'            => $weights['w_trust'],
        'w_activity'         => $weights['w_activity'],
        'w_expert'           => $weights['w_expert'],
        'posts_count'        => $postsCount,
        'friends_count'      => $friendsCount,
        'endorsements_count' => $endorsementsGiven + $endorsementsReceived,
    ];
});

// ---------- ДАШБОРД (сводка) ----------
$router->api('GET', '/api/admin/dashboard-stats', function () {
    require_auth();
    if (!is_admin()) {
        http_response_code(403);
        return ['error' => 'Forbidden'];
    }
    $db = db();
    return [
        'total_users'        => (int) scalar("SELECT COUNT(*) FROM users"),
        'new_users_today'    => (int) scalar("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()"),
        'active_users_24h'   => (int) scalar("SELECT COUNT(*) FROM users WHERE last_active >= DATE_SUB(NOW(), INTERVAL 1 DAY)"),
        'total_posts'        => (int) scalar("SELECT COUNT(*) FROM posts"),
        'posts_today'        => (int) scalar("SELECT COUNT(*) FROM posts WHERE DATE(created_at) = CURDATE()"),
        'total_comments'     => (int) scalar("SELECT COUNT(*) FROM comments"),
        'messages_today'     => (int) scalar("SELECT (SELECT COUNT(*) FROM messages WHERE DATE(created_at) = CURDATE()) + (SELECT COUNT(*) FROM group_messages WHERE DATE(created_at) = CURDATE())"),
        'total_messages'     => (int) scalar("SELECT (SELECT COUNT(*) FROM messages) + (SELECT COUNT(*) FROM group_messages)"),
        'total_endorsements' => (int) scalar("SELECT COUNT(*) FROM dfsn_endorsements"),
        'open_reports'       => (int) scalar("SELECT COUNT(*) FROM reports WHERE status = 'open'"),
        'db_size_mb'         => round((float) scalar("SELECT SUM(data_length + index_length) / 1024 / 1024 FROM information_schema.tables WHERE table_schema = DATABASE()"), 2),
    ];
});

// ---------- ОТПРАВКА ЖАЛОБЫ (пользовательская) ----------
$router->api('POST', '/api/report', function () {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $reporterId = $_SESSION['user_id'];
    $targetId = (int)($data['target_id'] ?? 0);
    $type = $data['type'] ?? '';
    $reason = trim($data['reason'] ?? '');

    if ($targetId <= 0 || !in_array($type, ['user','post','message']) || $reason === '') {
        http_response_code(422);
        return ['error' => 'Неверные данные'];
    }

    try {
        db()->prepare("INSERT INTO reports (reporter_id, type, target_id, reason, status, created_at) VALUES (?, ?, ?, ?, 'open', NOW())")
            ->execute([$reporterId, $type, $targetId, $reason]);
    } catch (PDOException $e) {
        http_response_code(500);
        return ['error' => 'Ошибка базы данных: ' . $e->getMessage()];
    }

    return ['success' => true];
});

// ---------- СТАТУС ПОЛЬЗОВАТЕЛЯ (ИСПОЛЬЗУЕТ last_active) ----------
$router->api('GET', '/api/users/{id}/status', function ($id) {
    require_auth();
    $user = find('users', (int)$id);
    if (!$user) {
        http_response_code(404);
        return ['error' => 'Пользователь не найден'];
    }

    $lastActive = $user['last_active'] ?? null;
    if (!$lastActive) {
        return ['text' => '○ был(а) давно', 'class' => 'profileStatus--offline'];
    }

    $diff = time() - strtotime($lastActive);
    if ($diff < 300) {
        return ['text' => '● в сети', 'class' => 'profileStatus--online'];
    } elseif ($diff < 1800) {
        return ['text' => '○ был(а) недавно', 'class' => 'profileStatus--recent'];
    } elseif ($diff < 86400) {
        return ['text' => '○ был(а) сегодня', 'class' => 'profileStatus--offline'];
    }
    return ['text' => '○ был(а) давно', 'class' => 'profileStatus--offline'];
});

// Заглушка для пинга (просто чтобы не было ошибок)
$router->api('POST', '/api/ping', function () {
    require_auth();
    return ['success' => true];
});

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
exit;