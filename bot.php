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
                case STATE_ADMIN_ADDING_COUPON_CODE: /* ... */ break;
                case STATE_ADMIN_ADDING_COUPON_VALUE: /* ... */ break;
                case STATE_ADMIN_ADDING_COUPON_MAX_USES: /* ... */ break;
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
