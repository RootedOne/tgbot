<?php

namespace Src\Controllers;

use Src\Core\TelegramBot;
use Src\Core\Config;
use Src\Data\ProductRepository;
use Src\Data\UserRepository;

class OrderController
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

    public function confirmBuy(int $chatId, int $messageId, int $userId, string $categoryKey, string $productId)
    {
        $product = $this->productRepo->getProduct($categoryKey, $productId);
        if (!$product) {
            $this->bot->editMessageText($chatId, $messageId, "Product not found.");
            return;
        }

        // Set User State
        $this->userRepo->setState($userId, [
            'status' => STATE_AWAITING_RECEIPT,
            'message_id' => $messageId,
            'product_name' => $product['name'],
            'price' => $product['price'],
            'category_key' => $categoryKey,
            'product_id' => $productId
        ]);

        $payment = $this->config->getPaymentDetails();
        $text = "💳 برای خرید <b>" . htmlspecialchars($product['name']) . "</b> (" . htmlspecialchars($product['price']) . " تومان) مبلغ رو واریز کن:\n\n";
        $text .= "💳 کارت: `" . htmlspecialchars($payment['card_number']) . "`\n";
        $text .= "👤 نام: `" . htmlspecialchars($payment['card_holder']) . "`\n\n";
        $text .= "بعد از پرداخت، عکس رسید رو بفرست.\nبرای لغو: /cancel";

        // Minimal cancel button
        $kb = json_encode(['inline_keyboard' => [[['text' => '🚫 لغو', 'callback_data' => CALLBACK_BACK_TO_MAIN]]]]);
        $this->bot->editMessageText($chatId, $messageId, $text, $kb);
    }

    public function handleReceipt(int $chatId, int $userId, string $photoId, string $userName, string $fullName)
    {
        $state = $this->userRepo->getState($userId);
        if (!isset($state['category_key']) || !isset($state['product_id'])) {
            $this->bot->sendMessage($chatId, "State error. Please start over.");
            return;
        }

        $prodName = $state['product_name'] ?? 'Unknown';
        $price = $state['price'] ?? 'N/A';
        $catKey = $state['category_key'];
        $prodId = $state['product_id'];

        $adminMsg = "🧾 New Receipt\n\n▪️ Product: $prodName\n▪️ Price: $price تومان\n\n👤 User: $fullName (@$userName)\nID: `$userId`";

        $product = $this->productRepo->getProduct($catKey, $prodId);
        $type = $product['type'] ?? 'manual';

        $acceptBtnText = ($type === 'manual') ? "✅ Accept & Send" : "✅ Accept";
        $acceptCallback = ($type === 'manual') ? CALLBACK_ACCEPT_AND_SEND_PREFIX : CALLBACK_ACCEPT_PAYMENT_PREFIX;
        $acceptCallback .= "{$userId}_{$catKey}_{$prodId}";
        $rejectCallback = CALLBACK_REJECT_PAYMENT_PREFIX . "{$userId}_{$catKey}_{$prodId}";

        $kb = json_encode(['inline_keyboard' => [
            [['text' => $acceptBtnText, 'callback_data' => $acceptCallback], ['text' => "❌ Reject", 'callback_data' => $rejectCallback]]
        ]]);

        $adminIds = $this->config->getAdminIds();
        if (!empty($adminIds)) {
            $this->bot->sendPhoto($adminIds[0], $photoId, $adminMsg, $kb, 'Markdown');
        }

        $this->bot->sendMessage($chatId, "🧾 رسید دریافت شد. منتظر تأیید باش.");
        $this->userRepo->clearState($userId);
    }

    public function processPaymentDecision(int $adminChatId, int $messageId, string $data, int $adminUserId)
    {
        // Parse data: Prefix + UserId_CatKey_ProdId
        // Logic handled in Router? Or here?
        // Let's assume Router extracted the parts and called this method.
        // Wait, handling this inside one method is cleaner.

        $isAccept = strpos($data, CALLBACK_ACCEPT_PAYMENT_PREFIX) === 0;
        $isManualSend = strpos($data, CALLBACK_ACCEPT_AND_SEND_PREFIX) === 0;
        $isReject = strpos($data, CALLBACK_REJECT_PAYMENT_PREFIX) === 0;

        $prefix = '';
        if ($isAccept) $prefix = CALLBACK_ACCEPT_PAYMENT_PREFIX;
        elseif ($isManualSend) $prefix = CALLBACK_ACCEPT_AND_SEND_PREFIX;
        elseif ($isReject) $prefix = CALLBACK_REJECT_PAYMENT_PREFIX;

        $payload = substr($data, strlen($prefix));

        // Format: USERID_CATKEY_PRODID. UserID is strictly numeric.
        $firstUS = strpos($payload, '_');
        $targetUserId = substr($payload, 0, $firstUS);
        $compositeKey = substr($payload, $firstUS + 1); // CATKEY_PRODID

        // Use parsing logic to handle underscores correctly
        $parsed = $this->productRepo->parseCompositeKey($compositeKey);

        if (!$parsed) {
            $this->bot->sendMessage($adminChatId, "⚠️ Error parsing product details from payment callback.");
            return;
        }

        $catKey = $parsed['category'];
        $prodId = $parsed['product'];

        $product = $this->productRepo->getProduct($catKey, $prodId);
        $prodName = $product['name'] ?? 'Unknown';
        $price = $product['price'] ?? '0';

        if ($isReject) {
            $this->bot->editMessageCaption($adminChatId, $messageId, "❌ REJECTED by Admin {$adminUserId}");
            $this->bot->sendMessage($targetUserId, "❌ پرداختت برای '{$prodName}' رد شد. تماس با پشتیبانی.");
            return;
        }

        if ($isAccept) {
            // Instant delivery logic
            $itemContent = $this->productRepo->popInstantItem($catKey, $prodId);
            $msg = "✅ پرداختت تأیید شد: {$prodName}";
            $adminCaption = "✅ ACCEPTED. ";

            if ($itemContent) {
                $msg .= "\n\n📦 محصول:\n<code>{$itemContent}</code>";
                $adminCaption .= "Item delivered automatically.";
                $this->userRepo->addPurchase($targetUserId, ['product_name' => $prodName, 'price' => $price, 'delivered_item_content' => $itemContent]);
            } else {
                $msg .= "\n\n⚠️ موجودی تمام شده. با پشتیبانی تماس بگیر.";
                $adminCaption .= "OUT OF STOCK. User notified.";
                $this->userRepo->addPurchase($targetUserId, ['product_name' => $prodName, 'price' => $price, 'delivered_item_content' => 'OUT OF STOCK']);
            }

            $this->bot->editMessageCaption($adminChatId, $messageId, $adminCaption);
            $this->bot->sendMessage($targetUserId, $msg);
        }

        if ($isManualSend) {
            // Manual delivery logic
            $purchaseIndex = $this->userRepo->addPurchase($targetUserId, ['product_name' => $prodName, 'price' => $price]);

            $this->userRepo->setState($adminUserId, [
                'status' => STATE_ADMIN_MANUAL_SEND_SESSION,
                'target_user_id' => $targetUserId,
                'purchase_index' => $purchaseIndex
            ]);

            // Set user state
            $this->userRepo->setState($targetUserId, [
                'status' => 'in_manual_send_session_with_admin',
                'admin_id' => $adminUserId
            ]);

            $this->bot->editMessageCaption($adminChatId, $messageId, "✅ Manual Session Started with User {$targetUserId}.");
            $this->bot->sendMessage($adminChatId, "Session active. Reply /save to store content, /end to finish.");
            $this->bot->sendMessage($targetUserId, "✅ پرداخت تأیید شد. ادمین برای تحویل محصول بهت وصل شد.");
        }
    }
}
