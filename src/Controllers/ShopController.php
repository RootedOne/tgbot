<?php

namespace Src\Controllers;

use Src\Core\TelegramBot;
use Src\Core\Config;
use Src\Data\ProductRepository;
use Src\Data\UserRepository;
use Src\Helpers\KeyboardHelper;

class ShopController
{
    private $bot;
    private $productRepo;
    private $userRepo;
    private $keyboardHelper;

    public function __construct(TelegramBot $bot, ProductRepository $productRepo, UserRepository $userRepo, KeyboardHelper $keyboardHelper)
    {
        $this->bot = $bot;
        $this->productRepo = $productRepo;
        $this->userRepo = $userRepo;
        $this->keyboardHelper = $keyboardHelper;
    }

    public function showMainMenu(int $chatId, string $firstName, bool $isAdmin)
    {
        $text = "👋 سلام " . htmlspecialchars($firstName) . "! خوش اومدی به فروشگاه 💫\nلطفاً یکی از گزینه‌ها رو انتخاب کن 👇";
        $keyboard = $this->keyboardHelper->generateMainMenu($isAdmin);
        $this->bot->sendMessage($chatId, $text, $keyboard);
    }

    public function handleBackToMain(int $chatId, int $messageId, string $firstName, bool $isAdmin)
    {
        $text = "👋 سلام " . htmlspecialchars($firstName) . "! خوش برگشتی به منوی اصلی 💫\nلطفاً یکی از گزینه‌ها رو انتخاب کن 👇";
        $keyboard = $this->keyboardHelper->generateMainMenu($isAdmin);
        $this->bot->editMessageText($chatId, $messageId, $text, $keyboard);
    }

    public function viewCategory(int $chatId, int $messageId, string $categoryKey)
    {
        $products = $this->productRepo->getProductsByCategory($categoryKey);
        $displayName = ucfirst(str_replace('_', ' ', $categoryKey));

        if (empty($products)) {
            $kb = json_encode(['inline_keyboard' => [[['text' => '🏠 برگشت به منوی اصلی', 'callback_data' => CALLBACK_BACK_TO_MAIN]]]]);
            $this->bot->editMessageText($chatId, $messageId, "😕 متأسفیم! الان توی دسته‌ی <b>" . htmlspecialchars($displayName) . "</b> محصولی موجود نیست.", $kb);
            return;
        }

        $keyboard = ['inline_keyboard' => []];
        foreach ($products as $id => $details) {
            $name = $details['name'];
            $price = $details['price'];
            $keyboard['inline_keyboard'][] = [['text' => "{$name} - \${$price}", 'callback_data' => "{$categoryKey}_{$id}"]];
        }
        $keyboard['inline_keyboard'][] = [['text' => '🏠 برگشت به منوی اصلی', 'callback_data' => CALLBACK_BACK_TO_MAIN]];

        $this->bot->editMessageText($chatId, $messageId, "🛍️ لطفاً یه محصول از دسته‌ی <b>" . htmlspecialchars($displayName) . "</b> انتخاب کن:", json_encode($keyboard));
    }

    public function viewProduct(int $chatId, int $messageId, string $categoryKey, string $productId)
    {
        $product = $this->productRepo->getProduct($categoryKey, $productId);
        if (!$product) {
            $kb = json_encode(['inline_keyboard' => [[['text' => '🏠 برگشت به منوی اصلی', 'callback_data' => CALLBACK_BACK_TO_MAIN]]]]);
            $this->bot->editMessageText($chatId, $messageId, "😔 محصول پیدا نشد.", $kb);
            return;
        }

        $text = "🛍️ محصول: " . htmlspecialchars($product['name']) . "\n";
        $text .= "💰 قیمت: $" . htmlspecialchars($product['price']) . "\n";
        $text .= "ℹ️ توضیحات: " . nl2br(htmlspecialchars($product['info'] ?? 'N/A')) . "\n\n";
        $text .= "می‌خوای این محصول رو بخری؟ 💳";

        $keyboard = json_encode(['inline_keyboard' => [
            [['text' => "✅ بله، بخرش", 'callback_data' => CALLBACK_CONFIRM_BUY_PREFIX . "{$categoryKey}_{$productId}"]],
            [['text' => "🔙 برگشت", 'callback_data' => 'view_category_' . $categoryKey]]
        ]]);

        $this->bot->editMessageText($chatId, $messageId, $text, $keyboard);
    }

    public function showMyProducts(int $chatId, int $messageId, int $userId)
    {
        $purchases = $this->userRepo->getPurchases($userId);

        if (empty($purchases)) {
            $text = "🙁 هنوز هیچ محصولی نداری!";
            $kb = json_encode(['inline_keyboard' => [[['text' => '🏠 برگشت به منوی اصلی', 'callback_data' => CALLBACK_BACK_TO_MAIN]]]]);
            $this->bot->editMessageText($chatId, $messageId, $text, $kb);
            return;
        }

        $text = "<b>📋 محصولاتت:</b>\nبرای دیدن جزئیات، روی هر مورد بزن 👇";
        $rows = [];
        foreach ($purchases as $index => $item) {
            $name = htmlspecialchars($item['product_name']);
            $date = isset($item['date']) ? date('d M Y', strtotime($item['date'])) : 'Unknown';
            $emoji = !empty($item['delivered_item_content']) ? "📦" : "📄";
            $rows[] = [['text' => "$emoji $name ($date)", 'callback_data' => CALLBACK_VIEW_PURCHASED_ITEM_PREFIX . "{$userId}_{$index}"]];
        }
        $rows[] = [['text' => '🏠 برگشت به منوی اصلی', 'callback_data' => CALLBACK_BACK_TO_MAIN]];

        $this->bot->editMessageText($chatId, $messageId, $text, json_encode(['inline_keyboard' => $rows]));
    }

    public function viewPurchasedItem(int $chatId, int $messageId, int $userId, int $ownerId, int $index)
    {
        if ($userId !== $ownerId) {
            $this->bot->answerCallbackQuery($messageId, "Unauthorized", true); // Should pass callback query ID ideally
            return;
        }

        $item = $this->userRepo->getPurchase($userId, $index);
        if (!$item) {
            $this->bot->editMessageText($chatId, $messageId, "Item not found.");
            return;
        }

        $text = "📦 محصول: " . htmlspecialchars($item['product_name']) . "\n";
        $text .= "🗓 تاریخ: " . ($item['date'] ?? 'N/A') . "\n";
        $text .= "💵 قیمت: $" . ($item['price'] ?? 'N/A') . "\n\n";

        if (!empty($item['delivered_item_content'])) {
            $text .= "📄 جزئیات:\n<code>" . htmlspecialchars($item['delivered_item_content']) . "</code>";
        } else {
            $text .= "ℹ️ تحویل دستی یا بدون محتوا.";
        }

        $kb = json_encode(['inline_keyboard' => [[['text' => '📦 محصولات من', 'callback_data' => CALLBACK_MY_PRODUCTS]]]]);
        $this->bot->editMessageText($chatId, $messageId, $text, $kb);
    }
}
