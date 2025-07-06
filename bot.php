<?php
// FILE: bot.php
// The main webhook entry point.

// --- Include necessary files ---
require_once 'config.php';
require_once 'functions.php';

// --- Get the update from Telegram ---
$update = json_decode(file_get_contents('php://input'));

if (!$update) { exit(); }

// ===================================================================
//  PROCESS THE UPDATE
// ===================================================================

// --- PRIORITY 1: Handle button presses (callback queries) ---
if (isset($update->callback_query)) {
    processCallbackQuery($update->callback_query);
    exit();
}

// --- PRIORITY 2: Handle regular messages ---
if (isset($update->message)) {
    $message = $update->message;
    $chat_id = $message->chat->id;
    $user_id = $message->from->id;
    $text = $message->text ?? null;
    $is_admin = in_array($user_id, getAdminIds());
    $user_state = getUserState($user_id);

    // Check if user is banned
    $user_specific_data = getUserData($user_id);
    if ($user_specific_data['is_banned']) {
        sendMessage($chat_id, "⚠️ You are banned from using this bot.");
        exit();
    }

    // --- Handle /cancel globally for admin states if applicable ---
    if ($is_admin && is_array($user_state) && isset($user_state['status']) && strpos($user_state['status'], 'admin_adding_coupon_') === 0 && strtolower($text) === '/cancel') {
        $original_message_id_to_edit = $user_state['original_message_id'] ?? null;
        clearUserState($user_id);
        $coupon_mgt_keyboard_on_cancel = json_encode(['inline_keyboard' => [
            [['text' => "➕ Add Coupon", 'callback_data' => CALLBACK_ADMIN_ADD_COUPON_PROMPT]],
            [['text' => "📋 List Coupons", 'callback_data' => CALLBACK_ADMIN_LIST_COUPONS]],
            [['text' => "➖ Delete Coupon", 'callback_data' => CALLBACK_ADMIN_DELETE_COUPON_SELECT]],
            [['text' => '« Back to Admin Panel', 'callback_data' => CALLBACK_ADMIN_PANEL]]
        ]]);
        if ($original_message_id_to_edit) {
            editMessageText($chat_id, $original_message_id_to_edit, "🎟️ Coupon creation cancelled. Coupon Management:", $coupon_mgt_keyboard_on_cancel);
        } else {
            sendMessage($chat_id, "🎟️ Coupon creation cancelled. Coupon Management:", $coupon_mgt_keyboard_on_cancel);
        }
        exit();
    }
    // Also handle /cancel for product adding flow
    if ($is_admin && is_array($user_state) && isset($user_state['status']) &&
        in_array($user_state['status'], [STATE_ADMIN_ADDING_PROD_NAME, STATE_ADMIN_ADDING_PROD_PRICE, STATE_ADMIN_ADDING_PROD_INFO, STATE_ADMIN_ADDING_PROD_ID, STATE_ADMIN_ADDING_PROD_INSTANT_ITEMS]) &&
        strtolower($text) === '/canceladdproduct') { // More specific cancel for products
        $original_message_id_prod_cancel = $user_state['original_message_id'] ?? null; // Assuming this might be stored
        clearUserState($user_id);
        $prod_mgt_kb = json_encode(['inline_keyboard' => [ /* ... product management keyboard ... */]]); // Define this
        if ($original_message_id_prod_cancel) {
             editMessageText($chat_id, $original_message_id_prod_cancel, "📦 Product creation cancelled. Product Management:", $prod_mgt_kb);
        } else {
            sendMessage($chat_id, "📦 Product creation cancelled. Product Management:", $prod_mgt_kb);
        }
        exit();
    }


    // --- Admin is adding a product (New Flow using defined constants) ---
    if ($is_admin && is_array($user_state) &&
        in_array($user_state['status'], [
            STATE_ADMIN_ADDING_PROD_NAME,
            STATE_ADMIN_ADDING_PROD_PRICE,
            STATE_ADMIN_ADDING_PROD_INFO,
            STATE_ADMIN_ADDING_PROD_ID,
            STATE_ADMIN_ADDING_PROD_INSTANT_ITEMS
            ])
    ) {
        // ... (existing product adding logic)
        switch ($user_state['status']) {
            case STATE_ADMIN_ADDING_PROD_NAME:
                $user_state['new_product_name'] = $text;
                $user_state['status'] = STATE_ADMIN_ADDING_PROD_TYPE_PROMPT;
                setUserState($user_id, $user_state);
                promptForProductType($chat_id, $user_id, $user_state['category_key'], $text);
                break;
            case STATE_ADMIN_ADDING_PROD_PRICE:
                if (!is_numeric($text) || $text < 0) { sendMessage($chat_id, "Invalid price. Non-negative number."); break; }
                $user_state['new_product_price'] = $text;
                $user_state['status'] = STATE_ADMIN_ADDING_PROD_INFO;
                setUserState($user_id, $user_state);
                sendMessage($chat_id, "Enter product info for '{$user_state['new_product_name']}':");
                break;
            case STATE_ADMIN_ADDING_PROD_INFO:
                $user_state['new_product_info'] = $text; setUserState($user_id, $user_state);
                if ($user_state['new_product_type'] === 'instant') {
                    $user_state['status'] = STATE_ADMIN_ADDING_PROD_INSTANT_ITEMS;
                    $user_state['new_product_items_buffer'] = []; setUserState($user_id, $user_state);
                    sendMessage($chat_id, "Instant Delivery. Send each item as separate message. Type /doneitems when finished for '{$user_state['new_product_name']}'.");
                } else {
                    $user_state['status'] = STATE_ADMIN_ADDING_PROD_ID; setUserState($user_id, $user_state);
                    sendMessage($chat_id, "Manual Delivery. Enter unique ID for '{$user_state['new_product_name']}':");
                }
                break;
            case STATE_ADMIN_ADDING_PROD_INSTANT_ITEMS:
                if ($text === '/doneitems') {
                    $user_state['status'] = STATE_ADMIN_ADDING_PROD_ID; setUserState($user_id, $user_state);
                    sendMessage($chat_id, "Items for '{$user_state['new_product_name']}' received (" . count($user_state['new_product_items_buffer']) . "). Enter unique product ID:");
                } else {
                    $user_state['new_product_items_buffer'][] = $text; setUserState($user_id, $user_state);
                    sendMessage($chat_id, "Item added. Next item or /doneitems.");
                }
                break;
            case STATE_ADMIN_ADDING_PROD_ID:
                $product_id_input = trim($text);
                if (empty($product_id_input)) { sendMessage($chat_id, "Product ID cannot be empty."); break; }
                global $products; if (isset($products[$user_state['category_key']][$product_id_input])) { sendMessage($chat_id, "Product ID '{$product_id_input}' already exists. Try different ID:"); break; }
                $new_product_data = ['name' => $user_state['new_product_name'], 'price' => $user_state['new_product_price'], 'type' => $user_state['new_product_type'], 'info' => $user_state['new_product_info'], 'items' => ($user_state['new_product_type'] === 'instant' ? $user_state['new_product_items_buffer'] : [])];
                $products[$user_state['category_key']][$product_id_input] = $new_product_data;
                if (writeJsonFile(PRODUCTS_FILE, $products)) { sendMessage($chat_id, "✅ Product '{$user_state['new_product_name']}' added to '{$user_state['category_key']}'!"); clearUserState($user_id); }
                else { sendMessage($chat_id, "⚠️ FAILED to save product. Try ID again or /canceladdproduct."); }
                break;
        }
    }
    // --- Admin is adding a coupon ---
    elseif ($is_admin && is_array($user_state) && isset($user_state['status']) && strpos($user_state['status'], 'admin_adding_coupon_') === 0) {
        $coupon_data = &$user_state['coupon_data']; // Use reference
        $original_msg_id = $user_state['original_message_id'];

        switch ($user_state['status']) {
            case STATE_ADMIN_ADDING_COUPON_CODE:
                if (empty(trim($text)) || !preg_match('/^[a-zA-Z0-9_-]+$/', trim($text))) { sendMessage($chat_id, "Invalid coupon code format. Alphanumeric, hyphens, underscores only. Try again or /cancel."); break; }
                if (getCoupon(trim($text))) { sendMessage($chat_id, "Coupon code '".htmlspecialchars(trim($text))."' already exists. Try a different one or /cancel."); break; }
                $coupon_data['code'] = trim($text);
                $user_state['status'] = STATE_ADMIN_ADDING_COUPON_DESCRIPTION;
                setUserState($user_id, $user_state);
                editMessageText($chat_id, $original_msg_id, "Coupon Code: `{$coupon_data['code']}`\nEnter a short description for this coupon (e.g., 'Summer Sale 10% off'):\nOr type /skip.", null, "Markdown");
                break;
            case STATE_ADMIN_ADDING_COUPON_DESCRIPTION:
                $coupon_data['description'] = (strtolower(trim($text)) === '/skip') ? '' : trim($text);
                $user_state['status'] = STATE_ADMIN_ADDING_COUPON_TYPE;
                setUserState($user_id, $user_state);
                $type_kb = json_encode(['inline_keyboard' => [
                    [['text' => 'Fixed Amount ($)', 'callback_data' => CALLBACK_ADMIN_SET_COUPON_TYPE_FIXED]],
                    [['text' => 'Percentage (%)', 'callback_data' => CALLBACK_ADMIN_SET_COUPON_TYPE_PERCENTAGE]]
                ]]);
                editMessageText($chat_id, $original_msg_id, "Coupon: `{$coupon_data['code']}`\nDescription: ".($coupon_data['description']?:'N/A')."\nSelect discount type:", $type_kb, "Markdown");
                break;
            case STATE_ADMIN_ADDING_COUPON_VALUE: // Value is set via callback, this state is for text input for value
                if (!is_numeric($text) || $text < 0) { sendMessage($chat_id, "Invalid value. Must be a non-negative number. Try again:"); break;}
                $coupon_data['discount_value'] = (float)$text;
                if ($coupon_data['discount_type'] === 'percentage' && ($coupon_data['discount_value'] <= 0 || $coupon_data['discount_value'] > 100)) {
                     sendMessage($chat_id, "Percentage value must be between 1 and 100. Try again:"); break;
                }
                $user_state['status'] = STATE_ADMIN_ADDING_COUPON_EXPIRY;
                setUserState($user_id, $user_state);
                $skip_kb = json_encode(['inline_keyboard' => [[['text' => 'Skip (No Expiry)', 'callback_data' => CALLBACK_ADMIN_COUPON_SKIP_EXPIRY]]]]);
                editMessageText($chat_id, $original_msg_id, "Coupon `{$coupon_data['code']}`\nValue: {$coupon_data['discount_value']}" . ($coupon_data['discount_type']==='percentage'?'%':'$') . "\nEnter expiry date (YYYY-MM-DD), or /skip for no expiry:", $skip_kb, "Markdown");
                break;
            case STATE_ADMIN_ADDING_COUPON_EXPIRY:
                if (strtolower(trim($text)) === '/skip') {
                    // This case should be handled by callback, but as a fallback if user types /skip
                    $coupon_data['expiry_date'] = null;
                } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($text)) || strtotime(trim($text)) === false) {
                    sendMessage($chat_id, "Invalid date format. Use YYYY-MM-DD. Try again or /skip."); break;
                } elseif (strtotime(trim($text)) < time()) {
                    sendMessage($chat_id, "Expiry date cannot be in the past. Try again or /skip."); break;
                } else {
                    $coupon_data['expiry_date'] = trim($text);
                }
                $user_state['status'] = STATE_ADMIN_ADDING_COUPON_MAX_USES;
                setUserState($user_id, $user_state);
                $skip_kb = json_encode(['inline_keyboard' => [[['text' => 'Skip (Unlimited Uses)', 'callback_data' => CALLBACK_ADMIN_COUPON_SKIP_MAX_USES]]]]);
                editMessageText($chat_id, $original_msg_id, "Expiry: ".($coupon_data['expiry_date']?:'Never')."\nEnter max total uses (e.g., 100), or /skip for unlimited:", $skip_kb, "Markdown");
                break;
            case STATE_ADMIN_ADDING_COUPON_MAX_USES:
                if (strtolower(trim($text)) === '/skip') $coupon_data['max_uses'] = PHP_INT_MAX;
                elseif (!is_numeric($text) || (int)$text < 0) { sendMessage($chat_id, "Invalid number for max uses. Try again or /skip."); break; }
                else $coupon_data['max_uses'] = (int)$text;
                $user_state['status'] = STATE_ADMIN_ADDING_COUPON_PER_USER_LIMIT;
                setUserState($user_id, $user_state);
                $skip_kb = json_encode(['inline_keyboard' => [[['text' => 'Skip (1 Per User)', 'callback_data' => CALLBACK_ADMIN_COUPON_SKIP_PER_USER_LIMIT]]]]);
                editMessageText($chat_id, $original_msg_id, "Max Uses: ".($coupon_data['max_uses']==PHP_INT_MAX?'∞':$coupon_data['max_uses'])."\nEnter max uses per user (e.g., 1), or /skip for 1:", $skip_kb, "Markdown");
                break;
            case STATE_ADMIN_ADDING_COUPON_PER_USER_LIMIT:
                if (strtolower(trim($text)) === '/skip') $coupon_data['per_user_limit'] = 1;
                elseif (!is_numeric($text) || (int)$text < 0) { sendMessage($chat_id, "Invalid number for per-user limit. Try again or /skip."); break; }
                else $coupon_data['per_user_limit'] = (int)$text;
                $user_state['status'] = STATE_ADMIN_ADDING_COUPON_MIN_PURCHASE;
                setUserState($user_id, $user_state);
                $skip_kb = json_encode(['inline_keyboard' => [[['text' => 'Skip (No Minimum)', 'callback_data' => CALLBACK_ADMIN_COUPON_SKIP_MIN_PURCHASE]]]]);
                editMessageText($chat_id, $original_msg_id, "Per User Limit: ".($coupon_data['per_user_limit']==PHP_INT_MAX?'∞':$coupon_data['per_user_limit'])."\nEnter minimum purchase amount (e.g., 50 for $50), or /skip for no minimum:", $skip_kb,"Markdown");
                break;
            case STATE_ADMIN_ADDING_COUPON_MIN_PURCHASE:
                if (strtolower(trim($text)) === '/skip') $coupon_data['min_purchase_amount'] = 0;
                elseif (!is_numeric($text) || (float)$text < 0) { sendMessage($chat_id, "Invalid minimum purchase. Try again or /skip."); break; }
                else $coupon_data['min_purchase_amount'] = (float)$text;
                // Next is applicability
                $user_state['status'] = STATE_ADMIN_ADDING_COUPON_APPLICABLE_CATEGORIES; // This state will show choices via callback
                setUserState($user_id, $user_state);
                promptForCouponApplicability($chat_id, $original_msg_id, $coupon_data['code']);
                break;
            case STATE_ADMIN_ADDING_COUPON_APPLICABLE_CATEGORIES: // Admin is providing comma-separated categories
                if (strtolower(trim($text)) === '/skip' || strtolower(trim($text)) === '/done') { // Skip or done with categories
                    // If skipped, categories array remains empty. Now ask for products or finalize.
                    $user_state['status'] = STATE_ADMIN_ADDING_COUPON_APPLICABLE_PRODUCTS;
                     setUserState($user_id, $user_state);
                    editMessageText($chat_id, $original_msg_id, "Categories: ".(empty($coupon_data['applicable_to']['categories']) ? 'All (Not restricted by category)' : implode(', ',$coupon_data['applicable_to']['categories']))."\nEnter product IDs (catKey_prodID, comma-separated) for coupon '<b>".htmlspecialchars($coupon_data['code'])."</b>', or /skip if it applies to all products (within selected categories, or all if categories were skipped).", null, "HTML");
                } else {
                    $cats_input = array_map('trim', explode(',', $text));
                    $valid_cats = []; $invalid_cats = []; global $products; if(empty($products)) $products = readJsonFile(PRODUCTS_FILE);
                    foreach($cats_input as $cat_key) if(isset($products[$cat_key])) $valid_cats[] = $cat_key; else $invalid_cats[] = $cat_key;
                    if(!empty($invalid_cats)) { sendMessage($chat_id, "Invalid category keys: ".implode(', ',$invalid_cats).". Please re-enter valid comma-separated category keys or /skip."); break; }
                    $coupon_data['applicable_to']['categories'] = array_unique(array_merge($coupon_data['applicable_to']['categories'] ?? [], $valid_cats));
                    setUserState($user_id, $user_state); // Save current selection
                    sendMessage($chat_id, "Added categories: ".implode(', ', $valid_cats).". Add more (comma-separated) or type /done to proceed to specific products or finalize.");
                }
                break;
            case STATE_ADMIN_ADDING_COUPON_APPLICABLE_PRODUCTS: // Admin is providing comma-separated products
                 if (strtolower(trim($text)) === '/skip' || strtolower(trim($text)) === '/done') { // Skip or done with products
                    // All data collected, try to add coupon
                    if (addCoupon($coupon_data)) {
                        editMessageText($chat_id, $original_msg_id, "✅ Coupon '<b>".htmlspecialchars($coupon_data['code'])."</b>' added successfully!", json_encode(['inline_keyboard' => [[['text' => '« Coupon Mgt', 'callback_data' => CALLBACK_ADMIN_COUPON_MANAGEMENT]]]]), "HTML");
                    } else {
                        editMessageText($chat_id, $original_msg_id, "⚠️ Error adding coupon. It might already exist or data was invalid.", json_encode(['inline_keyboard' => [[['text' => '« Coupon Mgt', 'callback_data' => CALLBACK_ADMIN_COUPON_MANAGEMENT]]]]));
                    }
                    clearUserState($user_id);
                } else {
                    $prods_input = array_map('trim', explode(',', $text));
                    $valid_prods = []; $invalid_prods = []; global $products; if(empty($products)) $products = readJsonFile(PRODUCTS_FILE);
                    foreach($prods_input as $prod_full_id) {
                        $parts = explode('_', $prod_full_id, 2); // Limit split to 2 parts for cat_prod
                        if(count($parts) === 2 && isset($products[$parts[0]][$parts[1]])) $valid_prods[] = $prod_full_id;
                        else $invalid_prods[] = $prod_full_id;
                    }
                    if(!empty($invalid_prods)) { sendMessage($chat_id, "Invalid product IDs (format: catKey_prodID): ".implode(', ',$invalid_prods).". Please re-enter valid comma-separated product IDs or /skip."); break; }
                     $coupon_data['applicable_to']['products'] = array_unique(array_merge($coupon_data['applicable_to']['products'] ?? [], $valid_prods));
                     setUserState($user_id, $user_state); // Save current selection
                     sendMessage($chat_id, "Added products: ".implode(', ', $valid_prods).". Add more (comma-separated) or type /done to finalize coupon.");
                }
                break;
        }
        unset($coupon_data); // Unset reference
    }
    // --- Admin is manually adding a product for a user (after /addprod <USERID>) ---
    elseif ($is_admin && is_array($user_state) && $user_state['status'] === STATE_ADMIN_ADDING_PROD_MANUAL) { /* ... existing logic ... */ }
    // --- Admin is editing an existing category name ---
    elseif ($is_admin && is_array($user_state) && $user_state['status'] === STATE_ADMIN_EDITING_CATEGORY_NAME) { /* ... existing logic ... */ }
    // --- Admin is adding a new category name ---
    elseif ($is_admin && is_array($user_state) && $user_state['status'] === STATE_ADMIN_ADDING_CATEGORY_NAME) { /* ... existing logic ... */ }
    // --- Admin is editing a product field ---
    elseif ($is_admin && is_array($user_state) && $user_state['status'] === STATE_ADMIN_EDITING_PROD_FIELD) { /* ... existing logic ... */ }
    // --- Admin is adding a single instant item to an existing product ---
    elseif ($is_admin && is_array($user_state) && $user_state['status'] === STATE_ADMIN_ADDING_SINGLE_INSTANT_ITEM) { /* ... existing logic ... */ }
    // --- Admin is in a manual send session with a user ---
    elseif ($is_admin && is_array($user_state) && $user_state['status'] === STATE_ADMIN_MANUAL_SEND_SESSION) { /* ... existing logic ... */ }
    // --- User is in a manual send session with an admin (receiving messages or sending to admin) ---
    elseif (!$is_admin && is_array($user_state) && $user_state['status'] === 'in_manual_send_session_with_admin') { /* ... existing logic ... */ }
    // --- User is in a direct support chat ---
    elseif (isset($user_state['chatting_with'])) { /* ... existing logic ... */ }
    // --- No special state, handle regular commands and messages ---
    else {
        if (is_array($user_state) && ($user_state['status'] ?? null) === STATE_AWAITING_SUPPORT_MESSAGE) { /* ... existing logic ... */ }
        // Admin command: /addprod <USERID>
        elseif ($is_admin && preg_match('/^\/addprod\s+(\d+)$/', $text, $matches)) { /* ... existing logic ... */ }
        // Admin wants to start a chat
        elseif ($is_admin && preg_match('/^\/s(\d+)$/', $text, $matches)) { /* ... existing logic ... */ }
        // User is awaiting to enter a coupon code
        elseif (is_array($user_state) && $user_state['status'] === STATE_AWAITING_COUPON_CODE) {
            // ... (existing coupon code entry logic from Step 2) ...
            $coupon_code_input = trim($text);
            $original_message_id_coupon = $user_state['message_id'];
            $product_name_coupon = $user_state['product_name'];
            $original_price_coupon = $user_state['original_price'];
            $category_key_coupon = $user_state['category_key'];
            $product_id_coupon = $user_state['product_id'];
            $product_id_with_category_coupon = $user_state['product_id_with_category'];

            if (strtolower($coupon_code_input) === '/skip') {
                setUserState($user_id, ['status' => STATE_AWAITING_RECEIPT, 'message_id' => $original_message_id_coupon, 'product_name' => $product_name_coupon, 'price_to_pay' => $original_price_coupon, 'original_price' => $original_price_coupon, 'category_key' => $category_key_coupon, 'product_id' => $product_id_coupon, 'product_id_with_category' => $product_id_with_category_coupon, 'applied_coupon_code' => null, 'discount_amount' => 0]);
                $paymentDets_coupon_skip = getPaymentDetails();
                $text_payment_confirm_coupon_skip = "To complete purchase for <b>".htmlspecialchars($product_name_coupon)."</b> (Price: \$".htmlspecialchars(number_format($original_price_coupon, 2))."), transfer to:\n\nCard: `".htmlspecialchars($paymentDets_coupon_skip['card_number'])."`\nHolder: `".htmlspecialchars($paymentDets_coupon_skip['card_holder'])."`\n\nSend receipt screenshot. /cancel to cancel.";
                editMessageText($chat_id, $original_message_id_coupon, $text_payment_confirm_coupon_skip, json_encode(['inline_keyboard' => [[['text' => '« Cancel Purchase', 'callback_data' => "{$category_key_coupon}_{$product_id_coupon}"]]]]), 'HTML');
            } elseif (strtolower($coupon_code_input) === '/cancel') {
                clearUserState($user_id);
                editMessageText($chat_id, $original_message_id_coupon, "Purchase cancelled.", json_encode(['inline_keyboard' => [[['text' => '« View Product Again', 'callback_data' => $product_id_with_category_coupon ]], [['text' => '« Main Menu', 'callback_data' => CALLBACK_BACK_TO_MAIN ]]]]));
            } else {
                $validation_result = validateCoupon($coupon_code_input, $user_id, $category_key_coupon, $product_id_with_category_coupon, $original_price_coupon);
                if ($validation_result['valid']) {
                    $coupon_details = $validation_result['coupon_details']; $price_calculation = applyCoupon($coupon_details, $original_price_coupon);
                    setUserState($user_id, ['status' => STATE_AWAITING_RECEIPT, 'message_id' => $original_message_id_coupon, 'product_name' => $product_name_coupon, 'price_to_pay' => $price_calculation['discounted_price'], 'original_price' => $original_price_coupon, 'category_key' => $category_key_coupon, 'product_id' => $product_id_coupon, 'product_id_with_category' => $product_id_with_category_coupon, 'applied_coupon_code' => $coupon_details['code'], 'discount_amount' => $price_calculation['discount_amount']]);
                    $paymentDets_coupon = getPaymentDetails();
                    $text_payment_confirm_coupon = "✅ Coupon '<b>".htmlspecialchars($coupon_details['code'])."</b>' applied!\nOriginal: \$".htmlspecialchars(number_format($original_price_coupon,2)).", Discount: \$".htmlspecialchars(number_format($price_calculation['discount_amount'],2))."\n<b>New Price: \$".htmlspecialchars(number_format($price_calculation['discounted_price'],2))."</b>\n\nTransfer \$".htmlspecialchars(number_format($price_calculation['discounted_price'],2))." to:\n\nCard: `".htmlspecialchars($paymentDets_coupon['card_number'])."`\nHolder: `".htmlspecialchars($paymentDets_coupon['card_holder'])."`\n\nSend receipt. /cancel to cancel.";
                    editMessageText($chat_id, $original_message_id_coupon, $text_payment_confirm_coupon, json_encode(['inline_keyboard' => [[['text' => '« Cancel Purchase', 'callback_data' => "{$category_key_coupon}_{$product_id_coupon}"]]]]), 'HTML');
                } else {
                    $text_ask_again = "⚠️ ".htmlspecialchars($validation_result['message'])."\nEnter valid coupon, /skip, or /cancel.";
                    editMessageText($chat_id, $original_message_id_coupon, $text_ask_again, json_encode(['inline_keyboard' => [[['text' => "Skip Coupon", 'callback_data' => 'skip_coupon_' . $product_id_with_category_coupon]], [['text' => '« Cancel', 'callback_data' => $product_id_with_category_coupon ]]]]), 'HTML');
                }
            }
        }
        // User sends /start
        elseif ($text === "/start") { /* ... existing logic ... */
            $first_name = $message->from->first_name;
            $welcome_text = "Hello, " . htmlspecialchars($first_name) . "! Welcome.";
            sendMessage($chat_id, $welcome_text, json_encode(generateDynamicMainMenuKeyboard($is_admin)));
        }
        // User sends a photo receipt
        elseif (isset($message->photo)) {
            // ... (existing photo receipt logic from Step 2) ...
            $state = getUserState($user_id);
            if (is_array($state) && ($state['status'] ?? null) === STATE_AWAITING_RECEIPT) {
                if (isset($state['message_id'])) { editMessageReplyMarkup($chat_id, $state['message_id'], null); }
                $product_name = $state['product_name'] ?? 'Unknown'; $price_to_pay = $state['price_to_pay'] ?? 'N/A';
                $original_price_receipt = $state['original_price'] ?? $price_to_pay; $applied_coupon_code_receipt = $state['applied_coupon_code'] ?? null;
                $discount_amount_receipt = $state['discount_amount'] ?? 0; $category_key = $state['category_key'] ?? 'unknown'; $product_id = $state['product_id'] ?? 'unknown';
                $user_info = "🧾 New Payment Receipt\n\n▪️ Product: " . htmlspecialchars($product_name) . "\n";
                if ($applied_coupon_code_receipt) $user_info .= "▪️ Original Price: $" . htmlspecialchars(number_format($original_price_receipt, 2)) . "\n▪️ Coupon: " . htmlspecialchars($applied_coupon_code_receipt) . "\n▪️ Discount: $" . htmlspecialchars(number_format($discount_amount_receipt, 2)) . "\n▪️ Final Price: $" . htmlspecialchars(number_format($price_to_pay, 2)) . "\n";
                else $user_info .= "▪️ Price Paid: $" . htmlspecialchars(number_format($price_to_pay, 2)) . "\n";
                $user_info .= "\n👤 From User:\nName: " . htmlspecialchars(($message->from->first_name ?? '') . " " . ($message->from->last_name ?? '')) . "\nUsername: @" . ($message->from->username ?? 'N/A') . "\nID: `$user_id`";
                $photo_file_id = $message->photo[count($message->photo) - 1]->file_id;
                forwardPhotoToAdmin($photo_file_id, $user_info, $user_id, $category_key, $product_id, $price_to_pay, $applied_coupon_code_receipt);
                sendMessage($chat_id, "✅ Receipt submitted for review."); clearUserState($user_id);
            } else { sendMessage($chat_id, "Photo received, but not expecting one. Use Support if help needed."); }
        }
    }
}
?>
