<?php
// FILE: functions.php
// Contains all reusable bot functions.

define('STATE_FILE', 'user_states.json');
define('PRODUCTS_FILE', 'products.json');
define('USER_PURCHASES_FILE', 'user_purchases.json');
define('USER_DATA_FILE', 'user_data.json');
define('BOT_CONFIG_DATA_FILE', 'bot_config_data.json');

// ===================================================================
//  STATE & DATA MANAGEMENT FUNCTIONS
// ===================================================================
function readJsonFile($filename) { if (!file_exists($filename)) return []; $json = file_get_contents($filename); return json_decode($json, true) ?: []; }
function writeJsonFile($filename, $data) { file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); }
function setUserState($user_id, $state) { $states = readJsonFile(STATE_FILE); $states[$user_id] = $state; writeJsonFile(STATE_FILE, $states); }
function getUserState($user_id) { $states = readJsonFile(STATE_FILE); return $states[$user_id] ?? null; }
function clearUserState($user_id) { $states = readJsonFile(STATE_FILE); if (isset($states[$user_id])) { unset($states[$user_id]); writeJsonFile(STATE_FILE, $states); } }

// --- Bot Config Data Functions ---
function getBotConfig() { return readJsonFile(BOT_CONFIG_DATA_FILE); }
function saveBotConfig($config_data) { writeJsonFile(BOT_CONFIG_DATA_FILE, $config_data); }
function getAdminIds() { $config = getBotConfig(); return $config['admins'] ?? []; }
function getPaymentDetails() { $config = getBotConfig(); return ['card_holder' => $config['payment_card_holder'] ?? 'Not Set', 'card_number' => $config['payment_card_number'] ?? 'Not Set']; }
function updatePaymentDetails($new_holder, $new_number) { $config = getBotConfig(); if ($new_holder !== null) { $config['payment_card_holder'] = $new_holder; } if ($new_number !== null) { $config['payment_card_number'] = $new_number; } saveBotConfig($config); }
function addAdmin($user_id) { if (!is_numeric($user_id)) return false; $user_id = (int) $user_id; $config = getBotConfig(); if (!in_array($user_id, ($config['admins'] ?? []))) { $config['admins'][] = $user_id; saveBotConfig($config); return true; } return false; }
function removeAdmin($user_id) { if (!is_numeric($user_id)) return false; $user_id = (int) $user_id; $config = getBotConfig(); $admins = $config['admins'] ?? []; $initial_count = count($admins); $config['admins'] = array_values(array_filter($admins, function($admin) use ($user_id) { return $admin !== $user_id; })); if (count($config['admins']) < $initial_count) { saveBotConfig($config); return true; } return false; }

// --- User Data Functions ---
function getUserData($user_id) { $all_user_data = readJsonFile(USER_DATA_FILE); if (isset($all_user_data[$user_id])) { return $all_user_data[$user_id]; } return ['balance' => 0, 'is_banned' => false]; }
function updateUserData($user_id, $data) { $all_user_data = readJsonFile(USER_DATA_FILE); $all_user_data[$user_id] = $data; writeJsonFile(USER_DATA_FILE, $all_user_data); }
function banUser($user_id) { $user_data = getUserData($user_id); $user_data['is_banned'] = true; updateUserData($user_id, $user_data); }
function unbanUser($user_id) { $user_data = getUserData($user_id); $user_data['is_banned'] = false; updateUserData($user_id, $user_data); }
function addUserBalance($user_id, $amount) { if (!is_numeric($amount) || $amount < 0) return false; $user_data = getUserData($user_id); $user_data['balance'] = ($user_data['balance'] ?? 0) + (float)$amount; updateUserData($user_id, $user_data); return true; }

// --- User Purchase and Product Functions ---
function recordPurchase($user_id, $product_name, $price) { $purchases = readJsonFile(USER_PURCHASES_FILE); $new_purchase = ['product_name' => $product_name, 'price' => $price, 'date' => date('Y-m-d H:i:s')]; if (!isset($purchases[$user_id])) { $purchases[$user_id] = []; } $purchases[$user_id][] = $new_purchase; writeJsonFile(USER_PURCHASES_FILE, $purchases); }
function getProductDetails($category_key, $product_id) { global $products; if (empty($products)) { $products = readJsonFile(PRODUCTS_FILE); } return $products[$category_key][$product_id] ?? null; }
function updateProductDetails($category_key, $product_id, $details) { global $products; if (empty($products)) { $products = readJsonFile(PRODUCTS_FILE); } if (isset($products[$category_key][$product_id])) { $products[$category_key][$product_id] = $details; writeJsonFile(PRODUCTS_FILE, $products); return true; } return false; }
function addInstantProductItem($category_key, $product_id, $item_content) { global $products; if (empty($products)) { $products = readJsonFile(PRODUCTS_FILE); } if (isset($products[$category_key][$product_id]) && ($products[$category_key][$product_id]['type'] ?? 'manual') === 'instant') { if (!isset($products[$category_key][$product_id]['items']) || !is_array($products[$category_key][$product_id]['items'])) { $products[$category_key][$product_id]['items'] = []; } $products[$category_key][$product_id]['items'][] = $item_content; writeJsonFile(PRODUCTS_FILE, $products); return true; } return false; }
function getAndRemoveInstantProductItem($category_key, $product_id) { global $products; if (empty($products)) { $products = readJsonFile(PRODUCTS_FILE); } if (isset($products[$category_key][$product_id]) && ($products[$category_key][$product_id]['type'] ?? 'manual') === 'instant' && !empty($products[$category_key][$product_id]['items']) && is_array($products[$category_key][$product_id]['items'])) { $item = array_shift($products[$category_key][$product_id]['items']); writeJsonFile(PRODUCTS_FILE, $products); return $item; } return null; }

function promptForProductType($chat_id, $admin_user_id, $category_key, $product_name_context) {
    $type_keyboard = ['inline_keyboard' => [
        [['text' => '📦 Instant Delivery', 'callback_data' => CALLBACK_ADMIN_SET_PROD_TYPE_INSTANT]],
        [['text' => '👤 Manual Delivery', 'callback_data' => CALLBACK_ADMIN_SET_PROD_TYPE_MANUAL]],
        [['text' => '« Cancel', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]]
    ]];
    sendMessage($chat_id, "Product: '{$product_name_context}'.\nSelect delivery type:", json_encode($type_keyboard));
}

$products = readJsonFile(PRODUCTS_FILE); // Global products loaded once

// --- BOT STATS FUNCTION ---
function generateBotStatsText() {
    $stats_text = "📊 <b>Bot Statistics</b> 📊\n\n";
    $products_data = readJsonFile(PRODUCTS_FILE);
    $total_products = 0;
    $products_per_category_lines = [];
    if (!empty($products_data)) {
        foreach ($products_data as $category_key => $category_products) {
            if(is_array($category_products)){
                $count = count($category_products);
                $total_products += $count;
                $category_display_name = ucfirst(str_replace('_', ' ', $category_key));
                $products_per_category_lines[] = "  - " . htmlspecialchars($category_display_name) . ": " . $count . " products";
            }
        }
    }
    $stats_text .= "📦 <b>Products:</b>\n";
    $stats_text .= "▪️ Total Products: " . $total_products . "\n";
    if (!empty($products_per_category_lines)) {
        $stats_text .= "▪️ Products per Category:\n" . implode("\n", $products_per_category_lines) . "\n";
    } else { $stats_text .= "▪️ No products found in any category.\n"; }
    $stats_text .= "\n";

    $user_data_all = readJsonFile(USER_DATA_FILE);
    $total_users = 0; $banned_users_count = 0;
    if (!empty($user_data_all) && is_array($user_data_all)) {
        $total_users = count($user_data_all);
        foreach ($user_data_all as $data) { // Value is $data, key is $user_id
            if (isset($data['is_banned']) && $data['is_banned'] === true) { $banned_users_count++; }
        }
    }
    $stats_text .= "👤 <b>Users:</b>\n";
    $stats_text .= "▪️ Total Users (with data entries): " . $total_users . "\n";
    $stats_text .= "▪️ Banned Users: " . $banned_users_count . "\n";
    $stats_text .= "\n";

    $user_purchases_all = readJsonFile(USER_PURCHASES_FILE);
    $total_purchases_count = 0; $total_sales_volume = 0.0; $manual_additions_count = 0;
    if (!empty($user_purchases_all) && is_array($user_purchases_all)) {
        foreach ($user_purchases_all as $purchases) { // Value is $purchases, key is $user_id
            if (is_array($purchases)) {
                $total_purchases_count += count($purchases);
                foreach ($purchases as $purchase) {
                    if (isset($purchase['price'])) {
                        if (is_numeric($purchase['price'])) { $total_sales_volume += (float)$purchase['price']; }
                        elseif (strtolower(trim($purchase['price'])) === 'manually added') { $manual_additions_count++; }
                    }
                }
            }
        }
    }
    $stats_text .= "💳 <b>Purchases & Sales:</b>\n";
    $stats_text .= "▪️ Total Purchase Records: " . $total_purchases_count . "\n";
    $stats_text .= "▪️ Total Sales Volume (from numeric prices): $" . number_format($total_sales_volume, 2) . "\n";
    if ($manual_additions_count > 0) { $stats_text .= "▪️ Manually Added Items (via /addprod): " . $manual_additions_count . "\n"; }
    return $stats_text;
}
// --- END BOT STATS FUNCTION ---

// ===================================================================
//  TELEGRAM API FUNCTIONS
// ===================================================================
function bot($method, $data = []) { $url = "https://api.telegram.org/bot" . API_TOKEN . "/" . $method; $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $data); $res = curl_exec($ch); curl_close($ch); return json_decode($res); }
function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = 'HTML') { bot('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageText($chat_id, $message_id, $text, $reply_markup = null, $parse_mode = 'HTML') { bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageCaption($chat_id, $message_id, $caption, $reply_markup = null, $parse_mode = 'HTML') { bot('editMessageCaption', ['chat_id' => $chat_id, 'message_id' => $message_id, 'caption' => $caption, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageReplyMarkup($chat_id, $message_id, $reply_markup = null) { bot('editMessageReplyMarkup', ['chat_id' => $chat_id, 'message_id' => $message_id, 'reply_markup' => $reply_markup]); }
function answerCallbackQuery($callback_query_id) { bot('answerCallbackQuery', ['callback_query_id' => $callback_query_id]); }
function forwardPhotoToAdmin($file_id, $caption, $original_user_id) {
    $admin_ids = getAdminIds();
    if(empty($admin_ids)) return;
    $admin_id = $admin_ids[0];
    $approval_keyboard = json_encode(['inline_keyboard' => [
        [['text' => "✅ Accept", 'callback_data' => CALLBACK_ACCEPT_PAYMENT_PREFIX . $original_user_id],
         ['text' => "❌ Reject", 'callback_data' => CALLBACK_REJECT_PAYMENT_PREFIX . $original_user_id]]
    ]]);
    bot('sendPhoto', ['chat_id' => $admin_id, 'photo' => $file_id, 'caption' => $caption, 'parse_mode' => 'Markdown', 'reply_markup' => $approval_keyboard]);
}
function generateCategoryKeyboard($category_key) {
    global $products;
    $keyboard = ['inline_keyboard' => []];
    $category_products = $products[$category_key] ?? [];
    foreach ($category_products as $id => $details) {
        $keyboard['inline_keyboard'][] = [['text' => "{$details['name']} - \${$details['price']}", 'callback_data' => "{$category_key}_{$id}"]];
    }
    $keyboard['inline_keyboard'][] = [['text' => '« Back to Main Menu', 'callback_data' => CALLBACK_BACK_TO_MAIN]];
    return json_encode($keyboard);
}

// ===================================================================
//  CALLBACK QUERY PROCESSOR
// ===================================================================
function processCallbackQuery($callback_query) {
    global $mainMenuKeyboard, $adminMenuKeyboard, $products; // $products is already loaded globally
    $chat_id = $callback_query->message->chat->id;
    $user_id = $callback_query->from->id;
    $data = $callback_query->data;
    $message_id = $callback_query->message->message_id;
    $is_admin = in_array($user_id, getAdminIds());

    answerCallbackQuery($callback_query->id);

    $user_specific_data = getUserData($user_id);
    if ($user_specific_data['is_banned']) {
        sendMessage($chat_id, "⚠️ You are banned from using this bot.");
        return;
    }

    if ($data === CALLBACK_MY_PRODUCTS) {
        $purchases = readJsonFile(USER_PURCHASES_FILE);
        $user_purchases = $purchases[$user_id] ?? [];
        if (empty($user_purchases)) { $text = "You have no products yet."; }
        else {
            $text = "<b>🛍️ Your Products:</b>\n\n";
            foreach ($user_purchases as $purchase) {
                $product_name = htmlspecialchars($purchase['product_name']);
                $price_text = htmlspecialchars($purchase['price']);
                $date = $purchase['date'];
                $text .= "<b>Product:</b> {$product_name}\n";
                if (is_numeric($purchase['price'])) { $text .= "<b>Price:</b> \${$price_text}\n"; }
                else { $text .= "<b>Note:</b> {$price_text}\n"; }
                $text .= "<b>Date:</b> {$date}\n\n";
            }
        }
        $keyboard = json_encode(['inline_keyboard' => [[['text' => '« Back to Main Menu', 'callback_data' => CALLBACK_BACK_TO_MAIN]]]]);
        editMessageText($chat_id, $message_id, $text, $keyboard, 'HTML');
    }
    // Example: elseif (strpos($data, 'prod_noop_') === 0) { /* ... */ }
    elseif ($data === CALLBACK_SUPPORT) {
        setUserState($user_id, ['status' => STATE_AWAITING_SUPPORT_MESSAGE, 'message_id' => $message_id]);
        $support_text = "❓Please describe your issue or question below.\nYour message will be forwarded to the admin team.\n\nType /cancel to abort sending a message.";
        $cancel_keyboard = json_encode(['inline_keyboard' => [[['text' => 'Cancel Support Request', 'callback_data' => CALLBACK_BACK_TO_MAIN]]]]); // Or a specific cancel_support callback
        editMessageText($chat_id, $message_id, $support_text, $cancel_keyboard);
    }
    elseif ($data === CALLBACK_SUPPORT_CONFIRM) { /* This seems unused, but was in original code. If used, would need logic */ }

    // --- Admin Panel Flow ---
    // Check if $data starts with 'admin_' to group admin actions
    elseif (strpos($data, 'admin_') === 0 || $data === CALLBACK_ADMIN_PANEL || $data === CALLBACK_ADMIN_PROD_MANAGEMENT || $data === CALLBACK_ADMIN_VIEW_STATS ) {
        if (!$is_admin) {  sendMessage($chat_id, "Access denied."); return; }

        if ($data === CALLBACK_ADMIN_PANEL) {
            $admin_panel_keyboard_def = [
                'inline_keyboard' => [
                    [['text' => "📦 Product Management", 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]],
                    [['text' => "📊 View Bot Stats", 'callback_data' => CALLBACK_ADMIN_VIEW_STATS]],
                    // [['text' => "⚙️ Manage Admins", 'callback_data' => 'admin_manage_admins']], // Example for future
                    // [['text' => "💳 Update Payment Details", 'callback_data' => 'admin_edit_payment_details_prompt']], // Example for future
                    [['text' => '« Back to Main Menu', 'callback_data' => CALLBACK_BACK_TO_MAIN]]
                ]
            ];
            editMessageText($chat_id, $message_id, "⚙️ Admin Panel ⚙️", json_encode($admin_panel_keyboard_def));
            return;
        }
        elseif ($data === CALLBACK_ADMIN_VIEW_STATS) {
            $stats_content = generateBotStatsText();
            $keyboard_back = json_encode(['inline_keyboard' => [[['text' => '« Back to Admin Panel', 'callback_data' => CALLBACK_ADMIN_PANEL]]]]);
            editMessageText($chat_id, $message_id, $stats_content, $keyboard_back, 'HTML');
            return;
        }
        elseif ($data === CALLBACK_ADMIN_PROD_MANAGEMENT) {
            $prod_mgt_keyboard = [
                'inline_keyboard' => [
                    [['text' => "➕ Add Product", 'callback_data' => CALLBACK_ADMIN_ADD_PROD_SELECT_CATEGORY]],
                    [['text' => "✏️ Edit Product", 'callback_data' => CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY]],
                    [['text' => "➖ Remove Product", 'callback_data' => CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY]],
                    [['text' => '« Back to Admin Panel', 'callback_data' => CALLBACK_ADMIN_PANEL]]
                ]
            ];
            editMessageText($chat_id, $message_id, "📦 Product Management 📦", json_encode($prod_mgt_keyboard));
        }
        elseif ($data === CALLBACK_ADMIN_ADD_PROD_SELECT_CATEGORY) {
            // Logic to show categories for adding product
            global $products; // Ensure $products is available
            $category_keys = array_keys($products); // Assuming $products is structured {category_key: {product_id: {}}}
            $keyboard_rows = [];
            if(empty($category_keys)) { // Allow adding to a new category if none exist.
                 setUserState($user_id, ['status' => STATE_ADMIN_ADDING_PROD_NAME, 'category_key' => 'default']); // Or prompt for new category name
                 editMessageText($chat_id, $message_id, "No categories exist yet. Adding to 'default' category.\nEnter the product name:", null); return;
            }
            foreach ($category_keys as $cat_key) {
                $keyboard_rows[] = [['text' => ucfirst(str_replace('_', ' ', $cat_key)), 'callback_data' => CALLBACK_ADMIN_AP_CAT_PREFIX . $cat_key]];
            }
            // Option to add to a new category can be added here too
            $keyboard_rows[] = [['text' => '« Back to Product Mgt', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]];
            editMessageText($chat_id, $message_id, "Select a category to add the new product to:", json_encode(['inline_keyboard' => $keyboard_rows]));
        }
        elseif (strpos($data, CALLBACK_ADMIN_AP_CAT_PREFIX) === 0) {
            $category_key = substr($data, strlen(CALLBACK_ADMIN_AP_CAT_PREFIX));
            setUserState($user_id, ['status' => STATE_ADMIN_ADDING_PROD_NAME, 'category_key' => $category_key, 'original_message_id' => $message_id]);
            editMessageText($chat_id, $message_id, "Adding to category: '".htmlspecialchars($category_key)."'.\nEnter the product name:", null);
        }
        elseif ($data === CALLBACK_ADMIN_SET_PROD_TYPE_INSTANT || $data === CALLBACK_ADMIN_SET_PROD_TYPE_MANUAL) {
            $user_state = getUserState($user_id);
            if(!$user_state || $user_state['status'] !== STATE_ADMIN_ADDING_PROD_TYPE_PROMPT) { /* Error or wrong state */ return; }
            $user_state['new_product_type'] = ($data === CALLBACK_ADMIN_SET_PROD_TYPE_INSTANT) ? 'instant' : 'manual';
            $user_state['status'] = STATE_ADMIN_ADDING_PROD_PRICE;
            setUserState($user_id, $user_state);
            editMessageText($chat_id, $message_id, "Type set to: {$user_state['new_product_type']}.\nEnter the price for '{$user_state['new_product_name']}': (numbers only)", null);
        }

        // --- EDIT PRODUCT FLOW ---
        elseif ($data === CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY) {
            global $products;
            if (empty($products)) { editMessageText($chat_id, $message_id, "No categories found.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]]]])); return; }
            $keyboard_rows = [];
            foreach (array_keys($products) as $ck) { $keyboard_rows[] = [['text' => ucfirst(str_replace('_', ' ', $ck)), 'callback_data' => CALLBACK_ADMIN_EP_SCAT_PREFIX . $ck]]; }
            $keyboard_rows[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]];
            editMessageText($chat_id, $message_id, "Select category to edit products from:", json_encode(['inline_keyboard' => $keyboard_rows]));
        }
        elseif (strpos($data, CALLBACK_ADMIN_EP_SCAT_PREFIX) === 0) {
            $category_key = substr($data, strlen(CALLBACK_ADMIN_EP_SCAT_PREFIX));
            global $products;
            if (!isset($products[$category_key]) || empty($products[$category_key])) { editMessageText($chat_id, $message_id, "No products in " . htmlspecialchars($category_key), json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY]]]])); return; }
            $keyboard_rows = [];
            foreach ($products[$category_key] as $pid => $pdetails) { $keyboard_rows[] = [['text' => htmlspecialchars($pdetails['name']) . " (\${$pdetails['price']})", 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . "{$category_key}_{$pid}"]]; }
            $keyboard_rows[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY]];
            editMessageText($chat_id, $message_id, "Select product to edit in " . htmlspecialchars($category_key) . ":", json_encode(['inline_keyboard' => $keyboard_rows]));
        }
        elseif (strpos($data, CALLBACK_ADMIN_EP_SPRO_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_EP_SPRO_PREFIX, '/') . '([^_]+)_(.+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2];
            $p = getProductDetails($category_key, $product_id);
            if (!$p) { /* error handling */ return; }
            $kb = [
                [['text' => "✏️ Name", 'callback_data' => CALLBACK_ADMIN_EDIT_NAME_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => "💲 Price", 'callback_data' => CALLBACK_ADMIN_EDIT_PRICE_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => "ℹ️ Info", 'callback_data' => CALLBACK_ADMIN_EDIT_INFO_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => "🔄 Type (current: {$p['type']})", 'callback_data' => CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX . "{$category_key}_{$product_id}"]],
            ];
            if ($p['type'] === 'instant') { $kb[] = [['text' => "🗂️ Items (".count($p['items']??[]).")", 'callback_data' => CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX . "{$category_key}_{$product_id}"]]; }
            $kb[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_EP_SCAT_PREFIX . $category_key]];
            editMessageText($chat_id, $message_id, "Editing: <b>".htmlspecialchars($p['name'])."</b>", json_encode(['inline_keyboard' => $kb]), 'HTML');
        }
        elseif (strpos($data, CALLBACK_ADMIN_EDIT_NAME_PREFIX) === 0 || strpos($data, CALLBACK_ADMIN_EDIT_PRICE_PREFIX) === 0 || strpos($data, CALLBACK_ADMIN_EDIT_INFO_PREFIX) === 0) {
            $field_to_edit = ''; $current_val_text = '';
            if(strpos($data, CALLBACK_ADMIN_EDIT_NAME_PREFIX) === 0) { $field_to_edit = 'name'; $prefix = CALLBACK_ADMIN_EDIT_NAME_PREFIX; }
            elseif(strpos($data, CALLBACK_ADMIN_EDIT_PRICE_PREFIX) === 0) { $field_to_edit = 'price'; $prefix = CALLBACK_ADMIN_EDIT_PRICE_PREFIX; }
            else { $field_to_edit = 'info'; $prefix = CALLBACK_ADMIN_EDIT_INFO_PREFIX; }
            $ids_str = substr($data, strlen($prefix)); list($category_key, $product_id) = explode('_', $ids_str, 2);
            $p = getProductDetails($category_key, $product_id); if(!$p) return;
            setUserState($user_id, ['status' => STATE_ADMIN_EDITING_PROD_FIELD, 'field_to_edit' => $field_to_edit, 'category_key' => $category_key, 'product_id' => $product_id, 'original_message_id' => $message_id]);
            editMessageText($chat_id, $message_id, "Current {$field_to_edit}: \"".htmlspecialchars($p[$field_to_edit]??'')."\"\nSend new {$field_to_edit}: (or /cancel)", null);
        }
        elseif (strpos($data, CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX, '/') . '([^_]+)_(.+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2]; $p = getProductDetails($category_key, $product_id); if(!$p) return;
            $kb = [
                [['text' => '📦 Set Instant', 'callback_data' => CALLBACK_ADMIN_SET_TYPE_TO_INSTANT_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => '👤 Set Manual', 'callback_data' => CALLBACK_ADMIN_SET_TYPE_TO_MANUAL_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . "{$category_key}_{$product_id}"]]
            ];
            editMessageText($chat_id, $message_id, "Current type: {$p['type']}. Select new type for ".htmlspecialchars($p['name']).":", json_encode(['inline_keyboard'=>$kb]));
        }
        elseif (strpos($data, CALLBACK_ADMIN_SET_TYPE_TO_INSTANT_PREFIX) === 0 || strpos($data, CALLBACK_ADMIN_SET_TYPE_TO_MANUAL_PREFIX) === 0) {
            $new_type = (strpos($data, CALLBACK_ADMIN_SET_TYPE_TO_INSTANT_PREFIX) === 0) ? 'instant' : 'manual';
            $prefix = ($new_type === 'instant') ? CALLBACK_ADMIN_SET_TYPE_TO_INSTANT_PREFIX : CALLBACK_ADMIN_SET_TYPE_TO_MANUAL_PREFIX;
            $ids_str = substr($data, strlen($prefix)); list($category_key, $product_id) = explode('_', $ids_str, 2);
            global $products; if(empty($products)) $products = readJsonFile(PRODUCTS_FILE);
            if(isset($products[$category_key][$product_id])) {
                $products[$category_key][$product_id]['type'] = $new_type;
                if($new_type === 'instant' && !isset($products[$category_key][$product_id]['items'])) $products[$category_key][$product_id]['items'] = [];
                writeJsonFile(PRODUCTS_FILE, $products);
                // Refresh edit options menu by re-sending its callback data structure
                $p_updated = getProductDetails($category_key, $product_id);
                $kb_re = [ /* Regenerate edit options keyboard like in CALLBACK_ADMIN_EP_SPRO_PREFIX */
                    [['text' => "✏️ Name", 'callback_data' => CALLBACK_ADMIN_EDIT_NAME_PREFIX . "{$category_key}_{$product_id}"]],
                    [['text' => "💲 Price", 'callback_data' => CALLBACK_ADMIN_EDIT_PRICE_PREFIX . "{$category_key}_{$product_id}"]],
                    [['text' => "ℹ️ Info", 'callback_data' => CALLBACK_ADMIN_EDIT_INFO_PREFIX . "{$category_key}_{$product_id}"]],
                    [['text' => "🔄 Type (current: {$p_updated['type']})", 'callback_data' => CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX . "{$category_key}_{$product_id}"]],
                ];
                if ($p_updated['type'] === 'instant') { $kb_re[] = [['text' => "🗂️ Items (".count($p_updated['items']??[]).")", 'callback_data' => CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX . "{$category_key}_{$product_id}"]]; }
                $kb_re[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_EP_SCAT_PREFIX . $category_key]];
                editMessageText($chat_id, $message_id, "✅ Type set to {$new_type}.\nEditing: <b>".htmlspecialchars($p_updated['name'])."</b>", json_encode(['inline_keyboard' => $kb_re]), 'HTML');
            } else { /* error */ }
        }
        // --- Manage Instant Items ---
        elseif (strpos($data, CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX, '/') . '([^_]+)_(.+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2]; $p = getProductDetails($category_key, $product_id);
            if (!$p || $p['type'] !== 'instant') { /* error */ return; }
            $items_count = count($p['items'] ?? []);
            $kb_rows = [[['text' => '➕ Add New Item', 'callback_data' => CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX . "{$category_key}_{$product_id}"]]];
            if ($items_count > 0) $kb_rows[] = [['text' => '➖ Remove An Item', 'callback_data' => CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX . "{$category_key}_{$product_id}"]];
            $kb_rows[] = [['text' => '« Back to Edit Options', 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . "{$category_key}_{$product_id}"]];
            editMessageText($chat_id, $message_id, "<b>Manage Items: ".htmlspecialchars($p['name'])."</b> ({$items_count} items)", json_encode(['inline_keyboard' => $kb_rows]), 'HTML');
        }
        elseif (strpos($data, CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX, '/') . '([^_]+)_(.+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2];
            setUserState($user_id, ['status' => STATE_ADMIN_ADDING_SINGLE_INSTANT_ITEM, 'category_key' => $category_key, 'product_id' => $product_id, 'original_message_id' => $message_id]);
            editMessageText($chat_id, $message_id, "Send new item content for '".htmlspecialchars($product_id)."': (or /cancel)", null);
        }
        elseif (strpos($data, CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX, '/') . '([^_]+)_(.+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2]; $p = getProductDetails($category_key, $product_id);
            if (!$p || empty($p['items'])) { /* error or no items */ editMessageText($chat_id, $message_id, "No items to remove.", json_encode(['inline_keyboard'=>[[['text'=>'« Back', 'callback_data'=>CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX."{$category_key}_{$product_id}"]]]])); return; }
            $kb_items = []; foreach($p['items'] as $idx => $item) { $kb_items[] = [['text' => "❌ ".substr(htmlspecialchars($item),0,30).(strlen($item)>30?'...':''), 'callback_data' => CALLBACK_ADMIN_REMOVE_INST_ITEM_DO_PREFIX."{$category_key}_{$product_id}_{$idx}"]]; }
            $kb_items[] = [['text'=>'« Back', 'callback_data'=>CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX."{$category_key}_{$product_id}"]];
            editMessageText($chat_id, $message_id, "Select item to remove for ".htmlspecialchars($p['name']).":", json_encode(['inline_keyboard'=>$kb_items]));
        }
        elseif (strpos($data, CALLBACK_ADMIN_REMOVE_INST_ITEM_DO_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_REMOVE_INST_ITEM_DO_PREFIX, '/') . '([^_]+)_([^_]+)_(\d+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2]; $item_idx = (int)$matches[3];
            global $products; if(empty($products)) $products = readJsonFile(PRODUCTS_FILE);
            if(isset($products[$category_key][$product_id]['items'][$item_idx])) {
                $removed = $products[$category_key][$product_id]['items'][$item_idx];
                array_splice($products[$category_key][$product_id]['items'], $item_idx, 1);
                writeJsonFile(PRODUCTS_FILE, $products);
                // Refresh manage items menu
                $p_updated = getProductDetails($category_key, $product_id); $items_count_upd = count($p_updated['items']??[]);
                $kb_rows_upd = [[['text' => '➕ Add New Item', 'callback_data' => CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX . "{$category_key}_{$product_id}"]]];
                if ($items_count_upd > 0) $kb_rows_upd[] = [['text' => '➖ Remove An Item', 'callback_data' => CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX . "{$category_key}_{$product_id}"]];
                $kb_rows_upd[] = [['text' => '« Back to Edit Options', 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . "{$category_key}_{$product_id}"]];
                editMessageText($chat_id, $message_id, "Item removed.\n<b>Manage Items: ".htmlspecialchars($p_updated['name'])."</b> ({$items_count_upd} items)", json_encode(['inline_keyboard' => $kb_rows_upd]), 'HTML');
            } else { /* error, item not found */ }
        }

        // --- REMOVE PRODUCT FLOW ---
        elseif ($data === CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY) {
            global $products;
            if (empty($products)) { editMessageText($chat_id, $message_id, "No categories found.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]]]])); return; }
            $keyboard_rows = [];
            foreach (array_keys($products) as $ck) { $keyboard_rows[] = [['text' => ucfirst(str_replace('_', ' ', $ck)), 'callback_data' => CALLBACK_ADMIN_RP_SCAT_PREFIX . $ck]]; }
            $keyboard_rows[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]];
            editMessageText($chat_id, $message_id, "Select category to remove product from:", json_encode(['inline_keyboard' => $keyboard_rows]));
        }
        elseif (strpos($data, CALLBACK_ADMIN_RP_SCAT_PREFIX) === 0) {
            $category_key = substr($data, strlen(CALLBACK_ADMIN_RP_SCAT_PREFIX));
            global $products;
            if (!isset($products[$category_key]) || empty($products[$category_key])) { editMessageText($chat_id, $message_id, "No products in ".htmlspecialchars($category_key), json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY]]]])); return; }
            $keyboard_rows = [];
            foreach ($products[$category_key] as $pid => $pdetails) { $keyboard_rows[] = [['text' => "➖ ".htmlspecialchars($pdetails['name']), 'callback_data' => CALLBACK_ADMIN_RP_SPRO_PREFIX . "{$category_key}_{$pid}"]]; }
            $keyboard_rows[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY]];
            editMessageText($chat_id, $message_id, "Select product to REMOVE in ".htmlspecialchars($category_key).": (⚠️ Permanent!)", json_encode(['inline_keyboard' => $keyboard_rows]));
        }
        elseif (strpos($data, CALLBACK_ADMIN_RP_SPRO_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_RP_SPRO_PREFIX, '/') . '([^_]+)_(.+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2]; $p = getProductDetails($category_key, $product_id); if(!$p) return;
            $kb = [[['text' => "✅ YES, REMOVE", 'callback_data' => CALLBACK_ADMIN_RP_CONF_YES_PREFIX."{$category_key}_{$product_id}"], ['text' => "❌ NO, CANCEL", 'callback_data' => CALLBACK_ADMIN_RP_CONF_NO_PREFIX."{$category_key}_{$product_id}"]], [['text'=>'« Back', 'callback_data'=>CALLBACK_ADMIN_RP_SCAT_PREFIX.$category_key]]];
            editMessageText($chat_id, $message_id, "⚠️ Confirm Removal: ".htmlspecialchars($p['name'])."?", json_encode(['inline_keyboard'=>$kb]), 'HTML');
        }
        elseif (strpos($data, CALLBACK_ADMIN_RP_CONF_YES_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_RP_CONF_YES_PREFIX, '/') . '([^_]+)_(.+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2];
            global $products; if(empty($products)) $products = readJsonFile(PRODUCTS_FILE);
            if(isset($products[$category_key][$product_id])) {
                $removed_name = $products[$category_key][$product_id]['name'];
                unset($products[$category_key][$product_id]);
                if(empty($products[$category_key])) unset($products[$category_key]);
                writeJsonFile(PRODUCTS_FILE, $products);
                editMessageText($chat_id, $message_id, "✅ Product '".htmlspecialchars($removed_name)."' removed.", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Categories', 'callback_data'=>CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY]]]]));
            } else { /* error */ }
        }
        elseif (strpos($data, CALLBACK_ADMIN_RP_CONF_NO_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_RP_CONF_NO_PREFIX, '/') . '([^_]+)_(.+)$/', $data, $matches)) {
            $category_key = $matches[1]; // Product ID not needed for "NO"
             // Effectively re-display admin_rp_scat_
            global $products; if(empty($products)) $products = readJsonFile(PRODUCTS_FILE);
            $keyboard_rows_no = [];
            if (isset($products[$category_key]) && !empty($products[$category_key])) {
                foreach ($products[$category_key] as $pid_loop => $details_loop) { $keyboard_rows_no[] = [['text' => "➖ ".htmlspecialchars($details_loop['name']), 'callback_data' => CALLBACK_ADMIN_RP_SPRO_PREFIX . "{$category_key}_{$pid_loop}"]]; }
            }
            $keyboard_rows_no[] = [['text' => '« Back to Categories', 'callback_data' => CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY]];
            editMessageText($chat_id, $message_id, "Removal cancelled. Select product to REMOVE in ".htmlspecialchars($category_key).":", json_encode(['inline_keyboard' => $keyboard_rows_no]));
        }
    }
    // User-facing purchase flow
    elseif ($data === CALLBACK_BUY_SPOTIFY || $data === CALLBACK_BUY_SSH || $data === CALLBACK_BUY_V2RAY) {
        $category_key = ''; $category_name = '';
        if ($data === CALLBACK_BUY_SPOTIFY) { $category_key = 'spotify_plan'; $category_name = 'Spotify'; }
        elseif ($data === CALLBACK_BUY_SSH) { $category_key = 'ssh_plan'; $category_name = 'SSH VPN'; }
        elseif ($data === CALLBACK_BUY_V2RAY) { $category_key = 'v2ray_plan'; $category_name = 'V2Ray VPN'; }

        if (!empty($category_key)) {
            global $products; if (empty($products)) { $products = readJsonFile(PRODUCTS_FILE); }
            if (isset($products[$category_key]) && !empty($products[$category_key])) {
                $kb = generateCategoryKeyboard($category_key); // generateCategoryKeyboard needs to use CALLBACK_BACK_TO_MAIN
                editMessageText($chat_id, $message_id, "Please select a {$category_name} plan:", $kb);
            } else {
                editMessageText($chat_id, $message_id, "Sorry, no {$category_name} products available.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_BACK_TO_MAIN]]]]));
            }
        } else { /* Should not happen */ }
    }
    // Regex for product selection like: spotify_plan_ID, ssh_plan_ID, v2ray_plan_ID
    elseif (preg_match('/^(spotify_plan|ssh_plan|v2ray_plan)_(.+)$/', $data, $matches)) {
        $product_type_key = $matches[1]; $product_id = $matches[2];
        $category_for_back_button = str_replace('_plan', '', $product_type_key); //e.g. spotify
        $product = getProductDetails($product_type_key, $product_id);
        if ($product) {
            $plan_info = "<b>Product:</b> " . htmlspecialchars($product['name']) . "\n";
            $plan_info .= "<b>Price:</b> $" . htmlspecialchars($product['price']) . "\n";
            $plan_info .= "<b>Info:</b> " . nl2br(htmlspecialchars($product['info'] ?? 'N/A')) . "\n\n";
            $plan_info .= "Purchase this item?";
            // Determine correct back callback: CALLBACK_BUY_SPOTIFY, etc.
            $back_cb = '';
            if($product_type_key === 'spotify_plan') $back_cb = CALLBACK_BUY_SPOTIFY;
            elseif($product_type_key === 'ssh_plan') $back_cb = CALLBACK_BUY_SSH;
            elseif($product_type_key === 'v2ray_plan') $back_cb = CALLBACK_BUY_V2RAY;

            $kb = json_encode(['inline_keyboard' => [
                [['text' => "✅ Buy", 'callback_data' => CALLBACK_CONFIRM_BUY_PREFIX . "{$product_type_key}_{$product_id}"]],
                [['text' => "« Back", 'callback_data' => $back_cb ]]
            ]]);
            editMessageText($chat_id, $message_id, $plan_info, $kb);
        }
     }
    elseif (strpos($data, CALLBACK_CONFIRM_BUY_PREFIX) === 0) {
        list($category_key, $product_id) = explode('_', substr($data, strlen(CALLBACK_CONFIRM_BUY_PREFIX)), 2);
        $product = getProductDetails($category_key, $product_id);
        if ($product) {
            setUserState($user_id, ['status' => STATE_AWAITING_RECEIPT, 'message_id' => $message_id, 'product_name' => $product['name'], 'price' => $product['price'], 'category_key' => $category_key, 'product_id' => $product_id]);
            $paymentDets = getPaymentDetails();
            $text = "Transfer **\${$product['price']}** to:\n\n";
            $text .= "Card Number: `{$paymentDets['card_number']}`\n";
            $text .= "Card Holder: `{$paymentDets['card_holder']}`\n\n";
            $text .= "Send screenshot of receipt to this chat.";
            $kb = json_encode(['inline_keyboard' => [[['text' => 'Cancel Purchase', 'callback_data' => CALLBACK_BACK_TO_MAIN]]]]);
            editMessageText($chat_id, $message_id, $text, $kb, 'Markdown');
        }
    }
    // Payment acceptance/rejection
    elseif (strpos($data, CALLBACK_ACCEPT_PAYMENT_PREFIX) === 0 || strpos($data, CALLBACK_REJECT_PAYMENT_PREFIX) === 0) {
        if(!$is_admin) { /* error */ return; }
        $is_accept = strpos($data, CALLBACK_ACCEPT_PAYMENT_PREFIX) === 0;
        $target_user_id = substr($data, strlen($is_accept ? CALLBACK_ACCEPT_PAYMENT_PREFIX : CALLBACK_REJECT_PAYMENT_PREFIX));

        // Get details from state of user who was 'awaiting_receipt'
        // This part is tricky, the original callback only had target_user_id.
        // We need to retrieve the product info associated with this payment.
        // This requires that the admin, when forwarding the receipt, also included product details or a transaction ID.
        // For now, let's assume the state was cleared for the user, and we rely on the caption of the forwarded photo.
        // A better system would involve a pending_payments.json or similar.
        // For now, the product name and price are in the caption of the message the admin is replying to.

        $original_caption = $callback_query->message->caption ?? '';
        // Try to parse product name and price from caption (this is fragile)
        $product_name_from_caption = "Unknown Product (from receipt)";
        $price_from_caption = "N/A";
        if(preg_match("/▪️ \*\*Product:\*\* (.*?)\n/", $original_caption, $cap_matches_name)){ $product_name_from_caption = $cap_matches_name[1]; }
        if(preg_match("/▪️ \*\*Price:\*\* \$(.*?)\n/", $original_caption, $cap_matches_price)){ $price_from_caption = $cap_matches_price[1]; }


        if ($is_accept) {
            // Here, you'd ideally retrieve the product from the user's state when they were 'awaiting_receipt'
            // For now, we'll use the parsed info from caption.
            // If it was an instant product, deliver it.
            // This part of the logic (delivering item) is missing from original, needs thought.
            // Let's assume we need category_key and product_id of the item purchased.
            // This info is NOT in the current callback `accept_payment_USERID`. It must be stored temporarily or passed.
            // For now, just record purchase and notify.

            // A simple way: If the product_name_from_caption and price_from_caption match an existing product,
            // and it's an instant product, then try to deliver. This is still imperfect.
            // A robust solution would be to store pending transaction details with a unique ID.

            recordPurchase($target_user_id, $product_name_from_caption, $price_from_caption);
            editMessageCaption($chat_id, $message_id, $original_caption . "\n\n✅ PAYMENT ACCEPTED by admin {$user_id}.", null); // No more buttons
            sendMessage($target_user_id, "✅ Your payment for '{$product_name_from_caption}' has been accepted! Check 'My Products'.");
            // If $product_name_from_caption was for an instant product, we'd need to deliver it here.
            // This requires finding its category_key and product_id.
            // Example: $item_content = getAndRemoveInstantProductItem($cat_key, $prod_id); if($item_content) sendMessage($target_user_id, "Your item: ".$item_content);
        } else { // Reject
            editMessageCaption($chat_id, $message_id, $original_caption . "\n\n❌ PAYMENT REJECTED by admin {$user_id}.", null);
            sendMessage($target_user_id, "⚠️ Your payment for '{$product_name_from_caption}' has been rejected. Please contact support if you have questions.");
        }
    }
    elseif ($data === CALLBACK_BACK_TO_MAIN) {
        $first_name = $callback_query->from->first_name;
        $welcome_text = "Hello, " . htmlspecialchars($first_name) . "! Welcome back to the main menu.\n\nPlease select an option:";
        $keyboard = $is_admin ? $adminMenuKeyboard : $mainMenuKeyboard;
        editMessageText($chat_id, $message_id, $welcome_text, $keyboard);
    }
}
?>
