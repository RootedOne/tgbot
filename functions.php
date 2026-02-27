<?php
// FILE: functions.php
// Contains all reusable bot functions.

define('STATE_FILE', 'user_states.json');
define('PRODUCTS_FILE', 'products.json');
define('USER_PURCHASES_FILE', 'user_purchases.json');
define('USER_DATA_FILE', 'user_data.json');

// Constants are now defined in config.php

// ===================================================================
//  DATABASE CONNECTION
// ===================================================================
function getPDO() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => true,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
            $pdo->exec("SET time_zone = '+00:00'");

            // Auto-initialize if needed
            initializeDatabase($pdo);

        } catch (\PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            return null;
        }
    }
    return $pdo;
}

function initializeDatabase($pdo) {
    try {
        // Check if user_states table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'user_states'");
        if ($stmt->fetch()) return;

        // If not, run schema
        $schemaPath = __DIR__ . '/schema.sql';
        if (!file_exists($schemaPath)) {
            error_log("schema.sql not found for auto-initialization.");
            return;
        }

        $schema = file_get_contents($schemaPath);
        $statements = array_filter(array_map('trim', explode(';', $schema)));

        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                $pdo->exec($stmt);
            }
        }
        error_log("Database initialized successfully from schema.sql.");
    } catch (Exception $e) {
        error_log("Database initialization failed: " . $e->getMessage());
    }
}

function ensureUserExists($user_id) {
    $pdo = getPDO();
    if (!$pdo) return;
    $stmt = $pdo->prepare("INSERT IGNORE INTO users (id) VALUES (:id)");
    $stmt->execute([':id' => $user_id]);
}

function parseProductCallback($data) {
    $pdo = getPDO();
    $category_keys = [];
    if ($pdo) $category_keys = $pdo->query("SELECT slug FROM categories")->fetchAll(PDO::FETCH_COLUMN);

    // Sort by length descending to match longest category slug first
    usort($category_keys, function($a, $b) { return strlen($b) - strlen($a); });

    foreach ($category_keys as $ck) {
        if (strpos($data, $ck . '_') === 0) {
            $product_id = substr($data, strlen($ck) + 1);
            return ['category' => $ck, 'product' => $product_id];
        }
    }
    return null;
}

// ===================================================================
//  STATE & DATA MANAGEMENT FUNCTIONS
// ===================================================================
function readJsonFile($filename) { if (!file_exists($filename)) return []; $json = file_get_contents($filename); return json_decode($json, true) ?: []; }
function writeJsonFile($filename, $data) {
    $json_data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($filename, $json_data) !== false;
}

function setUserState($user_id, $state) {
    ensureUserExists($user_id);
    $pdo = getPDO();
    if (!$pdo) return;
    $json = json_encode($state, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare("INSERT INTO user_states (user_id, state_data, updated_at) VALUES (:uid, :data, NOW()) ON DUPLICATE KEY UPDATE state_data = :data_update, updated_at = NOW()");
    $stmt->execute([':uid' => $user_id, ':data' => $json, ':data_update' => $json]);
}

function getUserState($user_id) {
    $pdo = getPDO();
    if (!$pdo) return null;
    $stmt = $pdo->prepare("SELECT state_data FROM user_states WHERE user_id = :id");
    $stmt->execute([':id' => $user_id]);
    $result = $stmt->fetchColumn();
    return $result ? json_decode($result, true) : null;
}

function clearUserState($user_id) {
    $pdo = getPDO();
    if (!$pdo) return;
    $stmt = $pdo->prepare("DELETE FROM user_states WHERE user_id = :uid");
    $stmt->execute([':uid' => $user_id]);
}

// --- Bot Config Data Functions ---
function getBotConfig() {
    $admins_str = getenv('BOT_ADMINS');
    $admins = $admins_str ? explode(',', $admins_str) : [];
    $admins = array_map(function($id) { return (int)trim($id); }, $admins);

    $layout_mode = getenv('MAIN_MENU_LAYOUT_MODE') ?: 'auto';
    $columns = getenv('MAIN_MENU_COLUMNS') ?: 1;

    $manual_layout_str = getenv('MAIN_MENU_MANUAL_LAYOUT');
    $manual_layout = $manual_layout_str ? json_decode($manual_layout_str, true) : [];

    return [
        'admins' => $admins,
        'payment_card_holder' => getenv('PAYMENT_CARD_HOLDER') ?: 'Not Set',
        'payment_card_number' => getenv('PAYMENT_CARD_NUMBER') ?: 'Not Set',
        'main_menu_layout_mode' => $layout_mode,
        'main_menu_columns' => (int)$columns,
        'main_menu_manual_layout' => $manual_layout
    ];
}

function saveBotConfig($config_data) {
    if (isset($config_data['admins']) && is_array($config_data['admins'])) {
        updateEnv('BOT_ADMINS', implode(',', $config_data['admins']));
    }
    if (isset($config_data['payment_card_holder'])) {
        updateEnv('PAYMENT_CARD_HOLDER', $config_data['payment_card_holder']);
    }
    if (isset($config_data['payment_card_number'])) {
        updateEnv('PAYMENT_CARD_NUMBER', $config_data['payment_card_number']);
    }
    if (isset($config_data['main_menu_layout_mode'])) {
        updateEnv('MAIN_MENU_LAYOUT_MODE', $config_data['main_menu_layout_mode']);
    }
    if (isset($config_data['main_menu_columns'])) {
        updateEnv('MAIN_MENU_COLUMNS', $config_data['main_menu_columns']);
    }
    if (isset($config_data['main_menu_manual_layout'])) {
        updateEnv('MAIN_MENU_MANUAL_LAYOUT', json_encode($config_data['main_menu_manual_layout'], JSON_UNESCAPED_UNICODE));
    }
}

function getAdminIds() { $config = getBotConfig(); return $config['admins'] ?? []; }
function getPaymentDetails() { $config = getBotConfig(); return ['card_holder' => $config['payment_card_holder'] ?? 'Not Set', 'card_number' => $config['payment_card_number'] ?? 'Not Set']; }
function updatePaymentDetails($new_holder, $new_number) { $config = getBotConfig(); if ($new_holder !== null) { $config['payment_card_holder'] = $new_holder; } if ($new_number !== null) { $config['payment_card_number'] = $new_number; } saveBotConfig($config); }
function addAdmin($user_id) { if (!is_numeric($user_id)) return false; $user_id = (int) $user_id; $config = getBotConfig(); if (!in_array($user_id, ($config['admins'] ?? []))) { $config['admins'][] = $user_id; saveBotConfig($config); return true; } return false; }
function removeAdmin($user_id) { if (!is_numeric($user_id)) return false; $user_id = (int) $user_id; $config = getBotConfig(); $admins = $config['admins'] ?? []; $initial_count = count($admins); $config['admins'] = array_values(array_filter($admins, function($admin) use ($user_id) { return $admin !== $user_id; })); if (count($config['admins']) < $initial_count) { saveBotConfig($config); return true; } return false; }

// --- User Data Functions ---
function getUserData($user_id) {
    $pdo = getPDO();
    if (!$pdo) return ['balance' => 0, 'is_banned' => false];
    $stmt = $pdo->prepare("SELECT balance, is_banned FROM users WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $user['is_banned'] = (bool)$user['is_banned'];
        return $user;
    }
    return ['balance' => 0, 'is_banned' => false];
}

function updateUserData($user_id, $data) {
    $pdo = getPDO();
    if (!$pdo) return;
    $balance = $data['balance'] ?? 0;
    $banned = !empty($data['is_banned']) ? 1 : 0;
    $stmt = $pdo->prepare("INSERT INTO users (id, balance, is_banned) VALUES (:id, :balance, :banned) ON DUPLICATE KEY UPDATE balance = :balance_update, is_banned = :banned_update");
    $stmt->execute([
        ':id' => $user_id,
        ':balance' => $balance,
        ':banned' => $banned,
        ':balance_update' => $balance,
        ':banned_update' => $banned
    ]);
}

function banUser($user_id) {
    $pdo = getPDO();
    if (!$pdo) return;
    $stmt = $pdo->prepare("INSERT INTO users (id, is_banned) VALUES (:uid, 1) ON DUPLICATE KEY UPDATE is_banned = 1");
    $stmt->execute([':uid' => $user_id]);
}

function unbanUser($user_id) {
    $pdo = getPDO();
    if (!$pdo) return;
    $stmt = $pdo->prepare("INSERT INTO users (id, is_banned) VALUES (:uid, 0) ON DUPLICATE KEY UPDATE is_banned = 0");
    $stmt->execute([':uid' => $user_id]);
}

function addUserBalance($user_id, $amount) {
    if (!is_numeric($amount) || $amount < 0) return false;
    $pdo = getPDO();
    if (!$pdo) return false;
    $stmt = $pdo->prepare("INSERT INTO users (id, balance) VALUES (:uid, :amount) ON DUPLICATE KEY UPDATE balance = balance + :amount_update");
    return $stmt->execute([':uid' => $user_id, ':amount' => $amount, ':amount_update' => $amount]);
}

// --- User Purchase and Product Functions ---
function recordPurchase($user_id, $product_name, $price, $delivered_item_content = null) {
    $pdo = getPDO();
    if (!$pdo) return false;

    $product_id = null;
    $stmtFind = $pdo->prepare("SELECT id FROM products WHERE name = :name LIMIT 1");
    $stmtFind->execute([':name' => $product_name]);
    $product_id = $stmtFind->fetchColumn() ?: null;

    $stmt = $pdo->prepare("INSERT INTO purchases (user_id, product_id, product_name, price, delivered_item_content, date) VALUES (:uid, :pid, :name, :price, :content, NOW())");
    $priceVal = is_numeric($price) ? $price : 0;

    if ($stmt->execute([
        ':uid' => $user_id,
        ':pid' => $product_id,
        ':name' => $product_name,
        ':price' => $priceVal,
        ':content' => $delivered_item_content
    ])) {
        return $pdo->lastInsertId();
    }
    return false;
}

function getProductDetails($category_key, $product_id) {
    $pdo = getPDO();
    if (!$pdo) return null;

    $stmt = $pdo->prepare("
        SELECT p.*, c.slug as category_slug
        FROM products p
        JOIN categories c ON p.category_id = c.id
        WHERE c.slug = :cat_slug AND p.slug = :prod_slug
    ");
    $stmt->execute([':cat_slug' => $category_key, ':prod_slug' => $product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($product) {
        $details = [
            'name' => $product['name'],
            'price' => $product['price'],
            'type' => $product['type'],
            'info' => $product['description']
        ];

        if ($product['type'] === 'instant') {
            $stmtItems = $pdo->prepare("SELECT id, content FROM product_items WHERE product_id = :pid AND is_sold = 0");
            $stmtItems->execute([':pid' => $product['id']]);
            $details['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        }
        return $details;
    }
    return null;
}

function updateProductDetails($category_key, $product_id, $details) {
    $pdo = getPDO();
    if (!$pdo) return false;

    $stmt = $pdo->prepare("
        UPDATE products p
        JOIN categories c ON p.category_id = c.id
        SET p.name = :name, p.price = :price, p.type = :type, p.description = :desc
        WHERE c.slug = :cat AND p.slug = :prod
    ");
    return $stmt->execute([
        ':name' => $details['name'],
        ':price' => $details['price'],
        ':type' => $details['type'],
        ':desc' => $details['info'],
        ':cat' => $category_key,
        ':prod' => $product_id
    ]);
}

function addInstantProductItem($category_key, $product_id, $item_content) {
    $pdo = getPDO();
    if (!$pdo) return false;

    $stmtGetId = $pdo->prepare("
        SELECT p.id FROM products p
        JOIN categories c ON p.category_id = c.id
        WHERE c.slug = :cat AND p.slug = :prod
    ");
    $stmtGetId->execute([':cat' => $category_key, ':prod' => $product_id]);
    $pid = $stmtGetId->fetchColumn();

    if ($pid) {
        $stmtInsert = $pdo->prepare("INSERT INTO product_items (product_id, content, is_sold) VALUES (:pid, :content, 0)");
        return $stmtInsert->execute([':pid' => $pid, ':content' => $item_content]);
    }
    return false;
}

function getAndRemoveInstantProductItem($category_key, $product_id) {
    $pdo = getPDO();
    if (!$pdo) return null;

    try {
        $pdo->beginTransaction();

        $stmtPid = $pdo->prepare("
            SELECT p.id FROM products p
            JOIN categories c ON p.category_id = c.id
            WHERE c.slug = :cat AND p.slug = :prod
        ");
        $stmtPid->execute([':cat' => $category_key, ':prod' => $product_id]);
        $pid = $stmtPid->fetchColumn();

        if (!$pid) {
            $pdo->rollBack();
            return null;
        }

        $stmtItem = $pdo->prepare("SELECT id, content FROM product_items WHERE product_id = :pid AND is_sold = 0 LIMIT 1 FOR UPDATE");
        $stmtItem->execute([':pid' => $pid]);
        $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            $stmtUpdate = $pdo->prepare("UPDATE product_items SET is_sold = 1, sold_at = UTC_TIMESTAMP() WHERE id = :id");
            $stmtUpdate->execute([':id' => $item['id']]);

            $pdo->commit();
            return $item['content'];
        } else {
            $pdo->rollBack();
            return null;
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Transaction failed in getAndRemoveInstantProductItem: " . $e->getMessage());
        return null;
    }
}

function promptForProductType($chat_id, $admin_user_id, $category_key, $product_name_context) {
    $type_keyboard = ['inline_keyboard' => [
        [['text' => '📦 Instant Delivery', 'callback_data' => CALLBACK_ADMIN_SET_PROD_TYPE_INSTANT]],
        [['text' => '👤 Manual Delivery', 'callback_data' => CALLBACK_ADMIN_SET_PROD_TYPE_MANUAL]],
        [['text' => '« Cancel', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]]
    ]];
    sendMessage($chat_id, "Product: '{$product_name_context}'.\nSelect delivery type:", json_encode($type_keyboard));
}

// --- BOT STATS FUNCTION ---
function generateBotStatsText() {
    $pdo = getPDO();
    if (!$pdo) return "Error connecting to database.";

    $stats_text = "📊 <b>Bot Statistics</b> 📊\n\n";

    // Products
    $total_products = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $stats_text .= "📦 <b>Products:</b>\n";
    $stats_text .= "▪️ Total Products: " . $total_products . "\n";

    $cats = $pdo->query("
        SELECT c.name, COUNT(p.id) as count
        FROM categories c
        LEFT JOIN products p ON c.id = p.category_id
        GROUP BY c.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($cats) {
        $stats_text .= "▪️ Products per Category:\n";
        foreach ($cats as $cat) {
            if ($cat['count'] > 0) {
                 $stats_text .= "  - " . htmlspecialchars($cat['name']) . ": " . $cat['count'] . " products\n";
            }
        }
    } else { $stats_text .= "▪️ No categories found.\n"; }
    $stats_text .= "\n";

    // Users
    $total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $banned_users = $pdo->query("SELECT COUNT(*) FROM users WHERE is_banned = 1")->fetchColumn();

    $stats_text .= "👤 <b>Users:</b>\n";
    $stats_text .= "▪️ Total Users: " . $total_users . "\n";
    $stats_text .= "▪️ Banned Users: " . $banned_users . "\n";
    $stats_text .= "\n";

    // Purchases
    $total_purchases = $pdo->query("SELECT COUNT(*) FROM purchases")->fetchColumn();
    $total_volume = $pdo->query("SELECT SUM(price) FROM purchases WHERE price > 0")->fetchColumn();

    $stats_text .= "💳 <b>Purchases & Sales:</b>\n";
    $stats_text .= "▪️ Total Purchase Records: " . $total_purchases . "\n";
    $stats_text .= "▪️ Total Sales Volume: $" . number_format($total_volume ?: 0, 2) . "\n";

    return $stats_text;
}

// ===================================================================
//  TELEGRAM API FUNCTIONS
// ===================================================================
function generateDynamicMainMenuKeyboard($is_admin_menu = false) {
    $config = getBotConfig();
    $layout_mode = $config['main_menu_layout_mode'] ?? 'auto';

    $all_buttons = [];
    $pdo = getPDO();
    if ($pdo) {
        $cats = $pdo->query("SELECT slug, name FROM categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cats as $cat) {
            $all_buttons['view_category_' . $cat['slug']] = ['text' => "🛍️ " . htmlspecialchars($cat['name']), 'callback_data' => 'view_category_' . $cat['slug']];
        }
    }

    $all_buttons[CALLBACK_MY_PRODUCTS] = ['text' => "📦 محصولات من", 'callback_data' => (string)CALLBACK_MY_PRODUCTS];
    $all_buttons[CALLBACK_SUPPORT] = ['text' => "💬 پشتیبانی", 'callback_data' => (string)CALLBACK_SUPPORT];

    if ($is_admin_menu) {
        $all_buttons[CALLBACK_ADMIN_PANEL] = ['text' => "⚙️ Admin Panel", 'callback_data' => (string)CALLBACK_ADMIN_PANEL];
    }

    if ($layout_mode === 'manual' && isset($config['main_menu_manual_layout'])) {
        $keyboard_rows = [];
        foreach ($config['main_menu_manual_layout'] as $row) {
            $keyboard_row = [];
            foreach ($row as $button_key) {
                if (isset($all_buttons[$button_key])) {
                    $keyboard_row[] = $all_buttons[$button_key];
                }
            }
            if (!empty($keyboard_row)) {
                $keyboard_rows[] = $keyboard_row;
            }
        }
    } else {
        $columns = $config['main_menu_columns'] ?? 1;
        $keyboard_rows = array_chunk(array_values($all_buttons), $columns);
    }

    $final_keyboard_structure = ['inline_keyboard' => $keyboard_rows];
    return $final_keyboard_structure;
}

function bot($method, $data = []) { $url = "https://api.telegram.org/bot" . API_TOKEN . "/" . $method; $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $data); $res = curl_exec($ch); curl_close($ch); return json_decode($res); }
function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = 'HTML') { bot('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageText($chat_id, $message_id, $text, $reply_markup = null, $parse_mode = 'HTML') { bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageCaption($chat_id, $message_id, $caption, $reply_markup = null, $parse_mode = 'HTML') { bot('editMessageCaption', ['chat_id' => $chat_id, 'message_id' => $message_id, 'caption' => $caption, 'reply_markup' => $reply_markup, 'parse_mode' => $parse_mode]); }
function editMessageReplyMarkup($chat_id, $message_id, $reply_markup = null) { bot('editMessageReplyMarkup', ['chat_id' => $chat_id, 'message_id' => $message_id, 'reply_markup' => $reply_markup]); }
function answerCallbackQuery($callback_query_id) { bot('answerCallbackQuery', ['callback_query_id' => $callback_query_id]); }

function forwardPhotoToAdmin($file_id, $caption, $original_user_id, $category_key, $product_id) {
    $admin_ids = getAdminIds();
    if(empty($admin_ids)) return;
    $admin_id = $admin_ids[0];

    $product_details = getProductDetails($category_key, $product_id);
    $product_type = $product_details['type'] ?? 'manual';

    $accept_button_text = "✅ Accept";
    $accept_button_callback_data = CALLBACK_ACCEPT_PAYMENT_PREFIX . $original_user_id . "_" . $category_key . "_" . $product_id;

    if ($product_type === 'manual') {
        $accept_button_text = "✅ Accept & Send";
        $accept_button_callback_data = CALLBACK_ACCEPT_AND_SEND_PREFIX . $original_user_id . "_" . $category_key . "_" . $product_id;
    }

    $reject_callback_data = CALLBACK_REJECT_PAYMENT_PREFIX . $original_user_id . "_" . $category_key . "_" . $product_id;

    $approval_keyboard = json_encode(['inline_keyboard' => [
        [['text' => $accept_button_text, 'callback_data' => $accept_button_callback_data],
         ['text' => "❌ Reject", 'callback_data' => $reject_callback_data]]
    ]]);
    bot('sendPhoto', ['chat_id' => $admin_id, 'photo' => $file_id, 'caption' => $caption, 'parse_mode' => 'Markdown', 'reply_markup' => $approval_keyboard]);
}

function generateCategoryKeyboard($category_key) {
    $pdo = getPDO();
    $keyboard = ['inline_keyboard' => []];

    if ($pdo) {
        $stmt = $pdo->prepare("
            SELECT p.slug, p.name, p.price
            FROM products p
            JOIN categories c ON p.category_id = c.id
            WHERE c.slug = :cat_slug
            ORDER BY p.id ASC
        ");
        $stmt->execute([':cat_slug' => $category_key]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as $prod) {
            $product_display_name = $prod['name'];
            $product_price = $prod['price'];
            $callback_value = "{$category_key}_{$prod['slug']}";
            $keyboard['inline_keyboard'][] = [['text' => "{$product_display_name} - \${$product_price}", 'callback_data' => $callback_value]];
        }
    }
    $keyboard['inline_keyboard'][] = [['text' => '🏠 برگشت به منوی اصلی', 'callback_data' => CALLBACK_BACK_TO_MAIN]];
    return json_encode($keyboard);
}

function getAdminPanelKeyboard() {
    return [
        'inline_keyboard' => [
            [['text' => "📦 Product Management", 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]],
            [['text' => "🗂️ Category Management", 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]],
            [['text' => "📊 View Bot Stats", 'callback_data' => CALLBACK_ADMIN_VIEW_STATS]],
            [['text' => "🎨 Main Menu UI", 'callback_data' => CALLBACK_ADMIN_MAIN_MENU_UI]],
            [['text' => '« Back to Main Menu', 'callback_data' => CALLBACK_BACK_TO_MAIN]]
        ]
    ];
}

function sendUserConfirmationAndMenu($chat_id, $confirmation_text, $user_id, $is_admin = null) {
    clearUserState($user_id);
    sendMessage($chat_id, $confirmation_text);
    if ($is_admin === null) {
        $is_admin = in_array($user_id, getAdminIds());
    }
    $menu_keyboard = generateDynamicMainMenuKeyboard($is_admin);
    sendMessage($chat_id, "🏠 <b>منوی اصلی</b> 👇", json_encode($menu_keyboard), 'HTML');
}

function sendAdminConfirmationAndMenu($chat_id, $confirmation_text, $user_id) {
    clearUserState($user_id);
    sendMessage($chat_id, $confirmation_text);
    $admin_keyboard = getAdminPanelKeyboard();
    sendMessage($chat_id, "⚙️ <b>Admin Panel</b> ⚙️", json_encode($admin_keyboard), 'HTML');
}

// ===================================================================
//  CALLBACK QUERY PROCESSOR
// ===================================================================
function processCallbackQuery($callback_query) {
    global $mainMenuKeyboard, $adminMenuKeyboard;
    $chat_id = $callback_query->message->chat->id;
    $user_id = $callback_query->from->id;
    $data = $callback_query->data;
    $message_id = $callback_query->message->message_id;
    $is_admin = in_array($user_id, getAdminIds());

    error_log("PROCESS_CALLBACK_QUERY: Received data: '" . $data . "' | UserID: " . $user_id);

    answerCallbackQuery($callback_query->id);

    $user_specific_data = getUserData($user_id);
    if ($user_specific_data['is_banned']) {
        sendMessage($chat_id, "⚠️ You are banned from using this bot.");
        return;
    }

    if (strpos($data, 'view_category_') === 0) {
        $category_key_view = substr($data, strlen('view_category_'));
        $category_display_name_view = ucfirst(str_replace('_', ' ', $category_key_view));

        $pdo = getPDO();
        $has_products = false;
        if ($pdo) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM products p JOIN categories c ON p.category_id = c.id WHERE c.slug = :slug");
            $stmt->execute([':slug' => $category_key_view]);
            if ($stmt->fetchColumn() > 0) $has_products = true;
        }

        if ($has_products) {
            $kb_category_products = generateCategoryKeyboard($category_key_view);
            editMessageText($chat_id, $message_id, "🛍️ لطفاً یه محصول از دسته‌ی <b>" . htmlspecialchars($category_display_name_view) . "</b> انتخاب کن:", $kb_category_products, 'HTML');
        } else {
            error_log("VIEW_CAT: Category '{$category_key_view}' is empty or not found. Data: ".$data);
            $kb_empty_cat = json_encode(['inline_keyboard' => [[['text' => '🏠 برگشت به منوی اصلی', 'callback_data' => CALLBACK_BACK_TO_MAIN]]]]);
            editMessageText($chat_id, $message_id, "😕 متأسفیم! الان توی دسته‌ی <b>" . htmlspecialchars($category_display_name_view) . "</b> محصولی موجود نیست، یا شاید همین تازگی‌ها آپدیت شده باشه.", $kb_empty_cat, 'HTML');
        }
        return;
    }

    elseif ($data === CALLBACK_MY_PRODUCTS) {
        $pdo = getPDO();
        $user_purchases_array = [];
        if ($pdo) {
            $stmt = $pdo->prepare("SELECT id, product_name, date, delivered_item_content FROM purchases WHERE user_id = :uid ORDER BY date DESC");
            $stmt->execute([':uid' => $user_id]);
            $user_purchases_array = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $message_to_send = "<b>📋 محصولاتت:</b>\nبرای دیدن جزئیات، روی هر مورد بزن 👇";
        $keyboard_button_rows = [];

        if (empty($user_purchases_array)) {
            $message_to_send = "🙁 هنوز هیچ محصولی نداری!";
        } else {
            foreach ($user_purchases_array as $purchase_item) {
                $product_name_btn = htmlspecialchars($purchase_item['product_name']);
                $purchase_date_str = $purchase_item['date'] ?? null;
                $purchase_date_btn = '📅 تاریخ نامشخص';
                if ($purchase_date_str && strtotime($purchase_date_str) !== false) {
                    $purchase_date_btn = date('d M Y', strtotime($purchase_date_str));
                }

                $emoji_btn = (isset($purchase_item['delivered_item_content']) && trim($purchase_item['delivered_item_content']) !== '') ? "📦" : "📄";

                $button_text_val = $emoji_btn . " " . $product_name_btn . " (" . $purchase_date_btn . ")";
                $keyboard_button_rows[] = [['text' => $button_text_val, 'callback_data' => CALLBACK_VIEW_PURCHASED_ITEM_PREFIX . $user_id . "_" . $purchase_item['id']]];
            }
        }

        $keyboard_button_rows[] = [['text' => '🏠 برگشت به منوی اصلی', 'callback_data' => CALLBACK_BACK_TO_MAIN]];
        $final_reply_markup = json_encode(['inline_keyboard' => $keyboard_button_rows]);

        editMessageText($chat_id, $message_id, $message_to_send, $final_reply_markup, 'HTML');
    }
    elseif (strpos($data, CALLBACK_VIEW_PURCHASED_ITEM_PREFIX) === 0) {
        answerCallbackQuery($callback_query->id);

        $payload = substr($data, strlen(CALLBACK_VIEW_PURCHASED_ITEM_PREFIX));
        $parts = explode('_', $payload);

        $text_to_display = "";
        $keyboard_markup = json_encode(['inline_keyboard' => [[['text' => '📦 محصولات من', 'callback_data' => CALLBACK_MY_PRODUCTS]]]]);

        if (count($parts) === 2) {
            $item_owner_id_from_cb = $parts[0];
            $purchase_id_from_cb = (int)$parts[1];

            if ((string)$user_id !== (string)$item_owner_id_from_cb) {
                error_log("VIEW_ITEM_DENIED: User {$user_id} attempted to view item for user {$item_owner_id_from_cb}. Denied. Callback: {$data}");
                $text_to_display = "🚫 این کار مجاز نیست.";
                editMessageText($chat_id, $message_id, $text_to_display, null, 'HTML');
                return;
            }

            $pdo = getPDO();
            $purchase_to_display = null;
            if ($pdo) {
                $stmt = $pdo->prepare("SELECT product_name, price, date, delivered_item_content FROM purchases WHERE id = :id AND user_id = :uid");
                $stmt->execute([':id' => $purchase_id_from_cb, ':uid' => $item_owner_id_from_cb]);
                $purchase_to_display = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($purchase_to_display) {
                $text_to_display = "📦 محصول: " . htmlspecialchars($purchase_to_display['product_name']) . "\n";
                $text_to_display .= "🗓 تاریخ خرید: " . htmlspecialchars($purchase_to_display['date']) . "\n";
                if (isset($purchase_to_display['price'])) {
                     $text_to_display .= "💵 قیمت: $" . htmlspecialchars($purchase_to_display['price']) . "\n";
                }
                $text_to_display .= "\n";

                if (isset($purchase_to_display['delivered_item_content']) && trim($purchase_to_display['delivered_item_content']) !== '') {
                    $text_to_display .= "📄 جزئیات محصول:\n<code>" . htmlspecialchars($purchase_to_display['delivered_item_content']) . "</code>";
                } else {
                    $text_to_display .= "ℹ️ این محصول دستی تحویل داده شده یا محتوای خاصی برای نمایش نداره.";
                }
            } else {
                $text_to_display = "❌ نتونستم این محصول خریداری‌شده رو پیدا کنم.\nممکنه حذف شده باشه یا خطایی پیش اومده باشه.";
                error_log("VIEW_ITEM_NOT_FOUND: Purchase item not found for user {$item_owner_id_from_cb} at ID {$purchase_id_from_cb}. Callback: {$data}");
            }
        } else {
            $text_to_display = "⚠️ خطا در دریافت جزئیات محصول به‌خاطر فرمت نامعتبر داده‌ها.";
            error_log("VIEW_ITEM_INVALID_FORMAT: Invalid data format for viewing purchased item. Callback: {$data}");
        }

        editMessageText($chat_id, $message_id, $text_to_display, $keyboard_markup, 'HTML');
    }
    elseif ($data === CALLBACK_SUPPORT) {
        setUserState($user_id, ['status' => STATE_AWAITING_SUPPORT_MESSAGE, 'message_id' => $message_id]);
        $support_text = "📝 لطفاً مشکلت یا سوالت رو پایین بنویس.\nپیامت برای تیم ادمین فرستاده میشه.\n\nبرای لغو، بنویس /cancel ❌";
        $cancel_keyboard = json_encode(['inline_keyboard' => [[['text' => '🚫 لغو درخواست پشتیبانی', 'callback_data' => CALLBACK_BACK_TO_MAIN]]]]);
        editMessageText($chat_id, $message_id, $support_text, $cancel_keyboard);
    }
    elseif ($data === CALLBACK_SUPPORT_CONFIRM) { /* Unused */ }

    elseif (strpos($data, 'admin_') === 0 || $data === CALLBACK_ADMIN_PANEL || $data === CALLBACK_ADMIN_PROD_MANAGEMENT || $data === CALLBACK_ADMIN_VIEW_STATS || $data === CALLBACK_ADMIN_CATEGORY_MANAGEMENT) {
        if (!$is_admin) {  sendMessage($chat_id, "Access denied."); return; }

        if ($data === CALLBACK_ADMIN_PANEL) {
            editMessageText($chat_id, $message_id, "⚙️ Admin Panel ⚙️", json_encode(getAdminPanelKeyboard()));
            return;
        }
        elseif ($data === CALLBACK_ADMIN_MAIN_MENU_UI) {
            $config = getBotConfig();
            $layout_mode = $config['main_menu_layout_mode'] ?? 'auto';

            $menu_ui_keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => ($layout_mode === 'auto' ? "✅ " : "") . "Automatic Layout", 'callback_data' => CALLBACK_ADMIN_AUTO_LAYOUT_MENU],
                        ['text' => ($layout_mode === 'manual' ? "✅ " : "") . "Manual Layout", 'callback_data' => CALLBACK_ADMIN_MANUAL_LAYOUT_MENU],
                    ],
                    [['text' => '« Back to Admin Panel', 'callback_data' => CALLBACK_ADMIN_PANEL]]
                ]
            ];
            editMessageText($chat_id, $message_id, "🎨 Main Menu Layout 🎨\n\nChoose the layout mode for the main menu.", json_encode($menu_ui_keyboard));
            return;
        }
        elseif ($data === CALLBACK_ADMIN_MANUAL_LAYOUT_MENU) {
            setUserState($user_id, ['status' => STATE_ADMIN_SETTING_MANUAL_LAYOUT, 'message_id' => $message_id]);

            $available_buttons = [];
            $pdo = getPDO();
            if ($pdo) {
                $cats = $pdo->query("SELECT slug FROM categories")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($cats as $slug) {
                    $available_buttons[] = 'view_category_' . $slug;
                }
            }
            $available_buttons[] = CALLBACK_MY_PRODUCTS;
            $available_buttons[] = CALLBACK_SUPPORT;
            $available_buttons[] = CALLBACK_ADMIN_PANEL;

            $config = getBotConfig();
            $current_layout = $config['main_menu_manual_layout'] ?? [];
            $current_layout_str = '';
            foreach ($current_layout as $row) {
                $current_layout_str .= implode(', ', $row) . "\n";
            }

            $message = "🎨 Manual Layout 🎨\n\n";
            $message .= "Available buttons:\n`" . implode("`\n`", $available_buttons) . "`\n\n";
            $message .= "Current layout:\n`" . ($current_layout_str ?: 'Not set') . "`\n\n";
            $message .= "To set the layout, send a message where each line represents a row of buttons, and button identifiers are separated by commas.\n\n";
            $message .= "For example:\n`view_category_spotify_plan, view_category_ssh_plan`\n`my_products, support`\n`admin_panel`";

            editMessageText($chat_id, $message_id, $message, json_encode(['inline_keyboard' => [[['text' => '« Back to Main Menu UI', 'callback_data' => CALLBACK_ADMIN_MAIN_MENU_UI]]]]), 'Markdown');
            return;
        }
        elseif ($data === CALLBACK_ADMIN_AUTO_LAYOUT_MENU) {
            $config = getBotConfig();
            $current_cols = $config['main_menu_columns'] ?? 1;

            $menu_ui_keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => ($current_cols == 1 ? "✅ " : "") . "1 Column", 'callback_data' => CALLBACK_ADMIN_SET_MENU_COLS_PREFIX . "1"],
                        ['text' => ($current_cols == 2 ? "✅ " : "") . "2 Columns", 'callback_data' => CALLBACK_ADMIN_SET_MENU_COLS_PREFIX . "2"],
                        ['text' => ($current_cols == 3 ? "✅ " : "") . "3 Columns", 'callback_data' => CALLBACK_ADMIN_SET_MENU_COLS_PREFIX . "3"],
                    ],
                    [['text' => '« Back to Main Menu UI', 'callback_data' => CALLBACK_ADMIN_MAIN_MENU_UI]]
                ]
            ];
            editMessageText($chat_id, $message_id, "🎨 Automatic Layout 🎨\n\nSelect the number of columns for the main menu buttons.", json_encode($menu_ui_keyboard));
            return;
        }
        elseif (strpos($data, CALLBACK_ADMIN_SET_MENU_COLS_PREFIX) === 0) {
            $parts = explode('_', $data);
            $new_cols = (int)end($parts);

            $config = getBotConfig();
            $config['main_menu_columns'] = $new_cols;
            $config['main_menu_layout_mode'] = 'auto';
            saveBotConfig($config);

            $current_cols = $new_cols;
            $menu_ui_keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => ($current_cols == 1 ? "✅ " : "") . "1 Column", 'callback_data' => CALLBACK_ADMIN_SET_MENU_COLS_PREFIX . "1"],
                        ['text' => ($current_cols == 2 ? "✅ " : "") . "2 Columns", 'callback_data' => CALLBACK_ADMIN_SET_MENU_COLS_PREFIX . "2"],
                        ['text' => ($current_cols == 3 ? "✅ " : "") . "3 Columns", 'callback_data' => CALLBACK_ADMIN_SET_MENU_COLS_PREFIX . "3"],
                    ],
                    [['text' => '« Back to Main Menu UI', 'callback_data' => CALLBACK_ADMIN_MAIN_MENU_UI]]
                ]
            ];
            editMessageText($chat_id, $message_id, "✅ Automatic layout updated to {$new_cols} columns.", json_encode($menu_ui_keyboard));
            return;
        }
        elseif ($data === CALLBACK_ADMIN_CATEGORY_MANAGEMENT) {
            $cat_mgt_keyboard = [
                'inline_keyboard' => [
                    [['text' => "➕ Add Category", 'callback_data' => CALLBACK_ADMIN_ADD_CATEGORY_PROMPT]],
                    [['text' => "✏️ Edit Category Name", 'callback_data' => CALLBACK_ADMIN_EDIT_CATEGORY_SELECT]],
                    [['text' => "➖ Remove Category", 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_SELECT]],
                    [['text' => '« Back to Admin Panel', 'callback_data' => CALLBACK_ADMIN_PANEL]]
                ]
            ];
            editMessageText($chat_id, $message_id, "🗂️ Category Management 🗂️\nSelect an action:", json_encode($cat_mgt_keyboard));
            return;
        }
        elseif ($data === CALLBACK_ADMIN_ADD_CATEGORY_PROMPT) {
            setUserState($user_id, ['status' => STATE_ADMIN_ADDING_CATEGORY_NAME, 'original_message_id' => $message_id]);
            editMessageText($chat_id, $message_id, "Enter the new category key (e.g., 'new_category_key').\n\nIt should be:\n- Unique\n- Alphanumeric characters and underscores only (a-z, 0-9, _)\n- E.g., `action_figures`, `digital_services_2`\n\nType /cancel to abort.", null);
            return;
        }
        elseif ($data === CALLBACK_ADMIN_EDIT_CATEGORY_SELECT) {
            $pdo = getPDO();
            $category_keys = [];
            if ($pdo) $category_keys = $pdo->query("SELECT slug FROM categories")->fetchAll(PDO::FETCH_COLUMN);

            $keyboard_rows = [];

            if (empty($category_keys)) {
                editMessageText($chat_id, $message_id, "No categories exist to edit.", json_encode(['inline_keyboard' => [[['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]]]]));
                return;
            }

            foreach ($category_keys as $cat_key) {
                $display_name = ucfirst(str_replace('_', ' ', $cat_key));
                $keyboard_rows[] = [['text' => "✏️ " . htmlspecialchars($display_name), 'callback_data' => CALLBACK_ADMIN_EDIT_CATEGORY_PROMPT_PREFIX . $cat_key]];
            }
            $keyboard_rows[] = [['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]];
            editMessageText($chat_id, $message_id, "Select a category to rename:", json_encode(['inline_keyboard' => $keyboard_rows]));
            return;
        }
        elseif (strpos($data, CALLBACK_ADMIN_EDIT_CATEGORY_PROMPT_PREFIX) === 0) {
            $old_category_key = substr($data, strlen(CALLBACK_ADMIN_EDIT_CATEGORY_PROMPT_PREFIX));
            setUserState($user_id, ['status' => STATE_ADMIN_EDITING_CATEGORY_NAME, 'old_category_key' => $old_category_key, 'original_message_id' => $message_id]);
            $display_old_key = htmlspecialchars(ucfirst(str_replace('_', ' ', $old_category_key)));
            editMessageText($chat_id, $message_id, "Editing category: <b>{$display_old_key}</b> (key: `{$old_category_key}`)\n\nEnter the new unique category key.\n\nIt should be alphanumeric with underscores (e.g., `new_key_1`).\nType /cancel to abort.", null, 'HTML');
            return;
        }
        elseif ($data === CALLBACK_ADMIN_REMOVE_CATEGORY_SELECT) {
            $pdo = getPDO();
            $category_stats = [];
            if ($pdo) {
                $stmt = $pdo->query("SELECT c.slug, COUNT(p.id) as count FROM categories c LEFT JOIN products p ON c.id = p.category_id GROUP BY c.id");
                $category_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $keyboard_rows = [];

            if (empty($category_stats)) {
                editMessageText($chat_id, $message_id, "No categories exist to remove.", json_encode(['inline_keyboard' => [[['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]]]]));
                return;
            }

            foreach ($category_stats as $stat) {
                $cat_key = $stat['slug'];
                $display_name = ucfirst(str_replace('_', ' ', $cat_key));
                $is_empty = ($stat['count'] == 0);
                $emoji = $is_empty ? "🗑️" : "⚠️";
                $keyboard_rows[] = [['text' => "{$emoji} " . htmlspecialchars($display_name), 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_CONFIRM_PREFIX . $cat_key]];
            }
            $keyboard_rows[] = [['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]];
            editMessageText($chat_id, $message_id, "Select a category to remove (⚠️ items in non-empty categories will also be deleted):", json_encode(['inline_keyboard' => $keyboard_rows]));
            return;
        }
        elseif (strpos($data, CALLBACK_ADMIN_REMOVE_CATEGORY_CONFIRM_PREFIX) === 0) {
            $category_to_remove = substr($data, strlen(CALLBACK_ADMIN_REMOVE_CATEGORY_CONFIRM_PREFIX));
            $pdo = getPDO();
            $cat_data = null;
            $product_count = 0;

            if ($pdo) {
                $stmt = $pdo->prepare("SELECT id, slug FROM categories WHERE slug = :slug");
                $stmt->execute([':slug' => $category_to_remove]);
                $cat_data = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($cat_data) {
                    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = :cid");
                    $stmtCount->execute([':cid' => $cat_data['id']]);
                    $product_count = $stmtCount->fetchColumn();
                }
            }

            if (!$cat_data) {
                editMessageText($chat_id, $message_id, "Error: Category '".htmlspecialchars($category_to_remove)."' not found. It might have already been removed.", json_encode(['inline_keyboard' => [[['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]]]]));
                return;
            }

            $is_empty = ($product_count == 0);
            $display_cat_name = htmlspecialchars(ucfirst(str_replace('_', ' ', $category_to_remove)));
            $kb_confirm_remove = [];

            if ($is_empty) {
                $confirm_text = "Category '<b>{$display_cat_name}</b>' (key: `{$category_to_remove}`) is empty.\nAre you sure you want to remove it?";
                $kb_confirm_remove[] = [['text' => "✅ Yes, Remove Empty Category", 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_DO_PREFIX . $category_to_remove . "_empty"]];
            } else {
                $confirm_text = "⚠️ <b>DANGER ZONE</b> ⚠️\nCategory '<b>{$display_cat_name}</b>' (key: `{$category_to_remove}`) contains <b>{$product_count} product(s)</b>.\n\nRemoving this category will also <b>PERMANENTLY DELETE ALL PRODUCTS</b> under it. This action is irreversible.\n\nAre you absolutely sure you want to proceed?";
                $kb_confirm_remove[] = [['text' => "☠️ YES, DELETE Category & {$product_count} Product(s)", 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_DO_PREFIX . $category_to_remove . "_withproducts"]];
            }
            $kb_confirm_remove[] = [['text' => "❌ No, Cancel", 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_SELECT]];
            $kb_confirm_remove[] = [['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]];
            editMessageText($chat_id, $message_id, $confirm_text, json_encode(['inline_keyboard' => $kb_confirm_remove]), 'HTML');
            return;
        }
        elseif (strpos($data, CALLBACK_ADMIN_REMOVE_CATEGORY_DO_PREFIX) === 0) {
            $parts_str = substr($data, strlen(CALLBACK_ADMIN_REMOVE_CATEGORY_DO_PREFIX));
            $last_underscore_pos = strrpos($parts_str, '_');
            if ($last_underscore_pos === false) {
                error_log("Invalid format for CALLBACK_ADMIN_REMOVE_CATEGORY_DO_PREFIX: {$data}");
                editMessageText($chat_id, $message_id, "Error processing removal command due to invalid format.", json_encode(['inline_keyboard' => [[['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]]]]));
                return;
            }

            $category_to_delete = substr($parts_str, 0, $last_underscore_pos);
            $action_type = substr($parts_str, $last_underscore_pos + 1);

            $pdo = getPDO();
            $success = false;
            $display_cat_name_deleted = htmlspecialchars(ucfirst(str_replace('_', ' ', $category_to_delete)));

            if ($pdo) {
                $stmt = $pdo->prepare("DELETE FROM categories WHERE slug = :slug");
                $success = $stmt->execute([':slug' => $category_to_delete]);
            }

            if ($success) {
                $success_message = "✅ Category '<b>{$display_cat_name_deleted}</b>' (key: `{$category_to_delete}`)";
                if ($action_type === "withproducts") {
                    $success_message .= " and all its associated products have been deleted.";
                } else {
                    $success_message .= " has been removed successfully.";
                }
                sendAdminConfirmationAndMenu($chat_id, $success_message, $user_id);
            } else {
                editMessageText($chat_id, $message_id, "⚠️ Failed to remove category '<b>{$display_cat_name_deleted}</b>' (or it didn't exist). Please check logs.", json_encode(['inline_keyboard' => [[['text' => '« Back to Category Mgt', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]]]]), 'HTML');
            }
            return;
        }
        elseif ($data === CALLBACK_ADMIN_VIEW_STATS) {
            $stats_content = generateBotStatsText();
            $keyboard_back = json_encode(['inline_keyboard' => [[['text' => '« Back to Admin Panel', 'callback_data' => CALLBACK_ADMIN_PANEL]]]]);
            editMessageText($chat_id, $message_id, $stats_content, $keyboard_back, 'HTML');
            return;
        }
        elseif ($data === CALLBACK_ADMIN_PROD_MANAGEMENT) {
            $prod_mgt_keyboard = [
                'inline_keyboard' => [
                    [['text' => "➕ Add Product", 'callback_data' => CALLBACK_ADMIN_ADD_PROD_SELECT_CATEGORY]],
                    [['text' => "✏️ Edit Product", 'callback_data' => CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY]],
                    [['text' => "➖ Remove Product", 'callback_data' => CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY]],
                    [['text' => '« Back to Admin Panel', 'callback_data' => CALLBACK_ADMIN_PANEL]]
                ]
            ];
            editMessageText($chat_id, $message_id, "📦 Product Management 📦", json_encode($prod_mgt_keyboard));
        }

        elseif ($data === CALLBACK_ADMIN_ADD_PROD_SELECT_CATEGORY) {
            $pdo = getPDO();
            $category_keys = [];
            if ($pdo) $category_keys = $pdo->query("SELECT slug FROM categories")->fetchAll(PDO::FETCH_COLUMN);

            $keyboard_rows = [];
            if(empty($category_keys)) {
                 setUserState($user_id, ['status' => STATE_ADMIN_ADDING_PROD_NAME, 'category_key' => 'default']);
                 editMessageText($chat_id, $message_id, "No categories exist yet. Adding to 'default' category.\nEnter the product name:", null); return;
            }
            foreach ($category_keys as $cat_key) {
                $keyboard_rows[] = [['text' => ucfirst(str_replace('_', ' ', $cat_key)), 'callback_data' => CALLBACK_ADMIN_AP_CAT_PREFIX . $cat_key]];
            }
            $keyboard_rows[] = [['text' => '« Back to Product Mgt', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]];
            editMessageText($chat_id, $message_id, "Select a category to add the new product to:", json_encode(['inline_keyboard' => $keyboard_rows]));
        }
        elseif (strpos($data, CALLBACK_ADMIN_AP_CAT_PREFIX) === 0) {
            $category_key = substr($data, strlen(CALLBACK_ADMIN_AP_CAT_PREFIX));
            setUserState($user_id, ['status' => STATE_ADMIN_ADDING_PROD_NAME, 'category_key' => $category_key, 'original_message_id' => $message_id]);
            editMessageText($chat_id, $message_id, "Adding to category: '".htmlspecialchars($category_key)."'.\nEnter the product name:", null);
        }
        elseif ($data === CALLBACK_ADMIN_SET_PROD_TYPE_INSTANT || $data === CALLBACK_ADMIN_SET_PROD_TYPE_MANUAL) {
            $user_state = getUserState($user_id);
            if(!$user_state || !isset($user_state['status']) || $user_state['status'] !== STATE_ADMIN_ADDING_PROD_TYPE_PROMPT) {
                return;
            }
            $user_state['new_product_type'] = ($data === CALLBACK_ADMIN_SET_PROD_TYPE_INSTANT) ? 'instant' : 'manual';
            $user_state['status'] = STATE_ADMIN_ADDING_PROD_PRICE;
            setUserState($user_id, $user_state);
            editMessageText($chat_id, $message_id, "Type set to: {$user_state['new_product_type']}.\nEnter the price for '{$user_state['new_product_name']}': (numbers only)", null);
        }

        elseif ($data === CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY) {
            $pdo = getPDO();
            $category_keys = [];
            if ($pdo) $category_keys = $pdo->query("SELECT slug FROM categories")->fetchAll(PDO::FETCH_COLUMN);

            if (empty($category_keys)) { editMessageText($chat_id, $message_id, "No categories found to edit products from.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]]]])); return; }
            $keyboard_rows = [];
            foreach ($category_keys as $ck) { $keyboard_rows[] = [['text' => ucfirst(str_replace('_', ' ', $ck)), 'callback_data' => CALLBACK_ADMIN_EP_SCAT_PREFIX . $ck]]; }
            $keyboard_rows[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]];
            editMessageText($chat_id, $message_id, "Select category to edit products from:", json_encode(['inline_keyboard' => $keyboard_rows]));
        }
        elseif (strpos($data, CALLBACK_ADMIN_EP_SCAT_PREFIX) === 0) {
            $category_key = substr($data, strlen(CALLBACK_ADMIN_EP_SCAT_PREFIX));
            $pdo = getPDO();
            $products_in_cat = [];
            if ($pdo) {
                $stmt = $pdo->prepare("SELECT p.slug, p.name, p.price FROM products p JOIN categories c ON p.category_id = c.id WHERE c.slug = :cat");
                $stmt->execute([':cat' => $category_key]);
                $products_in_cat = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($products_in_cat)) { editMessageText($chat_id, $message_id, "No products in '" . htmlspecialchars($category_key)."'.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY]]]])); return; }
            $keyboard_rows = [];
            foreach ($products_in_cat as $prod) { $keyboard_rows[] = [['text' => htmlspecialchars($prod['name']) . " (\${$prod['price']})", 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . "{$category_key}_{$prod['slug']}"]]; }
            $keyboard_rows[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY]];
            editMessageText($chat_id, $message_id, "Select product to edit in '" . htmlspecialchars($category_key) . "':", json_encode(['inline_keyboard' => $keyboard_rows]));
        }
        elseif (strpos($data, CALLBACK_ADMIN_EP_SPRO_PREFIX) === 0) {
            $ids_str = substr($data, strlen(CALLBACK_ADMIN_EP_SPRO_PREFIX));
            $category_key = null;
            $product_id = null;

            $pdo = getPDO();
            $category_keys = [];
            if ($pdo) $category_keys = $pdo->query("SELECT slug FROM categories")->fetchAll(PDO::FETCH_COLUMN);

            usort($category_keys, function($a, $b) {
                return strlen($b) - strlen($a);
            });

            foreach ($category_keys as $known_cat_key) {
                if (strpos($ids_str, $known_cat_key . '_') === 0) {
                    $category_key = $known_cat_key;
                    $product_id = substr($ids_str, strlen($known_cat_key) + 1);
                    break;
                }
            }

            if (!$category_key || !$product_id) {
                error_log("EP_SPRO_PARSE_FAIL: Failed to parse category/product from data: {$data}. Derived ids_str: {$ids_str}. Products keys evaluated: " . implode(", ", array_keys($products)));
                editMessageText($chat_id, $message_id, "Error: Could not determine product from callback data. Invalid format.", json_encode(['inline_keyboard'=>[[['text'=>'« Back', 'callback_data'=>CALLBACK_ADMIN_PROD_MANAGEMENT]]]]));
                return;
            }

            error_log("EP_SPRO_PRE_GET: Attempting getProductDetails with Category: '{$category_key}', ProductID: '{$product_id}' from Data: {$data}");

            $p = getProductDetails($category_key, $product_id);
            if (!$p) {
                error_log("EP_SPRO_NOT_FOUND: Product not found. Data: {$data}, Parsed Category: {$category_key}, Parsed ProductID: {$product_id}");
                $callback_cat_key_for_error_kb = $category_key;
                if (!isset($products[$category_key])) {
                    $callback_cat_key_for_error_kb = CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY;
                     error_log("EP_SPRO_NOT_FOUND_INVALID_CAT_FOR_KB: Parsed category '{$category_key}' not in products. Using generic callback.");
                }
                $error_kb = json_encode(['inline_keyboard' => [[['text' => '« Back to Product List', 'callback_data' => CALLBACK_ADMIN_EP_SCAT_PREFIX . $callback_cat_key_for_error_kb]]]]);
                editMessageText($chat_id, $message_id, "Error: Product '" . htmlspecialchars($product_id) . "' in category '" . htmlspecialchars($category_key) . "' not found. It might have been removed or the ID is incorrect.", $error_kb);
                return;
            }
            $kb_rows_edit_prod = [
                [['text' => "✏️ Edit Name", 'callback_data' => CALLBACK_ADMIN_EDIT_NAME_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => "💲 Edit Price", 'callback_data' => CALLBACK_ADMIN_EDIT_PRICE_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => "ℹ️ Edit Info/Description", 'callback_data' => CALLBACK_ADMIN_EDIT_INFO_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => "🔄 Edit Type (current: {$p['type']})", 'callback_data' => CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX . "{$category_key}_{$product_id}"]],
            ];
            if ($p['type'] === 'instant') {
                $item_count = count($p['items'] ?? []);
                $kb_rows_edit_prod[] = [['text' => "🗂️ Manage Instant Items ({$item_count})", 'callback_data' => CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX . "{$category_key}_{$product_id}"]];
            }
            $kb_rows_edit_prod[] = [['text' => '« Back to Product List', 'callback_data' => CALLBACK_ADMIN_EP_SCAT_PREFIX . $category_key]];
            editMessageText($chat_id, $message_id, "Editing Product: <b>".htmlspecialchars($p['name'])."</b>\nID: {$product_id}\nSelect what you want to edit:", json_encode(['inline_keyboard' => $kb_rows_edit_prod]), 'HTML');
        }
        elseif (strpos($data, CALLBACK_ADMIN_EDIT_NAME_PREFIX) === 0 || strpos($data, CALLBACK_ADMIN_EDIT_PRICE_PREFIX) === 0 || strpos($data, CALLBACK_ADMIN_EDIT_INFO_PREFIX) === 0) {
            $field_to_edit = '';
            $prefix_len = 0;
            if(strpos($data, CALLBACK_ADMIN_EDIT_NAME_PREFIX) === 0) { $field_to_edit = 'name'; $prefix_len = strlen(CALLBACK_ADMIN_EDIT_NAME_PREFIX); }
            elseif(strpos($data, CALLBACK_ADMIN_EDIT_PRICE_PREFIX) === 0) { $field_to_edit = 'price'; $prefix_len = strlen(CALLBACK_ADMIN_EDIT_PRICE_PREFIX); }
            else { $field_to_edit = 'info'; $prefix_len = strlen(CALLBACK_ADMIN_EDIT_INFO_PREFIX); }

            $ids_str = substr($data, $prefix_len);
            if (!preg_match('/^(.+)_([^_]+)$/', $ids_str, $matches_ids)) {
                error_log("Error parsing IDs for edit field '{$field_to_edit}': {$data}");
                editMessageText($chat_id, $message_id, "Error processing command. Invalid format for editing field.", null); return;
            }
            $category_key = $matches_ids[1]; $product_id = $matches_ids[2];

            $p = getProductDetails($category_key, $product_id);
            if(!$p) {
                error_log("Edit Field '{$field_to_edit}': Product not found. Cat:{$category_key}, Prod:{$product_id}, Data: {$data}");
                editMessageText($chat_id, $message_id, "Error: Product not found for editing.", null); return;
            }
            setUserState($user_id, ['status' => STATE_ADMIN_EDITING_PROD_FIELD, 'field_to_edit' => $field_to_edit, 'category_key' => $category_key, 'product_id' => $product_id, 'original_message_id' => $message_id]);
            $current_val_display = $p[$field_to_edit] ?? ($field_to_edit === 'info' ? '(empty)' : '');
            editMessageText($chat_id, $message_id, "Current product ".htmlspecialchars($field_to_edit).": \"".htmlspecialchars($current_val_display)."\"\nPlease send the new ".htmlspecialchars($field_to_edit)." for '".htmlspecialchars($p['name'])."'.\nOr type /cancel to abort.", null);
        }
        elseif (strpos($data, CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2]; $p = getProductDetails($category_key, $product_id);
            if(!$p) {
                error_log("Edit Type Prompt: Product not found. Cat:{$category_key}, Prod:{$product_id}, Data: {$data}");
                editMessageText($chat_id, $message_id, "Error: Product not found for editing type.", json_encode(['inline_keyboard' => [[['text' => '« Back to Edit Options', 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . $category_key . "_" . $product_id ]]]])); return;
            }
            $kb_edit_type = [
                [['text' => '📦 Set to Instant Delivery', 'callback_data' => CALLBACK_ADMIN_SET_TYPE_TO_INSTANT_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => '👤 Set to Manual Delivery', 'callback_data' => CALLBACK_ADMIN_SET_TYPE_TO_MANUAL_PREFIX . "{$category_key}_{$product_id}"]],
                [['text' => '« Back to Edit Options', 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . "{$category_key}_{$product_id}"]]
            ];
            editMessageText($chat_id, $message_id, "Current product type for '".htmlspecialchars($p['name'])."': <b>{$p['type']}</b>.\nSelect new delivery type:", json_encode(['inline_keyboard'=>$kb_edit_type]), 'HTML');
        }
        elseif (strpos($data, CALLBACK_ADMIN_SET_TYPE_TO_INSTANT_PREFIX) === 0 || strpos($data, CALLBACK_ADMIN_SET_TYPE_TO_MANUAL_PREFIX) === 0) {
            $new_type = (strpos($data, CALLBACK_ADMIN_SET_TYPE_TO_INSTANT_PREFIX) === 0) ? 'instant' : 'manual';
            $prefix_len_set_type = strlen(($new_type === 'instant') ? CALLBACK_ADMIN_SET_TYPE_TO_INSTANT_PREFIX : CALLBACK_ADMIN_SET_TYPE_TO_MANUAL_PREFIX);
            $ids_str_set_type = substr($data, $prefix_len_set_type);

            if (!preg_match('/^(.+)_([^_]+)$/', $ids_str_set_type, $matches_ids_set_type)) {
                error_log("Error parsing IDs for set type '{$new_type}': {$data}");
                editMessageText($chat_id, $message_id, "Error processing command to set type. Invalid format.", null); return;
            }
            $category_key = $matches_ids_set_type[1]; $product_id = $matches_ids_set_type[2];

            $pdo = getPDO();
            $success = false;

            // Get old type for message
            $old_p = getProductDetails($category_key, $product_id);
            $old_type = $old_p['type'] ?? 'unknown';

            if ($pdo) {
                $stmt = $pdo->prepare("UPDATE products p JOIN categories c ON p.category_id = c.id SET p.type = :type WHERE c.slug = :cat AND p.slug = :prod");
                $success = $stmt->execute([':type' => $new_type, ':cat' => $category_key, ':prod' => $product_id]);
            }

            if($success) {
                $p_updated_type = getProductDetails($category_key, $product_id);
                $kb_re_type = [
                    [['text' => "✏️ Edit Name", 'callback_data' => CALLBACK_ADMIN_EDIT_NAME_PREFIX . "{$category_key}_{$product_id}"]],
                    [['text' => "💲 Edit Price", 'callback_data' => CALLBACK_ADMIN_EDIT_PRICE_PREFIX . "{$category_key}_{$product_id}"]],
                    [['text' => "ℹ️ Edit Info/Description", 'callback_data' => CALLBACK_ADMIN_EDIT_INFO_PREFIX . "{$category_key}_{$product_id}"]],
                    [['text' => "🔄 Edit Type (current: {$p_updated_type['type']})", 'callback_data' => CALLBACK_ADMIN_EDIT_TYPE_PROMPT_PREFIX . "{$category_key}_{$product_id}"]],
                ];
                if ($p_updated_type['type'] === 'instant') {
                    $item_count_re = count($p_updated_type['items'] ?? []);
                    $kb_re_type[] = [['text' => "🗂️ Manage Instant Items ({$item_count_re})", 'callback_data' => CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX . "{$category_key}_{$product_id}"]];
                }
                $kb_re_type[] = [['text' => '« Back to Product List', 'callback_data' => CALLBACK_ADMIN_EP_SCAT_PREFIX . $category_key]];
                editMessageText($chat_id, $message_id, "✅ Product type for '".htmlspecialchars($p_updated_type['name'])."' changed from '{$old_type}' to '{$new_type}'.\nEditing Product: <b>".htmlspecialchars($p_updated_type['name'])."</b>", json_encode(['inline_keyboard' => $kb_re_type]), 'HTML');
            } else {
                 error_log("Set Type: Product not found or DB error. Cat:{$category_key}, Prod:{$product_id}, Data: {$data}");
                 editMessageText($chat_id, $message_id, "Error: Product not found or database error when attempting to set type.", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Product List', 'callback_data'=>CALLBACK_ADMIN_EP_SCAT_PREFIX.$category_key]]]]));
            }
        }
        elseif (strpos($data, CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2];
            $p = getProductDetails($category_key, $product_id);
            if (!$p || $p['type'] !== 'instant') {
                error_log("Manage Items: Product not instant or not found. Cat:{$category_key}, Prod:{$product_id}, Data: {$data}");
                editMessageText($chat_id, $message_id, "Error: This product is not an 'instant delivery' type or was not found.", json_encode(['inline_keyboard' => [[['text' => '« Back to Edit Options', 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . $category_key . "_" . $product_id ]]]]));
                return;
            }
            $items_count_manage = count($p['items'] ?? []);
            $kb_rows_manage_items = [[['text' => '➕ Add New Item', 'callback_data' => CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX . "{$category_key}_{$product_id}"]]];
            if ($items_count_manage > 0) $kb_rows_manage_items[] = [['text' => '➖ Remove An Item', 'callback_data' => CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX . "{$category_key}_{$product_id}"]];
            $kb_rows_manage_items[] = [['text' => '« Back to Edit Options', 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . "{$category_key}_{$product_id}"]];
            editMessageText($chat_id, $message_id, "<b>Managing Instant Items for: ".htmlspecialchars($p['name'])."</b>\nCurrently stocked: {$items_count_manage} item(s).", json_encode(['inline_keyboard' => $kb_rows_manage_items]), 'HTML');
        }
        elseif (strpos($data, CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2];
            $p = getProductDetails($category_key, $product_id);
            if (!$p || $p['type'] !== 'instant') {
                error_log("Add Inst Item Prompt: Product not instant or not found. Cat:{$category_key}, Prod:{$product_id}, Data: {$data}");
                editMessageText($chat_id, $message_id, "Error: Product not found or is not an 'instant delivery' type.", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Manage Items', 'callback_data'=>CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX."{$category_key}_{$product_id}"]]]])); return;
            }
            setUserState($user_id, ['status' => STATE_ADMIN_ADDING_SINGLE_INSTANT_ITEM, 'category_key' => $category_key, 'product_id' => $product_id, 'original_message_id' => $message_id]);
            editMessageText($chat_id, $message_id, "Please send the new item content for '".htmlspecialchars($p['name'])."'. This could be a code, a link, or account details.\nType /cancel to abort.", null);
        }
        elseif (strpos($data, CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2];
            $p = getProductDetails($category_key, $product_id);
            if (!$p || $p['type'] !== 'instant') {
                 error_log("Remove Inst Item List: Product not instant or not found. Cat:{$category_key}, Prod:{$product_id}, Data: {$data}");
                 editMessageText($chat_id, $message_id, "Error: Product not found or not an 'instant delivery' type for item removal.", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Manage Items', 'callback_data'=>CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX."{$category_key}_{$product_id}"]]]])); return;
            }
            if (empty($p['items'])) {
                editMessageText($chat_id, $message_id, "No items to remove for ".htmlspecialchars($p['name']).".", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Manage Items', 'callback_data'=>CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX."{$category_key}_{$product_id}"]]]])); return;
            }
            $kb_items_remove = [];
            foreach($p['items'] as $item) {
                $item_content = $item['content'];
                $item_id = $item['id'];
                $display_text = strlen($item_content) > 30 ? substr(htmlspecialchars($item_content),0,27).'...' : htmlspecialchars($item_content);
                $kb_items_remove[] = [['text' => "❌ {$display_text}", 'callback_data' => CALLBACK_ADMIN_REMOVE_INST_ITEM_DO_PREFIX."{$category_key}_{$product_id}_{$item_id}"]];
            }
            $kb_items_remove[] = [['text'=>'« Back to Manage Items', 'callback_data'=>CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX."{$category_key}_{$product_id}"]];
            editMessageText($chat_id, $message_id, "Select item to remove for ".htmlspecialchars($p['name']).":", json_encode(['inline_keyboard'=>$kb_items_remove]));
        }
        elseif (strpos($data, CALLBACK_ADMIN_REMOVE_INST_ITEM_DO_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_REMOVE_INST_ITEM_DO_PREFIX, '/') . '(.+)_([^_]+)_(\d+)$/', $data, $matches)) {
            $category_key = $matches[1]; $product_id = $matches[2]; $item_id_to_remove = (int)$matches[3];
            $pdo = getPDO();

            $deleted = false;
            if ($pdo) {
                // Delete item from DB
                $stmt = $pdo->prepare("DELETE FROM product_items WHERE id = :id");
                $deleted = $stmt->execute([':id' => $item_id_to_remove]);
            }

            if($deleted){
                $p_updated_after_remove = getProductDetails($category_key, $product_id);
                $items_count_after_remove = count($p_updated_after_remove['items'] ?? []);
                $kb_rows_after_remove = [[['text' => '➕ Add New Item', 'callback_data' => CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX . "{$category_key}_{$product_id}"]]];
                if ($items_count_after_remove > 0) $kb_rows_after_remove[] = [['text' => '➖ Remove An Item', 'callback_data' => CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX . "{$category_key}_{$product_id}"]];
                $kb_rows_after_remove[] = [['text' => '« Back to Edit Options', 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . "{$category_key}_{$product_id}"]];
                editMessageText($chat_id, $message_id, "✅ Item removed successfully.\n<b>Managing Instant Items for: ".htmlspecialchars($p_updated_after_remove['name'])."</b>\nCurrently stocked: {$items_count_after_remove} item(s).", json_encode(['inline_keyboard' => $kb_rows_after_remove]), 'HTML');
            } else {
                error_log("Remove Inst Item Do: Item not found or failed to delete. Cat:{$category_key}, Prod:{$product_id}, ItemID: {$item_id_to_remove}, Data: {$data}");
                editMessageText($chat_id, $message_id, "Error: Item not found or failed to delete.", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Manage Items', 'callback_data'=>CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX."{$category_key}_{$product_id}"]]]]));
            }
        }

        elseif ($data === CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY) {
            $pdo = getPDO();
            $category_keys = [];
            if ($pdo) $category_keys = $pdo->query("SELECT slug FROM categories")->fetchAll(PDO::FETCH_COLUMN);

            if (empty($category_keys)) { editMessageText($chat_id, $message_id, "No categories found to remove products from.", json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]]]])); return; }
            $keyboard_rows_rem_cat = [];
            foreach ($category_keys as $ck_rem) { $keyboard_rows_rem_cat[] = [['text' => ucfirst(str_replace('_', ' ', $ck_rem)), 'callback_data' => CALLBACK_ADMIN_RP_SCAT_PREFIX . $ck_rem]]; }
            $keyboard_rows_rem_cat[] = [['text' => '« Back to Product Mgt', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]];
            editMessageText($chat_id, $message_id, "Select category to remove product from:", json_encode(['inline_keyboard' => $keyboard_rows_rem_cat]));
        }
        elseif (strpos($data, CALLBACK_ADMIN_RP_SCAT_PREFIX) === 0) {
            $category_key_rem_prod = substr($data, strlen(CALLBACK_ADMIN_RP_SCAT_PREFIX));
            $pdo = getPDO();
            $products_in_cat = [];
            if ($pdo) {
                $stmt = $pdo->prepare("SELECT p.slug, p.name FROM products p JOIN categories c ON p.category_id = c.id WHERE c.slug = :cat");
                $stmt->execute([':cat' => $category_key_rem_prod]);
                $products_in_cat = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($products_in_cat)) { editMessageText($chat_id, $message_id, "No products in category '".htmlspecialchars($category_key_rem_prod)."' to remove.", json_encode(['inline_keyboard' => [[['text' => '« Back to Select Category', 'callback_data' => CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY]]]])); return; }
            $keyboard_rows_rem_prod = [];
            foreach ($products_in_cat as $prod) { $keyboard_rows_rem_prod[] = [['text' => "➖ ".htmlspecialchars($prod['name']), 'callback_data' => CALLBACK_ADMIN_RP_SPRO_PREFIX . "{$category_key_rem_prod}_{$prod['slug']}"]]; }
            $keyboard_rows_rem_prod[] = [['text' => '« Back to Select Category', 'callback_data' => CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY]];
            editMessageText($chat_id, $message_id, "Select product to REMOVE from '".htmlspecialchars($category_key_rem_prod)."':\n(⚠️ This action is permanent!)", json_encode(['inline_keyboard' => $keyboard_rows_rem_prod]), 'HTML');
        }
        elseif (strpos($data, CALLBACK_ADMIN_RP_SPRO_PREFIX) === 0) {
            $ids_str_rp = substr($data, strlen(CALLBACK_ADMIN_RP_SPRO_PREFIX));
            $category_key_rem_confirm = null;
            $product_id_rem_confirm = null;

            $pdo = getPDO();
            $category_keys = [];
            if ($pdo) $category_keys = $pdo->query("SELECT slug FROM categories")->fetchAll(PDO::FETCH_COLUMN);

            usort($category_keys, function($a, $b) { return strlen($b) - strlen($a); });

            foreach ($category_keys as $known_cat_key_rp) {
                if (strpos($ids_str_rp, $known_cat_key_rp . '_') === 0) {
                    $category_key_rem_confirm = $known_cat_key_rp;
                    $product_id_rem_confirm = substr($ids_str_rp, strlen($known_cat_key_rp) + 1);
                    break;
                }
            }

            if (!$category_key_rem_confirm || !$product_id_rem_confirm) {
                error_log("RP_SPRO: Failed to parse category/product from data: {$data}. Derived ids_str: {$ids_str_rp}");
                editMessageText($chat_id, $message_id, "Error: Could not determine product for removal from callback data. Invalid format.", json_encode(['inline_keyboard'=>[[['text'=>'« Back', 'callback_data'=>CALLBACK_ADMIN_PROD_MANAGEMENT]]]]));
                return;
            }

            $p_rem_confirm = getProductDetails($category_key_rem_confirm, $product_id_rem_confirm);
            if(!$p_rem_confirm) {
                error_log("RP_SPRO (Confirm): Product not found. Data: {$data}, Parsed Category: {$category_key_rem_confirm}, Parsed ProductID: {$product_id_rem_confirm}");
                $error_kb_rem_confirm = json_encode(['inline_keyboard' => [[['text' => '« Back to Product List', 'callback_data' => CALLBACK_ADMIN_RP_SCAT_PREFIX . $category_key_rem_confirm]]]]);
                editMessageText($chat_id, $message_id, "Error: Product '" . htmlspecialchars($product_id_rem_confirm) . "' in category '" . htmlspecialchars($category_key_rem_confirm) . "' not found. It might have already been removed.", $error_kb_rem_confirm);
                return;
            }
            $kb_rem_confirm = [
                [['text' => "✅ YES, REMOVE IT", 'callback_data' => CALLBACK_ADMIN_RP_CONF_YES_PREFIX."{$category_key_rem_confirm}_{$product_id_rem_confirm}"],
                 ['text' => "❌ NO, CANCEL", 'callback_data' => CALLBACK_ADMIN_RP_CONF_NO_PREFIX."{$category_key_rem_confirm}_{$product_id_rem_confirm}"]],
                [['text'=>'« Back to Product List', 'callback_data'=>CALLBACK_ADMIN_RP_SCAT_PREFIX.$category_key_rem_confirm]]
            ];
            editMessageText($chat_id, $message_id, "⚠️ Confirm Removal ⚠️\nAre you sure you want to permanently remove the product:\n<b>".htmlspecialchars($p_rem_confirm['name'])."</b>\nID: {$product_id_rem_confirm}\nCategory: ".htmlspecialchars($category_key_rem_confirm), json_encode(['inline_keyboard'=>$kb_rem_confirm]), 'HTML');
        }
        elseif (strpos($data, CALLBACK_ADMIN_RP_CONF_YES_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_RP_CONF_YES_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches_rem_yes)) {
            $category_key_do_remove = $matches_rem_yes[1];
            $product_id_do_remove = $matches_rem_yes[2];

            $pdo = getPDO();
            $success = false;
            $removed_prod_name_log = "Unknown";

            // Fetch name for log before delete
            $prod_to_del = getProductDetails($category_key_do_remove, $product_id_do_remove);
            if ($prod_to_del) $removed_prod_name_log = $prod_to_del['name'];

            if ($pdo) {
                // DELETE FROM products
                $stmt = $pdo->prepare("DELETE p FROM products p JOIN categories c ON p.category_id = c.id WHERE c.slug = :cat AND p.slug = :prod");
                $stmt->execute([':cat' => $category_key_do_remove, ':prod' => $product_id_do_remove]);
                if ($stmt->rowCount() > 0) $success = true;
            }

            if ($success) {
                sendAdminConfirmationAndMenu($chat_id, "✅ Product '".htmlspecialchars($removed_prod_name_log)."' (ID: {$product_id_do_remove}) has been removed from category '".htmlspecialchars($category_key_do_remove)."'.", $user_id);
            } else {
                editMessageText($chat_id, $message_id, "⚠️ Error: Product '".htmlspecialchars($product_id_do_remove)."' in category '".htmlspecialchars($category_key_do_remove)."' not found or failed to delete. It might have been already removed.", json_encode(['inline_keyboard'=>[[['text'=>'« Back to Product Removal', 'callback_data'=>CALLBACK_ADMIN_RP_SCAT_PREFIX . $category_key_do_remove ], ['text'=>'« Product Mgt', 'callback_data'=>CALLBACK_ADMIN_PROD_MANAGEMENT ]]]]));
            }
            return;
        }
        elseif (strpos($data, CALLBACK_ADMIN_RP_CONF_NO_PREFIX) === 0 && preg_match('/^' . preg_quote(CALLBACK_ADMIN_RP_CONF_NO_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches_rem_no)) {
            $category_key_rem_no = $matches_rem_no[1];

            $pdo = getPDO();
            $products_in_cat = [];
            if ($pdo) {
                $stmt = $pdo->prepare("SELECT p.slug, p.name FROM products p JOIN categories c ON p.category_id = c.id WHERE c.slug = :cat");
                $stmt->execute([':cat' => $category_key_rem_no]);
                $products_in_cat = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $keyboard_rows_rem_no_list = [];
            if (!empty($products_in_cat)) {
                 foreach ($products_in_cat as $prod) { $keyboard_rows_rem_no_list[] = [['text' => "➖ ".htmlspecialchars($prod['name']), 'callback_data' => CALLBACK_ADMIN_RP_SPRO_PREFIX . "{$category_key_rem_no}_{$prod['slug']}"]]; }
            }
            $keyboard_rows_rem_no_list[] = [['text' => '« Back to Select Category', 'callback_data' => CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY]];
            editMessageText($chat_id, $message_id, "Product removal cancelled. Select product to REMOVE from '".htmlspecialchars($category_key_rem_no)."':", json_encode(['inline_keyboard' => $keyboard_rows_rem_no_list]));
        }
    }
    // This is the general product selection handler
    // Attempt to parse as product selection using robust parser
    elseif (
        ($parsed_product = parseProductCallback($data)) &&
        (strpos($data, 'view_category_') !== 0) &&
        (strpos($data, 'admin_') !== 0) &&
        ($data !== CALLBACK_BACK_TO_MAIN) &&
        ($data !== CALLBACK_MY_PRODUCTS) &&
        ($data !== CALLBACK_SUPPORT) &&
        (strpos($data, CALLBACK_CONFIRM_BUY_PREFIX) !== 0) &&
        (strpos($data, CALLBACK_ACCEPT_PAYMENT_PREFIX) !== 0) &&
        (strpos($data, CALLBACK_REJECT_PAYMENT_PREFIX) !== 0) &&
        (strpos($data, CALLBACK_ACCEPT_AND_SEND_PREFIX) !== 0) &&
        (strpos($data, CALLBACK_VIEW_PURCHASED_ITEM_PREFIX) !== 0)
    ) {
        error_log("PROD_SEL_DEBUG: Product selection handler entered for data: '" . $data . "'");

        $category_key_select = $parsed_product['category'];
        $product_id_select = $parsed_product['product'];

        $product_selected = getProductDetails($category_key_select, $product_id_select);

        if ($product_selected) {
            $plan_info_text = "🛍️ محصول: " . htmlspecialchars($product_selected['name']) . "\n";
            $plan_info_text .= "💰 قیمت: $" . htmlspecialchars($product_selected['price']) . "\n";
            $plan_info_text .= "ℹ️ توضیحات: " . nl2br(htmlspecialchars($product_selected['info'] ?? 'N/A')) . "\n\n";
            $plan_info_text .= "می‌خوای این محصول رو بخری؟ 💳";
            $back_cb_data = 'view_category_' . $category_key_select;
            $kb_prod_select = json_encode(['inline_keyboard' => [
                [['text' => "✅ بله، بخرش", 'callback_data' => CALLBACK_CONFIRM_BUY_PREFIX . "{$category_key_select}_{$product_id_select}"]],
                [['text' => "🔙 برگشت به پلن‌ها", 'callback_data' => $back_cb_data ]]
            ]]);
            editMessageText($chat_id, $message_id, $plan_info_text, $kb_prod_select, 'HTML');
        } else {
             error_log("PROD_SEL_DEBUG: Product '{$category_key_select}_{$product_id_select}' not found. Data: ".$data);
             $kb_notfound_prod = json_encode(['inline_keyboard' => [[['text' => '📂 برگشت به دسته‌ها', 'callback_data' => 'view_category_' . $category_key_select ]], [['text' => '🏠 منوی اصلی', 'callback_data' => CALLBACK_BACK_TO_MAIN ]]]]);
             editMessageText($chat_id, $message_id, "😔 متأسفیم! محصول انتخاب‌شده پیدا نشد. ممکنه تازه حذف یا تغییر داده شده باشه.", $kb_notfound_prod);
        }
        return;
    }

    elseif (strpos($data, CALLBACK_CONFIRM_BUY_PREFIX) === 0) {
        $ids_str_confirm_buy = substr($data, strlen(CALLBACK_CONFIRM_BUY_PREFIX));
        if (!preg_match('/^(.+)_([^_]+)$/', $ids_str_confirm_buy, $matches_ids_confirm_buy)) {
             error_log("Error parsing IDs for confirm buy: {$data}");
             editMessageText($chat_id, $message_id, "⚠️ خطا در پردازش خرید. اطلاعات محصول درست نیست. لطفاً دوباره تلاش کن یا با پشتیبانی تماس بگیر.", json_encode(['inline_keyboard'=>[[['text'=>'🏠 منوی اصلی', 'callback_data'=>CALLBACK_BACK_TO_MAIN]]]])); return;
        }
        $category_key_confirm_buy = $matches_ids_confirm_buy[1];
        $product_id_confirm_buy = $matches_ids_confirm_buy[2];

        $product_to_buy = getProductDetails($category_key_confirm_buy, $product_id_confirm_buy);
        if ($product_to_buy) {
            setUserState($user_id, [
                'status' => STATE_AWAITING_RECEIPT,
                'message_id' => $message_id,
                'product_name' => $product_to_buy['name'],
                'price' => $product_to_buy['price'],
                'category_key' => $category_key_confirm_buy,
                'product_id' => $product_id_confirm_buy
            ]);
            $paymentDets_buy = getPaymentDetails();
            $text_buy_confirm = "💳 برای تکمیل خرید ".htmlspecialchars($product_to_buy['name'])." (قیمت: $".htmlspecialchars($product_to_buy['price']).") لطفاً مبلغ رو به حساب زیر واریز کن:\n\n";
            $text_buy_confirm .= "💳 شماره کارت: `".htmlspecialchars($paymentDets_buy['card_number'])."`\n";
            $text_buy_confirm .= "👤 به نام: `".htmlspecialchars($paymentDets_buy['card_holder'])."`\n\n";
            $text_buy_confirm .= "بعد از پرداخت، اسکرین‌شات رسید تراکنش رو توی همین چت بفرست.\nبرای لغو خرید بنویس /cancel ❌";

            // Fully reverted keyboard to only include the single Cancel button
            $cancel_button = ['text' => '🚫 لغو خرید', 'callback_data' => "{$category_key_confirm_buy}_{$product_id_confirm_buy}"];
            $kb_buy_confirm_array = [
                'inline_keyboard' => [
                    [$cancel_button]
                ]
            ];
            $kb_buy_confirm = json_encode($kb_buy_confirm_array);
            editMessageText($chat_id, $message_id, $text_buy_confirm, $kb_buy_confirm, 'HTML'); // Changed parse_mode to HTML
        } else {
            error_log("Confirm Buy: Product details not found. Cat:{$category_key_confirm_buy}, ProdID:{$product_id_confirm_buy}, Data: {$data}");
            editMessageText($chat_id, $message_id, "❌ خطا: محصولی که می‌خوای بخری پیدا نشد. ممکنه حذف یا آپدیت شده باشه. لطفاً دوباره انتخاب کن.", json_encode(['inline_keyboard'=>[[['text'=>'🏠 منوی اصلی', 'callback_data'=>CALLBACK_BACK_TO_MAIN]]]]));
        }
    }
    elseif (strpos($data, CALLBACK_ACCEPT_PAYMENT_PREFIX) === 0 || strpos($data, CALLBACK_REJECT_PAYMENT_PREFIX) === 0) {
        error_log("PAY_CONF: Entered payment confirmation handler. Data: '" . $data . "', AdminID: " . $user_id);
        if(!$is_admin) {
            sendMessage($chat_id, "Access denied for payment processing.");
            error_log("PAY_CONF: Access denied. User {$user_id} is not admin.");
            return;
        }

        $is_accept_payment = strpos($data, CALLBACK_ACCEPT_PAYMENT_PREFIX) === 0;
        $prefix_to_remove = $is_accept_payment ? CALLBACK_ACCEPT_PAYMENT_PREFIX : CALLBACK_REJECT_PAYMENT_PREFIX;
        $payload = substr($data, strlen($prefix_to_remove)); // USERID_CATKEY_PRODKEY

        // Parse USERID, CATKEY, PRODKEY from payload
        $target_user_id_payment = null;
        $category_key_payment = null;
        $product_id_payment = null;

        $first_underscore_pos = strpos($payload, '_');
        if ($first_underscore_pos === false) {
            error_log("PAY_CONF: Invalid payload format. Could not find first underscore in '{$payload}'. Full data: '{$data}'");
            editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Could not parse payment confirmation data. Please handle manually.", null, 'Markdown');
            return;
        }
        $target_user_id_payment = substr($payload, 0, $first_underscore_pos);
        $rest_of_payload = substr($payload, $first_underscore_pos + 1); // CATKEY_PRODKEY

        $last_underscore_pos = strrpos($rest_of_payload, '_');
        if ($last_underscore_pos === false) {
            error_log("PAY_CONF: Invalid payload format. Could not find last underscore in '{$rest_of_payload}'. Full data: '{$data}'");
            editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Could not parse product details from payment confirmation. Please handle manually.", null, 'Markdown');
            return;
        }
        $category_key_payment = substr($rest_of_payload, 0, $last_underscore_pos);
        $product_id_payment = substr($rest_of_payload, $last_underscore_pos + 1);

        if (!is_numeric($target_user_id_payment) || empty($category_key_payment) || empty($product_id_payment)) {
            error_log("PAY_CONF: Parsed components are invalid. UserID: '{$target_user_id_payment}', CatKey: '{$category_key_payment}', ProdID: '{$product_id_payment}'. Full data: '{$data}'");
            editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Invalid parsed details for payment confirmation. Please handle manually.", null, 'Markdown');
            return;
        }

        error_log("PAY_CONF: TargetUserID: '{$target_user_id_payment}', Category: '{$category_key_payment}', ProductID: '{$product_id_payment}', Action: " . ($is_accept_payment ? "Accept" : "Reject"));

        $original_caption_payment = $callback_query->message->caption ?? '';
        // Get product name from stored details, not just receipt, for accuracy.
        $product_details_for_msg = getProductDetails($category_key_payment, $product_id_payment);
        $product_name_for_msg = $product_details_for_msg ? $product_details_for_msg['name'] : "Unknown Product (ID: {$product_id_payment})";
        $product_price_for_msg = $product_details_for_msg ? ($product_details_for_msg['price'] ?? 'N/A') : 'N/A';

        if ($is_accept_payment) {
            $item_content_for_record = null; // Initialize content to be stored with purchase
            $admin_message_suffix = "\n\n✅ PAYMENT ACCEPTED by admin {$user_id} (@".($callback_query->from->username ?? 'N/A').").";
            $user_message = "🎉 خبر خوب! پرداختت برای «<b>".htmlspecialchars($product_name_for_msg)."</b>» تأیید شد.";

            if ($product_details_for_msg) {
                if (($product_details_for_msg['type'] ?? 'manual') === 'instant') {
                    error_log("PAY_CONF: Product '{$category_key_payment}_{$product_id_payment}' is INSTANT. Attempting to deliver.");
                    $item_to_deliver = getAndRemoveInstantProductItem($category_key_payment, $product_id_payment);
                    if ($item_to_deliver !== null) {
                        $item_content_for_record = $item_to_deliver; // Set item to be stored
                        $user_message .= "\n\n📦 اینجاست محصولت:\n<code>" . htmlspecialchars($item_to_deliver) . "</code>";
                        $admin_message_suffix .= "\n✅ Instant item delivered to user.";
                        error_log("PAY_CONF: Instant item '{$item_to_deliver}' delivered for {$category_key_payment}_{$product_id_payment} to user {$target_user_id_payment}.");
                    } else {
                        // Out of stock
                        $user_message .= "\n\n😅 محصولت آماده‌ست، ولی الان موجودی ارسال فوری تموم شده. لطفاً با پشتیبانی تماس بگیر تا زودتر رسیدگی بشه.";
                        $admin_message_suffix .= "\n⚠️ INSTANT DELIVERY FAILED: Product '{$category_key_payment}_{$product_id_payment}' is OUT OF STOCK. User {$target_user_id_payment} notified to contact support. PLEASE HANDLE MANUALLY.";
                        error_log("PAY_CONF: INSTANT DELIVERY FAILED (OUT OF STOCK) for {$category_key_payment}_{$product_id_payment} to user {$target_user_id_payment}.");
                    }
                } else { // Manual product
                    $user_message .= "\n👨‍💻 ادمین به‌زودی محصول رو به‌صورت دستی برات ارسال می‌کنه.\nمی‌تونی بعد از ثبت، اون رو توی بخش «محصولات من» ببینی.";
                    $admin_message_suffix .= "\nℹ️ This is a MANUAL delivery product. User notified.";
                    error_log("PAY_CONF: Manual product '{$category_key_payment}_{$product_id_payment}'. User {$target_user_id_payment} notified for manual delivery.");
                }
            } else { // Product details not found - critical error
                $user_message .= "\n\n🚨 خطا: نتونستیم جزئیات محصول خریداری‌شده‌ت (کد: {$product_id_payment}) رو دریافت کنیم.\nلطفاً سریعاً با پشتیبانی تماس بگیر.";
                $admin_message_suffix .= "\n\n🔥🔥 CRITICAL ERROR: Could not retrieve product details for '{$category_key_payment}_{$product_id_payment}' during payment acceptance. User {$target_user_id_payment} notified to contact support. PLEASE INVESTIGATE AND HANDLE MANUALLY.";
                error_log("PAY_CONF: CRITICAL ERROR - Product details not found for {$category_key_payment}_{$product_id_payment} for user {$target_user_id_payment}.");
            }

            // Call recordPurchase ONCE here, with all necessary info including potentially delivered item content
            recordPurchase($target_user_id_payment, $product_name_for_msg, $product_price_for_msg, $item_content_for_record);

            editMessageCaption($chat_id, $message_id, $original_caption_payment . $admin_message_suffix, null, 'Markdown');
            sendMessage($target_user_id_payment, $user_message);

        } else { // Payment Rejected
            $admin_message_suffix = "\n\n❌ PAYMENT REJECTED by admin {$user_id} (@".($callback_query->from->username ?? 'N/A').").";
            editMessageCaption($chat_id, $message_id, $original_caption_payment . $admin_message_suffix, null, 'Markdown');
            sendMessage($target_user_id_payment, "❌ متأسفیم! پرداختت برای «<b>".htmlspecialchars($product_name_for_msg)."</b>» رد شده.\nاگه فکر می‌کنی اشتباهی شده یا می‌خوای بدونی چرا، روی دکمه‌ی پشتیبانی بزن 💬");
            error_log("PAY_CONF: Payment REJECTED for user {$target_user_id_payment} for product {$category_key_payment}_{$product_id_payment}.");
        }
    }
    elseif (strpos($data, CALLBACK_ACCEPT_AND_SEND_PREFIX) === 0) {
        answerCallbackQuery($callback_query->id);
        if(!$is_admin) {
            sendMessage($chat_id, "Access denied for payment processing and sending.");
            error_log("ACCEPT_SEND_CONF: Access denied. User {$user_id} is not admin.");
            return;
        }

        $payload = substr($data, strlen(CALLBACK_ACCEPT_AND_SEND_PREFIX)); // USERID_CATKEY_PRODKEY
        // Parse USERID, CATKEY, PRODKEY from payload (same parsing as CALLBACK_ACCEPT_PAYMENT_PREFIX)
        $target_user_id_send = null; $category_key_send = null; $product_id_send = null;
        $first_underscore_pos_send = strpos($payload, '_');
        if ($first_underscore_pos_send === false) { /* error handling */ return; }
        $target_user_id_send = substr($payload, 0, $first_underscore_pos_send);
        $rest_of_payload_send = substr($payload, $first_underscore_pos_send + 1);
        $last_underscore_pos_send = strrpos($rest_of_payload_send, '_');
        if ($last_underscore_pos_send === false) { /* error handling */ return; }
        $category_key_send = substr($rest_of_payload_send, 0, $last_underscore_pos_send);
        $product_id_send = substr($rest_of_payload_send, $last_underscore_pos_send + 1);

        if (!is_numeric($target_user_id_send) || empty($category_key_send) || empty($product_id_send)) {
            error_log("ACCEPT_SEND_CONF: Parsed components are invalid. UserID: '{$target_user_id_send}', CatKey: '{$category_key_send}', ProdID: '{$product_id_send}'. Full data: '{$data}'");
            editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Invalid parsed details for accept & send. Please handle manually.", null, 'Markdown');
            return;
        }

        $product_details_send = getProductDetails($category_key_send, $product_id_send);
        if (!$product_details_send) {
            error_log("ACCEPT_SEND_CONF: Product not found {$category_key_send}_{$product_id_send}. Data: {$data}");
            editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Product details not found for accept & send. Please handle manually.", null, 'Markdown');
            return;
        }
        $product_name_send = $product_details_send['name'];
        $product_price_send = $product_details_send['price'] ?? 'N/A';

        // Record the purchase, initially with null delivered_item_content
        $purchase_index = recordPurchase($target_user_id_send, $product_name_send, $product_price_send, null);

        if ($purchase_index === false) {
            error_log("ACCEPT_SEND_CONF: Failed to record purchase for {$category_key_send}_{$product_id_send} for user {$target_user_id_send}.");
            editMessageCaption($chat_id, $message_id, ($callback_query->message->caption ?? '') . "\n\n⚠️ ERROR: Failed to record purchase during accept & send. Please handle manually.", null, 'Markdown');
            return;
        }

        // Notify the user
        sendMessage($target_user_id_send, "💰 پرداختت برای «<b>".htmlspecialchars($product_name_send)."</b>» تأیید شد.\nادمین به‌زودی با جزئیات محصول باهات تماس می‌گیره ✨");

        // Set admin state for manual send session
        setUserState($user_id, [ // $user_id is the admin's ID
            'status' => STATE_ADMIN_MANUAL_SEND_SESSION,
            'target_user_id' => $target_user_id_send,
            'purchase_category' => $category_key_send,
            'purchase_product_id' => $product_id_send,
            'purchase_index' => $purchase_index, // Store the index of the purchase
            'original_admin_msg_id' => $message_id // ID of the message with the "Accept & Send" button
        ]);

        // Also set a state for the target user to know they are in a session
        setUserState($target_user_id_send, [
            'status' => 'in_manual_send_session_with_admin', // Define this if needed, or use a flag
            'admin_id' => $user_id
        ]);


        // Edit the admin's original message (receipt photo caption)
        $admin_caption_update = ($callback_query->message->caption ?? '') . "\n\n✅ Payment accepted for ".htmlspecialchars($product_name_send).". You are now in a direct send session with User ID: {$target_user_id_send}.";
        editMessageCaption($chat_id, $message_id, $admin_caption_update, null, 'Markdown'); // Remove buttons by passing null markup

        // Send a new instructional message to the admin in their chat with the bot
        sendMessage($chat_id, "➡️ You are now live with User ID: <b>{$target_user_id_send}</b> to deliver '<b>".htmlspecialchars($product_name_send)."</b>'.\n\nReply to your own message with <code>/save</code> to store its content as the delivered item. Type <code>/end</code> when finished.", null, "HTML");

        // Send an initial message to the target user
        sendMessage($target_user_id_send, "🧑‍💻 ادمین برای محصول «<b>".htmlspecialchars($product_name_send)."</b>» بهت وصل شده.\nلطفاً منتظر پیامش بمون 🙏");
        error_log("ACCEPT_SEND_CONF: Admin {$user_id} started manual send session with user {$target_user_id_send} for product {$category_key_send}_{$product_id_send}, purchase index {$purchase_index}.");
    }
    elseif ($data === CALLBACK_BACK_TO_MAIN) {
        clearUserState($user_id);
        $first_name_main = $callback_query->from->first_name;
        $welcome_text_main = "👋 سلام " . htmlspecialchars($first_name_main) . "! خوش برگشتی به منوی اصلی 💫\nلطفاً یکی از گزینه‌ها رو انتخاب کن 👇";
        $keyboard_main_array = generateDynamicMainMenuKeyboard($is_admin);
        editMessageText($chat_id, $message_id, $welcome_text_main, json_encode($keyboard_main_array));
    }
}

// ===================================================================
//  COUPON FUNCTIONS
// ===================================================================
function getCoupon($code) {
    $pdo = getPDO();
    if (!$pdo) return null;
    $stmt = $pdo->prepare("SELECT * FROM coupons WHERE code = :code");
    $stmt->execute([':code' => $code]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function redeemCoupon($code, $user_id) {
    $pdo = getPDO();
    if (!$pdo) return false;

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM coupons WHERE code = :code FOR UPDATE");
        $stmt->execute([':code' => $code]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$coupon) {
            $pdo->rollBack();
            return "not_found";
        }

        if ($coupon['used_count'] >= $coupon['max_uses']) {
            $pdo->rollBack();
            return "limit_reached";
        }

        if ($coupon['expires_at'] && strtotime($coupon['expires_at']) < time()) {
            $pdo->rollBack();
            return "expired";
        }

        // Update usage
        $stmtUpd = $pdo->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id = :id");
        $stmtUpd->execute([':id' => $coupon['id']]);

        $pdo->commit();
        return $coupon['value']; // Return discount value

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Coupon redeem error: " . $e->getMessage());
        return false;
    }
}
?>
