-- Populate the actions table for every user-facing event the bot now logs.
-- Research needs a complete audit trail in the log table, not just answers.

-- action_id 14 was labelled 'S-asked' but the code has been writing it as the
-- nickname-set event. Rename to match actual usage (two historical rows keep
-- their consistent meaning).
UPDATE actions SET action = 'NicknameSet' WHERE action_id = 14;

INSERT IGNORE INTO actions (action_id, action) VALUES
    (15, 'S-asked'),                -- survey question shown to user (previously unused)
    (19, 'MenuCommand'),            -- /menu command
    (20, 'MenuStart'),              -- "start playing" button from main menu
    (21, 'MenuBadges'),             -- "my badges" button from main menu
    (22, 'MenuLeaderboardRoot'),    -- "leaderboards" button (opens sub-menu)
    (23, 'MenuLeaderboardAll'),     -- all-time leaderboard pick
    (24, 'MenuLeaderboardWeekly'),  -- weekly leaderboard pick
    (25, 'MenuLeaderboardMonthly'), -- monthly leaderboard pick
    (26, 'MenuBack'),               -- "back to menu" button
    (30, 'NicknameChangeRequest'),  -- /changenickname command
    (31, 'ClearStatsRequest'),      -- /clearstats command (shows confirmation)
    (32, 'ClearStatsConfirm'),      -- user confirmed clear
    (33, 'ClearStatsCancel'),       -- user cancelled clear
    (40, 'BadgeEarned');            -- badge awarded (additional_value = badge_id)
