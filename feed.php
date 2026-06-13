<?php
require_once __DIR__ . '/kopilot/kopilot_init.php';
require_auth();

$pageTitle = 'Лента - Friendscape';
$currentUserId = $_SESSION['user_id'];

// Получаем друзей (только подтверждённые)
$friends = select(
    "SELECT u.id FROM friendships f
     JOIN users u ON u.id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END
     WHERE (f.requester_id = ? OR f.addressee_id = ?) AND f.status = 'accepted'",
    [$currentUserId, $currentUserId, $currentUserId]
);
$friendIds = array_column($friends, 'id');

// Выборка постов: 
// - посты друзей (приватность friends или public)
// - публичные посты остальных пользователей (кроме себя)
if (empty($friendIds)) {
    $stmt = db()->prepare("
        SELECT p.*, u.first_name, u.last_name, u.avatar,
               (SELECT GROUP_CONCAT(CONCAT(pm.id, '|', pm.file_url, '|', pm.media_type) SEPARATOR ',') 
                FROM post_media pm 
                WHERE pm.post_id = p.id) AS media_list
        FROM posts p
        JOIN users u ON u.id = p.user_id
        WHERE u.privacy_posts = 'public' AND p.user_id != ?
        ORDER BY p.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$currentUserId]);
} else {
    $placeholders = implode(',', array_fill(0, count($friendIds), '?'));
    $stmt = db()->prepare("
        SELECT p.*, u.first_name, u.last_name, u.avatar,
               (SELECT GROUP_CONCAT(CONCAT(pm.id, '|', pm.file_url, '|', pm.media_type) SEPARATOR ',') 
                FROM post_media pm 
                WHERE pm.post_id = p.id) AS media_list
        FROM posts p
        JOIN users u ON u.id = p.user_id
        WHERE 
            (p.user_id IN ($placeholders) AND u.privacy_posts IN ('friends', 'public')) OR
            (u.privacy_posts = 'public' AND p.user_id != ?)
        ORDER BY p.created_at DESC
        LIMIT 10
    ");
    $params = array_merge($friendIds, [$currentUserId]);
    $stmt->execute($params);
}
$posts = $stmt->fetchAll();

// Формируем массив media и удаляем пустые посты
$filteredPosts = [];
foreach ($posts as $post) {
    $post['media'] = [];
    if (!empty($post['media_list'])) {
        $parts = explode(',', $post['media_list']);
        foreach ($parts as $part) {
            list($id, $url, $type) = explode('|', $part);
            $post['media'][] = ['id' => $id, 'url' => $url, 'type' => $type];
        }
        unset($post['media_list']);
    } elseif (!empty($post['image'])) {
        $oldType = preg_match('/\.(mp4|webm|mov)$/i', $post['image']) ? 'video' : 'image';
        $post['media'][] = ['id' => 0, 'url' => $post['image'], 'type' => $oldType];
        unset($post['image']);
    }
    
    if (empty($post['media']) && empty($post['content'])) continue;
    
    $stmt2 = db()->prepare("SELECT reaction FROM post_reactions WHERE post_id = ? AND user_id = ?");
    $stmt2->execute([$post['id'], $currentUserId]);
    $react = $stmt2->fetch();
    $post['user_reaction'] = $react ? $react['reaction'] : null;
    
    $filteredPosts[] = $post;
}
$posts = $filteredPosts;

function renderPostHTML($post, $currentUserId) {
    $fullName = htmlspecialchars($post['first_name'] . ' ' . $post['last_name']);
    $profileUrl = ($post['user_id'] == $currentUserId) ? '/profile.php' : '/user.php?id=' . $post['user_id'];
    $isLiked = ($post['user_reaction'] === 'like');
    $isDisliked = ($post['user_reaction'] === 'dislike');

    $mediaHtml = '';
    if (!empty($post['media'])) {
        $mediaHtml = '<div class="carousel-container"><div class="carousel-track">';
        foreach ($post['media'] as $media) {
            if ($media['type'] === 'video') {
                $mediaHtml .= '<div class="carousel-slide"><video controls src="' . htmlspecialchars($media['url']) . '" preload="metadata"></video></div>';
            } else {
                $mediaHtml .= '<div class="carousel-slide"><img src="' . htmlspecialchars($media['url']) . '" alt=""></div>';
            }
        }
        $mediaHtml .= '</div>';
        if (count($post['media']) > 1) {
            $mediaHtml .= '
                <button class="carousel-prev">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="15 18 9 12 15 6"/>
                    </svg>
                </button>
                <button class="carousel-next">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                </button>
                <div class="carousel-dots"></div>';
        }
        $mediaHtml .= '</div>';
    }
    $textHtml = !empty($post['content']) ? '<div class="postBodyText">' . htmlspecialchars($post['content']) . '</div>' : '';

    $avatarHtml = '';
    if (!empty($post['avatar'])) {
        $avatarHtml = '<img class="opPicture" src="' . htmlspecialchars($post['avatar']) . '" alt="" onerror="this.onerror=null;this.src=\'\';this.style.display=\'none\';this.nextSibling.style.display=\'flex\';">';
        $avatarHtml .= '<div class="opPicture-placeholder" style="display:none;">' . htmlspecialchars(mb_substr($post['first_name'], 0, 1) . mb_substr($post['last_name'], 0, 1)) . '</div>';
    } else {
        $avatarHtml = '<div class="opPicture-placeholder">' . htmlspecialchars(mb_substr($post['first_name'], 0, 1) . mb_substr($post['last_name'], 0, 1)) . '</div>';
    }

    $likeBtnClass = $isLiked ? 'likeButton active' : 'likeButton';
    $dislikeBtnClass = $isDisliked ? 'dislikeButton active' : 'dislikeButton';

    return <<<HTML
    <div class="post" data-post-id="{$post['id']}" data-author-id="{$post['user_id']}">
        <div class="postHeader">
            {$avatarHtml}
            <div class="opLabel">
                <a href="{$profileUrl}">{$fullName}</a>
            </div>
            <div class="postOptions">
                <button class="post-menu-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                </button>
            </div>
        </div>
        <div class="postBody">
            {$mediaHtml}
            {$textHtml}
        </div>
        <div class="postFooter">
            <div class="postReactions">
                <button class="{$likeBtnClass}" data-post-id="{$post['id']}">
                    <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                    </span>
                </button>
                <p class="positiveCounter">{$post['likes_count']}</p>
                <button class="{$dislikeBtnClass}" data-post-id="{$post['id']}">
                    <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                    </span>
                </button>
                <p class="negativeCounter">{$post['dislikes_count']}</p>
            </div>
            <div class="postActions">
                <button class="commentSheet" data-post-id="{$post['id']}">
                    <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                        </svg>
                    </span>
                </button>
                <button class="sharePost" data-post-id="{$post['id']}">
                    <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98"/><path d="M15.41 6.51l-6.82 3.98"/>
                        </svg>
                    </span>
                </button>
            </div>
        </div>
    </div>
HTML;
}

$initialPostsHTML = '';
foreach ($posts as $post) {
    $initialPostsHTML .= renderPostHTML($post, $currentUserId);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        /* Основные стили (лента, карусель, кнопки) */
        .mainArea {
            margin-left: 20%;
            height: 100vh;
            overflow-y: auto;
            scroll-snap-type: y mandatory;
            background: #f0f0f0;
            scrollbar-width: none;
        }
        .feed-container { display: flex; flex-direction: column; }
        .post {
            scroll-snap-align: start;
            min-height: 100vh;
            width: 100%;
            margin: 0;
            border-radius: 0;
            box-shadow: none;
            background: #fff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            border-bottom: 2px solid #eef1f8;
        }
        .carousel-container {
            position: relative;
            width: 100%;
            aspect-ratio: 1 / 1;
            overflow: hidden;
            background: #000;
            margin-bottom: 8px;
        }
        .carousel-slide img,
        .carousel-slide video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            cursor: pointer;
        }
        .carousel-prev,
        .carousel-next {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0,0,0,0.6);
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .carousel-container:hover .carousel-prev,
        .carousel-container:hover .carousel-next {
            opacity: 1;
        }
        .carousel-prev { left: 10px; }
        .carousel-next { right: 10px; }
        .carousel-prev svg,
        .carousel-next svg {
            width: 24px;
            height: 24px;
            stroke: white;
            stroke-width: 2;
            fill: none;
        }
        .carousel-dots {
            position: absolute;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 8px;
            z-index: 2;
        }
        .carousel-dot {
            width: 8px;
            height: 8px;
            background: rgba(255,255,255,0.5);
            border-radius: 50%;
            border: none;
            cursor: pointer;
        }
        .carousel-dot.active { background: white; }
        @media (max-width: 768px) { .mainArea { margin-left: 70px; } }
        .loader { text-align: center; padding: 20px; color: #8b8fa3; }
        /* Стили для комментариев */
        .comments-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-bottom: 20px;
            padding: 0 20px;
        }
        .comment-item {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        .comment-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .comment-content {
            flex: 1;
            background: #f9fafb;
            border-radius: 12px;
            padding: 8px 12px;
        }
        .comment-author a {
            font-weight: 600;
            color: #3b5dd3;
            text-decoration: none;
        }
        .comment-text { color: #333; font-size: 0.95em; margin-top: 4px; }
        .comment-date { font-size: 0.75em; color: #888; margin-top: 4px; }
        .comment-form {
            display: flex;
            gap: 8px;
            align-items: center;
            padding: 0 20px 20px;
        }
        .comment-form textarea {
            flex: 1;
            resize: none;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            padding: 8px 14px;
        }
        .no-comments, .error { text-align: center; color: #888; padding: 20px; }
        
        /* Меню действий */
        .post-actions-menu {
            position: absolute;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            min-width: 200px;
            z-index: 100;
            overflow: hidden;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-8px);
            transition: opacity 0.2s, visibility 0.2s, transform 0.2s;
        }
        .post-actions-menu.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        .post-actions-menu__item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            font-size: 0.95em;
            color: #1e1e2f;
            cursor: pointer;
            transition: background 0.15s;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
        }
        .post-actions-menu__item:hover {
            background: #f5f6fa;
        }
        .post-actions-menu__item--danger {
            color: #b91c1c;
        }
    </style>
</head>
<body>
    <div class="sidebar"><?php require_once "components/header.php"; ?></div>
    <div class="mainArea" id="mainArea">
        <div class="feed-container" id="feed-container">
            <?= $initialPostsHTML ?>
        </div>
        <div id="loader" class="loader" style="display: none;">Загрузка...</div>
    </div>

    <!-- Модальные окна -->
    <div class="modal-overlay" id="comments-modal" style="display: none;">
        <div class="modal-container">
            <span class="modal-close" id="modal-close">&times;</span>
            <div id="modal-post-container"></div>
            <div class="comments-block">
                <div class="comment-form">
                    <textarea id="comment-input" placeholder="Написать комментарий..."></textarea>
                    <button id="comment-send-btn">
                        <span class="Menu__icon" style="background: #f0f0f0; color: #3b5dd3;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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

    <div class="modal-overlay" id="share-modal" style="display: none;">
        <div class="modal-container" style="padding: 20px; max-width: 400px;">
            <span class="modal-close" id="share-modal-close">&times;</span>
            <h3 style="margin:0 0 16px;">Отправить пост</h3>
            <div id="share-chat-list" style="max-height: 300px; overflow-y: auto;"></div>
        </div>
    </div>

    <div id="post-actions-menu" class="post-actions-menu"></div>

    <script src="/kopilot/js/kopilot.js"></script>
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <script>
        // ---------- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ----------
        function esc(str) {
            if (str == null) return '';
            return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
        }

        // ---------- КАРУСЕЛЬ ----------
        function initCarousel(container) {
            const track = container.querySelector('.carousel-track');
            const slides = track ? Array.from(track.children) : [];
            if (slides.length <= 1) return;
            const prevBtn = container.querySelector('.carousel-prev');
            const nextBtn = container.querySelector('.carousel-next');
            const dotsNav = container.querySelector('.carousel-dots');
            let currentIndex = 0;
            function updateCarousel() {
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
                updateCarousel();
            }
            if (prevBtn) prevBtn.addEventListener('click', () => goToSlide(currentIndex - 1));
            if (nextBtn) nextBtn.addEventListener('click', () => goToSlide(currentIndex + 1));
            let touchStartX = 0;
            container.addEventListener('touchstart', e => touchStartX = e.changedTouches[0].screenX);
            container.addEventListener('touchend', e => {
                const diff = e.changedTouches[0].screenX - touchStartX;
                if (Math.abs(diff) > 50) diff > 0 ? goToSlide(currentIndex - 1) : goToSlide(currentIndex + 1);
            });
            if (dotsNav && dotsNav.children.length === 0 && slides.length > 1) {
                for (let i = 0; i < slides.length; i++) {
                    const dot = document.createElement('button');
                    dot.classList.add('carousel-dot');
                    if (i === currentIndex) dot.classList.add('active');
                    dot.addEventListener('click', () => goToSlide(i));
                    dotsNav.appendChild(dot);
                }
            }
            window.addEventListener('resize', updateCarousel);
            updateCarousel();
        }

        // ---------- ПОЛНОЭКРАННЫЙ ПРОСМОТР ----------
        function openFullMedia(src, type) {
            const overlay = document.createElement('div');
            overlay.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0); z-index:10001; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:background 0.3s ease;';
            const closeBtn = document.createElement('div');
            closeBtn.innerHTML = '&times;';
            closeBtn.style.cssText = 'position:absolute; top:20px; right:30px; font-size:40px; color:#fff; cursor:pointer; font-family:sans-serif; z-index:10002; opacity:0; transition:opacity 0.2s ease 0.1s;';
            let media;
            if (type === 'image') {
                media = document.createElement('img');
                media.src = src;
                media.style.cssText = 'max-width:90%; max-height:90%; object-fit:contain; opacity:0; transform:scale(0.9); transition:opacity 0.3s ease, transform 0.3s ease;';
            } else {
                media = document.createElement('video');
                media.src = src;
                media.controls = true;
                media.autoplay = true;
                media.style.cssText = 'max-width:90%; max-height:90%; opacity:0; transform:scale(0.9); transition:opacity 0.3s ease, transform 0.3s ease;';
            }
            overlay.appendChild(media);
            overlay.appendChild(closeBtn);
            document.body.appendChild(overlay);
            requestAnimationFrame(() => {
                overlay.style.background = 'rgba(0,0,0,0.95)';
                media.style.opacity = '1';
                media.style.transform = 'scale(1)';
                closeBtn.style.opacity = '1';
            });
            const remove = () => {
                overlay.style.background = 'rgba(0,0,0,0)';
                media.style.opacity = '0';
                media.style.transform = 'scale(0.9)';
                closeBtn.style.opacity = '0';
                setTimeout(() => overlay.remove(), 300);
            };
            closeBtn.onclick = remove;
            overlay.onclick = e => { if (e.target === overlay) remove(); };
        }

        function bindMediaClicks() {
            document.querySelectorAll('.carousel-slide').forEach(slide => {
                const img = slide.querySelector('img');
                const video = slide.querySelector('video');
                if (img && !img.dataset.clickBound) {
                    img.dataset.clickBound = '1';
                    img.addEventListener('click', e => { e.stopPropagation(); openFullMedia(img.src, 'image'); });
                }
                if (video && !video.dataset.clickBound) {
                    video.dataset.clickBound = '1';
                    video.addEventListener('click', e => { e.stopPropagation(); openFullMedia(video.src, 'video'); });
                }
            });
        }

        // ---------- РЕАКЦИИ ----------
        function attachReactionHandlers() {
            document.querySelectorAll('.likeButton, .dislikeButton').forEach(btn => {
                if (btn.dataset.handlerAttached) return;
                btn.dataset.handlerAttached = '1';
                btn.addEventListener('click', async function() {
                    const postId = this.dataset.postId;
                    const type = this.classList.contains('likeButton') ? 'like' : 'dislike';
                    const endpoint = type === 'like' ? '/api/posts/like' : '/api/posts/dislike';
                    const res = await kop.post(endpoint, { post_id: postId });
                    if (res.success) {
                        const postDiv = this.closest('.post');
                        postDiv.querySelector('.positiveCounter').textContent = res.likes_count;
                        postDiv.querySelector('.negativeCounter').textContent = res.dislikes_count;
                        const likeBtn = postDiv.querySelector('.likeButton');
                        const dislikeBtn = postDiv.querySelector('.dislikeButton');
                        likeBtn.classList.remove('active');
                        dislikeBtn.classList.remove('active');
                        if (res.user_liked) likeBtn.classList.add('active');
                        if (res.user_disliked) dislikeBtn.classList.add('active');
                    }
                });
            });
        }

        // ---------- КОММЕНТАРИИ ----------
        function closeCommentsModal() {
            const modal = document.getElementById('comments-modal');
            if (!modal) return;
            modal.classList.remove('active');
            setTimeout(() => modal.style.display = 'none', 300);
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
            } else {
                postContainer.innerHTML = '<p>Пост не найден</p>';
            }

            try {
                const data = await kop.get(`/api/posts/${postId}/comments`);
                if (data.comments && data.comments.length) {
                    commentsList.innerHTML = data.comments.map(c => {
                        const initials = (c.first_name?.charAt(0)||'')+(c.last_name?.charAt(0)||'');
                        const avatarHtml = c.avatar
                            ? `<img src="${esc(c.avatar)}" alt="">`
                            : `<span class="comment-avatar-placeholder">${esc(initials)}</span>`;
                        return `
                            <div class="comment-item">
                                <div class="comment-avatar">${avatarHtml}</div>
                                <div class="comment-content">
                                    <div class="comment-author"><a href="user.php?id=${c.user_id}">${esc(c.first_name)} ${esc(c.last_name)}</a></div>
                                    <div class="comment-text">${esc(c.content)}</div>
                                    <div class="comment-date">${new Date(c.created_at).toLocaleString()}</div>
                                </div>
                            </div>
                        `;
                    }).join('');
                } else {
                    commentsList.innerHTML = '<p class="no-comments">Нет комментариев</p>';
                }
            } catch(e) {
                commentsList.innerHTML = '<p class="error">Ошибка загрузки</p>';
            }

            const newSendBtn = sendBtn.cloneNode(true);
            sendBtn.parentNode.replaceChild(newSendBtn, sendBtn);
            newSendBtn.onclick = async () => {
                const content = commentInput.value.trim();
                if (!content) return;
                const response = await kop.post(`/api/posts/${postId}/comments`, { content });
                if (response.success) {
                    const c = response.comment;
                    const initials = (c.first_name?.charAt(0)||'')+(c.last_name?.charAt(0)||'');
                    const avatarHtml = c.avatar
                        ? `<img src="${esc(c.avatar)}" alt="">`
                        : `<span class="comment-avatar-placeholder">${esc(initials)}</span>`;
                    const newComment = `
                        <div class="comment-item">
                            <div class="comment-avatar">${avatarHtml}</div>
                            <div class="comment-content">
                                <div class="comment-author"><a href="user.php?id=${c.user_id}">${esc(c.first_name)} ${esc(c.last_name)}</a></div>
                                <div class="comment-text">${esc(c.content)}</div>
                                <div class="comment-date">${new Date(c.created_at).toLocaleString()}</div>
                            </div>
                        </div>
                    `;
                    if (commentsList.querySelector('.no-comments')) commentsList.innerHTML = '';
                    commentsList.insertAdjacentHTML('afterbegin', newComment);
                    commentInput.value = '';
                }
            };

            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('active'), 10);
            document.body.classList.add('no-scroll');

            const closeBtn = document.getElementById('modal-close');
            if (closeBtn) {
                const newCloseBtn = closeBtn.cloneNode(true);
                closeBtn.parentNode.replaceChild(newCloseBtn, closeBtn);
                newCloseBtn.onclick = closeCommentsModal;
            }
            modal.onclick = (e) => { if (e.target === modal) closeCommentsModal(); };
        }

        function attachCommentHandler() {
            document.querySelectorAll('.commentSheet').forEach(btn => {
                if (btn.dataset.commentHandlerAttached) return;
                btn.dataset.commentHandlerAttached = '1';
                btn.addEventListener('click', () => openCommentsModal(btn.dataset.postId));
            });
        }

        // ---------- ПОДЕЛИТЬСЯ ----------
        async function openShareModal(postId) {
            const modal = document.getElementById('share-modal');
            const chatList = document.getElementById('share-chat-list');
            let chats = [];
            try { const data = await kop.get('/api/chats'); chats = data.chats || []; } catch(e) {}
            if (!chats.length) chatList.innerHTML = '<p style="color:#8b8fa3;text-align:center;padding:20px;">Нет активных чатов</p>';
            else {
                chatList.innerHTML = chats.map(chat => `
                    <div class="share-chat-item" data-other-user="${chat.other_user_id}" style="display:flex;align-items:center;gap:12px;padding:12px;cursor:pointer;border-radius:12px;">
                        <img src="${chat.avatar || ''}" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">
                        <span>${esc(chat.first_name)} ${esc(chat.last_name)}</span>
                    </div>
                `).join('');
                chatList.querySelectorAll('.share-chat-item').forEach(item => {
                    item.addEventListener('click', async () => {
                        await kop.post('/api/messages/send', { receiver_id: item.dataset.otherUser, content: `${location.origin}/post.php?id=${postId}` });
                        kop.flash('Пост отправлен');
                        modal.classList.remove('active');
                        modal.style.display = 'none';
                    });
                });
            }
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('active'), 10);
        }
        function attachShareButtons() {
            document.querySelectorAll('.sharePost').forEach(btn => {
                if (btn.dataset.shareAttached) return;
                btn.dataset.shareAttached = '1';
                btn.addEventListener('click', e => { e.stopPropagation(); openShareModal(btn.dataset.postId); });
            });
        }

        // ---------- МЕНЮ ПОСТА (ФИНАЛЬНОЕ, РАБОЧЕЕ, С ИСПРАВЛЕННОЙ ПЕРЕМЕННОЙ) ----------
        const postMenu = document.getElementById('post-actions-menu');
        function hidePostMenu() { if (postMenu) postMenu.classList.remove('active'); }

        // Скрытие при скролле и клике вне
        function hideMenuOnScroll() { hidePostMenu(); }
        window.addEventListener('scroll', hideMenuOnScroll);
        const mainAreaElem = document.getElementById('mainArea');
        if (mainAreaElem) mainAreaElem.addEventListener('scroll', hideMenuOnScroll);
        document.addEventListener('click', (e) => {
            if (postMenu && !postMenu.contains(e.target) && !e.target.closest('.post-menu-btn')) hidePostMenu();
        });

        function showPostMenu(button, postId, authorId) {
            const rect = button.getBoundingClientRect();
            postMenu.style.top = (rect.bottom + window.scrollY + 4) + 'px';
            postMenu.style.left = (rect.right + window.scrollX - 200) + 'px';
            // ИСПРАВЛЕНО: используем currentUserIdVal, а не currentUserId
            const isOwn = (authorId == currentUserIdVal);
            let html = `<div class="post-actions-menu__item" data-action="copy-link" data-post-id="${postId}"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> Скопировать ссылку</div>`;
            if (!isOwn) {
                html += `<div class="post-actions-menu__item" data-action="hide" data-post-id="${postId}"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg> Скрыть</div>`;
                html += `<div class="post-actions-menu__item" data-action="report" data-post-id="${postId}"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg> Пожаловаться</div>`;
            }
            postMenu.innerHTML = html;
            postMenu.classList.add('active');
        }

        // Делегирование кликов на кнопки с тремя точками
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.post-menu-btn');
            if (!btn) return;
            e.stopPropagation();
            const postDiv = btn.closest('.post');
            if (!postDiv) return;
            const postId = postDiv.dataset.postId;
            const authorId = postDiv.dataset.authorId;
            showPostMenu(btn, postId, authorId);
        });

        // Обработка пунктов меню (тоже через делегирование)
        document.addEventListener('click', (e) => {
            const item = e.target.closest('.post-actions-menu__item');
            if (!item) return;
            e.stopPropagation();
            const action = item.dataset.action;
            const postId = item.dataset.postId;
            if (action === 'copy-link') {
                navigator.clipboard.writeText(`${location.origin}/post.php?id=${postId}`).then(() => kop.flash('Ссылка скопирована')).catch(() => kop.flash('Ошибка копирования'));
            } else if (action === 'hide') {
                kop.post(`/api/posts/${postId}/hide`, {}).then(() => {
                    document.querySelector(`.post[data-post-id="${postId}"]`)?.remove();
                    kop.flash('Пост скрыт');
                }).catch(() => kop.flash('Ошибка'));
            } else if (action === 'report') {
                kop.flash('Жалоба отправлена');
            }
            hidePostMenu();
        });

        // ---------- БЕСКОНЕЧНАЯ ПОДГРУЗКА (с фильтрацией своих постов) ----------
        const currentUserIdVal = <?= json_encode($currentUserId) ?>;
        let currentPage = 2;
        let isLoading = false;
        let hasMore = true;
        const feedContainer = document.getElementById('feed-container');
        const loader = document.getElementById('loader');
        const mainAreaScroll = document.getElementById('mainArea');
        const csrfToken = document.querySelector('input[name="_csrf"]').value;

        function renderPostHTMLClient(post) {
            const fullName = esc(post.first_name) + ' ' + esc(post.last_name);
            const profileUrl = (post.user_id == currentUserIdVal) ? '/profile.php' : '/user.php?id=' + post.user_id;
            const isLiked = (post.user_reaction === 'like');
            const isDisliked = (post.user_reaction === 'dislike');
            let mediaHtml = '';
            if (post.media && post.media.length) {
                mediaHtml = '<div class="carousel-container"><div class="carousel-track">';
                for (const media of post.media) {
                    if (media.type === 'video') {
                        mediaHtml += `<div class="carousel-slide"><video controls src="${esc(media.url)}" preload="metadata"></video></div>`;
                    } else {
                        mediaHtml += `<div class="carousel-slide"><img src="${esc(media.url)}" alt=""></div>`;
                    }
                }
                mediaHtml += '</div>';
                if (post.media.length > 1) {
                    mediaHtml += `<button class="carousel-prev"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg></button>
                                 <button class="carousel-next"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></button>
                                 <div class="carousel-dots"></div>`;
                }
                mediaHtml += '</div>';
            }
            const textHtml = post.content ? `<div class="postBodyText">${esc(post.content)}</div>` : '';
            let avatarHtml = '';
            if (post.avatar) {
                avatarHtml = `<img class="opPicture" src="${esc(post.avatar)}" alt="" onerror="this.onerror=null;this.src='';this.style.display='none';this.nextSibling.style.display='flex';">`;
                avatarHtml += `<div class="opPicture-placeholder" style="display:none;">${esc((post.first_name?.charAt(0)||'')+(post.last_name?.charAt(0)||''))}</div>`;
            } else {
                avatarHtml = `<div class="opPicture-placeholder">${esc((post.first_name?.charAt(0)||'')+(post.last_name?.charAt(0)||''))}</div>`;
            }
            const likeBtnClass = isLiked ? 'likeButton active' : 'likeButton';
            const dislikeBtnClass = isDisliked ? 'dislikeButton active' : 'dislikeButton';
            return `
                <div class="post" data-post-id="${post.id}" data-author-id="${post.user_id}">
                    <div class="postHeader">
                        ${avatarHtml}
                        <div class="opLabel"><a href="${profileUrl}">${fullName}</a></div>
                        <div class="postOptions">
                            <button class="post-menu-btn">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="postBody">${mediaHtml}${textHtml}</div>
                    <div class="postFooter">
                        <div class="postReactions">
                            <button class="${likeBtnClass}" data-post-id="${post.id}"><span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></span></button>
                            <p class="positiveCounter">${post.likes_count}</p>
                            <button class="${dislikeBtnClass}" data-post-id="${post.id}"><span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg></span></button>
                            <p class="negativeCounter">${post.dislikes_count}</p>
                        </div>
                        <div class="postActions">
                            <button class="commentSheet" data-post-id="${post.id}"><span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></span></button>
                            <button class="sharePost" data-post-id="${post.id}"><span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98"/><path d="M15.41 6.51l-6.82 3.98"/></svg></span></button>
                        </div>
                    </div>
                </div>
            `;
        }

        async function loadMorePosts() {
            if (isLoading || !hasMore) return;
            isLoading = true;
            loader.style.display = 'block';
            try {
                const response = await fetch(`/api/feed/posts?page=${currentPage}&limit=10`, {
                    headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' }
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();
                let posts = data.posts || [];
                posts = posts.filter(post => post.user_id != currentUserIdVal);
                if (posts.length) {
                    let html = '';
                    for (const post of posts) {
                        html += renderPostHTMLClient(post);
                    }
                    feedContainer.insertAdjacentHTML('beforeend', html);
                    hasMore = data.has_more === true;
                    currentPage++;
                    document.querySelectorAll('.carousel-container').forEach(initCarousel);
                    bindMediaClicks();
                    attachReactionHandlers();
                    attachCommentHandler();
                    attachShareButtons();
                } else {
                    if (data.has_more === false || posts.length === 0) hasMore = false;
                }
            } catch(e) {
                console.error('Ошибка подгрузки', e);
                kop.flash('Не удалось загрузить посты');
            } finally {
                isLoading = false;
                loader.style.display = 'none';
            }
        }

        function initInfiniteScroll() {
            let scrollTimeout;
            mainAreaScroll.addEventListener('scroll', () => {
                clearTimeout(scrollTimeout);
                scrollTimeout = setTimeout(() => {
                    if (mainAreaScroll.scrollTop + mainAreaScroll.clientHeight >= mainAreaScroll.scrollHeight - 300) loadMorePosts();
                }, 200);
            });
        }

        // Запуск
        document.querySelectorAll('.carousel-container').forEach(initCarousel);
        bindMediaClicks();
        attachReactionHandlers();
        attachCommentHandler();
        attachShareButtons();
        initInfiniteScroll();
    </script>
</body>
</html>