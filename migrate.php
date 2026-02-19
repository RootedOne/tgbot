<?php
// FILE: migrate.php
// Migration script to move data from JSON files to MySQL.

require_once 'config.php';
require_once 'functions.php'; // For getPDO() and potentially readJsonFile (though we might inline read logic to avoid dependency on functions we are about to change)

// Ensure we are in CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

echo "Starting migration...\n";

$pdo = getPDO();
if (!$pdo) {
    die("Could not connect to database. Check config.php and .env.\n");
}

// 1. Create Tables
echo "Creating tables from schema.sql...\n";
$schema = file_get_contents('schema.sql');
if (!$schema) {
    die("schema.sql not found.\n");
}

// Split schema by commands (simple split by ;)
// Note: This is a basic split, complex triggers/procedures might fail but our schema is simple.
$statements = array_filter(array_map('trim', explode(';', $schema)));

foreach ($statements as $stmt) {
    if (!empty($stmt)) {
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            echo "Error executing SQL: " . substr($stmt, 0, 50) . "...\n";
            echo "Message: " . $e->getMessage() . "\n";
            // Continue, maybe table already exists
        }
    }
}
echo "Tables created/verified.\n";

// 2. Migrate Users (user_data.json)
echo "Migrating Users...\n";
$userData = json_decode(file_get_contents('user_data.json'), true) ?: [];
$userCount = 0;

$stmtUser = $pdo->prepare("INSERT INTO users (id, balance, is_banned) VALUES (:id, :balance, :is_banned) ON DUPLICATE KEY UPDATE balance = :balance, is_banned = :is_banned");

foreach ($userData as $userId => $data) {
    // Validate User ID
    if (!is_numeric($userId)) continue;

    try {
        $stmtUser->execute([
            ':id' => $userId,
            ':balance' => $data['balance'] ?? 0,
            ':is_banned' => !empty($data['is_banned']) ? 1 : 0
        ]);
        $userCount++;
    } catch (PDOException $e) {
        echo "Failed to migrate user $userId: " . $e->getMessage() . "\n";
    }
}
echo "Migrated $userCount users.\n";


// 3. Migrate Products & Categories (products.json)
echo "Migrating Products & Categories...\n";
$productsData = json_decode(file_get_contents('products.json'), true) ?: [];
$catCount = 0;
$prodCount = 0;
$itemCount = 0;

$stmtCat = $pdo->prepare("INSERT INTO categories (slug, name) VALUES (:slug, :name) ON DUPLICATE KEY UPDATE name = :name");
$stmtProd = $pdo->prepare("INSERT INTO products (slug, category_id, name, price, type, description) VALUES (:slug, :category_id, :name, :price, :type, :description) ON DUPLICATE KEY UPDATE name=:name, price=:price, type=:type, description=:description");
$stmtGetCatId = $pdo->prepare("SELECT id FROM categories WHERE slug = :slug");
$stmtItem = $pdo->prepare("INSERT INTO product_items (product_id, content, is_sold) VALUES (:product_id, :content, 0)");

foreach ($productsData as $catKey => $products) {
    if (empty($catKey)) continue;

    // Insert Category
    $catName = ucfirst(str_replace('_', ' ', $catKey));
    $stmtCat->execute([':slug' => $catKey, ':name' => $catName]);
    $catCount++;

    // Get Category ID
    $stmtGetCatId->execute([':slug' => $catKey]);
    $catId = $stmtGetCatId->fetchColumn();

    if (!$catId) {
        echo "Error: Could not retrieve ID for category $catKey\n";
        continue;
    }

    foreach ($products as $prodKey => $prodData) {
        if (empty($prodKey)) continue;

        $type = $prodData['type'] ?? 'manual';
        // Validate ENUM
        if (!in_array($type, ['manual', 'instant'])) $type = 'manual';

        // Insert Product
        try {
            $stmtProd->execute([
                ':slug' => $prodKey,
                ':category_id' => $catId,
                ':name' => $prodData['name'] ?? 'Unknown',
                ':price' => $prodData['price'] ?? 0,
                ':type' => $type,
                ':description' => $prodData['info'] ?? null
            ]);
            $prodCount++;
            $prodId = $pdo->lastInsertId();

            // If updated (duplicate key), lastInsertId might not return the ID correctly depending on driver/config.
            // Let's fetch it explicitly to be safe for items insertion.
            $stmtGetProdId = $pdo->prepare("SELECT id FROM products WHERE slug = :slug AND category_id = :cat_id");
            $stmtGetProdId->execute([':slug' => $prodKey, ':cat_id' => $catId]);
            $prodId = $stmtGetProdId->fetchColumn();


            // Insert Product Items (Instant Delivery)
            if ($type === 'instant' && !empty($prodData['items']) && is_array($prodData['items'])) {
                foreach ($prodData['items'] as $content) {
                    if (empty(trim($content))) continue;
                    $stmtItem->execute([
                        ':product_id' => $prodId,
                        ':content' => $content
                    ]);
                    $itemCount++;
                }
            }

        } catch (PDOException $e) {
            echo "Failed to migrate product $prodKey: " . $e->getMessage() . "\n";
        }
    }
}
echo "Migrated $catCount categories, $prodCount products, $itemCount items.\n";


// 4. Migrate Purchases (user_purchases.json)
echo "Migrating Purchases...\n";
$purchasesData = json_decode(file_get_contents('user_purchases.json'), true) ?: [];
$purchaseCount = 0;

// Helper to find product ID by name (best effort)
$stmtFindProdByName = $pdo->prepare("SELECT id FROM products WHERE name = :name LIMIT 1");

$stmtPurchase = $pdo->prepare("INSERT INTO purchases (user_id, product_id, product_name, price, delivered_item_content, date) VALUES (:user_id, :product_id, :product_name, :price, :delivered_item_content, :date)");

// Begin transaction for bulk purchases
$pdo->beginTransaction();

foreach ($purchasesData as $userId => $purchases) {
    if (!is_numeric($userId)) continue;

    // Ensure user exists (if purchase exists but user not in user_data.json, create placeholder)
    $stmtCheckUser = $pdo->prepare("SELECT id FROM users WHERE id = :id");
    $stmtCheckUser->execute([':id' => $userId]);
    if (!$stmtCheckUser->fetch()) {
        $stmtCreateUser = $pdo->prepare("INSERT IGNORE INTO users (id) VALUES (:id)");
        $stmtCreateUser->execute([':id' => $userId]);
    }

    foreach ($purchases as $p) {
        $prodName = $p['product_name'] ?? 'Unknown';

        // Try to link to a product
        $stmtFindProdByName->execute([':name' => $prodName]);
        $prodId = $stmtFindProdByName->fetchColumn() ?: null;

        $date = $p['date'] ?? date('Y-m-d H:i:s');

        try {
            $stmtPurchase->execute([
                ':user_id' => $userId,
                ':product_id' => $prodId,
                ':product_name' => $prodName,
                ':price' => is_numeric($p['price'] ?? null) ? $p['price'] : 0, // Handle "Manually Added"
                ':delivered_item_content' => $p['delivered_item_content'] ?? null,
                ':date' => $date
            ]);
            $purchaseCount++;
        } catch (PDOException $e) {
             echo "Failed to migrate purchase for user $userId: " . $e->getMessage() . "\n";
        }
    }
}
$pdo->commit();
echo "Migrated $purchaseCount purchases.\n";


// 5. Migrate Coupons (coupons.json)
echo "Migrating Coupons...\n";
$couponsData = json_decode(file_get_contents('coupons.json'), true) ?: [];
$couponCount = 0;

$stmtCoupon = $pdo->prepare("INSERT INTO coupons (code, value, type, max_uses, used_count) VALUES (:code, :value, :type, :max_uses, :used_count) ON DUPLICATE KEY UPDATE value = :value");

foreach ($couponsData as $code => $cData) {
    if (empty($code)) continue;
    // Assuming JSON structure: "CODE": { "value": 10, "type": "fixed", ... }
    // Since file is empty [] I can't be sure, but this is safe guess.
    // If it's an array of objects: [ {"code": "...", ...} ] logic differs.
    // Given other files are associative keyed by ID, I'll assume that.
    // But coupons.json reads as `[]` in list_files, implying indexed array?
    // Let's assume generic object iteration.

    // If $couponsData is indexed array of objects:
    if (isset($cData['code'])) {
        $code = $cData['code'];
    }

    try {
        $stmtCoupon->execute([
            ':code' => $code,
            ':value' => $cData['value'] ?? 0,
            ':type' => $cData['type'] ?? 'fixed',
            ':max_uses' => $cData['max_uses'] ?? 1,
            ':used_count' => $cData['used_count'] ?? 0
        ]);
        $couponCount++;
    } catch (PDOException $e) {
        echo "Failed to migrate coupon $code: " . $e->getMessage() . "\n";
    }
}
echo "Migrated $couponCount coupons.\n";


// 6. Migrate User States (user_states.json)
echo "Migrating User States...\n";
$statesData = json_decode(file_get_contents('user_states.json'), true) ?: [];
$stateCount = 0;

$stmtState = $pdo->prepare("INSERT INTO user_states (user_id, state_data, updated_at) VALUES (:user_id, :state_data, NOW()) ON DUPLICATE KEY UPDATE state_data = :state_data, updated_at = NOW()");

foreach ($statesData as $userId => $state) {
    if (!is_numeric($userId)) continue;

    // Ensure user exists
    $stmtCheckUser->execute([':id' => $userId]);
    if (!$stmtCheckUser->fetch()) {
        $stmtCreateUser->execute([':id' => $userId]);
    }

    try {
        $stmtState->execute([
            ':user_id' => $userId,
            ':state_data' => json_encode($state, JSON_UNESCAPED_UNICODE)
        ]);
        $stateCount++;
    } catch (PDOException $e) {
        echo "Failed to migrate state for user $userId: " . $e->getMessage() . "\n";
    }
}
echo "Migrated $stateCount user states.\n";

echo "Migration Complete!\n";
?>
