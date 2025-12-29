<?php

namespace Src\Controllers;

use Src\Core\TelegramBot;
use Src\Core\Config;
use Src\Data\ProductRepository;
use Src\Data\UserRepository;

class AdminController
{
    private $bot;
    private $config;
    private $productRepo;
    private $userRepo;

    public function __construct(TelegramBot $bot, Config $config, ProductRepository $productRepo, UserRepository $userRepo)
    {
        $this->bot = $bot;
        $this->config = $config;
        $this->productRepo = $productRepo;
        $this->userRepo = $userRepo;
    }

    // --- Main Panel ---
    public function showPanel(int $chatId, int $messageId)
    {
        $kb = [
            'inline_keyboard' => [
                [['text' => "📦 Product Management", 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]],
                [['text' => "🗂️ Category Management", 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]],
                [['text' => "📊 View Stats", 'callback_data' => CALLBACK_ADMIN_VIEW_STATS]],
                [['text' => '« Back to Main Menu', 'callback_data' => CALLBACK_BACK_TO_MAIN]]
            ]
        ];
        $this->bot->editMessageText($chatId, $messageId, "⚙️ Admin Panel ⚙️", json_encode($kb));
    }

    public function handleCallback(int $chatId, int $messageId, string $data, int $userId)
    {
        if ($data === CALLBACK_ADMIN_PROD_MANAGEMENT) {
            $this->showProductManagement($chatId, $messageId);
        } elseif ($data === CALLBACK_ADMIN_CATEGORY_MANAGEMENT) {
            $this->showCategoryManagement($chatId, $messageId);
        } elseif ($data === CALLBACK_ADMIN_VIEW_STATS) {
            $this->showStats($chatId, $messageId);
        } elseif ($data === CALLBACK_ADMIN_ADD_PROD_SELECT_CATEGORY) {
            $this->showSelectCategoryForAdd($chatId, $messageId);
        } elseif (strpos($data, CALLBACK_ADMIN_AP_CAT_PREFIX) === 0) {
            $catKey = substr($data, strlen(CALLBACK_ADMIN_AP_CAT_PREFIX));
            $this->startAddProductFlow($userId, $chatId, $messageId, $catKey);
        } elseif ($data === CALLBACK_ADMIN_SET_PROD_TYPE_INSTANT || $data === CALLBACK_ADMIN_SET_PROD_TYPE_MANUAL) {
            $this->setProductType($userId, $chatId, $messageId, $data);
        } elseif ($data === CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY) {
             $this->showSelectCategoryForEdit($chatId, $messageId);
        } elseif (strpos($data, CALLBACK_ADMIN_EP_SCAT_PREFIX) === 0) {
             $catKey = substr($data, strlen(CALLBACK_ADMIN_EP_SCAT_PREFIX));
             $this->showSelectProductForEdit($chatId, $messageId, $catKey);
        } elseif (strpos($data, CALLBACK_ADMIN_EP_SPRO_PREFIX) === 0) {
             if (preg_match('/^' . preg_quote(CALLBACK_ADMIN_EP_SPRO_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches)) {
                 $this->showEditProductOptions($chatId, $messageId, $matches[1], $matches[2]);
             }
        } elseif (strpos($data, CALLBACK_ADMIN_EDIT_NAME_PREFIX) === 0) {
             $this->startEditField($userId, $chatId, $messageId, $data, 'name');
        } elseif (strpos($data, CALLBACK_ADMIN_EDIT_PRICE_PREFIX) === 0) {
             $this->startEditField($userId, $chatId, $messageId, $data, 'price');
        } elseif (strpos($data, CALLBACK_ADMIN_EDIT_INFO_PREFIX) === 0) {
             $this->startEditField($userId, $chatId, $messageId, $data, 'info');
        } elseif (strpos($data, CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX) === 0) {
             if (preg_match('/^' . preg_quote(CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches)) {
                 $this->showInstantItemsManager($chatId, $messageId, $matches[1], $matches[2]);
             }
        } elseif (strpos($data, CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX) === 0) {
             if (preg_match('/^' . preg_quote(CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches)) {
                 $this->startAddInstantItemFlow($userId, $chatId, $messageId, $matches[1], $matches[2]);
             }
        } elseif (strpos($data, CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX) === 0) {
             if (preg_match('/^' . preg_quote(CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches)) {
                 $this->showRemoveInstantItemList($chatId, $messageId, $matches[1], $matches[2]);
             }
        } elseif (strpos($data, CALLBACK_ADMIN_REMOVE_INST_ITEM_DO_PREFIX) === 0) {
             if (preg_match('/^' . preg_quote(CALLBACK_ADMIN_REMOVE_INST_ITEM_DO_PREFIX, '/') . '(.+)_([^_]+)_(\d+)$/', $data, $matches)) {
                 $this->removeInstantItem($chatId, $messageId, $matches[1], $matches[2], (int)$matches[3]);
             }
        } elseif (strpos($data, CALLBACK_ADMIN_ADD_CATEGORY_PROMPT) === 0) {
             $this->startAddCategoryFlow($userId, $chatId, $messageId);
        } elseif ($data === CALLBACK_ADMIN_REMOVE_CATEGORY_SELECT) {
             $this->showRemoveCategorySelect($chatId, $messageId);
        } elseif (strpos($data, CALLBACK_ADMIN_REMOVE_CATEGORY_CONFIRM_PREFIX) === 0) {
             $catKey = substr($data, strlen(CALLBACK_ADMIN_REMOVE_CATEGORY_CONFIRM_PREFIX));
             $this->removeCategory($chatId, $messageId, $catKey);
        } elseif ($data === CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY) {
             $this->showSelectCategoryForRemoveProd($chatId, $messageId);
        } elseif (strpos($data, CALLBACK_ADMIN_RP_SCAT_PREFIX) === 0) {
             $catKey = substr($data, strlen(CALLBACK_ADMIN_RP_SCAT_PREFIX));
             $this->showSelectProductForRemove($chatId, $messageId, $catKey);
        } elseif (strpos($data, CALLBACK_ADMIN_RP_SPRO_PREFIX) === 0) {
             if (preg_match('/^' . preg_quote(CALLBACK_ADMIN_RP_SPRO_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches)) {
                 $this->showConfirmRemoveProduct($chatId, $messageId, $matches[1], $matches[2]);
             }
        } elseif (strpos($data, CALLBACK_ADMIN_RP_CONF_YES_PREFIX) === 0) {
             if (preg_match('/^' . preg_quote(CALLBACK_ADMIN_RP_CONF_YES_PREFIX, '/') . '(.+)_([^_]+)$/', $data, $matches)) {
                 $this->removeProduct($chatId, $messageId, $matches[1], $matches[2]);
             }
        }
    }

    public function handleInput(int $chatId, int $userId, string $text, $messageObj)
    {
        $state = $this->userRepo->getState($userId);
        $status = $state['status'] ?? '';

        if ($text === '/cancel' && $status !== STATE_ADMIN_MANUAL_SEND_SESSION) {
            $this->userRepo->clearState($userId);
            $this->bot->sendMessage($chatId, "🚫 Operation cancelled.");
            return;
        }

        switch ($status) {
            case STATE_ADMIN_ADDING_PROD_NAME:
                $state['new_product_name'] = $text;
                $state['status'] = STATE_ADMIN_ADDING_PROD_TYPE_PROMPT;
                $this->userRepo->setState($userId, $state);
                $this->promptProductType($chatId, $state['new_product_name']);
                break;

            case STATE_ADMIN_ADDING_PROD_PRICE:
                if (!is_numeric($text) || $text < 0) {
                    $this->bot->sendMessage($chatId, "Invalid price. Numbers only.");
                    return;
                }
                $state['new_product_price'] = $text;
                $state['status'] = STATE_ADMIN_ADDING_PROD_INFO;
                $this->userRepo->setState($userId, $state);
                $this->bot->sendMessage($chatId, "Enter product info/description:");
                break;

            case STATE_ADMIN_ADDING_PROD_INFO:
                $state['new_product_info'] = $text;
                $this->userRepo->setState($userId, $state);
                if (($state['new_product_type'] ?? 'manual') === 'instant') {
                    $state['status'] = STATE_ADMIN_ADDING_PROD_INSTANT_ITEMS;
                    $state['new_product_items_buffer'] = [];
                    $this->userRepo->setState($userId, $state);
                    $this->bot->sendMessage($chatId, "Product is Instant. Send items one by one. Type /doneitems when finished.");
                } else {
                    $state['status'] = STATE_ADMIN_ADDING_PROD_ID;
                    $this->userRepo->setState($userId, $state);
                    $this->bot->sendMessage($chatId, "Product is Manual. Enter a unique Product ID (slug):");
                }
                break;

            case STATE_ADMIN_ADDING_PROD_INSTANT_ITEMS:
                if ($text === '/doneitems') {
                     $state['status'] = STATE_ADMIN_ADDING_PROD_ID;
                     $this->userRepo->setState($userId, $state);
                     $count = count($state['new_product_items_buffer'] ?? []);
                     $this->bot->sendMessage($chatId, "Received $count items. Now enter a unique Product ID:");
                } else {
                     $state['new_product_items_buffer'][] = $text;
                     $this->userRepo->setState($userId, $state);
                     $this->bot->sendMessage($chatId, "Item added. Next?");
                }
                break;

            case STATE_ADMIN_ADDING_PROD_ID:
                $prodId = trim($text);
                $catKey = $state['category_key'];

                $data = [
                    'name' => $state['new_product_name'],
                    'price' => $state['new_product_price'],
                    'info' => $state['new_product_info'],
                    'type' => $state['new_product_type'],
                    'items' => $state['new_product_items_buffer'] ?? []
                ];

                if ($this->productRepo->addProduct($catKey, $prodId, $data)) {
                    $this->bot->sendMessage($chatId, "✅ Product '$prodId' added successfully!");
                    $this->userRepo->clearState($userId);
                } else {
                    $this->bot->sendMessage($chatId, "⚠️ Failed. ID might exist. Try another ID:");
                }
                break;

            case STATE_ADMIN_EDITING_PROD_FIELD:
                $catKey = $state['category_key'];
                $prodId = $state['product_id'];
                $field = $state['field_to_edit'];
                $newVal = $text;

                if ($field === 'price' && (!is_numeric($text) || $text < 0)) {
                    $this->bot->sendMessage($chatId, "Invalid price.");
                    return;
                }

                if ($this->productRepo->updateProduct($catKey, $prodId, [$field => $newVal])) {
                    $this->bot->sendMessage($chatId, "✅ Updated $field.");
                    $this->userRepo->clearState($userId);
                    // Could redirect back to edit menu here
                } else {
                    $this->bot->sendMessage($chatId, "⚠️ Update failed.");
                }
                break;

            case STATE_ADMIN_ADDING_SINGLE_INSTANT_ITEM:
                $catKey = $state['category_key'];
                $prodId = $state['product_id'];
                if ($this->productRepo->addInstantItem($catKey, $prodId, $text)) {
                    $this->bot->sendMessage($chatId, "✅ Item added.");
                    $this->userRepo->clearState($userId);
                    // Could show manager again
                } else {
                    $this->bot->sendMessage($chatId, "⚠️ Failed to add item.");
                }
                break;

            case STATE_ADMIN_ADDING_CATEGORY_NAME:
                 $newCatKey = trim($text);
                 if (!preg_match('/^[a-zA-Z0-9_]+$/', $newCatKey)) {
                     $this->bot->sendMessage($chatId, "Invalid format. Use a-z, 0-9, _.");
                     return;
                 }
                 if ($this->productRepo->addCategory($newCatKey)) {
                     $this->bot->sendMessage($chatId, "✅ Category '$newCatKey' added.");
                     $this->userRepo->clearState($userId);
                 } else {
                     $this->bot->sendMessage($chatId, "Failed. Key might exist.");
                 }
                 break;

            case STATE_ADMIN_MANUAL_SEND_SESSION:
                 if (isset($messageObj->reply_to_message) && $text === '/save') {
                     $content = $messageObj->reply_to_message->text ?? '';
                     $targetUserId = $state['target_user_id'];
                     $idx = $state['purchase_index'];
                     $this->userRepo->updatePurchase($targetUserId, $idx, ['delivered_item_content' => $content]);
                     $this->bot->sendMessage($chatId, "✅ Content saved.");
                 } else {
                     $this->bot->copyMessage($chatId, $state['target_user_id'], $messageObj->message_id);
                 }
                 break;
        }
    }

    // --- Helpers for Display ---

    private function showProductManagement($chatId, $messageId)
    {
        $kb = [
            'inline_keyboard' => [
                [['text' => "➕ Add Product", 'callback_data' => CALLBACK_ADMIN_ADD_PROD_SELECT_CATEGORY]],
                [['text' => "✏️ Edit Product", 'callback_data' => CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY]],
                [['text' => "➖ Remove Product", 'callback_data' => CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY]],
                [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PANEL]]
            ]
        ];
        $this->bot->editMessageText($chatId, $messageId, "📦 Product Management", json_encode($kb));
    }

    private function showCategoryManagement($chatId, $messageId)
    {
        $kb = [
            'inline_keyboard' => [
                 [['text' => "➕ Add Category", 'callback_data' => CALLBACK_ADMIN_ADD_CATEGORY_PROMPT]],
                 [['text' => "➖ Remove Category", 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_SELECT]],
                 [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PANEL]]
            ]
        ];
        $this->bot->editMessageText($chatId, $messageId, "🗂️ Category Management", json_encode($kb));
    }

    private function showSelectCategoryForAdd($chatId, $messageId)
    {
        $cats = $this->productRepo->getAllCategories();
        $rows = [];
        if (empty($cats)) {
             $this->startAddProductFlow($this->bot->request('getMe')->id, $chatId, $messageId, 'default');
             return;
        }
        foreach ($cats as $c) {
            $rows[] = [['text' => ucfirst($c), 'callback_data' => CALLBACK_ADMIN_AP_CAT_PREFIX . $c]];
        }
        $rows[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]];
        $this->bot->editMessageText($chatId, $messageId, "Select Category to Add to:", json_encode(['inline_keyboard' => $rows]));
    }

    private function startAddProductFlow($userId, $chatId, $messageId, $catKey)
    {
        $this->userRepo->setState($userId, [
            'status' => STATE_ADMIN_ADDING_PROD_NAME,
            'category_key' => $catKey
        ]);
        $this->bot->editMessageText($chatId, $messageId, "Adding to '$catKey'.\nEnter Product Name:");
    }

    private function promptProductType($chatId, $prodName)
    {
        $kb = [
            'inline_keyboard' => [
                [['text' => '📦 Instant', 'callback_data' => CALLBACK_ADMIN_SET_PROD_TYPE_INSTANT]],
                [['text' => '👤 Manual', 'callback_data' => CALLBACK_ADMIN_SET_PROD_TYPE_MANUAL]]
            ]
        ];
        $this->bot->sendMessage($chatId, "Type for '$prodName':", json_encode($kb));
    }

    private function setProductType($userId, $chatId, $messageId, $data)
    {
        $state = $this->userRepo->getState($userId);
        $state['new_product_type'] = ($data === CALLBACK_ADMIN_SET_PROD_TYPE_INSTANT) ? 'instant' : 'manual';
        $state['status'] = STATE_ADMIN_ADDING_PROD_PRICE;
        $this->userRepo->setState($userId, $state);
        $this->bot->editMessageText($chatId, $messageId, "Type set to {$state['new_product_type']}.\nEnter Price:");
    }

    private function showSelectCategoryForEdit($chatId, $messageId)
    {
        $cats = $this->productRepo->getAllCategories();
        $rows = [];
        foreach ($cats as $c) {
            $rows[] = [['text' => ucfirst($c), 'callback_data' => CALLBACK_ADMIN_EP_SCAT_PREFIX . $c]];
        }
        $rows[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]];
        $this->bot->editMessageText($chatId, $messageId, "Select Category to Edit:", json_encode(['inline_keyboard' => $rows]));
    }

    private function showSelectProductForEdit($chatId, $messageId, $catKey)
    {
        $prods = $this->productRepo->getProductsByCategory($catKey);
        $rows = [];
        foreach ($prods as $id => $p) {
            $rows[] = [['text' => $p['name'], 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . "{$catKey}_{$id}"]];
        }
        $rows[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_EDIT_PROD_SELECT_CATEGORY]];
        $this->bot->editMessageText($chatId, $messageId, "Select Product in '$catKey':", json_encode(['inline_keyboard' => $rows]));
    }

    private function showEditProductOptions($chatId, $messageId, $catKey, $prodId)
    {
        $p = $this->productRepo->getProduct($catKey, $prodId);
        $kb = [
            'inline_keyboard' => [
                [['text' => "✏️ Edit Name", 'callback_data' => CALLBACK_ADMIN_EDIT_NAME_PREFIX . "{$catKey}_{$prodId}"]],
                [['text' => "💲 Edit Price", 'callback_data' => CALLBACK_ADMIN_EDIT_PRICE_PREFIX . "{$catKey}_{$prodId}"]],
                [['text' => "ℹ️ Edit Info", 'callback_data' => CALLBACK_ADMIN_EDIT_INFO_PREFIX . "{$catKey}_{$prodId}"]]
            ]
        ];

        if (($p['type'] ?? 'manual') === 'instant') {
            $count = count($p['items'] ?? []);
            $kb['inline_keyboard'][] = [['text' => "🗂️ Manage Items ($count)", 'callback_data' => CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX . "{$catKey}_{$prodId}"]];
        }

        $kb['inline_keyboard'][] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_EP_SCAT_PREFIX . $catKey]];
        $this->bot->editMessageText($chatId, $messageId, "Editing {$p['name']} ($prodId)", json_encode($kb));
    }

    private function startEditField($userId, $chatId, $messageId, $data, $field)
    {
        $prefix = '';
        if ($field === 'name') $prefix = CALLBACK_ADMIN_EDIT_NAME_PREFIX;
        if ($field === 'price') $prefix = CALLBACK_ADMIN_EDIT_PRICE_PREFIX;
        if ($field === 'info') $prefix = CALLBACK_ADMIN_EDIT_INFO_PREFIX;

        $suffix = substr($data, strlen($prefix));
        if (preg_match('/^(.+)_([^_]+)$/', $suffix, $matches)) {
            $catKey = $matches[1];
            $prodId = $matches[2];

            $this->userRepo->setState($userId, [
                'status' => STATE_ADMIN_EDITING_PROD_FIELD,
                'field_to_edit' => $field,
                'category_key' => $catKey,
                'product_id' => $prodId
            ]);
            $this->bot->editMessageText($chatId, $messageId, "Send new $field:");
        }
    }

    private function showInstantItemsManager($chatId, $messageId, $catKey, $prodId)
    {
        $p = $this->productRepo->getProduct($catKey, $prodId);
        $count = count($p['items'] ?? []);
        $kb = [
            'inline_keyboard' => [
                [['text' => "➕ Add Item", 'callback_data' => CALLBACK_ADMIN_ADD_INST_ITEM_PROMPT_PREFIX . "{$catKey}_{$prodId}"]],
                [['text' => "➖ Remove Item", 'callback_data' => CALLBACK_ADMIN_REMOVE_INST_ITEM_LIST_PREFIX . "{$catKey}_{$prodId}"]],
                [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_EP_SPRO_PREFIX . "{$catKey}_{$prodId}"]]
            ]
        ];
        $this->bot->editMessageText($chatId, $messageId, "Managing Items for {$p['name']}.\nStock: $count", json_encode($kb));
    }

    private function startAddInstantItemFlow($userId, $chatId, $messageId, $catKey, $prodId)
    {
        $this->userRepo->setState($userId, [
            'status' => STATE_ADMIN_ADDING_SINGLE_INSTANT_ITEM,
            'category_key' => $catKey,
            'product_id' => $prodId
        ]);
        $this->bot->editMessageText($chatId, $messageId, "Send content for new item:");
    }

    private function showRemoveInstantItemList($chatId, $messageId, $catKey, $prodId)
    {
        $p = $this->productRepo->getProduct($catKey, $prodId);
        $items = $p['items'] ?? [];
        $rows = [];
        foreach ($items as $idx => $item) {
            $disp = substr($item, 0, 20);
            $rows[] = [['text' => "❌ $disp", 'callback_data' => CALLBACK_ADMIN_REMOVE_INST_ITEM_DO_PREFIX . "{$catKey}_{$prodId}_{$idx}"]];
        }
        $rows[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_MANAGE_INSTANT_ITEMS_PREFIX . "{$catKey}_{$prodId}"]];
        $this->bot->editMessageText($chatId, $messageId, "Select item to remove:", json_encode(['inline_keyboard' => $rows]));
    }

    private function removeInstantItem($chatId, $messageId, $catKey, $prodId, $index)
    {
        if ($this->productRepo->removeInstantItemAtIndex($catKey, $prodId, $index)) {
             $this->showInstantItemsManager($chatId, $messageId, $catKey, $prodId);
        } else {
             $this->bot->sendMessage($chatId, "Failed to remove item.");
        }
    }

    private function startAddCategoryFlow($userId, $chatId, $messageId)
    {
        $this->userRepo->setState($userId, ['status' => STATE_ADMIN_ADDING_CATEGORY_NAME]);
        $this->bot->editMessageText($chatId, $messageId, "Enter new category key (a-z, 0-9, _):");
    }

    private function showRemoveCategorySelect($chatId, $messageId)
    {
        $cats = $this->productRepo->getAllCategories();
        $rows = [];
        foreach ($cats as $c) {
            $rows[] = [['text' => "🗑️ $c", 'callback_data' => CALLBACK_ADMIN_REMOVE_CATEGORY_CONFIRM_PREFIX . $c]];
        }
        $rows[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_CATEGORY_MANAGEMENT]];
        $this->bot->editMessageText($chatId, $messageId, "Select Category to Remove:", json_encode(['inline_keyboard' => $rows]));
    }

    private function removeCategory($chatId, $messageId, $catKey)
    {
        if ($this->productRepo->deleteCategory($catKey)) {
            $this->bot->sendMessage($chatId, "✅ Category '$catKey' deleted.");
        } else {
            $this->bot->sendMessage($chatId, "⚠️ Failed to delete.");
        }
        $this->showCategoryManagement($chatId, $messageId);
    }

    private function showSelectCategoryForRemoveProd($chatId, $messageId)
    {
        $cats = $this->productRepo->getAllCategories();
        $rows = [];
        foreach ($cats as $c) {
            $rows[] = [['text' => ucfirst($c), 'callback_data' => CALLBACK_ADMIN_RP_SCAT_PREFIX . $c]];
        }
        $rows[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PROD_MANAGEMENT]];
        $this->bot->editMessageText($chatId, $messageId, "Select Category:", json_encode(['inline_keyboard' => $rows]));
    }

    private function showSelectProductForRemove($chatId, $messageId, $catKey)
    {
        $prods = $this->productRepo->getProductsByCategory($catKey);
        $rows = [];
        foreach ($prods as $id => $p) {
            $rows[] = [['text' => "❌ " . $p['name'], 'callback_data' => CALLBACK_ADMIN_RP_SPRO_PREFIX . "{$catKey}_{$id}"]];
        }
        $rows[] = [['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_REMOVE_PROD_SELECT_CATEGORY]];
        $this->bot->editMessageText($chatId, $messageId, "Select Product to Remove:", json_encode(['inline_keyboard' => $rows]));
    }

    private function showConfirmRemoveProduct($chatId, $messageId, $catKey, $prodId)
    {
        $kb = [
            'inline_keyboard' => [
                [['text' => "✅ YES, REMOVE", 'callback_data' => CALLBACK_ADMIN_RP_CONF_YES_PREFIX . "{$catKey}_{$prodId}"]],
                [['text' => "❌ NO", 'callback_data' => CALLBACK_ADMIN_RP_SCAT_PREFIX . $catKey]]
            ]
        ];
        $this->bot->editMessageText($chatId, $messageId, "Confirm delete '$prodId'?", json_encode($kb));
    }

    private function removeProduct($chatId, $messageId, $catKey, $prodId)
    {
        if ($this->productRepo->deleteProduct($catKey, $prodId)) {
            $this->bot->sendMessage($chatId, "✅ Product deleted.");
        } else {
            $this->bot->sendMessage($chatId, "⚠️ Failed to delete.");
        }
        $this->showSelectProductForRemove($chatId, $messageId, $catKey);
    }

    public function showStats($chatId, $messageId)
    {
        $text = "📊 Stats: [See older bot logic for implementation]";
        $kb = json_encode(['inline_keyboard' => [[['text' => '« Back', 'callback_data' => CALLBACK_ADMIN_PANEL]]]]);
        $this->bot->editMessageText($chatId, $messageId, $text, $kb);
    }
}
