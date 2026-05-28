<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/kopilot/kopilot_init.php';

$router->api('POST', '/api/user/update-name', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $firstName = trim($data['first_name'] ?? '');
    $lastName  = trim($data['last_name'] ?? '');
    if ($firstName === '' || $lastName === '') {
        throw new ValidationException([
            'first_name' => 'Имя обязательно',
            'last_name'  => 'Фамилия обязательна',
        ]);
    }
    update('users', $_SESSION['user_id'], [
        'first_name' => $firstName,
        'last_name'  => $lastName,
    ]);
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
    if (isset($data['show_online'])) {
        $updates['show_online'] = (bool)$data['show_online'] ? 1 : 0;
    }
    if (!empty($updates)) {
        update('users', $_SESSION['user_id'], $updates);
    }
    return ['success' => true];
});

$router->api('POST', '/api/user/update-notifications', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $updates = [];
    if (isset($data['notify_sound'])) {
        $updates['notify_sound'] = (bool)$data['notify_sound'] ? 1 : 0;
    }
    if (isset($data['notify_push'])) {
        $updates['notify_push'] = (bool)$data['notify_push'] ? 1 : 0;
    }
    if (!empty($updates)) {
        update('users', $_SESSION['user_id'], $updates);
    }
    return ['success' => true];
});

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

// ---------- МЕССЕНДЖЕР ----------

$router->api('GET', '/api/chats', function() {
    require_auth();
    $userId = $_SESSION['user_id'];

    $chats = select(
        "SELECT c.id AS chat_id,
                CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END AS other_user_id,
                u.first_name, u.last_name, u.avatar,
                (SELECT content FROM messages WHERE chat_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                (SELECT COUNT(*) FROM messages WHERE chat_id = c.id AND sender_id != ? AND is_read = 0) AS unread_count,
                c.last_message_at
         FROM chats c
         JOIN users u ON u.id = CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END
         WHERE c.user1_id = ? OR c.user2_id = ?
         ORDER BY c.last_message_at DESC",
        [$userId, $userId, $userId, $userId, $userId]
    );

    return ['chats' => $chats];
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

    db()->prepare("UPDATE messages SET is_read = 1 WHERE chat_id = ? AND sender_id != ?")
        ->execute([$chatId, $userId]);

    $messages = select(
        "SELECT id, sender_id, content, is_read, created_at
         FROM messages
         WHERE chat_id = ?
         ORDER BY created_at ASC",
        [$chatId]
    );

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

    $receiver = find('users', $receiverId);
    if (!$receiver) {
        http_response_code(422);
        return ['error' => 'Пользователь не найден'];
    }

    $user1 = min($senderId, $receiverId);
    $user2 = max($senderId, $receiverId);

    $chat = db()->prepare("SELECT id FROM chats WHERE user1_id = ? AND user2_id = ?")
                ->execute([$user1, $user2])
                ->fetch();

    if (!$chat) {
        db()->prepare("INSERT INTO chats (user1_id, user2_id, last_message_at) VALUES (?, ?, NOW())")
           ->execute([$user1, $user2]);
        $chatId = db()->lastInsertId();
    } else {
        $chatId = $chat['id'];
        db()->prepare("UPDATE chats SET last_message_at = NOW() WHERE id = ?")
           ->execute([$chatId]);
    }

    insert('messages', [
        'chat_id' => $chatId,
        'sender_id' => $senderId,
        'content' => $content,
        'is_read' => 0
    ]);

    return ['success' => true, 'message_id' => db()->lastInsertId()];
});

$router->api('GET', '/api/search/users', function() {
    require_auth();
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 1) {
        return ['users' => []];
    }
    $stmt = db()->prepare(
        "SELECT id, first_name, last_name, avatar FROM users 
         WHERE first_name LIKE ? OR last_name LIKE ? 
         ORDER BY last_name ASC LIMIT 10"
    );
    $like = "%{$q}%";
    $stmt->execute([$like, $like]);
    return ['users' => $stmt->fetchAll()];
});

// Создание поста с файлом
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
            if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                $imagePath = 'uploads/posts/'.$filename;
            }
        }
    }

    if ($content === '' && !$imagePath) {
        http_response_code(422);
        return ['error' => 'Пост не может быть пустым'];
    }

    $postId = insert('posts', [
        'user_id' => $_SESSION['user_id'],
        'content' => $content,
        'image' => $imagePath,
        'likes_count' => 0,
        'dislikes_count' => 0
    ]);
    db()->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['user_id']]);
    return ['success' => true, 'post' => find('posts', $postId)];
});

// Лайк поста (независимый toggle)
$router->api('POST', '/api/posts/like', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $postId = (int)($data['post_id'] ?? 0);
    if ($postId <= 0) return ['error' => 'Неверный ID поста'];
    $userId = $_SESSION['user_id'];
    $post = find('posts', $postId);
    if (!$post) return ['error' => 'Пост не найден'];

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
    return [
        'success' => true,
        'likes_count' => $updatedPost['likes_count'],
        'dislikes_count' => $updatedPost['dislikes_count'],
        'user_liked' => $userLiked,
        'user_disliked' => false
    ];
});

// Дизлайк поста (независимый toggle)
$router->api('POST', '/api/posts/dislike', function() {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $postId = (int)($data['post_id'] ?? 0);
    if ($postId <= 0) return ['error' => 'Неверный ID поста'];
    $userId = $_SESSION['user_id'];
    $post = find('posts', $postId);
    if (!$post) return ['error' => 'Пост не найден'];

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
    return [
        'success' => true,
        'likes_count' => $updatedPost['likes_count'],
        'dislikes_count' => $updatedPost['dislikes_count'],
        'user_liked' => false,
        'user_disliked' => $userDisliked
    ];
});

// Получить комментарии к посту
$router->api('GET', '/api/posts/{postId}/comments', function($postId) {
    require_auth();
    $postId = (int)$postId;
    $comments = select(
        "SELECT c.*, u.first_name, u.last_name, u.avatar
         FROM comments c
         JOIN users u ON u.id = c.user_id
         WHERE c.post_id = ?
         ORDER BY c.created_at ASC",
        [$postId]
    );
    return ['comments' => $comments];
});

// Создать комментарий
$router->api('POST', '/api/posts/{postId}/comments', function($postId) {
    require_auth();
    $data = json_decode(file_get_contents('php://input'), true);
    $content = trim($data['content'] ?? '');
    if ($content === '') {
        http_response_code(422);
        return ['error' => 'Комментарий не может быть пустым'];
    }
    $postId = (int)$postId;
    $post = find('posts', $postId);
    if (!$post) return ['error' => 'Пост не найден'];

    $commentId = insert('comments', [
        'post_id' => $postId,
        'user_id' => $_SESSION['user_id'],
        'content' => $content
    ]);

    $author = find('users', $_SESSION['user_id']);
    $comment = find('comments', $commentId);
    $comment['first_name'] = $author['first_name'];
    $comment['last_name']  = $author['last_name'];
    $comment['avatar']     = $author['avatar'] ?? '';

    return ['success' => true, 'comment' => $comment];
});

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);