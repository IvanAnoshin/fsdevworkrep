<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';
require_auth();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Friendscape — Загрузка</title>
    <style>
        body {
            margin: 0;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .preloader-container {
            text-align: center;
        }
        .preloader-text {
            color: #3b5dd3;
            font-size: 3em;
            font-weight: 600;
            display: inline-block; /* ← обязательно для работы transform */
            animation: gentlePulse 5s infinite ease-in-out;
        }
        @keyframes gentlePulse {
            0%   { transform: scale(1);   opacity: 0.9; }
            50%  { transform: scale(1.08); opacity: 1; }
            100% { transform: scale(1);   opacity: 0.9; }
        }
    </style>
</head>
<body>
    <div class="preloader-container">
        <span class="preloader-text">Friendscape</span>
    </div>

    <script>
         setTimeout(() => {
             window.location.href = '/profile.php';
         }, 5000);
    </script>
</body>
</html>