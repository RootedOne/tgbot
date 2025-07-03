<?php
// FILE: config.php
// All bot configurations.

// --- Bot Token ---
define('API_TOKEN', '7191984881:AAHP_mD54kaJC6rUk28mLFpcPB6Yf2iaXRA'); // Replace with your actual token

// --- Admin Configuration ---
const ADMINS = [2045651875]; // Replace with your numeric Telegram User ID

// --- Main Menu Keyboard ---
$mainMenuKeyboard = json_encode([
    'inline_keyboard' => [
        [['text' => "🛍️ My Products", 'callback_data' => 'my_products']], // Added My Products
        [['text' => "Buy Spotify Account", 'callback_data' => 'buy_spotify']],
        [['text' => "Buy SSH Vpn Account", 'callback_data' => 'buy_ssh']],
        [['text' => "Buy V2ray Vpn Account", 'callback_data' => 'buy_v2ray']],
        [['text' => "Support", 'callback_data' => 'support']],
    ]
]);

// --- Admin Menu Keyboard ---
$adminMenuKeyboard = json_encode([
    'inline_keyboard' => [
        [['text' => "🛍️ My Products", 'callback_data' => 'my_products']], // Added My Products
        [['text' => "Buy Spotify Account", 'callback_data' => 'buy_spotify']],
        [['text' => "Buy SSH Vpn Account", 'callback_data' => 'buy_ssh']],
        [['text' => "Buy V2ray Vpn Account", 'callback_data' => 'buy_v2ray']],
        [['text' => "Support", 'callback_data' => 'support']],
        [['text' => "⚙️ Admin Panel", 'callback_data' => 'admin_panel']],
    ]
]);

?>
