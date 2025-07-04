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
    $is_admin = in_array($user_id, getAdminIds()); // Use new function
    $user_state = getUserState($user_id);

    // Check if user is banned
    $user_specific_data = getUserData($user_id);
    if ($user_specific_data['is_banned']) {
        sendMessage($chat_id, "⚠️ You are banned from using this bot.");
        exit();
    }

    // --- Admin is adding a product (New Flow) ---
    if ($is_admin && is_array($user_state) && 
        in_array($user_state['status'], [
            'admin_adding_prod_name', 
            'admin_adding_prod_price', 
            'admin_adding_prod_info', 
            'admin_adding_prod_id', 
            'admin_adding_prod_instant_items'
            ])
    ) {
        // This whole block will be new logic for the add product flow
        switch ($user_state['status']) {
            case 'admin_adding_prod_name': // Name provided by admin
                $user_state['new_product_name'] = $text;
                $user_state['status'] = 'admin_adding_prod_type_prompt'; // Callback will handle type selection
                setUserState($user_id, $user_state);
                // Prompt for product type (via callback in functions.php)
                promptForProductType($chat_id, $user_id, $user_state['category_key'], $text /* product name for context */);
                break;

            case 'admin_adding_prod_price': // Price provided by admin (after type selected via callback)
                if (!is_numeric($text) || $text < 0) {
                    sendMessage($chat_id, "Invalid price. Please enter a non-negative number.");
                    // Remain in this state, or send back to type selection if needed
                    // For simplicity, just re-prompt for price.
                    sendMessage($chat_id, "Enter the price for '{$user_state['new_product_name']}': (numbers only)");
                    break;
                }
                $user_state['new_product_price'] = $text;
                $user_state['status'] = 'admin_adding_prod_info_prompt';
                setUserState($user_id, $user_state);
                sendMessage($chat_id, "Enter the product information/description for '{$user_state['new_product_name']}' (this will be shown on the confirmation page):");
                break;

            case 'admin_adding_prod_info': // Info provided by admin
                $user_state['new_product_info'] = $text;
                setUserState($user_id, $user_state); // Save info
                // Now, branch based on stored type
                if ($user_state['new_product_type'] === 'instant') {
                    $user_state['status'] = 'admin_adding_prod_instant_items';
                    $user_state['new_product_items_buffer'] = []; // Initialize buffer for items
                    setUserState($user_id, $user_state);
                    sendMessage($chat_id, "Product type: Instant Delivery.\nPlease send each deliverable item as a separate message (e.g., a code, a link, account details).\nType /doneitems when you have added all items for '{$user_state['new_product_name']}'.");
                } else { // Manual type
                    $user_state['status'] = 'admin_adding_prod_id';
                    setUserState($user_id, $user_state);
                    sendMessage($chat_id, "Product type: Manual Delivery.\nEnter a unique ID for '{$user_state['new_product_name']}' (e.g., 'product_xyz' or a number):");
                }
                break;
            
            case 'admin_adding_prod_instant_items': // Admin is sending items for an instant product
                if ($text === '/doneitems') {
                    // All items sent, now ask for product ID
                    $user_state['status'] = 'admin_adding_prod_id';
                    setUserState($user_id, $user_state);
                    sendMessage($chat_id, "All items for '{$user_state['new_product_name']}' received (" . count($user_state['new_product_items_buffer']) . " items).\nNow, enter a unique ID for this product:");
                } else {
                    // Add the received text as an item
                    $user_state['new_product_items_buffer'][] = $text;
                    setUserState($user_id, $user_state);
                    sendMessage($chat_id, "Item added: \"$text\". Send the next item, or type /doneitems if finished.");
                }
                break;

            case 'admin_adding_prod_id': // ID provided by admin (for both manual and instant after items)
                $product_id = trim($text);
                if (empty($product_id)) {
                    sendMessage($chat_id, "Product ID cannot be empty. Please enter a unique ID:");
                    break;
                }
                // Check if product ID already exists in this category
                global $products;
                if (isset($products[$user_state['category_key']][$product_id])) {
                    sendMessage($chat_id, "Product ID '{$product_id}' already exists in this category. Please enter a different unique ID:");
                    break;
                }

                $new_product_data = [
                    'name' => $user_state['new_product_name'],
                    'price' => $user_state['new_product_price'],
                    'type' => $user_state['new_product_type'],
                    'info' => $user_state['new_product_info'],
                    'items' => ($user_state['new_product_type'] === 'instant' ? $user_state['new_product_items_buffer'] : [])
                ];
                
                $products[$user_state['category_key']][$product_id] = $new_product_data;
                writeJsonFile(PRODUCTS_FILE, $products); // Save all products
                
                sendMessage($chat_id, "✅ Product '{$user_state['new_product_name']}' (ID: {$product_id}) added successfully to category '{$user_state['category_key']}'!");
                clearUserState($user_id);
                // Optionally, show the product management menu again
                // For now, just clear state. Admin can navigate back.
                break;
        }
    }
    // --- Admin is adding a product (OLD FLOW - to be phased out) ---
    // This is the old logic for admin_adding_name, admin_adding_price, admin_adding_id
    // It should be distinguished from the new flow states. We can prefix new states e.g. admin_newprod_name
    // For now, the condition above `strpos($user_state['status'], 'admin_adding_') === 0 && $user_state['status'] !== 'admin_adding_prod_manual'`
    // might catch both if not careful with state names.
    // Let's assume the new states like 'admin_adding_prod_name' are distinct enough for now.
    // The old flow was simpler and didn't have type/info/items.

    // --- Admin is manually adding a product for a user (after /addprod <USERID>) ---
    elseif ($is_admin && is_array($user_state) && $user_state['status'] === 'admin_adding_prod_manual') {
        $target_user_id = $user_state['target_user_id'];
        $product_description = $text; // The admin's message is the product description

        // Record the manually added product. Using a distinct "price" to identify it if needed later.
        // The product_description will be used as the 'name' in user_purchases.json
        recordPurchase($target_user_id, "🎁 " . $product_description, "Manually Added"); 

        clearUserState($user_id); // $user_id here is the admin's ID
        sendMessage($chat_id, "✅ Custom product '{$product_description}' has been added to user `{$target_user_id}`'s purchases.", null, 'Markdown');
        sendMessage($target_user_id, "🎁 A new item has been manually added to your purchases by an admin: '{$product_description}'. You can see it in 'My Products'.");
    }
    // --- User is in a direct support chat ---
    elseif (isset($user_state['chatting_with'])) {
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
        // --- **MODIFIED**: Handle a pending support message ---
        if (is_array($user_state) && ($user_state['status'] ?? null) === 'awaiting_support_message') {
            
            // Remove the "Cancel" button from the previous message
            if(isset($user_state['message_id'])){
                editMessageReplyMarkup($chat_id, $user_state['message_id'], null);
            }

            // Prepare user info for the admin
            $user_info = "New support message from:\n";
            $user_info .= "User: " . htmlspecialchars(($message->from->first_name ?? '') . " " . ($message->from->last_name ?? '')) . "\n";
            $user_info .= "Username: @" . ($message->from->username ?? 'N/A') . "\n";
            $user_info .= "User ID: `$user_id`\n\n";
            $user_info .= "Message:\n" . htmlspecialchars($text);

            // Send to admin
            $admin_ids = getAdminIds();
            if(!empty($admin_ids)){ // Ensure there is at least one admin
                $admin_id_to_send_to = $admin_ids[0]; // Send to the first admin in the list
                sendMessage($admin_id_to_send_to, $user_info, null, 'Markdown');
            } else {
                // Optional: Log that no admins are configured to receive support messages
                error_log("No admins configured to receive support message from user $user_id");
            }
            
            // Confirm to user and clear state
            sendMessage($chat_id, "✅ Thank you! Your message has been sent to the support team.");
            clearUserState($user_id);
        }
        // Admin command: /addprod <USERID>
        elseif ($is_admin && preg_match('/^\/addprod\s+(\d+)$/', $text, $matches)) {
            $user_id_to_add_to = $matches[1];
            // Check if target user ID is valid (e.g., is a number, maybe check if user exists if you have a user list)
            // For now, just assume it's a valid ID if it's numeric.
            if (is_numeric($user_id_to_add_to)) {
                setUserState($user_id, ['status' => 'admin_adding_prod_manual', 'target_user_id' => $user_id_to_add_to]);
                sendMessage($chat_id, "Please send the product description/name for user `{$user_id_to_add_to}`. This text will appear as the item in their 'My Products' list.", null, 'Markdown');
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
            sendMessage($customer_id, "✅ An admin has connected with you. You can reply here directly.");
        }
        // User sends /start
        elseif ($text === "/start") {
            $first_name = $message->from->first_name;
            $welcome_text = "Hello, " . htmlspecialchars($first_name) . "! Welcome to the shop.\n\nPlease select an option:";
            $keyboard = $is_admin ? $adminMenuKeyboard : $mainMenuKeyboard;
            sendMessage($chat_id, $welcome_text, $keyboard);
        }
        // User sends a photo receipt
        elseif (isset($message->photo)) {
            $state = getUserState($user_id);
            if (is_array($state) && ($state['status'] ?? null) === 'awaiting_receipt') {
                if (isset($state['message_id'])) { editMessageReplyMarkup($chat_id, $state['message_id'], null); }
                $product_name = $state['product_name'] ?? 'Unknown Product';
                $price = $state['price'] ?? 'N/A';
                $user_info = "🧾 New Payment Receipt\n\n▪️ **Product:** $product_name\n▪️ **Price:** $$price\n\n👤 **From User:**\nName: " . htmlspecialchars(($message->from->first_name ?? '') . " " . ($message->from->last_name ?? '')) . "\nUsername: @" . ($message->from->username ?? 'N/A') . "\nID: `$user_id`";
                $photo_file_id = $message->photo[count($message->photo) - 1]->file_id;
                forwardPhotoToAdmin($photo_file_id, $user_info, $user_id);
                sendMessage($chat_id, "✅ Thank you! Your receipt has been submitted and is now under review.");
            } else {
                sendMessage($chat_id, "I've received your photo, but I wasn't expecting one. If you need help, please use the Support button.");
            }
        }
        // Fallback for other messages - uncomment if you want to notify user for unrecognized commands
        // else {
        //    sendMessage($chat_id, "Sorry, I didn't understand that command. Please use the menu or type /start.");
        // }
    }
}

?>
