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

    // Debug log added to diagnose state issues
    error_log("DEBUG_MSG_HANDLER: UserID: {$user_id}, IsAdmin: " . ($is_admin ? 'Yes' : 'No') . ", Text: '{$text}', UserState: " . print_r($user_state, true));

    // Check if user is banned
    $user_specific_data = getUserData($user_id);
    if ($user_specific_data['is_banned']) {
        sendMessage($chat_id, "⚠️ You are banned from using this bot.");
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
                    // Re-prompt message should ideally match the one in functions.php or be more generic
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
                $new_product_data = [
                    'name' => $user_state['new_product_name'],
                    'price' => $user_state['new_product_price'],
                    'type' => $user_state['new_product_type'],
                    'info' => $user_state['new_product_info'],
                    'items' => ($user_state['new_product_type'] === 'instant' ? $user_state['new_product_items_buffer'] : [])
                ];
                $products[$user_state['category_key']][$product_id_input] = $new_product_data;

                if (writeJsonFile(PRODUCTS_FILE, $products)) {
                    sendMessage($chat_id, "✅ Product '{$user_state['new_product_name']}' (ID: {$product_id_input}) added successfully to category '{$user_state['category_key']}'!");
                    clearUserState($user_id);
                } else {
                    sendMessage($chat_id, "⚠️ Product '{$user_state['new_product_name']}' data was prepared, but FAILED to save to file. Please check server logs or file permissions. The product was NOT added.\nYou are still in the process of adding this product. You can try entering a product ID again or use the cancel option if available at this stage.");
                }
                break;
        }
    }
    // --- Admin is manually adding a product for a user (after /addprod <USERID>) ---
    elseif ($is_admin && is_array($user_state) && ($user_state['status'] ?? null) === STATE_ADMIN_ADDING_PROD_MANUAL) {
        $target_user_id = $user_state['target_user_id'];
        $admin_chat_id = $chat_id;
        if (strtolower($text) === '/cancel') { // Assuming /cancel is a universal command to clear state
            clearUserState($user_id);
            sendMessage($admin_chat_id, "Cancelled adding a manual product to user `{$target_user_id}`.", null, 'Markdown');
        } else {
            $product_description = $text;
            recordPurchase($target_user_id, "🎁 " . $product_description, "Manually Added", null);
            clearUserState($user_id);
            sendMessage($admin_chat_id, "✅ Custom product '" . htmlspecialchars($product_description) . "' has been added to user `{$target_user_id}`'s purchases.", null, 'Markdown');
            if ($target_user_id != $user_id) {
                 sendMessage($target_user_id, "🎁 A new item has been manually added to your purchases by an admin: '" . htmlspecialchars($product_description) . "'. You can see it in 'My Products'.");
            }
        }
    }
    // --- Admin is editing an existing category name ---
    elseif ($is_admin && is_array($user_state) && ($user_state['status'] ?? null) === STATE_ADMIN_EDITING_CATEGORY_NAME) {
        $new_category_key_input = trim($text);
        $old_category_key = $user_state['old_category_key'];
        $original_message_id_cat_edit = $user_state['original_message_id'] ?? null;
        $display_old_key_for_msg = htmlspecialchars(ucfirst(str_replace('_', ' ', $old_category_key)));

        $show_cat_mgt_menu_default = function($chat_id_func, $message_id_func_param, $msg_text_param) {
            $cat_mgt_keyboard_def_json = json_encode([
                'inline_keyboard' => [
                    [['text' => "➕ Add Category", 'callback_data' => CALLBACK_ADMIN_ADD_CATEGORY_PROMPT]],
                    [['text' => "✏️ Edit Category Name", 'callback_data' => CALLBACK_ADMIN_EDIT_CATEGORY_SELECT]],
                    [['text' => "➖ Remove Category", 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_SELECT]],
                    [['text' => '« Back to Admin Panel', 'callback_data' => CALLBACK_ADMIN_PANEL]]
                ]
            ]);
            if ($message_id_func_param) {
                 editMessageText($chat_id_func, $message_id_func_param, $msg_text_param, $cat_mgt_keyboard_def_json, "HTML");
            } else {
                sendMessage($chat_id_func, $msg_text_param, $cat_mgt_keyboard_def_json, "HTML");
            }
        };

        if ($new_category_key_input === '/cancel') {
            clearUserState($user_id);
            $show_cat_mgt_menu_default($chat_id, $original_message_id_cat_edit, "🗂️ Category Management 🗂️\nEdit category '{$display_old_key_for_msg}' cancelled. Select an action:");
        } elseif (empty($new_category_key_input)) {
            sendMessage($chat_id, "New category key cannot be empty. Please enter a valid key for '{$display_old_key_for_msg}', or type /cancel.");
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $new_category_key_input)) {
            sendMessage($chat_id, "Invalid new category key format for '{$display_old_key_for_msg}'. Use alphanumeric characters and underscores (a-z, 0-9, _).\nOr type /cancel.");
        } elseif ($new_category_key_input === $old_category_key) {
            sendMessage($chat_id, "The new category key '<b>".htmlspecialchars($new_category_key_input)."</b>' is the same as the old key. No changes made.", null, "HTML");
            clearUserState($user_id);
            if($original_message_id_cat_edit){
                 $show_cat_mgt_menu_default($chat_id, $original_message_id_cat_edit, "🗂️ Category Management 🗂️\nEdit for '{$display_old_key_for_msg}' resulted in no change. Select an action:");
            }
        } else {
            global $products;
            if (empty($products)) { $products = readJsonFile(PRODUCTS_FILE); }

            if (isset($products[$new_category_key_input])) {
                sendMessage($chat_id, "The new category key '<b>".htmlspecialchars($new_category_key_input)."</b>' already exists. Choose a unique key for '{$display_old_key_for_msg}', or type /cancel.", null, "HTML");
            } else {
                if (!isset($products[$old_category_key])) {
                     sendMessage($chat_id, "⚠️ Error: Original category '{$display_old_key_for_msg}' (key: `{$old_category_key}`) not found.");
                     clearUserState($user_id);
                     if ($original_message_id_cat_edit) {
                        $show_cat_mgt_menu_default($chat_id, $original_message_id_cat_edit, "🗂️ Category Management 🗂️\nError editing '{$display_old_key_for_msg}'. Select an action:");
                     }
                     return;
                }
                $products_to_move = $products[$old_category_key];
                $products[$new_category_key_input] = $products_to_move;
                unset($products[$old_category_key]);
                if (writeJsonFile(PRODUCTS_FILE, $products)) {
                    $display_new_key_for_msg = htmlspecialchars(ucfirst(str_replace('_', ' ', $new_category_key_input)));
                    sendMessage($chat_id, "✅ Category '{$display_old_key_for_msg}' renamed to '{$display_new_key_for_msg}'. Products moved.");
                    clearUserState($user_id);
                    if ($original_message_id_cat_edit) {
                        $show_cat_mgt_menu_default($chat_id, $original_message_id_cat_edit, "🗂️ Category Management 🗂️\nCategory '{$display_old_key_for_msg}' renamed to '{$display_new_key_for_msg}'. Select an action:");
                    }
                } else { /* ... error handling for write fail ... */ }
            }
        }
    }
    // --- Admin is adding a new category name ---
    elseif ($is_admin && is_array($user_state) && ($user_state['status'] ?? null) === STATE_ADMIN_ADDING_CATEGORY_NAME) {
        $new_category_key_input = trim($text);
        $original_message_id_add_cat = $user_state['original_message_id'] ?? null;

        if ($new_category_key_input === '/cancel') {
            clearUserState($user_id);
            $cat_mgt_keyboard_json = json_encode([ /* ... Category Mgt Keyboard ... */ ]);
            if ($original_message_id_add_cat) {
                editMessageText($chat_id, $original_message_id_add_cat, "🗂️ Category Management 🗂️\nAdd category cancelled.", $cat_mgt_keyboard_json, "HTML");
            } else { sendMessage($chat_id, "Add category cancelled."); }
        } elseif (empty($new_category_key_input)) {
            sendMessage($chat_id, "Category key cannot be empty. Please enter a valid key, or type /cancel.");
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $new_category_key_input)) {
            sendMessage($chat_id, "Invalid category key format. Use alphanumeric and underscores (a-z, 0-9, _).\nOr type /cancel.");
        } else {
            global $products;
            if (empty($products)) { $products = readJsonFile(PRODUCTS_FILE); }
            if (isset($products[$new_category_key_input])) {
                sendMessage($chat_id, "Category key '<b>".htmlspecialchars($new_category_key_input)."</b>' already exists. Enter unique key, or type /cancel.", null, "HTML");
            } else {
                $products[$new_category_key_input] = [];
                if (writeJsonFile(PRODUCTS_FILE, $products)) {
                    sendMessage($chat_id, "✅ Category '<b>".htmlspecialchars($new_category_key_input)."</b>' added successfully!");
                    clearUserState($user_id);
                    if ($original_message_id_add_cat) {
                         $cat_mgt_keyboard_success_json = json_encode([ /* ... Category Mgt Keyboard ... */ ]);
                         editMessageText($chat_id, $original_message_id_add_cat, "🗂️ Category Management 🗂️\nCategory '<b>".htmlspecialchars($new_category_key_input)."</b>' added. Select an action:", $cat_mgt_keyboard_success_json, "HTML");
                    }
                } else { /* ... error handling ... */ }
            }
        }
    }
    // --- Admin is adding coupon details ---
    elseif ($is_admin && is_array($user_state) &&
        in_array($user_state['status'], [
            STATE_ADMIN_ADDING_COUPON_CODE,
            STATE_ADMIN_ADDING_COUPON_VALUE,
            STATE_ADMIN_ADDING_COUPON_MAX_USES
        ])
    ) {
        $admin_coupon_state = $user_state;
        $prompt_message_id_to_edit = $admin_coupon_state['original_message_id'] ?? null;

        // Note: /cancel is now handled by a button callback, so text /cancel logic is removed from here.

        switch ($admin_coupon_state['status']) {
            case STATE_ADMIN_ADDING_COUPON_CODE:
                $coupon_code_input = strtoupper(trim($text));
                if (empty($coupon_code_input)) {
                    sendMessage($chat_id, "Coupon code cannot be empty. Please try again or use the Cancel button.");
                    break;
                }
                if (!preg_match('/^[A-Z0-9]+$/', $coupon_code_input)) {
                    sendMessage($chat_id, "Invalid coupon code format. Use uppercase letters/numbers (A-Z, 0-9), no spaces/special characters.\nOr use Cancel button.");
                    break;
                }
                if (getCouponByCode($coupon_code_input) !== null) {
                    sendMessage($chat_id, "Coupon code '<b>".htmlspecialchars($coupon_code_input)."</b>' already exists. Enter unique code or use Cancel button.", null, "HTML");
                    break;
                }

                $admin_coupon_state['coupon_data']['code'] = $coupon_code_input;
                $admin_coupon_state['status'] = STATE_ADMIN_ADDING_COUPON_TYPE;
                setUserState($user_id, $admin_coupon_state);

                $type_selection_keyboard_array = ['inline_keyboard' => [
                    [['text' => "Percentage (%)", 'callback_data' => CALLBACK_ADMIN_SET_COUPON_TYPE_PERCENTAGE]],
                    [['text' => "Fixed Amount (\$)", 'callback_data' => CALLBACK_ADMIN_SET_COUPON_TYPE_FIXED]],
                    [['text' => '« Cancel', 'callback_data' => CALLBACK_ADMIN_CANCEL_COUPON_CREATION]]
                ]];
                $type_selection_keyboard = json_encode($type_selection_keyboard_array);

                if ($prompt_message_id_to_edit) {
                    editMessageText($chat_id, $prompt_message_id_to_edit, "Code '<b>".htmlspecialchars($coupon_code_input)."</b>' accepted.\nSelect coupon type:", $type_selection_keyboard, "HTML");
                } else {
                     sendMessage($chat_id, "Code '<b>".htmlspecialchars($coupon_code_input)."</b>' accepted.\nSelect coupon type:", $type_selection_keyboard, "HTML");
                }
                break;

            case STATE_ADMIN_ADDING_COUPON_VALUE:
                if (!is_numeric($text) || floatval($text) <= 0) {
                    sendMessage($chat_id, "Invalid discount value. Enter a positive number or use Cancel button.");
                    break;
                }
                $discount_value = floatval($text);
                $current_coupon_type = $admin_coupon_state['coupon_data']['discount_type'] ?? 'unknown';

                if ($current_coupon_type === 'percentage' && $discount_value > 100) {
                     sendMessage($chat_id, "Percentage discount cannot exceed 100. Enter valid percentage or use Cancel button.");
                     break;
                }

                $admin_coupon_state['coupon_data']['discount_value'] = $discount_value;
                $admin_coupon_state['status'] = STATE_ADMIN_ADDING_COUPON_MAX_USES;
                setUserState($user_id, $admin_coupon_state);

                $next_prompt_text = "Type: ".ucfirst($current_coupon_type).", Value: {$discount_value}.\n";
                $next_prompt_text .= "Enter the maximum number of times this coupon can be used (e.g., 100. Must be 1 or greater).";

                $cancel_keyboard_for_max_uses = json_encode(['inline_keyboard' => [
                    [['text' => '« Cancel', 'callback_data' => CALLBACK_ADMIN_CANCEL_COUPON_CREATION]]
                ]]);

                if ($prompt_message_id_to_edit) {
                    editMessageText($chat_id, $prompt_message_id_to_edit, $next_prompt_text, $cancel_keyboard_for_max_uses, "HTML");
                } else {
                    sendMessage($chat_id, $next_prompt_text, $cancel_keyboard_for_max_uses, "HTML");
                }
                break;

            case STATE_ADMIN_ADDING_COUPON_MAX_USES:
                if (!is_numeric($text) || (int)$text < 1 || (string)(int)$text !== trim($text)) {
                    sendMessage($chat_id, "Invalid maximum uses. Enter a whole number (1 or greater), or use Cancel button.");
                    break;
                }
                $admin_coupon_state['coupon_data']['max_uses'] = (int)$text;

                $new_coupon_data = $admin_coupon_state['coupon_data'];

                if (addCoupon($new_coupon_data)) {
                    sendMessage($chat_id, "✅ Coupon '<b>".htmlspecialchars($new_coupon_data['code'])."</b>' added successfully!");
                    error_log("COUPON_ADD: Coupon {$new_coupon_data['code']} added by admin {$user_id}. Data: ".print_r($new_coupon_data,true));
                } else {
                    sendMessage($chat_id, "⚠️ Error adding coupon '<b>".htmlspecialchars($new_coupon_data['code'])."</b>'. It might already exist or data was invalid. Please try again or check logs.", null, "HTML");
                     error_log("COUPON_ADD_FAIL: Failed to add coupon by admin {$user_id}. Attempted Data: ".print_r($new_coupon_data,true));
                }
                clearUserState($user_id);

                $coupon_mgt_keyboard_final = json_encode([
                    'inline_keyboard' => [
                        [['text' => "➕ Add New Coupon", 'callback_data' => CALLBACK_ADMIN_ADD_COUPON_PROMPT]],
                        [['text' => '« Back to Admin Panel', 'callback_data' => CALLBACK_ADMIN_PANEL]]
                    ]
                ]);
                if ($prompt_message_id_to_edit) {
                    editMessageText($chat_id, $prompt_message_id_to_edit, "🎫 Coupon Management 🎫\nSelect an action:", $coupon_mgt_keyboard_final, "HTML");
                } else {
                     sendMessage($chat_id, "🎫 Coupon Management 🎫\nSelect an action:", $coupon_mgt_keyboard_final, "HTML");
                }
                break;
        }
    }
    // --- Admin is editing a product field ---
    elseif ($is_admin && is_array($user_state) && ($user_state['status'] ?? null) === STATE_ADMIN_EDITING_PROD_FIELD) {
        // ... (existing product field editing logic, ensure /cancel or back buttons are handled)
        $field_to_edit = $user_state['field_to_edit'];
        $category_key = $user_state['category_key'];
        $product_id = $user_state['product_id'];
        $original_message_id_prod_edit = $user_state['original_message_id'] ?? null;

        if ($text === '/cancel') { // This /cancel might need to be a button too for consistency
            clearUserState($user_id);
            // ... (code to show product edit options again) ...
            return;
        }
        // ... (rest of product field editing logic) ...
    }
    // --- Admin is adding a single instant item to an existing product ---
    elseif ($is_admin && is_array($user_state) && ($user_state['status'] ?? null) === STATE_ADMIN_ADDING_SINGLE_INSTANT_ITEM) {
        // ... (existing instant item adding logic, ensure /cancel or back buttons are handled) ...
        if ($text === '/cancel') { // This /cancel might need to be a button
            // ... (clear state and show manage items screen) ...
            return;
        }
        // ... (rest of logic) ...
    }
    // --- Admin is in a manual send session with a user ---
    elseif ($is_admin && is_array($user_state) && ($user_state['status'] ?? null) === STATE_ADMIN_MANUAL_SEND_SESSION) {
        // ... (existing manual send session logic) ...
    }
    // --- User is in a manual send session with an admin ---
    elseif (!$is_admin && is_array($user_state) && ($user_state['status'] ?? '') === 'in_manual_send_session_with_admin') {
        // ... (existing logic for user in manual send session) ...
    }
    // --- User is in a direct support chat (generic) ---
    elseif (isset($user_state['chatting_with'])) {
        // ... (existing generic support chat logic) ...
    }
    // --- No specific state, handle regular commands and messages ---
    else {
        if (is_array($user_state) && ($user_state['status'] ?? null) === STATE_AWAITING_SUPPORT_MESSAGE) {
            // ... (existing support message submission logic, consider adding a cancel button to initial prompt) ...
             if (strtolower($text) === '/cancel') { // This /cancel should ideally be a button
                    // ... clear state, edit original prompt ...
                    return;
                }
            // ...
        }
        // Admin command: /addprod <USERID> (This is a text command based flow)
        elseif ($is_admin && preg_match('/^\/addprod\s+(\d+)$/', $text, $matches)) {
            // ... (existing /addprod logic) ...
        }
        // Admin wants to start a chat (This is a text command based flow)
        elseif ($is_admin && preg_match('/^\/s(\d+)$/', $text, $matches)) {
            // ... (existing /s logic) ...
        }
        // User sends /start
        elseif ($text === "/start") {
            error_log("START_CMD: /start command received for chat_id: {$chat_id}, user_id: {$user_id}, is_admin: " . ($is_admin ? 'Yes' : 'No'));
            $first_name = $message->from->first_name;
            $welcome_text = "Hello, " . htmlspecialchars($first_name) . "! Welcome to the shop.\n\nPlease select an option:";
            $keyboard_array = generateDynamicMainMenuKeyboard($is_admin);
            error_log("START_CMD: Keyboard array received: " . print_r($keyboard_array, true));
            $json_keyboard = json_encode($keyboard_array);
            error_log("START_CMD: JSON keyboard: " . $json_keyboard);
            sendMessage($chat_id, $welcome_text, $json_keyboard);
        }
        // User sends a photo receipt
        elseif (isset($message->photo)) {
            $current_user_state_for_photo = getUserState($user_id);
            if (is_array($current_user_state_for_photo) && ($current_user_state_for_photo['status'] ?? null) === STATE_AWAITING_RECEIPT) {
                if (isset($current_user_state_for_photo['message_id'])) { editMessageReplyMarkup($chat_id, $current_user_state_for_photo['message_id'], null); }

                $product_name_receipt = $current_user_state_for_photo['product_name'] ?? 'Unknown Product';
                $price_receipt = $current_user_state_for_photo['price'] ?? 'N/A';
                $category_key_receipt = $current_user_state_for_photo['category_key'] ?? 'unknown_category';
                $product_id_receipt = $current_user_state_for_photo['product_id'] ?? 'unknown_product';

                $user_info_receipt = "🧾 New Payment Receipt\n\n▪️ **Product:** $product_name_receipt\n▪️ **Price:** $$price_receipt\n\n👤 **From User:**\nName: " . htmlspecialchars(($message->from->first_name ?? '') . " " . ($message->from->last_name ?? '')) . "\nUsername: @" . ($message->from->username ?? 'N/A') . "\nID: `$user_id`";
                $photo_file_id_receipt = $message->photo[count($message->photo) - 1]->file_id;

                forwardPhotoToAdmin($photo_file_id_receipt, $user_info_receipt, $user_id, $category_key_receipt, $product_id_receipt);

                sendMessage($chat_id, "✅ Thank you! Your receipt has been submitted and is now under review.");
                clearUserState($user_id);
            } else {
                sendMessage($chat_id, "I've received your photo, but I wasn't expecting one. If you need help, please use the Support button.");
            }
        }
    }
}

?>
