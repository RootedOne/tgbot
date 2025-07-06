<?php
// FILE: config.php
// All bot configurations.

// --- Bot Token ---
define('API_TOKEN', '7191984881:AAH1BBX3S6SKNCKGkjKnKZj2OTto_bfg2ZI'); // Replace with your actual token

// --- Admin Configuration ---
// Admin list is now managed in bot_config_data.json

// --- State Machine Constants ---
define('STATE_ADMIN_ADDING_PROD_NAME', 'admin_adding_prod_name');
define('STATE_ADMIN_ADDING_PROD_TYPE_PROMPT', 'admin_adding_prod_type_prompt');
define('STATE_ADMIN_ADDING_PROD_PRICE', 'admin_adding_prod_price');
define('STATE_ADMIN_ADDING_PROD_INFO', 'admin_adding_prod_info');
define('STATE_ADMIN_ADDING_PROD_INSTANT_ITEMS', 'admin_adding_prod_instant_items');
define('STATE_ADMIN_ADDING_PROD_ID', 'admin_adding_prod_id');
define('STATE_ADMIN_ADDING_PROD_MANUAL', 'admin_adding_prod_manual');
define('STATE_ADMIN_EDITING_PROD_FIELD', 'admin_editing_prod_field');
define('STATE_ADMIN_ADDING_SINGLE_INSTANT_ITEM', 'admin_adding_single_instant_item');
define('STATE_AWAITING_SUPPORT_MESSAGE', 'awaiting_support_message');
define('STATE_AWAITING_RECEIPT', 'awaiting_receipt');
// New states for category management
define('STATE_ADMIN_ADDING_CATEGORY_NAME', 'state_admin_adding_category_name');
define('STATE_ADMIN_EDITING_CATEGORY_NAME', 'state_admin_editing_category_name');
define('STATE_ADMIN_MANUAL_SEND_SESSION', 'admin_manual_send_session'); // New state for admin sending manual product info
// Note: 'admin_adding_prod_info_prompt' was a transient state name used before setting 'admin_adding_prod_info', so not making it a global constant.

// --- Callback Data Prefixes/Actions ---
// General Navigation
define('CALLBACK_ADMIN_PANEL', 'admin_panel');
define('CALLBACK_ADMIN_PROD_MANAGEMENT', 'admin_prod_management');
define('CALLBACK_BACK_TO_MAIN', 'back_to_main');
define('CALLBACK_MY_PRODUCTS', 'my_products');
define('CALLBACK_SUPPORT', 'support');
define('CALLBACK_SUPPORT_CONFIRM', 'support_confirm');

// Admin Add Product
define('CALLBACK_ADMIN_ADD_PROD_SELECT_CATEGORY', 'admin_add_prod_select_category');
define('CALLBACK_ADMIN_AP_CAT_PREFIX', 'admin_ap_cat_');
define('CALLBACK_ADMIN_SET_PROD_TYPE_INSTANT', 'admin_set_prod_type_instant');
define('CALLBACK_ADMIN_SET_PROD_TYPE_MANUAL', 'admin_set_prod_type_manual');

// Admin Edit Product
define('CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY', 'admin_edit_prod_select_category');
define('CALLBACK_ADMIN_EP_SCAT_PREFIX', 'admin_ep_scat_');
define('CALLBACK_ADMIN_EP_SPRO_PREFIX', 'admin_ep_spro_');
define('CALLBACK_ADMIN_EDIT_NAME_PREFIX', 'admin_edit_name_');
define('CALLBACK_ADMIN_EDIT_PRICE_PREFIX', 'admin_edit_price_');
define('CALLBACK_ADMIN_EDIT_INFO_PREFIX', 'admin_edit_info_');
define('CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX', 'admin_edit_type_prompt_');
define('CALLBACK_ADMIN_SET_TYPE_TO_INSTANT_PREFIX', 'admin_set_type_inst_'); // Standardized
define('CALLBACK_ADMIN_SET_TYPE_TO_MANUAL_PREFIX', 'admin_set_type_man_');   // Standardized

// Admin Manage Instant Items (within Edit)
define('CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX', 'admin_manage_instant_items_');
define('CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX', 'admin_add_inst_item_prompt_');
define('CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX', 'admin_remove_inst_item_list_');
define('CALLBACK_ADMIN_REMOVE_INST_ITEM_DO_PREFIX', 'admin_remove_inst_item_do_');

// Admin Remove Product
define('CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY', 'admin_remove_prod_select_category');
define('CALLBACK_ADMIN_RP_SCAT_PREFIX', 'admin_rp_scat_');
define('CALLBACK_ADMIN_RP_SPRO_PREFIX', 'admin_rp_spro_');
define('CALLBACK_ADMIN_RP_CONF_YES_PREFIX', 'admin_rp_conf_yes_');
define('CALLBACK_ADMIN_RP_CONF_NO_PREFIX', 'admin_rp_conf_no_');

// Admin Stats
define('CALLBACK_ADMIN_VIEW_STATS', 'admin_view_stats');

// Category Management (Admin) - New Constants
define('CALLBACK_ADMIN_CATEGORY_MANAGEMENT', 'admin_category_management');
define('CALLBACK_ADMIN_ADD_CATEGORY_PROMPT', 'admin_add_category_prompt');
define('CALLBACK_ADMIN_EDIT_CATEGORY_SELECT', 'admin_edit_category_select');
define('CALLBACK_ADMIN_EDIT_CATEGORY_PROMPT_PREFIX', 'admin_edit_cat_prompt_');
define('CALLBACK_ADMIN_REMOVE_CATEGORY_SELECT', 'admin_remove_category_select');
define('CALLBACK_ADMIN_REMOVE_CATEGORY_CONFIRM_PREFIX', 'admin_rem_cat_conf_');
define('CALLBACK_ADMIN_REMOVE_CATEGORY_DO_PREFIX', 'admin_rem_cat_do_');

// Payment Callbacks
define('CALLBACK_ACCEPT_PAYMENT_PREFIX', 'accept_payment_');
define('CALLBACK_REJECT_PAYMENT_PREFIX', 'reject_payment_');
define('CALLBACK_ACCEPT_AND_SEND_PREFIX', 'accept_send_'); // New callback for manual product accept & send flow

// Product Purchase Callbacks (Main Menu initiated) - These are now replaced by dynamic menu view_category_...
// define('CALLBACK_BUY_SPOTIFY', 'buy_spotify');
// define('CALLBACK_BUY_SSH', 'buy_ssh');
// define('CALLBACK_BUY_V2RAY', 'buy_v2ray');
// For product selection within a category like "spotify_plan_PRODUCTID"
// These are more like patterns than fixed prefixes for single actions.
// Example: 'spotify_plan_', 'ssh_plan_', 'v2ray_plan_' are category keys used in product selection.
// The callback then becomes CATEGORYKEY_PRODUCTID.
// So, not defining specific constants for "spotify_plan_PRODUCTID" but acknowledging the pattern.
define('CALLBACK_CONFIRM_BUY_PREFIX', 'confirm_buy_'); // confirm_buy_CATEGORY_PRODUCTID
define('CALLBACK_VIEW_PURCHASED_ITEM_PREFIX', 'v_p_i_'); // view_purchased_item_USERID_PURCHASEINDEX
define('CALLBACK_COPY_CARD_NUMBER', 'copy_card_num');
define('CALLBACK_COPY_PRICE_PREFIX', 'copy_price_');


// --- Main Menu Keyboard ---
// These are now generated dynamically by generateDynamicMainMenuKeyboard() in functions.php
/*
$mainMenuKeyboard = json_encode([
    'inline_keyboard' => [
        [['text' => "🛍️ My Products", 'callback_data' => CALLBACK_MY_PRODUCTS]],
        // The following lines are examples of what was here and is now dynamic
        // [['text' => "Buy Spotify Account", 'callback_data' => CALLBACK_BUY_SPOTIFY]],
        // [['text' => "Buy SSH Vpn Account", 'callback_data' => CALLBACK_BUY_SSH]],
        // [['text' => "Buy V2ray Vpn Account", 'callback_data' => CALLBACK_BUY_V2RAY]],
        [['text' => "Support", 'callback_data' => CALLBACK_SUPPORT]],
    ]
]);
*/

// --- Admin Menu Keyboard ---
// These are now generated dynamically by generateDynamicMainMenuKeyboard() in functions.php
/*
$adminMenuKeyboard = json_encode([
    'inline_keyboard' => [
        [['text' => "🛍️ My Products", 'callback_data' => CALLBACK_MY_PRODUCTS]],
        // The following lines are examples of what was here and is now dynamic
        // [['text' => "Buy Spotify Account", 'callback_data' => CALLBACK_BUY_SPOTIFY]],
        // [['text' => "Buy SSH Vpn Account", 'callback_data' => CALLBACK_BUY_SSH]],
        // [['text' => "Buy V2ray Vpn Account", 'callback_data' => CALLBACK_BUY_V2RAY]],
        [['text' => "Support", 'callback_data' => CALLBACK_SUPPORT]],
        [['text' => "⚙️ Admin Panel", 'callback_data' => CALLBACK_ADMIN_PANEL]],
    ]
]);
*/
?>
