# Question Selection Implementation - 4-Level Logic

## Overview
This document describes the new 4-level question-selection logic implemented in the `getQuestion()` function in `bot_functions.php`.

## Implementation Details

### Helper Functions

#### 1. `buildSuccessRateCondition($difficulty, $operator, $threshold)`
**Purpose**: Builds SQL WHERE clause fragments for success rate filtering.

**Parameters**:
- `$difficulty` - Question difficulty level (1-4)
- `$operator` - Comparison operator (`>=`, `<`, etc.)
- `$threshold` - Success rate threshold (0.0 to 1.0)

**Returns**: SQL condition string

**Example Output**:
```sql
difficulty = 1 AND numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.85
```

**Division-by-Zero Protection**: 
- Always checks `numofanswers > 0` before dividing
- Questions with 0 answers are automatically excluded from success-rate filters

---

#### 2. `buildExclusionClause($user_id)`
**Purpose**: Builds SQL clause to exclude previously-seen questions.

**Parameters**:
- `$user_id` - User ID (sanitized to integer)

**Returns**: SQL NOT IN clause

**Example Output**:
```sql
id NOT IN (SELECT questionid FROM user_q WHERE userid = 1671626997)
```

---

#### 3. `executeQuestionQuery($db, $query, $debug_label)`
**Purpose**: Executes a question query and handles empty results.

**Parameters**:
- `$db` - mysqli database connection
- `$query` - SQL query string
- `$debug_label` - Optional label for debugging (disabled by default)

**Returns**: 
- mysqli result object if rows found
- `null` if no rows found (and frees the empty result)

**Debug Mode**: Uncomment the `error_log` line in the function to enable SQL logging.

---

### Main Logic: `getQuestion()`

#### Level 1 (Easiest Pool)
**Target Users**: Beginners

**Selection Criteria**:
- Success rate: `>= 0.85` (85%+)
- Difficulty: `1` (easy)
- **Repeat allowed**: Questions CAN repeat (no user_q exclusion)

**SQL Example**:
```sql
SELECT * FROM questions 
WHERE difficulty = 1 
  AND numofanswers > 0 
  AND (numofcorrectanswers / numofanswers) >= 0.85 
ORDER BY RAND() LIMIT 1
```

**Fallback**: If no questions match, goes to final global fallback.

---

#### Level 2 (Mid-Easy Mix)
**Target Users**: Progressing learners

**Selection Criteria**:
- **70% probability**: Pick from difficulty `2`, success rate `>= 0.60`
- **30% probability**: Pick from difficulty `1`, success rate `>= 0.85`
- **Repeat allowed**: Questions CAN repeat

**Probability Implementation**:
```php
$rand = rand(1, 100);
if ($rand <= 70) {
    // 70% bucket: difficulty 2, rate >= 0.6
} else {
    // 30% bucket: difficulty 1, rate >= 0.85
}
```

**Within-Level Fallback**: If selected bucket is empty, tries the other bucket before going to global fallback.

---

#### Level 3 (Mid-Hard Mix)
**Target Users**: Intermediate learners

**Selection Criteria**:
- **50% probability**: Difficulty `3`, success rate `>= 0.50`
- **50% probability**: Mixed difficulty `1` or `2`:
  - Difficulty `1` requires success rate `>= 0.85`
  - Difficulty `2` requires success rate `>= 0.60`
- **No repeats**: Questions are excluded if already seen by this user

**SQL Example (Mixed Bucket)**:
```sql
SELECT * FROM questions 
WHERE (
    (difficulty = 1 AND numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.85)
    OR
    (difficulty = 2 AND numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.6)
)
AND id NOT IN (SELECT questionid FROM user_q WHERE userid = ?)
ORDER BY RAND() LIMIT 1
```

**Within-Level Fallback**: If selected bucket is empty, tries the other bucket.

---

#### Level 4 (Hard Mix)
**Target Users**: Advanced learners

**Selection Criteria**:
- **50% probability**: Difficulty `4`, success rate `< 0.50` (hardest questions)
- **20% probability**: Difficulty `3`, success rate `>= 0.50`
- **30% probability**: Mixed difficulty `1` or `2` (same thresholds as Level 3)
- **No repeats**: Questions are excluded if already seen

**Probability Implementation**:
```php
$rand = rand(1, 100);
if ($rand <= 50) {
    // 50% bucket: diff 4, rate < 0.5
} elseif ($rand <= 70) {
    // 20% bucket: diff 3, rate >= 0.5
} else {
    // 30% bucket: diff 1 or 2 mixed
}
```

**Within-Level Fallback**: 
- If selected bucket is empty, tries remaining buckets in order
- Example: If diff=4 bucket empty → tries diff=3 → tries diff=1,2 mix

---

### Fallback Strategy

#### Within-Level Fallbacks
Each level (2, 3, 4) implements intelligent fallbacks:
1. Try initially selected probability bucket
2. If empty, try other buckets within the same level
3. Only proceed to global fallback if all level buckets are exhausted

#### Global Fallback
If no question found after all level-specific attempts:
```sql
SELECT * FROM questions ORDER BY RAND() LIMIT 1
```
This ensures the bot NEVER crashes due to empty result sets.

---

### Security Features

#### SQL Injection Prevention
1. **User ID Sanitization**:
   ```php
   $safe_user_id = intval($user_id);
   ```

2. **Integer Casting in Helpers**:
   ```php
   $diff = intval($difficulty);
   $uid = intval($user_id);
   ```

3. **Float Validation**:
   ```php
   $thresh = floatval($threshold);
   ```

#### Division-by-Zero Protection
Every success rate calculation includes:
```sql
numofanswers > 0 AND (numofcorrectanswers / numofanswers) [operator] [threshold]
```

This ensures:
- No division by zero errors
- Questions with no attempts are excluded from rate-based filters
- Can still appear in global fallback if needed

---

### Memory Management

#### Result Set Cleanup
- `executeQuestionQuery()` automatically frees empty result sets
- Main function frees the final result after fetching data:
  ```php
  mysqli_free_result($question_result);
  ```

---

### Performance Considerations

#### Random Selection
Uses MySQL's `ORDER BY RAND()` with `LIMIT 1`:
- Simple and effective for small-to-medium question pools
- For large databases (10,000+ questions), consider indexed alternatives

#### Query Optimization Tips
1. **Add Index on Success Rate** (optional):
   ```sql
   ALTER TABLE questions 
   ADD INDEX idx_difficulty_success (difficulty, numofcorrectanswers, numofanswers);
   ```

2. **Add Index on user_q for Exclusion**:
   ```sql
   ALTER TABLE user_q 
   ADD INDEX idx_user_questions (userid, questionid);
   ```

3. **Questions Table Index**:
   ```sql
   ALTER TABLE questions 
   ADD INDEX idx_difficulty (difficulty);
   ```

---

### Debug Mode

To enable SQL logging, uncomment this line in `executeQuestionQuery()`:
```php
error_log("[$debug_label] SQL: $query");
```

This will log each query with its bucket label to PHP error log:
```
[L1-main] SQL: SELECT * FROM questions WHERE difficulty = 1 AND numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.85 ORDER BY RAND() LIMIT 1
[L2-diff2] SQL: SELECT * FROM questions WHERE difficulty = 2 AND numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.6 ORDER BY RAND() LIMIT 1
```

---

### Testing Recommendations

#### Test Cases to Verify

1. **Level 1 Users**:
   - Should only get easy (diff=1) questions with 85%+ success rate
   - Can see same questions multiple times

2. **Level 2 Users**:
   - Should get ~70% diff=2 questions, ~30% diff=1 questions
   - Success rates should match thresholds
   - Can see repeats

3. **Level 3 Users**:
   - Should get 50/50 mix between diff=3 and diff=1,2
   - Should NEVER see previously answered questions
   - Check user_q exclusion works

4. **Level 4 Users**:
   - Should get hardest questions (diff=4 with <50% rate)
   - Mixed probabilities: 50/20/30
   - No repeats

5. **Edge Cases**:
   - User with all questions answered (Level 3/4) → should fall back to global
   - Empty difficulty buckets → should try fallbacks
   - Questions with numofanswers=0 → should be excluded from rate filters

#### Manual Testing Query
To check current question distribution:
```sql
SELECT 
    difficulty,
    COUNT(*) as total,
    SUM(CASE WHEN numofanswers > 0 THEN 1 ELSE 0 END) as with_answers,
    AVG(CASE WHEN numofanswers > 0 
        THEN numofcorrectanswers / numofanswers 
        ELSE NULL END) as avg_success_rate
FROM questions
GROUP BY difficulty;
```

---

### Migration Notes

#### No Schema Changes Required
The implementation uses existing tables:
- `questions` (id, difficulty, numofcorrectanswers, numofanswers)
- `users` (id, level)
- `user_q` (userid, questionid)

#### Backward Compatibility
- Function signature unchanged: `function getQuestion()`
- Returns same data structure
- Global variables used: `$db`, `$user_id`, `$chat_id`

---

### Summary of Key Improvements

✅ **Precise Level Logic**: Each level has distinct success rate thresholds  
✅ **Probability Mixing**: Levels 2-4 use weighted random selection  
✅ **Repeat Control**: Levels 1-2 allow repeats, 3-4 prevent them  
✅ **Division-by-Zero Safe**: All SQL queries protect against div/0  
✅ **SQL Injection Safe**: All user inputs are sanitized  
✅ **Graceful Fallbacks**: Multi-tier fallback prevents crashes  
✅ **Memory Safe**: Proper mysqli result cleanup  
✅ **Maintainable**: Helper functions for reusable SQL building  
✅ **Debuggable**: Optional query logging with labels  

---

### Questions Answered

**Q: How is probability logic implemented?**  
A: Using `rand(1, 100)` with threshold comparisons:
- `rand <= 50` = 50%
- `rand <= 70` = 20% (from 50 to 70)
- `rand > 70` = 30% (from 70 to 100)

**Q: How is division-by-zero avoided?**  
A: Every success rate filter includes `numofanswers > 0 AND (numofcorrectanswers/numofanswers) ...`

**Q: What if all buckets are empty?**  
A: Final fallback: `SELECT * FROM questions ORDER BY RAND() LIMIT 1` (any question)

**Q: Are there SQL injection risks?**  
A: No - `intval()` and `floatval()` used on all dynamic values before SQL injection.

**Q: Does this work with mysqli?**  
A: Yes - exclusively uses mysqli functions (`mysqli_query`, `mysqli_fetch_assoc`, `mysqli_free_result`)

---

## Deployment Checklist

- [ ] Backup current `bot_functions.php`
- [ ] Deploy new code to production
- [ ] Enable debug logging temporarily (optional)
- [ ] Test with users at each level (1-4)
- [ ] Monitor PHP error logs for issues
- [ ] Verify no division-by-zero errors
- [ ] Check query performance with `EXPLAIN`
- [ ] Consider adding recommended indexes
- [ ] Disable debug logging after verification

---

**Implementation Date**: January 2, 2026  
**Developer Notes**: All requirements from specification implemented exactly as written. Code is production-ready.

