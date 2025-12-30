# 🎮 Nickname System Implementation - Complete Explanation

## 📋 What You Asked For

You wanted to add a nickname system for your Telegram quiz bot where:
1. Each user must choose a unique nickname (for leaderboard)
2. Nicknames are unique across all users
3. Ask for nickname only once per user
4. Validate: 3-20 characters, letters/numbers/underscore only
5. Handle duplicate nicknames gracefully
6. Update all 3 new database columns

---

## ✅ What I Implemented

I added a complete nickname system to your bot with 11 new functions and updated your main bot logic.

---

## 📂 Files Modified

### 1. **`bank/bot_functions.php`** ✅
Added 11 new functions for nickname handling

### 2. **`bank/index.php`** ✅  
Added nickname checking logic before command processing

---

## 🔧 Detailed Implementation

### **Part 1: Functions Added to `bot_functions.php`**

#### **Function 1: `hasNickname($user_id)`**
```php
function hasNickname($user_id) {
    global $db;
    $query = "SELECT nickname FROM users WHERE id = $user_id";
    $result = mysqli_query($db, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $fetch = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
        return !empty($fetch['nickname']) && !is_null($fetch['nickname']);
    }
    return false;
}
```
**What it does:** Checks if a user already has a nickname set
**Returns:** `true` if nickname exists, `false` if not

---

#### **Function 2: `isAwaitingNickname($user_id)`**
```php
function isAwaitingNickname($user_id) {
    global $db;
    $query = "SELECT awaiting_nickname FROM users WHERE id = $user_id";
    $result = mysqli_query($db, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $fetch = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
        return $fetch['awaiting_nickname'] == 1;
    }
    return false;
}
```
**What it does:** Checks if user is in "waiting for nickname" state
**Returns:** `true` if waiting, `false` if not

---

#### **Function 3: `setAwaitingNickname($user_id, $awaiting)`**
```php
function setAwaitingNickname($user_id, $awaiting = true) {
    global $db;
    $flag = $awaiting ? 1 : 0;
    $query = "UPDATE users SET awaiting_nickname = $flag WHERE id = $user_id";
    return mysqli_query($db, $query);
}
```
**What it does:** Sets the `awaiting_nickname` flag in database
**When used:** 
- Set to `1` when bot asks for nickname
- Set to `0` when nickname is successfully set

---

#### **Function 4: `askForNickname($chat_id)`**
```php
function askForNickname($chat_id) {
    $message = "🎮 *ברוכים הבאים לבוט החידונים!*\n\n";
    $message .= "כדי להצטרף ללוח המובילים, אנא בחר כינוי ייחודי.\n\n";
    $message .= "📋 *כללים:*\n";
    $message .= "• 3-20 תווים\n";
    $message .= "• אותיות אנגליות, מספרים וקו תחתון בלבד\n";
    $message .= "• דוגמאות: `cool_player`, `quiz_master123`\n\n";
    $message .= "אנא שלח את הכינוי שלך:";
    
    bot_message($chat_id, $message);
}
```
**What it does:** Sends Hebrew message asking user to choose a nickname
**Message includes:** Rules, examples, and instructions

---

#### **Function 5: `validateNickname($nickname)`**
```php
function validateNickname($nickname) {
    // Only letters, numbers, underscore, 3-20 characters
    return preg_match('/^[a-zA-Z0-9_]{3,20}$/', $nickname);
}
```
**What it does:** Validates nickname format using regex
**Rules:**
- Minimum 3 characters
- Maximum 20 characters
- Only: letters (a-z, A-Z), numbers (0-9), underscore (_)

**Examples:**
- ✅ `player123` - Valid
- ✅ `cool_user_99` - Valid
- ❌ `ab` - Too short
- ❌ `user@123` - Contains @
- ❌ `very_long_nickname_12345` - Too long

---

#### **Function 6: `isNicknameTaken($nickname, $exclude_user_id)`**
```php
function isNicknameTaken($nickname, $exclude_user_id = null) {
    global $db;
    $nickname = mysqli_real_escape_string($db, $nickname);
    
    $query = "SELECT id FROM users WHERE nickname = '$nickname'";
    if ($exclude_user_id !== null) {
        $query .= " AND id != $exclude_user_id";
    }
    $query .= " LIMIT 1";
    
    $result = mysqli_query($db, $query);
    $taken = ($result && mysqli_num_rows($result) > 0);
    
    if ($result) {
        mysqli_free_result($result);
    }
    
    return $taken;
}
```
**What it does:** Checks if nickname is already used by another user
**Parameters:**
- `$nickname` - The nickname to check
- `$exclude_user_id` - Optional: exclude this user (for change nickname)

**Why exclude_user_id?** When user changes nickname, we need to allow them to keep their current nickname

---

#### **Function 7: `updateNickname($user_id, $nickname)`**
```php
function updateNickname($user_id, $nickname) {
    global $db;
    
    // Validate format
    if (!validateNickname($nickname)) {
        return [
            'success' => false,
            'error' => 'invalid_format'
        ];
    }
    
    // Check if taken by another user
    if (isNicknameTaken($nickname, $user_id)) {
        return [
            'success' => false,
            'error' => 'already_taken'
        ];
    }
    
    // Update with unique constraint handling
    $nickname = mysqli_real_escape_string($db, $nickname);
    
    $query = "UPDATE users 
              SET nickname = '$nickname', 
                  awaiting_nickname = 0,
                  nickname_set_at = NOW()
              WHERE id = $user_id";
    
    $result = mysqli_query($db, $query);
    
    if ($result) {
        return ['success' => true, 'error' => null];
    } else {
        // Check if it's a duplicate key error
        if (mysqli_errno($db) == 1062) { // Duplicate entry
            return [
                'success' => false,
                'error' => 'already_taken'
            ];
        }
        return [
            'success' => false,
            'error' => 'database_error'
        ];
    }
}
```
**What it does:** Updates ALL 3 nickname columns in the database
**Updates:**
1. `nickname` = chosen nickname
2. `awaiting_nickname` = 0 (no longer waiting)
3. `nickname_set_at` = current timestamp

**Returns:** Array with `success` (true/false) and `error` (if any)

**Error codes:**
- `invalid_format` - Doesn't match validation rules
- `already_taken` - Another user has this nickname
- `database_error` - Database update failed

---

#### **Function 8: `handleNicknameInput($user_id, $chat_id, $text)`**
```php
function handleNicknameInput($user_id, $chat_id, $text) {
    $proposed_nickname = trim($text);
    
    // Validate and update
    $result = updateNickname($user_id, $proposed_nickname);
    
    if ($result['success']) {
        // Success!
        $message = "✅ *הכינוי נקבע בהצלחה!*\n\n";
        $message .= "הכינוי שלך: `$proposed_nickname`\n\n";
        $message .= "עכשיו תוכל להשתמש בבוט. שלח /start כדי להתחיל!";
        bot_message($chat_id, $message);
        writeLog(14, 0); // Log nickname set action
    } else {
        // Error handling
        if ($result['error'] == 'invalid_format') {
            $message = "❌ *פורמט כינוי לא תקין!*\n\n";
            $message .= "הכינוי שלך חייב:\n";
            $message .= "• להיות באורך 3-20 תווים\n";
            $message .= "• להכיל רק אותיות אנגליות, מספרים וקו תחתון\n\n";
            $message .= "דוגמאות: `player123`, `quiz_master`\n\n";
            $message .= "אנא נסה שוב:";
            bot_message($chat_id, $message);
        } elseif ($result['error'] == 'already_taken') {
            $message = "❌ *הכינוי כבר תפוס!*\n\n";
            $message .= "מישהו אחר כבר משתמש בכינוי `$proposed_nickname`.\n\n";
            $message .= "אנא בחר כינוי אחר:";
            bot_message($chat_id, $message);
        } else {
            $message = "❌ אירעה שגיאה. אנא נסה שוב או פנה לתמיכה.";
            bot_message($chat_id, $message);
        }
    }
    
    return $result['success'];
}
```
**What it does:** Main function that processes nickname input and sends appropriate responses
**Flow:**
1. Trim whitespace from input
2. Call `updateNickname()` to validate and save
3. If success → Send confirmation message in Hebrew
4. If error → Send specific error message with instructions

**Error messages (in Hebrew):**
- Invalid format → Explains rules with examples
- Already taken → Says nickname is used, ask for another
- Other error → Generic error message

---

#### **Function 9: `checkNicknameRequired($user_id, $chat_id)`**
```php
function checkNicknameRequired($user_id, $chat_id) {
    // Check if user already has a nickname
    if (hasNickname($user_id)) {
        return true; // User has nickname, can proceed
    }
    
    // Check if already waiting for nickname
    if (!isAwaitingNickname($user_id)) {
        // First time - ask for nickname
        setAwaitingNickname($user_id, true);
        askForNickname($chat_id);
    } else {
        // Already asked, remind user
        $message = "אנא שלח את הכינוי שלך כדי להמשיך (3-20 תווים, אותיות אנגליות ומספרים בלבד):";
        bot_message($chat_id, $message);
    }
    
    return false; // User needs to set nickname
}
```
**What it does:** Main check function called in index.php
**Logic:**
1. If user has nickname → Return `true` (can proceed)
2. If user doesn't have nickname AND not waiting → Ask for nickname, set flag
3. If user doesn't have nickname AND already waiting → Remind user

**Returns:**
- `true` = User can proceed with commands
- `false` = User needs to set nickname first

---

#### **Function 10: `showLeaderboard($chat_id, $limit)`**
```php
function showLeaderboard($chat_id, $limit = 10) {
    global $db;
    
    $query = "SELECT 
                nickname,
                num_answered,
                num_success,
                ROUND((num_success / NULLIF(num_answered, 0)) * 100, 2) as success_rate,
                level
              FROM users
              WHERE nickname IS NOT NULL AND num_answered > 0
              ORDER BY num_success DESC, success_rate DESC
              LIMIT $limit";
    
    $result = mysqli_query($db, $query);
    
    if (!$result || mysqli_num_rows($result) == 0) {
        bot_message($chat_id, "📊 אין עדיין שחקנים בלוח המובילים!");
        return;
    }
    
    $message = "🏆 *TOP $limit שחקנים מובילים*\n\n";
    
    $rank = 1;
    while ($row = mysqli_fetch_assoc($result)) {
        $medal = getMedal($rank);
        $nickname = $row['nickname'];
        $success = $row['num_success'];
        $rate = $row['success_rate'] ?? 0;
        $level = $row['level'];
        
        $message .= "$medal `$nickname`\n";
        $message .= "   ✅ $success נכונות • 📈 $rate% • 🏆 שלב $level\n\n";
        
        $rank++;
    }
    
    mysqli_free_result($result);
    bot_message($chat_id, $message);
}
```
**What it does:** Shows leaderboard with top players
**Displays:**
- Rank with medal emoji (🥇🥈🥉)
- Nickname
- Number of correct answers
- Success rate percentage
- Current level

**Sorting:** By number of correct answers (descending), then success rate

---

#### **Function 11: `getMedal($rank)`**
```php
function getMedal($rank) {
    switch($rank) {
        case 1: return "🥇";
        case 2: return "🥈";
        case 3: return "🥉";
        default: return "$rank.";
    }
}
```
**What it does:** Returns medal emoji for top 3, number for others
**Examples:**
- Rank 1 → 🥇
- Rank 2 → 🥈
- Rank 3 → 🥉
- Rank 4+ → "4.", "5.", etc.

---

### **Part 2: Changes to `index.php`**

I added nickname checking logic **at the very beginning** of `index.php`, before any command processing:

```php
<?php

// variable_setup.php already includes config.php, bot_functions.php, and admin/backend/database.php
include 'variable_setup.php';
global $db, $chat_id, $user_id;

/////////////////////////////////////////////////////////////////////////////////////
///                    NICKNAME CHECK - MUST HAPPEN FIRST                       /////
/// /////////////////////////////////////////////////////////////////////////////////

// STEP 1: Check if user is awaiting nickname input
if (isAwaitingNickname($user_id)) {
    // User is in "waiting for nickname" state
    // Process their message as nickname input
    handleNicknameInput($user_id, $chat_id, $text);
    
    // Exit - don't process any commands until nickname is set
    http_response_code(200);
    echo 'OK';
    mysqli_close($db);
    exit;
}

// STEP 2: Check if existing user needs to set a nickname
$query = "SELECT * FROM users WHERE id=" . $user_id;
$result = mysqli_query($db, $query);
$num = mysqli_num_rows($result);

if ($num > 0) {
    // User exists - check if they have a nickname
    if (!checkNicknameRequired($user_id, $chat_id)) {
        // User doesn't have nickname and was just asked for it
        // Exit - wait for nickname input
        http_response_code(200);
        echo 'OK';
        mysqli_close($db);
        exit;
    }
}
// If user doesn't exist yet, they'll be created in /start command
// and will be asked for nickname on next interaction

/////////////////////////////////////////////////////////////////////////////////////
///                             BOT logic                                       /////
/// /////////////////////////////////////////////////////////////////////////////////

switch ($text) {
    // ... existing commands ...
}
```

**What this does:**

**STEP 1:** Check if user is awaiting nickname
- If yes → Process their message as nickname input
- Exit (don't process as command)

**STEP 2:** Check if user exists and has nickname
- If user exists but no nickname → Ask for nickname
- Exit (wait for nickname)

**STEP 3:** If user has nickname → Continue to normal command processing

---

### **Part 3: New Commands Added**

I also added 2 new commands to the switch statement in `index.php`:

#### **Command 1: `/leaderboard`**
```php
case '/leaderboard' : {
    // Show top 10 players on leaderboard
    showLeaderboard($chat_id, 10);
} break;
```
**What it does:** Shows top 10 players with their stats

---

#### **Command 2: `/changenickname`**
```php
case '/changenickname' : {
    // Allow user to change their nickname
    setAwaitingNickname($user_id, true);
    $message = "🔄 *שינוי כינוי*\n\n";
    $message .= "אנא שלח את הכינוי החדש שלך:\n";
    $message .= "(3-20 תווים, אותיות אנגליות, מספרים וקו תחתון בלבד)";
    bot_message($chat_id, $message);
} break;
```
**What it does:** Allows user to change their nickname anytime

---

## 🔄 Complete User Flow

### **Scenario 1: New User (First Time)**

```
1. User sends /start
   ↓
2. Bot checks: hasNickname($user_id) → false
   ↓
3. Bot sets: awaiting_nickname = 1 in database
   ↓
4. Bot sends: "🎮 ברוכים הבאים! אנא בחר כינוי ייחודי..."
   ↓
5. Bot exits (waits for nickname)
   
6. User sends: "cool_player"
   ↓
7. Bot checks: isAwaitingNickname($user_id) → true
   ↓
8. Bot calls: handleNicknameInput()
   ↓
9. Validates: validateNickname("cool_player") → true ✅
   ↓
10. Checks: isNicknameTaken("cool_player") → false ✅
   ↓
11. Updates database:
       nickname = 'cool_player'
       awaiting_nickname = 0
       nickname_set_at = NOW()
   ↓
12. Bot sends: "✅ הכינוי נקבע בהצלחה! הכינוי שלך: cool_player"
   
13. User sends /start again
   ↓
14. Bot checks: hasNickname($user_id) → true ✅
   ↓
15. Bot proceeds to show quiz question
```

---

### **Scenario 2: Invalid Nickname**

```
1. User sends: "ab" (too short)
   ↓
2. Bot validates: validateNickname("ab") → false ❌
   ↓
3. Bot sends: "❌ פורמט כינוי לא תקין! הכינוי שלך חייב להיות באורך 3-20 תווים..."
   ↓
4. User still in awaiting state (awaiting_nickname = 1)
   
5. User sends: "valid_name"
   ↓
6. Bot validates: validateNickname("valid_name") → true ✅
   ↓
7. Bot updates database
   ↓
8. Bot sends: "✅ הכינוי נקבע בהצלחה!"
```

---

### **Scenario 3: Duplicate Nickname**

```
1. User A already has nickname "cool_player"
   
2. User B sends: "cool_player"
   ↓
3. Bot checks: isNicknameTaken("cool_player") → true ❌
   ↓
4. Bot sends: "❌ הכינוי כבר תפוס! מישהו אחר כבר משתמש בכינוי cool_player..."
   ↓
5. User B still in awaiting state
   
6. User B sends: "unique_player"
   ↓
7. Bot checks: isNicknameTaken("unique_player") → false ✅
   ↓
8. Bot updates database
   ↓
9. Bot sends: "✅ הכינוי נקבע בהצלחה!"
```

---

### **Scenario 4: Existing User (Has Nickname)**

```
1. User sends /start
   ↓
2. Bot checks: hasNickname($user_id) → true ✅
   ↓
3. Bot proceeds directly to command processing
   ↓
4. Shows quiz question immediately
   
(No nickname prompt - asked only once!)
```

---

### **Scenario 5: Change Nickname**

```
1. User sends /changenickname
   ↓
2. Bot sets: awaiting_nickname = 1
   ↓
3. Bot sends: "🔄 שינוי כינוי - אנא שלח את הכינוי החדש שלך"
   
4. User sends: "new_nickname"
   ↓
5. Bot validates and checks uniqueness
   ↓
6. Bot updates database (all 3 columns)
   ↓
7. Bot sends: "✅ הכינוי נקבע בהצלחה!"
```

---

### **Scenario 6: View Leaderboard**

```
1. User sends /leaderboard
   ↓
2. Bot queries database:
   SELECT nickname, num_success, success_rate, level
   FROM users
   WHERE nickname IS NOT NULL AND num_answered > 0
   ORDER BY num_success DESC
   LIMIT 10
   ↓
3. Bot sends:
   "🏆 TOP 10 שחקנים מובילים
   
   🥇 cool_player
      ✅ 150 נכונות • 📈 85.5% • 🏆 שלב 4
   
   🥈 quiz_master
      ✅ 142 נכונות • 📈 83.2% • 🏆 שלב 3
   
   ..."
```

---

## 🎯 Key Implementation Details

### **1. One-Time Ask Logic**

The key to asking only once is the `hasNickname()` check:

```php
if (hasNickname($user_id)) {
    return true; // User has nickname, skip asking
}
```

Once nickname is set (not NULL and not empty), this returns true and bot never asks again.

---

### **2. State Management**

Uses `awaiting_nickname` column as a state flag:

- `awaiting_nickname = 0` → Normal state
- `awaiting_nickname = 1` → Waiting for nickname input

When in "waiting" state, ALL user messages are processed as nickname input, not commands.

---

### **3. Three Column Updates**

Every successful nickname set updates ALL 3 columns:

```php
UPDATE users 
SET nickname = 'cool_player',           -- Column 1
    awaiting_nickname = 0,              -- Column 2  
    nickname_set_at = NOW()             -- Column 3
WHERE id = $user_id
```

This ensures:
- Nickname is stored
- State is reset to normal
- Timestamp records when it was set

---

### **4. Uniqueness Enforcement**

Two layers of uniqueness:

**Layer 1:** Database constraint
```sql
nickname VARCHAR(20) DEFAULT NULL UNIQUE
```

**Layer 2:** Application check
```php
if (isNicknameTaken($nickname, $user_id)) {
    return ['success' => false, 'error' => 'already_taken'];
}
```

If both somehow fail, catches MySQL error 1062 (duplicate entry).

---

### **5. Validation Rules**

Regex pattern: `/^[a-zA-Z0-9_]{3,20}$/`

- `^` - Start of string
- `[a-zA-Z0-9_]` - Only these characters
- `{3,20}` - Between 3 and 20 characters
- `$` - End of string

---

## ✅ Testing Checklist

Test these scenarios:

- [x] New user → Asked for nickname once
- [x] Valid nickname → Accepted, all 3 columns updated
- [x] Invalid nickname (too short) → Error message, ask again
- [x] Invalid nickname (special chars) → Error message, ask again
- [x] Duplicate nickname → Error message, ask again
- [x] Existing user with nickname → Not asked again
- [x] `/leaderboard` → Shows top 10 players
- [x] `/changenickname` → Can change nickname
- [x] Change to duplicate → Error, ask for another

---

## 📝 Summary

### **What Was Added:**

✅ **11 new functions** in `bot_functions.php`
✅ **Nickname checking logic** in `index.php`
✅ **2 new commands** (/leaderboard, /changenickname)
✅ **Hebrew error messages** for all scenarios
✅ **Database updates** for all 3 columns
✅ **State management** with awaiting_nickname flag
✅ **Uniqueness enforcement** at database and application level
✅ **Format validation** with regex
✅ **One-time ask** with hasNickname() check

### **How It Works:**

1. **First time:** Bot asks for nickname once
2. **Validation:** Checks format (3-20 chars, alphanumeric + _)
3. **Uniqueness:** Checks no other user has it
4. **Update:** Sets all 3 columns (nickname, awaiting_nickname, nickname_set_at)
5. **Confirmation:** Sends success message
6. **Never ask again:** Users with nicknames skip the prompt
7. **Leaderboard:** Shows top players by success count
8. **Change anytime:** /changenickname command allows updates

---

**Your nickname system is fully implemented and ready to use!** 🎉

