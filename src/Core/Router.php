<?php

namespace Src\Core;

use Src\Controllers\AdminController;
use Src\Controllers\ShopController;
use Src\Controllers\OrderController;
use Src\Controllers\SupportController;
use Src\Data\ProductRepository;
use Src\Data\UserRepository;

class Router
{
    private $bot;
    private $config;
    private $productRepo;
    private $userRepo;

    private $shopController;
    private $orderController;
    private $adminController;
    private $supportController;

    public function __construct(
        TelegramBot $bot,
        Config $config,
        ProductRepository $productRepo,
        UserRepository $userRepo,
        ShopController $shopController,
        OrderController $orderController,
        AdminController $adminController,
        SupportController $supportController
    ) {
        $this->bot = $bot;
        $this->config = $config;
        $this->productRepo = $productRepo;
        $this->userRepo = $userRepo;
        $this->shopController = $shopController;
        $this->orderController = $orderController;
        $this->adminController = $adminController;
        $this->supportController = $supportController;
    }

    public function handle($update)
    {
        if (isset($update->callback_query)) {
            $this->handleCallback($update->callback_query);
        } elseif (isset($update->message)) {
            $this->handleMessage($update->message);
        }
    }

    private function handleCallback($callback)
    {
        $chatId = $callback->message->chat->id;
        $messageId = $callback->message->message_id;
        $data = $callback->data;
        $userId = $callback->from->id;

        // Security: Check Banned Status
        if ($this->userRepo->isBanned($userId)) {
            $this->bot->answerCallbackQuery($callback->id, "⛔ You are banned.", true);
            return;
        }

        $isAdmin = $this->config->isAdmin($userId);
        $firstName = $callback->from->first_name ?? 'User';

        $this->bot->answerCallbackQuery($callback->id);

        // --- Admin Callbacks ---
        if ($isAdmin && (strpos($data, 'admin_') === 0 || $data === CALLBACK_ADMIN_PANEL)) {
            $this->adminController->handleCallback($chatId, $messageId, $data, $userId);
            return;
        }

        // --- Payment Decisions (Admin) ---
        if ($isAdmin && (strpos($data, CALLBACK_ACCEPT_PAYMENT_PREFIX) === 0 || strpos($data, CALLBACK_REJECT_PAYMENT_PREFIX) === 0 || strpos($data, CALLBACK_ACCEPT_AND_SEND_PREFIX) === 0)) {
             $this->orderController->processPaymentDecision($chatId, $messageId, $data, $userId);
             return;
        }

        // --- User Callbacks ---
        if ($data === CALLBACK_BACK_TO_MAIN) {
            $this->shopController->handleBackToMain($chatId, $messageId, $firstName, $isAdmin);
            return;
        }

        if ($data === CALLBACK_MY_PRODUCTS) {
            $this->shopController->showMyProducts($chatId, $messageId, $userId);
            return;
        }

        if ($data === CALLBACK_SUPPORT) {
            $this->supportController->showSupportMenu($chatId, $messageId, $userId);
            return;
        }

        if (strpos($data, 'view_category_') === 0) {
            $catKey = substr($data, 14);
            $this->shopController->viewCategory($chatId, $messageId, $catKey);
            return;
        }

        if (strpos($data, CALLBACK_CONFIRM_BUY_PREFIX) === 0) {
            $compositeKey = substr($data, strlen(CALLBACK_CONFIRM_BUY_PREFIX));
            $parsed = $this->productRepo->parseCompositeKey($compositeKey);
            if ($parsed) {
                $this->orderController->confirmBuy($chatId, $messageId, $userId, $parsed['category'], $parsed['product']);
            }
            return;
        }

        if (strpos($data, CALLBACK_VIEW_PURCHASED_ITEM_PREFIX) === 0) {
             // For purchased items, the ID is UserID_Index. UserID doesn't contain underscores usually, but let's be safe.
             // Actually format is: PREFIX . USERID . '_' . INDEX
             // UserID is strictly numeric, Index is strictly numeric. So explode works here.
             $parts = explode('_', substr($data, strlen(CALLBACK_VIEW_PURCHASED_ITEM_PREFIX)));
             if (count($parts) >= 2) {
                 $this->shopController->viewPurchasedItem($chatId, $messageId, $userId, (int)$parts[0], (int)$parts[1]);
             }
             return;
        }

        // --- Generic Product View (Last Resort) ---
        // Try parsing as composite key first (Category_ProductID)
        $parsed = $this->productRepo->parseCompositeKey($data);
        if ($parsed) {
             $this->shopController->viewProduct($chatId, $messageId, $parsed['category'], $parsed['product']);
        }
    }

    private function handleMessage($message)
    {
        $chatId = $message->chat->id;
        $userId = $message->from->id;
        $text = $message->text ?? '';
        $firstName = $message->from->first_name ?? 'User';

        // Security: Check Banned Status
        if ($this->userRepo->isBanned($userId)) {
            $this->bot->sendMessage($chatId, "⛔ You are banned from using this bot.");
            return;
        }

        $isAdmin = $this->config->isAdmin($userId);

        if ($text === '/start') {
            $this->shopController->showMainMenu($chatId, $firstName, $isAdmin);
            return;
        }

        // --- Admin Reply to Support Message ---
        if ($isAdmin && isset($message->reply_to_message)) {
            $replyText = $message->reply_to_message->text ?? '';
            // Pattern matches the message sent in SupportController: "From: ... [USERID]"
            // We look for [DIGITS]
            if (preg_match('/\[(\d+)\]/', $replyText, $matches)) {
                $targetUserId = (int)$matches[1];
                $this->supportController->sendReplyToUser($targetUserId, $text);
                $this->bot->sendMessage($chatId, "✅ Reply sent to user $targetUserId.");
                return;
            }
        }

        // --- State Handling ---
        $state = $this->userRepo->getState($userId);
        if ($state) {
            $status = $state['status'] ?? '';

            // Admin State Handling
            if ($isAdmin && (strpos($status, 'admin_') === 0 || strpos($status, 'state_admin_') === 0)) {
                if ($status === STATE_ADMIN_MANUAL_SEND_SESSION) {
                     if ($text === '/end') {
                         $this->userRepo->clearState($userId);
                         if (isset($state['target_user_id'])) {
                             $this->userRepo->clearState($state['target_user_id']);
                         }
                         $this->bot->sendMessage($chatId, "Session Ended.");
                         return;
                     }
                     $this->adminController->handleInput($chatId, $userId, $text, $message);
                     return;
                }

                $this->adminController->handleInput($chatId, $userId, $text, $message);
                return;
            }

            // User State Handling
            if ($status === STATE_AWAITING_RECEIPT && isset($message->photo)) {
                $photoId = end($message->photo)->file_id;
                $username = $message->from->username ?? 'N/A';
                $fullName = $firstName . ' ' . ($message->from->last_name ?? '');
                $this->orderController->handleReceipt($chatId, $userId, $photoId, $username, $fullName);
                return;
            }

            if ($status === STATE_AWAITING_SUPPORT_MESSAGE) {
                if ($text === '/cancel') {
                    $this->userRepo->clearState($userId);
                    $this->bot->sendMessage($chatId, "Cancelled.");
                    return;
                }
                $username = $message->from->username ?? 'N/A';
                $fullName = $firstName . ' ' . ($message->from->last_name ?? '');
                $this->supportController->handleSupportMessage($chatId, $userId, $text, $username, $fullName);
                return;
            }

            if ($status === 'in_manual_send_session_with_admin') {
                if (isset($state['admin_id'])) {
                    $this->bot->copyMessage($state['admin_id'], $chatId, $message->message_id);
                }
                return;
            }
        }
    }
}
