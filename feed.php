<?php
$pageTitle = 'Лента - Friendscape';

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="css/main.css">
</head>
<body>
    <div id="container">
        <div class="sidebar"><?php require_once "components/header.php"; ?></div>
        <div class="mainArea">
            <div class="moments">
                <div class="momentItem"></div>
                <div class="momentItem"></div>
                <div class="momentItem"></div>
                <div class="momentItem"></div>
            </div>

                    <div class="post">
                        <div class="postHeader">
                            <!-- Аватар теперь img -->
                            <img class="opPicture" src="avatar.jpg" alt="Аватар Ивана Иванова">
                            <div class="opLabel">
                                <a href="">Иван Иванов</a>
                            </div>
                            <div class="postOptions">
                                <button>
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                                </button>
                            </div>
                        </div>
                        <!-- Тело поста теперь img -->
                        <img class="postBody" src="post-image.jpg" alt="Содержимое поста">
                        <div class="postFooter">
                            <div class="postReactions">
                                <button class="likeButton">
                                    <span class="Menu__icon" style="background: #d1fae5; color: #059669;">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="12" y1="5" x2="12" y2="19"/>
                                            <line x1="5" y1="12" x2="19" y2="12"/>
                                        </svg>
                                    </span>
                                </button>
                                <p class="positiveCounter">1</p>
                                <button class="dislikeButton">
                                    <span class="Menu__icon" style="background: #fee2e2; color: #b91c1c;">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="5" y1="12" x2="19" y2="12"/>
                                        </svg>
                                    </span>
                                </button>
                                <p class="negativeCounter">1</p>
                            </div>
                            <div class="postActions">
                                <button class="commentSheet">
                                    <span class="Menu__icon" style="background: #e8e0fc; color: #7c3aed;">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                                        </svg>
                                    </span>
                                </button>
                                <button class="sharePost">
                                    <span class="Menu__icon" style="background: #fce7f3; color: #db2777;">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="18" cy="5" r="3"/>
                                            <circle cx="6" cy="12" r="3"/>
                                            <circle cx="18" cy="19" r="3"/>
                                            <path d="M8.59 13.51l6.83 3.98"/>
                                            <path d="M15.41 6.51l-6.82 3.98"/>
                                        </svg>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="post">
                        <div class="postHeader">
                            <!-- Аватар теперь img -->
                            <img class="opPicture" src="avatar.jpg" alt="Аватар Ивана Иванова">
                            <div class="opLabel">
                                <a href="">Иван Иванов</a>
                            </div>
                            <div class="postOptions">
                                <button>
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                                </button>
                            </div>
                        </div>
                        <!-- Тело поста теперь img -->
                        <img class="postBody" src="post-image.jpg" alt="Содержимое поста">
                        <div class="postFooter">
                            <div class="postReactions">
                                <button class="likeButton">
                                    <span class="Menu__icon" style="background: #d1fae5; color: #059669;">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="12" y1="5" x2="12" y2="19"/>
                                            <line x1="5" y1="12" x2="19" y2="12"/>
                                        </svg>
                                    </span>
                                </button>
                                <p class="positiveCounter">1</p>
                                <button class="dislikeButton">
                                    <span class="Menu__icon" style="background: #fee2e2; color: #b91c1c;">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="5" y1="12" x2="19" y2="12"/>
                                        </svg>
                                    </span>
                                </button>
                                <p class="negativeCounter">1</p>
                            </div>
                            <div class="postActions">
                                <button class="commentSheet">
                                    <span class="Menu__icon" style="background: #e8e0fc; color: #7c3aed;">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                                        </svg>
                                    </span>
                                </button>
                                <button class="sharePost">
                                    <span class="Menu__icon" style="background: #fce7f3; color: #db2777;">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="18" cy="5" r="3"/>
                                            <circle cx="6" cy="12" r="3"/>
                                            <circle cx="18" cy="19" r="3"/>
                                            <path d="M8.59 13.51l6.83 3.98"/>
                                            <path d="M15.41 6.51l-6.82 3.98"/>
                                        </svg>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>                    

        </div>
    </div>    
</body>
</html>