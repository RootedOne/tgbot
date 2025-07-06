<?php
// FILE: functions.php
// Contains all reusable bot functions.

define('STATE_FILE', 'user_states.json');
define('PRODUCTS_FILE', 'products.json');
define('USER_PURCHASES_FILE', 'user_purchases.json');
define('USER_DATA_FILE', 'user_data.json');
define('BOT_CONFIG_DATA_FILE', 'bot_config_data.json');
define('COUPONS_FILE', 'coupons.json'); // Added for coupons

// Constants are now defined in config.php
// ===================================================================
//  STATE & DATA MANAGEMENT FUNCTIONS
// ===================================================================
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
function recordPurchase($user_id, $product_name, $original_price, $price_paid, $applied_coupon_code, $discount_amount, $delivered_item_content = null) {
    $purchases = readJsonFile(USER_PURCHASES_FILE);
    $new_purchase = [
        'product_name' => $product_name,
        'original_price' => $original_price,
        'price_paid' => $price_paid,
        'applied_coupon_code' => $applied_coupon_code,
        'discount_amount' => $discount_amount,
        'date' => date('Y-m-d H:i:s')
    ];
    if ($delivered_item_content !== null) {
        $new_purchase['delivered_item_content'] = $delivered_item_content;
    }
    if (!isset($purchases[$user_id])) {
        $purchases[$user_id] = [];
    }
    $purchases[$user_id][] = $new_purchase;
    $new_purchase_index = count($purchases[$user_id]) - 1;

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

$products = readJsonFile(PRODUCTS_FILE);

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
    $total_coupons_redeemed = 0; $total_discount_value = 0.0;

    if (!empty($user_purchases_all) && is_array($user_purchases_all)) {
        foreach ($user_purchases_all as $user_id_purchases => $purchases) {
            if (is_array($purchases)) {
                $total_purchases_count += count($purchases);
                foreach ($purchases as $purchase) {
                    // Use 'price_paid' for sales volume if available, otherwise 'price'
                    $price_key = isset($purchase['price_paid']) ? 'price_paid' : 'price';
                    if (isset($purchase[$price_key])) {
                        if (is_numeric($purchase[$price_key])) {
                            $total_sales_volume += (float)$purchase[$price_key];
                        } elseif (strtolower(trim($purchase[$price_key])) === 'manually added') {
                            $manual_additions_count++;
                        }
                    }
                    if (!empty($purchase['applied_coupon_code'])) {
                        $total_coupons_redeemed++;
                        if (isset($purchase['discount_amount']) && is_numeric($purchase['discount_amount'])) {
                            $total_discount_value += (float)$purchase['discount_amount'];
                        }
                    }
                }
            }
        }
    }
    $stats_text .= "💳 <b>Purchases & Sales:</b>\n";
    $stats_text .= "▪️ Total Purchase Records: " . $total_purchases_count . "\n";
    $stats_text .= "▪️ Total Sales Volume (after discounts): $" . number_format($total_sales_volume, 2) . "\n";
    if ($manual_additions_count > 0) { $stats_text .= "▪️ Manually Added Items (via /addprod): " . $manual_additions_count . "\n"; }

    $stats_text .= "\n🎟️ <b>Coupon Stats:</b>\n";
    $stats_text .= "▪️ Total Coupons Redeemed: " . $total_coupons_redeemed . "\n";
    $stats_text .= "▪️ Total Discount Given: $" . number_format($total_discount_value, 2) . "\n";

    return $stats_text;
}
// --- END BOT STATS FUNCTION ---

// ===================================================================
//  COUPON MANAGEMENT FUNCTIONS
// ===================================================================

function getAllCoupons() { return readJsonFile(COUPONS_FILE); }
function getCoupon($coupon_code) { $coupons = getAllCoupons(); foreach ($coupons as $coupon) { if (strcasecmp($coupon['code'], $coupon_code) === 0) return $coupon; } return null; }

function addCoupon($coupon_data) {
    $coupons = getAllCoupons();
    if (!isset($coupon_data['code']) || !isset($coupon_data['discount_type']) || !isset($coupon_data['discount_value'])) { error_log("addCoupon: Missing essential coupon data."); return false; }
    if (getCoupon($coupon_data['code'])) { error_log("addCoupon: Coupon code '{$coupon_data['code']}' already exists."); return false; }
    $defaults = [
        'description' => '', 'expiry_date' => null, 'max_uses' => PHP_INT_MAX, 'current_uses' => 0, 'per_user_limit' => 1,
        'applicable_to' => ['categories' => [], 'products' => []], 'min_purchase_amount' => 0, 'status' => 'active', 'date_created' => date('Y-m-d H:i:s')
    ];
    $new_coupon = array_merge($defaults, $coupon_data);
    if (!isset($new_coupon['applicable_to']['categories'])) $new_coupon['applicable_to']['categories'] = [];
    if (!isset($new_coupon['applicable_to']['products'])) $new_coupon['applicable_to']['products'] = [];
    $coupons[] = $new_coupon;
    return writeJsonFile(COUPONS_FILE, $coupons);
}

function deleteCoupon($coupon_code) {
    $coupons = getAllCoupons(); $updated_coupons = []; $found = false;
    foreach ($coupons as $coupon) { if (strcasecmp($coupon['code'], $coupon_code) !== 0) $updated_coupons[] = $coupon; else $found = true; }
    if ($found) return writeJsonFile(COUPONS_FILE, $updated_coupons);
    return false;
}

function validateCoupon($coupon_code, $user_id, $product_category_key, $product_id_with_category, $purchase_amount) {
    $coupon = getCoupon($coupon_code);
    if (!$coupon) return ['valid' => false, 'message' => "Coupon code not found."];
    if (($coupon['status'] ?? 'disabled') !== 'active') return ['valid' => false, 'message' => "This coupon is not active."];
    if (isset($coupon['expiry_date']) && !empty($coupon['expiry_date']) && strtotime($coupon['expiry_date'] . ' 23:59:59') < time()) return ['valid' => false, 'message' => "This coupon has expired."];
    if (($coupon['current_uses'] ?? 0) >= ($coupon['max_uses'] ?? PHP_INT_MAX)) return ['valid' => false, 'message' => "This coupon has reached its maximum usage limit."];
    if (isset($coupon['min_purchase_amount']) && $purchase_amount < $coupon['min_purchase_amount']) return ['valid' => false, 'message' => "This coupon requires a minimum purchase of $" . number_format($coupon['min_purchase_amount'], 2) . "."];
    $user_purchases_data = readJsonFile(USER_PURCHASES_FILE); $user_purchases = $user_purchases_data[$user_id] ?? []; $user_coupon_uses = 0;
    foreach ($user_purchases as $purchase) { if (isset($purchase['applied_coupon_code']) && strcasecmp($purchase['applied_coupon_code'], $coupon_code) === 0) $user_coupon_uses++; }
    if ($user_coupon_uses >= ($coupon['per_user_limit'] ?? 1)) return ['valid' => false, 'message' => "You have already used this coupon the maximum number of times allowed per user."];
    $applicable_to = $coupon['applicable_to'] ?? ['categories' => [], 'products' => []];
    $is_generally_applicable = empty($applicable_to['categories']) && empty($applicable_to['products']);
    if (!$is_generally_applicable) {
        $is_specifically_applicable = false;
        if (!empty($applicable_to['categories'])) { foreach ($applicable_to['categories'] as $cat_key) if (strcasecmp($cat_key, $product_category_key) === 0) { $is_specifically_applicable = true; break; } }
        if (!$is_specifically_applicable && !empty($applicable_to['products'])) { foreach ($applicable_to['products'] as $prod_id_string) if (strcasecmp($prod_id_string, $product_id_with_category) === 0) { $is_specifically_applicable = true; break; } }
        if (!$is_specifically_applicable) return ['valid' => false, 'message' => "This coupon is not valid for the selected product or category."];
    }
    return ['valid' => true, 'message' => "Coupon applied successfully!", 'coupon_details' => $coupon];
}

function applyCoupon($coupon_details, $original_price) {
    if (!$coupon_details || !isset($coupon_details['discount_type']) || !isset($coupon_details['discount_value'])) { return ['original_price' => (float)$original_price, 'discounted_price' => (float)$original_price, 'discount_amount' => 0]; }
    $discount_value = (float)$coupon_details['discount_value']; $price = (float)$original_price; $discounted_price = $price; $discount_amount = 0;
    if ($coupon_details['discount_type'] === 'percentage') {
        if ($discount_value < 0 || $discount_value > 100) return ['original_price' => $price, 'discounted_price' => $price, 'discount_amount' => 0];
        $discount_amount = ($price * $discount_value) / 100; $discounted_price = $price - $discount_amount;
    } elseif ($coupon_details['discount_type'] === 'fixed') {
        if ($discount_value < 0) return ['original_price' => $price, 'discounted_price' => $price, 'discount_amount' => 0];
        $discount_amount = $discount_value; $discounted_price = $price - $discount_amount;
    } else { return ['original_price' => $price, 'discounted_price' => $price, 'discount_amount' => 0]; }
    $final_price = max(0, $discounted_price);
    $actual_discount_amount = ($final_price == 0 && $price < $discount_amount) ? $price : $price - $final_price;
    return ['original_price' => $price, 'discounted_price' => round($final_price, 2), 'discount_amount' => round($actual_discount_amount, 2)];
}

function redeemCoupon($coupon_code, $user_id) {
    $coupons = getAllCoupons(); $found_and_updated = false;
    for ($i = 0; $i < count($coupons); $i++) {
        if (strcasecmp($coupons[$i]['code'], $coupon_code) === 0) {
            if (($coupons[$i]['status'] ?? 'disabled') === 'active') {
                $coupons[$i]['current_uses'] = ($coupons[$i]['current_uses'] ?? 0) + 1;
                if (isset($coupons[$i]['max_uses']) && $coupons[$i]['current_uses'] >= $coupons[$i]['max_uses']) { /* $coupons[$i]['status'] = 'disabled'; */ }
                $found_and_updated = true; break;
            } else { error_log("redeemCoupon: Attempted to redeem inactive coupon '{$coupon_code}'."); return false; }
        }
    }
    if ($found_and_updated) return writeJsonFile(COUPONS_FILE, $coupons);
    error_log("redeemCoupon: Coupon '{$coupon_code}' not found or failed to save."); return false;
}

function promptForCouponApplicability($chat_id, $message_id, $coupon_code_context) {
    $applicability_keyboard = json_encode(['inline_keyboard' => [
        [['text' => '🌍 All Products', 'callback_data' => CALLBACK_ADMIN_COUPON_APPLICABLE_TO_ALL]],
        [['text' => '🗂️ Specific Categories', 'callback_data' => CALLBACK_ADMIN_COUPON_APPLICABLE_TO_CATS]],
        [['text' => '📦 Specific Products', 'callback_data' => CALLBACK_ADMIN_COUPON_APPLICABLE_TO_PRODS]],
        [['text' => '« Cancel Coupon Creation', 'callback_data' => CALLBACK_ADMIN_COUPON_MANAGEMENT]]
    ]]);
    editMessageText($chat_id, $message_id, "Coupon '<b>".htmlspecialchars($coupon_code_context)."</b>':\nTo which products/categories should this coupon apply?", $applicability_keyboard, "HTML");
}

// ===================================================================
//  TELEGRAM API FUNCTIONS
// ===================================================================
function generateDynamicMainMenuKeyboard($is_admin_menu = false) {
    global $products; $products = readJsonFile(PRODUCTS_FILE); $keyboard_rows = [];
    if (!empty($products)) { foreach ($products as $category_key => $category_items) { if (is_string($category_key) && !empty($category_key) && is_array($category_items)) { $displayName = ucfirst(str_replace('_', ' ', $category_key)); $keyboard_rows[] = [['text' => "🛍️ " . htmlspecialchars($displayName), 'callback_data' => 'view_category_' . $category_key]]; } } }
    $keyboard_rows[] = [['text' => "📦 My Products", 'callback_data' => (string)CALLBACK_MY_PRODUCTS]];
    $keyboard_rows[] = [['text' => "❓ Support", 'callback_data' => (string)CALLBACK_SUPPORT]];
    if ($is_admin_menu) { $keyboard_rows[] = [['text' => "⚙️ Admin Panel", 'callback_data' => (string)CALLBACK_ADMIN_PANEL]]; }
    return ['inline_keyboard' => $keyboard_rows];
}

function bot($method, $data = []) { $url = "https://api.telegram.org/bot" . API_TOKEN . "/" . $method; $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $data); $res = curl_exec($ch); curl_close($ch); return json_decode($res); }
function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = 'HTML') { bot('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageText($chat_id, $message_id, $text, $reply_markup = null, $parse_mode = 'HTML') { bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageCaption($chat_id, $message_id, $caption, $reply_markup = null, $parse_mode = 'HTML') { bot('editMessageCaption', ['chat_id' => $chat_id, 'message_id' => $message_id, 'caption' => $caption, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageReplyMarkup($chat_id, $message_id, $reply_markup = null) { bot('editMessageReplyMarkup', ['chat_id' => $chat_id, 'message_id' => $message_id, 'reply_markup' => $reply_markup]); }
function answerCallbackQuery($callback_query_id) { bot('answerCallbackQuery', ['callback_query_id' => $callback_query_id]); }

function forwardPhotoToAdmin($file_id, $caption, $original_user_id, $category_key, $product_id, $final_price_to_pay, $applied_coupon_code) {
    $admin_ids = getAdminIds(); if(empty($admin_ids)) return; $admin_id = $admin_ids[0];
    $product_details = getProductDetails($category_key, $product_id); $product_type = $product_details['type'] ?? 'manual';
    $coupon_code_for_callback = $applied_coupon_code ? preg_replace('/[^a-zA-Z0-9_-]/', '', $applied_coupon_code) : 'NO_COUPON';
    $price_for_callback = str_replace('.', '_', (string)number_format($final_price_to_pay, 2, '.', ''));
    $base_callback_payload = $original_user_id . "_" . $category_key . "_" . $product_id . "_" . $price_for_callback . "_" . $coupon_code_for_callback;
    $accept_button_text = "✅ Accept"; $accept_button_callback_data = CALLBACK_ACCEPT_PAYMENT_PREFIX . $base_callback_payload;
    if ($product_type === 'manual') { $accept_button_text = "✅ Accept & Send"; $accept_button_callback_data = CALLBACK_ACCEPT_AND_SEND_PREFIX . $base_callback_payload; }
    $reject_callback_data = CALLBACK_REJECT_PAYMENT_PREFIX . $base_callback_payload;
    $approval_keyboard = json_encode(['inline_keyboard' => [[['text' => $accept_button_text, 'callback_data' => $accept_button_callback_data], ['text' => "❌ Reject", 'callback_data' => $reject_callback_data]]]]);
    bot('sendPhoto', ['chat_id' => $admin_id, 'photo' => $file_id, 'caption' => $caption, 'parse_mode' => 'Markdown', 'reply_markup' => $approval_keyboard]);
}

function generateCategoryKeyboard($category_key) {
    global $products; $keyboard = ['inline_keyboard' => []]; $category_products = $products[$category_key] ?? [];
    foreach ($category_products as $id => $details) { if (is_array($details) && isset($details['name']) && isset($details['price'])) { $keyboard['inline_keyboard'][] = [['text' => "{$details['name']} - \${$details['price']}", 'callback_data' => "{$category_key}_{$id}"]]; } }
    $keyboard['inline_keyboard'][] = [['text' => '« Back to Main Menu', 'callback_data' => CALLBACK_BACK_TO_MAIN]];
    return json_encode($keyboard);
}

// ===================================================================
//  CALLBACK QUERY PROCESSOR
// ===================================================================
function processCallbackQuery($callback_query) {
    global $mainMenuKeyboard, $adminMenuKeyboard, $products;
    $chat_id = $callback_query->message->chat->id; $user_id = $callback_query->from->id; $data = $callback_query->data; $message_id = $callback_query->message->message_id; $is_admin = in_array($user_id, getAdminIds());
    error_log("PROCESS_CALLBACK_QUERY: Data: '{$data}' | User: {$user_id}"); answerCallbackQuery($callback_query->id);
    if (getUserData($user_id)['is_banned']) { sendMessage($chat_id, "⚠️ You are banned."); return; }

    if (strpos($data, 'view_category_') === 0) { /* ... existing logic ... */
        global $products; $products = readJsonFile(PRODUCTS_FILE); $category_key_view = substr($data, strlen('view_category_')); $category_display_name_view = ucfirst(str_replace('_', ' ', $category_key_view));
        if (isset($products[$category_key_view]) && !empty($products[$category_key_view])) { editMessageText($chat_id, $message_id, "Select product from <b>" . htmlspecialchars($category_display_name_view) . "</b>:", generateCategoryKeyboard($category_key_view), 'HTML'); }
        else { editMessageText($chat_id, $message_id, "No products in <b>" . htmlspecialchars($category_display_name_view) . "</b>.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_BACK_TO_MAIN]]]]), 'HTML'); } return;
    }
    elseif ($data === CALLBACK_MY_PRODUCTS) {
        $purchases_all_data = readJsonFile(USER_PURCHASES_FILE); $user_purchases_array = $purchases_all_data[$user_id] ?? [];
        $message_to_send = "<b>🛍️ Your Products:</b>\nClick to view details."; $keyboard_button_rows = [];
        if (empty($user_purchases_array)) { $message_to_send = "You have no products yet."; }
        else { foreach ($user_purchases_array as $index => $purchase_item) {
            $product_name_btn = htmlspecialchars($purchase_item['product_name']);
            $purchase_date_btn = isset($purchase_item['date']) && strtotime($purchase_item['date']) !== false ? date('d M Y', strtotime($purchase_item['date'])) : 'Unknown Date';
            $emoji_btn = (isset($purchase_item['delivered_item_content']) && trim($purchase_item['delivered_item_content']) !== '') ? "📦" : "📄";
            // Updated price display
            $price_display = '';
            if (isset($purchase_item['applied_coupon_code']) && $purchase_item['applied_coupon_code']) {
                $price_display = " (Paid: $" . htmlspecialchars(number_format($purchase_item['price_paid'],2)) . ")";
            } elseif (isset($purchase_item['price_paid'])) {
                 $price_display = " (Paid: $" . htmlspecialchars(number_format($purchase_item['price_paid'],2)) . ")";
            } elseif (isset($purchase_item['original_price'])) { // Fallback for older records before price_paid
                 $price_display = " (Price: $" . htmlspecialchars(number_format($purchase_item['original_price'],2)) . ")";
            }


            $button_text_val = $emoji_btn . " " . $product_name_btn . " " . $price_display . " (" . $purchase_date_btn . ")";
            $keyboard_button_rows[] = [['text' => $button_text_val, 'callback_data' => CALLBACK_VIEW_PURCHASED_ITEM_PREFIX . $user_id . "_" . $index]];
        }}
        $keyboard_button_rows[] = [['text' => '« Back to Main Menu', 'callback_data' => CALLBACK_BACK_TO_MAIN]];
        editMessageText($chat_id, $message_id, $message_to_send, json_encode(['inline_keyboard' => $keyboard_button_rows]), 'HTML');
    }
    elseif (strpos($data, CALLBACK_VIEW_PURCHASED_ITEM_PREFIX) === 0) {
        $payload = substr($data, strlen(CALLBACK_VIEW_PURCHASED_ITEM_PREFIX)); $parts = explode('_', $payload);
        $text_to_display = ""; $keyboard_markup = json_encode(['inline_keyboard' => [[['text' => '« Back to My Products', 'callback_data' => CALLBACK_MY_PRODUCTS]]]]);
        if (count($parts) === 2 && (string)$user_id === (string)$parts[0]) {
            $all_purchases_data = readJsonFile(USER_PURCHASES_FILE); $user_specific_purchases_list = $all_purchases_data[$parts[0]] ?? [];
            if (isset($user_specific_purchases_list[(int)$parts[1]])) {
                $p = $user_specific_purchases_list[(int)$parts[1]];
                $text_to_display = "<b>Item:</b> " . htmlspecialchars($p['product_name']) . "\n";
                $text_to_display .= "<b>Purchased:</b> " . htmlspecialchars($p['date']) . "\n";
                if (isset($p['applied_coupon_code']) && $p['applied_coupon_code']) {
                    $text_to_display .= "<b>Original Price:</b> $" . htmlspecialchars(number_format($p['original_price'],2)) . "\n";
                    $text_to_display .= "<b>Coupon:</b> " . htmlspecialchars($p['applied_coupon_code']) . "\n";
                    $text_to_display .= "<b>Discount:</b> $" . htmlspecialchars(number_format($p['discount_amount'],2)) . "\n";
                    $text_to_display .= "<b>Price Paid:</b> $" . htmlspecialchars(number_format($p['price_paid'],2)) . "\n";
                } elseif (isset($p['price_paid'])) { // For items bought without coupon but with new structure
                     $text_to_display .= "<b>Price Paid:</b> $" . htmlspecialchars(number_format($p['price_paid'],2)) . "\n";
                } elseif (isset($p['original_price'])) { // Fallback for older items
                     $text_to_display .= "<b>Price:</b> $" . htmlspecialchars(number_format($p['original_price'],2)) . "\n";
                } elseif (isset($p['price'])) { // Absolute fallback for very old items
                     $text_to_display .= "<b>Price:</b> $" . htmlspecialchars($p['price']) . "\n";
                }

                $text_to_display .= "\n";
                if (isset($p['delivered_item_content']) && trim($p['delivered_item_content']) !== '') { $text_to_display .= "<b>Your item details:</b>\n<code>" . htmlspecialchars($p['delivered_item_content']) . "</code>"; }
                else { $text_to_display .= "This item was delivered manually or does not have specific viewable content here."; }
            } else $text_to_display = "⚠️ Item not found.";
        } else $text_to_display = "⚠️ Error or access denied.";
        editMessageText($chat_id, $message_id, $text_to_display, $keyboard_markup, 'HTML');
    }
    elseif ($data === CALLBACK_SUPPORT) { /* ... existing logic ... */  setUserState($user_id, ['status' => STATE_AWAITING_SUPPORT_MESSAGE, 'message_id' => $message_id]); editMessageText($chat_id, $message_id, "❓Please describe your issue. Type /cancel to abort.", json_encode(['inline_keyboard' => [[['text' => 'Cancel Support', 'callback_data' => CALLBACK_BACK_TO_MAIN]]]])); }
    elseif (strpos($data, 'admin_') === 0 || $data === CALLBACK_ADMIN_PANEL || $data === CALLBACK_ADMIN_PROD_MANAGEMENT || $data === CALLBACK_ADMIN_VIEW_STATS || $data === CALLBACK_ADMIN_CATEGORY_MANAGEMENT) {
        if (!$is_admin) { sendMessage($chat_id, "Access denied."); return; }
        if ($data === CALLBACK_ADMIN_PANEL) {
            editMessageText($chat_id, $message_id, "⚙️ Admin Panel ⚙️", json_encode(['inline_keyboard' => [[['text' => "📦 Product Management", 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]], [['text' => "🗂️ Category Management", 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]], [['text' => "🎟️ Coupon Management", 'callback_data' => CALLBACK_ADMIN_COUPON_MANAGEMENT]], [['text' => "📊 View Bot Stats", 'callback_data' => CALLBACK_ADMIN_VIEW_STATS]], [['text' => '« Back to Main Menu', 'callback_data' => CALLBACK_BACK_TO_MAIN]]]])); return;
        }
        elseif ($data === CALLBACK_ADMIN_COUPON_MANAGEMENT) {
            editMessageText($chat_id, $message_id, "🎟️ Coupon Management 🎟️", json_encode(['inline_keyboard' => [[['text' => "➕ Add Coupon", 'callback_data' => CALLBACK_ADMIN_ADD_COUPON_PROMPT]], [['text' => "📋 List Coupons", 'callback_data' => CALLBACK_ADMIN_LIST_COUPONS]], [['text' => "➖ Delete Coupon", 'callback_data' => CALLBACK_ADMIN_DELETE_COUPON_SELECT]], [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PANEL]]]])); return;
        }
        elseif ($data === CALLBACK_ADMIN_ADD_COUPON_PROMPT) { setUserState($user_id, ['status' => STATE_ADMIN_ADDING_COUPON_CODE, 'original_message_id' => $message_id, 'coupon_data' => []]); editMessageText($chat_id, $message_id, "Enter Coupon Code (unique, alphanumeric/hyphens). /cancel to abort.", null); return; }
        elseif ($data === CALLBACK_ADMIN_LIST_COUPONS) { /* ... logic from previous step ... */
            $all_coupons = getAllCoupons(); if (empty($all_coupons)) { editMessageText($chat_id, $message_id, "No coupons.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_COUPON_MANAGEMENT]]]])); return; }
            $text = "📋 **Coupons:**\n"; foreach ($all_coupons as $c) { $text .= "🔹 **`{$c['code']}`**: {$c['discount_value']}" . ($c['discount_type']==='percentage'?'%':'$') . ", Uses: {$c['current_uses']}/" . ($c['max_uses']==PHP_INT_MAX?'∞':$c['max_uses']) . ", Exp: ".($c['expiry_date']?:'Never')."\n"; }
            editMessageText($chat_id, $message_id, $text, json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_COUPON_MANAGEMENT]]]]), 'Markdown'); return;
        }
        elseif ($data === CALLBACK_ADMIN_DELETE_COUPON_SELECT) { /* ... logic from previous step ... */
            $all_coupons = getAllCoupons(); if (empty($all_coupons)) { editMessageText($chat_id, $message_id, "No coupons to delete.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_COUPON_MANAGEMENT]]]])); return; }
            $kb_rows = []; foreach ($all_coupons as $c) $kb_rows[] = [['text' => "➖ ".htmlspecialchars($c['code']), 'callback_data' => CALLBACK_ADMIN_DELETE_COUPON_CONFIRM_PREFIX . $c['code']]];
            $kb_rows[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_COUPON_MANAGEMENT]]; editMessageText($chat_id, $message_id, "Select coupon to delete:", json_encode(['inline_keyboard' => $kb_rows])); return;
        }
        elseif (strpos($data, CALLBACK_ADMIN_DELETE_COUPON_CONFIRM_PREFIX) === 0) { /* ... logic from previous step ... */
            $code_del = substr($data, strlen(CALLBACK_ADMIN_DELETE_COUPON_CONFIRM_PREFIX));
            if (deleteCoupon($code_del)) editMessageText($chat_id, $message_id, "✅ Coupon '{$code_del}' deleted.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_COUPON_MANAGEMENT]]]]));
            else editMessageText($chat_id, $message_id, "⚠️ Error deleting '{$code_del}'.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_DELETE_COUPON_SELECT]]]])); return;
        }
        elseif ($data === CALLBACK_ADMIN_SET_COUPON_TYPE_FIXED || $data === CALLBACK_ADMIN_SET_COUPON_TYPE_PERCENTAGE) { /* ... logic from previous step ... */
            $u_state = getUserState($user_id); if ($u_state && $u_state['status'] === STATE_ADMIN_ADDING_COUPON_TYPE) {
                $u_state['coupon_data']['discount_type'] = ($data === CALLBACK_ADMIN_SET_COUPON_TYPE_FIXED) ? 'fixed' : 'percentage'; $u_state['status'] = STATE_ADMIN_ADDING_COUPON_VALUE; setUserState($user_id, $u_state);
                $prompt = "Enter discount value for '<b>".htmlspecialchars($u_state['coupon_data']['code'])."</b>': " . ($u_state['coupon_data']['discount_type']==='percentage'?"Percentage (1-100)":"Fixed amount");
                editMessageText($chat_id, $message_id, $prompt, null, 'HTML');
            } return;
        }
        elseif (in_array($data, [CALLBACK_ADMIN_COUPON_SKIP_EXPIRY, CALLBACK_ADMIN_COUPON_SKIP_MAX_USES, CALLBACK_ADMIN_COUPON_SKIP_PER_USER_LIMIT, CALLBACK_ADMIN_COUPON_SKIP_MIN_PURCHASE, CALLBACK_ADMIN_COUPON_APPLICABLE_TO_ALL, CALLBACK_ADMIN_COUPON_APPLICABLE_TO_CATS, CALLBACK_ADMIN_COUPON_APPLICABLE_TO_PRODS])) { /* ... logic from previous step ... */
            $u_state = getUserState($user_id); if (!$u_state || !isset($u_state['coupon_data'])) { clearUserState($user_id); editMessageText($chat_id, $message_id, "Error: State lost.", json_encode(['inline_keyboard' => [[['text' => '« Coupon Mgt', 'callback_data' => CALLBACK_ADMIN_COUPON_MANAGEMENT]]]])); return; }
            $next_prompt = ''; $skip_kb = null; $next_status_val = '';
            if ($data === CALLBACK_ADMIN_COUPON_SKIP_EXPIRY && $u_state['status'] === STATE_ADMIN_ADDING_COUPON_EXPIRY) { $u_state['coupon_data']['expiry_date'] = null; $next_status_val = STATE_ADMIN_ADDING_COUPON_MAX_USES; $next_prompt = "Max total uses for '<b>".htmlspecialchars($u_state['coupon_data']['code'])."</b>' (/skip for unlimited):"; $skip_kb = json_encode(['inline_keyboard' => [[['text' => 'Skip (Unlimited)', 'callback_data' => CALLBACK_ADMIN_COUPON_SKIP_MAX_USES]]]]); }
            elseif ($data === CALLBACK_ADMIN_COUPON_SKIP_MAX_USES && $u_state['status'] === STATE_ADMIN_ADDING_COUPON_MAX_USES) { $u_state['coupon_data']['max_uses'] = PHP_INT_MAX; $next_status_val = STATE_ADMIN_ADDING_COUPON_PER_USER_LIMIT; $next_prompt = "Max uses per user for '<b>".htmlspecialchars($u_state['coupon_data']['code'])."</b>' (/skip for 1):"; $skip_kb = json_encode(['inline_keyboard' => [[['text' => 'Skip (1 Per User)', 'callback_data' => CALLBACK_ADMIN_COUPON_SKIP_PER_USER_LIMIT]]]]); }
            elseif ($data === CALLBACK_ADMIN_COUPON_SKIP_PER_USER_LIMIT && $u_state['status'] === STATE_ADMIN_ADDING_COUPON_PER_USER_LIMIT) { $u_state['coupon_data']['per_user_limit'] = 1; $next_status_val = STATE_ADMIN_ADDING_COUPON_MIN_PURCHASE; $next_prompt = "Min purchase for '<b>".htmlspecialchars($u_state['coupon_data']['code'])."</b>' (/skip for no min):"; $skip_kb = json_encode(['inline_keyboard' => [[['text' => 'Skip (No Minimum)', 'callback_data' => CALLBACK_ADMIN_COUPON_SKIP_MIN_PURCHASE]]]]); }
            elseif ($data === CALLBACK_ADMIN_COUPON_SKIP_MIN_PURCHASE && $u_state['status'] === STATE_ADMIN_ADDING_COUPON_MIN_PURCHASE) { $u_state['coupon_data']['min_purchase_amount'] = 0; $u_state['status'] = STATE_ADMIN_ADDING_COUPON_APPLICABLE_CATEGORIES; setUserState($user_id, $u_state); promptForCouponApplicability($chat_id, $message_id, $u_state['coupon_data']['code']); return; }
            elseif ($data === CALLBACK_ADMIN_COUPON_APPLICABLE_TO_ALL && $u_state['status'] === STATE_ADMIN_ADDING_COUPON_APPLICABLE_CATEGORIES) { $u_state['coupon_data']['applicable_to'] = ['categories'=>[],'products'=>[]]; if(addCoupon($u_state['coupon_data'])) editMessageText($chat_id, $message_id, "✅ Coupon '<b>".htmlspecialchars($u_state['coupon_data']['code'])."</b>' added (applies to all)!", json_encode(['inline_keyboard'=>[[['text'=>'« Coupon Mgt','callback_data'=>CALLBACK_ADMIN_COUPON_MANAGEMENT]]]]),"HTML"); else editMessageText($chat_id, $message_id, "⚠️ Error adding coupon.",json_encode(['inline_keyboard'=>[[['text'=>'« Coupon Mgt','callback_data'=>CALLBACK_ADMIN_COUPON_MANAGEMENT]]]])); clearUserState($user_id); return; }
            elseif ($data === CALLBACK_ADMIN_COUPON_APPLICABLE_TO_CATS && $u_state['status'] === STATE_ADMIN_ADDING_COUPON_APPLICABLE_CATEGORIES) { $u_state['status'] = STATE_ADMIN_ADDING_COUPON_APPLICABLE_CATEGORIES; $u_state['coupon_data']['_applicability_mode'] = 'categories'; $u_state['coupon_data']['applicable_to']['categories'] = []; setUserState($user_id, $u_state); editMessageText($chat_id, $message_id, "Enter category keys for '<b>".htmlspecialchars($u_state['coupon_data']['code'])."</b>' (comma-separated, or /skip):", null, "HTML"); return; }
            elseif ($data === CALLBACK_ADMIN_COUPON_APPLICABLE_TO_PRODS && $u_state['status'] === STATE_ADMIN_ADDING_COUPON_APPLICABLE_CATEGORIES ) { $u_state['status'] = STATE_ADMIN_ADDING_COUPON_APPLICABLE_PRODUCTS; $u_state['coupon_data']['_applicability_mode'] = 'products'; $u_state['coupon_data']['applicable_to']['products'] = []; setUserState($user_id, $u_state); editMessageText($chat_id, $message_id, "Enter product IDs (catKey_prodID, comma-separated) for '<b>".htmlspecialchars($u_state['coupon_data']['code'])."</b>', or /skip:", null, "HTML"); return; }
            if ($next_status_val && $next_prompt) { $u_state['status'] = $next_status_val; setUserState($user_id, $u_state); editMessageText($chat_id, $message_id, $next_prompt, $skip_kb, "HTML"); } return;
        }
        // Fallback for other admin actions like product/category management
        // This is a simplified catch-all to avoid breaking existing admin functionality not explicitly handled above in the coupon section.
        // A more robust approach would be to ensure all admin callbacks are distinctly handled or routed.
        elseif (in_array($data, [CALLBACK_ADMIN_CATEGORY_MANAGEMENT, CALLBACK_ADMIN_PROD_MANAGEMENT, CALLBACK_ADMIN_VIEW_STATS]) ||
                strpos($data, CALLBACK_ADMIN_ADD_CATEGORY_PROMPT) === 0 || strpos($data, CALLBACK_ADMIN_EDIT_CATEGORY_SELECT) === 0 ||
                strpos($data, CALLBACK_ADMIN_ADD_PROD_SELECT_CATEGORY) === 0 || strpos($data, CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY) === 0 ||
                strpos($data, CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY) === 0 || strpos($data, CALLBACK_ADMIN_REMOVE_CATEGORY_SELECT) === 0
            ) {
            // sendMessage($chat_id, "DEBUG: Fallback to other admin actions. Data: $data"); // Optional debug
            // This indicates the callback was not handled by the more specific coupon logic above.
            // Let the original logic for these (if any further down or if they are top-level) try to handle it.
            // For now, just log it if it's an unexpected fall-through.
            // error_log("Admin callback {$data} fell through coupon specific logic. Ensure it's handled elsewhere if needed.");
        }
    }
    elseif ( preg_match('/^(.*)_([^_]+)$/', $data, $matches_prod_select) && (strpos($data, 'view_category_') !== 0) && (strpos($data, 'admin_') !== 0) && ($data !== CALLBACK_BACK_TO_MAIN) && ($data !== CALLBACK_MY_PRODUCTS) && ($data !== CALLBACK_SUPPORT) && (strpos($data, CALLBACK_CONFIRM_BUY_PREFIX) !== 0) && (strpos($data, CALLBACK_ACCEPT_PAYMENT_PREFIX) !== 0) && (strpos($data, CALLBACK_REJECT_PAYMENT_PREFIX) !== 0) && (strpos($data, CALLBACK_ACCEPT_AND_SEND_PREFIX) !== 0) && (strpos($data, CALLBACK_VIEW_PURCHASED_ITEM_PREFIX) !== 0) && (strpos($data, 'skip_coupon_') !== 0) ) {
        global $products; $products = readJsonFile(PRODUCTS_FILE); $category_key_select = $matches_prod_select[1]; $product_id_select = $matches_prod_select[2];
        if (isset($products[$category_key_select][$product_id_select])) {
            $product_selected = $products[$category_key_select][$product_id_select];
            $plan_info_text = "<b>Product:</b> " . htmlspecialchars($product_selected['name']) . "\n<b>Price:</b> $" . htmlspecialchars($product_selected['price']) . "\n<b>Info:</b> " . nl2br(htmlspecialchars($product_selected['info'] ?? 'N/A')) . "\n\nDo you want to purchase this item?";
            $kb_prod_select = json_encode(['inline_keyboard' => [[['text' => "✅ Yes, Buy This", 'callback_data' => CALLBACK_CONFIRM_BUY_PREFIX . "{$category_key_select}_{$product_id_select}"]], [['text' => "« Back", 'callback_data' => 'view_category_' . $category_key_select ]]]]);
            editMessageText($chat_id, $message_id, $plan_info_text, $kb_prod_select, 'HTML');
        } else { editMessageText($chat_id, $message_id, "Sorry, product not found.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => 'view_category_' . $category_key_select ]]]])); } return;
    }
    elseif (strpos($data, CALLBACK_CONFIRM_BUY_PREFIX) === 0) {
        $ids_str_confirm_buy = substr($data, strlen(CALLBACK_CONFIRM_BUY_PREFIX)); if (!preg_match('/^(.+)_([^_]+)$/', $ids_str_confirm_buy, $matches_ids_confirm_buy)) { editMessageText($chat_id, $message_id, "Error processing purchase.", json_encode(['inline_keyboard'=>[[['text'=>'« Main Menu', 'callback_data'=>CALLBACK_BACK_TO_MAIN]]]])); return; }
        $category_key_confirm_buy = $matches_ids_confirm_buy[1]; $product_id_confirm_buy = $matches_ids_confirm_buy[2]; $product_to_buy = getProductDetails($category_key_confirm_buy, $product_id_confirm_buy);
        if ($product_to_buy) {
            setUserState($user_id, ['status' => STATE_AWAITING_COUPON_CODE, 'message_id' => $message_id, 'product_name' => $product_to_buy['name'], 'original_price' => $product_to_buy['price'], 'category_key' => $category_key_confirm_buy, 'product_id' => $product_id_confirm_buy, 'product_id_with_category' => $ids_str_confirm_buy]);
            $text_ask_coupon = "Purchasing <b>".htmlspecialchars($product_to_buy['name'])."</b> for \$".htmlspecialchars($product_to_buy['price']).".\n\nEnter coupon code, or type /skip or press Skip.";
            $kb_ask_coupon = json_encode(['inline_keyboard' => [[['text' => "Skip Coupon", 'callback_data' => 'skip_coupon_' . $ids_str_confirm_buy]], [['text' => '« Cancel', 'callback_data' => "{$category_key_confirm_buy}_{$product_id_confirm_buy}"]]]]);
            editMessageText($chat_id, $message_id, $text_ask_coupon, $kb_ask_coupon, 'HTML');
        } else { editMessageText($chat_id, $message_id, "Error: Product not found.", json_encode(['inline_keyboard'=>[[['text'=>'« Main Menu', 'callback_data'=>CALLBACK_BACK_TO_MAIN]]]])); }
    }
    elseif (strpos($data, 'skip_coupon_') === 0) {
        $ids_str_skip_coupon = substr($data, strlen('skip_coupon_')); if (!preg_match('/^(.+)_([^_]+)$/', $ids_str_skip_coupon, $matches_ids_skip_coupon)) { editMessageText($chat_id, $message_id, "Error processing.", json_encode(['inline_keyboard'=>[[['text'=>'« Main Menu', 'callback_data'=>CALLBACK_BACK_TO_MAIN]]]])); return; }
        $category_key_skip = $matches_ids_skip_coupon[1]; $product_id_skip = $matches_ids_skip_coupon[2]; $user_state_skip = getUserState($user_id);
        if ($user_state_skip && $user_state_skip['status'] === STATE_AWAITING_COUPON_CODE && $user_state_skip['category_key'] === $category_key_skip && $user_state_skip['product_id'] === $product_id_skip) {
            setUserState($user_id, ['status' => STATE_AWAITING_RECEIPT, 'message_id' => $message_id, 'product_name' => $user_state_skip['product_name'], 'price_to_pay' => $user_state_skip['original_price'], 'original_price' => $user_state_skip['original_price'], 'category_key' => $category_key_skip, 'product_id' => $product_id_skip, 'product_id_with_category' => $ids_str_skip_coupon, 'applied_coupon_code' => null, 'discount_amount' => 0]);
            $paymentDets_skip = getPaymentDetails();
            $text_payment_confirm = "To complete purchase for <b>".htmlspecialchars($user_state_skip['product_name'])."</b> (Price: \$".htmlspecialchars(number_format($user_state_skip['original_price'],2))."), transfer to:\n\nCard: `".htmlspecialchars($paymentDets_skip['card_number'])."`\nHolder: `".htmlspecialchars($paymentDets_skip['card_holder'])."`\n\nSend receipt screenshot. /cancel to cancel.";
            editMessageText($chat_id, $message_id, $text_payment_confirm, json_encode(['inline_keyboard' => [[['text' => '« Cancel Purchase', 'callback_data' => "{$category_key_skip}_{$product_id_skip}"]]]]), 'HTML');
        } else { editMessageText($chat_id, $message_id, "Issue processing. Try again.", json_encode(['inline_keyboard'=>[[['text'=>'« Main Menu', 'callback_data'=>CALLBACK_BACK_TO_MAIN]]]])); }
    }
    elseif (strpos($data, CALLBACK_ACCEPT_PAYMENT_PREFIX) === 0 || strpos($data, CALLBACK_REJECT_PAYMENT_PREFIX) === 0) {
        if(!$is_admin) { return; }
        $is_accept_payment = strpos($data, CALLBACK_ACCEPT_PAYMENT_PREFIX) === 0;
        $prefix_to_remove = $is_accept_payment ? CALLBACK_ACCEPT_PAYMENT_PREFIX : CALLBACK_REJECT_PAYMENT_PREFIX;
        $payload = substr($data, strlen($prefix_to_remove)); $parts = explode('_', $payload);
        if (count($parts) < 5) { error_log("PAY_CONF: Invalid payload format (P1). Payload: '{$payload}'."); editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Parse P1. Manual.", null, 'Markdown'); return; }
        $target_user_id_payment = $parts[0];
        if (!is_numeric($target_user_id_payment)) { error_log("PAY_CONF: Invalid UserID. UserID part: '{$parts[0]}'."); editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Parse UserID. Manual.", null, 'Markdown'); return; }
        $coupon_code_from_payload = $parts[count($parts) - 1]; $final_price_from_payload_str = $parts[count($parts) - 2]; $product_id_payment = $parts[count($parts) - 3];
        $category_key_parts_arr = array_slice($parts, 1, count($parts) - 4); $category_key_payment = implode('_', $category_key_parts_arr);
        if (empty($category_key_payment) || empty($product_id_payment)) { error_log("PAY_CONF: Failed Cat/Prod ID parse. Cat: '{$category_key_payment}', Prod: '{$product_id_payment}'."); editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Parse Cat/Prod. Manual.", null, 'Markdown'); return; }
        $final_price_to_pay_numeric = (float)str_replace('_', '.', $final_price_from_payload_str);
        $coupon_code_true_value = ($coupon_code_from_payload === 'NO_COUPON') ? null : $coupon_code_from_payload;
        $original_caption_payment = $callback_query->message->caption ?? '';
        $product_details_for_msg = getProductDetails($category_key_payment, $product_id_payment);
        $product_name_for_msg = $product_details_for_msg ? $product_details_for_msg['name'] : "Prod ID:{$product_id_payment}";
        $original_product_price_for_record = $product_details_for_msg ? (float)$product_details_for_msg['price'] : $final_price_to_pay_numeric; // Fallback if price not found

        if ($is_accept_payment) {
            $item_content_for_record = null; $admin_message_suffix = "\n\n✅ PAYMENT ACCEPTED by admin @".($callback_query->from->username ?? $user_id).".";
            $user_message = "✅ Payment for '<b>".htmlspecialchars($product_name_for_msg)."</b>' accepted.";
            if ($product_details_for_msg && ($product_details_for_msg['type'] ?? 'manual') === 'instant') {
                $item_to_deliver = getAndRemoveInstantProductItem($category_key_payment, $product_id_payment);
                if ($item_to_deliver !== null) { $item_content_for_record = $item_to_deliver; $user_message .= "\nItem:\n<code>" . htmlspecialchars($item_to_deliver) . "</code>"; $admin_message_suffix .= "\n✅ Instant item delivered."; }
                else { $user_message .= "\n⚠️ Out of stock. Contact support!"; $admin_message_suffix .= "\n⚠️ INSTANT DELIVERY FAILED: OUT OF STOCK. Handle manually."; }
            } else { $user_message .= "\nManual delivery soon."; $admin_message_suffix .= "\nℹ️ Manual delivery."; }

            $discount_amount_for_record = 0;
            if ($coupon_code_true_value && $original_product_price_for_record > $final_price_to_pay_numeric) {
                $discount_amount_for_record = round($original_product_price_for_record - $final_price_to_pay_numeric, 2);
            }

            recordPurchase($target_user_id_payment, $product_name_for_msg, $original_product_price_for_record, $final_price_to_pay_numeric, $coupon_code_true_value, $discount_amount_for_record, $item_content_for_record);

            if ($coupon_code_true_value) {
                if (redeemCoupon($coupon_code_true_value, $target_user_id_payment)) { $admin_message_suffix .= "\n🎟️ Coupon '{$coupon_code_true_value}' redeemed."; }
                else { $admin_message_suffix .= "\n⚠️ FAILED to redeem coupon '{$coupon_code_true_value}'. Check manually."; }
            }
            editMessageCaption($chat_id, $message_id, $original_caption_payment . $admin_message_suffix, null, 'Markdown');
            sendMessage($target_user_id_payment, $user_message);
        } else { /* Payment Rejected */
            $admin_message_suffix = "\n\n❌ PAYMENT REJECTED by admin @".($callback_query->from->username ?? $user_id).".";
            if ($coupon_code_true_value) $admin_message_suffix .= "\n(Coupon '{$coupon_code_true_value}' not redeemed.)";
            editMessageCaption($chat_id, $message_id, $original_caption_payment . $admin_message_suffix, null, 'Markdown');
            sendMessage($target_user_id_payment, "⚠️ Payment for '<b>".htmlspecialchars($product_name_for_msg)."</b>' rejected. Contact support.");
        }
    }
    elseif (strpos($data, CALLBACK_ACCEPT_AND_SEND_PREFIX) === 0) {
        if(!$is_admin) return;
        $payload_send = substr($data, strlen(CALLBACK_ACCEPT_AND_SEND_PREFIX)); $parts_send = explode('_', $payload_send);
        if (count($parts_send) < 5) { error_log("ACCEPT_SEND_CONF: Invalid payload: {$payload_send}"); editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Data error. Manual.", null, 'Markdown'); return; }
        $target_user_id_send = $parts_send[0]; if (!is_numeric($target_user_id_send)) return;
        $coupon_code_send_str = $parts_send[count($parts_send) - 1];
        $final_price_send_str = $parts_send[count($parts_send) - 2]; // Price paid by user
        $product_id_send = $parts_send[count($parts_send) - 3];
        $category_key_parts_send_arr = array_slice($parts_send, 1, count($parts_send) - 4); $category_key_send = implode('_', $category_key_parts_send_arr);
        $coupon_code_send_true_value = ($coupon_code_send_str === 'NO_COUPON') ? null : $coupon_code_send_str;
        $final_price_send_numeric = (float)str_replace('_', '.', $final_price_send_str);

        if (empty($category_key_send) || empty($product_id_send)) { error_log("ACCEPT_SEND_CONF: Parse error. Payload: {$payload_send}"); editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Parse error2. Manual.", null, 'Markdown'); return; }
        $product_details_send = getProductDetails($category_key_send, $product_id_send); if (!$product_details_send) return;
        $product_name_send = $product_details_send['name'];
        $original_product_price_send = (float)($product_details_send['price'] ?? $final_price_send_numeric);

        $discount_amount_send_record = 0;
        if($coupon_code_send_true_value && $original_product_price_send > $final_price_send_numeric){
            $discount_amount_send_record = round($original_product_price_send - $final_price_send_numeric, 2);
        }

        $purchase_index = recordPurchase($target_user_id_send, $product_name_send, $original_product_price_send, $final_price_send_numeric, $coupon_code_send_true_value, $discount_amount_send_record, null);
        if ($purchase_index === false) return;

        if ($coupon_code_send_true_value) { if (redeemCoupon($coupon_code_send_true_value, $target_user_id_send)) error_log("ACCEPT_SEND_CONF: Coupon '{$coupon_code_send_true_value}' redeemed (manual)."); else error_log("ACCEPT_SEND_CONF_WARN: Failed redeem '{$coupon_code_send_true_value}' (manual).");}

        sendMessage($target_user_id_send, "✅ Payment for '<b>".htmlspecialchars($product_name_send)."</b>' accepted. Admin contacting you.");
        setUserState($user_id, ['status' => STATE_ADMIN_MANUAL_SEND_SESSION, 'target_user_id' => $target_user_id_send, 'purchase_category' => $category_key_send, 'purchase_product_id' => $product_id_send, 'purchase_index' => $purchase_index, 'original_admin_msg_id' => $message_id]);
        setUserState($target_user_id_send, ['status' => 'in_manual_send_session_with_admin', 'admin_id' => $user_id]);
        editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n✅ Payment accepted for ".htmlspecialchars($product_name_send).". Manual send session started.", null, 'Markdown');
        sendMessage($chat_id, "➡️ Live with User ID: <b>{$target_user_id_send}</b> for '<b>".htmlspecialchars($product_name_send)."</b>'.\nReply /save to your message to store. /end when done.", null, "HTML");
        sendMessage($target_user_id_send, "Admin connected for '<b>".htmlspecialchars($product_name_send)."</b>'. Please wait.");
    }
    elseif ($data === CALLBACK_BACK_TO_MAIN) {
        clearUserState($user_id); $first_name_main = $callback_query->from->first_name;
        editMessageText($chat_id, $message_id, "Hello, " . htmlspecialchars($first_name_main) . "! Welcome back.", json_encode(generateDynamicMainMenuKeyboard($is_admin)));
    }
}
?>
