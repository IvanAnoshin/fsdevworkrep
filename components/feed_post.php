<?php
/**
 * Компонент поста для ленты Friendscape (полноэкранный режим)
 * 
 * Принимает:
 *   $post          – массив с данными поста
 *   $currentUserId – ID текущего пользователя
 */

if (!isset($post, $currentUserId)) return;

// Подключаем общие функции, если они ещё не определены
if (!function_exists('timeAgo')) {
    function timeAgo(string $datetime): string {
        $time = strtotime($datetime);
        if (!$time) return '';
        $diff = time() - $time;
        if ($diff < 60)       return 'только что';
        if ($diff < 3600)     return floor($diff / 60) . ' мин. назад';
        if ($diff < 86400)    return floor($diff / 3600) . ' ч. назад';
        if ($diff < 172800)   return 'вчера';
        if ($diff < 604800)   return floor($diff / 86400) . ' дн. назад';
        $months = ['янв.', 'фев.', 'мар.', 'апр.', 'мая', 'июн.', 'июл.', 'авг.', 'сен.', 'окт.', 'ноя.', 'дек.'];
        return date('j', $time) . ' ' . $months[(int)date('n', $time) - 1] . ' ' . date('Y', $time);
    }
}

if (!function_exists('renderHashtags')) {
    function renderHashtags(string $text): string {
        $escaped = esc($text);
        return preg_replace(
            '/#([\w\p{L}]+)/u',
            '<a href="search.php?q=%23$1" class="hashtag-link">#$1</a>',
            $escaped
        );
    }
}

// Реакция пользователя (если ещё не определена)
if (!isset($post['user_reaction'])) {
    $stmt = db()->prepare("SELECT reaction FROM post_reactions WHERE post_id = ? AND user_id = ?");
    $stmt->execute([$post['id'], $currentUserId]);
    $react = $stmt->fetch();
    $post['user_reaction'] = $react ? $react['reaction'] : null;
}

$isOwn = ($post['user_id'] == $currentUserId);
$fullName = esc($post['first_name'] . ' ' . $post['last_name']);
$profileUrl = $isOwn ? 'profile.php' : 'user.php?id=' . $post['user_id'];

// Время
$timeAgoStr = timeAgo($post['created_at'] ?? '');
$timeHtml = $timeAgoStr ? '<span class="post-time">' . $timeAgoStr . '</span>' : '';

// Медиа
$mediaHtml = '';
if (!empty($post['media'])) {
    $mediaHtml = '<div class="carousel-container"><div class="carousel-track">';
    foreach ($post['media'] as $media) {
        $url = esc($media['url'] ?? '');
        if (($media['type'] ?? '') === 'video') {
            $mediaHtml .= '<div class="carousel-slide"><video controls src="' . $url . '" preload="metadata"></video></div>';
        } else {
            $mediaHtml .= '<div class="carousel-slide"><img src="' . $url . '" alt=""></div>';
        }
    }
    $mediaHtml .= '</div>';
    if (count($post['media']) > 1) {
        $mediaHtml .= '
            <button class="carousel-prev"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg></button>
            <button class="carousel-next"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></button>
            <div class="carousel-dots"></div>';
    }
    $mediaHtml .= '</div>';
}

// Текст
$textHtml = '';
if (!empty($post['content'])) {
    $textHtml = '<div class="postBodyText">' . renderHashtags($post['content']) . '</div>';
}

// Аватар
$avatarHtml = '';
if (!empty($post['avatar'])) {
    $avatarHtml = '<img class="opPicture" src="' . esc($post['avatar']) . '" alt="" onerror="this.onerror=null;this.src=\'\';this.style.display=\'none\';this.nextSibling.style.display=\'flex\';">';
    $avatarHtml .= '<div class="opPicture-placeholder" style="display:none;">' . esc(mb_substr($post['first_name'], 0, 1) . mb_substr($post['last_name'], 0, 1)) . '</div>';
} else {
    $avatarHtml = '<div class="opPicture-placeholder">' . esc(mb_substr($post['first_name'], 0, 1) . mb_substr($post['last_name'], 0, 1)) . '</div>';
}

// Лайки
$isLiked = ($post['user_reaction'] === 'like');
$isDisliked = ($post['user_reaction'] === 'dislike');
$likeClass = $isLiked ? 'likeButton active' : 'likeButton';
$dislikeClass = $isDisliked ? 'dislikeButton active' : 'dislikeButton';
?>

<div class="post" data-post-id="<?= $post['id'] ?>" data-author-id="<?= $post['user_id'] ?>">
    <div class="postHeader">
        <?= $avatarHtml ?>
        <div class="opLabel">
            <a href="<?= $profileUrl ?>"><?= $fullName ?></a>
            <?= $timeHtml ?>
        </div>
        <div class="postOptions">
            <button class="post-menu-btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
            </button>
        </div>
    </div>
    <div class="postBody">
        <?= $mediaHtml ?>
        <?= $textHtml ?>
    </div>
    <div class="postFooter">
        <div class="postReactions">
            <button class="<?= $likeClass ?>" data-post-id="<?= $post['id'] ?>">
                <span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </span>
            </button>
            <p class="positiveCounter"><?= formatCount($post['likes_count']) ?></p>
            <button class="<?= $dislikeClass ?>" data-post-id="<?= $post['id'] ?>">
                <span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </span>
            </button>
            <p class="negativeCounter"><?= formatCount($post['dislikes_count']) ?></p>
        </div>
        <div class="postActions">
            <button class="commentSheet" data-post-id="<?= $post['id'] ?>">
                <span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                </span>
            </button>
            <button class="sharePost" data-post-id="<?= $post['id'] ?>">
                <span class="Menu__icon" style="background:#f0f0f0;color:#3b5dd3;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98"/><path d="M15.41 6.51l-6.82 3.98"/></svg>
                </span>
            </button>
        </div>
    </div>
</div>