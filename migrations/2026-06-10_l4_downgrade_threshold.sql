-- Make level-4 demotion actually reachable.
--
-- Two bugs made the top level un-demotable:
--   1. current_run grew unbounded at level 4 (no reset/cap on correct answers),
--      so the downgrade threshold drifted ever further out of reach (one user hit
--      current_run = 323). Fixed in code: bot_functions.php now caps current_run
--      at 0 for level-4 correct answers.
--   2. downgrade_at was -5, but the wrong-answer branch floors current_run at -4,
--      so it could never go below -5. Moving the threshold to -3 puts the trigger
--      above the floor so it fires: 4 wrong answers in a row (no correct in
--      between) demote a level-4 player to level 3.
--
-- Tuning: -2 => 3 wrong in a row; -3 => 4; -4 => 5. Leveling remains driven by
-- current_run only, fully independent of overall_points.
UPDATE gamification SET downgrade_at = -3 WHERE level = 4;

-- One-off state correction: drain the runaway positive cushions accumulated under
-- the old behaviour so the rule applies from now, not after hundreds of wrongs.
UPDATE users SET current_run = 0 WHERE level = 4 AND current_run > 0;
