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

    error_log("DEBUG_MSG_HANDLER: UserID: {$user_id}, IsAdmin: " . ($is_admin ? 'Yes' : 'No') . ", Text: '{$text}', UserState: " . print_r($user_state, true));

    $user_specific_data = getUserData($user_id);
    if ($user_specific_data['is_banned']) {
        sendMessage($chat_id, "⚠️ You are banned from using this bot.");
        exit();
    }

    // --- Admin States ---
    if ($is_admin && is_array($user_state)) {
        // Admin adding a product
        if (in_array($user_state['status'], [
            STATE_ADMIN_ADDING_PROD_NAME, STATE_ADMIN_ADDING_PROD_PRICE,
            STATE_ADMIN_ADDING_PROD_INFO, STATE_ADMIN_ADDING_PROD_ID,
            STATE_ADMIN_ADDING_PROD_INSTANT_ITEMS
        ])) {
            // ... (product adding logic as before) ...
            switch ($user_state['status']) {
                case STATE_ADMIN_ADDING_PROD_NAME:
                    $user_state['new_product_name'] = $text;
                    $user_state['status'] = STATE_ADMIN_ADDING_PROD_TYPE_PROMPT;
                    setUserState($user_id, $user_state);
                    promptForProductType($chat_id, $user_id, $user_state['category_key'], $text);
                    break;
                case STATE_ADMIN_ADDING_PROD_PRICE:
                    if (!is_numeric($text) || $text < 0) {
                        sendMessage($chat_id, "Invalid price. Please enter a non-negative number.");
                        sendMessage($chat_id, "Enter the price for '{$user_state['new_product_name']}': (numbers only)");
                        break;
                    }
                    $user_state['new_product_price'] = $text;
                    $user_state['status'] = STATE_ADMIN_ADDING_PROD_INFO;
                    setUserState($user_id, $user_state);
                    sendMessage($chat_id, "Enter the product information/description for '{$user_state['new_product_name']}' (this will be shown on the confirmation page):");
                    break;
                case STATE_ADMIN_ADDING_PROD_INFO:
                    $user_state['new_product_info'] = $text;
                    setUserState($user_id, $user_state);
                    if ($user_state['new_product_type'] === 'instant') {
                        $user_state['status'] = STATE_ADMIN_ADDING_PROD_INSTANT_ITEMS;
                        $user_state['new_product_items_buffer'] = [];
                        setUserState($user_id, $user_state);
                        sendMessage($chat_id, "Product type: Instant Delivery.\nPlease send each deliverable item as a separate message (e.g., a code, a link, account details).\nType /doneitems when you have added all items for '{$user_state['new_product_name']}'.");
                    } else {
                        $user_state['status'] = STATE_ADMIN_ADDING_PROD_ID;
                        setUserState($user_id, $user_state);
                        sendMessage($chat_id, "Product type: Manual Delivery.\nEnter a unique ID for '{$user_state['new_product_name']}' (e.g., 'product_xyz' or a number):");
                    }
                    break;
                case STATE_ADMIN_ADDING_PROD_INSTANT_ITEMS:
                    if ($text === '/doneitems') {
                        $user_state['status'] = STATE_ADMIN_ADDING_PROD_ID;
                        setUserState($user_id, $user_state);
                        sendMessage($chat_id, "All items for '{$user_state['new_product_name']}' received (" . count($user_state['new_product_items_buffer']) . " items).\nNow, enter a unique ID for this product:");
                    } else {
                        $user_state['new_product_items_buffer'][] = $text;
                        setUserState($user_id, $user_state);
                        sendMessage($chat_id, "Item added: \"".htmlspecialchars($text)."\". Send the next item, or type /doneitems if finished.");
                    }
                    break;
                case STATE_ADMIN_ADDING_PROD_ID:
                    $product_id_input = trim($text);
                    if (empty($product_id_input)) {
                        sendMessage($chat_id, "Product ID cannot be empty. Please enter a unique ID:");
                        break;
                    }
                    global $products;
                    if(empty($products)) $products = readJsonFile(PRODUCTS_FILE);
                    if (isset($products[$user_state['category_key']][$product_id_input])) {
                        sendMessage($chat_id, "Product ID '{$product_id_input}' already exists in this category. Please enter a different unique ID:");
                        break;
                    }
                    $new_product_data = [ /* ... */ ]; // as before
                    $products[$user_state['category_key']][$product_id_input] = $new_product_data;
                    if (writeJsonFile(PRODUCTS_FILE, $products)) { /* success */ clearUserState($user_id); } else { /* error */ }
                    break;
            }
        }
        // Admin manually adding prod for user
        elseif (($user_state['status'] ?? null) === STATE_ADMIN_ADDING_PROD_MANUAL) { /* ... as before ... */
            $target_user_id = $user_state['target_user_id'];
            $admin_chat_id = $chat_id;
            if (strtolower($text) === '/cancel') {
                clearUserState($user_id);
                sendMessage($admin_chat_id, "Cancelled adding manual product to user `{$target_user_id}`.", null, 'Markdown');
            } else {
                $product_description = $text;
                recordPurchase($target_user_id, "🎁 " . $product_description, "Manually Added", null);
                clearUserState($user_id);
                sendMessage($admin_chat_id, "✅ Custom product '" . htmlspecialchars($product_description) . "' added to user `{$target_user_id}`.", null, 'Markdown');
                if ($target_user_id != $user_id) {
                     sendMessage($target_user_id, "🎁 Admin added: '" . htmlspecialchars($product_description) . "'. View in 'My Products'.");
                }
            }
        }
        // Admin editing category name
        elseif (($user_state['status'] ?? null) === STATE_ADMIN_EDITING_CATEGORY_NAME) { /* ... as before ... */ }
        // Admin adding category name
        elseif (($user_state['status'] ?? null) === STATE_ADMIN_ADDING_CATEGORY_NAME) { /* ... as before ... */ }
        // Admin adding coupon details
        elseif (in_array($user_state['status'], [
            STATE_ADMIN_ADDING_COUPON_CODE,
            STATE_ADMIN_ADDING_COUPON_VALUE,
            STATE_ADMIN_ADDING_COUPON_MAX_USES
        ])) {
            // ... (coupon adding logic from previous implementation, with cancel buttons) ...
            $admin_coupon_state = $user_state;
            $prompt_message_id_to_edit = $admin_coupon_state['original_message_id'] ?? null;
            // Note: /cancel text command is removed as per plan, only button cancel now
            switch ($admin_coupon_state['status']) {
                case STATE_ADMIN_ADDING_COUPON_CODE:
                    // ... (logic as implemented for adding coupon code)
                    $coupon_code_input = strtoupper(trim($text));
                    if (empty($coupon_code_input)) { /* send error */ break; }
                    if (!preg_match('/^[A-Z0-9]+$/', $coupon_code_input)) { /* send error */ break; }
                    if (getCouponByCode($coupon_code_input) !== null) { /* send error */ break; }
                    $admin_coupon_state['coupon_data']['code'] = $coupon_code_input;
                    $admin_coupon_state['status'] = STATE_ADMIN_ADDING_COUPON_TYPE;
                    setUserState($user_id, $admin_coupon_state);
                    $type_selection_keyboard_array = ['inline_keyboard' => [
                        [['text' => "Percentage (%)", 'callback_data' => CALLBACK_ADMIN_SET_COUPON_TYPE_PERCENTAGE]],
                        [['text' => "Fixed Amount (\$)", 'callback_data' => CALLBACK_ADMIN_SET_COUPON_TYPE_FIXED]],
                        [['text' => '« Cancel', 'callback_data' => CALLBACK_ADMIN_CANCEL_COUPON_CREATION]]
                    ]];
                    if ($prompt_message_id_to_edit) { editMessageText($chat_id, $prompt_message_id_to_edit, "Code '<b>".htmlspecialchars($coupon_code_input)."</b>' accepted.\nSelect coupon type:", json_encode($type_selection_keyboard_array), "HTML"); }
                    else { sendMessage($chat_id, "Code '<b>".htmlspecialchars($coupon_code_input)."</b>' accepted.\nSelect coupon type:", json_encode($type_selection_keyboard_array), "HTML"); }
                    break;
                case STATE_ADMIN_ADDING_COUPON_VALUE:
                    // ... (logic as implemented for adding coupon value)
                    if (!is_numeric($text) || floatval($text) <= 0) { /* send error */ break; }
                    $discount_value = floatval($text);
                    $current_coupon_type = $admin_coupon_state['coupon_data']['discount_type'] ?? 'unknown';
                    if ($current_coupon_type === 'percentage' && $discount_value > 100) { /* send error */ break; }
                    $admin_coupon_state['coupon_data']['discount_value'] = $discount_value;
                    $admin_coupon_state['status'] = STATE_ADMIN_ADDING_COUPON_MAX_USES;
                    setUserState($user_id, $admin_coupon_state);
                    $next_prompt_text = "Type: ".ucfirst($current_coupon_type).", Value: {$discount_value}.\n";
                    $next_prompt_text .= "Enter the maximum number of times this coupon can be used (e.g., 100. Must be 1 or greater).";
                    $cancel_keyboard_max_uses = json_encode(['inline_keyboard' => [[['text' => '« Cancel', 'callback_data' => CALLBACK_ADMIN_CANCEL_COUPON_CREATION]]]]);
                    if ($prompt_message_id_to_edit) { editMessageText($chat_id, $prompt_message_id_to_edit, $next_prompt_text, $cancel_keyboard_max_uses, "HTML"); }
                    else { sendMessage($chat_id, $next_prompt_text, $cancel_keyboard_max_uses, "HTML");}
                    break;
                case STATE_ADMIN_ADDING_COUPON_MAX_USES:
                    // ... (logic as implemented for adding coupon max uses & saving)
                    if (!is_numeric($text) || (int)$text < 1 || (string)(int)$text !== trim($text)) { /* send error */ break; }
                    $admin_coupon_state['coupon_data']['max_uses'] = (int)$text;
                    $new_coupon_data = $admin_coupon_state['coupon_data'];
                    if (addCoupon($new_coupon_data)) { /* success msg, log */ } else { /* error msg, log */ }
                    clearUserState($user_id);
                    $coupon_mgt_kb_final = json_encode([ 'inline_keyboard' => [ [['text' => "➕ Add New Coupon", /* ... */]], [['text' => '« Back to Admin Panel', /* ... */]] ]]);
                    if ($prompt_message_id_to_edit) { editMessageText($chat_id, $prompt_message_id_to_edit, "🎫 Coupon Management 🎫\nSelect an action:", $coupon_mgt_kb_final, "HTML"); }
                    else { sendMessage($chat_id, "🎫 Coupon Management 🎫\nSelect an action:", $coupon_mgt_kb_final, "HTML"); }
                    break;
            }
        }
        // Admin editing product field
        elseif (($user_state['status'] ?? null) === STATE_ADMIN_EDITING_PROD_FIELD) { /* ... as before ... */ }
        // Admin adding single instant item
        elseif (($user_state['status'] ?? null) === STATE_ADMIN_ADDING_SINGLE_INSTANT_ITEM) { /* ... as before ... */ }
        // Admin in manual send session
        elseif (($user_state['status'] ?? null) === STATE_ADMIN_MANUAL_SEND_SESSION) { /* ... as before ... */ }
        // End of specific admin states handled by text messages
    }
    // --- User is entering a coupon code ---
    elseif (!$is_admin && is_array($user_state) && ($user_state['status'] ?? null) === STATE_USER_ENTERING_COUPON) {
        $coupon_code_text = trim($text);
        $product_context = $user_state['product_context_for_coupon'] ?? null;
        $original_message_id_for_prod_display = $user_state['original_message_id'] ?? null;

        if (strtolower($coupon_code_text) === '/cancel') { // Allow text cancel for user here
            clearUserState($user_id);
            // Re-display the product they were viewing without coupon
            if ($original_message_id_for_prod_display && $product_context) {
                // Simulate clicking the product again by directly calling the product display logic or crafting its parts
                // This requires parsing product_context back to cat_key, prod_id
                $parts = explode('_', $product_context); // Assuming simple split, might need more robust parsing
                if (count($parts) >= 2) { // Basic check
                    $cat_key = $parts[0];
                    $prod_id = $parts[count($parts)-1]; // Take last part as product ID
                    if (count($parts) > 2) { // Category key had underscores
                       array_pop($parts);
                       $cat_key = implode('_', $parts);
                    }

                    global $products;
                    if(empty($products)) $products = readJsonFile(PRODUCTS_FILE);
                    $product_selected_details = $products[$cat_key][$prod_id] ?? null;

                    if ($product_selected_details) {
                        $plan_info_text = "<b>Product:</b> " . htmlspecialchars($product_selected_details['name']) . "\n";
                        $plan_info_text .= "<b>Price: $" . htmlspecialchars($product_selected_details['price']) . "</b>\n";
                        $plan_info_text .= "<b>Info:</b> " . nl2br(htmlspecialchars($product_selected_details['info'] ?? 'N/A')) . "\n\n";
                        $plan_info_text .= "Do you want to purchase this item?";
                        $keyboard_buttons_reverted = [
                            [['text' => "✅ Yes, Buy This (Price: $" . htmlspecialchars($product_selected_details['price']) . ")", 'callback_data' => CALLBACK_CONFIRM_BUY_PREFIX . $product_context]],
                            [['text' => "🎫 Apply Coupon", 'callback_data' => CALLBACK_APPLY_COUPON_PREFIX . $product_context]],
                            [['text' => "« Back to Plans", 'callback_data' => 'view_category_' . $cat_key]]
                        ];
                        editMessageText($chat_id, $original_message_id_for_prod_display, $plan_info_text, json_encode(['inline_keyboard' => $keyboard_buttons_reverted]), 'HTML');
                    } else { sendMessage($chat_id, "Coupon entry cancelled. Product details could not be reloaded.");}
                } else { sendMessage($chat_id, "Coupon entry cancelled. Error reloading product view.");}
            } else { sendMessage($chat_id, "Coupon entry cancelled."); }
            exit();
        }

        if (!$product_context || !$original_message_id_for_prod_display) {
            sendMessage($chat_id, "⚠️ Error: Could not process coupon. Product context is missing. Please try again.");
            clearUserState($user_id);
            exit();
        }

        $validation_result = validateAndApplyCoupon($user_id, $coupon_code_text, $product_context);

        if ($validation_result['success']) {
            $coupon_details = $validation_result['details'];
            // Update user state to reflect applied coupon
            $new_user_state_with_coupon = [
                // Keep other potential state elements if any, or start fresh for this context
                'status' => 'viewing_product_with_coupon', // Or some other indicator, or just store details
                'applied_coupon_for_product' => $product_context,
                'applied_coupon_details' => $coupon_details,
                'original_message_id' => $original_message_id_for_prod_display // Keep for further edits
            ];
            setUserState($user_id, $new_user_state_with_coupon);

            // Re-fetch product details to construct the message
            // (parsing from product_context - this logic needs to be robust if cat keys have '_')
            $parts_val = explode('_', $product_context);
            $category_key_val = $parts_val[0]; // Simplified parsing, needs improvement if cat_key has '_'
            $product_id_val = $parts_val[count($parts_val)-1];
            if(count($parts_val) > 2) { $category_key_val = implode('_', array_slice($parts_val, 0, -1));}


            global $products; if(empty($products)) $products = readJsonFile(PRODUCTS_FILE);
            $product_selected_val = $products[$category_key_val][$product_id_val] ?? null;

            if ($product_selected_val) {
                $text_after_coupon = "<b>Product:</b> " . htmlspecialchars($product_selected_val['name']) . "\n";
                $text_after_coupon .= "Original Price: $" . htmlspecialchars($coupon_details['original_price']) . "\n";
                $text_after_coupon .= "Coupon '<b>" . htmlspecialchars($coupon_details['code']) . "</b>' Applied: -$" . htmlspecialchars($coupon_details['discount_amount_calculated']) . "\n";
                $text_after_coupon .= "<b>Final Price: $" . htmlspecialchars($coupon_details['discounted_price']) . "</b>\n";
                $text_after_coupon .= "<b>Info:</b> " . nl2br(htmlspecialchars($product_selected_val['info'] ?? 'N/A')) . "\n\n";
                $text_after_coupon .= "Do you want to purchase this item?";

                $keyboard_after_coupon = [
                    [['text' => "✅ Yes, Buy This (Price: $" . htmlspecialchars($coupon_details['discounted_price']) . ")", 'callback_data' => CALLBACK_CONFIRM_BUY_PREFIX . $product_context]],
                    [['text' => "🚫 Remove Coupon (" . htmlspecialchars($coupon_details['code']) . ")", 'callback_data' => CALLBACK_REMOVE_COUPON_PREFIX . $product_context]],
                    [['text' => "« Back to Plans", 'callback_data' => 'view_category_' . $category_key_val]]
                ];
                editMessageText($chat_id, $original_message_id_for_prod_display, $text_after_coupon, json_encode(['inline_keyboard' => $keyboard_after_coupon]), 'HTML');
            } else {
                 sendMessage($chat_id, $validation_result['message'] . " However, there was an issue re-displaying product details.");
                 clearUserState($user_id); // Or send back to main menu
            }

        } else { // Coupon validation failed
            sendMessage($chat_id, "⚠️ " . $validation_result['message'] . "\nPlease try another code, or type /cancel to go back to the product view without a coupon.");
            // User remains in STATE_USER_ENTERING_COUPON to try again or cancel.
            // The original prompt message (original_message_id_for_prod_display) still shows "Enter coupon code".
            // We could edit it to include the error and re-prompt.
            $prompt_text_again = "⚠️ " . $validation_result['message'] . "\nPlease enter your coupon code again, or click Cancel.";
            $cancel_keyboard_again = json_encode(['inline_keyboard' => [
                 [['text' => '« Cancel Coupon Entry', 'callback_data' => $product_context ]]
            ]]);
            editMessageText($chat_id, $original_message_id_for_prod_display, $prompt_text_again, $cancel_keyboard_again, "HTML");
        }
    }
    // --- User is in a manual send session with an admin ---
    elseif (!$is_admin && is_array($user_state) && ($user_state['status'] ?? '') === 'in_manual_send_session_with_admin') { /* ... as before ... */ }
    // --- User is in a direct support chat (generic) ---
    elseif (isset($user_state['chatting_with'])) { /* ... as before ... */ }
    // --- No specific state, handle regular commands and messages ---
    else {
        // ... (STATE_AWAITING_SUPPORT_MESSAGE, /addprod, /s, /start, photo receipt logic as before) ...
        if (is_array($user_state) && ($user_state['status'] ?? null) === STATE_AWAITING_SUPPORT_MESSAGE) { /* ... */ }
        elseif ($is_admin && preg_match('/^\/addprod\s+(\d+)$/', $text, $matches)) { /* ... */ }
        elseif ($is_admin && preg_match('/^\/s(\d+)$/', $text, $matches)) { /* ... */ }
        elseif ($text === "/start") { /* ... as before ... */ }
        elseif (isset($message->photo)) { /* ... as before ... */ }
    }
}

?>
