<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';
require_auth();

$user = find('users', $_SESSION['user_id']);
if (!$user) {
    // На всякий случай: если авторизованный пользователь не найден, разлогиниваем
    session_destroy();
    redirect('/feed.php');
}

$pageTitle = 'Поиск - Friendscape';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($pageTitle) ?></title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        /* ---------- МОДАЛКА КОММЕНТАРИЕВ (как в исправленной ленте) ---------- */
        html body #comments-modal .modal-container {
            max-width: 480px !important;
            width: auto !important;
            margin-left: auto;
            margin-right: auto;
        }
        html body #comments-modal .comments-list {
            max-height: 40vh !important;
            overflow-y: auto !important;
            margin-bottom: 12px !important;
        }
        html body #comments-modal .comment-form {
            padding: 8px 0 0 !important;
        }

        /* Сброс ограничений для поста внутри модалки */
        #modal-post-container .post {
            height: auto !important;
            max-width: 100% !important;
            min-height: auto !important;
            margin: 0 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
        }

        .comments-list { display: flex; flex-direction: column; gap: 14px; margin-bottom: 20px; padding: 0 20px; }
        .comment-item { display: flex; gap: 12px; align-items: flex-start; }
        .comment-avatar { width: 32px; height: 32px; border-radius: 50%; overflow: hidden; flex-shrink: 0; background: #f0f0f0; display: flex; align-items: center; justify-content: center; }
        .comment-content { flex: 1; background: #f9fafb; border-radius: 12px; padding: 8px 12px; }
        .comment-author a { font-weight: 600; color: #3b5dd3; text-decoration: none; }
        .comment-text { color: #333; font-size: 0.95em; margin-top: 4px; }
        .comment-date { font-size: 0.75em; color: #888; margin-top: 4px; }
        .comment-form { display: flex; gap: 8px; align-items: center; padding: 0 20px 20px; }
        .comment-form textarea { flex: 1; resize: none; border: 1px solid #e0e0e0; border-radius: 20px; padding: 8px 14px; }
        .no-comments, .error { text-align: center; color: #888; padding: 20px; }
    </style>
</head>
<body>
    <?php require_once "components/header.php"; ?>

    <div class="searchArea" id="searchArea">
        <div class="searchWrapper">
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
    </div>

    <!-- Модальное окно комментариев (как в исправленной ленте) -->
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
        // ---------- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ----------
        function esc(str) {
            if (str == null) return '';
            return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
        }

        // ---------- ПОЛНОЭКРАННЫЙ ПРОСМОТР ----------
        function openFullMedia(src, type) {
            const overlay = document.createElement('div');
            overlay.className = 'media-fullscreen';
            const closeBtn = document.createElement('div');
            closeBtn.className = 'close-btn';
            closeBtn.innerHTML = '&times;';
            let media;
            if (type === 'image') {
                media = document.createElement('img');
                media.src = src;
                media.className = 'media-content';
            } else {
                media = document.createElement('video');
                media.src = src;
                media.controls = true;
                media.autoplay = true;
                media.className = 'media-content';
            }
            overlay.appendChild(media);
            overlay.appendChild(closeBtn);
            const removeOverlay = () => {
                overlay.classList.remove('visible');
                setTimeout(() => {
                    if (overlay.parentNode) overlay.remove();
                }, 300);
            };
            closeBtn.onclick = removeOverlay;
            overlay.onclick = (e) => {
                if (e.target === overlay) removeOverlay();
            };
            document.body.appendChild(overlay);
            requestAnimationFrame(() => {
                overlay.classList.add('visible');
            });
        }

        // ---------- КАРУСЕЛЬ ----------
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
                    Array.from(dotsNav.children).forEach((dot, i) => dot.classList.toggle('active', i === currentIndex));
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

        // ---------- МЕНЮ ПОСТА ----------
        const postMenu = document.createElement('div');
        postMenu.className = 'post-actions-menu';
        document.body.appendChild(postMenu);
        let currentOpenMenuPostId = null;

        function hidePostMenu() {
            postMenu.classList.remove('active');
            currentOpenMenuPostId = null;
        }

        document.addEventListener('click', (e) => {
            if (!postMenu.contains(e.target) && !e.target.closest('.postOptions button')) {
                hidePostMenu();
            }
        });

        function showPostMenu(button, postId) {
            if (currentOpenMenuPostId === postId) {
                hidePostMenu();
                return;
            }
            hidePostMenu();
            currentOpenMenuPostId = postId;

            const rect = button.getBoundingClientRect();
            postMenu.style.top = (rect.bottom + window.scrollY + 4) + 'px';
            postMenu.style.left = (rect.right + window.scrollX - 200) + 'px';

            postMenu.innerHTML = `
                <div class="post-actions-menu__item" data-action="copy-link" data-post-id="${postId}">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    Скопировать ссылку
                </div>
                <div class="post-actions-menu__item" data-action="report" data-post-id="${postId}">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
                    Пожаловаться
                </div>
            `;
            postMenu.classList.add('active');

            postMenu.querySelectorAll('.post-actions-menu__item').forEach(item => {
                item.addEventListener('click', function() {
                    const action = this.dataset.action;
                    const pid = this.dataset.postId;
                    if (action === 'copy-link') {
                        navigator.clipboard.writeText(`${location.origin}/post.php?id=${pid}`)
                            .then(() => kop.flash('Ссылка скопирована'))
                            .catch(() => kop.flash('Ошибка'));
                    } else if (action === 'report') {
                        showReportModal(pid, 'post');
                    }
                    hidePostMenu();
                });
            });
        }

        function showReportModal(targetId, type) {
            const existing = document.querySelector('.report-modal-overlay');
            if (existing) existing.remove();

            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay report-modal-overlay';
            overlay.style.display = 'flex';
            overlay.innerHTML = `
                <div class="modal-container" style="max-width:440px; padding:28px 24px;">
                    <span class="modal-close" style="float:right; cursor:pointer; font-size:1.5em; line-height:1;">&times;</span>
                    <h3 style="margin:0 0 12px;">Жалоба</h3>
                    <p style="color:#4b5563; margin:0 0 16px;">Опишите причину. Мы рассмотрим обращение.</p>
                    <textarea id="report-reason" placeholder="Что случилось?" style="width:100%; padding:14px; border-radius:14px; border:1px solid #e5e7eb; background:#f9fafb; font-family:inherit; min-height:110px; box-sizing:border-box; outline:none;"></textarea>
                    <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:20px;">
                        <button class="btn btn--secondary" id="report-cancel">Отмена</button>
                        <button class="btn btn--primary" id="report-submit" style="background:#3b5dd3; color:#fff;">Отправить</button>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);
            requestAnimationFrame(() => overlay.classList.add('active'));

            const close = () => {
                overlay.classList.remove('active');
                setTimeout(() => overlay.remove(), 300);
            };
            overlay.querySelector('.modal-close').addEventListener('click', close);
            overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
            overlay.querySelector('#report-cancel').addEventListener('click', close);
            overlay.querySelector('#report-submit').addEventListener('click', async () => {
                const reason = overlay.querySelector('#report-reason').value.trim();
                if (!reason) { kop.flash('Укажите причину'); return; }
                try {
                    const res = await fetch('/api/report', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('input[name="_csrf"]').value },
                        body: JSON.stringify({ target_id: targetId, type: type, reason: reason })
                    });
                    if (res.ok) kop.flash('Жалоба отправлена');
                    else { const d = await res.json(); kop.flash(d.error || 'Ошибка'); }
                } catch(e) { kop.flash('Ошибка соединения'); }
                close();
            });
        }

        // ---------- МОДАЛКА КОММЕНТАРИЕВ (исправленная) ----------
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

            const modalContainer = modal.querySelector('.modal-container');
            if (originalPost && modalContainer) {
                let postWidth = originalPost.getBoundingClientRect().width;
                const maxWidth = Math.min(window.innerWidth - 40, 480);
                if (postWidth > maxWidth) postWidth = maxWidth;
                modalContainer.style.width = postWidth + 'px';
            }

            if (originalPost) {
                const bodyClone = originalPost.querySelector('.postBody').cloneNode(true);
                postContainer.innerHTML = '';
                postContainer.appendChild(bodyClone);

                const clonedPost = postContainer.querySelector('.post');
                if (clonedPost) {
                    clonedPost.style.height = 'auto';
                    clonedPost.style.maxWidth = '100%';
                    clonedPost.style.minHeight = 'auto';
                    clonedPost.style.margin = '0';
                }

                postContainer.querySelectorAll('.carousel-container').forEach(initCarousel);

                postContainer.querySelectorAll('.carousel-slide').forEach(slide => {
                    const img = slide.querySelector('img');
                    const video = slide.querySelector('video');
                    if (img && !img.dataset.clickBound) {
                        img.dataset.clickBound = '1';
                        img.addEventListener('click', (e) => { e.stopPropagation(); openFullMedia(img.src, 'image'); });
                    }
                    if (video && !video.dataset.clickBound) {
                        video.dataset.clickBound = '1';
                        video.addEventListener('click', (e) => { e.stopPropagation(); openFullMedia(video.src, 'video'); });
                    }
                });

                postContainer.querySelectorAll('img').forEach(img => {
                    if (!img.dataset.clickBound && !img.closest('.carousel-slide')) {
                        img.dataset.clickBound = '1';
                        img.addEventListener('click', (e) => { e.stopPropagation(); openFullMedia(img.src, 'image'); });
                    }
                });
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
                            </div>`;
                    }).join('');
                } else {
                    commentsList.innerHTML = '<p class="no-comments">Нет комментариев. Будьте первым!</p>';
                }
            } catch(e) { commentsList.innerHTML = '<p class="error">Ошибка загрузки комментариев</p>'; }

            const newSendBtn = sendBtn.cloneNode(true);
            sendBtn.parentNode.replaceChild(newSendBtn, sendBtn);
            newSendBtn.onclick = async () => {
                const content = commentInput.value.trim();
                if (!content) return;
                try {
                    const resp = await kop.post(`/api/posts/${postId}/comments`, { content });
                    if (resp.success) {
                        const c = resp.comment;
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
                            </div>`;
                        if (commentsList.querySelector('.no-comments')) commentsList.innerHTML = '';
                        commentsList.insertAdjacentHTML('afterbegin', newCommentHtml);
                        commentInput.value = '';
                    }
                } catch(e) { kop.flash('Ошибка отправки комментария'); }
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
        function attachPostHandlers(container = document) {
            container.querySelectorAll('.likeButton, .dislikeButton').forEach(btn => {
                if (btn.dataset.handlerAttached) return;
                btn.dataset.handlerAttached = '1';
                btn.addEventListener('click', async function(e) {
                    e.stopPropagation();
                    const postId = this.dataset.postId;
                    const type = this.classList.contains('likeButton') ? 'like' : 'dislike';
                    const endpoint = type === 'like' ? '/api/posts/like' : '/api/posts/dislike';
                    try {
                        const resp = await kop.post(endpoint, { post_id: postId });
                        if (resp.success) {
                            const postDiv = this.closest('.post');
                            postDiv.querySelector('.positiveCounter').textContent = resp.likes_count;
                            postDiv.querySelector('.negativeCounter').textContent = resp.dislikes_count;
                            postDiv.querySelector('.likeButton').classList.toggle('active', resp.user_liked);
                            postDiv.querySelector('.dislikeButton').classList.toggle('active', resp.user_disliked);
                        }
                    } catch(e) {}
                });
            });

            container.querySelectorAll('.commentSheet').forEach(btn => {
                if (btn.dataset.commentHandlerAttached) return;
                btn.dataset.commentHandlerAttached = '1';
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    openCommentsModal(this.dataset.postId);
                });
            });

            container.querySelectorAll('.postOptions button').forEach(btn => {
                if (btn.dataset.menuAttached) return;
                btn.dataset.menuAttached = '1';
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const postDiv = this.closest('.post');
                    const postId = postDiv.dataset.postId;
                    showPostMenu(this, postId);
                });
            });
        }

        // ---------- ПОИСК ----------
        const searchArea = document.getElementById('searchArea');
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
                searchArea.classList.remove('active');
                setTimeout(() => { if (!results.classList.contains('visible')) results.innerHTML = ''; }, 250);
                return;
            }

            container.classList.add('active');
            searchArea.classList.add('active');

            debounceTimer = setTimeout(async () => {
                let html = '';
                try {
                    const [usersData, postsData] = await Promise.all([
                        kop.get(`/api/search/users?q=${encodeURIComponent(query)}`),
                        kop.get(`/api/search/posts-html?q=${encodeURIComponent(query)}`)
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
                                        ${user.avatar ? `<img src="${user.avatar}" alt="">` : `<span class="searchResultInitials">${initials}</span>`}
                                    </div>
                                    <div class="searchResultName"><span>${user.first_name} ${user.last_name}</span></div>
                                </div>`;
                        }).join('');
                        html += '</div>';
                    }

                    if (posts.length > 0) {
                        if (users.length > 0) html += '<hr class="section-divider">';
                        html += '<div class="search-results-section"><h3>Посты</h3>';
                        html += posts.map(post => post.html).join('');
                        html += '</div>';
                    }

                    if (html === '') html = '<p style="color:#8b8fa3;text-align:center;padding:20px;">Ничего не найдено</p>';

                    results.innerHTML = html;

                    document.querySelectorAll('.carousel-container').forEach(c => initCarousel(c));
                    attachPostHandlers();

                    requestAnimationFrame(() => results.classList.add('visible'));
                } catch(e) {
                    results.innerHTML = '<p style="color:#b91c1c;text-align:center;padding:20px;">Ошибка поиска</p>';
                }
            }, 300);
        });
    </script>
</body>
</html>