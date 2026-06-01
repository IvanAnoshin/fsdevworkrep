<?php
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});
require_once __DIR__ . '/kopilot/kopilot_init.php';

// Вспомогательная функция проверки доступа к посту
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
    return ['success' => true];
});

$router->api('POST', '/api/friends/accept', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $requester = (int)($data['requester_id'] ?? 0);
    db()->prepare("UPDATE friendships SET status = 'accepted' WHERE requester_id = ? AND addressee_id = ?")->execute([$requester, $_SESSION['user_id']]);
    return ['success' => true];
});

$router->api('POST', '/api/friends/decline', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $requester = (int)($data['requester_id'] ?? 0);
    db()->prepare("DELETE FROM friendships WHERE requester_id = ? AND addressee_id = ?")->execute([$requester, $_SESSION['user_id']]);
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
    $stmt = db()->prepare("SELECT id, sender_id, content, file_url, created_at FROM messages WHERE chat_id = ? AND id > ? ORDER BY id ASC");
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
    $stmt = db()->prepare("SELECT id, sender_id, content, file_url, is_read, created_at FROM messages WHERE chat_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
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
                CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END AS other_user_id,
                u.first_name, u.last_name, u.avatar,
                c.is_pinned,
                (SELECT content FROM messages WHERE chat_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_message,
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
        SELECT gm.id, gm.sender_id, gm.content, gm.file_url, gm.created_at, u.first_name, u.last_name, u.avatar
        FROM group_messages gm
        JOIN users u ON u.id = gm.sender_id
        WHERE gm.group_id = ? AND gm.id > ?
        ORDER BY gm.id ASC
    ");
    $stmt->execute([$groupId, $lastId]);
    $messages = $stmt->fetchAll();
    return ['messages' => $messages];
});

$router->api('POST', '/api/messages/send', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $senderId = $_SESSION['user_id'];
    $receiverId = (int)($data['receiver_id'] ?? 0);
    $content = trim($data['content'] ?? '');
    if (!$receiverId || !$content) {
        http_response_code(422);
        return ['error' => 'Получатель и текст обязательны'];
    }
    if (mb_strlen($content) > 5000) {
        http_response_code(422);
        return ['error' => 'Сообщение слишком длинное (макс. 5000 символов)'];
    }
    $receiver = find('users', $receiverId);
    if (!$receiver) {
        http_response_code(422);
        return ['error' => 'Пользователь не найден'];
    }
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
    $user1 = min($senderId, $receiverId);
    $user2 = max($senderId, $receiverId);
    $stmt = db()->prepare("SELECT id FROM chats WHERE user1_id = ? AND user2_id = ?");
    $stmt->execute([$user1, $user2]);
    $chat = $stmt->fetch();
    if (!$chat) {
        db()->prepare("INSERT INTO chats (user1_id, user2_id, last_message_at) VALUES (?, ?, NOW())")->execute([$user1, $user2]);
        $chatId = db()->lastInsertId();
    } else {
        $chatId = $chat['id'];
        db()->prepare("UPDATE chats SET last_message_at = NOW() WHERE id = ?")->execute([$chatId]);
    }
    insert('messages', ['chat_id' => $chatId, 'sender_id' => $senderId, 'content' => $content, 'is_read' => 0]);
    $messageId = db()->lastInsertId();
    return [
        'success' => true,
        'message_id' => $messageId,
        'chat_id' => $chatId,
        'other_user_id' => $receiverId
    ];
});

$router->api('DELETE', '/api/messages/{messageId}', function($messageId) {
    require_auth();
    $msg = find('messages', (int)$messageId);
    if (!$msg || $msg['sender_id'] != $_SESSION['user_id']) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    if (time() - strtotime($msg['created_at']) > 86400) {
        http_response_code(403);
        return ['error' => 'Время удаления истекло'];
    }
    // Удаляем файл, если есть
    if (!empty($msg['file_url'])) {
        $filePath = __DIR__ . $msg['file_url'];
        if (file_exists($filePath)) unlink($filePath);
    }
    db()->prepare("DELETE FROM messages WHERE id = ?")->execute([(int)$messageId]);
    $chatId = $msg['chat_id'];
    db()->prepare("UPDATE chats SET last_message_at = COALESCE((SELECT created_at FROM messages WHERE chat_id = ? ORDER BY created_at DESC LIMIT 1), NOW()) WHERE id = ?")
        ->execute([$chatId, $chatId]);
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

$router->api('POST', '/api/groups/send-file', function() {
    require_auth();
    $userId = $_SESSION['user_id'];
    $groupId = (int)($_POST['group_id'] ?? 0);
    if (!$groupId) throw new ValidationException(['group_id' => 'Не указана группа']);
    $member = scalar("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?", [$groupId, $userId]);
    if (!$member) { http_response_code(403); return ['error' => 'Доступ запрещён']; }
    if (empty($_FILES['file'])) throw new ValidationException(['file' => 'Файл не загружен']);
    $file = $_FILES['file'];
    // Проверка реального MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','application/pdf'];
    if (!in_array($realMime, $allowed)) {
        throw new ValidationException(['file' => 'Недопустимый тип файла (определён по содержимому)']);
    }
    if ($file['size'] > 10 * 1024 * 1024) throw new ValidationException(['file' => 'Файл слишком большой']);
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
    $msgId = insert('group_messages', ['group_id' => $groupId, 'sender_id' => $userId, 'content' => $content, 'file_url' => $fileUrl]);
    db()->prepare("UPDATE chat_groups SET last_message_at = NOW() WHERE id = ?")->execute([$groupId]);
    return ['success' => true, 'message_id' => $msgId, 'file_url' => $fileUrl, 'file_name' => $file['name']];
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
        SELECT gm.id, gm.sender_id, gm.content, gm.file_url, gm.created_at, u.first_name, u.last_name, u.avatar
        FROM group_messages gm
        JOIN users u ON u.id = gm.sender_id
        WHERE gm.group_id = ?
        ORDER BY gm.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$groupId, $perPage, $offset]);
    $messages = $stmt->fetchAll();
    $messages = array_reverse($messages);
    db()->prepare("INSERT INTO group_reads (group_id, user_id, last_read) VALUES (?, ?, NOW()) 
                   ON DUPLICATE KEY UPDATE last_read = NOW()")->execute([$groupId, $userId]);
    return ['messages' => $messages];
});

$router->api('POST', '/api/groups/{groupId}/messages', function($groupId) {
    require_auth();
    $userId = $_SESSION['user_id'];
    $groupId = (int)$groupId;
    $member = scalar("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?", [$groupId, $userId]);
    if (!$member) {
        http_response_code(403);
        return ['error' => 'Вы не участник группы'];
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $content = trim($data['content'] ?? '');
    if ($content === '') {
        http_response_code(422);
        return ['error' => 'Сообщение не может быть пустым'];
    }
    if (mb_strlen($content) > 5000) {
        http_response_code(422);
        return ['error' => 'Сообщение слишком длинное (макс. 5000 символов)'];
    }
    $msgId = insert('group_messages', ['group_id' => $groupId, 'sender_id' => $userId, 'content' => $content]);
    db()->prepare("UPDATE chat_groups SET last_message_at = NOW() WHERE id = ?")->execute([$groupId]);
    return ['success' => true, 'message_id' => $msgId];
});

// ---------- ПОИСК ----------
$router->api('GET', '/api/search/users', function() {
    require_auth();
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 1) return ['users' => []];
    $stmt = db()->prepare("SELECT id, first_name, last_name, avatar FROM users WHERE first_name LIKE ? OR last_name LIKE ? ORDER BY last_name ASC LIMIT 10");
    $like = "%{$q}%";
    $stmt->execute([$like, $like]);
    return ['users' => $stmt->fetchAll()];
});

// ---------- ПОСТЫ ----------
$router->api('POST', '/api/posts/create', function() {
    require_auth();
    $content = trim($_POST['content'] ?? '');
    $imagePath = null;
    if (!empty($_FILES['file']['tmp_name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg','image/png','image/gif','video/mp4','video/webm','video/quicktime'];
        if (in_array($_FILES['file']['type'], $allowed)) {
            $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
            $filename = 'post_'.$_SESSION['user_id'].'_'.time().'.'.$ext;
            $uploadDir = __DIR__.'/uploads/posts/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $dest = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) $imagePath = 'uploads/posts/'.$filename;
        }
    }
    if ($content === '' && !$imagePath) { http_response_code(422); return ['error' => 'Пост не может быть пустым']; }
    $postId = insert('posts', ['user_id' => $_SESSION['user_id'], 'content' => $content, 'image' => $imagePath, 'likes_count' => 0, 'dislikes_count' => 0]);
    db()->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['user_id']]);
    return ['success' => true, 'post' => find('posts', $postId)];
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
        if (in_array($_FILES['file']['type'], $allowed)) {
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

// ---------- МЕДИАХАБ (ЛИЧНЫЕ ЧАТЫ) ----------
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

// ---------- МЕДИАХАБ (ГРУППОВЫЕ ЧАТЫ) ----------
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

// ---------- ПОЛУЧЕНИЕ УЧАСТНИКОВ ГРУППЫ ----------
$router->api('GET', '/api/groups/{groupId}/members', function($groupId) {
    require_auth();
    $currentUserId = $_SESSION['user_id'];
    $groupId = (int)$groupId;
    
    // Проверка, что текущий пользователь – участник группы
    $member = scalar("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?", [$groupId, $currentUserId]);
    if (!$member) {
        http_response_code(403);
        return ['error' => 'Доступ запрещён'];
    }
    
    $group = find('chat_groups', $groupId);
    $creatorId = $group['created_by'] ?? null;
    
    // Явно выбираем id из таблицы users, а не из group_members
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
    
    // Добавляем роль
    foreach ($members as &$member) {
        $member['role'] = ($member['id'] == $creatorId) ? 'admin' : 'member';
    }
    
    return ['members' => $members];
});

// ---------- ЗАКРЕПЛЕНИЕ ЛИЧНОГО ЧАТА ----------
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

// ---------- ЗАКРЕПЛЕНИЕ ГРУППОВОГО ЧАТА ----------
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

$router->api('POST', '/api/messages/send-file', function() {
    require_auth();
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
    // Проверка реального MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','application/pdf'];
    if (!in_array($realMime, $allowed)) {
        throw new ValidationException(['file' => 'Недопустимый тип файла (определён по содержимому)']);
    }
    if ($file['size'] > 10 * 1024 * 1024) throw new ValidationException(['file' => 'Файл слишком большой']);
    
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
        db()->prepare("INSERT INTO chats (user1_id, user2_id, last_message_at) VALUES (?, ?, NOW())")->execute([$user1, $user2]);
        $chatId = db()->lastInsertId();
    } else {
        $chatId = $chat['id'];
        db()->prepare("UPDATE chats SET last_message_at = NOW() WHERE id = ?")->execute([$chatId]);
    }
    
    $msgId = insert('messages', [
        'chat_id' => $chatId,
        'sender_id' => $senderId,
        'content' => $content,
        'file_url' => $fileUrl,
        'is_read' => 0
    ]);
    
    return [
        'success' => true,
        'message_id' => $msgId,
        'file_url' => $fileUrl,
        'file_name' => $file['name'],
        'chat_id' => $chatId,
        'other_user_id' => $receiverId
    ];
});

// ---------- ЛЕНТА НОВОСТЕЙ (для feed.php) ----------
$router->api('GET', '/api/feed/posts', function() {
    require_auth();
    $userId = $_SESSION['user_id'];
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 10;
    $offset = ($page - 1) * $limit;

    // Получаем список друзей (подтверждённых)
    $friends = select(
        "SELECT u.id FROM friendships f
         JOIN users u ON u.id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END
         WHERE (f.requester_id = ? OR f.addressee_id = ?) AND f.status = 'accepted'",
        [$userId, $userId, $userId]
    );
    $friendIds = array_column($friends, 'id');
    $friendIds[] = $userId; // включаем себя

    // Посты: свои + друзей (если у них открыто для друзей) + публичные посты всех
    $placeholders = implode(',', array_fill(0, count($friendIds), '?'));
    $stmt = db()->prepare("
        SELECT p.*, u.first_name, u.last_name, u.avatar
        FROM posts p
        JOIN users u ON u.id = p.user_id
        WHERE 
            (p.user_id = ?) OR
            (p.user_id IN ($placeholders) AND u.privacy_posts IN ('friends', 'public')) OR
            (u.privacy_posts = 'public')
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params = array_merge([$userId], $friendIds, [$limit, $offset]);
    $stmt->execute($params);
    $posts = $stmt->fetchAll();

    // Для каждого поста нужно проверить, лайкнул ли текущий пользователь
    foreach ($posts as &$post) {
        $stmt2 = db()->prepare("SELECT reaction FROM post_reactions WHERE post_id = ? AND user_id = ?");
        $stmt2->execute([$post['id'], $userId]);
        $react = $stmt2->fetch();
        $post['user_reaction'] = $react ? $react['reaction'] : null;
    }

    return ['posts' => $posts, 'has_more' => count($posts) === $limit];
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
        $photo['url'] = $photo['file_url'];  // добавляем ключ url
        unset($photo['file_url']);           // опционально удаляем старый
    }
    return ['photos' => $photos];
    // Логирование (в файл или в ответ)
    error_log(print_r($photos, true));
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

// Создаём таблицу, если её нет
db()->exec("CREATE TABLE IF NOT EXISTS user_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    file_url VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX(user_id)
)");

$router->api('GET', '/api/get-user-photos', function() {
    require_auth();
    $userId = (int)($_GET['user_id'] ?? 0);
    if (!$userId) {
        http_response_code(400);
        return ['error' => 'Не указан user_id'];
    }
    // Проверка, что пользователь существует (опционально)
    $user = find('users', $userId);
    if (!$user) {
        http_response_code(404);
        return ['error' => 'Пользователь не найден'];
    }
    $stmt = db()->prepare("SELECT id, file_url, created_at FROM user_photos WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($photos as &$photo) {
        $photo['url'] = $photo['file_url']; // для удобства клиента
    }
    return ['photos' => $photos];
});

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
exit;