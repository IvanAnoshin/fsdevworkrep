<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';
$pageTitle = 'Поиск - Friendscape';
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
    <div class="sidebar"><?php require_once "components/header.php"; ?></div>

    <div class="searchArea">
        <div class="searchContainer" id="searchContainer">
            <div class="searchInputWrapper">
                <span class="searchIcon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
                    </svg>
                </span>
                <input type="text" id="searchInput" class="searchInput" placeholder="Искать людей..." autocomplete="off">
            </div>
            <div id="searchResults" class="searchResults"></div>
        </div>
    </div>

    <script>
        const container = document.getElementById('searchContainer');
        const input = document.getElementById('searchInput');
        const results = document.getElementById('searchResults');
        let debounceTimer;

        input.addEventListener('input', function() {
            const query = this.value.trim();
            clearTimeout(debounceTimer);
            
            if (query.length > 0) {
                container.classList.add('active');
                // Дебаунс: ждём 300 мс после последнего ввода
                debounceTimer = setTimeout(async () => {
                    try {
                        const data = await kop.get(`/api/search/users?q=${encodeURIComponent(query)}`);
                        if (data.users.length === 0) {
                            results.innerHTML = '<p style="color:#8b8fa3;text-align:center;padding:20px;">Не найдено</p>';
                        } else {
                            results.innerHTML = data.users.map(user => {
                                const initials = (user.first_name?.charAt(0) || '') + (user.last_name?.charAt(0) || '');
                                return `
                                    <div class="searchResultItem" onclick="window.location='user.php?id=${user.id}'" style="cursor:pointer;">
                                        <div class="searchResultAvatar" style="background: #e0f2fe; color: #0284c7;">
                                            ${user.avatar ? 
                                                `<img src="${user.avatar}" alt="">` : 
                                                `<span class="searchResultInitials">${initials}</span>`
                                            }
                                        </div>
                                        <div class="searchResultName">
                                            <span>${user.first_name} ${user.last_name}</span>
                                        </div>
                                    </div>
                                `;
                            }).join('');
                        }
                        requestAnimationFrame(() => {
                            results.classList.add('visible');
                        });
                    } catch (e) {
                        results.innerHTML = '<p style="color:#b91c1c;text-align:center;padding:20px;">Ошибка поиска</p>';
                    }
                }, 300);
            } else {
                clearTimeout(debounceTimer);
                results.classList.remove('visible');
                container.classList.remove('active');
                setTimeout(() => {
                    if (!results.classList.contains('visible')) {
                        results.innerHTML = '';
                    }
                }, 250);
            }
        });
    </script>
</body>
</html>