# Quick Reference: New Question Selection Logic

## What Changed
The `getQuestion()` function in `bot_functions.php` now implements a sophisticated 4-level question selection system with probability-based mixing and success-rate filtering.

## Level Breakdown

### Level 1 (Beginner)
- **Questions**: Easy (difficulty=1) with 85%+ success rate
- **Repeats**: ✅ Allowed
- **Logic**: Simple filter, no mixing

### Level 2 (Intermediate)
- **Questions**: 
  - 70% → Difficulty 2 with 60%+ success rate
  - 30% → Difficulty 1 with 85%+ success rate
- **Repeats**: ✅ Allowed
- **Logic**: Probability-weighted random selection

### Level 3 (Advanced)
- **Questions**:
  - 50% → Difficulty 3 with 50%+ success rate
  - 50% → Difficulty 1 or 2 (85%+ and 60%+ respectively)
- **Repeats**: ❌ NOT allowed
- **Logic**: Excludes previously answered questions from user_q table

### Level 4 (Expert)
- **Questions**:
  - 50% → Difficulty 4 with <50% success rate (hardest)
  - 20% → Difficulty 3 with 50%+ success rate
  - 30% → Difficulty 1 or 2 mix
- **Repeats**: ❌ NOT allowed
- **Logic**: Complex mixing with exclusions

## Key Safety Features

### 1. Division-by-Zero Protection
Every query includes:
```sql
numofanswers > 0 AND (numofcorrectanswers / numofanswers) [operator] [threshold]
```

### 2. SQL Injection Prevention
All user inputs sanitized:
```php
$safe_user_id = intval($user_id);
```

### 3. Fallback Strategy
- **Within-Level**: Tries other buckets in same level
- **Global**: Falls back to ANY question if all else fails
- **Result**: Bot never crashes due to empty questions

## New Helper Functions

1. **`buildSuccessRateCondition($difficulty, $operator, $threshold)`**
   - Builds SQL conditions for success rate filtering
   - Handles division-by-zero automatically

2. **`buildExclusionClause($user_id)`**
   - Creates NOT IN clause for excluding seen questions
   - Used for Levels 3 and 4

3. **`executeQuestionQuery($db, $query, $debug_label)`**
   - Executes query and handles empty results
   - Optional debug logging (disabled by default)

## Debugging

To enable SQL query logging, uncomment in `executeQuestionQuery()`:
```php
error_log("[$debug_label] SQL: $query");
```

## Testing Quick Checks

### Check Level Distribution
```sql
SELECT level, COUNT(*) FROM users GROUP BY level;
```

### Check Question Success Rates
```sql
SELECT 
    difficulty,
    COUNT(*) as total,
    AVG(CASE WHEN numofanswers > 0 
        THEN numofcorrectanswers / numofanswers END) as avg_rate
FROM questions
GROUP BY difficulty;
```

### Check User Progress (Level 3/4)
```sql
SELECT u.id, u.nickname, COUNT(uq.questionid) as answered
FROM users u
LEFT JOIN user_q uq ON u.id = uq.userid
WHERE u.level IN (3, 4)
GROUP BY u.id;
```

## Performance Tips

Optional indexes for better performance:
```sql
-- Speed up success rate filtering
ALTER TABLE questions 
ADD INDEX idx_diff_success (difficulty, numofanswers, numofcorrectanswers);

-- Speed up exclusion lookups
ALTER TABLE user_q 
ADD INDEX idx_user_question (userid, questionid);
```

## Rollback Plan

If issues occur, the old `getQuestion()` function can be restored from git history or backup.

## Status
✅ **Implementation Complete**  
✅ **Code Reviewed**  
✅ **No Syntax Errors**  
✅ **Ready for Testing**  

---

**Implementation Date**: January 2, 2026  
**Files Modified**: `bank/bot_functions.php`  
**Documentation**: `QUESTION-SELECTION-IMPLEMENTATION.md`

