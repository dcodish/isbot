<?php
// Polling Bot - Works without ngrok!
// This bot continuously checks for new messages and processes them

echo "🤖 Starting Bot in Polling Mode...\n";
echo "=====================================\n\n";

require_once __DIR__ . '/bank/config.php';
require_once __DIR__ . '/bank/bot_functions.php';
global $db, $API_URL;

echo "✅ Database: Connected\n";
if ($username_bot !== '') {
    echo "✅ Bot Username: @$username_bot\n";
}
echo "\n";

if (TOKEN === '') {
    echo "❌ BOT_TOKEN is not configured.\n";
    echo "Create bank/.env from bank/.env.example and add your Telegram bot token.\n";
    exit(1);
}

echo "Listening for messages... (Press Ctrl+C to stop)\n";
echo "=====================================\n\n";

$offset = 0;

while (true) {
    // Get updates from Telegram
    $url = $API_URL . "getUpdates?offset=$offset&timeout=30";
    $response = @file_get_contents($url);

    if ($response === false) {
        echo "⚠️  Connection error, retrying...\n";
        sleep(5);
        continue;
    }

    $updates = json_decode($response, true);

    if (!isset($updates['ok']) || !$updates['ok']) {
        echo "⚠️  API error, retrying...\n";
        sleep(5);
        continue;
    }

    if (isset($updates['result']) && count($updates['result']) > 0) {
        foreach ($updates['result'] as $update) {
            $offset = $update['update_id'] + 1;

            echo "\n📩 [" . date('H:i:s') . "] Received update #" . $update['update_id'] . "\n";

            // Process the update by simulating webhook call
            $jsonUpdate = json_encode($update);

            // Call index.php via HTTP to process it (same as webhook)
            $ch = curl_init('http://localhost:8000/bank/index.php');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonUpdate,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($jsonUpdate)
                ],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode == 200) {
                // Extract message info
                if (isset($update['message'])) {
                    $user_id = $update['message']['from']['id'];
                    $first_name = $update['message']['from']['first_name'];

                    // Check if it's a sticker
                    if (isset($update['message']['sticker'])) {
                        $sticker_file_id = $update['message']['sticker']['file_id'];
                        echo "   👤 User: $first_name ($user_id)\n";
                        echo "   🎨 Sticker: $sticker_file_id\n";
                        echo "   ✅ Processed successfully!\n";
                    } else {
                        $text = $update['message']['text'] ?? '';
                        echo "   👤 User: $first_name ($user_id)\n";
                        echo "   💬 Message: $text\n";
                        echo "   ✅ Processed successfully!\n";
                    }
                } elseif (isset($update['callback_query'])) {
                    $user_id = $update['callback_query']['from']['id'];
                    $first_name = $update['callback_query']['from']['first_name'];
                    $data = $update['callback_query']['data'];
                    echo "   👤 User: $first_name ($user_id)\n";
                    echo "   🔘 Callback: $data\n";
                    echo "   ✅ Processed successfully!\n";
                }
            } else {
                echo "   ❌ Error: HTTP $httpCode\n";
                if ($response) {
                    echo "   Response: " . substr($response, 0, 100) . "...\n";
                }
            }
        }
    }

    // Small delay to avoid hitting API limits
    usleep(100000); // 0.1 seconds
}

