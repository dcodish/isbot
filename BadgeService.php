<?php

/**
 * BadgeService - Handles all badge-related operations
 *
 * This service manages:
 * - Checking if users have earned badges
 * - Awarding badges to users
 * - Tracking badge progress
 * - Sending badge notifications via Telegram
 */
class BadgeService {
    private $db;
    private $user_id;
    private $chat_id;

    /**
     * Constructor
     * @param mysqli $db Database connection
     * @param int $user_id Telegram user ID
     * @param int $chat_id Telegram chat ID
     */
    public function __construct($db, $user_id, $chat_id) {
        $this->db = $db;
        $this->user_id = intval($user_id);
        $this->chat_id = intval($chat_id);
    }

    /**
     * Award a badge to a user by badge name
     * @param string $badge_name Unique badge identifier
     * @param bool $notify Whether to send notification immediately
     * @return bool True if badge was newly awarded, false if already had it
     */
    public function awardBadge($badge_name, $notify = true) {
        // Get badge details
        $badge_name_safe = mysqli_real_escape_string($this->db, $badge_name);
        $query = "SELECT badge_id, badge_title_he, badge_description_he, badge_emoji, 
                         sticker_file_id, points_reward
                  FROM badges 
                  WHERE badge_name = '$badge_name_safe' AND is_active = 1
                  LIMIT 1";

        $result = mysqli_query($this->db, $query);
        if (!$result || mysqli_num_rows($result) == 0) {
            error_log("Badge not found: $badge_name");
            if ($result) mysqli_free_result($result);
            return false;
        }

        $badge = mysqli_fetch_assoc($result);
        mysqli_free_result($result);

        // Check if user already has this badge
        if ($this->hasBadge($badge['badge_id'])) {
            return false; // Already has badge
        }

        // Award the badge
        $badge_id = intval($badge['badge_id']);
        $query = "INSERT INTO user_badges (user_id, badge_id, notified) 
                  VALUES ({$this->user_id}, $badge_id, 0)";

        if (!mysqli_query($this->db, $query)) {
            error_log("Failed to award badge $badge_name to user {$this->user_id}: " . mysqli_error($this->db));
            return false;
        }

        // Award bonus points if any
        if ($badge['points_reward'] > 0) {
            $points = intval($badge['points_reward']);
            $query = "UPDATE users SET overall_points = overall_points + $points 
                      WHERE id = {$this->user_id}";
            mysqli_query($this->db, $query);
        }

        // Send notification if requested
        if ($notify) {
            $this->notifyBadgeEarned($badge);
        }

        return true;
    }

    /**
     * Check if user has a specific badge
     * @param int $badge_id Badge ID
     * @return bool
     */
    private function hasBadge($badge_id) {
        $badge_id = intval($badge_id);
        $query = "SELECT 1 FROM user_badges 
                  WHERE user_id = {$this->user_id} AND badge_id = $badge_id 
                  LIMIT 1";

        $result = mysqli_query($this->db, $query);
        $has = ($result && mysqli_num_rows($result) > 0);

        if ($result) mysqli_free_result($result);
        return $has;
    }

    /**
     * Send badge notification to user
     * @param array $badge Badge data from database
     */
    private function notifyBadgeEarned($badge) {
        // Send sticker if available
        if (!empty($badge['sticker_file_id'])) {
            $this->sendSticker($badge['sticker_file_id']);
            usleep(100000); // 100ms delay
        }

        // Build congratulations message
        $emoji = $badge['badge_emoji'] ?: '🏆';
        $message = "🎉 תג חדש! 🎉\n\n";
        $message .= "$emoji {$badge['badge_title_he']} $emoji\n\n";
        $message .= "{$badge['badge_description_he']}\n";

        if ($badge['points_reward'] > 0) {
            $message .= "\n💰 קיבלת {$badge['points_reward']} נקודות בונוס!";
        }

        bot_message($this->chat_id, $message);

        // Mark as notified
        $badge_id = intval($badge['badge_id']);
        $query = "UPDATE user_badges SET notified = 1 
                  WHERE user_id = {$this->user_id} AND badge_id = $badge_id";
        mysqli_query($this->db, $query);
    }

    /**
     * Send a sticker to the user
     * @param string $sticker_file_id Telegram sticker file_id
     */
    private function sendSticker($sticker_file_id) {
        global $API_URL;
        $sticker_file_id = urlencode($sticker_file_id);
        $url = "sendSticker?chat_id={$this->chat_id}&sticker={$sticker_file_id}";
        bot($url);
    }

    /**
     * Update or create badge progress
     * @param string $badge_name Badge identifier
     * @param int $value New progress value
     */
    public function updateProgress($badge_name, $value) {
        $badge_name_safe = mysqli_real_escape_string($this->db, $badge_name);
        $value = intval($value);

        $query = "INSERT INTO badge_progress (user_id, badge_name, progress_value) 
                  VALUES ({$this->user_id}, '$badge_name_safe', $value)
                  ON DUPLICATE KEY UPDATE 
                  progress_value = $value,
                  last_updated = CURRENT_TIMESTAMP";

        mysqli_query($this->db, $query);
    }

    /**
     * Get current progress for a badge
     * @param string $badge_name Badge identifier
     * @return int Current progress value
     */
    public function getProgress($badge_name) {
        $badge_name_safe = mysqli_real_escape_string($this->db, $badge_name);
        $query = "SELECT progress_value FROM badge_progress 
                  WHERE user_id = {$this->user_id} AND badge_name = '$badge_name_safe'
                  LIMIT 1";

        $result = mysqli_query($this->db, $query);
        $value = 0;

        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            $value = intval($row['progress_value']);
        }

        if ($result) mysqli_free_result($result);
        return $value;
    }

    /**
     * Reset progress for a badge (e.g., when streak is broken)
     * @param string $badge_name Badge identifier
     */
    public function resetProgress($badge_name) {
        $this->updateProgress($badge_name, 0);
    }

    /**
     * Check and award "nickname_chosen" badge when user sets nickname
     */
    public function checkWelcomeBadge() {
        $this->awardBadge('nickname_chosen');
    }

    /**
     * Check and award badges after a correct answer
     */
    public function checkCorrectAnswerBadges() {
        // Get user stats
        $stats = $this->getUserStats();
        $total_answered = $stats['total_answered'];
        $total_correct = $stats['total_correct'];

        // first_steps: First question answered
        if ($total_answered == 1) {
            $this->awardBadge('first_steps');
        }

        // Streak badges - flawless_streak (10 in a row)
        $current_streak = $this->getProgress('current_streak');
        $current_streak++;
        $this->updateProgress('current_streak', $current_streak);

        if ($current_streak == 10) {
            $this->awardBadge('flawless_streak');
        }

        // Milestone badges based on total answered
        if ($total_answered == 50) {
            $this->awardBadge('getting_serious');
        } elseif ($total_answered == 200) {
            $this->awardBadge('knowledge_builder');
        } elseif ($total_answered == 400) {
            $this->awardBadge('course_master');
        }

        // accuracy_ace: 80%+ success rate
        if ($stats['total_answered'] >= 20 && $stats['success_rate'] >= 80) {
            $this->awardBadge('accuracy_ace');
        }

        // hard_mode_hero: 20 correct on level 4 (hardest)
        $this->checkHardModeHero();

        // Time-based badges
        $this->checkTimeBadges();

        // Consistency badges
        $this->checkConsistencyBadges();

        // daily_grind: 30 questions in one day
        $this->checkDailyGrind();
    }

    /**
     * Check and award badges after a wrong answer
     */
    public function checkWrongAnswerBadges() {
        // Reset streak on wrong answer
        $this->resetProgress('current_streak');

        // Get stats
        $stats = $this->getUserStats();
        $total_answered = $stats['total_answered'];
        $total_wrong = $stats['total_wrong'];

        // Milestone badges (still check on wrong answers)
        if ($total_answered == 50) {
            $this->awardBadge('getting_serious');
        } elseif ($total_answered == 200) {
            $this->awardBadge('knowledge_builder');
        } elseif ($total_answered == 400) {
            $this->awardBadge('course_master');
        }

        // never_quit: 50 wrong answers but still going
        if ($total_wrong == 50) {
            $this->awardBadge('never_quit');
        }

        // Time-based badges
        $this->checkTimeBadges();

        // Consistency badges
        $this->checkConsistencyBadges();

        // daily_grind
        $this->checkDailyGrind();
    }

    /**
     * Check and award level-up badges
     * @param int $new_level The level user just reached
     */
    public function checkLevelBadge($new_level) {
        switch ($new_level) {
            case 1:
                $this->awardBadge('level_1_beginner');
                break;
            case 2:
                $this->awardBadge('level_2_explorer');
                break;
            case 3:
                $this->awardBadge('level_3_advanced');
                break;
            case 4:
                $this->awardBadge('level_4_expert');
                break;
        }
    }

    /**
     * Check and award still_standing badge when user levels up after having leveled down
     */
    public function checkComebackBadge() {
        // Check if user has ever leveled down
        $query = "SELECT 1 FROM log 
                  WHERE userid = {$this->user_id} AND action_type = 10
                  LIMIT 1";

        $result = mysqli_query($this->db, $query);
        $has_leveled_down = ($result && mysqli_num_rows($result) > 0);

        if ($result) mysqli_free_result($result);

        if ($has_leveled_down) {
            $this->awardBadge('still_standing');
        }
    }

    /**
     * Check time-based badges (early_bird, late_night_scholar)
     */
    private function checkTimeBadges() {
        $hour = intval(date('H'));

        // early_bird: before 08:00
        if ($hour >= 0 && $hour < 8) {
            $this->awardBadge('early_bird');
        }

        // late_night_scholar: after 22:00
        if ($hour >= 22) {
            $this->awardBadge('late_night_scholar');
        }
    }

    /**
     * Check consistency badges (daily_learner, weekly_warrior, never_give_up)
     */
    private function checkConsistencyBadges() {
        // daily_learner: 3 consecutive days
        $query = "SELECT COUNT(DISTINCT DATE(timestamp)) as consecutive_days
                  FROM point_log
                  WHERE user_id = {$this->user_id}
                  AND timestamp >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)
                  AND DATE(timestamp) <= CURDATE()";

        $result = mysqli_query($this->db, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            if ($row['consecutive_days'] >= 3) {
                $this->awardBadge('daily_learner');
            }
            mysqli_free_result($result);
        }

        // weekly_warrior: 7 different days in one week
        $query = "SELECT COUNT(DISTINCT DATE(timestamp)) as days_this_week
                  FROM point_log
                  WHERE user_id = {$this->user_id}
                  AND YEARWEEK(timestamp, 1) = YEARWEEK(CURDATE(), 1)";

        $result = mysqli_query($this->db, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            if ($row['days_this_week'] >= 7) {
                $this->awardBadge('weekly_warrior');
            }
            mysqli_free_result($result);
        }

        // never_give_up: return after inactivity (7+ days gap, then activity)
        $query = "SELECT MAX(timestamp) as last_activity,
                         DATEDIFF(NOW(), MAX(timestamp)) as days_since
                  FROM point_log
                  WHERE user_id = {$this->user_id}
                  AND timestamp < DATE_SUB(NOW(), INTERVAL 1 DAY)";

        $result = mysqli_query($this->db, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            if ($row['days_since'] !== null && $row['days_since'] >= 7) {
                // User had 7+ days of inactivity and just came back
                $this->awardBadge('never_give_up');
            }
            mysqli_free_result($result);
        }
    }

    /**
     * Check daily_grind badge (30 questions in one day)
     */
    private function checkDailyGrind() {
        $query = "SELECT COUNT(*) as today_count
                  FROM point_log
                  WHERE user_id = {$this->user_id}
                  AND DATE(timestamp) = CURDATE()";

        $result = mysqli_query($this->db, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            if ($row['today_count'] >= 30) {
                $this->awardBadge('daily_grind');
            }
            mysqli_free_result($result);
        }
    }

    /**
     * Check hard_mode_hero badge (20 correct on level 4)
     */
    private function checkHardModeHero() {
        $query = "SELECT COUNT(*) as level4_correct
                  FROM point_log
                  WHERE user_id = {$this->user_id}
                  AND action_type = 1
                  AND question_level = 4";

        $result = mysqli_query($this->db, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            if ($row['level4_correct'] >= 20) {
                $this->awardBadge('hard_mode_hero');
            }
            mysqli_free_result($result);
        }
    }

    /**
     * Get user statistics for badge checking
     * @return array
     */
    private function getUserStats() {
        $query = "SELECT 
                    COUNT(*) as total_answered,
                    SUM(CASE WHEN action_type = 1 THEN 1 ELSE 0 END) as total_correct,
                    SUM(CASE WHEN action_type = 2 THEN 1 ELSE 0 END) as total_wrong
                  FROM point_log
                  WHERE user_id = {$this->user_id}";

        $result = mysqli_query($this->db, $query);
        $stats = [
            'total_answered' => 0,
            'total_correct' => 0,
            'total_wrong' => 0,
            'success_rate' => 0,
            'overall_points' => 0
        ];

        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            $stats['total_answered'] = intval($row['total_answered']);
            $stats['total_correct'] = intval($row['total_correct']);
            $stats['total_wrong'] = intval($row['total_wrong']);

            if ($stats['total_answered'] > 0) {
                $stats['success_rate'] = round(($stats['total_correct'] / $stats['total_answered']) * 100, 2);
            }
            mysqli_free_result($result);
        }

        // Get overall points
        $query = "SELECT overall_points FROM users WHERE id = {$this->user_id}";
        $result = mysqli_query($this->db, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            $stats['overall_points'] = intval($row['overall_points']);
            mysqli_free_result($result);
        }

        return $stats;
    }

    /**
     * Get all badges earned by user
     * @return array Array of badge data
     */
    public function getUserBadges() {
        $query = "SELECT b.badge_name, b.badge_title_he, b.badge_emoji, ub.earned_at
                  FROM user_badges ub
                  JOIN badges b ON ub.badge_id = b.badge_id
                  WHERE ub.user_id = {$this->user_id}
                  ORDER BY ub.earned_at DESC";

        $result = mysqli_query($this->db, $query);
        $badges = [];

        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $badges[] = $row;
            }
            mysqli_free_result($result);
        }

        return $badges;
    }

    /**
     * Get badge count for user
     * @return int
     */
    public function getBadgeCount() {
        $query = "SELECT COUNT(*) as count 
                  FROM user_badges 
                  WHERE user_id = {$this->user_id}";

        $result = mysqli_query($this->db, $query);
        $count = 0;

        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            $count = intval($row['count']);
            mysqli_free_result($result);
        }

        return $count;
    }
}
