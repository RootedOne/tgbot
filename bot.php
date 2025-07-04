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
                    sendMessage($chat_id, "Enter the price for '{$user_state['new_product_name']}': (numbers only)");
                    break;
                }
                $user_state['new_product_price'] = $text;
                // The prompt for info is sent, but the state user gets into is STATE_ADMIN_ADDING_PROD_INFO
                // This was STATE_ADMIN_ADDING_PROD_INFO_PROMPT in planning, but code uses STATE_ADMIN_ADDING_PROD_INFO
                $user_state['status'] = STATE_ADMIN_ADDING_PROD_INFO;
                setUserState($user_id, $user_state);
                sendMessage($chat_id, "Enter the product information/description for '{$user_state['new_product_name']}' (this will be shown on the confirmation page):");
                break;

            case STATE_ADMIN_ADDING_PROD_INFO:
                $user_state['new_product_info'] = $text;
                setUserState($user_id, $user_state);
                if ($user_state['new_product_type'] === 'instant') { // Assuming 'instant' is a string literal, not a constant here
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
                    sendMessage($chat_id, "Item added: \"$text\". Send the next item, or type /doneitems if finished.");
                }
                break;

            case STATE_ADMIN_ADDING_PROD_ID:
                $product_id_input = trim($text);
                if (empty($product_id_input)) {
                    sendMessage($chat_id, "Product ID cannot be empty. Please enter a unique ID:");
                    break;
                }
                global $products;
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
                writeJsonFile(PRODUCTS_FILE, $products);
                sendMessage($chat_id, "✅ Product '{$user_state['new_product_name']}' (ID: {$product_id_input}) added successfully to category '{$user_state['category_key']}'!");
                clearUserState($user_id);
                break;
        }
    }
    // --- Admin is manually adding a product for a user (after /addprod <USERID>) ---
    elseif ($is_admin && is_array($user_state) && $user_state['status'] === STATE_ADMIN_ADDING_PROD_MANUAL) {
        $target_user_id = $user_state['target_user_id'];
        $admin_chat_id = $chat_id;
        if (strtolower($text) === '/cancel') {
            clearUserState($user_id);
            sendMessage($admin_chat_id, "Cancelled adding a manual product to user `{$target_user_id}`.", null, 'Markdown');
        } else {
            $product_description = $text;
            recordPurchase($target_user_id, "🎁 " . $product_description, "Manually Added");
            clearUserState($user_id);
            sendMessage($admin_chat_id, "✅ Custom product '" . htmlspecialchars($product_description) . "' has been added to user `{$target_user_id}`'s purchases.", null, 'Markdown');
            if ($target_user_id != $user_id) {
                 sendMessage($target_user_id, "🎁 A new item has been manually added to your purchases by an admin: '" . htmlspecialchars($product_description) . "'. You can see it in 'My Products'.");
            }
        }
    }
    // --- Admin is editing a product field ---
    elseif ($is_admin && is_array($user_state) && $user_state['status'] === STATE_ADMIN_EDITING_PROD_FIELD) {
        $field_to_edit = $user_state['field_to_edit'];
        $category_key = $user_state['category_key'];
        $product_id = $user_state['product_id'];
        $original_message_id = $user_state['original_message_id'] ?? null;

        if ($text === '/cancel') {
            clearUserState($user_id);
            $product_details_current = getProductDetails($category_key, $product_id);
            if (!$product_details_current) {
                 sendMessage($chat_id, "Edit cancelled. Product not found. Returning to product management.");
                 if($original_message_id) editMessageText($chat_id, $original_message_id, "Edit cancelled. Product not found.", null);
                 exit();
            }
            $edit_options_kb_rows_tmp = [
                [['text' => "✏️ Edit Name", 'callback_data' => CALLBACK_ADMIN_EDIT_NAME_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => "💲 Edit Price", 'callback_data' => CALLBACK_ADMIN_EDIT_PRICE_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => "ℹ️ Edit Info/Description", 'callback_data' => CALLBACK_ADMIN_EDIT_INFO_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => "🔄 Edit Type (current: {$product_details_current['type']})", 'callback_data' => CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX . "{$category_key}_{$product_id}"]],
            ];
            if ($product_details_current['type'] === 'instant') {
                $item_count = count($product_details_current['items'] ?? []);
                $edit_options_kb_rows_tmp[] = [['text' => "🗂️ Manage Instant Items ({$item_count})", 'callback_data' => CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX . "{$category_key}_{$product_id}"]];
            }
            $edit_options_kb_rows_tmp[] = [['text' => '« Back to Product List', 'callback_data' => CALLBACK_ADMIN_EP_SCAT_PREFIX . $category_key]];
            $edit_options_kb_rows_tmp[] = [['text' => '« Back to Product Mgt', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]];
            $text_msg = "Edit cancelled.\nEditing Product: <b>" . htmlspecialchars($product_details_current['name']) . "</b>\nID: {$product_id}\nSelect what you want to edit:";
            if(isset($original_message_id)){
                editMessageText($chat_id, $original_message_id, $text_msg, json_encode(['inline_keyboard' => $edit_options_kb_rows_tmp]), 'HTML');
            } else {
                sendMessage($chat_id, $text_msg, json_encode(['inline_keyboard' => $edit_options_kb_rows_tmp]), 'HTML');
            }
            exit();
        }

        $new_value = trim($text);
        $validation_error = null;
        if ($field_to_edit === 'price') {
            if (!is_numeric($new_value) || $new_value < 0) {
                $validation_error = "Invalid price. Please enter a non-negative number, or /cancel.";
            } else { $new_value = (string)$new_value; }
        } elseif ($field_to_edit === 'name') {
            if (empty($new_value)) { $validation_error = "Product name cannot be empty. Please enter a valid name, or /cancel."; }
        }

        if ($validation_error) {
            sendMessage($chat_id, $validation_error);
        } else {
            global $products;
            if (empty($products)) { $products = readJsonFile(PRODUCTS_FILE); }
            if (isset($products[$category_key][$product_id])) {
                $old_value = $products[$category_key][$product_id][$field_to_edit] ?? ($field_to_edit === 'info' ? 'Not set' : '');
                $products[$category_key][$product_id][$field_to_edit] = $new_value;
                if (writeJsonFile(PRODUCTS_FILE, $products)) {
                    sendMessage($chat_id, "✅ Product " . htmlspecialchars($field_to_edit) . " updated successfully from \"" . htmlspecialchars($old_value) . "\" to \"" . htmlspecialchars($new_value) . "\".");
                    clearUserState($user_id);
                    $product_details_updated = getProductDetails($category_key, $product_id);
                    $edit_options_kb_rows_ref = [
                        [['text' => "✏️ Edit Name", 'callback_data' => CALLBACK_ADMIN_EDIT_NAME_PREFIX . "{$category_key}_{$product_id}"]],
                        [['text' => "💲 Edit Price", 'callback_data' => CALLBACK_ADMIN_EDIT_PRICE_PREFIX . "{$category_key}_{$product_id}"]],
                        [['text' => "ℹ️ Edit Info/Description", 'callback_data' => CALLBACK_ADMIN_EDIT_INFO_PREFIX . "{$category_key}_{$product_id}"]],
                        [['text' => "🔄 Edit Type (current: {$product_details_updated['type']})", 'callback_data' => CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX . "{$category_key}_{$product_id}"]],
                    ];
                    if ($product_details_updated['type'] === 'instant') {
                        $item_count = count($product_details_updated['items'] ?? []);
                        $edit_options_kb_rows_ref[] = [['text' => "🗂️ Manage Instant Items ({$item_count})", 'callback_data' => CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX . "{$category_key}_{$product_id}"]];
                    }
                    $edit_options_kb_rows_ref[] = [['text' => '« Back to Product List', 'callback_data' => CALLBACK_ADMIN_EP_SCAT_PREFIX . $category_key]];
                    $edit_options_kb_rows_ref[] = [['text' => '« Back to Product Mgt', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]];
                    $text_msg_upd = "Editing Product: <b>" . htmlspecialchars($product_details_updated['name']) . "</b>\nID: {$product_id}\nSelect what you want to edit:";
                    if(isset($original_message_id)){
                         editMessageText($chat_id, $original_message_id, $text_msg_upd, json_encode(['inline_keyboard' => $edit_options_kb_rows_ref]), 'HTML');
                    } else {
                        sendMessage($chat_id, $text_msg_upd, json_encode(['inline_keyboard' => $edit_options_kb_rows_ref]), 'HTML');
                    }
                } else {
                    sendMessage($chat_id, "⚠️ Error saving product changes for '" . htmlspecialchars($field_to_edit) . "'. Please try again.");
                }
            } else {
                sendMessage($chat_id, "⚠️ Error: Product not found during update. Please go back and try again.");
                clearUserState($user_id);
                 if(isset($original_message_id)) {
                    editMessageText($chat_id, $original_message_id, "Product not found. Edit cancelled.", null);
                 }
            }
        }
    }
    // --- Admin is adding a single instant item to an existing product ---
    elseif ($is_admin && is_array($user_state) && $user_state['status'] === STATE_ADMIN_ADDING_SINGLE_INSTANT_ITEM) {
        $category_key = $user_state['category_key'];
        $product_id = $user_state['product_id'];
        $original_message_id = $user_state['original_message_id'] ?? null;
        if ($text === '/cancel') {
            clearUserState($user_id);
            $product_details_current = getProductDetails($category_key, $product_id);
             if (!$product_details_current) {
                 sendMessage($chat_id, "Add item cancelled. Product not found. Returning to product management.");
                 if($original_message_id) editMessageText($chat_id, $original_message_id, "Add item cancelled. Product not found.", null);
                 exit();
            }
            $items_text = "<b>Manage Instant Items: " . htmlspecialchars($product_details_current['name']) . "</b> (Cancelled)\n";
            $current_items = $product_details_current['items'] ?? [];
            $items_text .= "Currently stocked: " . count($current_items) . " item(s).\n";
            $kb_rows = [
                [['text' => '➕ Add New Item', 'callback_data' => CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX . "{$category_key}_{$product_id}"]],
            ];
            if (!empty($current_items)) {
                 $kb_rows[] = [['text' => '➖ Remove An Item', 'callback_data' => CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX . "{$category_key}_{$product_id}"]];
            }
            $kb_rows[] = [['text' => '« Back to Edit Options', 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . "{$category_key}_{$product_id}"]];
            if(isset($original_message_id)){
                editMessageText($chat_id, $original_message_id, $items_text, json_encode(['inline_keyboard' => $kb_rows]), 'HTML');
            } else {
                sendMessage($chat_id, $items_text, json_encode(['inline_keyboard' => $kb_rows]), 'HTML');
            }
            exit();
        }

        $new_item_content = $text;
        if (empty(trim($new_item_content))) {
            sendMessage($chat_id, "Item content cannot be empty. Please send the content or /cancel.");
        } else {
            if (addInstantProductItem($category_key, $product_id, $new_item_content)) {
                sendMessage($chat_id, "✅ New instant item added successfully to '" . htmlspecialchars($product_id) . "'.");
                clearUserState($user_id);
                $product_details_updated = getProductDetails($category_key, $product_id);
                $items_text_upd = "<b>Manage Instant Items: " . htmlspecialchars($product_details_updated['name']) . "</b>\n";
                $current_items_upd = $product_details_updated['items'] ?? [];
                $items_text_upd .= "Currently stocked: " . count($current_items_upd) . " item(s).\n";
                $kb_rows_upd = [
                    [['text' => '➕ Add New Item', 'callback_data' => CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX . "{$category_key}_{$product_id}"]],
                ];
                if (!empty($current_items_upd)) {
                     $kb_rows_upd[] = [['text' => '➖ Remove An Item', 'callback_data' => CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX . "{$category_key}_{$product_id}"]];
                }
                $kb_rows_upd[] = [['text' => '« Back to Edit Options', 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . "{$category_key}_{$product_id}"]];
                if(isset($original_message_id)){
                    editMessageText($chat_id, $original_message_id, $items_text_upd, json_encode(['inline_keyboard' => $kb_rows_upd]), 'HTML');
                } else {
                     sendMessage($chat_id, $items_text_upd, json_encode(['inline_keyboard' => $kb_rows_upd]), 'HTML');
                }
            } else {
                sendMessage($chat_id, "⚠️ Error adding instant item. Product might not be 'instant' type, not found, or an issue occurred saving. Or type /cancel.");
            }
        }
    }
    // --- User is in a direct support chat ---
    elseif (isset($user_state['chatting_with'])) {
        // ... (support chat logic - remains unchanged for now)
        if ($is_admin && preg_match('/^\/e(\d+)$/', $text, $matches)) {
            $customer_id_to_end = $matches[1];
            $current_chat_partner = $user_state['chatting_with'];
            if ($customer_id_to_end == $current_chat_partner) {
                clearUserState($user_id);
                clearUserState($current_chat_partner);
                sendMessage($user_id, "☑️ Chat ended with user $current_chat_partner.");
                sendMessage($current_chat_partner, "☑️ The support chat has been ended by the admin.");
            }
        }
        elseif ($is_admin) {
            bot('copyMessage', ['from_chat_id' => $chat_id, 'chat_id' => $user_state['chatting_with'], 'message_id' => $message->message_id]);
        }
        else {
            sendMessage($chat_id, "↳ Your message has been sent to the admin.");
            bot('copyMessage', ['from_chat_id' => $chat_id, 'chat_id' => $user_state['chatting_with'], 'message_id' => $message->message_id]);
        }
    }
    // --- No special state, handle regular commands and messages ---
    else {
        if (is_array($user_state) && ($user_state['status'] ?? null) === STATE_AWAITING_SUPPORT_MESSAGE) {
            if(isset($user_state['message_id'])){
                editMessageReplyMarkup($chat_id, $user_state['message_id'], null);
            }
            $user_info = "New support message from:\n";
            $user_info .= "User: " . htmlspecialchars(($message->from->first_name ?? '') . " " . ($message->from->last_name ?? '')) . "\n";
            $user_info .= "Username: @" . ($message->from->username ?? 'N/A') . "\n";
            $user_info .= "User ID: `$user_id`\n\n";
            $user_info .= "Message:\n" . htmlspecialchars($text);
            $admin_ids = getAdminIds();
            if(!empty($admin_ids)){
                $admin_id_to_send_to = $admin_ids[0];
                sendMessage($admin_id_to_send_to, $user_info, null, 'Markdown');
            } else {
                error_log("No admins configured to receive support message from user $user_id");
            }
            sendMessage($chat_id, "✅ Thank you! Your message has been sent to the support team.");
            clearUserState($user_id);
        }
        // Admin command: /addprod <USERID>
        elseif ($is_admin && preg_match('/^\/addprod\s+(\d+)$/', $text, $matches)) {
            $user_id_to_add_to = $matches[1];
            if (is_numeric($user_id_to_add_to)) {
                setUserState($user_id, ['status' => STATE_ADMIN_ADDING_PROD_MANUAL, 'target_user_id' => $user_id_to_add_to]);
                sendMessage($chat_id, "Please send the product description/name for user `{$user_id_to_add_to}`. This text will appear as the item in their 'My Products' list.\nOr type /cancel to abort.", null, 'Markdown');
            } else {
                sendMessage($chat_id, "Invalid User ID provided. Usage: `/addprod <USERID>`");
            }
        }
        // Admin wants to start a chat
        elseif ($is_admin && preg_match('/^\/s(\d+)$/', $text, $matches)) {
            // ... (start chat logic - remains unchanged)
            $customer_id = $matches[1];
            setUserState($user_id, ['chatting_with' => $customer_id]);
            setUserState($customer_id, ['chatting_with' => $user_id]);
            sendMessage($user_id, "✅ You are now connected with user `$customer_id`. Send `/e$customer_id` to end the chat.", null, 'Markdown');
            sendMessage($customer_id, "✅ An admin has connected with you. You can reply here directly.");
        }
        // User sends /start
        elseif ($text === "/start") {
            $first_name = $message->from->first_name;
            $welcome_text = "Hello, " . htmlspecialchars($first_name) . "! Welcome to the shop.\n\nPlease select an option:";
            $keyboard = $is_admin ? $adminMenuKeyboard : $mainMenuKeyboard; // These keyboards now use constants
            sendMessage($chat_id, $welcome_text, $keyboard);
        }
        // User sends a photo receipt
        elseif (isset($message->photo)) {
            $state = getUserState($user_id);
            if (is_array($state) && ($state['status'] ?? null) === STATE_AWAITING_RECEIPT) {
                if (isset($state['message_id'])) { editMessageReplyMarkup($chat_id, $state['message_id'], null); }
                $product_name = $state['product_name'] ?? 'Unknown Product';
                $price = $state['price'] ?? 'N/A';
                $user_info = "🧾 New Payment Receipt\n\n▪️ **Product:** $product_name\n▪️ **Price:** $$price\n\n👤 **From User:**\nName: " . htmlspecialchars(($message->from->first_name ?? '') . " " . ($message->from->last_name ?? '')) . "\nUsername: @" . ($message->from->username ?? 'N/A') . "\nID: `$user_id`";
                $photo_file_id = $message->photo[count($message->photo) - 1]->file_id;
                forwardPhotoToAdmin($photo_file_id, $user_info, $user_id); // Assuming forwardPhotoToAdmin uses constants for its kbd if any
                sendMessage($chat_id, "✅ Thank you! Your receipt has been submitted and is now under review.");
                 clearUserState($user_id); // Clear state after submission
            } else {
                sendMessage($chat_id, "I've received your photo, but I wasn't expecting one. If you need help, please use the Support button.");
            }
        }
    }
}

?>
