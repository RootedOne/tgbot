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

function promptForProductType($chat_id, $admin_user_id, $category_key, $product_name_context) { $type_keyboard = ['inline_keyboard' => [[['text' => '📦 Instant Delivery', 'callback_data' => 'admin_set_prod_type_instant']],[['text' => '👤 Manual Delivery', 'callback_data' => 'admin_set_prod_type_manual']],[['text' => '« Cancel', 'callback_data' => 'admin_prod_management']]]]; sendMessage($chat_id, "Product: '{$product_name_context}'.\nSelect delivery type:", json_encode($type_keyboard));}

$products = readJsonFile(PRODUCTS_FILE);

// ===================================================================
//  TELEGRAM API FUNCTIONS
// ===================================================================
function bot($method, $data = []) { $url = "https://api.telegram.org/bot" . API_TOKEN . "/" . $method; $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $data); $res = curl_exec($ch); curl_close($ch); return json_decode($res); }
function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = 'HTML') { bot('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageText($chat_id, $message_id, $text, $reply_markup = null, $parse_mode = 'HTML') { bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageCaption($chat_id, $message_id, $caption, $reply_markup = null, $parse_mode = 'HTML') { bot('editMessageCaption', ['chat_id' => $chat_id, 'message_id' => $message_id, 'caption' => $caption, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageReplyMarkup($chat_id, $message_id, $reply_markup = null) { bot('editMessageReplyMarkup', ['chat_id' => $chat_id, 'message_id' => $message_id, 'reply_markup' => $reply_markup]); }
function answerCallbackQuery($callback_query_id) { bot('answerCallbackQuery', ['callback_query_id' => $callback_query_id]); }
function forwardPhotoToAdmin($file_id, $caption, $original_user_id) { $admin_ids = getAdminIds(); if(empty($admin_ids)) return; $admin_id = $admin_ids[0]; $approval_keyboard = json_encode(['inline_keyboard' => [[['text' => "✅ Accept", 'callback_data' => "accept_payment_$original_user_id"], ['text' => "❌ Reject", 'callback_data' => "reject_payment_$original_user_id"]]]]); bot('sendPhoto', ['chat_id' => $admin_id, 'photo' => $file_id, 'caption' => $caption, 'parse_mode' => 'Markdown', 'reply_markup' => $approval_keyboard]); }
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
    $is_admin = in_array($user_id, getAdminIds());

    answerCallbackQuery($callback_query->id);

    $user_specific_data = getUserData($user_id);
    if ($user_specific_data['is_banned']) {
        sendMessage($chat_id, "⚠️ You are banned from using this bot.");
        return;
    }

    if ($data === 'my_products') {
        $purchases = readJsonFile(USER_PURCHASES_FILE);
        $user_purchases = $purchases[$user_id] ?? [];

        if (empty($user_purchases)) {
            $text = "You have no products yet.";
        } else {
            $text = "<b>🛍️ Your Products:</b>\n\n";
            foreach ($user_purchases as $purchase) {
                $product_name = htmlspecialchars($purchase['product_name']);
                $price = htmlspecialchars($purchase['price']); // Price might be "Manually Added" or a number
                $date = $purchase['date']; // Already a string
                $text .= "<b>Product:</b> {$product_name}\n";
                // Display price only if it's numeric, otherwise it might be a note like "Manually Added"
                if (is_numeric($price)) {
                    $text .= "<b>Price:</b> \${$price}\n";
                } else {
                    $text .= "<b>Note:</b> {$price}\n";
                }
                $text .= "<b>Date:</b> {$date}\n\n";
            }
        }
        $keyboard = json_encode(['inline_keyboard' => [[['text' => '« Back to Main Menu', 'callback_data' => 'back_to_main']]]]);
        editMessageText($chat_id, $message_id, $text, $keyboard, 'HTML');
    }
    elseif (strpos($data, 'prod_noop_') === 0) { /* ... existing noop logic ... */ }
    elseif ($data === 'support') {
        $support_message = "If you need help or have any questions, you can write a message to our support team.\n\n";
        $support_message .= "Please choose an option:";
        $support_keyboard = json_encode([
            'inline_keyboard' => [
                [['text' => "✍️ Write Message", 'callback_data' => 'support_confirm_action']], // Changed to avoid conflict if 'support_confirm' is used elsewhere
                [['text' => "« Cancel", 'callback_data' => 'back_to_main']]
            ]
        ]);
        editMessageText($chat_id, $message_id, $support_message, $support_keyboard);
    }
    // Updated to handle 'support_confirm_action' from the 'Write Message' button
    elseif ($data === 'support_confirm_action') {
        // Set user state to await their support message
        setUserState($user_id, ['status' => 'awaiting_support_message', 'message_id' => $message_id]);

        $prompt_text = "Please send your message to the support team now.\n\nYour message will be forwarded directly.";
        $cancel_keyboard = json_encode([
            'inline_keyboard' => [
                [['text' => "🚫 Cancel Support Request", 'callback_data' => 'cancel_support_request']]
            ]
        ]);
        editMessageText($chat_id, $message_id, $prompt_text, $cancel_keyboard);
    }
    elseif ($data === 'cancel_support_request') { // Handler for the new cancel button
        clearUserState($user_id);
        // Take user back to main menu
        $first_name = $callback_query->from->first_name;
        $welcome_text = "Hello, " . htmlspecialchars($first_name) . "! Support request cancelled. Welcome back to the main menu.";
        $keyboard = $is_admin ? $adminMenuKeyboard : $mainMenuKeyboard;
        editMessageText($chat_id, $message_id, $welcome_text, $keyboard);
    }
    // --- Admin Panel Flow ---
    elseif (strpos($data, 'admin_') === 0) {
        if (!$is_admin) {  sendMessage($chat_id, "Access denied."); return; }

        if ($data === 'admin_panel') { /* ... shows main admin menu ... */ }
        elseif ($data === 'admin_prod_management') { /* ... shows product mgt menu ... */ }
        elseif ($data === 'admin_add_prod_select_category') { /* ... selects category for new product ... */ }
        elseif (strpos($data, 'admin_ap_cat_') === 0) { /* ... sets state for adding product name ... */ }
        elseif (strpos($data, 'admin_set_prod_type_') === 0) { /* ... sets product type for new product ... */ }
        elseif ($data === 'admin_remove_prod_select_category') { /* ... */ }
        elseif (strpos($data, 'admin_rp_scat_') === 0) { /* ... */ }
        elseif (strpos($data, 'admin_rp_spro_') === 0) { /* ... */ }
        elseif (strpos($data, 'admin_rp_conf_') === 0) { /* ... */ }
        elseif ($data === 'admin_edit_prod_select_category') { /* ... */ }
        elseif (strpos($data, 'admin_ep_scat_') === 0) { /* ... */ }
        elseif (strpos($data, 'admin_ep_spro_') === 0 && preg_match('/^admin_ep_spro_([^_]+)_(.+)$/', $data, $matches_ep_spro)) { /* ... shows edit options ... */ }
        elseif (preg_match('/^admin_edit_(name|price|info)_([^_]+)_(.+)$/', $data, $matches_edit_action)) { /* ... prompts for new name/price/info ... */ }
        elseif (preg_match('/^admin_edit_type_prompt_([^_]+)_(.+)$/', $data, $matches_edit_type_prompt)) { /* ... shows type change buttons ... */ }
        elseif (preg_match('/^admin_set_type_to_(instant|manual)_([^_]+)_(.+)$/', $data, $matches_set_type)) { /* ... sets new type ... */ }

        // --- Manage Instant Items Flow (Copied from mistaken bot.php overwrite) ---
        elseif (preg_match('/^admin_manage_instant_items_([^_]+)_(.+)$/', $data, $matches_manage_items)) {
            $category_key = $matches_manage_items[1]; $product_id = $matches_manage_items[2];
            $product_details = getProductDetails($category_key, $product_id);
            if (!$product_details || ($product_details['type'] ?? 'manual') !== 'instant') {
                editMessageText($chat_id, $message_id, "Error: Not an instant product or not found.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => "admin_ep_spro_{$category_key}_{$product_id}"]]]])); return;
            }
            $items_text = "<b>Manage Instant Items: {$product_details['name']}</b>\n";
            $current_items = $product_details['items'] ?? [];
            $items_text .= "Currently stocked: " . count($current_items) . " item(s).\n";
            $kb_rows = [
                [['text' => '➕ Add New Item', 'callback_data' => "admin_add_inst_item_prompt_{$category_key}_{$product_id}"]],
            ];
            if (!empty($current_items)) {
                 $kb_rows[] = [['text' => '➖ Remove An Item', 'callback_data' => "admin_remove_inst_item_list_{$category_key}_{$product_id}"]];
            }
            $kb_rows[] = [['text' => '« Back to Edit Options', 'callback_data' => "admin_ep_spro_{$category_key}_{$product_id}"]];
            editMessageText($chat_id, $message_id, $items_text, json_encode(['inline_keyboard' => $kb_rows]), 'HTML');
        }
        elseif (preg_match('/^admin_add_inst_item_prompt_([^_]+)_(.+)$/', $data, $matches_add_prompt)) {
            $category_key = $matches_add_prompt[1]; $product_id = $matches_add_prompt[2];
            setUserState($user_id, ['status' => "admin_adding_single_instant_item", 'category_key' => $category_key, 'product_id' => $product_id, 'original_message_id' => $message_id]);
            editMessageText($chat_id, $message_id, "Send the new instant item content for '{$product_id}' (e.g., code, link):", null);
        }
        elseif (preg_match('/^admin_remove_inst_item_list_([^_]+)_(.+)$/', $data, $matches_remove_list)) {
            $category_key = $matches_remove_list[1]; $product_id = $matches_remove_list[2];
            $product_details = getProductDetails($category_key, $product_id);
            $current_items = $product_details['items'] ?? [];
            if (empty($current_items)) {
                editMessageText($chat_id, $message_id, "No items to remove for '{$product_details['name']}'.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => "admin_manage_instant_items_{$category_key}_{$product_id}"]]]])); return;
            }
            $items_kb = [];
            foreach ($current_items as $index => $item_content) {
                $display_text = (strlen($item_content) > 30) ? substr($item_content, 0, 27) . "..." : $item_content;
                $items_kb[] = [['text' => "❌ {$display_text}", 'callback_data' => "admin_remove_inst_item_do_{$category_key}_{$product_id}_{$index}"]];
            }
            $items_kb[] = [['text' => '« Back to Manage Items', 'callback_data' => "admin_manage_instant_items_{$category_key}_{$product_id}"]];
            editMessageText($chat_id, $message_id, "Select item to remove for '{$product_details['name']}':", json_encode(['inline_keyboard' => $items_kb]));
        }
        elseif (preg_match('/^admin_remove_inst_item_do_([^_]+)_([^_]+)_(\d+)$/', $data, $matches_remove_do)) {
            $category_key = $matches_remove_do[1]; $product_id = $matches_remove_do[2]; $item_index = (int)$matches_remove_do[3];
            $product_details = getProductDetails($category_key, $product_id);
            if ($product_details && isset($product_details['items'][$item_index])) {
                $removed_item = htmlspecialchars($product_details['items'][$item_index]);
                array_splice($product_details['items'], $item_index, 1);
                updateProductDetails($category_key, $product_id, $product_details);

                $product_details_after_remove = getProductDetails($category_key, $product_id);
                $items_text = "Item '{$removed_item}' removed.\n<b>Manage Instant Items: {$product_details_after_remove['name']}</b>\n";
                $current_items_after_remove = $product_details_after_remove['items'] ?? [];
                $items_text .= "Currently stocked: " . count($current_items_after_remove) . " item(s).\n";
                $kb_rows_after_remove = [[['text' => '➕ Add New Item', 'callback_data' => "admin_add_inst_item_prompt_{$category_key}_{$product_id}"]]];
                if (!empty($current_items_after_remove)) { $kb_rows_after_remove[] = [['text' => '➖ Remove An Item', 'callback_data' => "admin_remove_inst_item_list_{$category_key}_{$product_id}"]];}
                $kb_rows_after_remove[] = [['text' => '« Back to Edit Options', 'callback_data' => "admin_ep_spro_{$category_key}_{$product_id}"]];
                editMessageText($chat_id, $message_id, $items_text, json_encode(['inline_keyboard' => $kb_rows_after_remove]), 'HTML');
            } else {
                editMessageText($chat_id, $message_id, "Error: Item not found or already removed.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => "admin_manage_instant_items_{$category_key}_{$product_id}"]]]]));
            }
        }
        // --- Fallback for old admin commands ---
        elseif ($data === 'admin_manage_products' || strpos($data, 'admin_manage_cat_') === 0 || (strpos($data, 'admin_add_') === 0 && strpos($data, 'admin_add_prod_select_category') !== 0 && strpos($data, 'admin_ap_cat_') !== 0) || preg_match('/^admin_remove_(.+)_(.+)$/', $data) ) { /* ... */ }
    }
    elseif (preg_match('/^(accept|reject)_payment_(\d+)$/', $data, $matches)) { /* ... */ }
    elseif ($data === 'buy_spotify' || $data === 'buy_ssh' || $data === 'buy_v2ray') {
        $category_key = '';
        $category_name = '';
        if ($data === 'buy_spotify') {
            $category_key = 'spotify_plan';
            $category_name = 'Spotify';
        } elseif ($data === 'buy_ssh') {
            $category_key = 'ssh_plan';
            $category_name = 'SSH VPN';
        } elseif ($data === 'buy_v2ray') {
            $category_key = 'v2ray_plan';
            $category_name = 'V2Ray VPN';
        }

        if (!empty($category_key)) {
            global $products; // Ensure $products is accessible
            if (empty($products)) { $products = readJsonFile(PRODUCTS_FILE); } // Load if not already loaded

            if (isset($products[$category_key]) && !empty($products[$category_key])) {
                $keyboard = generateCategoryKeyboard($category_key);
                editMessageText($chat_id, $message_id, "Please select a {$category_name} plan:", $keyboard);
            } else {
                // Category exists but has no products, or category key is wrong
                editMessageText($chat_id, $message_id, "Sorry, there are no {$category_name} products available at the moment.", json_encode(['inline_keyboard' => [[['text' => '« Back to Main Menu', 'callback_data' => 'back_to_main']]]]));
            }
        } else {
            // Should not happen if $data is one of the three
            editMessageText($chat_id, $message_id, "An unexpected error occurred. Please try again.", json_encode(['inline_keyboard' => [[['text' => '« Back to Main Menu', 'callback_data' => 'back_to_main']]]]));
        }
    }
    elseif (preg_match('/^(spotify|ssh|v2ray)_plan_(.+)$/', $data, $matches)) {
        $product_type_key = $matches[1] . '_plan'; $product_id = $matches[2]; $category = $matches[1];
        $product = getProductDetails($product_type_key, $product_id);
        if ($product) {
            $plan_info = "<b>Product Details</b>\n\n";
            $plan_info .= "▪️ **Product:** {$product['name']}\n";
            $plan_info .= "▪️ **Price:** \${$product['price']}\n";
            $plan_info .= "▪️ **Info:** " . nl2br(htmlspecialchars($product['info'] ?? 'No additional information.')) . "\n\n";
            $plan_info .= "Do you want to purchase this plan?";
            $keyboard = json_encode(['inline_keyboard' => [[['text' => "✅ Buy", 'callback_data' => "confirm_buy_{$product_type_key}_{$product_id}"]], [['text' => "« Back", 'callback_data' => "buy_{$category}"]]]]);
            editMessageText($chat_id, $message_id, $plan_info, $keyboard);
        }
     }
    elseif (preg_match('/^confirm_buy_(.+?)_(.+)$/', $data, $matches)) {
        $product_type_key = $matches[1]; $product_id = $matches[2];
        $product = getProductDetails($product_type_key, $product_id);
        if ($product) {
            setUserState($user_id, ['status' => 'awaiting_receipt', 'message_id' => $message_id, 'product_name' => $product['name'], 'price' => $product['price'], 'category_key' => $product_type_key, 'product_id' => $product_id]);
            $paymentDets = getPaymentDetails();
            $payment_info_text = "Please transfer **\${$product['price']}** to:\n\n";
            $payment_info_text .= "Card Number: `{$paymentDets['card_number']}`\n";
            $payment_info_text .= "Card Holder: `{$paymentDets['card_holder']}`\n\n";
            $payment_info_text .= "After payment, send the screenshot of your receipt to this chat.";
            $keyboard = json_encode(['inline_keyboard' => [[['text' => 'Cancel Purchase', 'callback_data' => 'back_to_main']]]]);
            editMessageText($chat_id, $message_id, $payment_info_text, $keyboard, 'Markdown');
        } else {
            // Temporary debugging: Notify if product is not found
            editMessageText($chat_id, $message_id, "Debug: Product not found for type '{$product_type_key}' and ID '{$product_id}'. Please check product data and callback generation.", json_encode(['inline_keyboard' => [[['text' => '« Back to Main Menu', 'callback_data' => 'back_to_main']]]]));
        }
    }
    elseif ($data === 'back_to_main') {
        $first_name = $callback_query->from->first_name; // Get user's first name for a personalized message
        $welcome_text = "Hello, " . htmlspecialchars($first_name) . "! Welcome back to the main menu.\n\nPlease select an option:";
        $keyboard = $is_admin ? $adminMenuKeyboard : $mainMenuKeyboard;
        // It's good practice to make sure global keyboards are loaded if they come from config.php
        // However, they are declared global at the top of processCallbackQuery, so they should be available.
        editMessageText($chat_id, $message_id, $welcome_text, $keyboard);
    }
}
?>
