<?php
// FILE: functions.php
// Contains all reusable bot functions.

define('STATE_FILE', 'user_states.json');
define('PRODUCTS_FILE', 'products.json');
define('USER_PURCHASES_FILE', 'user_purchases.json');
define('USER_DATA_FILE', 'user_data.json');
define('BOT_CONFIG_DATA_FILE', 'bot_config_data.json');

// Constants are now defined in config.php
// ===================================================================
//  STATE & DATA MANAGEMENT FUNCTIONS
// ===================================================================
// --- Generic JSON Read/Write ---
function readJsonFile($filename) { if (!file_exists($filename)) return []; $json = file_get_contents($filename); return json_decode($json, true) ?: []; }

function writeJsonFile($filename, $data) {
    $json_data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json_data === false) {
        error_log("writeJsonFile: json_encode error for {$filename}: " . json_last_error_msg());
        return false;
    }

    if (file_put_contents($filename, $json_data) === false) {
        error_log("writeJsonFile: file_put_contents error for {$filename}. Check permissions, path, or disk space.");
        return false;
    }
    return true;
}

// --- User State Functions ---
function setUserState($user_id, $state) { $states = readJsonFile(STATE_FILE); $states[$user_id] = $state; if(!writeJsonFile(STATE_FILE, $states)) {error_log("Failed to write user state for {$user_id}");} }
function getUserState($user_id) { $states = readJsonFile(STATE_FILE); return $states[$user_id] ?? null; }
function clearUserState($user_id) { $states = readJsonFile(STATE_FILE); if (isset($states[$user_id])) { unset($states[$user_id]); if(!writeJsonFile(STATE_FILE, $states)){error_log("Failed to write user states after clearing for {$user_id}");}} }

// --- Bot Config Data Functions ---
function getBotConfig() { return readJsonFile(BOT_CONFIG_DATA_FILE); }
function saveBotConfig($config_data) { if(!writeJsonFile(BOT_CONFIG_DATA_FILE, $config_data)){error_log("Failed to save bot config data.");} }
function getAdminIds() { $config = getBotConfig(); return $config['admins'] ?? []; }
function getPaymentDetails() { $config = getBotConfig(); return ['card_holder' => $config['payment_card_holder'] ?? 'Not Set', 'card_number' => $config['payment_card_number'] ?? 'Not Set']; }
function updatePaymentDetails($new_holder, $new_number) { $config = getBotConfig(); if ($new_holder !== null) { $config['payment_card_holder'] = $new_holder; } if ($new_number !== null) { $config['payment_card_number'] = $new_number; } saveBotConfig($config); }
function addAdmin($user_id) { if (!is_numeric($user_id)) return false; $user_id = (int) $user_id; $config = getBotConfig(); if (!in_array($user_id, ($config['admins'] ?? []))) { $config['admins'][] = $user_id; saveBotConfig($config); return true; } return false; }
function removeAdmin($user_id) { if (!is_numeric($user_id)) return false; $user_id = (int) $user_id; $config = getBotConfig(); $admins = $config['admins'] ?? []; $initial_count = count($admins); $config['admins'] = array_values(array_filter($admins, function($admin) use ($user_id) { return $admin !== $user_id; })); if (count($config['admins']) < $initial_count) { saveBotConfig($config); return true; } return false; }

// --- User Data Functions ---
function getUserData($user_id) { $all_user_data = readJsonFile(USER_DATA_FILE); if (isset($all_user_data[$user_id])) { return $all_user_data[$user_id]; } return ['balance' => 0, 'is_banned' => false]; }
function updateUserData($user_id, $data) { $all_user_data = readJsonFile(USER_DATA_FILE); $all_user_data[$user_id] = $data; if(!writeJsonFile(USER_DATA_FILE, $all_user_data)){error_log("Failed to update user data for {$user_id}");} }
function banUser($user_id) { $user_data = getUserData($user_id); $user_data['is_banned'] = true; updateUserData($user_id, $user_data); }
function unbanUser($user_id) { $user_data = getUserData($user_id); $user_data['is_banned'] = false; updateUserData($user_id, $user_data); }
function addUserBalance($user_id, $amount) { if (!is_numeric($amount) || $amount < 0) return false; $user_data = getUserData($user_id); $user_data['balance'] = ($user_data['balance'] ?? 0) + (float)$amount; updateUserData($user_id, $user_data); return true; }

// --- User Purchase and Product Functions ---
function recordPurchase($user_id, $product_name, $price, $delivered_item_content = null) {
    $purchases = readJsonFile(USER_PURCHASES_FILE);
    $new_purchase = [
        'product_name' => $product_name,
        'price' => $price,
        'date' => date('Y-m-d H:i:s')
    ];
    if ($delivered_item_content !== null) {
        $new_purchase['delivered_item_content'] = $delivered_item_content;
    }
    if (!isset($purchases[$user_id])) {
        $purchases[$user_id] = [];
    }
    $purchases[$user_id][] = $new_purchase;
    $new_purchase_index = count($purchases[$user_id]) - 1; // Index of the item just added

    if(writeJsonFile(USER_PURCHASES_FILE, $purchases)){
        return $new_purchase_index;
    } else {
        error_log("Failed to record purchase for user {$user_id}");
        return false;
    }
}
function getProductDetails($category_key, $product_id) { global $products; if (empty($products)) { $products = readJsonFile(PRODUCTS_FILE); } return $products[$category_key][$product_id] ?? null; }
function updateProductDetails($category_key, $product_id, $details) {
    global $products;
    if (empty($products)) { $products = readJsonFile(PRODUCTS_FILE); }
    if (isset($products[$category_key][$product_id])) {
        $products[$category_key][$product_id] = $details;
        return writeJsonFile(PRODUCTS_FILE, $products);
    }
    return false;
}
function addInstantProductItem($category_key, $product_id, $item_content) {
    global $products;
    if (empty($products)) { $products = readJsonFile(PRODUCTS_FILE); }
    if (isset($products[$category_key][$product_id]) && ($products[$category_key][$product_id]['type'] ?? 'manual') === 'instant') {
        if (!isset($products[$category_key][$product_id]['items']) || !is_array($products[$category_key][$product_id]['items'])) {
            $products[$category_key][$product_id]['items'] = [];
        }
        $products[$category_key][$product_id]['items'][] = $item_content;
        return writeJsonFile(PRODUCTS_FILE, $products);
    }
    return false;
}
function getAndRemoveInstantProductItem($category_key, $product_id) {
    global $products;
    if (empty($products)) { $products = readJsonFile(PRODUCTS_FILE); }
    if (isset($products[$category_key][$product_id]) &&
        ($products[$category_key][$product_id]['type'] ?? 'manual') === 'instant' &&
        !empty($products[$category_key][$product_id]['items']) &&
        is_array($products[$category_key][$product_id]['items'])) {
        $item = array_shift($products[$category_key][$product_id]['items']);
        if (writeJsonFile(PRODUCTS_FILE, $products)) {
            return $item;
        } else {
            error_log("Failed to save products after removing an instant item for {$category_key}_{$product_id}. Item was removed from memory but not saved.");
            return null;
        }
    }
    return null;
}

function promptForProductType($chat_id, $admin_user_id, $category_key, $product_name_context) {
    $type_keyboard = ['inline_keyboard' => [
        [['text' => '📦 Instant Delivery', 'callback_data' => CALLBACK_ADMIN_SET_PROD_TYPE_INSTANT]],
        [['text' => '👤 Manual Delivery', 'callback_data' => CALLBACK_ADMIN_SET_PROD_TYPE_MANUAL]],
        [['text' => '« Cancel', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]]
    ]];
    sendMessage($chat_id, "Product: '{$product_name_context}'.\nSelect delivery type:", json_encode($type_keyboard));
}

$products = readJsonFile(PRODUCTS_FILE); // Global products cache

// --- Coupon Data Functions ---
function readCouponsFile() {
    return readJsonFile(COUPONS_FILE);
}

function writeCouponsFile($coupons_array) {
    return writeJsonFile(COUPONS_FILE, $coupons_array);
}

function getCouponByCode($code_to_find) {
    $coupons = readCouponsFile();
    $code_to_find_upper = strtoupper(trim($code_to_find));
    foreach ($coupons as $coupon) {
        if (isset($coupon['code']) && strtoupper($coupon['code']) === $code_to_find_upper) {
            return $coupon;
        }
    }
    return null;
}

function addCoupon($coupon_data) {
    if (!isset($coupon_data['code']) || empty(trim($coupon_data['code']))) {
        error_log("addCoupon: Coupon code is missing or empty.");
        return false;
    }
    $coupon_data['code'] = strtoupper(trim($coupon_data['code'])); // Ensure uppercase and trimmed

    if (getCouponByCode($coupon_data['code']) !== null) {
        error_log("addCoupon: Coupon code '{$coupon_data['code']}' already exists.");
        return false; // Code already exists
    }

    // Validate required fields (basic validation for now)
    if (!isset($coupon_data['discount_type']) || !in_array($coupon_data['discount_type'], ['percentage', 'fixed_amount'])) return false;
    if (!isset($coupon_data['discount_value']) || !is_numeric($coupon_data['discount_value']) || $coupon_data['discount_value'] <= 0) return false;
    if ($coupon_data['discount_type'] === 'percentage' && $coupon_data['discount_value'] > 100) return false; // Percentage cannot be > 100
    if (!isset($coupon_data['max_uses']) || !is_numeric($coupon_data['max_uses']) || (int)$coupon_data['max_uses'] < 0) return false; // 0 for unlimited, or positive

    $coupon_data['max_uses'] = (int)$coupon_data['max_uses'];
    $coupon_data['uses_count'] = $coupon_data['uses_count'] ?? 0;
    $coupon_data['is_active'] = $coupon_data['is_active'] ?? true;
    $coupon_data['created_at'] = $coupon_data['created_at'] ?? date('Y-m-d H:i:s');


    $coupons = readCouponsFile();
    $coupons[] = $coupon_data;
    return writeCouponsFile($coupons);
}
// --- End Coupon Data Functions ---


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
        foreach ($user_data_all as $data) {
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
        foreach ($user_purchases_all as $purchases) {
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
function generateDynamicMainMenuKeyboard($is_admin_menu = false) {
    // error_log("START_MENU: generateDynamicMainMenuKeyboard called. Admin: " . ($is_admin_menu ? 'Yes' : 'No'));
    global $products;
    $products = readJsonFile(PRODUCTS_FILE);

    $keyboard_rows = [];

    if (!empty($products)) {
        foreach ($products as $category_key => $category_items) {
            if (is_string($category_key) && !empty($category_key) && is_array($category_items)) {
                $displayName = ucfirst(str_replace('_', ' ', $category_key));
                $keyboard_rows[] = [['text' => "🛍️ " . htmlspecialchars($displayName), 'callback_data' => 'view_category_' . $category_key]];
            } else {
                error_log("START_MENU: Skipped invalid top-level item in products.json. Key: " . print_r($category_key, true) . " Items: " . print_r($category_items, true));
            }
        }
    }

    $keyboard_rows[] = [['text' => "📦 My Products", 'callback_data' => (string)CALLBACK_MY_PRODUCTS]];
    $keyboard_rows[] = [['text' => "❓ Support", 'callback_data' => (string)CALLBACK_SUPPORT]];

    if ($is_admin_menu) {
        $keyboard_rows[] = [['text' => "⚙️ Admin Panel", 'callback_data' => (string)CALLBACK_ADMIN_PANEL]];
    }

    $final_keyboard_structure = ['inline_keyboard' => $keyboard_rows];
    // error_log("START_MENU: Returning keyboard structure: " . print_r($final_keyboard_structure, true));
    return $final_keyboard_structure;
}

function bot($method, $data = []) { $url = "https://api.telegram.org/bot" . API_TOKEN . "/" . $method; $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $data); $res = curl_exec($ch); curl_close($ch); return json_decode($res); }
function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = 'HTML') { bot('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageText($chat_id, $message_id, $text, $reply_markup = null, $parse_mode = 'HTML') { bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageCaption($chat_id, $message_id, $caption, $reply_markup = null, $parse_mode = 'HTML') { bot('editMessageCaption', ['chat_id' => $chat_id, 'message_id' => $message_id, 'caption' => $caption, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageReplyMarkup($chat_id, $message_id, $reply_markup = null) { bot('editMessageReplyMarkup', ['chat_id' => $chat_id, 'message_id' => $message_id, 'reply_markup' => $reply_markup]); }
function answerCallbackQuery($callback_query_id) { bot('answerCallbackQuery', ['callback_query_id' => $callback_query_id]); }
// Modify function signature to accept category_key and product_id
function forwardPhotoToAdmin($file_id, $caption, $original_user_id, $category_key, $product_id) {
    $admin_ids = getAdminIds();
    if(empty($admin_ids)) return;
    $admin_id = $admin_ids[0];

    $product_details = getProductDetails($category_key, $product_id);
    $product_type = $product_details['type'] ?? 'manual'; // Default to manual if type not set

    $accept_button_text = "✅ Accept";
    $accept_button_callback_data = CALLBACK_ACCEPT_PAYMENT_PREFIX . $original_user_id . "_" . $category_key . "_" . $product_id;

    if ($product_type === 'manual') {
        $accept_button_text = "✅ Accept & Send";
        $accept_button_callback_data = CALLBACK_ACCEPT_AND_SEND_PREFIX . $original_user_id . "_" . $category_key . "_" . $product_id;
    }

    // Reject button callback data remains the same, but needs all identifiers for consistency if rejection logic ever needs them.
    // The previous implementation already included category_key and product_id in reject_callback_data.
    $reject_callback_data = CALLBACK_REJECT_PAYMENT_PREFIX . $original_user_id . "_" . $category_key . "_" . $product_id;

    $approval_keyboard = json_encode(['inline_keyboard' => [
        [['text' => $accept_button_text, 'callback_data' => $accept_button_callback_data],
         ['text' => "❌ Reject", 'callback_data' => $reject_callback_data]]
    ]]);
    bot('sendPhoto', ['chat_id' => $admin_id, 'photo' => $file_id, 'caption' => $caption, 'parse_mode' => 'Markdown', 'reply_markup' => $approval_keyboard]);
}

function generateCategoryKeyboard($category_key) {
    // error_log("GEN_CAT_KB: Called for category: " . $category_key);
    global $products;

    $keyboard = ['inline_keyboard' => []];
    $category_products = $products[$category_key] ?? [];
    // error_log("GEN_CAT_KB: Products in this category ('" . $category_key . "'): " . print_r($category_products, true));

    // if (empty($category_products)) {
    //     error_log("GEN_CAT_KB: No products found in loop for category: " . $category_key);
    // }

    foreach ($category_products as $id => $details) {
        if (is_array($details) && isset($details['name']) && isset($details['price'])) {
            $product_display_name = $details['name'];
            $product_price = $details['price'];
            $callback_value = "{$category_key}_{$id}";
            // error_log("GEN_CAT_KB_PROD_CB: For category '{$category_key}', generated product callback: '" . $callback_value . "'");
            $keyboard['inline_keyboard'][] = [['text' => "{$product_display_name} - \${$product_price}", 'callback_data' => $callback_value]];
        } else {
            error_log("GEN_CAT_KB: Product ID '{$id}' in category '{$category_key}' has malformed details: " . print_r($details, true));
        }
    }
    $keyboard['inline_keyboard'][] = [['text' => '« Back to Main Menu', 'callback_data' => CALLBACK_BACK_TO_MAIN]];
    return json_encode($keyboard);
}

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

    error_log("PROCESS_CALLBACK_QUERY: Received data: '" . $data . "' | UserID: " . $user_id);

    if (strpos($data, CALLBACK_ADMIN_RP_CONF_YES_PREFIX) === 0) {
        // error_log("DEBUG PRE-ACK: RP_CONF_YES_PREFIX data received by processCallbackQuery. Data: " . $data);
    }

    answerCallbackQuery($callback_query->id);

    $user_specific_data = getUserData($user_id);
    if ($user_specific_data['is_banned']) {
        sendMessage($chat_id, "⚠️ You are banned from using this bot.");
        return;
    }

    if (strpos($data, 'view_category_') === 0) {
        // error_log("VIEW_CAT: Entered handler. Data: " . $data);
        global $products; $products = readJsonFile(PRODUCTS_FILE);

        $category_key_view = substr($data, strlen('view_category_'));
        // error_log("VIEW_CAT: Category key extracted: " . $category_key_view);

        $category_display_name_view = ucfirst(str_replace('_', ' ', $category_key_view));

        if (isset($products[$category_key_view]) && !empty($products[$category_key_view])) {
            $kb_category_products = generateCategoryKeyboard($category_key_view);
            editMessageText($chat_id, $message_id, "Please select a product from <b>" . htmlspecialchars($category_display_name_view) . "</b>:", $kb_category_products, 'HTML');
        } else {
            error_log("VIEW_CAT: Category '{$category_key_view}' is empty or not found in loaded products for display. Data: ".$data);
            $kb_empty_cat = json_encode(['inline_keyboard' => [[['text' => '« Back to Main Menu', 'callback_data' => CALLBACK_BACK_TO_MAIN]]]]);
            editMessageText($chat_id, $message_id, "Sorry, no products are currently available in the <b>" . htmlspecialchars($category_display_name_view) . "</b> category, or the category may have been recently updated.", $kb_empty_cat, 'HTML');
        }
        return;
    }

    elseif ($data === CALLBACK_MY_PRODUCTS) {
        $purchases_all_data = readJsonFile(USER_PURCHASES_FILE);
        $user_purchases_array = $purchases_all_data[$user_id] ?? [];

        $message_to_send = "<b>🛍️ Your Products:</b>\nClick on an item to view its details.";
        $keyboard_button_rows = [];

        if (empty($user_purchases_array)) {
            $message_to_send = "You have no products yet.";
        } else {
            foreach ($user_purchases_array as $index => $purchase_item) {
                $product_name_btn = htmlspecialchars($purchase_item['product_name']);
                // Ensure date is valid before formatting, fallback if not
                $purchase_date_str = $purchase_item['date'] ?? null;
                $purchase_date_btn = 'Unknown Date';
                if ($purchase_date_str && strtotime($purchase_date_str) !== false) {
                    $purchase_date_btn = date('d M Y', strtotime($purchase_date_str));
                }

                $emoji_btn = (isset($purchase_item['delivered_item_content']) && trim($purchase_item['delivered_item_content']) !== '') ? "📦" : "📄";

                $button_text_val = $emoji_btn . " " . $product_name_btn . " (" . $purchase_date_btn . ")";
                $keyboard_button_rows[] = [['text' => $button_text_val, 'callback_data' => CALLBACK_VIEW_PURCHASED_ITEM_PREFIX . $user_id . "_" . $index]];
            }
        }

        $keyboard_button_rows[] = [['text' => '« Back to Main Menu', 'callback_data' => CALLBACK_BACK_TO_MAIN]];
        $final_reply_markup = json_encode(['inline_keyboard' => $keyboard_button_rows]);

        editMessageText($chat_id, $message_id, $message_to_send, $final_reply_markup, 'HTML');
    }
    elseif (strpos($data, CALLBACK_VIEW_PURCHASED_ITEM_PREFIX) === 0) {
        answerCallbackQuery($callback_query->id); // Answer immediately to acknowledge button press

        $payload = substr($data, strlen(CALLBACK_VIEW_PURCHASED_ITEM_PREFIX)); // Expected: USERID_PURCHASEINDEX
        $parts = explode('_', $payload);

        $text_to_display = "";
        $keyboard_markup = json_encode(['inline_keyboard' => [[['text' => '« Back to My Products', 'callback_data' => CALLBACK_MY_PRODUCTS]]]]);

        if (count($parts) === 2) {
            $item_owner_id_from_cb = $parts[0];
            $purchase_index_from_cb = (int)$parts[1];

            if ((string)$user_id !== (string)$item_owner_id_from_cb) {
                error_log("VIEW_ITEM_DENIED: User {$user_id} attempted to view item for user {$item_owner_id_from_cb}. Denied. Callback: {$data}");
                // To prevent information leakage or confusion, edit the message to a generic error or back to My Products.
                // For simplicity, just showing an error text.
                $text_to_display = "⚠️ Action not allowed.";
                // No 'Back' button here as this is an unauthorized access attempt.
                // Or, could edit to "My Products" view again.
                // Let's keep it simple:
                editMessageText($chat_id, $message_id, $text_to_display, null, 'HTML'); // No keyboard for error
                return;
            }

            $all_purchases_data = readJsonFile(USER_PURCHASES_FILE);
            $user_specific_purchases_list = $all_purchases_data[$item_owner_id_from_cb] ?? [];

            if (isset($user_specific_purchases_list[$purchase_index_from_cb])) {
                $purchase_to_display = $user_specific_purchases_list[$purchase_index_from_cb];

                $text_to_display = "<b>Item:</b> " . htmlspecialchars($purchase_to_display['product_name']) . "\n";
                $text_to_display .= "<b>Purchased:</b> " . htmlspecialchars($purchase_to_display['date']) . "\n";
                if (isset($purchase_to_display['price'])) {
                     $text_to_display .= "<b>Price:</b> $" . htmlspecialchars($purchase_to_display['price']) . "\n";
                }
                $text_to_display .= "\n"; // Extra newline before details or note

                if (isset($purchase_to_display['delivered_item_content']) && trim($purchase_to_display['delivered_item_content']) !== '') {
                    $text_to_display .= "<b>Your item details:</b>\n<code>" . htmlspecialchars($purchase_to_display['delivered_item_content']) . "</code>";
                } else {
                    $text_to_display .= "This item was delivered manually or does not have specific viewable content here.";
                }
            } else {
                $text_to_display = "⚠️ Could not find this purchased item. It might have been removed or there was an error.";
                error_log("VIEW_ITEM_NOT_FOUND: Purchase item not found for user {$item_owner_id_from_cb} at index {$purchase_index_from_cb}. Callback: {$data}");
            }
        } else {
            $text_to_display = "⚠️ Error retrieving item details due to invalid data format.";
            error_log("VIEW_ITEM_INVALID_FORMAT: Invalid data format for viewing purchased item. Callback: {$data}");
        }

        editMessageText($chat_id, $message_id, $text_to_display, $keyboard_markup, 'HTML');
    }
    elseif ($data === CALLBACK_SUPPORT) {
        setUserState($user_id, ['status' => STATE_AWAITING_SUPPORT_MESSAGE, 'message_id' => $message_id]);
        $support_text = "❓Please describe your issue or question below.\nYour message will be forwarded to the admin team.\n\nType /cancel to abort sending a message.";
        $cancel_keyboard = json_encode(['inline_keyboard' => [[['text' => 'Cancel Support Request', 'callback_data' => CALLBACK_BACK_TO_MAIN]]]]);
        editMessageText($chat_id, $message_id, $support_text, $cancel_keyboard);
    }
    elseif ($data === CALLBACK_SUPPORT_CONFIRM) { /* Unused */ }

    elseif (strpos($data, 'admin_') === 0 || $data === CALLBACK_ADMIN_PANEL || $data === CALLBACK_ADMIN_PROD_MANAGEMENT || $data === CALLBACK_ADMIN_VIEW_STATS || $data === CALLBACK_ADMIN_CATEGORY_MANAGEMENT) {
        if (!$is_admin) {  sendMessage($chat_id, "Access denied."); return; }

        if ($data === CALLBACK_ADMIN_PANEL) {
            $admin_panel_keyboard_def = [
                'inline_keyboard' => [
                    [['text' => "📦 Product Management", 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]],
                    [['text' => "🗂️ Category Management", 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]],
                    [['text' => "🎫 Coupon Management", 'callback_data' => CALLBACK_ADMIN_COUPON_MANAGEMENT]],
                    [['text' => "📊 View Bot Stats", 'callback_data' => CALLBACK_ADMIN_VIEW_STATS]],
                    [['text' => '« Back to Main Menu', 'callback_data' => CALLBACK_BACK_TO_MAIN]]
                ]
            ];
            editMessageText($chat_id, $message_id, "⚙️ Admin Panel ⚙️", json_encode($admin_panel_keyboard_def));
            return;
        }
        elseif ($data === CALLBACK_ADMIN_COUPON_MANAGEMENT) {
            $coupon_mgt_keyboard = [
                'inline_keyboard' => [
                    [['text' => "➕ Add New Coupon", 'callback_data' => CALLBACK_ADMIN_ADD_COUPON_PROMPT]],
                    // [['text' => "✏️ View/Edit Coupons", 'callback_data' => 'admin_view_edit_coupons']], // Placeholder for Phase 2/3
                    // [['text' => "📊 Coupon Stats", 'callback_data' => 'admin_coupon_stats']], // Placeholder for Phase 2/3
                    [['text' => '« Back to Admin Panel', 'callback_data' => CALLBACK_ADMIN_PANEL]]
                ]
            ];
            editMessageText($chat_id, $message_id, "🎫 Coupon Management 🎫\nSelect an action:", json_encode($coupon_mgt_keyboard));
            return;
        }
        elseif ($data === CALLBACK_ADMIN_ADD_COUPON_PROMPT) {
            setUserState($user_id, ['status' => STATE_ADMIN_ADDING_COUPON_CODE, 'original_message_id' => $message_id, 'coupon_data' => [] ]);
            editMessageText($chat_id, $message_id, "Enter the new coupon code (e.g., SUMMER20, SAVE15OFF).\n\n- It will be stored in UPPERCASE.\n- Should be unique.\n- Alphanumeric characters recommended.\n\nType /cancel to abort.", null);
            return;
        }
        elseif (strpos($data, CALLBACK_ADMIN_SET_COUPON_TYPE_PERCENTAGE) === 0 || strpos($data, CALLBACK_ADMIN_SET_COUPON_TYPE_FIXED) === 0) {
            $user_state_coupon_type = getUserState($user_id);
            if (!$user_state_coupon_type || ($user_state_coupon_type['status'] ?? '') !== STATE_ADMIN_ADDING_COUPON_TYPE) {
                answerCallbackQuery($callback_query->id, "Invalid action or session expired. Please start over.", true);
                error_log("COUPON_ADD: Invalid state for setting coupon type. User: {$user_id}, State: " . print_r($user_state_coupon_type, true));
                return;
            }

            $chosen_type = (strpos($data, CALLBACK_ADMIN_SET_COUPON_TYPE_PERCENTAGE) === 0) ? 'percentage' : 'fixed_amount';
            $user_state_coupon_type['coupon_data']['discount_type'] = $chosen_type;
            $user_state_coupon_type['status'] = STATE_ADMIN_ADDING_COUPON_VALUE;
            setUserState($user_id, $user_state_coupon_type);

            $prompt_value_text = "Selected type: " . ucfirst($chosen_type) . ".\n";
            if ($chosen_type === 'percentage') {
                $prompt_value_text .= "Enter the discount percentage (e.g., for 10% enter 10). Must be between 1 and 100.";
            } else {
                $prompt_value_text .= "Enter the fixed discount amount (e.g., for $5 off enter 5). Must be a positive number.";
            }
            $prompt_value_text .= "\n\nType /cancel to abort.";
            editMessageText($chat_id, $message_id, $prompt_value_text, null); // $message_id is from the coupon type selection message
            return;
        }
        elseif ($data === CALLBACK_ADMIN_CATEGORY_MANAGEMENT) {
            $cat_mgt_keyboard = [
                'inline_keyboard' => [
                    [['text' => "➕ Add Category", 'callback_data' => CALLBACK_ADMIN_ADD_CATEGORY_PROMPT]],
                    [['text' => "✏️ Edit Category Name", 'callback_data' => CALLBACK_ADMIN_EDIT_CATEGORY_SELECT]],
                    [['text' => "➖ Remove Category", 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_SELECT]],
                    [['text' => '« Back to Admin Panel', 'callback_data' => CALLBACK_ADMIN_PANEL]]
                ]
            ];
            editMessageText($chat_id, $message_id, "🗂️ Category Management 🗂️\nSelect an action:", json_encode($cat_mgt_keyboard));
            return;
        }
        elseif ($data === CALLBACK_ADMIN_ADD_CATEGORY_PROMPT) {
            setUserState($user_id, ['status' => STATE_ADMIN_ADDING_CATEGORY_NAME, 'original_message_id' => $message_id]);
            editMessageText($chat_id, $message_id, "Enter the new category key (e.g., 'new_category_key').\n\nIt should be:\n- Unique\n- Alphanumeric characters and underscores only (a-z, 0-9, _)\n- E.g., `action_figures`, `digital_services_2`\n\nType /cancel to abort.", null);
            return;
        }
        elseif ($data === CALLBACK_ADMIN_EDIT_CATEGORY_SELECT) {
            global $products; $products = readJsonFile(PRODUCTS_FILE);
            $category_keys = array_keys($products);
            $keyboard_rows = [];

            if (empty($category_keys)) {
                editMessageText($chat_id, $message_id, "No categories exist to edit.", json_encode(['inline_keyboard' => [[['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]]]]));
                return;
            }

            foreach ($category_keys as $cat_key) {
                $display_name = ucfirst(str_replace('_', ' ', $cat_key));
                $keyboard_rows[] = [['text' => "✏️ " . htmlspecialchars($display_name), 'callback_data' => CALLBACK_ADMIN_EDIT_CATEGORY_PROMPT_PREFIX . $cat_key]];
            }
            $keyboard_rows[] = [['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]];
            editMessageText($chat_id, $message_id, "Select a category to rename:", json_encode(['inline_keyboard' => $keyboard_rows]));
            return;
        }
        elseif (strpos($data, CALLBACK_ADMIN_EDIT_CATEGORY_PROMPT_PREFIX) === 0) {
            $old_category_key = substr($data, strlen(CALLBACK_ADMIN_EDIT_CATEGORY_PROMPT_PREFIX));
            setUserState($user_id, ['status' => STATE_ADMIN_EDITING_CATEGORY_NAME, 'old_category_key' => $old_category_key, 'original_message_id' => $message_id]);
            $display_old_key = htmlspecialchars(ucfirst(str_replace('_', ' ', $old_category_key)));
            editMessageText($chat_id, $message_id, "Editing category: <b>{$display_old_key}</b> (key: `{$old_category_key}`)\n\nEnter the new unique category key.\n\nIt should be alphanumeric with underscores (e.g., `new_key_1`).\nType /cancel to abort.", null, 'HTML');
            return;
        }
        elseif ($data === CALLBACK_ADMIN_REMOVE_CATEGORY_SELECT) {
            global $products; $products = readJsonFile(PRODUCTS_FILE);
            $category_keys = array_keys($products);
            $keyboard_rows = [];

            if (empty($category_keys)) {
                editMessageText($chat_id, $message_id, "No categories exist to remove.", json_encode(['inline_keyboard' => [[['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]]]]));
                return;
            }

            foreach ($category_keys as $cat_key) {
                $display_name = ucfirst(str_replace('_', ' ', $cat_key));
                $is_empty = empty($products[$cat_key]);
                $emoji = $is_empty ? "🗑️" : "⚠️";
                $keyboard_rows[] = [['text' => "{$emoji} " . htmlspecialchars($display_name), 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_CONFIRM_PREFIX . $cat_key]];
            }
            $keyboard_rows[] = [['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]];
            editMessageText($chat_id, $message_id, "Select a category to remove (⚠️ items in non-empty categories will also be deleted):", json_encode(['inline_keyboard' => $keyboard_rows]));
            return;
        }
        elseif (strpos($data, CALLBACK_ADMIN_REMOVE_CATEGORY_CONFIRM_PREFIX) === 0) {
            global $products; $products = readJsonFile(PRODUCTS_FILE);
            $category_to_remove = substr($data, strlen(CALLBACK_ADMIN_REMOVE_CATEGORY_CONFIRM_PREFIX));

            if (!isset($products[$category_to_remove])) {
                editMessageText($chat_id, $message_id, "Error: Category '".htmlspecialchars($category_to_remove)."' not found. It might have already been removed.", json_encode(['inline_keyboard' => [[['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]]]]));
                return;
            }

            $is_empty = empty($products[$category_to_remove]);
            $display_cat_name = htmlspecialchars(ucfirst(str_replace('_', ' ', $category_to_remove)));
            $kb_confirm_remove = [];

            if ($is_empty) {
                $confirm_text = "Category '<b>{$display_cat_name}</b>' (key: `{$category_to_remove}`) is empty.\nAre you sure you want to remove it?";
                $kb_confirm_remove[] = [['text' => "✅ Yes, Remove Empty Category", 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_DO_PREFIX . $category_to_remove . "_empty"]];
            } else {
                $product_count = count($products[$category_to_remove]);
                $confirm_text = "⚠️ <b>DANGER ZONE</b> ⚠️\nCategory '<b>{$display_cat_name}</b>' (key: `{$category_to_remove}`) contains <b>{$product_count} product(s)</b>.\n\nRemoving this category will also <b>PERMANENTLY DELETE ALL PRODUCTS</b> under it. This action is irreversible.\n\nAre you absolutely sure you want to proceed?";
                $kb_confirm_remove[] = [['text' => "☠️ YES, DELETE Category & {$product_count} Product(s)", 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_DO_PREFIX . $category_to_remove . "_withproducts"]];
            }
            $kb_confirm_remove[] = [['text' => "❌ No, Cancel", 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_SELECT]];
            $kb_confirm_remove[] = [['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]];
            editMessageText($chat_id, $message_id, $confirm_text, json_encode(['inline_keyboard' => $kb_confirm_remove]), 'HTML');
            return;
        }
        elseif (strpos($data, CALLBACK_ADMIN_REMOVE_CATEGORY_DO_PREFIX) === 0) {
            $parts_str = substr($data, strlen(CALLBACK_ADMIN_REMOVE_CATEGORY_DO_PREFIX));
            $last_underscore_pos = strrpos($parts_str, '_');
            if ($last_underscore_pos === false) {
                error_log("Invalid format for CALLBACK_ADMIN_REMOVE_CATEGORY_DO_PREFIX: {$data}");
                editMessageText($chat_id, $message_id, "Error processing removal command due to invalid format.", json_encode(['inline_keyboard' => [[['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]]]]));
                return;
            }

            $category_to_delete = substr($parts_str, 0, $last_underscore_pos);
            $action_type = substr($parts_str, $last_underscore_pos + 1);

            global $products;
            if (empty($products)) { $products = readJsonFile(PRODUCTS_FILE); }

            $display_cat_name_deleted = htmlspecialchars(ucfirst(str_replace('_', ' ', $category_to_delete)));

            if (!isset($products[$category_to_delete])) {
                 editMessageText($chat_id, $message_id, "Error: Category '{$display_cat_name_deleted}' (key: `{$category_to_delete}`) was not found for deletion. It might have already been removed.", json_encode(['inline_keyboard' => [[['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]]]]), 'HTML');
                 return;
            }

            unset($products[$category_to_delete]);

            if (writeJsonFile(PRODUCTS_FILE, $products)) {
                $success_message = "✅ Category '<b>{$display_cat_name_deleted}</b>' (key: `{$category_to_delete}`)";
                if ($action_type === "withproducts") {
                    $success_message .= " and all its associated products have been deleted.";
                } else {
                    $success_message .= " has been removed successfully.";
                }
                $cat_mgt_keyboard_after_delete = [
                    'inline_keyboard' => [
                        [['text' => "➕ Add Category", 'callback_data' => CALLBACK_ADMIN_ADD_CATEGORY_PROMPT]],
                        [['text' => "✏️ Edit Category Name", 'callback_data' => CALLBACK_ADMIN_EDIT_CATEGORY_SELECT]],
                        [['text' => "➖ Remove Category", 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_SELECT]],
                        [['text' => '« Back to Admin Panel', 'callback_data' => CALLBACK_ADMIN_PANEL]]
                    ]
                ];
                editMessageText($chat_id, $message_id, $success_message . "\n\n🗂️ Category Management 🗂️\nSelect an action:", json_encode($cat_mgt_keyboard_after_delete), 'HTML');
            } else {
                error_log("Failed to write products file after removing category {$category_to_delete}. The category might still appear until bot restart if memory wasn't updated from disk properly.");
                editMessageText($chat_id, $message_id, "⚠️ Failed to save changes after attempting to remove category '<b>{$display_cat_name_deleted}</b>'. Please check server logs or file permissions. The category might not be fully removed from the data file.", json_encode(['inline_keyboard' => [[['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]]]]), 'HTML');
            }
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
            global $products; $products = readJsonFile(PRODUCTS_FILE);
            $category_keys = array_keys($products);
            $keyboard_rows = [];
            if(empty($category_keys)) {
                 setUserState($user_id, ['status' => STATE_ADMIN_ADDING_PROD_NAME, 'category_key' => 'default']);
                 editMessageText($chat_id, $message_id, "No categories exist yet. Adding to 'default' category.\nEnter the product name:", null); return;
            }
            foreach ($category_keys as $cat_key) {
                $keyboard_rows[] = [['text' => ucfirst(str_replace('_', ' ', $cat_key)), 'callback_data' => CALLBACK_ADMIN_AP_CAT_PREFIX . $cat_key]];
            }
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
            if(!$user_state || !isset($user_state['status']) || $user_state['status'] !== STATE_ADMIN_ADDING_PROD_TYPE_PROMPT) {
                return;
            }
            $user_state['new_product_type'] = ($data === CALLBACK_ADMIN_SET_PROD_TYPE_INSTANT) ? 'instant' : 'manual';
            $user_state['status'] = STATE_ADMIN_ADDING_PROD_PRICE;
            setUserState($user_id, $user_state);
            editMessageText($chat_id, $message_id, "Type set to: {$user_state['new_product_type']}.\nEnter the price for '{$user_state['new_product_name']}': (numbers only)", null);
        }

        elseif ($data === CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY) {
            global $products; $products = readJsonFile(PRODUCTS_FILE);
            if (empty($products)) { editMessageText($chat_id, $message_id, "No categories found to edit products from.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]]]])); return; }
            $keyboard_rows = [];
            foreach (array_keys($products) as $ck) { $keyboard_rows[] = [['text' => ucfirst(str_replace('_', ' ', $ck)), 'callback_data' => CALLBACK_ADMIN_EP_SCAT_PREFIX . $ck]]; }
            $keyboard_rows[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]];
            editMessageText($chat_id, $message_id, "Select category to edit products from:", json_encode(['inline_keyboard' => $keyboard_rows]));
        }
        elseif (strpos($data, CALLBACK_ADMIN_EP_SCAT_PREFIX) === 0) {
            global $products; $products = readJsonFile(PRODUCTS_FILE);
            $category_key = substr($data, strlen(CALLBACK_ADMIN_EP_SCAT_PREFIX));
            if (!isset($products[$category_key]) || empty($products[$category_key])) { editMessageText($chat_id, $message_id, "No products in '" . htmlspecialchars($category_key)."'.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY]]]])); return; }
            $keyboard_rows = [];
            foreach ($products[$category_key] as $pid => $pdetails) { $keyboard_rows[] = [['text' => htmlspecialchars($pdetails['name']) . " (\${$pdetails['price']})", 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . "{$category_key}_{$pid}"]]; }
            $keyboard_rows[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY]];
            editMessageText($chat_id, $message_id, "Select product to edit in '" . htmlspecialchars($category_key) . "':", json_encode(['inline_keyboard' => $keyboard_rows]));
        }
        elseif (strpos($data, CALLBACK_ADMIN_EP_SPRO_PREFIX) === 0) {
            $ids_str = substr($data, strlen(CALLBACK_ADMIN_EP_SPRO_PREFIX));
            $category_key = null;
            $product_id = null;

            global $products;
            if(empty($products)) $products = readJsonFile(PRODUCTS_FILE);

            // Get category keys and sort them by length, descending to match longest possible key first
            $category_keys_from_file = array_keys($products);
            usort($category_keys_from_file, function($a, $b) {
                return strlen($b) - strlen($a); // Sort by length descending
            });

            foreach ($category_keys_from_file as $known_cat_key) { // Iterate through sorted keys
                if (strpos($ids_str, $known_cat_key . '_') === 0) {
                    $category_key = $known_cat_key;
                    $product_id = substr($ids_str, strlen($known_cat_key) + 1);
                    break;
                }
            }

            if (!$category_key || !$product_id) {
                error_log("EP_SPRO_PARSE_FAIL: Failed to parse category/product from data: {$data}. Derived ids_str: {$ids_str}. Products keys evaluated: " . implode(", ", array_keys($products)));
                editMessageText($chat_id, $message_id, "Error: Could not determine product from callback data. Invalid format.", json_encode(['inline_keyboard'=>[[['text'=>'« Back', 'callback_data'=>CALLBACK_ADMIN_PROD_MANAGEMENT]]]]));
                return;
            }

            error_log("EP_SPRO_PRE_GET: Attempting getProductDetails with Category: '{$category_key}', ProductID: '{$product_id}' from Data: {$data}");

            $p = getProductDetails($category_key, $product_id);
            if (!$p) {
                error_log("EP_SPRO_NOT_FOUND: Product not found. Data: {$data}, Parsed Category: {$category_key}, Parsed ProductID: {$product_id}");
                // Construct a safe fallback category key for the error keyboard, in case $category_key itself is problematic.
                // However, if parsing failed, we'd return above. If it succeeded, $category_key should be valid from $products.
                $callback_cat_key_for_error_kb = $category_key;
                if (!isset($products[$category_key])) { // If somehow the parsed category_key isn't in products, don't use it for callback
                    // This case should ideally be caught by !$category_key check, but as a safeguard:
                    $callback_cat_key_for_error_kb = CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY; // Go way back
                     error_log("EP_SPRO_NOT_FOUND_INVALID_CAT_FOR_KB: Parsed category '{$category_key}' not in products. Using generic callback.");
                }
                $error_kb = json_encode(['inline_keyboard' => [[['text' => '« Back to Product List', 'callback_data' => CALLBACK_ADMIN_EP_SCAT_PREFIX . $callback_cat_key_for_error_kb]]]]);
                editMessageText($chat_id, $message_id, "Error: Product '" . htmlspecialchars($product_id) . "' in category '" . htmlspecialchars($category_key) . "' not found. It might have been removed or the ID is incorrect.", $error_kb);
                return;
            }
            $kb_rows_edit_prod = [
                [['text' => "✏️ Edit Name", 'callback_data' => CALLBACK_ADMIN_EDIT_NAME_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => "💲 Edit Price", 'callback_data' => CALLBACK_ADMIN_EDIT_PRICE_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => "ℹ️ Edit Info/Description", 'callback_data' => CALLBACK_ADMIN_EDIT_INFO_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => "🔄 Edit Type (current: {$p['type']})", 'callback_data' => CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX . "{$category_key}_{$product_id}"]],
            ];
            if ($p['type'] === 'instant') {
                $item_count = count($p['items'] ?? []);
                $kb_rows_edit_prod[] = [['text' => "🗂️ Manage Instant Items ({$item_count})", 'callback_data' => CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX . "{$category_key}_{$product_id}"]];
            }
            $kb_rows_edit_prod[] = [['text' => '« Back to Product List', 'callback_data' => CALLBACK_ADMIN_EP_SCAT_PREFIX . $category_key]];
            editMessageText($chat_id, $message_id, "Editing Product: <b>".htmlspecialchars($p['name'])."</b>\nID: {$product_id}\nSelect what you want to edit:", json_encode(['inline_keyboard' => $kb_rows_edit_prod]), 'HTML');
        }
        elseif (strpos($data, CALLBACK_ADMIN_EDIT_NAME_PREFIX) === 0 || strpos($data, CALLBACK_ADMIN_EDIT_PRICE_PREFIX) === 0 || strpos($data, CALLBACK_ADMIN_EDIT_INFO_PREFIX) === 0) {
            $field_to_edit = '';
            $prefix_len = 0;
            if(strpos($data, CALLBACK_ADMIN_EDIT_NAME_PREFIX) === 0) { $field_to_edit = 'name'; $prefix_len = strlen(CALLBACK_ADMIN_EDIT_NAME_PREFIX); }
            elseif(strpos($data, CALLBACK_ADMIN_EDIT_PRICE_PREFIX) === 0) { $field_to_edit = 'price'; $prefix_len = strlen(CALLBACK_ADMIN_EDIT_PRICE_PREFIX); }
            else { $field_to_edit = 'info'; $prefix_len = strlen(CALLBACK_ADMIN_EDIT_INFO_PREFIX); }

            $ids_str = substr($data, $prefix_len);
            if (!preg_match('/^(.+)_([^_]+)$/', $ids_str, $matches_ids)) {
                error_log("Error parsing IDs for edit field '{$field_to_edit}': {$data}");
                editMessageText($chat_id, $message_id, "Error processing command. Invalid format for editing field.", null); return;
            }
            $category_key = $matches_ids[1]; $product_id = $matches_ids[2];

            $p = getProductDetails($category_key, $product_id);
            if(!$p) {
                error_log("Edit Field '{$field_to_edit}': Product not found. Cat:{$category_key}, Prod:{$product_id}, Data: {$data}");
                editMessageText($chat_id, $message_id, "Error: Product not found for editing.", null); return;
            }
            setUserState($user_id, ['status' => STATE_ADMIN_EDITING_PROD_FIELD, 'field_to_edit' => $field_to_edit, 'category_key' => $category_key, 'product_id' => $product_id, 'original_message_id' => $message_id]);
            $current_val_display = $p[$field_to_edit] ?? ($field_to_edit === 'info' ? '(empty)' : '');
            editMessageText($chat_id, $message_id, "Current product ".htmlspecialchars($field_to_edit).": \"".htmlspecialchars($current_val_display)."\"\nPlease send the new ".htmlspecialchars($field_to_edit)." for '".htmlspecialchars($p['name'])."'.\nOr type /cancel to abort.", null);
        }
        elseif (strpos($data, CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2]; $p = getProductDetails($category_key, $product_id);
            if(!$p) {
                error_log("Edit Type Prompt: Product not found. Cat:{$category_key}, Prod:{$product_id}, Data: {$data}");
                editMessageText($chat_id, $message_id, "Error: Product not found for editing type.", json_encode(['inline_keyboard' => [[['text' => '« Back to Edit Options', 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . $category_key . "_" . $product_id ]]]])); return;
            }
            $kb_edit_type = [
                [['text' => '📦 Set to Instant Delivery', 'callback_data' => CALLBACK_ADMIN_SET_TYPE_TO_INSTANT_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => '👤 Set to Manual Delivery', 'callback_data' => CALLBACK_ADMIN_SET_TYPE_TO_MANUAL_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => '« Back to Edit Options', 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . "{$category_key}_{$product_id}"]]
            ];
            editMessageText($chat_id, $message_id, "Current product type for '".htmlspecialchars($p['name'])."': <b>{$p['type']}</b>.\nSelect new delivery type:", json_encode(['inline_keyboard'=>$kb_edit_type]), 'HTML');
        }
        elseif (strpos($data, CALLBACK_ADMIN_SET_TYPE_TO_INSTANT_PREFIX) === 0 || strpos($data, CALLBACK_ADMIN_SET_TYPE_TO_MANUAL_PREFIX) === 0) {
            $new_type = (strpos($data, CALLBACK_ADMIN_SET_TYPE_TO_INSTANT_PREFIX) === 0) ? 'instant' : 'manual';
            $prefix_len_set_type = strlen(($new_type === 'instant') ? CALLBACK_ADMIN_SET_TYPE_TO_INSTANT_PREFIX : CALLBACK_ADMIN_SET_TYPE_TO_MANUAL_PREFIX);
            $ids_str_set_type = substr($data, $prefix_len_set_type);

            if (!preg_match('/^(.+)_([^_]+)$/', $ids_str_set_type, $matches_ids_set_type)) {
                error_log("Error parsing IDs for set type '{$new_type}': {$data}");
                editMessageText($chat_id, $message_id, "Error processing command to set type. Invalid format.", null); return;
            }
            $category_key = $matches_ids_set_type[1]; $product_id = $matches_ids_set_type[2];

            global $products; if(empty($products)) $products = readJsonFile(PRODUCTS_FILE);
            if(isset($products[$category_key][$product_id])) {
                $old_type = $products[$category_key][$product_id]['type'];
                $products[$category_key][$product_id]['type'] = $new_type;
                if($new_type === 'instant' && !isset($products[$category_key][$product_id]['items'])) {
                    $products[$category_key][$product_id]['items'] = [];
                } elseif ($new_type === 'manual' && isset($products[$category_key][$product_id]['items'])) {
                }

                if(writeJsonFile(PRODUCTS_FILE, $products)) {
                    $p_updated_type = getProductDetails($category_key, $product_id);
                    $kb_re_type = [
                        [['text' => "✏️ Edit Name", 'callback_data' => CALLBACK_ADMIN_EDIT_NAME_PREFIX . "{$category_key}_{$product_id}"]],
                        [['text' => "💲 Edit Price", 'callback_data' => CALLBACK_ADMIN_EDIT_PRICE_PREFIX . "{$category_key}_{$product_id}"]],
                        [['text' => "ℹ️ Edit Info/Description", 'callback_data' => CALLBACK_ADMIN_EDIT_INFO_PREFIX . "{$category_key}_{$product_id}"]],
                        [['text' => "🔄 Edit Type (current: {$p_updated_type['type']})", 'callback_data' => CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX . "{$category_key}_{$product_id}"]],
                    ];
                    if ($p_updated_type['type'] === 'instant') {
                        $item_count_re = count($p_updated_type['items'] ?? []);
                        $kb_re_type[] = [['text' => "🗂️ Manage Instant Items ({$item_count_re})", 'callback_data' => CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX . "{$category_key}_{$product_id}"]];
                    }
                    $kb_re_type[] = [['text' => '« Back to Product List', 'callback_data' => CALLBACK_ADMIN_EP_SCAT_PREFIX . $category_key]];
                    editMessageText($chat_id, $message_id, "✅ Product type for '".htmlspecialchars($p_updated_type['name'])."' changed from '{$old_type}' to '{$new_type}'.\nEditing Product: <b>".htmlspecialchars($p_updated_type['name'])."</b>", json_encode(['inline_keyboard' => $kb_re_type]), 'HTML');
                } else {
                     editMessageText($chat_id, $message_id, "⚠️ Error saving product type change for '".htmlspecialchars($products[$category_key][$product_id]['name'])."'. Please check server logs/permissions.", json_encode(['inline_keyboard' => [[['text' => '« Back to Edit Type Prompt', 'callback_data' => CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX . $category_key . "_" . $product_id ]]]]));
                }
            } else {
                 error_log("Set Type: Product not found when attempting to change type. Cat:{$category_key}, Prod:{$product_id}, Data: {$data}");
                 editMessageText($chat_id, $message_id, "Error: Product not found when attempting to set type.", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Product List', 'callback_data'=>CALLBACK_ADMIN_EP_SCAT_PREFIX.$category_key]]]]));
            }
        }
        elseif (strpos($data, CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2];
            $p = getProductDetails($category_key, $product_id);
            if (!$p || $p['type'] !== 'instant') {
                error_log("Manage Items: Product not instant or not found. Cat:{$category_key}, Prod:{$product_id}, Data: {$data}");
                editMessageText($chat_id, $message_id, "Error: This product is not an 'instant delivery' type or was not found.", json_encode(['inline_keyboard' => [[['text' => '« Back to Edit Options', 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . $category_key . "_" . $product_id ]]]]));
                return;
            }
            $items_count_manage = count($p['items'] ?? []);
            $kb_rows_manage_items = [[['text' => '➕ Add New Item', 'callback_data' => CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX . "{$category_key}_{$product_id}"]]];
            if ($items_count_manage > 0) $kb_rows_manage_items[] = [['text' => '➖ Remove An Item', 'callback_data' => CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX . "{$category_key}_{$product_id}"]];
            $kb_rows_manage_items[] = [['text' => '« Back to Edit Options', 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . "{$category_key}_{$product_id}"]];
            editMessageText($chat_id, $message_id, "<b>Managing Instant Items for: ".htmlspecialchars($p['name'])."</b>\nCurrently stocked: {$items_count_manage} item(s).", json_encode(['inline_keyboard' => $kb_rows_manage_items]), 'HTML');
        }
        elseif (strpos($data, CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2];
            $p = getProductDetails($category_key, $product_id);
            if (!$p || $p['type'] !== 'instant') {
                error_log("Add Inst Item Prompt: Product not instant or not found. Cat:{$category_key}, Prod:{$product_id}, Data: {$data}");
                editMessageText($chat_id, $message_id, "Error: Product not found or is not an 'instant delivery' type.", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Manage Items', 'callback_data'=>CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX."{$category_key}_{$product_id}"]]]])); return;
            }
            setUserState($user_id, ['status' => STATE_ADMIN_ADDING_SINGLE_INSTANT_ITEM, 'category_key' => $category_key, 'product_id' => $product_id, 'original_message_id' => $message_id]);
            editMessageText($chat_id, $message_id, "Please send the new item content for '".htmlspecialchars($p['name'])."'. This could be a code, a link, or account details.\nType /cancel to abort.", null);
        }
        elseif (strpos($data, CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2];
            $p = getProductDetails($category_key, $product_id);
            if (!$p || $p['type'] !== 'instant') {
                 error_log("Remove Inst Item List: Product not instant or not found. Cat:{$category_key}, Prod:{$product_id}, Data: {$data}");
                 editMessageText($chat_id, $message_id, "Error: Product not found or not an 'instant delivery' type for item removal.", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Manage Items', 'callback_data'=>CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX."{$category_key}_{$product_id}"]]]])); return;
            }
            if (empty($p['items'])) {
                editMessageText($chat_id, $message_id, "No items to remove for ".htmlspecialchars($p['name']).".", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Manage Items', 'callback_data'=>CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX."{$category_key}_{$product_id}"]]]])); return;
            }
            $kb_items_remove = []; foreach($p['items'] as $idx => $item_content) { $display_text = strlen($item_content) > 30 ? substr(htmlspecialchars($item_content),0,27).'...' : htmlspecialchars($item_content); $kb_items_remove[] = [['text' => "❌ {$display_text}", 'callback_data' => CALLBACK_ADMIN_REMOVE_INST_ITEM_DO_PREFIX."{$category_key}_{$product_id}_{$idx}"]]; }
            $kb_items_remove[] = [['text'=>'« Back to Manage Items', 'callback_data'=>CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX."{$category_key}_{$product_id}"]];
            editMessageText($chat_id, $message_id, "Select item to remove for ".htmlspecialchars($p['name']).":", json_encode(['inline_keyboard'=>$kb_items_remove]));
        }
        elseif (strpos($data, CALLBACK_ADMIN_REMOVE_INST_ITEM_DO_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_REMOVE_INST_ITEM_DO_PREFIX, '/') . '(.+)_([^_]+)_(\d+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2]; $item_idx_to_remove = (int)$matches[3];
            global $products; if(empty($products)) $products = readJsonFile(PRODUCTS_FILE);

            if(isset($products[$category_key][$product_id]['items'][$item_idx_to_remove])) {
                array_splice($products[$category_key][$product_id]['items'], $item_idx_to_remove, 1);
                if(writeJsonFile(PRODUCTS_FILE, $products)){
                    $p_updated_after_remove = getProductDetails($category_key, $product_id);
                    $items_count_after_remove = count($p_updated_after_remove['items'] ?? []);
                    $kb_rows_after_remove = [[['text' => '➕ Add New Item', 'callback_data' => CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX . "{$category_key}_{$product_id}"]]];
                    if ($items_count_after_remove > 0) $kb_rows_after_remove[] = [['text' => '➖ Remove An Item', 'callback_data' => CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX . "{$category_key}_{$product_id}"]];
                    $kb_rows_after_remove[] = [['text' => '« Back to Edit Options', 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . "{$category_key}_{$product_id}"]];
                    editMessageText($chat_id, $message_id, "✅ Item removed successfully.\n<b>Managing Instant Items for: ".htmlspecialchars($p_updated_after_remove['name'])."</b>\nCurrently stocked: {$items_count_after_remove} item(s).", json_encode(['inline_keyboard' => $kb_rows_after_remove]), 'HTML');
                } else {
                     error_log("Remove Inst Item Do: Failed to write products file after removing item. Cat:{$category_key}, Prod:{$product_id}, ItemIdx: {$item_idx_to_remove}");
                     editMessageText($chat_id, $message_id, "⚠️ Error saving item removal. Please check server logs/permissions.", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Manage Items', 'callback_data'=>CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX."{$category_key}_{$product_id}"]]]]));
                }
            } else {
                error_log("Remove Inst Item Do: Item not found at index. Cat:{$category_key}, Prod:{$product_id}, ItemIdx: {$item_idx_to_remove}, Data: {$data}");
                editMessageText($chat_id, $message_id, "Error: Item not found or already removed. It might have been removed in another action.", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Manage Items', 'callback_data'=>CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX."{$category_key}_{$product_id}"]]]]));
            }
        }

        elseif ($data === CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY) {
            global $products; $products = readJsonFile(PRODUCTS_FILE);
            if (empty($products)) { editMessageText($chat_id, $message_id, "No categories found to remove products from.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]]]])); return; }
            $keyboard_rows_rem_cat = [];
            foreach (array_keys($products) as $ck_rem) { $keyboard_rows_rem_cat[] = [['text' => ucfirst(str_replace('_', ' ', $ck_rem)), 'callback_data' => CALLBACK_ADMIN_RP_SCAT_PREFIX . $ck_rem]]; }
            $keyboard_rows_rem_cat[] = [['text' => '« Back to Product Mgt', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]];
            editMessageText($chat_id, $message_id, "Select category to remove product from:", json_encode(['inline_keyboard' => $keyboard_rows_rem_cat]));
        }
        elseif (strpos($data, CALLBACK_ADMIN_RP_SCAT_PREFIX) === 0) {
            global $products; $products = readJsonFile(PRODUCTS_FILE);
            $category_key_rem_prod = substr($data, strlen(CALLBACK_ADMIN_RP_SCAT_PREFIX));
            if (!isset($products[$category_key_rem_prod]) || empty($products[$category_key_rem_prod])) { editMessageText($chat_id, $message_id, "No products in category '".htmlspecialchars($category_key_rem_prod)."' to remove.", json_encode(['inline_keyboard' => [[['text' => '« Back to Select Category', 'callback_data' => CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY]]]])); return; }
            $keyboard_rows_rem_prod = [];
            foreach ($products[$category_key_rem_prod] as $pid_rem => $pdetails_rem) { $keyboard_rows_rem_prod[] = [['text' => "➖ ".htmlspecialchars($pdetails_rem['name']), 'callback_data' => CALLBACK_ADMIN_RP_SPRO_PREFIX . "{$category_key_rem_prod}_{$pid_rem}"]]; }
            $keyboard_rows_rem_prod[] = [['text' => '« Back to Select Category', 'callback_data' => CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY]];
            editMessageText($chat_id, $message_id, "Select product to REMOVE from '".htmlspecialchars($category_key_rem_prod)."':\n(⚠️ This action is permanent!)", json_encode(['inline_keyboard' => $keyboard_rows_rem_prod]), 'HTML');
        }
        elseif (strpos($data, CALLBACK_ADMIN_RP_SPRO_PREFIX) === 0) {
            $ids_str_rp = substr($data, strlen(CALLBACK_ADMIN_RP_SPRO_PREFIX));
            $category_key_rem_confirm = null;
            $product_id_rem_confirm = null;

            global $products;
            if(empty($products)) $products = readJsonFile(PRODUCTS_FILE);

            foreach (array_keys($products) as $known_cat_key_rp) {
                if (strpos($ids_str_rp, $known_cat_key_rp . '_') === 0) {
                    $category_key_rem_confirm = $known_cat_key_rp;
                    $product_id_rem_confirm = substr($ids_str_rp, strlen($known_cat_key_rp) + 1);
                    break;
                }
            }

            if (!$category_key_rem_confirm || !$product_id_rem_confirm) {
                error_log("RP_SPRO: Failed to parse category/product from data: {$data}. Derived ids_str: {$ids_str_rp}");
                editMessageText($chat_id, $message_id, "Error: Could not determine product for removal from callback data. Invalid format.", json_encode(['inline_keyboard'=>[[['text'=>'« Back', 'callback_data'=>CALLBACK_ADMIN_PROD_MANAGEMENT]]]]));
                return;
            }

            $p_rem_confirm = getProductDetails($category_key_rem_confirm, $product_id_rem_confirm);
            if(!$p_rem_confirm) {
                error_log("RP_SPRO (Confirm): Product not found. Data: {$data}, Parsed Category: {$category_key_rem_confirm}, Parsed ProductID: {$product_id_rem_confirm}");
                $error_kb_rem_confirm = json_encode(['inline_keyboard' => [[['text' => '« Back to Product List', 'callback_data' => CALLBACK_ADMIN_RP_SCAT_PREFIX . $category_key_rem_confirm]]]]);
                editMessageText($chat_id, $message_id, "Error: Product '" . htmlspecialchars($product_id_rem_confirm) . "' in category '" . htmlspecialchars($category_key_rem_confirm) . "' not found. It might have already been removed.", $error_kb_rem_confirm);
                return;
            }
            $kb_rem_confirm = [
                [['text' => "✅ YES, REMOVE IT", 'callback_data' => CALLBACK_ADMIN_RP_CONF_YES_PREFIX."{$category_key_rem_confirm}_{$product_id_rem_confirm}"],
                 ['text' => "❌ NO, CANCEL", 'callback_data' => CALLBACK_ADMIN_RP_CONF_NO_PREFIX."{$category_key_rem_confirm}_{$product_id_rem_confirm}"]],
                [['text'=>'« Back to Product List', 'callback_data'=>CALLBACK_ADMIN_RP_SCAT_PREFIX.$category_key_rem_confirm]]
            ];
            editMessageText($chat_id, $message_id, "⚠️ Confirm Removal ⚠️\nAre you sure you want to permanently remove the product:\n<b>".htmlspecialchars($p_rem_confirm['name'])."</b>\nID: {$product_id_rem_confirm}\nCategory: ".htmlspecialchars($category_key_rem_confirm), json_encode(['inline_keyboard'=>$kb_rem_confirm]), 'HTML');
        }
        elseif (strpos($data, CALLBACK_ADMIN_RP_CONF_YES_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_RP_CONF_YES_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches_rem_yes)) {
            $category_key_do_remove = $matches_rem_yes[1];
            $product_id_do_remove = $matches_rem_yes[2];

            global $products;
            if(empty($products)) { $products = readJsonFile(PRODUCTS_FILE); }

            if(isset($products[$category_key_do_remove][$product_id_do_remove])) {
                $removed_prod_name_log = $products[$category_key_do_remove][$product_id_do_remove]['name'];
                unset($products[$category_key_do_remove][$product_id_do_remove]);

                if (writeJsonFile(PRODUCTS_FILE, $products)) {
                    editMessageText($chat_id, $message_id, "✅ Product '".htmlspecialchars($removed_prod_name_log)."' (ID: {$product_id_do_remove}) has been removed from category '".htmlspecialchars($category_key_do_remove)."'.", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Product Removal', 'callback_data'=>CALLBACK_ADMIN_RP_SCAT_PREFIX . $category_key_do_remove ], ['text'=>'« Product Mgt', 'callback_data'=>CALLBACK_ADMIN_PROD_MANAGEMENT ]]]]));
                } else {
                    editMessageText($chat_id, $message_id, "⚠️ Product '".htmlspecialchars($removed_prod_name_log)."' was removed from memory, but an ERROR occurred saving changes to disk. Please check server logs/permissions. The product might reappear if the bot restarts before a successful save.", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Product Removal', 'callback_data'=>CALLBACK_ADMIN_RP_SCAT_PREFIX . $category_key_do_remove ], ['text'=>'« Product Mgt', 'callback_data'=>CALLBACK_ADMIN_PROD_MANAGEMENT]]]]));
                }
            } else {
                editMessageText($chat_id, $message_id, "⚠️ Error: Product '".htmlspecialchars($product_id_do_remove)."' in category '".htmlspecialchars($category_key_do_remove)."' not found. It might have been already removed.", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Product Removal', 'callback_data'=>CALLBACK_ADMIN_RP_SCAT_PREFIX . $category_key_do_remove ], ['text'=>'« Product Mgt', 'callback_data'=>CALLBACK_ADMIN_PROD_MANAGEMENT ]]]]));
            }
            return;
        }
        elseif (strpos($data, CALLBACK_ADMIN_RP_CONF_NO_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_RP_CONF_NO_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches_rem_no)) {
            $category_key_rem_no = $matches_rem_no[1];
            global $products; $products = readJsonFile(PRODUCTS_FILE);
            $keyboard_rows_rem_no_list = [];
            if (isset($products[$category_key_rem_no]) && !empty($products[$category_key_rem_no])) {
                 foreach ($products[$category_key_rem_no] as $pid_loop_no => $details_loop_no) { $keyboard_rows_rem_no_list[] = [['text' => "➖ ".htmlspecialchars($details_loop_no['name']), 'callback_data' => CALLBACK_ADMIN_RP_SPRO_PREFIX . "{$category_key_rem_no}_{$pid_loop_no}"]]; }
            }
            $keyboard_rows_rem_no_list[] = [['text' => '« Back to Select Category', 'callback_data' => CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY]];
            editMessageText($chat_id, $message_id, "Product removal cancelled. Select product to REMOVE from '".htmlspecialchars($category_key_rem_no)."':", json_encode(['inline_keyboard' => $keyboard_rows_rem_no_list]));
        }
    }
    /*
    elseif ($data === CALLBACK_BUY_SPOTIFY || $data === CALLBACK_BUY_SSH || $data === CALLBACK_BUY_V2RAY) {
        // ... (This block was removed as it's handled by dynamic view_category_)
    }
    */
    // This is the general product selection handler
    elseif (
        preg_match('/^(.*)_([^_]+)$/', $data, $matches_prod_select) &&
        (strpos($data, 'view_category_') !== 0) &&
        (strpos($data, 'admin_') !== 0) && // Ensure it's not an admin callback caught here by mistake
        ($data !== CALLBACK_BACK_TO_MAIN) &&
        ($data !== CALLBACK_MY_PRODUCTS) &&
        ($data !== CALLBACK_SUPPORT) &&
        (strpos($data, CALLBACK_CONFIRM_BUY_PREFIX) !== 0) &&
        (strpos($data, CALLBACK_ACCEPT_PAYMENT_PREFIX) !== 0) &&
        (strpos($data, CALLBACK_REJECT_PAYMENT_PREFIX) !== 0) &&
        (strpos($data, CALLBACK_ACCEPT_AND_SEND_PREFIX) !== 0) &&
        (strpos($data, CALLBACK_VIEW_PURCHASED_ITEM_PREFIX) !== 0)
    ) {
        error_log("PROD_SEL_DEBUG: Product selection handler entered for data: '" . $data . "'");
        global $products; $products = readJsonFile(PRODUCTS_FILE);

        $category_key_select = $matches_prod_select[1];
        $product_id_select = $matches_prod_select[2];

        if (isset($products[$category_key_select][$product_id_select])) {
            $product_selected = $products[$category_key_select][$product_id_select];
            $plan_info_text = "<b>Product:</b> " . htmlspecialchars($product_selected['name']) . "\n";
            $plan_info_text .= "<b>Price:</b> $" . htmlspecialchars($product_selected['price']) . "\n";
            $plan_info_text .= "<b>Info:</b> " . nl2br(htmlspecialchars($product_selected['info'] ?? 'N/A')) . "\n\n";
            $plan_info_text .= "Do you want to purchase this item?";
            $back_cb_data = 'view_category_' . $category_key_select;
            $kb_prod_select = json_encode(['inline_keyboard' => [
                [['text' => "✅ Yes, Buy This", 'callback_data' => CALLBACK_CONFIRM_BUY_PREFIX . "{$category_key_select}_{$product_id_select}"]],
                [['text' => "« Back to Plans", 'callback_data' => $back_cb_data ]]
            ]]);
            editMessageText($chat_id, $message_id, $plan_info_text, $kb_prod_select, 'HTML');
        } else {
             error_log("PROD_SEL_DEBUG: Product '{$category_key_select}_{$product_id_select}' not found in loaded products. Data: ".$data);
             $kb_notfound_prod = json_encode(['inline_keyboard' => [[['text' => '« Back to Categories', 'callback_data' => 'view_category_' . $category_key_select ]], [['text' => '« Main Menu', 'callback_data' => CALLBACK_BACK_TO_MAIN ]]]]);
             editMessageText($chat_id, $message_id, "Sorry, the selected product could not be found. It might have been recently updated or removed.", $kb_notfound_prod);
        }
        return;
    }

    elseif (strpos($data, CALLBACK_CONFIRM_BUY_PREFIX) === 0) {
        $ids_str_confirm_buy = substr($data, strlen(CALLBACK_CONFIRM_BUY_PREFIX));
        if (!preg_match('/^(.+)_([^_]+)$/', $ids_str_confirm_buy, $matches_ids_confirm_buy)) {
             error_log("Error parsing IDs for confirm buy: {$data}");
             editMessageText($chat_id, $message_id, "Error processing your purchase request. The product information seems invalid. Please try again or contact support.", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Main Menu', 'callback_data'=>CALLBACK_BACK_TO_MAIN]]]])); return;
        }
        $category_key_confirm_buy = $matches_ids_confirm_buy[1];
        $product_id_confirm_buy = $matches_ids_confirm_buy[2];

        $product_to_buy = getProductDetails($category_key_confirm_buy, $product_id_confirm_buy);
        if ($product_to_buy) {
            setUserState($user_id, [
                'status' => STATE_AWAITING_RECEIPT,
                'message_id' => $message_id,
                'product_name' => $product_to_buy['name'],
                'price' => $product_to_buy['price'],
                'category_key' => $category_key_confirm_buy,
                'product_id' => $product_id_confirm_buy
            ]);
            $paymentDets_buy = getPaymentDetails();
            $text_buy_confirm = "To complete your purchase for <b>".htmlspecialchars($product_to_buy['name'])."</b> (Price: \$".htmlspecialchars($product_to_buy['price'])."), please transfer the amount to:\n\n";
            $text_buy_confirm .= "Card Number: `".htmlspecialchars($paymentDets_buy['card_number'])."`\n";
            $text_buy_confirm .= "Card Holder: `".htmlspecialchars($paymentDets_buy['card_holder'])."`\n\n";
            $text_buy_confirm .= "After making the payment, please send a screenshot of the transaction receipt to this chat.\n\nType /cancel to cancel this purchase.";

            // Fully reverted keyboard to only include the single Cancel button
            $cancel_button = ['text' => '« Cancel Purchase', 'callback_data' => "{$category_key_confirm_buy}_{$product_id_confirm_buy}"];
            $kb_buy_confirm_array = [
                'inline_keyboard' => [
                    [$cancel_button]
                ]
            ];
            $kb_buy_confirm = json_encode($kb_buy_confirm_array);
            editMessageText($chat_id, $message_id, $text_buy_confirm, $kb_buy_confirm, 'HTML'); // Changed parse_mode to HTML
        } else {
            error_log("Confirm Buy: Product details not found. Cat:{$category_key_confirm_buy}, ProdID:{$product_id_confirm_buy}, Data: {$data}");
            editMessageText($chat_id, $message_id, "Error: The product you are trying to purchase could not be found. It might have been removed or updated. Please select again.", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Main Menu', 'callback_data'=>CALLBACK_BACK_TO_MAIN]]]]));
        }
    }
    elseif (strpos($data, CALLBACK_ACCEPT_PAYMENT_PREFIX) === 0 || strpos($data, CALLBACK_REJECT_PAYMENT_PREFIX) === 0) {
        error_log("PAY_CONF: Entered payment confirmation handler. Data: '" . $data . "', AdminID: " . $user_id);
        if(!$is_admin) {
            sendMessage($chat_id, "Access denied for payment processing.");
            error_log("PAY_CONF: Access denied. User {$user_id} is not admin.");
            return;
        }

        $is_accept_payment = strpos($data, CALLBACK_ACCEPT_PAYMENT_PREFIX) === 0;
        $prefix_to_remove = $is_accept_payment ? CALLBACK_ACCEPT_PAYMENT_PREFIX : CALLBACK_REJECT_PAYMENT_PREFIX;
        $payload = substr($data, strlen($prefix_to_remove)); // USERID_CATKEY_PRODKEY

        // Parse USERID, CATKEY, PRODKEY from payload
        $target_user_id_payment = null;
        $category_key_payment = null;
        $product_id_payment = null;

        $first_underscore_pos = strpos($payload, '_');
        if ($first_underscore_pos === false) {
            error_log("PAY_CONF: Invalid payload format. Could not find first underscore in '{$payload}'. Full data: '{$data}'");
            editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Could not parse payment confirmation data. Please handle manually.", null, 'Markdown');
            return;
        }
        $target_user_id_payment = substr($payload, 0, $first_underscore_pos);
        $rest_of_payload = substr($payload, $first_underscore_pos + 1); // CATKEY_PRODKEY

        $last_underscore_pos = strrpos($rest_of_payload, '_');
        if ($last_underscore_pos === false) {
            error_log("PAY_CONF: Invalid payload format. Could not find last underscore in '{$rest_of_payload}'. Full data: '{$data}'");
            editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Could not parse product details from payment confirmation. Please handle manually.", null, 'Markdown');
            return;
        }
        $category_key_payment = substr($rest_of_payload, 0, $last_underscore_pos);
        $product_id_payment = substr($rest_of_payload, $last_underscore_pos + 1);

        if (!is_numeric($target_user_id_payment) || empty($category_key_payment) || empty($product_id_payment)) {
            error_log("PAY_CONF: Parsed components are invalid. UserID: '{$target_user_id_payment}', CatKey: '{$category_key_payment}', ProdID: '{$product_id_payment}'. Full data: '{$data}'");
            editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Invalid parsed details for payment confirmation. Please handle manually.", null, 'Markdown');
            return;
        }

        error_log("PAY_CONF: TargetUserID: '{$target_user_id_payment}', Category: '{$category_key_payment}', ProductID: '{$product_id_payment}', Action: " . ($is_accept_payment ? "Accept" : "Reject"));

        $original_caption_payment = $callback_query->message->caption ?? '';
        // Get product name from stored details, not just receipt, for accuracy.
        $product_details_for_msg = getProductDetails($category_key_payment, $product_id_payment);
        $product_name_for_msg = $product_details_for_msg ? $product_details_for_msg['name'] : "Unknown Product (ID: {$product_id_payment})";
        $product_price_for_msg = $product_details_for_msg ? ($product_details_for_msg['price'] ?? 'N/A') : 'N/A';

        if ($is_accept_payment) {
            $item_content_for_record = null; // Initialize content to be stored with purchase
            $admin_message_suffix = "\n\n✅ PAYMENT ACCEPTED by admin {$user_id} (@".($callback_query->from->username ?? 'N/A').").";
            $user_message = "✅ Great news! Your payment for '<b>".htmlspecialchars($product_name_for_msg)."</b>' has been accepted.";

            if ($product_details_for_msg) {
                if (($product_details_for_msg['type'] ?? 'manual') === 'instant') {
                    error_log("PAY_CONF: Product '{$category_key_payment}_{$product_id_payment}' is INSTANT. Attempting to deliver.");
                    $item_to_deliver = getAndRemoveInstantProductItem($category_key_payment, $product_id_payment);
                    if ($item_to_deliver !== null) {
                        $item_content_for_record = $item_to_deliver; // Set item to be stored
                        $user_message .= "\n\nHere is your item:\n<code>" . htmlspecialchars($item_to_deliver) . "</code>";
                        $admin_message_suffix .= "\n✅ Instant item delivered to user.";
                        error_log("PAY_CONF: Instant item '{$item_to_deliver}' delivered for {$category_key_payment}_{$product_id_payment} to user {$target_user_id_payment}.");
                    } else {
                        // Out of stock
                        $user_message .= "\n\n⚠️ Your product is ready, but we're currently out of stock for instant delivery. Please contact support, and we'll assist you shortly!";
                        $admin_message_suffix .= "\n⚠️ INSTANT DELIVERY FAILED: Product '{$category_key_payment}_{$product_id_payment}' is OUT OF STOCK. User {$target_user_id_payment} notified to contact support. PLEASE HANDLE MANUALLY.";
                        error_log("PAY_CONF: INSTANT DELIVERY FAILED (OUT OF STOCK) for {$category_key_payment}_{$product_id_payment} to user {$target_user_id_payment}.");
                    }
                } else { // Manual product
                    $user_message .= "\nYour product will be delivered manually by an admin shortly. You can find it in 'My Products' once processed.";
                    $admin_message_suffix .= "\nℹ️ This is a MANUAL delivery product. User notified.";
                    error_log("PAY_CONF: Manual product '{$category_key_payment}_{$product_id_payment}'. User {$target_user_id_payment} notified for manual delivery.");
                }
            } else { // Product details not found - critical error
                $user_message .= "\n\n⚠️ ERROR: We could not retrieve the details for your purchased product (ID: {$product_id_payment}). Please contact support immediately for assistance.";
                $admin_message_suffix .= "\n\n🔥🔥 CRITICAL ERROR: Could not retrieve product details for '{$category_key_payment}_{$product_id_payment}' during payment acceptance. User {$target_user_id_payment} notified to contact support. PLEASE INVESTIGATE AND HANDLE MANUALLY.";
                error_log("PAY_CONF: CRITICAL ERROR - Product details not found for {$category_key_payment}_{$product_id_payment} for user {$target_user_id_payment}.");
            }

            // Call recordPurchase ONCE here, with all necessary info including potentially delivered item content
            recordPurchase($target_user_id_payment, $product_name_for_msg, $product_price_for_msg, $item_content_for_record);

            editMessageCaption($chat_id, $message_id, $original_caption_payment . $admin_message_suffix, null, 'Markdown');
            sendMessage($target_user_id_payment, $user_message);

        } else { // Payment Rejected
            $admin_message_suffix = "\n\n❌ PAYMENT REJECTED by admin {$user_id} (@".($callback_query->from->username ?? 'N/A').").";
            editMessageCaption($chat_id, $message_id, $original_caption_payment . $admin_message_suffix, null, 'Markdown');
            sendMessage($target_user_id_payment, "⚠️ We regret to inform you that your payment for '<b>".htmlspecialchars($product_name_for_msg)."</b>' has been rejected. If you believe this is an error, or for more details, please contact support by pressing the Support button.");
            error_log("PAY_CONF: Payment REJECTED for user {$target_user_id_payment} for product {$category_key_payment}_{$product_id_payment}.");
        }
    }
    elseif (strpos($data, CALLBACK_ACCEPT_AND_SEND_PREFIX) === 0) {
        answerCallbackQuery($callback_query->id);
        if(!$is_admin) {
            sendMessage($chat_id, "Access denied for payment processing and sending.");
            error_log("ACCEPT_SEND_CONF: Access denied. User {$user_id} is not admin.");
            return;
        }

        $payload = substr($data, strlen(CALLBACK_ACCEPT_AND_SEND_PREFIX)); // USERID_CATKEY_PRODKEY
        // Parse USERID, CATKEY, PRODKEY from payload (same parsing as CALLBACK_ACCEPT_PAYMENT_PREFIX)
        $target_user_id_send = null; $category_key_send = null; $product_id_send = null;
        $first_underscore_pos_send = strpos($payload, '_');
        if ($first_underscore_pos_send === false) { /* error handling */ return; }
        $target_user_id_send = substr($payload, 0, $first_underscore_pos_send);
        $rest_of_payload_send = substr($payload, $first_underscore_pos_send + 1);
        $last_underscore_pos_send = strrpos($rest_of_payload_send, '_');
        if ($last_underscore_pos_send === false) { /* error handling */ return; }
        $category_key_send = substr($rest_of_payload_send, 0, $last_underscore_pos_send);
        $product_id_send = substr($rest_of_payload_send, $last_underscore_pos_send + 1);

        if (!is_numeric($target_user_id_send) || empty($category_key_send) || empty($product_id_send)) {
            error_log("ACCEPT_SEND_CONF: Parsed components are invalid. UserID: '{$target_user_id_send}', CatKey: '{$category_key_send}', ProdID: '{$product_id_send}'. Full data: '{$data}'");
            editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Invalid parsed details for accept & send. Please handle manually.", null, 'Markdown');
            return;
        }

        $product_details_send = getProductDetails($category_key_send, $product_id_send);
        if (!$product_details_send) {
            error_log("ACCEPT_SEND_CONF: Product not found {$category_key_send}_{$product_id_send}. Data: {$data}");
            editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Product details not found for accept & send. Please handle manually.", null, 'Markdown');
            return;
        }
        $product_name_send = $product_details_send['name'];
        $product_price_send = $product_details_send['price'] ?? 'N/A';

        // Record the purchase, initially with null delivered_item_content
        $purchase_index = recordPurchase($target_user_id_send, $product_name_send, $product_price_send, null);

        if ($purchase_index === false) {
            error_log("ACCEPT_SEND_CONF: Failed to record purchase for {$category_key_send}_{$product_id_send} for user {$target_user_id_send}.");
            editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Failed to record purchase during accept & send. Please handle manually.", null, 'Markdown');
            return;
        }

        // Notify the user
        sendMessage($target_user_id_send, "✅ Your payment for '<b>".htmlspecialchars($product_name_send)."</b>' has been accepted. An admin will contact you shortly with the product details.");

        // Set admin state for manual send session
        setUserState($user_id, [ // $user_id is the admin's ID
            'status' => STATE_ADMIN_MANUAL_SEND_SESSION,
            'target_user_id' => $target_user_id_send,
            'purchase_category' => $category_key_send,
            'purchase_product_id' => $product_id_send,
            'purchase_index' => $purchase_index, // Store the index of the purchase
            'original_admin_msg_id' => $message_id // ID of the message with the "Accept & Send" button
        ]);

        // Also set a state for the target user to know they are in a session
        setUserState($target_user_id_send, [
            'status' => 'in_manual_send_session_with_admin', // Define this if needed, or use a flag
            'admin_id' => $user_id
        ]);


        // Edit the admin's original message (receipt photo caption)
        $admin_caption_update = ($callback_query->message->caption ?? '') . "\n\n✅ Payment accepted for ".htmlspecialchars($product_name_send).". You are now in a direct send session with User ID: {$target_user_id_send}.";
        editMessageCaption($chat_id, $message_id, $admin_caption_update, null, 'Markdown'); // Remove buttons by passing null markup

        // Send a new instructional message to the admin in their chat with the bot
        sendMessage($chat_id, "➡️ You are now live with User ID: <b>{$target_user_id_send}</b> to deliver '<b>".htmlspecialchars($product_name_send)."</b>'.\n\nReply to your own message with <code>/save</code> to store its content as the delivered item. Type <code>/end</code> when finished.", null, "HTML");

        // Send an initial message to the target user
        sendMessage($target_user_id_send, "An admin is now connected to provide details for your purchase: '<b>".htmlspecialchars($product_name_send)."</b>'. Please wait for their message.");
        error_log("ACCEPT_SEND_CONF: Admin {$user_id} started manual send session with user {$target_user_id_send} for product {$category_key_send}_{$product_id_send}, purchase index {$purchase_index}.");
    }
    elseif ($data === CALLBACK_BACK_TO_MAIN) {
        clearUserState($user_id);
        $first_name_main = $callback_query->from->first_name;
        $welcome_text_main = "Hello, " . htmlspecialchars($first_name_main) . "! Welcome back to the main menu.\n\nPlease select an option:";
        $keyboard_main_array = generateDynamicMainMenuKeyboard($is_admin);
        editMessageText($chat_id, $message_id, $welcome_text_main, json_encode($keyboard_main_array));
    }
}
?>
```

[end of functions.php]
