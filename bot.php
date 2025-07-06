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

    // --- TOP PRIORITY: Handle /start command globally ---
    if ($text === "/start") {
        clearUserState($user_id); // Clear previous state
        error_log("START_CMD: /start command received (state cleared, top priority) for chat_id: {$chat_id}, user_id: {$user_id}, is_admin: " . ($is_admin ? 'Yes' : 'No'));

        $first_name = $message->from->first_name;
        $welcome_text = "Hello, " . htmlspecialchars($first_name) . "! Welcome to the shop.\n\nPlease select an option:";

        $keyboard_array = generateDynamicMainMenuKeyboard($is_admin);
        error_log("START_CMD: Keyboard array received (top priority): " . print_r($keyboard_array, true));

        $json_keyboard = json_encode($keyboard_array);
        error_log("START_CMD: JSON keyboard (top priority): " . $json_keyboard);

        sendMessage($chat_id, $welcome_text, $json_keyboard);
        exit(); // Crucial: stop further processing after /start
    }

    // Check if user is banned (do this after /start might have cleared state for a banned user, allowing them to see a fresh menu if unbanned later)
    // Or, if ban check should prevent even /start, move this before /start block. For now, /start is ultimate reset.
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
            // ... (product adding logic - ensure it's complete as per last working version) ...
            switch ($user_state['status']) {
                case STATE_ADMIN_ADDING_PROD_NAME:
                    $user_state['new_product_name'] = $text;
                    $user_state['status'] = STATE_ADMIN_ADDING_PROD_TYPE_PROMPT;
                    setUserState($user_id, $user_state);
                    promptForProductType($chat_id, $user_id, $user_state['category_key'], $text);
                    break;
                case STATE_ADMIN_ADDING_PROD_PRICE:
                    if (!is_numeric($text) || $text < 0) { /* error */ break; }
                    $user_state['new_product_price'] = $text;
                    $user_state['status'] = STATE_ADMIN_ADDING_PROD_INFO;
                    setUserState($user_id, $user_state);
                    sendMessage($chat_id, "Enter product info for '{$user_state['new_product_name']}':");
                    break;
                case STATE_ADMIN_ADDING_PROD_INFO:
                     $user_state['new_product_info'] = $text;
                    setUserState($user_id, $user_state);
                    if ($user_state['new_product_type'] === 'instant') { /* ask for items */ }
                    else { /* ask for ID */ }
                    break;
                case STATE_ADMIN_ADDING_PROD_INSTANT_ITEMS: /* ... */ break;
                case STATE_ADMIN_ADDING_PROD_ID: /* ... */ break;
            }
        }
        // Admin manually adding prod for user
        elseif (($user_state['status'] ?? null) === STATE_ADMIN_ADDING_PROD_MANUAL) { /* ... as before ... */ }
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
            // ... (coupon adding logic as before, with cancel buttons now handled by callbacks) ...
             switch ($user_state['status']) {
                case STATE_ADMIN_ADDING_COUPON_CODE:
                    $potential_code = strtoupper(trim($text));
                    if (empty($potential_code)) {
                        sendMessage($chat_id, "Coupon code cannot be empty. Please enter a valid code.", json_encode(['inline_keyboard' => [[['text' => '« Cancel', 'callback_data' => CALLBACK_ADMIN_CANCEL_COUPON_CREATION]]]]));
                        break;
                    }
                    if (getCouponByCode($potential_code) !== null) {
                        sendMessage($chat_id, "⚠️ Coupon code '<b>".htmlspecialchars($potential_code)."</b>' already exists. Please enter a unique code.", json_encode(['inline_keyboard' => [[['text' => '« Cancel', 'callback_data' => CALLBACK_ADMIN_CANCEL_COUPON_CREATION]]]]), "HTML");
                        break;
                    }

                    $user_state['coupon_data']['code'] = $potential_code;
                    $user_state['status'] = STATE_ADMIN_ADDING_COUPON_TYPE;
                    setUserState($user_id, $user_state);

                    $type_keyboard = ['inline_keyboard' => [
                        [['text' => 'Percentage (%)', 'callback_data' => CALLBACK_ADMIN_SET_COUPON_TYPE_PERCENTAGE]],
                        [['text' => 'Fixed Amount ($)', 'callback_data' => CALLBACK_ADMIN_SET_COUPON_TYPE_FIXED]],
                        [['text' => '« Cancel', 'callback_data' => CALLBACK_ADMIN_CANCEL_COUPON_CREATION]]
                    ]];
                    // Edit the original prompt message to ask for type
                    if (isset($user_state['original_message_id'])) {
                        editMessageText($chat_id, $user_state['original_message_id'], "Coupon Code: <b>".htmlspecialchars($potential_code)."</b>\nSelect the discount type:", json_encode($type_keyboard), "HTML");
                    } else { // Fallback if original_message_id wasn't set
                        sendMessage($chat_id, "Coupon Code: <b>".htmlspecialchars($potential_code)."</b>\nSelect the discount type:", json_encode($type_keyboard), "HTML");
                    }
                    break;
                case STATE_ADMIN_ADDING_COUPON_VALUE:
                    if (!is_numeric($text) || floatval($text) <= 0) {
                        sendMessage($chat_id, "Invalid value. Please enter a positive number for the discount value.", json_encode(['inline_keyboard' => [[['text' => '« Cancel', 'callback_data' => CALLBACK_ADMIN_CANCEL_COUPON_CREATION]]]]));
                        break;
                    }
                    $value = floatval($text);
                    $discount_type = $user_state['coupon_data']['discount_type'] ?? null;

                    if ($discount_type === 'percentage' && ($value <= 0 || $value > 100)) {
                        sendMessage($chat_id, "Invalid percentage. Please enter a number between 1 and 100.", json_encode(['inline_keyboard' => [[['text' => '« Cancel', 'callback_data' => CALLBACK_ADMIN_CANCEL_COUPON_CREATION]]]]));
                        break;
                    }
                    // For fixed_amount, it's already checked for > 0. No upper limit unless specified.

                    $user_state['coupon_data']['discount_value'] = $value;
                    $user_state['status'] = STATE_ADMIN_ADDING_COUPON_MAX_USES;
                    setUserState($user_id, $user_state);

                    $prompt_max_uses_text = "Discount Value: " . ($discount_type === 'percentage' ? "{$value}%" : "\${$value}") . "\n";
                    $prompt_max_uses_text .= "Enter the maximum number of uses for this coupon (e.g., 100). Enter 0 for unlimited uses.";

                    if (isset($user_state['original_message_id'])) {
                        editMessageText($chat_id, $user_state['original_message_id'], $prompt_max_uses_text, json_encode(['inline_keyboard' => [[['text' => '« Cancel', 'callback_data' => CALLBACK_ADMIN_CANCEL_COUPON_CREATION]]]]), "HTML");
                    } else {
                         sendMessage($chat_id, $prompt_max_uses_text, json_encode(['inline_keyboard' => [[['text' => '« Cancel', 'callback_data' => CALLBACK_ADMIN_CANCEL_COUPON_CREATION]]]]), "HTML");
                    }
                    break;
                case STATE_ADMIN_ADDING_COUPON_MAX_USES:
                    if (!is_numeric($text) || intval($text) < 0) {
                        sendMessage($chat_id, "Invalid input. Please enter a non-negative integer for maximum uses (e.g., 0, 10, 100).", json_encode(['inline_keyboard' => [[['text' => '« Cancel', 'callback_data' => CALLBACK_ADMIN_CANCEL_COUPON_CREATION]]]]));
                        break;
                    }
                    $max_uses = intval($text);
                    $user_state['coupon_data']['max_uses'] = $max_uses;
                    // Default values for new coupons
                    $user_state['coupon_data']['uses_count'] = 0;
                    $user_state['coupon_data']['is_active'] = true;
                    $user_state['coupon_data']['created_at'] = date('Y-m-d H:i:s');


                    if (addCoupon($user_state['coupon_data'])) {
                        $success_msg = "✅ Coupon '<b>" . htmlspecialchars($user_state['coupon_data']['code']) . "</b>' added successfully!\n";
                        $success_msg .= "Type: " . htmlspecialchars(ucfirst(str_replace('_', ' ', $user_state['coupon_data']['discount_type']))) . "\n";
                        $success_msg .= "Value: " . ($user_state['coupon_data']['discount_type'] === 'percentage' ? $user_state['coupon_data']['discount_value'] . "%" : "$" . $user_state['coupon_data']['discount_value']) . "\n";
                        $success_msg .= "Max Uses: " . ($max_uses == 0 ? "Unlimited" : $max_uses) . "\n";

                        clearUserState($user_id);
                        // Prepare Coupon Management Menu to display after adding
                        $coupon_mgt_keyboard_after_add = [
                            'inline_keyboard' => [
                                [['text' => "➕ Add New Coupon", 'callback_data' => CALLBACK_ADMIN_ADD_COUPON_PROMPT]],
                                // Future buttons for view/edit/stats
                                [['text' => '« Back to Admin Panel', 'callback_data' => CALLBACK_ADMIN_PANEL]]
                            ]
                        ];
                        if (isset($user_state['original_message_id'])) {
                             editMessageText($chat_id, $user_state['original_message_id'], $success_msg . "\n🎫 Coupon Management 🎫", json_encode($coupon_mgt_keyboard_after_add), "HTML");
                        } else {
                            sendMessage($chat_id, $success_msg . "\n🎫 Coupon Management 🎫", json_encode($coupon_mgt_keyboard_after_add), "HTML");
                        }
                    } else {
                        // This case should be rare if pre-check for code uniqueness is done.
                        // Could be a file write issue or other unexpected problem in addCoupon.
                        sendMessage($chat_id, "⚠️ An error occurred while trying to save the coupon. Please try again or check the logs.", json_encode(['inline_keyboard' => [[['text' => '« Cancel', 'callback_data' => CALLBACK_ADMIN_CANCEL_COUPON_CREATION]]]]));
                        // Optionally, clear state or keep it for retry, for now, keeping state for potential retry.
                        // clearUserState($user_id); // Or offer to go back to coupon management.
                    }
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
        // ... (logic for STATE_USER_ENTERING_COUPON as implemented for coupon phase 2) ...
    }
    // --- User is in a manual send session with an admin ---
    elseif (!$is_admin && is_array($user_state) && ($user_state['status'] ?? '') === 'in_manual_send_session_with_admin') { /* ... as before ... */ }
    // --- User is in a direct support chat (generic) ---
    elseif (isset($user_state['chatting_with'])) { /* ... as before ... */ }
    // --- No specific state, handle regular commands and messages ---
    // IMPORTANT: The /start block that was previously here is NOW MOVED TO THE TOP.
    // This 'else' block now only handles other commands or default behavior if no state matched and it wasn't /start.
    else {
        if (is_array($user_state) && ($user_state['status'] ?? null) === STATE_AWAITING_SUPPORT_MESSAGE) {
            // ... (existing support message submission logic, consider cancel button) ...
        }
        elseif ($is_admin && preg_match('/^\/addprod\s+(\d+)$/', $text, $matches)) { /* ... as before ... */ }
        elseif ($is_admin && preg_match('/^\/s(\d+)$/', $text, $matches)) { /* ... as before ... */ }
        // User sends a photo receipt (this was previously in the final else, after /start)
        elseif (isset($message->photo)) {
            $current_user_state_for_photo = getUserState($user_id);
            if (is_array($current_user_state_for_photo) && ($current_user_state_for_photo['status'] ?? null) === STATE_AWAITING_RECEIPT) {
                // ... (logic as provided by user for receipt handling) ...
                if (isset($current_user_state_for_photo['message_id'])) { editMessageReplyMarkup($chat_id, $current_user_state_for_photo['message_id'], null); }
                $product_name_receipt = $current_user_state_for_photo['product_name'] ?? 'Unknown Product';
                $price_receipt = $current_user_state_for_photo['price'] ?? 'N/A';
                $category_key_receipt = $current_user_state_for_photo['category_key'] ?? 'unknown_category';
                $product_id_receipt = $current_user_state_for_photo['product_id'] ?? 'unknown_product';
                $user_info_receipt = "🧾 New Payment Receipt ..."; // Shortened for brevity
                $photo_file_id_receipt = $message->photo[count($message->photo) - 1]->file_id;
                forwardPhotoToAdmin($photo_file_id_receipt, $user_info_receipt, $user_id, $category_key_receipt, $product_id_receipt);
                sendMessage($chat_id, "✅ Thank you! Your receipt has been submitted and is now under review.");
                clearUserState($user_id);
            } else {
                sendMessage($chat_id, "I've received your photo, but I wasn't expecting one. If you need help, please use the Support button.");
            }
        }
        // Default response or other commands can go here
        // else {
        //    sendMessage($chat_id, "Sorry, I didn't understand that command or message if no other condition met.");
        // }
    }
}

?>
