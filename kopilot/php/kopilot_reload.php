<?php
// kopilot/php/kopilot_reload.php
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json');

// Путь к корню сайта (на два уровня выше)
$root = realpath(__DIR__ . '/../..');
if (!$root) {
    echo json_encode(['timestamp' => time()]);
    exit;
}

// Рекурсивно собираем время последней модификации
$maxMtime = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

clearstatcache();

foreach ($iterator as $file) {
    if ($file->isFile()) {
        // Пропускаем сам скрипт перезагрузки
        if ($file->getRealPath() === __FILE__) continue;
        $mtime = $file->getMTime();
        if ($mtime > $maxMtime) {
            $maxMtime = $mtime;
        }
    }
}

echo json_encode(['timestamp' => $maxMtime ?: time()]);