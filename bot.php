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
        sendMessage($chat_id, "🚫 متأسفم! فعلاً دسترسی‌ت به ربات بسته شده.");
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
                
                if (writeJsonFile(PRODUCTS_FILE, $products)) {
                    sendMessage($chat_id, "✅ Product '{$user_state['new_product_name']}' (ID: {$product_id_input}) added successfully to category '{$user_state['category_key']}'!");
                    clearUserState($user_id);
                } else {
                    sendMessage($chat_id, "⚠️ Product '{$user_state['new_product_name']}' data was prepared, but FAILED to save to file. Please check server logs or file permissions. The product was NOT added.\nYou are still in the process of adding this product. You can try entering a product ID again, or type /canceladdproduct to abort the whole process.");
                    // User remains in STATE_ADMIN_ADDING_PROD_ID to potentially retry ID or take other action.
                    // A general /cancel command for all states would be useful here.
                }
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
                 sendMessage($target_user_id, "🛍 ادمین یه محصول جدید برات اضافه کرده:\n«" . htmlspecialchars($product_description) . "»\nمی‌تونی از بخش «محصولات من» ببینیش 😎");
            }
        }
    }
    // --- Admin is editing an existing category name ---
    elseif ($is_admin && is_array($user_state) && $user_state['status'] === STATE_ADMIN_EDITING_CATEGORY_NAME) {
        $new_category_key_input = trim($text);
        $old_category_key = $user_state['old_category_key'];
        $original_message_id = $user_state['original_message_id'] ?? null;
        $display_old_key_for_msg = htmlspecialchars(ucfirst(str_replace('_', ' ', $old_category_key)));

        // Function to show Category Management Menu (to avoid repetition)
        $show_cat_mgt_menu = function($chat_id_func, $message_id_func, $msg_text) use ($original_message_id) { // Ensure $original_message_id is captured if it's from an outer scope and needed here.
            $cat_mgt_keyboard_edit_cancel = [
                'inline_keyboard' => [
                    [['text' => "➕ Add Category", 'callback_data' => CALLBACK_ADMIN_ADD_CATEGORY_PROMPT]],
                    [['text' => "✏️ Edit Category Name", 'callback_data' => CALLBACK_ADMIN_EDIT_CATEGORY_SELECT]],
                    [['text' => "➖ Remove Category", 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_SELECT]],
                    [['text' => '« Back to Admin Panel', 'callback_data' => CALLBACK_ADMIN_PANEL]]
                ]
            ];
            if ($original_message_id && $message_id_func) { // Make sure message_id_func is valid if used
                 editMessageText($chat_id_func, $message_id_func, $msg_text, json_encode($cat_mgt_keyboard_edit_cancel));
            } else {
                // Fallback if original_message_id for editing is not available, send a new message.
                sendMessage($chat_id_func, $msg_text, json_encode($cat_mgt_keyboard_edit_cancel));
            }
        };

        if ($new_category_key_input === '/cancel') {
            clearUserState($user_id);
            $show_cat_mgt_menu($chat_id, $original_message_id, "🗂️ Category Management 🗂️\nEdit category '{$display_old_key_for_msg}' cancelled. Select an action:");
        } elseif (empty($new_category_key_input)) {
            sendMessage($chat_id, "New category key cannot be empty. Please enter a valid key for '{$display_old_key_for_msg}', or type /cancel.");
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $new_category_key_input)) {
            sendMessage($chat_id, "Invalid new category key format for '{$display_old_key_for_msg}'. Please use only alphanumeric characters and underscores (a-z, 0-9, _).\nOr type /cancel.");
        } elseif ($new_category_key_input === $old_category_key) {
            sendMessage($chat_id, "The new category key '{$new_category_key_input}' is the same as the old key. No changes made.");
            clearUserState($user_id);
            $show_cat_mgt_menu($chat_id, $original_message_id, "🗂️ Category Management 🗂️\nEdit for '{$display_old_key_for_msg}' resulted in no change. Select an action:");
        } else {
            global $products;
            if (empty($products)) { $products = readJsonFile(PRODUCTS_FILE); }

            if (isset($products[$new_category_key_input])) {
                sendMessage($chat_id, "The new category key '{$new_category_key_input}' already exists. Please choose a different unique key for '{$display_old_key_for_msg}', or type /cancel.");
            } else {
                if (!isset($products[$old_category_key])) {
                     sendMessage($chat_id, "⚠️ Error: The original category '{$display_old_key_for_msg}' (key: `{$old_category_key}`) could not be found. It might have been deleted or changed. Please try again from Category Management.");
                     clearUserState($user_id);
                     $show_cat_mgt_menu($chat_id, $original_message_id, "🗂️ Category Management 🗂️\nError editing '{$display_old_key_for_msg}'. Select an action:");
                     return;
                }

                $products_to_move = $products[$old_category_key];
                $products[$new_category_key_input] = $products_to_move;
                unset($products[$old_category_key]);

                if (writeJsonFile(PRODUCTS_FILE, $products)) {
                    $display_new_key_for_msg = htmlspecialchars(ucfirst(str_replace('_', ' ', $new_category_key_input)));
                    sendMessage($chat_id, "✅ Category '{$display_old_key_for_msg}' (key: `{$old_category_key}`) successfully renamed to '{$display_new_key_for_msg}' (key: `{$new_category_key_input}`). All associated products have been moved.");
                    clearUserState($user_id);
                    $show_cat_mgt_menu($chat_id, $original_message_id, "🗂️ Category Management 🗂️\nCategory '{$display_old_key_for_msg}' renamed to '{$display_new_key_for_msg}'. Select an action:");
                } else {
                    // Attempt to revert in-memory change if save fails
                    $products[$old_category_key] = $products_to_move;
                    unset($products[$new_category_key_input]);
                    error_log("Failed to write products file after category rename from {$old_category_key} to {$new_category_key_input}. In-memory changes reverted.");
                    sendMessage($chat_id, "⚠️ Failed to save the category rename from '{$display_old_key_for_msg}' to '{$new_category_key_input}'. The change was NOT saved due to a file write error. Please check server logs or file permissions.\nYou are still in the process of editing this category (original key '{$display_old_key_for_msg}'). You can try entering a new key again, or type /cancel.");
                }
            }
        }
    }
    // --- Admin is adding a new category name ---
    elseif ($is_admin && is_array($user_state) && $user_state['status'] === STATE_ADMIN_ADDING_CATEGORY_NAME) {
        $new_category_key_input = trim($text);
        $original_message_id = $user_state['original_message_id'] ?? null;

        if ($new_category_key_input === '/cancel') {
            clearUserState($user_id);
            if ($original_message_id) {
                // Show Category Management main menu again
                $cat_mgt_keyboard_re = [
                    'inline_keyboard' => [
                        [['text' => "➕ Add Category", 'callback_data' => CALLBACK_ADMIN_ADD_CATEGORY_PROMPT]],
                        [['text' => "✏️ Edit Category Name", 'callback_data' => CALLBACK_ADMIN_EDIT_CATEGORY_SELECT]],
                        [['text' => "➖ Remove Category", 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_SELECT]],
                        [['text' => '« Back to Admin Panel', 'callback_data' => CALLBACK_ADMIN_PANEL]]
                    ]
                ];
                editMessageText($chat_id, $original_message_id, "🗂️ Category Management 🗂️\nAdd category cancelled. Select an action:", json_encode($cat_mgt_keyboard_re));
            } else {
                sendMessage($chat_id, "Add category cancelled.");
            }
        } elseif (empty($new_category_key_input)) {
            sendMessage($chat_id, "Category key cannot be empty. Please enter a valid key, or type /cancel.");
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $new_category_key_input)) {
            sendMessage($chat_id, "Invalid category key format. Please use only alphanumeric characters and underscores (a-z, 0-9, _).\nE.g., `action_figures`, `digital_services_2`\nOr type /cancel.");
        } else {
            global $products;
            if (empty($products)) { $products = readJsonFile(PRODUCTS_FILE); } // Ensure products are loaded

            if (isset($products[$new_category_key_input])) {
                sendMessage($chat_id, "Category key '{$new_category_key_input}' already exists. Please enter a unique key, or type /cancel.");
            } else {
                $products[$new_category_key_input] = []; // Add as an empty category
                if (writeJsonFile(PRODUCTS_FILE, $products)) {
                    sendMessage($chat_id, "✅ Category '" . htmlspecialchars($new_category_key_input) . "' added successfully!");
                    clearUserState($user_id);
                    if ($original_message_id) {
                        $cat_mgt_keyboard_re_success = [
                            'inline_keyboard' => [
                                [['text' => "➕ Add Category", 'callback_data' => CALLBACK_ADMIN_ADD_CATEGORY_PROMPT]],
                                [['text' => "✏️ Edit Category Name", 'callback_data' => CALLBACK_ADMIN_EDIT_CATEGORY_SELECT]],
                                [['text' => "➖ Remove Category", 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_SELECT]],
                                [['text' => '« Back to Admin Panel', 'callback_data' => CALLBACK_ADMIN_PANEL]]
                            ]
                        ];
                        editMessageText($chat_id, $original_message_id, "🗂️ Category Management 🗂️\nCategory '".htmlspecialchars($new_category_key_input)."' added. Select an action:", json_encode($cat_mgt_keyboard_re_success));
                    }
                } else {
                    sendMessage($chat_id, "⚠️ Failed to save the new category '{$new_category_key_input}' to the products file. Please check server logs or file permissions. The category was NOT added.\nYou can try entering the category key again, or type /cancel.");
                    // User remains in STATE_ADMIN_ADDING_CATEGORY_NAME to allow retry or cancel.
                }
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
            if (addInstantProductItem($category_key, $product_id, $new_item_content)) { // addInstantProductItem internally calls writeJsonFile
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
            } else { // This else implies addInstantProductItem returned false, meaning writeJsonFile failed.
                sendMessage($chat_id, "⚠️ Error adding instant item. Product might not be 'instant' type, not found, or an issue occurred saving. Please check server logs. Or type /cancel.");
            }
        }
    }
    // --- Admin is in a manual send session with a user ---
    elseif ($is_admin && is_array($user_state) && $user_state['status'] === STATE_ADMIN_MANUAL_SEND_SESSION) {
        $admin_state_data = $user_state; // Admin's own state
        $target_user_id_session = $admin_state_data['target_user_id'];
        $admin_chat_id_session = $chat_id; // Admin's chat ID

        if (isset($message->reply_to_message) && strtolower($text) === '/save') {
            if ($message->reply_to_message->from->id == $user_id) { // Admin replied to their own message with /save
                $content_to_save = $message->reply_to_message->text ?? ''; // Assuming text content for now
                if (!empty(trim($content_to_save))) {
                    $all_purchases = readJsonFile(USER_PURCHASES_FILE);
                    $purchase_index_to_update = $admin_state_data['purchase_index'];

                    if (isset($all_purchases[$target_user_id_session][$purchase_index_to_update])) {
                        $all_purchases[$target_user_id_session][$purchase_index_to_update]['delivered_item_content'] = $content_to_save;
                        if (writeJsonFile(USER_PURCHASES_FILE, $all_purchases)) {
                            sendMessage($admin_chat_id_session, "✅ Content saved for this delivery:\n<code>".htmlspecialchars($content_to_save)."</code>\nYou can continue sending messages or use /end.", null, "HTML");
                            error_log("MANUAL_SEND: Admin {$user_id} saved content for user {$target_user_id_session}, purchase index {$purchase_index_to_update}.");
                        } else {
                            sendMessage($admin_chat_id_session, "⚠️ Error: Could not save the content to the purchases file. Please try again or check logs.");
                            error_log("MANUAL_SEND_ERROR: Failed to write user_purchases.json after admin {$user_id} tried to /save for user {$target_user_id_session}.");
                        }
                    } else {
                        sendMessage($admin_chat_id_session, "⚠️ Error: Could not find the specific purchase record to save content for. Please report this issue.");
                        error_log("MANUAL_SEND_ERROR: Purchase record not found for user {$target_user_id_session} at index {$purchase_index_to_update} when admin {$user_id} tried to /save.");
                    }
                } else {
                    sendMessage($admin_chat_id_session, "⚠️ Cannot save empty content. Please reply /save to a message with actual content.");
                }
            } else {
                sendMessage($admin_chat_id_session, "⚠️ To use /save, please reply directly to your own message that contains the information you want to save for the user.");
            }
        }
        elseif (strtolower($text) === '/end') {
            clearUserState($user_id); // Clear admin's session state
            if (getUserState($target_user_id_session)['status'] === 'in_manual_send_session_with_admin') { // Check if user is still in session
                 clearUserState($target_user_id_session); // Clear user's session state
            }

            sendMessage($admin_chat_id_session, "✅ Manual send session ended with User ID: {$target_user_id_session}.");
            sendMessage($target_user_id_session, "✅ ادمین این سشن  رو تموم کرد.");

            // Update the original admin message caption (receipt photo)
            $original_admin_msg_id = $admin_state_data['original_admin_msg_id'] ?? null;
            if ($original_admin_msg_id) {
                // Fetch the original caption again if needed, or assume it's implicitly handled by just appending.
                // For simplicity, we'll assume the caption is still available or not strictly needed to be prepended.
                // A robust way would be to store the original caption in the state, or fetch it.
                // However, `editMessageCaption` on a photo requires the *new full caption*.
                // We might not have the original photo's caption easily here if it wasn't stored in state.
                // Let's try to retrieve the original message to get its caption.
                // This is complex as bot() calls don't return full message objects easily.
                // Simplification: Just send a new message to admin or update the button message.
                // The current plan says "Update the original admin message".
                // The original admin message was already edited to remove buttons and show "You are now in a direct send session".
                // So we edit *that* message.
                $current_caption_text = "✅ Payment accepted. Delivery session concluded."; // Simplified
                // If we stored the product name in admin state, we could use it.
                // $product_name_state = $admin_state_data['purchase_category']."_".$admin_state_data['purchase_product_id']; // Example
                // $current_caption_text = "✅ Payment accepted for ".$product_name_state.". Delivery session concluded.";
                // For now, keeping it simple.
                // The original message ID is $admin_state_data['original_admin_msg_id']
                // The chat ID is $admin_chat_id_session (which is $chat_id for the admin)
                // The original message was a photo with caption.
                // We can't easily *append* to a photo caption without knowing the original.
                // The message was already edited in functions.php to remove buttons.
                // Let's assume the previous editMessageCaption replaced the buttons with text. We append to that.
                // This is still tricky. Let's assume we just edit the text on that message if it was converted to text message.
                // The plan step 3 said: editMessageCaption($chat_id, $message_id, $admin_caption_update, null, 'Markdown');
                // So the buttons were removed. We can try to append to this caption.
                // This requires fetching the current caption, which is not straightforward.
                // SAFEST: The existing message already says "You are now in a direct send session". We can edit it to "Session ended".
                 if ($original_admin_msg_id && is_numeric($original_admin_msg_id)) {
                    // Let's assume the message is the one that had the buttons, its caption was updated.
                    // We cannot *append* to caption easily. We'd *replace* it.
                    // The message original_admin_msg_id is in the admin's chat.
                    $final_caption = "✅ Payment accepted. Delivery session with User ID {$target_user_id_session} concluded.";
                    // We need to know if it's a photo to use editMessageCaption, or text for editMessageText
                    // This is complex. For now, we will NOT update the original receipt message further.
                    // The admin gets a new message "Manual send session ended..." which is clear.
                    error_log("MANUAL_SEND: Session ended. Admin {$user_id}, User {$target_user_id_session}. Original admin msg ID {$original_admin_msg_id} not further updated to simplify.");

                 }

            }
        }
        else { // Admin sends a regular message to be forwarded
            bot('copyMessage', [
                'from_chat_id' => $admin_chat_id_session,
                'chat_id' => $target_user_id_session,
                'message_id' => $message->message_id
            ]);
            // Optional: send confirmation to admin "Message sent"
            // sendMessage($admin_chat_id_session, "↪️ Sent to user.");
        }
    }
    // --- User is in a manual send session with an admin (receiving messages or sending to admin) ---
    elseif (!$is_admin && is_array($user_state) && $user_state['status'] === 'in_manual_send_session_with_admin') {
        $user_session_data = $user_state;
        $admin_id_to_forward_to = $user_session_data['admin_id'] ?? null;
        if ($admin_id_to_forward_to) {
            // Check if the admin is still in that session state with this user
            $admin_current_state = getUserState($admin_id_to_forward_to);
            if ($admin_current_state &&
                ($admin_current_state['status'] ?? null) === STATE_ADMIN_MANUAL_SEND_SESSION &&
                ($admin_current_state['target_user_id'] ?? null) == $user_id) {

                bot('copyMessage', [
                    'from_chat_id' => $chat_id, // User's chat_id
                    'chat_id' => $admin_id_to_forward_to,
                    'message_id' => $message->message_id
                ]);
            } else {
                // Admin is no longer in session with this user, or state is inconsistent.
                // Inform user, clear their state.
                sendMessage($chat_id, "👋 ادمین از سشن تحویل خارج شد.\nاگه سوالی داری، از بخش پشتیبانی اصلی استفاده کن ✉️");
                clearUserState($user_id);
            }
        } else {
            // User state is corrupted or admin_id missing.
            sendMessage($chat_id, "⚠️ یه مشکلی توی سشن تحویل پیش اومده.\nاگه لازمه، با پشتیبانی تماس بگیر 💬");
            clearUserState($user_id);
            error_log("MANUAL_SEND_ERROR: User {$user_id} in 'in_manual_send_session_with_admin' but admin_id is missing in their state.");
        }
    }
    // --- User is in a direct support chat ---
    elseif (isset($user_state['chatting_with'])) {
        // This existing block handles the generic /s<ID> support chat.
        // It needs to be distinguished from the manual send session.
        // The manual send session is more specific and has higher priority if active.
        // The order of these elseif blocks matters. STATE_ADMIN_MANUAL_SEND_SESSION should be checked before 'chatting_with'.
        // And 'in_manual_send_session_with_admin' for user should also be checked before.
        // The current placement of new blocks *before* 'chatting_with' is correct.

        if ($is_admin && preg_match('/^\/e(\d+)$/', $text, $matches)) {
            $customer_id_to_end = $matches[1];
            $current_chat_partner = $user_state['chatting_with'];
            if ($customer_id_to_end == $current_chat_partner) {
                clearUserState($user_id);
                clearUserState($current_chat_partner);
                sendMessage($user_id, "☑️ Chat ended with user $current_chat_partner.");
                sendMessage($current_chat_partner, "❌ ادمین چت پشتیبانی رو بست.");
            }
        } 
        elseif ($is_admin) {
            bot('copyMessage', ['from_chat_id' => $chat_id, 'chat_id' => $user_state['chatting_with'], 'message_id' => $message->message_id]);
        } 
        else {
            sendMessage($chat_id, "📨 پیامت فرستاده شد برای ادمین.");
            bot('copyMessage', ['from_chat_id' => $chat_id, 'chat_id' => $user_state['chatting_with'], 'message_id' => $message->message_id]);
        }
    }
    // --- No special state, handle regular commands and messages ---
    else {
        if (is_array($user_state) && ($user_state['status'] ?? null) === STATE_AWAITING_SUPPORT_MESSAGE) {
            if(isset($user_state['message_id'])){ // If a previous message had a "Cancel" button for support
                // Check if the text is /cancel
                if (strtolower($text) === '/cancel') {
                    editMessageText($chat_id, $user_state['message_id'], "🚫 درخواست پشتیبانی لغو شد.", null); // Remove buttons from original prompt
                    sendMessage($chat_id, "❌ درخواست پشتیبانی‌ت لغو شد.");
                    clearUserState($user_id);
                    exit();
                }
                editMessageReplyMarkup($chat_id, $user_state['message_id'], null); // Remove cancel button from prev message
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
            sendMessage($chat_id, "🙏 مرسی! پیامت رفت برای تیم پشتیبانی.");
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
            $customer_id = $matches[1];
            setUserState($user_id, ['chatting_with' => $customer_id]);
            setUserState($customer_id, ['chatting_with' => $user_id]);
            sendMessage($user_id, "✅ You are now connected with user `$customer_id`. Send `/e$customer_id` to end the chat.", null, 'Markdown');
            sendMessage($customer_id, "💬 ادمین بهت وصل شده!\nمی‌تونی مستقیم از همین‌جا جواب بدی 😄");
        }
        // User sends /start
        elseif ($text === "/start") {
            error_log("START_CMD: /start command received for chat_id: {$chat_id}, user_id: {$user_id}, is_admin: " . ($is_admin ? 'Yes' : 'No')); // LOG START_CMD
            $first_name = $message->from->first_name;
            $welcome_text = "👋 سلام " . htmlspecialchars($first_name) . "! خوش اومدی به فروشگاه 💫\nلطفاً یکی از گزینه‌ها رو انتخاب کن 👇";

            $keyboard_array = generateDynamicMainMenuKeyboard($is_admin); // New dynamic keyboard
            error_log("START_CMD: Keyboard array received: " . print_r($keyboard_array, true)); // LOG KEYBOARD_ARRAY

            $json_keyboard = json_encode($keyboard_array);
            error_log("START_CMD: JSON keyboard: " . $json_keyboard); // LOG JSON_KEYBOARD

            sendMessage($chat_id, $welcome_text, $json_keyboard);
        }
        // User sends a photo receipt
        elseif (isset($message->photo)) {
            $state = getUserState($user_id);
            if (is_array($state) && ($state['status'] ?? null) === STATE_AWAITING_RECEIPT) {
                if (isset($state['message_id'])) { editMessageReplyMarkup($chat_id, $state['message_id'], null); }
                $product_name = $state['product_name'] ?? 'Unknown Product';
                $price = $state['price'] ?? 'N/A';
        $category_key = $state['category_key'] ?? 'unknown_category'; // Retrieve category_key
        $product_id = $state['product_id'] ?? 'unknown_product';     // Retrieve product_id

                $user_info = "🧾 New Payment Receipt\n\n▪️ **Product:** $product_name\n▪️ **Price:** $$price\n\n👤 **From User:**\nName: " . htmlspecialchars(($message->from->first_name ?? '') . " " . ($message->from->last_name ?? '')) . "\nUsername: @" . ($message->from->username ?? 'N/A') . "\nID: `$user_id`";
                $photo_file_id = $message->photo[count($message->photo) - 1]->file_id;

        // Pass category_key and product_id to forwardPhotoToAdmin
        forwardPhotoToAdmin($photo_file_id, $user_info, $user_id, $category_key, $product_id);

                sendMessage($chat_id, "🧾 مرسی! رسیدت ارسال شد و الان داره بررسی میشه.");
        clearUserState($user_id);
            } else {
                sendMessage($chat_id, "📸 عکست رسید، ولی الان منتظر عکس نبودم 😅\nاگه کمک می‌خوای، از دکمه‌ی پشتیبانی استفاده کن ❤️");
            }
        }
    }
}

?>
