-- 17 of 20 badges had badge_emoji = '?' (ASCII 0x3F) from a broken earlier insert.
-- Replace with thematically appropriate emojis.

UPDATE badges SET badge_emoji = '👣'  WHERE badge_name = 'first_steps';
UPDATE badges SET badge_emoji = '💪'  WHERE badge_name = 'getting_serious';
UPDATE badges SET badge_emoji = '📚'  WHERE badge_name = 'knowledge_builder';
UPDATE badges SET badge_emoji = '🎓'  WHERE badge_name = 'course_master';
UPDATE badges SET badge_emoji = '🎯'  WHERE badge_name = 'accuracy_ace';
UPDATE badges SET badge_emoji = '💎'  WHERE badge_name = 'flawless_streak';
UPDATE badges SET badge_emoji = '🦸'  WHERE badge_name = 'hard_mode_hero';
UPDATE badges SET badge_emoji = '🥉'  WHERE badge_name = 'level_1_beginner';
UPDATE badges SET badge_emoji = '🥈'  WHERE badge_name = 'level_2_explorer';
UPDATE badges SET badge_emoji = '🥇'  WHERE badge_name = 'level_3_advanced';
UPDATE badges SET badge_emoji = '🏆'  WHERE badge_name = 'level_4_expert';
UPDATE badges SET badge_emoji = '📅'  WHERE badge_name = 'daily_learner';
UPDATE badges SET badge_emoji = '🗓️' WHERE badge_name = 'weekly_warrior';
UPDATE badges SET badge_emoji = '🫡'  WHERE badge_name = 'never_give_up';
UPDATE badges SET badge_emoji = '🌙'  WHERE badge_name = 'late_night_scholar';
UPDATE badges SET badge_emoji = '🌅'  WHERE badge_name = 'early_bird';
UPDATE badges SET badge_emoji = '🛡️' WHERE badge_name = 'never_quit';
