<?php
/*
    Kopilot Framework
    version 1.0

    Посвящаю Сереге

*/

// ПОДКЛЮЧЕНИЕ К БАЗЕ

define('DB_HOST', 'localhost');
define('DB_NAME', 'fsdb');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('DB Connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('Внутренняя ошибка сервера');
        }
    }
    return $pdo;
}

// БЕЗОПАСНЫЙ ВЫВОД
function esc(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ХЕЛПЕРЫ

function redirect(string $url, int $code = 302): never {
    http_response_code($code);
    header("Location: $url");
    exit;
}

function flash(string $key, mixed $value = null): mixed {
    if ($value !== null) {
        $_SESSION['_flash'][$key] = $value;
        return null;
    }
    $val = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $val;
}

function old(string $key, mixed $default = ''): mixed {
    return $_SESSION['_old'][$key] ?? $default;
}

function set_old(array $data): void {
    $_SESSION['_old'] = $data;
}

function clear_old(): void {
    unset($_SESSION['_old']);
}

// CSRF-защита
function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void {
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('CSRF-токен недействителен');
    }
}

// АВТОРИЗАЦИЯ

function is_logged_in(): bool {
    return !empty($_SESSION['authenticated']) && !empty($_SESSION['user_id']);
}

function require_auth(): void {
    if (!is_logged_in()) {
        redirect('/login.php');
    }
}

function require_guest (): void {
    if (is_logged_in()) {
        redirect('/profile.php');
    }
}

// ФУНКЦИИ ДЛЯ РАБОТЫ С БАЗОЙ

function find(string $table, mixed $id): ?array {
    $stmt = db()->prepare("SELECT * FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function all(string $table, string $order = ''): array {
    $sql = "SELECT * FROM $table";
    if ($order) $sql .= " ORDER BY $order";
    $stmt = db()->query($sql);
    return $stmt->fetchAll();
}

function insert(string $table, array $data): int {
    $columns = implode(', ', array_keys($data));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    $stmt = db()->prepare("INSERT INTO $table ($columns) VALUES ($placeholders)");
    $stmt->execute(array_values($data));
    return (int) db()->lastInsertId();
}

function update(string $table, mixed $id, array $data): void {
    $sets = implode(', ', array_map(fn($col) => "$col = ?", array_keys($data)));
    $stmt = db()->prepare("UPDATE $table SET $sets WHERE id = ?");
    $stmt->execute([...array_values($data), $id]);
}

function select(string $sql, array $params = []): array {
    return fetch_data(['_' => ['sql' => $sql, 'params' => $params, 'mode' => 'all']])['_'];
}

function scalar(string $sql, array $params = []): mixed {
    return fetch_data(['_' => ['sql' => $sql, 'params' => $params, 'mode' => 'scalar']])['_'];
}

function fetch_data(array $queries): array {
    $result = [];
    foreach ($queries as $varName => $config) {
        // Если передан просто SQL-строка (без параметров)
        if (is_string($config)) {
            $config = ['sql' => $config, 'params' => [], 'mode' => 'all'];
        }
        // Если передан индексный массив [sql, params, mode]
        if (isset($config[0]) && is_string($config[0]) && !isset($config['sql'])) {
            $config = [
                'sql'    => $config[0],
                'params' => $config[1] ?? [],
                'mode'   => $config[2] ?? 'all',
            ];
        }

        $sql    = $config['sql']    ?? '';
        $params = $config['params'] ?? [];
        $mode   = $config['mode']   ?? 'all';

        $stmt = db()->prepare($sql);
        $stmt->execute((array) $params);

        $result[$varName] = match ($mode) {
            'one'    => $stmt->fetch() ?: null,
            'scalar' => $stmt->fetchColumn(),
            default  => $stmt->fetchAll(),
        };
    }
    return $result;
}

// ПАГИНАЦИЯ
function paginate(string $sql, array $params, int $page = 1, int $perPage = 10): array {
    // Заменяем первый SELECT ... FROM на SELECT COUNT(*) FROM
    $countSql = preg_replace('/SELECT\s+.*?\s+FROM/i', 'SELECT COUNT(*) FROM', $sql, 1);
    $total = scalar($countSql, $params);
    $lastPage = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $lastPage));
    $offset = ($page - 1) * $perPage;
    $sqlWithLimit = $sql . " LIMIT $perPage OFFSET $offset";
    $records = select($sqlWithLimit, $params);
    return [
        'records'  => $records,
        'total'    => $total,
        'page'     => $page,
        'perPage'  => $perPage,
        'lastPage' => $lastPage,
        'hasPrev'  => $page > 1,
        'hasNext'  => $page < $lastPage,
    ];
}

// КЕШИРОВАНИЕ
function cache(string $key, callable $callback, int $ttl = 3600): mixed {
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $file = $dir . '/' . md5($key) . '.cache';
    if (file_exists($file) && time() - filemtime($file) < $ttl) {
        return unserialize(file_get_contents($file));
    }
    $data = $callback();
    file_put_contents($file, serialize($data));
    return $data;
}

// ОЧИСТКА КЕША

// Удалить кеш по конкретному ключу
function cache_forget(string $key): void {
    $dir = __DIR__ . '/cache';
    $file = $dir . '/' . md5($key) . '.cache';
    if (file_exists($file)) {
        unlink($file);
    }
}

// Полностью очистить весь кеш
function cache_clear(): void {
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) return;
    $files = glob($dir . '/*.cache');
    foreach ($files as $file) {
        unlink($file);
    }
}

// Удалить устаревшие файлы кеша (старше указанного возраста в секундах)
function cache_prune(int $maxAge = 3600): void {
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) return;
    $files = glob($dir . '/*.cache');
    $now = time();
    foreach ($files as $file) {
        if ($now - filemtime($file) > $maxAge) {
            unlink($file);
        }
    }
}

// Функция для JSON ответов

function json_response(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// РАБОТА С ВВОДОМ
function validate(array $rules): array {
    $errors = [];
    foreach ($rules as $field => $rule) {
        $value = input($field);
        $ruleList = explode('|', $rule);
        foreach ($ruleList as $r) {
            if ($r === 'required' && ($value === '' || $value === null)) {
                $errors[$field] = "Поле обязательно для заполнения";
            } elseif (str_starts_with($r, 'min:')) {
                $min = (int) substr($r, 4);
                if (mb_strlen($value) < $min) {
                    $errors[$field] = "Минимум $min символов";
                }
            } elseif (str_starts_with($r, 'max:')) {
                $max = (int) substr($r, 4);
                if (mb_strlen($value) > $max) {
                    $errors[$field] = "Максимум $max символов";
                }
            } elseif ($r === 'alpha' && !preg_match('/^[\p{L}\s\-]+$/u', $value)) {
                $errors[$field] = "Только буквы, пробелы и дефисы";
            } elseif ($r === 'alphanumeric' && !preg_match('/^[a-zA-Z0-9_]+$/', $value)) {
                $errors[$field] = "Только латиница, цифры и _";
            }
            
            if (isset($errors[$field])) break;
        }
    }
    if (!empty($errors)) {
        flash('errors', $errors);
        set_old($_POST);
        redirect($_SERVER['HTTP_REFERER'] ?? '/');
    }
    return $errors;
}

function input(string $name, string $default = ''): string {
    return trim($_POST[$name] ?? $default);
}

// ШАБЛОНИЗАТОР

function view(string $template, array $data = []): string {
    extract($data);
    ob_start();
    include __DIR__ . "/templates/$template.php";
    return ob_get_clean();
}

function render(string $template, array $data = []): void {
    echo view($template, $data);
}

// РОУТЕР

class Router {
    private array $routes = [];
    private string $prefix = '';

    public function group(string $prefix, callable $callback): void {
        $previous = $this->prefix;
        $this->prefix .= $prefix;
        $callback($this);
        $this->prefix = $previous;
    }

    public function add(string $method, string $path, callable $handler): void {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $this->prefix . $path,
            'handler' => $handler,
        ];
    }

    public function get(string $path, callable $handler): void {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void {
        $this->add('POST', $path, $handler);
    }

    public function api(string $method, string $path, callable $handler): void {
        $this->add($method, $path, function(...$params) use ($handler) {
            // CSRF-проверка для не-GET запросов
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                csrf_check();
            }
            try {
                $result = $handler(...$params);
                if (is_array($result) || is_object($result)) {
                    json_response($result);
                } elseif (is_string($result)) {
                    json_response(['message' => $result]);
                } else {
                    json_response(['success' => true]);
                }
            } catch (ValidationException $e) {
                json_response(['errors' => $e->getErrors()], 422);
            } catch (Exception $e) {
                error_log($e->getMessage());
                json_response(['error' => 'Внутренняя ошибка сервера'], 500);
            }
        });
    }

    public function dispatch(string $method, string $uri): void {
        $uri = parse_url($uri, PHP_URL_PATH);
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) continue;

            $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                call_user_func($route['handler'], ...$params);
                return;
            }
        }

        http_response_code(404);
        echo 'Страница не найдена';
    }
}

$router = new Router();

// ОБРАБОТКА ФОРМ БЕЗ BOILERPLATE

function form(callable $handler): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    try {
        $handler();
    } catch (FormError $e) {
        redirect($_SERVER['HTTP_REFERER'] ?? '/');
    }
}

class ValidationException extends Exception {
    private array $errors;
    public function __construct(array $errors) {
        $this->errors = $errors;
        parent::__construct('Validation failed');
    }
    public function getErrors(): array {
        return $this->errors;
    }
}

class FormError extends Exception {}

function error(string $field, string $message): void {
    $errors = flash('errors') ?? [];
    $errors[$field] = $message;
    flash('errors', $errors);
    set_old($_POST);
    throw new FormError($message);
}

// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ

function getOrCreateChat(int $user1, int $user2): int {
    // Проверяем существующий чат
    $stmt = db()->prepare(
        "SELECT id FROM chats WHERE (user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)"
    );
    $stmt->execute([$user1, $user2, $user2, $user1]);
    $chat = $stmt->fetch();
    if ($chat) return $chat['id'];

    // Создаём новый
    $chatId = insert('chats', [
        'user1_id' => min($user1, $user2),
        'user2_id' => max($user1, $user2),
        'last_message_at' => date('Y-m-d H:i:s')
    ]);
    return $chatId;
}

function renderPost(array $post, array $author): string {
    ob_start();
    ?>
    <div class="post">
        <div class="postHeader">
            <img class="opPicture" src="<?= esc($author['avatar'] ?? '') ?>" alt="">
            <div class="opLabel">
                <a href="user.php?id=<?= $author['id'] ?>"><?= esc($author['first_name'] . ' ' . $author['last_name']) ?></a>
            </div>
            <div class="postOptions">
                <button>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                </button>
            </div>
        </div>
        <img class="postBody" src="<?= esc($post['image'] ?? '') ?>" alt="">
        <div class="postFooter">
            <div class="postReactions">
                <button class="likeButton">
                    <span class="Menu__icon" style="background: #d1fae5; color: #059669;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </span>
                </button>
                <span class="positiveCounter"><?= $post['likes_count'] ?? 0 ?></span>
                <button class="dislikeButton">
                    <span class="Menu__icon" style="background: #fee2e2; color: #b91c1c;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </span>
                </button>
                <span class="negativeCounter"><?= $post['dislikes_count'] ?? 0 ?></span>
            </div>
            <div class="postActions">
                <button class="commentSheet">
                    <span class="Menu__icon" style="background: #e8e0fc; color: #7c3aed;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    </span>
                </button>
                <button class="sharePost">
                    <span class="Menu__icon" style="background: #fce7f3; color: #db2777;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98"/><path d="M15.41 6.51l-6.82 3.98"/></svg>
                    </span>
                </button>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}