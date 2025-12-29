<?php

namespace Src\Core;

class Constants
{
    // Files - Fallback if not defined in config.php
    const STATE_FILE = 'user_states.json';
    const PRODUCTS_FILE = 'products.json';
    const USER_PURCHASES_FILE = 'user_purchases.json';
    const USER_DATA_FILE = 'user_data.json';
    const BOT_CONFIG_DATA_FILE = 'bot_config_data.json';

    // State Constants
    const STATE_ADMIN_ADDING_PROD_NAME = 'admin_adding_prod_name';
    const STATE_ADMIN_ADDING_PROD_TYPE_PROMPT = 'admin_adding_prod_type_prompt';
    const STATE_ADMIN_ADDING_PROD_PRICE = 'admin_adding_prod_price';
    const STATE_ADMIN_ADDING_PROD_INFO = 'admin_adding_prod_info';
    const STATE_ADMIN_ADDING_PROD_INSTANT_ITEMS = 'admin_adding_prod_instant_items';
    const STATE_ADMIN_ADDING_PROD_ID = 'admin_adding_prod_id';
    const STATE_ADMIN_ADDING_PROD_MANUAL = 'admin_adding_prod_manual';
    const STATE_ADMIN_EDITING_PROD_FIELD = 'admin_editing_prod_field';
    const STATE_ADMIN_ADDING_SINGLE_INSTANT_ITEM = 'admin_adding_single_instant_item';
    const STATE_AWAITING_SUPPORT_MESSAGE = 'awaiting_support_message';
    const STATE_AWAITING_RECEIPT = 'awaiting_receipt';
    const STATE_ADMIN_ADDING_CATEGORY_NAME = 'state_admin_adding_category_name';
    const STATE_ADMIN_EDITING_CATEGORY_NAME = 'state_admin_editing_category_name';
    const STATE_ADMIN_MANUAL_SEND_SESSION = 'admin_manual_send_session';
    const STATE_ADMIN_SETTING_MANUAL_LAYOUT = 'admin_setting_manual_layout';

    // Callback Data Prefixes/Actions
    const CALLBACK_ADMIN_PANEL = 'admin_panel';
    const CALLBACK_ADMIN_PROD_MANAGEMENT = 'admin_prod_management';
    const CALLBACK_BACK_TO_MAIN = 'back_to_main';
    const CALLBACK_MY_PRODUCTS = 'my_products';
    const CALLBACK_SUPPORT = 'support';
    const CALLBACK_SUPPORT_CONFIRM = 'support_confirm';

    // Admin Add Product
    const CALLBACK_ADMIN_ADD_PROD_SELECT_CATEGORY = 'admin_add_prod_select_category';
    const CALLBACK_ADMIN_AP_CAT_PREFIX = 'admin_ap_cat_';
    const CALLBACK_ADMIN_SET_PROD_TYPE_INSTANT = 'admin_set_prod_type_instant';
    const CALLBACK_ADMIN_SET_PROD_TYPE_MANUAL = 'admin_set_prod_type_manual';

    // Admin Edit Product
    const CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY = 'admin_edit_prod_select_category';
    const CALLBACK_ADMIN_EP_SCAT_PREFIX = 'admin_ep_scat_';
    const CALLBACK_ADMIN_EP_SPRO_PREFIX = 'admin_ep_spro_';
    const CALLBACK_ADMIN_EDIT_NAME_PREFIX = 'admin_edit_name_';
    const CALLBACK_ADMIN_EDIT_PRICE_PREFIX = 'admin_edit_price_';
    const CALLBACK_ADMIN_EDIT_INFO_PREFIX = 'admin_edit_info_';
    const CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX = 'admin_edit_type_prompt_';
    const CALLBACK_ADMIN_SET_TYPE_TO_INSTANT_PREFIX = 'admin_set_type_inst_';
    const CALLBACK_ADMIN_SET_TYPE_TO_MANUAL_PREFIX = 'admin_set_type_man_';

    // Admin Manage Instant Items
    const CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX = 'admin_manage_instant_items_';
    const CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX = 'admin_add_inst_item_prompt_';
    const CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX = 'admin_remove_inst_item_list_';
    const CALLBACK_ADMIN_REMOVE_INST_ITEM_DO_PREFIX = 'admin_remove_inst_item_do_';

    // Admin Remove Product
    const CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY = 'admin_remove_prod_select_category';
    const CALLBACK_ADMIN_RP_SCAT_PREFIX = 'admin_rp_scat_';
    const CALLBACK_ADMIN_RP_SPRO_PREFIX = 'admin_rp_spro_';
    const CALLBACK_ADMIN_RP_CONF_YES_PREFIX = 'admin_rp_conf_yes_';
    const CALLBACK_ADMIN_RP_CONF_NO_PREFIX = 'admin_rp_conf_no_';

    // Admin Stats
    const CALLBACK_ADMIN_VIEW_STATS = 'admin_view_stats';

    // Admin UI
    const CALLBACK_ADMIN_MAIN_MENU_UI = 'admin_main_menu_ui';
    const CALLBACK_ADMIN_AUTO_LAYOUT_MENU = 'admin_auto_layout_menu';
    const CALLBACK_ADMIN_MANUAL_LAYOUT_MENU = 'admin_manual_layout_menu';
    const CALLBACK_ADMIN_SET_MENU_COLS_PREFIX = 'admin_set_menu_cols_';

    // Category Management
    const CALLBACK_ADMIN_CATEGORY_MANAGEMENT = 'admin_category_management';
    const CALLBACK_ADMIN_ADD_CATEGORY_PROMPT = 'admin_add_category_prompt';
    const CALLBACK_ADMIN_EDIT_CATEGORY_SELECT = 'admin_edit_category_select';
    const CALLBACK_ADMIN_EDIT_CATEGORY_PROMPT_PREFIX = 'admin_edit_cat_prompt_';
    const CALLBACK_ADMIN_REMOVE_CATEGORY_SELECT = 'admin_remove_category_select';
    const CALLBACK_ADMIN_REMOVE_CATEGORY_CONFIRM_PREFIX = 'admin_rem_cat_conf_';
    const CALLBACK_ADMIN_REMOVE_CATEGORY_DO_PREFIX = 'admin_rem_cat_do_';

    // Payment
    const CALLBACK_ACCEPT_PAYMENT_PREFIX = 'accept_payment_';
    const CALLBACK_REJECT_PAYMENT_PREFIX = 'reject_payment_';
    const CALLBACK_ACCEPT_AND_SEND_PREFIX = 'accept_send_';

    const CALLBACK_CONFIRM_BUY_PREFIX = 'confirm_buy_';
    const CALLBACK_VIEW_PURCHASED_ITEM_PREFIX = 'v_p_i_';
}
