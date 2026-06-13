<?php
// router.php для встроенного сервера PHP
if (php_sapi_name() === 'cli-server') {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    
    // API-запросы передаём в api.php
    if (str_starts_with($uri, '/api/')) {
        require __DIR__ . '/api.php';
        return true;
    }
    
    // Статические файлы отдаём напрямую, если они существуют
    $publicPath = __DIR__ . '/public' . $uri;
    if (file_exists($publicPath) && !is_dir($publicPath)) {
        return false; // будет отдано встроенным сервером
    }
    
    // Остальные запросы — на index.php или другой фронт-контроллер
    require __DIR__ . '/public/index.php';
    return true;
}