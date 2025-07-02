<?php
// FILE: functions.php
// Contains all reusable bot functions.

define('STATE_FILE', 'user_states.json');
define('PRODUCTS_FILE', 'products.json');

// ===================================================================
//  STATE & DATA MANAGEMENT FUNCTIONS
// ===================================================================
function readJsonFile($filename) { if (!file_exists($filename)) return []; $json = file_get_contents($filename); return json_decode($json, true) ?: []; }
function writeJsonFile($filename, $data) { file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT)); }
function setUserState($user_id, $state) { $states = readJsonFile(STATE_FILE); $states[$user_id] = $state; writeJsonFile(STATE_FILE, $states); }
function getUserState($user_id) { $states = readJsonFile(STATE_FILE); return $states[$user_id] ?? null; }
function clearUserState($user_id) { $states = readJsonFile(STATE_FILE); if (isset($states[$user_id])) { unset($states[$user_id]); writeJsonFile(STATE_FILE, $states); } }
$products = readJsonFile(PRODUCTS_FILE); // Load all products into a global variable

// ===================================================================
//  TELEGRAM API FUNCTIONS
// ===================================================================
function bot($method, $data = []) { $url = "https://api.telegram.org/bot" . API_TOKEN . "/" . $method; $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $data); $res = curl_exec($ch); curl_close($ch); return json_decode($res); }
function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = 'HTML') { bot('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageText($chat_id, $message_id, $text, $reply_markup = null, $parse_mode = 'HTML') { bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageCaption($chat_id, $message_id, $caption, $reply_markup = null, $parse_mode = 'HTML') { bot('editMessageCaption', ['chat_id' => $chat_id, 'message_id' => $message_id, 'caption' => $caption, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageReplyMarkup($chat_id, $message_id, $reply_markup = null) { bot('editMessageReplyMarkup', ['chat_id' => $chat_id, 'message_id' => $message_id, 'reply_markup' => $reply_markup]); }
function answerCallbackQuery($callback_query_id) { bot('answerCallbackQuery', ['callback_query_id' => $callback_query_id]); }
function forwardPhotoToAdmin($file_id, $caption, $original_user_id) { $admin_id = ADMINS[0]; $approval_keyboard = json_encode(['inline_keyboard' => [[['text' => "✅ Accept", 'callback_data' => "accept_payment_$original_user_id"], ['text' => "❌ Reject", 'callback_data' => "reject_payment_$original_user_id"]]]]); bot('sendPhoto', ['chat_id' => $admin_id, 'photo' => $file_id, 'caption' => $caption, 'parse_mode' => 'Markdown', 'reply_markup' => $approval_keyboard]); }
function generateCategoryKeyboard($category_key) { global $products; $keyboard = ['inline_keyboard' => []]; $category_products = $products[$category_key] ?? []; foreach ($category_products as $id => $details) { $keyboard['inline_keyboard'][] = [['text' => "{$details['name']} - \${$details['price']}", 'callback_data' => "{$category_key}_{$id}"]]; } $keyboard['inline_keyboard'][] = [['text' => '« Back to Main Menu', 'callback_data' => 'back_to_main']]; return json_encode($keyboard); }

// ===================================================================
//  CALLBACK QUERY PROCESSOR
// ===================================================================

function processCallbackQuery($callback_query) {
    global $mainMenuKeyboard, $adminMenuKeyboard, $products;

    $chat_id = $callback_query->message->chat->id;
    $user_id = $callback_query->from->id;
    $data = $callback_query->data;
    $message_id = $callback_query->message->message_id;

    answerCallbackQuery($callback_query->id);

    // --- SUPPORT FLOW ---
    if ($data === 'support') {
        $text = "Are you sure you want to contact support? This will send your next message to the admins.";
        $keyboard = json_encode(['inline_keyboard' => [
            [['text' => '✅ Yes, Continue', 'callback_data' => 'support_confirm']],
            [['text' => '❌ No, Cancel', 'callback_data' => 'back_to_main']]
        ]]);
        editMessageText($chat_id, $message_id, $text, $keyboard);
    }
    elseif ($data === 'support_confirm') {
        // **MODIFIED**: Store the message_id so we can edit it later
        setUserState($user_id, ['status' => 'awaiting_support_message', 'message_id' => $message_id]);
        $text = "Please type your message now. It will be forwarded to our support team.";
        $keyboard = json_encode(['inline_keyboard' => [[['text' => 'Cancel Support Request', 'callback_data' => 'back_to_main']]]]);
        editMessageText($chat_id, $message_id, $text, $keyboard);
    }
    // --- Admin Panel Flow ---
    elseif (strpos($data, 'admin_') === 0) {
        if (!in_array($user_id, ADMINS)) return;

        if ($data === 'admin_panel') {
            $keyboard = json_encode(['inline_keyboard' => [[['text' => '📦 Manage Products', 'callback_data' => 'admin_manage_products']], [['text' => '« Back to Main Menu', 'callback_data' => 'back_to_main']]]]);
            editMessageText($chat_id, $message_id, "Welcome to the Admin Panel.", $keyboard);
        } elseif ($data === 'admin_manage_products') {
            $keyboard = json_encode(['inline_keyboard' => [[['text' => 'Spotify', 'callback_data' => 'admin_manage_cat_spotify_plan']], [['text' => 'SSH', 'callback_data' => 'admin_manage_cat_ssh_plan']], [['text' => 'V2Ray', 'callback_data' => 'admin_manage_cat_v2ray_plan']], [['text' => '« Back', 'callback_data' => 'admin_panel']]]]);
            editMessageText($chat_id, $message_id, "Select a category to manage:", $keyboard);
        } elseif (strpos($data, 'admin_manage_cat_') === 0) {
            $category_key = str_replace('admin_manage_cat_', '', $data);
            $category_name = ucfirst(str_replace('_plan', '', $category_key));
            $keyboard = ['inline_keyboard' => []];
            $category_products = $products[$category_key] ?? [];
            foreach ($category_products as $id => $details) { $keyboard['inline_keyboard'][] = [['text' => "❌ Remove: {$details['name']}", 'callback_data' => "admin_remove_{$category_key}_{$id}"]]; }
            $keyboard['inline_keyboard'][] = [['text' => '➕ Add New Product', 'callback_data' => "admin_add_{$category_key}"]];
            $keyboard['inline_keyboard'][] = [['text' => '« Back to Categories', 'callback_data' => 'admin_manage_products']];
            editMessageText($chat_id, $message_id, "Managing <b>$category_name</b> products:", json_encode($keyboard));
        } elseif (strpos($data, 'admin_add_') === 0) {
            $category_key = str_replace('admin_add_', '', $data);
            setUserState($user_id, ['status' => 'admin_adding_name', 'category' => $category_key]);
            editMessageText($chat_id, $message_id, "Enter the name for the new product:");
        } elseif (preg_match('/^admin_remove_(.+)_(.+)$/', $data, $matches)) {
            $category_key = $matches[1];
            $product_id = $matches[2];
            $all_products = readJsonFile(PRODUCTS_FILE);
            unset($all_products[$category_key][$product_id]);
            writeJsonFile(PRODUCTS_FILE, $all_products);
            $products = $all_products;
            $category_name = ucfirst(str_replace('_plan', '', $category_key));
            $keyboard = ['inline_keyboard' => []];
            foreach ($all_products[$category_key] as $id => $details) { $keyboard['inline_keyboard'][] = [['text' => "❌ Remove: {$details['name']}", 'callback_data' => "admin_remove_{$category_key}_{$id}"]]; }
            $keyboard['inline_keyboard'][] = [['text' => '➕ Add New Product', 'callback_data' => "admin_add_{$category_key}"]];
            $keyboard['inline_keyboard'][] = [['text' => '« Back to Categories', 'callback_data' => 'admin_manage_products']];
            editMessageText($chat_id, $message_id, "Product removed. Managing <b>$category_name</b> products:", json_encode($keyboard));
        }
    }
    // --- Admin Accept/Reject Payment ---
    elseif (preg_match('/^(accept|reject)_payment_(\d+)$/', $data, $matches)) {
        if (!in_array($user_id, ADMINS)) return;
        
        $action = $matches[1];
        $customer_id = $matches[2];

        if ($action === 'accept') {
            sendMessage($customer_id, "🎉 Your payment has been approved! Your product/service is now active.");
            $original_caption = $callback_query->message->caption;
            $new_caption = $original_caption . "\n\n**Decision:** ✅ Payment Approved\n\nTo talk to this user, send: `/s{$customer_id}`";
            editMessageCaption($chat_id, $message_id, $new_caption, null, 'Markdown');
        } else { // reject
            sendMessage($customer_id, "⚠️ Your payment has been rejected. Please contact support for assistance.");
            $original_caption = $callback_query->message->caption;
            $new_caption = $original_caption . "\n\n**Decision:** ❌ Payment Rejected";
            editMessageCaption($chat_id, $message_id, $new_caption, null, 'HTML');
        }
        clearUserState($customer_id);
    }
    // --- User purchasing flow: Category selection ---
    elseif ($data === 'buy_spotify' || $data === 'buy_ssh' || $data === 'buy_v2ray') {
        $category_map = ['buy_spotify' => 'spotify_plan', 'buy_ssh' => 'ssh_plan', 'buy_v2ray' => 'v2ray_plan'];
        $category_key = $category_map[$data];
        $category_name = ucfirst(str_replace('_plan', '', $category_key));
        $keyboard = generateCategoryKeyboard($category_key);
        editMessageText($chat_id, $message_id, "Please select a $category_name plan:", $keyboard);
    }
    // --- User purchasing flow: Plan selection ---
    elseif (preg_match('/^(spotify|ssh|v2ray)_plan_(.+)$/', $data, $matches)) {
        $product_type = $matches[1] . '_plan';
        $product_id   = $matches[2];
        $category     = $matches[1];

        if (isset($products[$product_type][$product_id])) {
            $product = $products[$product_type][$product_id];
            $plan_info = "<b>Product Details</b>\n\n▪️ **Product:** {$product['name']}\n▪️ **Price:** \${$product['price']}\n\nDo you want to purchase this plan?";
            $keyboard = json_encode(['inline_keyboard' => [[['text' => "✅ Buy", 'callback_data' => "confirm_buy_{$product_type}_{$product_id}"]], [['text' => "« Back", 'callback_data' => "buy_{$category}"]]]]);
            editMessageText($chat_id, $message_id, $plan_info, $keyboard);
        }
    }
    // --- User purchasing flow: Final confirmation ---
    elseif (preg_match('/^confirm_buy_(spotify|ssh|v2ray)_plan_(.+)$/', $data, $matches)) {
        $product_type = $matches[1] . '_plan';
        $product_id   = $matches[2];

        if (isset($products[$product_type][$product_id])) {
            $product = $products[$product_type][$product_id];
            setUserState($user_id, ['status' => 'awaiting_receipt', 'message_id' => $message_id, 'product_name' => $product['name'], 'price' => $product['price']]);
            $payment_info_text = "Please transfer **$$product[price]** to the card below.\n\nCard Number: `6666-1111-6666-1111`\nCard Holder: `Ali Azad`\n\nAfter payment, send the screenshot of your receipt to this chat.";
            $keyboard = json_encode(['inline_keyboard' => [[['text' => 'Cancel Purchase', 'callback_data' => 'back_to_main']]]]);
            editMessageText($chat_id, $message_id, $payment_info_text, $keyboard, 'Markdown');
        }
    }
    // --- Static navigation ---
    elseif ($data === 'back_to_main') {
        $welcome_text = "Welcome back to the main menu.";
        $keyboard = in_array($user_id, ADMINS) ? $adminMenuKeyboard : $mainMenuKeyboard;
        editMessageText($chat_id, $message_id, $welcome_text, $keyboard);
    }
}
