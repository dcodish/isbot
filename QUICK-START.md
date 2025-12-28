# 🚀 Telegram Quiz Bot - Quick Start Guide

**Bot**: @ISQ_devA_bot | **Database**: bot (MySQL) | **Mode**: Polling (Development)

---

## 📋 Prerequisites

- ✅ PHP installed (with mysqli extension)
- ✅ MySQL running (localhost, database: `bot`)
- ✅ Composer dependencies installed (`bank/vendor/`)

---

## 🚀 START BOT (2 Simple Steps)

### Step 1: Start PHP Server

**Run in terminal:**
```powershell
cd C:\Users\ASUS\PhpstormProjects\test
php -S localhost:8000
```

✅ **Keep this terminal window open!**

⚠️ **Important**: Run from `test/` directory, NOT `test/bank/`

---

### Step 2: Start Polling Bot

**Run in NEW terminal:**
```powershell
cd C:\Users\ASUS\PhpstormProjects\test
php bot-polling.php
```

**You'll see:**
```
🤖 Starting Bot in Polling Mode...
✅ Bot Token: 8210054669:AAGiinrx5q8Yoqgv6jheae6XGCgUf_5d4dM
✅ Database: Connected
✅ Bot Username: @ISQ_devA_bot

Listening for messages... (Press Ctrl+C to stop)
```

✅ **Bot is now running!**

---

## 📱 TEST YOUR BOT

1. Open **Telegram** (mobile or desktop)
2. Search: **`@ISQ_devA_bot`**
3. Click **START** or send: **`/start`**
4. **Bot responds with a quiz question!** 🎉

**In polling terminal you'll see:**
```
📩 [18:xx:xx] Received update #194533557
   👤 User: Adi (1671626997)
   💬 Message: /start
   ✅ Processed successfully!
```

---

## 🎮 Bot Commands

| Command | Description |
|---------|-------------|
| `/start` | Get a new quiz question |
| `/stat` | View your statistics (answered, success rate) |
| `/level` | Check your current level and explanation |
| `/clearstat` | Reset all your progress |

---

## 📊 How It Works

```
User sends /start
    ↓
bot-polling.php checks Telegram API for new messages
    ↓
Gets update from Telegram
    ↓
Sends to index.php (localhost:8000/bank/index.php)
    ↓
variable_setup.php parses message
    ↓
index.php processes /start command
    ↓
Checks user in database
    ↓
Calls showNextQ() → queries 531 questions
    ↓
Sends question with 4 answer buttons
    ↓
User receives question!
```

---

## 🔧 Configuration

### Database (MySQL)
- **Host**: localhost
- **User**: root
- **Password**: 5400
- **Database**: bot
- **Tables**: users (1,555), questions (531), user_q, user_survey

Configuration file: `bank/config.php`

### Bot Settings
- **Token**: 8210054669:AAGiinrx5q8Yoqgv6jheae6XGCgUf_5d4dM
- **Username**: @ISQ_devA_bot
- **Bot ID**: 8210054669
- **Admin User**: 1671626997
- **Debug Mode**: ON (logs to `bank/result.txt`)

---

## 📁 Project Structure

```
test/
├── bot-polling.php              # Polling bot (checks for messages)
└── bank/
    ├── index.php                # Main bot logic (/start, /stat, etc.)
    ├── variable_setup.php       # Parses incoming messages
    ├── config.php               # Configuration (DB, token)
    ├── bot_functions.php        # Helper functions
    ├── result.txt               # Debug log (incoming messages)
    ├── admin/                   # Admin panel
    │   └── backend/
    │       └── database.php     # DB connection
    └── vendor/                  # PHP dependencies
```

---

## 🐛 Troubleshooting

### Bot Not Responding?

**Check 1: PHP Server Running?**
```powershell
netstat -ano | findstr :8000
# Should show process on port 8000
```

**Check 2: Polling Bot Running?**
Look for terminal with "Listening for messages..."

**Check 3: Database Connected?**
```powershell
php -r "try { mysqli_connect('localhost','root','5400','bot'); echo 'Connected'; } catch(Exception $e) { echo 'Failed: '.$e->getMessage(); }"
```

**Check 4: View Logs**
Check `bank/result.txt` for incoming messages

**Check 5: Port Already in Use?**
```powershell
# Find process using port 8000
netstat -ano | findstr :8000

# Kill it
taskkill /F /PID <process_id>
```

---

## 🔄 Stop/Restart Bot

### To Stop:
- Press **Ctrl+C** in polling bot terminal
- Press **Ctrl+C** in PHP server terminal

### To Restart:
1. Stop both terminals (Ctrl+C)
2. Restart PHP server: `php -S localhost:8000`
3. Restart polling bot: `php bot-polling.php`

---

## 📝 Development Notes

### Why Polling Instead of Webhook?
- ✅ No ngrok needed
- ✅ No public URL required
- ✅ No webhook "400 Bad Request" errors
- ✅ Perfect for local development
- ✅ Simple to start/stop

### Debug Mode
All incoming messages are logged to `bank/result.txt` when DEBUG is ON

### Testing Changes
After changing code:
1. Stop polling bot (Ctrl+C)
2. Restart it: `php bot-polling.php`
3. PHP server doesn't need restart (unless config changed)

---

## 🎯 Quick Reference

### Start Everything:
```powershell
# Terminal 1:
cd C:\Users\ASUS\PhpstormProjects\test
php -S localhost:8000

# Terminal 2 (NEW):
cd C:\Users\ASUS\PhpstormProjects\test
php bot-polling.php
```

### Test Bot:
Message **@ISQ_devA_bot** with `/start`

### Check if Working:
- Look at polling terminal for "Processed successfully"
- Check `bank/result.txt` for logged messages
- See response in Telegram

---

## ✅ Quick Checklist

Before messaging the bot:
- [ ] PHP server running on localhost:8000
- [ ] Polling bot running (shows "Listening for messages...")
- [ ] MySQL database `bot` accessible
- [ ] Telegram app open
- [ ] Ready to send `/start` to @ISQ_devA_bot

---

## 🎉 You're Ready!

Your Telegram quiz bot is fully configured and ready to use!

**Just run the 2 commands above and message the bot!** 🚀

---

**Last Updated**: December 27, 2025
**Status**: ✅ Verified Working

