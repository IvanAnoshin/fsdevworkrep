<?php
/**
 * DFSN – Digital Fortress Social Network
 * Ядро алгоритма v5.2.4 (исправлено сохранение поручительств)
 * 
 * - Прямая вставка без транзакции для гарантированного сохранения
 * - Все проверки (лимиты, активность) выполняются до вставки
 * - Защита от дубликатов через уникальный индекс
 * - Обновление весов получателя
 */

// ----------------------------------------------------------
// 1. КОНСТАНТЫ
// ----------------------------------------------------------
define('W_BASE',               1.0);
define('ENDORSEMENT_K',        0.05);
define('MAX_COMPLAINTS_PER_DAY', 5);
define('ANOMALY_THRESHOLD_BASE', 3.0);
define('MIN_SAMPLES_FOR_CHECK', 20);
define('ALPHA_TRUST',          0.4);
define('BETA_INTEREST',        0.3);
define('GAMMA_QUALITY',        0.2);
define('DELTA_RECENCY',        0.1);
define('EXPLORATION_RATE',     0.1);
define('PENALTY_DECAY_HALF',   7 * 24 * 3600);
define('VECTOR_DIMENSION',     50);
define('REC_CACHE_TTL_PEOPLE', 3600);
define('REC_CACHE_TTL_CONTENT', 60);
define('MAX_ENDORSEMENTS_PER_DAY', 10);
define('MAX_ACTIVE_ENDORSEMENTS',  200);
define('COMPLAINT_MAX_AGE',    60 * 24 * 3600);
define('MIN_ACTIVITY_FOR_ENDORSE', 0.6);
define('DFSN_LOGGING_ENABLED', true);
define('DFSN_DATA_COLLECTION_ENABLED', true);
define('DFSN_MODEL_DUMP_ENABLED', true);
define('DFSN_DATASET_MAX_ROWS', 500000);
define('DFSN_DUMP_RETENTION_DAYS', 7);

// ----------------------------------------------------------
// 2. ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ----------------------------------------------------------
function cosineSimilarity(array $a, array $b): float {
    $dot = 0.0; $normA = 0.0; $normB = 0.0;
    foreach ($a as $i => $va) {
        $vb = $b[$i] ?? 0;
        $dot += $va * $vb;
        $normA += $va * $va;
        $normB += $vb * $vb;
    }
    if ($normA == 0 || $normB == 0) return 0.0;
    return $dot / (sqrt($normA) * sqrt($normB));
}

function sigmoid(float $x): float {
    return 1.0 / (1.0 + exp(-$x));
}

function decayFactor(int $penaltyTimestamp, ?int $now = null): float {
    $now = $now ?? time();
    $elapsed = $now - $penaltyTimestamp;
    return exp(-$elapsed / PENALTY_DECAY_HALF * log(2));
}

function tokenize(string $text): array {
    $text = mb_strtolower(strip_tags($text));
    preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);
    return $matches[0] ?? [];
}

// ----------------------------------------------------------
// 3. УСТАНОВКА ТАБЛИЦ (с индексами и уникальным ключом)
// ----------------------------------------------------------
function dfsn_install_tables(): void {
    $db = db();
    $db->exec("
        CREATE TABLE IF NOT EXISTS dfsn_weights (
            user_id INT PRIMARY KEY,
            w_trust FLOAT NOT NULL DEFAULT 1.0,
            w_activity FLOAT NOT NULL DEFAULT 1.0,
            w_expert FLOAT NOT NULL DEFAULT 1.0,
            endorsement_sum FLOAT NOT NULL DEFAULT 0.0,
            complaint_penalty FLOAT NOT NULL DEFAULT 0.0,
            updated_at INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB;

        CREATE TABLE IF NOT EXISTS dfsn_endorsements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_user_id INT NOT NULL,
            to_user_id INT NOT NULL,
            coefficient FLOAT NOT NULL DEFAULT 0.05,
            created_at INT NOT NULL,
            FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_pair (from_user_id, to_user_id),
            INDEX idx_to_user (to_user_id),
            INDEX idx_from_user (from_user_id)
        ) ENGINE=InnoDB;

        CREATE TABLE IF NOT EXISTS dfsn_complaints (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_user_id INT NOT NULL,
            to_user_id INT NOT NULL,
            weight FLOAT NOT NULL DEFAULT 0.1,
            created_at INT NOT NULL,
            FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_to_user (to_user_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB;

        CREATE TABLE IF NOT EXISTS dfsn_interest_vectors (
            user_id INT PRIMARY KEY,
            vector JSON NOT NULL,
            updated_at INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB;

        CREATE TABLE IF NOT EXISTS dfsn_behavior_profiles (
            user_id INT PRIMARY KEY,
            profile_data JSON NOT NULL,
            sample_count INT NOT NULL DEFAULT 0,
            updated_at INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB;

        CREATE TABLE IF NOT EXISTS dfsn_recommendations_cache (
            user_id INT NOT NULL,
            type ENUM('people','content') NOT NULL,
            items JSON NOT NULL,
            created_at INT NOT NULL,
            PRIMARY KEY (user_id, type),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB;

        CREATE TABLE IF NOT EXISTS post_metrics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            read_time FLOAT NOT NULL DEFAULT 0,
            author_id INT NOT NULL,
            created_at INT NOT NULL DEFAULT (UNIX_TIMESTAMP()),
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
            INDEX idx_author (author_id),
            INDEX idx_post (post_id)
        ) ENGINE=InnoDB;

        CREATE TABLE IF NOT EXISTS user_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            login_time INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_time (user_id, login_time)
        ) ENGINE=InnoDB;

        CREATE TABLE IF NOT EXISTS dfsn_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL COMMENT 'чей аккаунт затронут',
            event_type VARCHAR(32) NOT NULL COMMENT 'тип события',
            context JSON NULL COMMENT 'дополнительные данные',
            created_at INT NOT NULL,
            INDEX idx_user (user_id),
            INDEX idx_event (event_type),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB;

        -- Таблица для сбора образцов датасета
        CREATE TABLE IF NOT EXISTS dfsn_dataset (
            id INT AUTO_INCREMENT PRIMARY KEY,
            features JSON NOT NULL COMMENT 'вектор признаков (обезличенный)',
            label VARCHAR(32) NULL COMMENT 'метка (trusted, suspicious, anomaly, high_quality, etc.)',
            source_type VARCHAR(32) NOT NULL COMMENT 'behaviour, content, social',
            created_at INT NOT NULL,
            INDEX idx_label (label),
            INDEX idx_source (source_type),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB;

        -- Таблица для дампов модели
        CREATE TABLE IF NOT EXISTS dfsn_model_dumps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dump_data JSON NOT NULL COMMENT 'полный дамп состояния модели',
            created_at INT NOT NULL,
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB;
    ");

    // Дополнительные индексы для внешних таблиц
    try {
        $db->exec("CREATE INDEX idx_posts_created ON posts(created_at)");
    } catch (\PDOException $e) {
        // индекс уже существует – игнорируем
    }
}

// ----------------------------------------------------------
// 4. ОСНОВНОЙ КЛАСС DFSN
// ----------------------------------------------------------
class DFSN {

    private function guardValidUser(int $userId): void {
        if (!find('users', $userId)) {
            throw new \InvalidArgumentException("User not found: $userId");
        }
    }

    // ==================== ЛОГГЕР ====================
    private function logEvent(int $userId, string $eventType, array $context = []): void {
        if (!DFSN_LOGGING_ENABLED) return;
        try {
            db()->prepare("INSERT INTO dfsn_log (user_id, event_type, context, created_at) VALUES (?, ?, ?, ?)")
                ->execute([$userId, $eventType, json_encode($context), time()]);
        } catch (\Throwable $e) {
            error_log("DFSN log error: " . $e->getMessage());
        }
    }

    // ==================== СБОР ДАННЫХ ДЛЯ ДАТАСЕТА ====================
    private function collectSample(array $features, string $sourceType, ?string $label = null): void {
        if (!DFSN_DATA_COLLECTION_ENABLED) return;
        $count = scalar("SELECT COUNT(*) FROM dfsn_dataset");
        if ($count >= DFSN_DATASET_MAX_ROWS) {
            db()->prepare("DELETE FROM dfsn_dataset ORDER BY created_at ASC LIMIT ?")
                ->execute([max(1, (int)($count - DFSN_DATASET_MAX_ROWS + 1000))]);
        }
        try {
            db()->prepare("INSERT INTO dfsn_dataset (features, label, source_type, created_at) VALUES (?, ?, ?, ?)")
                ->execute([json_encode($features), $label, $sourceType, time()]);
        } catch (\Throwable $e) {
            error_log("DFSN dataset insert error: " . $e->getMessage());
        }
    }

    // ==================== РЕЙТИНГОВАЯ СИСТЕМА ====================

    public function calculateUserWeights(int $userId): array {
        $this->guardValidUser($userId);
        $user = find('users', $userId);
        $row = find('dfsn_weights', $userId);

        $endorsementSum = $row ? (float)$row['endorsement_sum'] : 0.0;
        $complaintPenalty = $this->calculateFreshComplaintPenalty($userId);
        $wTrust = W_BASE + $endorsementSum - $complaintPenalty;
        $wTrust = max(0.1, $wTrust);

        $daysSinceReg = max(1, (time() - strtotime($user['created_at'])) / 86400);
        $activityFactor = $this->calculateActivityFactor($userId);
        $wActivity = W_BASE * log(1 + $daysSinceReg) * $activityFactor;
        $wActivity = max(0.5, min(2.0, $wActivity));

        $wExpert = W_BASE + $this->calculateContentQuality($userId);
        $wExpert = max(0.5, min(2.0, $wExpert));

        db()->prepare("UPDATE dfsn_weights SET 
            w_trust = ?, w_activity = ?, w_expert = ?,
            complaint_penalty = ?, updated_at = ?
            WHERE user_id = ?")
        ->execute([$wTrust, $wActivity, $wExpert, $complaintPenalty, time(), $userId]);

        // Сбор образца социального профиля
        if (DFSN_DATA_COLLECTION_ENABLED) {
            $label = $wTrust >= 1.5 ? 'trusted' : ($wTrust < 0.3 ? 'suspicious' : null);
            $this->collectSample([
                'w_trust' => $wTrust,
                'w_activity' => $wActivity,
                'w_expert' => $wExpert,
                'endorsement_sum' => $endorsementSum,
                'complaint_penalty' => $complaintPenalty,
                'age_days' => $daysSinceReg,
                'activity_factor' => $activityFactor,
            ], 'social', $label);
        }

        return ['w_trust' => $wTrust, 'w_activity' => $wActivity, 'w_expert' => $wExpert];
    }

    private function calculateFreshComplaintPenalty(int $userId): float {
        $complaints = select(
            "SELECT weight, created_at FROM dfsn_complaints WHERE to_user_id = ? AND created_at > ?",
            [$userId, time() - COMPLAINT_MAX_AGE]
        );
        $penalty = 0.0;
        $now = time();
        foreach ($complaints as $c) {
            $penalty += $c['weight'] * decayFactor($c['created_at'], $now);
        }
        return $penalty;
    }

    public function getUserWeights(int $userId): array {
        $this->guardValidUser($userId);
        $row = find('dfsn_weights', $userId);
        if ($row) {
            return [
                'w_trust'    => (float)$row['w_trust'],
                'w_activity' => (float)$row['w_activity'],
                'w_expert'   => (float)$row['w_expert']
            ];
        }
        $default = ['w_trust' => W_BASE, 'w_activity' => W_BASE, 'w_expert' => W_BASE];
        db()->prepare("INSERT IGNORE INTO dfsn_weights (user_id, w_trust, w_activity, w_expert, endorsement_sum, complaint_penalty, updated_at)
                       VALUES (?, ?, ?, ?, 0, 0, ?)")
            ->execute([$userId, W_BASE, W_BASE, W_BASE, time()]);
        return $default;
    }

    private function calculateActivityFactor(int $userId): float {
        $postsCount = scalar("SELECT COUNT(*) FROM posts WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)", [$userId]);
        $logins = scalar("SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND login_time > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))", [$userId]);
        return round(0.5 + 0.7 * sigmoid(($postsCount + $logins) / 30 - 0.5), 2);
    }

    private function calculateContentQuality(int $userId): float {
        $avgReadTime = scalar("SELECT AVG(read_time) FROM post_metrics WHERE author_id = ?", [$userId]) ?? 0;
        return round(sigmoid($avgReadTime / 60 - 0.5), 2);
    }

    // ==================== ПОРУЧИТЕЛЬСТВА (ФИНАЛЬНАЯ РАБОЧАЯ ВЕРСИЯ) ====================

    public function processEndorsement(int $fromUserId, int $toUserId): string {
        $this->guardValidUser($fromUserId);
        $this->guardValidUser($toUserId);
        if ($fromUserId === $toUserId) return 'self_endorsement_denied';

        $fromWeights = $this->getUserWeights($fromUserId);
        if ($fromWeights['w_activity'] < MIN_ACTIVITY_FOR_ENDORSE) return 'low_activity_denied';

        // Проверка дневного лимита
        $todayCount = scalar(
            "SELECT COUNT(*) FROM dfsn_endorsements WHERE from_user_id = ? AND created_at > UNIX_TIMESTAMP(DATE(NOW()))",
            [$fromUserId]
        );
        if ($todayCount >= MAX_ENDORSEMENTS_PER_DAY) {
            return 'daily_limit_reached';
        }

        // Проверка общего лимита
        $activeCount = scalar("SELECT COUNT(*) FROM dfsn_endorsements WHERE from_user_id = ?", [$fromUserId]);
        if ($activeCount >= MAX_ACTIVE_ENDORSEMENTS) {
            return 'total_limit_reached';
        }

        $coeff = ENDORSEMENT_K * (1 + $fromWeights['w_trust'] / W_BASE);
        $increment = $fromWeights['w_trust'] * $coeff;

        // Прямая вставка без транзакции
        try {
            db()->prepare("INSERT INTO dfsn_endorsements (from_user_id, to_user_id, coefficient, created_at) VALUES (?, ?, ?, ?)")
                ->execute([$fromUserId, $toUserId, round($coeff, 4), time()]);
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) {
                return 'already_endorsed';
            }
            throw $e;
        }

        // Обновление веса получателя
        db()->prepare("UPDATE dfsn_weights SET 
            endorsement_sum = endorsement_sum + ?,
            w_trust = W_BASE + endorsement_sum + ? - complaint_penalty,
            updated_at = ?
            WHERE user_id = ?")
        ->execute([$increment, $increment, time(), $toUserId]);

        $this->logEvent($fromUserId, 'endorsement_given', ['to_user_id' => $toUserId]);
        $this->logEvent($toUserId, 'endorsement_received', ['from_user_id' => $fromUserId]);

        $this->collectSample([
            'from_user_w_trust' => $fromWeights['w_trust'],
            'to_user_w_trust'   => $this->getUserWeights($toUserId)['w_trust'],
            'endorsement_coeff' => $coeff,
            'action' => 'endorsement'
        ], 'social', 'trusted');

        $this->invalidateRecommendationCache($fromUserId);
        $this->invalidateRecommendationCache($toUserId);
        return 'success';
    }

    // ==================== ЖАЛОБЫ (АТОМАРНЫЕ) ====================

    public function processComplaint(int $fromUserId, int $toUserId): string {
        $this->guardValidUser($fromUserId);
        $this->guardValidUser($toUserId);
        if ($fromUserId === $toUserId) return 'self_complaint_denied';

        $fromWeights = $this->getUserWeights($fromUserId);
        if ($fromWeights['w_trust'] < 0.5) return 'low_trust_denied';

        $db = db();
        $db->beginTransaction();
        try {
            $db->prepare("SELECT id FROM dfsn_complaints WHERE from_user_id = ? FOR UPDATE")->execute([$fromUserId]);

            $todayCount = scalar(
                "SELECT COUNT(*) FROM dfsn_complaints WHERE from_user_id = ? AND created_at > UNIX_TIMESTAMP(DATE(NOW()))",
                [$fromUserId]
            );
            if ($todayCount >= MAX_COMPLAINTS_PER_DAY) {
                $db->rollBack();
                return 'daily_limit_reached';
            }

            $weight = 0.1 * $fromWeights['w_trust'];
            try {
                $db->prepare("INSERT INTO dfsn_complaints (from_user_id, to_user_id, weight, created_at) VALUES (?, ?, ?, ?)")
                    ->execute([$fromUserId, $toUserId, round($weight, 3), time()]);
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000) {
                    $db->rollBack();
                    return 'already_complained';
                }
                throw $e;
            }

            $penalty = $this->calculateFreshComplaintPenalty($toUserId);
            $endorsementSum = scalar("SELECT endorsement_sum FROM dfsn_weights WHERE user_id = ?", [$toUserId]) ?? 0;
            $wTrust = max(0.1, W_BASE + $endorsementSum - $penalty);

            $db->prepare("UPDATE dfsn_weights SET complaint_penalty = ?, w_trust = ?, updated_at = ? WHERE user_id = ?")
                ->execute([$penalty, $wTrust, time(), $toUserId]);

            $db->commit();

            $this->logEvent($fromUserId, 'complaint_filed', ['to_user_id' => $toUserId]);
            $this->logEvent($toUserId, 'complaint_received', ['from_user_id' => $fromUserId]);

            $this->collectSample([
                'from_user_w_trust' => $fromWeights['w_trust'],
                'to_user_w_trust'   => $wTrust,
                'complaint_weight'  => $weight,
                'action' => 'complaint'
            ], 'social', $wTrust < 0.4 ? 'suspicious' : null);

        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }

        $this->invalidateRecommendationCache($toUserId);
        return 'success';
    }

    // ==================== ПОВЕДЕНЧЕСКИЙ СТРАЖ ====================

    public function getBehavioralProfile(int $userId): array {
        $this->guardValidUser($userId);
        $row = find('dfsn_behavior_profiles', $userId);
        if ($row) return json_decode($row['profile_data'], true);
        $default = ['features' => array_fill(0, 18, 0.0), 'count' => 0];
        db()->prepare("INSERT IGNORE INTO dfsn_behavior_profiles (user_id, profile_data, sample_count, updated_at) VALUES (?, ?, 0, ?)")
            ->execute([$userId, json_encode($default), time()]);
        return $default;
    }

    public function checkSession(int $userId, array $sessionFeatures): bool {
        $profile = $this->getBehavioralProfile($userId);
        if ($profile['count'] < MIN_SAMPLES_FOR_CHECK) return false;
        $dist = 0.0;
        $mean = $profile['features'];
        for ($i = 0; $i < count($mean); $i++) {
            $diff = ($sessionFeatures[$i] ?? 0) - $mean[$i];
            $dist += $diff * $diff;
        }
        $dist = sqrt($dist);
        $threshold = ANOMALY_THRESHOLD_BASE * (1 + 2 / ($profile['count'] + 1));
        $isAnomaly = $dist > $threshold;

        if ($isAnomaly) {
            $this->logEvent($userId, 'behavioral_anomaly', [
                'distance' => round($dist, 2),
                'threshold' => round($threshold, 2)
            ]);
            if (DFSN_DATA_COLLECTION_ENABLED) {
                $this->collectSample(array_merge($sessionFeatures, [
                    'profile_count' => $profile['count'],
                    'distance' => round($dist, 2),
                    'threshold' => round($threshold, 2)
                ]), 'behaviour', 'anomaly');
            }
        }

        return $isAnomaly;
    }

    public function updateBehavioralProfile(int $userId, array $sessionFeatures, float $emaAlpha = 0.2): void {
        $profile = $this->getBehavioralProfile($userId);
        $count = $profile['count'];
        $oldMean = $profile['features'];
        $newMean = [];
        for ($i = 0; $i < count($oldMean); $i++) {
            $newMean[$i] = $emaAlpha * ($sessionFeatures[$i] ?? 0) + (1 - $emaAlpha) * $oldMean[$i];
        }
        $profile['features'] = $newMean;
        $profile['count'] = $count + 1;
        db()->prepare("UPDATE dfsn_behavior_profiles SET profile_data = ?, sample_count = ?, updated_at = ? WHERE user_id = ?")
            ->execute([json_encode($profile), $profile['count'], time(), $userId]);
    }

    // ==================== ВЕКТОР ИНТЕРЕСОВ ====================

    public function updateInterestVector(int $userId): void {
        $this->guardValidUser($userId);
        $vector = array_fill(0, VECTOR_DIMENSION, 0.0);
        $posts = select("SELECT content FROM posts WHERE user_id = ? ORDER BY created_at DESC LIMIT 100", [$userId]);
        foreach ($posts as $post) {
            $words = tokenize($post['content']);
            foreach ($words as $word) {
                $idx = abs(crc32($word) % VECTOR_DIMENSION);
                $vector[$idx] += 1.0;
            }
        }
        $norm = array_sum($vector) ?: 1;
        foreach ($vector as &$v) $v /= $norm;

        db()->prepare("INSERT INTO dfsn_interest_vectors (user_id, vector, updated_at) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE vector = VALUES(vector), updated_at = VALUES(updated_at)")
            ->execute([$userId, json_encode($vector), time()]);

        $this->collectSample(['interest_vector' => $vector], 'interest');

        $this->invalidateRecommendationCache($userId);
    }

    public function getInterestVector(int $userId): array {
        $this->guardValidUser($userId);
        $row = find('dfsn_interest_vectors', $userId);
        if ($row) return json_decode($row['vector'], true);
        $this->updateInterestVector($userId);
        $row = find('dfsn_interest_vectors', $userId);
        return $row ? json_decode($row['vector'], true) : array_fill(0, VECTOR_DIMENSION, 0.0);
    }

    public function interestSimilarity(int $userA, int $userB): float {
        return cosineSimilarity($this->getInterestVector($userA), $this->getInterestVector($userB));
    }

    // ==================== ГРАФ ДОВЕРИЯ ====================

    public function trustAffinity(int $userA, int $userB): float {
        if ($userA == $userB) return 1.0;
        $direct = scalar(
            "SELECT COUNT(*) FROM dfsn_endorsements WHERE (from_user_id = ? AND to_user_id = ?) OR (from_user_id = ? AND to_user_id = ?)",
            [$userA, $userB, $userB, $userA]
        );
        if ($direct) return 0.8;

        $mutualWeight = scalar(
            "SELECT COALESCE(SUM(w.w_trust), 0)
             FROM dfsn_endorsements e1
             JOIN dfsn_endorsements e2 ON e1.from_user_id = e2.from_user_id
             JOIN dfsn_weights w ON w.user_id = e1.from_user_id
             WHERE e1.to_user_id = ? AND e2.to_user_id = ?",
            [$userA, $userB]
        );
        if ($mutualWeight > 0) {
            return min(0.7, 0.2 + 0.5 * sigmoid($mutualWeight / 3 - 1));
        }
        return 0.0;
    }

    // ==================== РЕКОМЕНДАЦИИ ====================

    public function getRecommendedPeople(int $userId, int $limit = 10, int $offset = 0): array {
        $cached = $this->getCachedRecommendations($userId, 'people', REC_CACHE_TTL_PEOPLE);
        if ($cached !== null) return array_slice($cached, $offset, $limit);

        $candidates = $this->findCandidatePeople($userId);
        if (empty($candidates)) {
            $candidates = select("SELECT id FROM users WHERE id != ? ORDER BY created_at DESC LIMIT 50", [$userId]);
            $candidates = array_column($candidates, 'id');
        }

        $weightsMap = $this->getUserWeightsBulk($candidates);
        $interestVectors = $this->getInterestVectorsBulk($candidates);
        $userVector = $this->getInterestVector($userId);

        $results = [];
        foreach ($candidates as $candidateId) {
            $cWeights = $weightsMap[$candidateId] ?? ['w_trust' => W_BASE, 'w_activity' => W_BASE, 'w_expert' => W_BASE];
            $trust = $this->trustAffinity($userId, $candidateId);
            $interest = cosineSimilarity($userVector, $interestVectors[$candidateId] ?? []);
            $score = ALPHA_TRUST * $trust + BETA_INTEREST * $interest + GAMMA_QUALITY * ($cWeights['w_trust'] + $cWeights['w_expert']) / 2;
            $results[] = ['user_id' => $candidateId, 'score' => round($score, 4)];
        }
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        $results = $this->addExploration($results, $candidates, $weightsMap, 'user_id');
        $results = array_slice($results, 0, 200);
        $this->cacheRecommendations($userId, 'people', $results);
        return array_slice($results, $offset, $limit);
    }

    public function getRecommendedContent(int $userId, int $limit = 10, int $offset = 0): array {
        $cached = $this->getCachedRecommendations($userId, 'content', REC_CACHE_TTL_CONTENT);
        if ($cached !== null) return array_slice($cached, $offset, $limit);

        $posts = select("
            SELECT p.id, p.user_id, p.created_at, p.likes_count,
                   COALESCE(pm.avg_read_time, 0) as avg_read_time
            FROM posts p
            LEFT JOIN (
                SELECT post_id, AVG(read_time) as avg_read_time FROM post_metrics GROUP BY post_id
            ) pm ON pm.post_id = p.id
            ORDER BY p.created_at DESC LIMIT 500
        ");
        if (empty($posts)) return [];

        $authorIds = array_unique(array_column($posts, 'user_id'));
        $weightsMap = $this->getUserWeightsBulk($authorIds);
        $interestVectors = $this->getInterestVectorsBulk($authorIds);
        $userVector = $this->getInterestVector($userId);
        $now = time();

        $results = [];
        foreach ($posts as $post) {
            $authorId = $post['user_id'];
            $authorWeights = $weightsMap[$authorId] ?? ['w_trust' => W_BASE, 'w_activity' => W_BASE, 'w_expert' => W_BASE];
            $trust = $this->trustAffinity($userId, $authorId);
            $interest = cosineSimilarity($userVector, $interestVectors[$authorId] ?? []);
            $quality = sigmoid(($post['avg_read_time'] ?? 0) / 30 + log(1 + ($post['likes_count'] ?? 0)) - 2);
            $recency = exp(-($now - strtotime($post['created_at'])) / (7 * 86400));
            $score = ALPHA_TRUST * $trust + BETA_INTEREST * $interest + GAMMA_QUALITY * $quality * $authorWeights['w_expert'] + DELTA_RECENCY * $recency;
            $results[] = ['post_id' => $post['id'], 'score' => round($score, 4)];

            if (DFSN_DATA_COLLECTION_ENABLED && count($results) <= 50) {
                $this->collectSample([
                    'avg_read_time' => $post['avg_read_time'],
                    'likes_count'   => $post['likes_count'],
                    'author_w_expert'=> $authorWeights['w_expert'],
                    'quality_score' => $quality,
                    'recency'       => $recency,
                    'final_score'   => $score
                ], 'content', $quality > 0.7 ? 'high_quality' : ($quality < 0.3 ? 'low_quality' : null));
            }
        }
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        $results = $this->addExploration($results, $posts, $weightsMap, 'post_id');
        $results = array_slice($results, 0, 200);
        $this->cacheRecommendations($userId, 'content', $results);
        return array_slice($results, $offset, $limit);
    }

    private function addExploration(array $sortedResults, array $allCandidates, array $weightsMap, string $idField): array {
        $topCount = (int)(count($sortedResults) * (1 - EXPLORATION_RATE));
        $top = array_slice($sortedResults, 0, $topCount);
        $rest = array_slice($sortedResults, $topCount);
        $qualityCandidates = [];
        foreach ($allCandidates as $candidate) {
            $cid = is_array($candidate) ? $candidate[$idField] : $candidate;
            $weights = $weightsMap[$cid] ?? null;
            if ($weights && ($weights['w_expert'] ?? 0) > 0.8) {
                $qualityCandidates[] = $cid;
            }
        }
        if (!empty($qualityCandidates)) {
            shuffle($qualityCandidates);
            $exploreCount = min(count($rest), count($qualityCandidates));
            for ($i = 0; $i < $exploreCount; $i++) {
                $rest[$i] = [$idField => $qualityCandidates[$i], 'score' => 0];
            }
        }
        return array_merge($top, $rest);
    }

    private function findCandidatePeople(int $userId): array {
        $ids = select(
            "SELECT DISTINCT e2.to_user_id AS id
             FROM dfsn_endorsements e1
             JOIN dfsn_endorsements e2 ON e1.to_user_id = e2.from_user_id
             WHERE e1.from_user_id = ?
               AND e2.to_user_id != ?
               AND e2.to_user_id NOT IN (SELECT to_user_id FROM dfsn_endorsements WHERE from_user_id = ?)
               AND e2.to_user_id IN (SELECT user_id FROM dfsn_weights WHERE w_trust > 0.5)
             LIMIT 200",
            [$userId, $userId, $userId]
        );
        return array_column($ids, 'id');
    }

    private function getUserWeightsBulk(array $userIds): array {
        if (empty($userIds)) return [];
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $rows = select("SELECT * FROM dfsn_weights WHERE user_id IN ($placeholders)", $userIds);
        $map = [];
        foreach ($rows as $row) {
            $map[$row['user_id']] = [
                'w_trust'    => (float)$row['w_trust'],
                'w_activity' => (float)$row['w_activity'],
                'w_expert'   => (float)$row['w_expert']
            ];
        }
        return $map;
    }

    private function getInterestVectorsBulk(array $userIds): array {
        if (empty($userIds)) return [];
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $rows = select("SELECT user_id, vector FROM dfsn_interest_vectors WHERE user_id IN ($placeholders)", $userIds);
        $map = [];
        foreach ($rows as $row) {
            $map[$row['user_id']] = json_decode($row['vector'], true) ?: [];
        }
        return $map;
    }

    // ==================== КЭШ РЕКОМЕНДАЦИЙ ====================

    private function getCachedRecommendations(int $userId, string $type, int $ttl): ?array {
        $row = select(
            "SELECT items, created_at FROM dfsn_recommendations_cache WHERE user_id = ? AND type = ?",
            [$userId, $type]
        );
        if (!$row) return null;
        $age = time() - $row[0]['created_at'];
        if ($age < $ttl) return json_decode($row[0]['items'], true);
        if (mt_rand() / mt_getrandmax() > $age / ($ttl * 2)) {
            return json_decode($row[0]['items'], true);
        }
        return null;
    }

    private function cacheRecommendations(int $userId, string $type, array $items): void {
        db()->prepare("INSERT INTO dfsn_recommendations_cache (user_id, type, items, created_at) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE items = VALUES(items), created_at = VALUES(created_at)")
            ->execute([$userId, $type, json_encode($items), time()]);
    }

    private function invalidateRecommendationCache(int $userId): void {
        db()->prepare("DELETE FROM dfsn_recommendations_cache WHERE user_id = ?")->execute([$userId]);
    }

    public function invalidateContentCacheForAuthor(int $authorId): void {
        db()->prepare("
            DELETE cache FROM dfsn_recommendations_cache cache
            WHERE cache.type = 'content'
              AND cache.user_id IN (
                  SELECT e.from_user_id FROM dfsn_endorsements e WHERE e.to_user_id = ?
              )
        ")->execute([$authorId]);
    }

    // ==================== ДАМП МОДЕЛИ ====================
    public function exportModelDump(): void {
        if (!DFSN_MODEL_DUMP_ENABLED) return;

        $dump = [
            'version' => '5.2.4',
            'timestamp' => time(),
            'weights' => select("SELECT * FROM dfsn_weights"),
            'behavior_profiles_count' => scalar("SELECT COUNT(*) FROM dfsn_behavior_profiles"),
            'interest_vectors_count' => scalar("SELECT COUNT(*) FROM dfsn_interest_vectors"),
            'endorsements_count' => scalar("SELECT COUNT(*) FROM dfsn_endorsements"),
            'complaints_count' => scalar("SELECT COUNT(*) FROM dfsn_complaints"),
            'dataset_count' => scalar("SELECT COUNT(*) FROM dfsn_dataset"),
        ];

        db()->prepare("INSERT INTO dfsn_model_dumps (dump_data, created_at) VALUES (?, ?)")
            ->execute([json_encode($dump), time()]);

        db()->prepare("DELETE FROM dfsn_model_dumps WHERE created_at < ?")
            ->execute([time() - DFSN_DUMP_RETENTION_DAYS * 24 * 3600]);
    }

    // ==================== ФОНОВЫЕ ЗАДАЧИ ====================

    public function cronDaily(): void {
        db()->prepare("DELETE FROM dfsn_complaints WHERE created_at < ?")->execute([time() - COMPLAINT_MAX_AGE]);

        $offset = 0;
        $chunkSize = 500;
        do {
            $users = select(
                "SELECT DISTINCT to_user_id FROM dfsn_complaints WHERE created_at > ? LIMIT ? OFFSET ?",
                [time() - COMPLAINT_MAX_AGE, $chunkSize, $offset]
            );
            foreach ($users as $row) {
                $uid = $row['to_user_id'];
                $penalty = $this->calculateFreshComplaintPenalty($uid);
                $endorsementSum = scalar("SELECT endorsement_sum FROM dfsn_weights WHERE user_id = ?", [$uid]) ?? 0;
                $wTrust = max(0.1, W_BASE + $endorsementSum - $penalty);
                db()->prepare("UPDATE dfsn_weights SET complaint_penalty = ?, w_trust = ?, updated_at = ? WHERE user_id = ?")
                    ->execute([$penalty, $wTrust, time(), $uid]);
            }
            $offset += $chunkSize;
        } while (count($users) >= $chunkSize);

        if (DFSN_LOGGING_ENABLED) {
            db()->prepare("DELETE FROM dfsn_log WHERE created_at < ?")->execute([time() - 90 * 24 * 3600]);
        }

        if (DFSN_MODEL_DUMP_ENABLED) {
            $this->exportModelDump();
        }
    }
}