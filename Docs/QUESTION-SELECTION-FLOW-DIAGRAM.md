# Question Selection Flow Diagram

## Level 1 Flow
```
┌─────────────┐
│   Level 1   │
│  (Beginner) │
└──────┬──────┘
       │
       ▼
┌──────────────────────────────┐
│ Difficulty = 1               │
│ Success Rate >= 85%          │
│ ✅ Repeats Allowed           │
└──────┬───────────────────────┘
       │
       ▼
    [QUESTION]
```

## Level 2 Flow
```
┌─────────────┐
│   Level 2   │
│(Intermediate)│
└──────┬──────┘
       │
       ▼
    rand(1-100)
       │
   ┌───┴────┐
   │        │
   ▼        ▼
 <=70     >70
 (70%)   (30%)
   │        │
   ▼        ▼
┌───────┐ ┌───────┐
│Diff=2 │ │Diff=1 │
│Rate   │ │Rate   │
│>=60%  │ │>=85%  │
└───┬───┘ └───┬───┘
    │         │
    └────┬────┘
         │
    [FALLBACK: Try other bucket]
         │
         ▼
      [QUESTION]
```

## Level 3 Flow
```
┌─────────────┐
│   Level 3   │
│ (Advanced)  │
│❌ No Repeats│
└──────┬──────┘
       │
       ▼
 Exclude user_q
       │
       ▼
    rand(1-100)
       │
   ┌───┴────┐
   │        │
   ▼        ▼
 <=50     >50
 (50%)   (50%)
   │        │
   ▼        ▼
┌───────┐ ┌──────────┐
│Diff=3 │ │Diff=1 OR │
│Rate   │ │Diff=2    │
│>=50%  │ │Mixed     │
└───┬───┘ └────┬─────┘
    │          │
    │    ┌─────┴─────┐
    │    ▼           ▼
    │  Diff=1     Diff=2
    │  >=85%      >=60%
    │    └─────┬─────┘
    │          │
    └────┬─────┘
         │
    [FALLBACK: Try other bucket]
         │
         ▼
      [QUESTION]
```

## Level 4 Flow
```
┌─────────────┐
│   Level 4   │
│   (Expert)  │
│❌ No Repeats│
└──────┬──────┘
       │
       ▼
 Exclude user_q
       │
       ▼
    rand(1-100)
       │
   ┌───┴───┬────────┐
   │       │        │
   ▼       ▼        ▼
 <=50   51-70     >70
 (50%)   (20%)   (30%)
   │       │        │
   ▼       ▼        ▼
┌──────┐┌──────┐┌──────────┐
│Diff=4││Diff=3││Diff=1/2  │
│Rate  ││Rate  ││Mixed     │
│<50%  ││>=50% ││          │
│      ││      ││          │
│Hard- ││Mid-  ││Easy/Mid  │
│est   ││Hard  ││Review    │
└──┬───┘└──┬───┘└────┬─────┘
   │       │         │
   └───────┴────┬────┘
                │
         [FALLBACK: Try remaining 2 buckets]
                │
                ▼
             [QUESTION]
```

## Success Rate Thresholds by Difficulty

```
Difficulty │ Level 1 │ Level 2 │ Level 3 │ Level 4
───────────┼─────────┼─────────┼─────────┼─────────
    1      │  ≥85%   │  ≥85%   │  ≥85%   │  ≥85%
    2      │   —     │  ≥60%   │  ≥60%   │  ≥60%
    3      │   —     │   —     │  ≥50%   │  ≥50%
    4      │   —     │   —     │   —     │  <50%
```

## Probability Distribution

### Level 2 Probabilities
```
Diff=2 (≥60%): ████████████████████████████████████████ 70%
Diff=1 (≥85%): █████████████████ 30%
```

### Level 3 Probabilities
```
Diff=3 (≥50%):    ██████████████████████████ 50%
Diff=1,2 (Mixed): ██████████████████████████ 50%
```

### Level 4 Probabilities
```
Diff=4 (<50%):    ██████████████████████████ 50%
Diff=3 (≥50%):    ██████████ 20%
Diff=1,2 (Mixed): ███████████████ 30%
```

## Repeat Logic Summary

| Level | Repeats Allowed? | Mechanism |
|-------|------------------|-----------|
| 1     | ✅ YES           | No exclusion |
| 2     | ✅ YES           | No exclusion |
| 3     | ❌ NO            | `NOT IN (SELECT questionid FROM user_q WHERE userid=?)` |
| 4     | ❌ NO            | `NOT IN (SELECT questionid FROM user_q WHERE userid=?)` |

## Global Fallback Chain

```
┌─────────────────────────┐
│ Selected Bucket Empty?  │
└───────────┬─────────────┘
            │
            ▼ YES
┌─────────────────────────┐
│ Try Other Buckets       │
│ (Same Level)            │
└───────────┬─────────────┘
            │
            ▼ ALL EMPTY
┌─────────────────────────┐
│ Default: Difficulty=1   │
│ (Any from easy pool)    │
└───────────┬─────────────┘
            │
            ▼ STILL EMPTY
┌─────────────────────────┐
│ FINAL: ANY Question     │
│ (ORDER BY RAND())       │
└───────────┬─────────────┘
            │
            ▼
        [GUARANTEED
         QUESTION]
```

## Division-by-Zero Protection

Every success rate query includes:
```
┌────────────────────────┐
│ Check numofanswers > 0 │
│         BEFORE         │
│   Calculating Rate     │
└────────────────────────┘
```

This ensures:
- No SQL errors
- Questions with 0 attempts excluded from rate-based filters
- Can still appear in global fallback

## SQL Injection Prevention

```
User Input ($user_id)
       │
       ▼
  intval($user_id)  ← Converts to integer
       │
       ▼
 Safe for SQL Query
```

All parameters sanitized:
- `intval()` for IDs
- `floatval()` for thresholds
- No raw string interpolation

## Example SQL Queries Generated

### Level 1
```sql
SELECT * FROM questions 
WHERE difficulty = 1 
  AND numofanswers > 0 
  AND (numofcorrectanswers / numofanswers) >= 0.85 
ORDER BY RAND() LIMIT 1
```

### Level 3 (Mixed Bucket)
```sql
SELECT * FROM questions 
WHERE (
    (difficulty = 1 AND numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.85)
    OR
    (difficulty = 2 AND numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.6)
)
AND id NOT IN (SELECT questionid FROM user_q WHERE userid = 1234)
ORDER BY RAND() LIMIT 1
```

### Level 4 (Hardest Bucket)
```sql
SELECT * FROM questions 
WHERE difficulty = 4 
  AND numofanswers > 0 
  AND (numofcorrectanswers / numofanswers) < 0.5
AND id NOT IN (SELECT questionid FROM user_q WHERE userid = 1234)
ORDER BY RAND() LIMIT 1
```

---

**Diagram Legend:**
- `[ ]` = Data/Result
- `┌─┐` = Process/Decision
- `→ ▼` = Flow direction
- `█` = Probability bar (each █ ≈ 5%)
- `✅` = Enabled
- `❌` = Disabled

**Color Coding (if viewed in rendered Markdown):**
- Green = Success/Allowed
- Red = Restricted/Not Allowed
- Blue = Process/Logic
- Yellow = Fallback/Warning

