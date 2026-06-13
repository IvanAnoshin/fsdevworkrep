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
                <input type="text" id="searchInput" class="searchInput" placeholder="Искать людей, посты и хештеги..." autocomplete="off">
            </div>
            <div id="searchResults" class="searchResults"></div>
        </div>
    </div>

    <!-- Модальное окно комментариев -->
    <div class="modal-overlay" id="comments-modal" style="display: none;">
        <div class="modal-container">
            <span class="modal-close" id="modal-close">&times;</span>
            <div id="modal-post-container"></div>
            <div class="comments-block">
                <div class="comment-form">
                    <textarea id="comment-input" placeholder="Написать комментарий..."></textarea>
                    <button id="comment-send-btn">
                        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="22" y1="2" x2="11" y2="13"/>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                            </svg>
                        </span>
                    </button>
                </div>
                <div class="comments-list" id="comments-list"></div>
            </div>
        </div>
    </div>

    <script>
        // ---------- Глобальные функции ----------
        function esc(str) {
            if (str == null) return '';
            return String(str).replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        function renderHashtags(text) {
            if (!text) return '';
            return esc(text).replace(/#([\w\p{L}]+)/gu, '<a href="search.php?q=%23$1" class="hashtag-link">#$1</a>');
        }

        // Приведение путей к изображениям к абсолютному виду
        function fixMediaUrl(url) {
            if (!url) return '';
            if (url.startsWith('http')) return url;
            return window.location.origin + '/' + url.replace(/^\/+/, '');
        }

        // Рендеринг полноценного поста
        function renderFullPost(post) {
            const initials = (post.first_name?.charAt(0) || '') + (post.last_name?.charAt(0) || '');
            const avatarHtml = post.avatar
                ? `<img class="opPicture" src="${esc(post.avatar)}" alt="">`
                : `<div class="opPicture-placeholder">${initials}</div>`;

            // Нормализуем медиа: иногда приходит строка, иногда массив
            let mediaArray = [];
            if (post.media) {
                if (Array.isArray(post.media)) {
                    mediaArray = post.media;
                } else if (typeof post.media === 'string' && post.media.length > 0) {
                    try {
                        mediaArray = JSON.parse(post.media);
                    } catch (e) {
                        // если не парсится, пробуем разбить как список URL
                        mediaArray = post.media.split(',').map(url => ({ url: url.trim(), type: 'image' }));
                    }
                }
            }

            let mediaHtml = '';
            if (mediaArray.length > 0) {
                // Убедимся, что у всех элементов есть url и type
                mediaArray = mediaArray.filter(m => m && m.url);
                if (mediaArray.length > 0) {
                    mediaHtml = `
                        <div class="carousel-container">
                            <div class="carousel-track">
                                ${mediaArray.map(media => {
                                    const url = fixMediaUrl(media.url);
                                    if (media.type === 'video') {
                                        return `<div class="carousel-slide"><video controls src="${esc(url)}" preload="metadata"></video></div>`;
                                    } else {
                                        return `<div class="carousel-slide"><img src="${esc(url)}" alt=""></div>`;
                                    }
                                }).join('')}
                            </div>
                            ${mediaArray.length > 1 ? `
                                <button class="carousel-prev">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                                </button>
                                <button class="carousel-next">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                                </button>
                                <div class="carousel-dots"></div>
                            ` : ''}
                        </div>`;
                }
            }

            const textHtml = post.content ? `<div class="postBodyText">${renderHashtags(post.content)}</div>` : '';

            return `
                <div class="post" data-post-id="${post.id}" data-author-id="${post.user_id}">
                    <div class="postHeader">
                        ${avatarHtml}
                        <div class="opLabel">
                            <a href="user.php?id=${post.user_id}">${esc(post.first_name)} ${esc(post.last_name)}</a>
                        </div>
                    </div>
                    <div class="postBody">
                        ${mediaHtml}
                        ${textHtml}
                    </div>
                    <div class="postFooter">
                        <div class="postReactions">
                            <button class="likeButton" data-post-id="${post.id}">
                                <span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                </span>
                            </button>
                            <p class="positiveCounter">${post.likes_count}</p>
                            <button class="dislikeButton" data-post-id="${post.id}">
                                <span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                </span>
                            </button>
                            <p class="negativeCounter">${post.dislikes_count}</p>
                        </div>
                        <div class="postActions">
                            <button class="commentSheet" data-post-id="${post.id}">
                                <span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                                </span>
                            </button>
                            <button class="collectionButton" data-post-id="${post.id}" title="В коллекцию">
                                <span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
                                </span>
                            </button>
                            <button class="sharePost" data-post-id="${post.id}">
                                <span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98"/><path d="M15.41 6.51l-6.82 3.98"/></svg>
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }

        // Инициализация карусели
        function initCarousel(container) {
            if (!container) return;
            const track = container.querySelector('.carousel-track');
            const slides = track ? Array.from(track.children) : [];
            if (slides.length <= 1) return;

            const prevBtn = container.querySelector('.carousel-prev');
            const nextBtn = container.querySelector('.carousel-next');
            const dotsNav = container.querySelector('.carousel-dots');
            let currentIndex = 0;

            function updateCarouselTransform() {
                const slideWidth = slides[0].getBoundingClientRect().width;
                track.style.transform = 'translateX(-' + (currentIndex * slideWidth) + 'px)';
                if (dotsNav) {
                    Array.from(dotsNav.children).forEach((dot, i) => {
                        dot.classList.toggle('active', i === currentIndex);
                    });
                }
            }

            function goToSlide(index) {
                if (index < 0) index = 0;
                if (index >= slides.length) index = slides.length - 1;
                if (index === currentIndex) return;
                currentIndex = index;
                updateCarouselTransform();
            }

            if (prevBtn) prevBtn.addEventListener('click', () => goToSlide(currentIndex - 1));
            if (nextBtn) nextBtn.addEventListener('click', () => goToSlide(currentIndex + 1));

            if (dotsNav && dotsNav.children.length === 0 && slides.length > 1) {
                for (let i = 0; i < slides.length; i++) {
                    const dot = document.createElement('button');
                    dot.classList.add('carousel-dot');
                    if (i === currentIndex) dot.classList.add('active');
                    dot.addEventListener('click', () => goToSlide(i));
                    dotsNav.appendChild(dot);
                }
            }

            window.addEventListener('resize', updateCarouselTransform);
            updateCarouselTransform();
        }

        // ---------- МОДАЛКА КОММЕНТАРИЕВ ----------
        function closeModal() {
            const modal = document.getElementById('comments-modal');
            if (!modal) return;
            modal.classList.remove('active');
            setTimeout(() => { modal.style.display = 'none'; }, 300);
            document.body.classList.remove('no-scroll');
        }

        async function openCommentsModal(postId) {
            const modal = document.getElementById('comments-modal');
            const postContainer = document.getElementById('modal-post-container');
            const commentsList = document.getElementById('comments-list');
            const commentInput = document.getElementById('comment-input');
            const sendBtn = document.getElementById('comment-send-btn');

            const originalPost = document.querySelector(`.post[data-post-id="${postId}"]`);
            if (originalPost) {
                const bodyClone = originalPost.querySelector('.postBody').cloneNode(true);
                postContainer.innerHTML = '';
                postContainer.appendChild(bodyClone);
                postContainer.querySelectorAll('.carousel-container').forEach(initCarousel);
            } else {
                postContainer.innerHTML = '<p>Пост не найден</p>';
            }

            try {
                const data = await kop.get(`/api/posts/${postId}/comments`);
                if (data.comments && data.comments.length > 0) {
                    data.comments.reverse();
                    commentsList.innerHTML = data.comments.map(c => {
                        const initials = (c.first_name?.charAt(0) || '') + (c.last_name?.charAt(0) || '');
                        const avatarHtml = c.avatar
                            ? `<img src="${c.avatar}" alt="">`
                            : `<span class="comment-avatar-placeholder">${initials}</span>`;
                        return `
                            <div class="comment-item">
                                <div class="comment-avatar">${avatarHtml}</div>
                                <div class="comment-content">
                                    <div class="comment-author"><a href="user.php?id=${c.user_id}">${esc(c.first_name)} ${esc(c.last_name)}</a></div>
                                    <div class="comment-text">${esc(c.content)}</div>
                                    <div class="comment-date">${new Date(c.created_at).toLocaleString('ru-RU')}</div>
                                </div>
                            </div>
                        `;
                    }).join('');
                } else {
                    commentsList.innerHTML = '<p class="no-comments">Нет комментариев. Будьте первым!</p>';
                }
            } catch (e) {
                commentsList.innerHTML = '<p class="error">Ошибка загрузки комментариев</p>';
            }

            const newSendBtn = sendBtn.cloneNode(true);
            sendBtn.parentNode.replaceChild(newSendBtn, sendBtn);
            newSendBtn.onclick = async () => {
                const content = commentInput.value.trim();
                if (!content) return;
                try {
                    const response = await kop.post(`/api/posts/${postId}/comments`, { content });
                    if (response.success) {
                        const c = response.comment;
                        const initials = (c.first_name?.charAt(0) || '') + (c.last_name?.charAt(0) || '');
                        const avatarHtml = c.avatar
                            ? `<img src="${c.avatar}" alt="">`
                            : `<span class="comment-avatar-placeholder">${initials}</span>`;
                        const newCommentHtml = `
                            <div class="comment-item">
                                <div class="comment-avatar">${avatarHtml}</div>
                                <div class="comment-content">
                                    <div class="comment-author"><a href="user.php?id=${c.user_id}">${esc(c.first_name)} ${esc(c.last_name)}</a></div>
                                    <div class="comment-text">${esc(c.content)}</div>
                                    <div class="comment-date">${new Date(c.created_at).toLocaleString('ru-RU')}</div>
                                </div>
                            </div>
                        `;
                        if (commentsList.querySelector('.no-comments')) commentsList.innerHTML = '';
                        commentsList.insertAdjacentHTML('afterbegin', newCommentHtml);
                        commentInput.value = '';
                    }
                } catch (e) {
                    kop.flash('Ошибка отправки комментария');
                }
            };

            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('active'), 10);
            document.body.classList.add('no-scroll');

            const closeBtn = document.getElementById('modal-close');
            if (closeBtn) {
                const newCloseBtn = closeBtn.cloneNode(true);
                closeBtn.parentNode.replaceChild(newCloseBtn, closeBtn);
                newCloseBtn.addEventListener('click', closeModal);
            }
            modal.addEventListener('click', function onOverlayClick(e) {
                if (e.target === modal) {
                    closeModal();
                    modal.removeEventListener('click', onOverlayClick);
                }
            });
        }

        // ---------- ОБРАБОТЧИКИ КНОПОК ----------
        function attachPostHandlers() {
            // Снимаем старые обработчики, чтобы не дублировались
            document.querySelectorAll('.likeButton, .dislikeButton').forEach(btn => {
                btn.dataset.handlerAttached = '0';
            });
            document.querySelectorAll('.commentSheet').forEach(btn => {
                btn.dataset.commentAttached = '0';
            });
            document.querySelectorAll('.collectionButton').forEach(btn => {
                btn.dataset.collectionAttached = '0';
            });
            document.querySelectorAll('.sharePost').forEach(btn => {
                btn.dataset.shareAttached = '0';
            });

            // Реакции
            document.querySelectorAll('.likeButton, .dislikeButton').forEach(btn => {
                if (btn.dataset.handlerAttached === '1') return;
                btn.dataset.handlerAttached = '1';
                btn.addEventListener('click', async function(e) {
                    e.stopPropagation();
                    const postId = this.dataset.postId;
                    const type = this.classList.contains('likeButton') ? 'like' : 'dislike';
                    const endpoint = type === 'like' ? '/api/posts/like' : '/api/posts/dislike';
                    try {
                        const response = await kop.post(endpoint, { post_id: postId });
                        if (response.success) {
                            const postDiv = this.closest('.post');
                            postDiv.querySelector('.positiveCounter').textContent = response.likes_count;
                            postDiv.querySelector('.negativeCounter').textContent = response.dislikes_count;
                            const likeBtn = postDiv.querySelector('.likeButton');
                            const dislikeBtn = postDiv.querySelector('.dislikeButton');
                            likeBtn.classList.remove('active');
                            dislikeBtn.classList.remove('active');
                            if (response.user_liked) likeBtn.classList.add('active');
                            if (response.user_disliked) dislikeBtn.classList.add('active');
                        }
                    } catch(e) {}
                });
            });

            // Комментарии
            document.querySelectorAll('.commentSheet').forEach(btn => {
                if (btn.dataset.commentAttached === '1') return;
                btn.dataset.commentAttached = '1';
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    openCommentsModal(this.dataset.postId);
                });
            });

            // Коллекция
            document.querySelectorAll('.collectionButton').forEach(btn => {
                if (btn.dataset.collectionAttached === '1') return;
                btn.dataset.collectionAttached = '1';
                btn.addEventListener('click', async function(e) {
                    e.stopPropagation();
                    try {
                        const resp = await kop.post('/api/collection/add', { post_id: this.dataset.postId });
                        kop.flash(resp.success ? 'Добавлено в коллекцию' : (resp.error || 'Ошибка'));
                    } catch(e) { kop.flash('Ошибка'); }
                });
            });

            // Поделиться
            document.querySelectorAll('.sharePost').forEach(btn => {
                if (btn.dataset.shareAttached === '1') return;
                btn.dataset.shareAttached = '1';
                btn.addEventListener('click', async function(e) {
                    e.stopPropagation();
                    const postUrl = `${window.location.origin}/post.php?id=${this.dataset.postId}`;
                    try {
                        await navigator.clipboard.writeText(postUrl);
                        kop.flash('Ссылка на пост скопирована');
                    } catch(e) { kop.flash('Не удалось скопировать ссылку'); }
                });
            });
        }

        // ---------- Поиск ----------
        const container = document.getElementById('searchContainer');
        const input = document.getElementById('searchInput');
        const results = document.getElementById('searchResults');
        let debounceTimer;

        input.focus();

        document.addEventListener('keydown', function(e) {
            const tag = document.activeElement?.tagName?.toLowerCase();
            const isEditable = document.activeElement?.isContentEditable;
            if (tag === 'input' || tag === 'textarea' || isEditable) return;
            if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
                e.preventDefault();
                input.focus();
                input.value += e.key;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });

        input.addEventListener('input', function() {
            const query = this.value.trim();
            clearTimeout(debounceTimer);

            if (query.length === 0) {
                results.classList.remove('visible');
                container.classList.remove('active');
                setTimeout(() => {
                    if (!results.classList.contains('visible')) results.innerHTML = '';
                }, 250);
                return;
            }

            container.classList.add('active');

            debounceTimer = setTimeout(async () => {
                let html = '';

                try {
                    const [usersData, postsData] = await Promise.all([
                        kop.get(`/api/search/users?q=${encodeURIComponent(query)}`),
                        kop.get(`/api/search/content?q=${encodeURIComponent(query)}`)
                    ]);

                    const users = usersData.users || [];
                    const posts = postsData.posts || [];

                    if (users.length > 0) {
                        html += '<div class="search-results-section"><h3>Люди</h3>';
                        html += users.map(user => {
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
                        html += '</div>';
                    }

                    if (posts.length > 0) {
                        if (users.length > 0) {
                            html += '<hr class="section-divider">';
                        }
                        html += '<div class="search-results-section"><h3>Посты</h3>';
                        html += posts.map(post => renderFullPost(post)).join('');
                        html += '</div>';
                    }

                    if (html === '') {
                        html = '<p style="color:#8b8fa3;text-align:center;padding:20px;">Ничего не найдено</p>';
                    }

                    results.innerHTML = html;

                    // Инициализируем карусели и обработчики
                    document.querySelectorAll('.carousel-container').forEach(c => initCarousel(c));
                    attachPostHandlers();

                    requestAnimationFrame(() => results.classList.add('visible'));
                } catch (e) {
                    results.innerHTML = '<p style="color:#b91c1c;text-align:center;padding:20px;">Ошибка поиска</p>';
                }
            }, 300);
        });
    </script>
</body>
</html>