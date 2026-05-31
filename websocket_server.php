<?php
// Простейший WebSocket-сервер без зависимостей
// Запуск: php websocket_server.php

require_once __DIR__ . '/kopilot/php/kopilot.php';

$host = '0.0.0.0';
$port = 8080;

$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($server, $host, $port);
socket_listen($server);
socket_set_nonblock($server);

$clients = [];

function wsHandshake($socket, $headers) {
    preg_match('/Sec-WebSocket-Key:\s(.*)\r\n/', $headers, $matches);
    if (!isset($matches[1])) return false;
    $key = trim($matches[1]);
    $acceptKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $response = "HTTP/1.1 101 Switching Protocols\r\n"
              . "Upgrade: websocket\r\n"
              . "Connection: Upgrade\r\n"
              . "Sec-WebSocket-Accept: $acceptKey\r\n\r\n";
    socket_write($socket, $response, strlen($response));
    return true;
}

function wsDecode($data) {
    if (strlen($data) < 2) return null;
    $firstByte = ord($data[0]);
    $secondByte = ord($data[1]);
    $opcode = $firstByte & 0x0F;
    if ($opcode == 8) return ['type' => 'close'];
    if ($opcode != 1) return null;
    $masked = ($secondByte & 0x80) != 0;
    $payloadLength = $secondByte & 0x7F;
    $offset = 2;
    if ($payloadLength == 126) {
        $payloadLength = unpack('n', substr($data, 2, 2))[1];
        $offset = 4;
    } elseif ($payloadLength == 127) {
        return null;
    }
    $maskKey = $masked ? substr($data, $offset, 4) : '';
    $offset += $masked ? 4 : 0;
    $payload = substr($data, $offset, $payloadLength);
    if ($masked && $maskKey) {
        for ($i = 0; $i < strlen($payload); $i++) {
            $payload[$i] = $payload[$i] ^ $maskKey[$i % 4];
        }
    }
    return ['type' => 'text', 'data' => $payload];
}

function wsEncode($payload) {
    $length = strlen($payload);
    $frame = chr(0x81);
    if ($length <= 125) {
        $frame .= chr($length);
    } elseif ($length <= 65535) {
        $frame .= chr(126) . pack('n', $length);
    } else {
        $frame .= chr(127) . pack('J', $length);
    }
    $frame .= $payload;
    return $frame;
}

echo "WebSocket сервер запущен на порту $port\n";

while (true) {
    $newSocket = @socket_accept($server);
    if ($newSocket) {
        socket_set_nonblock($newSocket);
        $clients[(int)$newSocket] = ['socket' => $newSocket, 'userId' => null, 'buffer' => ''];
    }

    foreach ($clients as $key => &$client) {
        $socket = $client['socket'];
        $data = @socket_read($socket, 4096);

        if ($data === false || $data === '') {
            socket_close($socket);
            unset($clients[$key]);
            continue;
        }

        if ($client['userId'] === null && strpos($data, 'Upgrade: websocket') !== false) {
            wsHandshake($socket, $data);
            continue;
        }

        $decoded = wsDecode($data);
        if (!$decoded) continue;

        if ($decoded['type'] === 'close') {
            socket_close($socket);
            unset($clients[$key]);
            continue;
        }

        $message = json_decode($decoded['data'], true);
        if (!$message) continue;

        if ($message['type'] === 'auth') {
            $client['userId'] = $message['user_id'];
            continue;
        }

        if ($message['type'] === 'message' && $client['userId']) {
            $senderId = $client['userId'];
            $receiverId = $message['receiver_id'];
            $content = $message['content'];

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
            $messageId = insert('messages', [
                'chat_id' => $chatId,
                'sender_id' => $senderId,
                'content' => $content,
                'is_read' => 0
            ]);
            $now = date('Y-m-d H:i:s');

            $payload = json_encode([
                'type' => 'new_message',
                'message_id' => $messageId,
                'chat_id' => $chatId,
                'sender_id' => $senderId,
                'content' => $content,
                'created_at' => $now
            ]);
            $encodedPayload = wsEncode($payload);
            foreach ($clients as &$c) {
                if ($c['userId'] == $receiverId) {
                    socket_write($c['socket'], $encodedPayload, strlen($encodedPayload));
                }
            }
            $ack = json_encode([
                'type' => 'message_sent',
                'message_id' => $messageId,
                'chat_id' => $chatId,
                'other_user_id' => $receiverId
            ]);
            socket_write($socket, wsEncode($ack), strlen(wsEncode($ack)));
        }
    }
    usleep(10000);
}