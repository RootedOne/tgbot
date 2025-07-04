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
    $is_admin = in_array($user_id, ADMINS);
    $user_state = getUserState($user_id);

    // --- Admin is adding a product ---
    if ($is_admin && is_array($user_state) && strpos($user_state['status'], 'admin_adding_') === 0 && $user_state['status'] !== 'admin_adding_prod_manual') {
        $category_key = $user_state['category'];

        switch ($user_state['status']) {
            case 'admin_adding_name':
                $user_state['name'] = $text;
                $user_state['status'] = 'admin_adding_price';
                setUserState($user_id, $user_state);
                sendMessage($chat_id, "Great. Now enter the price (numbers only):");
                break;
            case 'admin_adding_price':
                if (!is_numeric($text)) { sendMessage($chat_id, "Invalid price. Please enter numbers only."); break; }
                $user_state['price'] = $text;
                $user_state['status'] = 'admin_adding_id';
                setUserState($user_id, $user_state);
                sendMessage($chat_id, "Perfect. Now enter a unique ID for this product (e.g., 'family' or '7'):");
                break;
            case 'admin_adding_id':
                $user_state['id'] = $text;
                $all_products = readJsonFile(PRODUCTS_FILE);
                $all_products[$category_key][$user_state['id']] = ['name' => $user_state['name'], 'price' => $user_state['price']];
                writeJsonFile(PRODUCTS_FILE, $all_products);
                clearUserState($user_id); // $user_id here is the admin's ID
                sendMessage($chat_id, "✅ Product '{$user_state['name']}' added successfully!");
                break;
        }
    }
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
            $admin_id = ADMINS[0];
            sendMessage($admin_id, $user_info, null, 'Markdown');

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
