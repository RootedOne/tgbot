<?php

namespace Src\Controllers;

use Src\Core\TelegramBot;
use Src\Core\Config;
use Src\Data\UserRepository;

class SupportController
{
    private $bot;
    private $config;
    private $userRepo;

    public function __construct(TelegramBot $bot, Config $config, UserRepository $userRepo)
    {
        $this->bot = $bot;
        $this->config = $config;
        $this->userRepo = $userRepo;
    }

    public function showSupportMenu(int $chatId, int $messageId, int $userId)
    {
        $this->userRepo->setState($userId, ['status' => STATE_AWAITING_SUPPORT_MESSAGE, 'message_id' => $messageId]);
        $text = "📝 پیام خود را بنویسید:\nبرای لغو: /cancel";
        $kb = json_encode(['inline_keyboard' => [[['text' => '🚫 لغو', 'callback_data' => CALLBACK_BACK_TO_MAIN]]]]);
        $this->bot->editMessageText($chatId, $messageId, $text, $kb);
    }

    public function handleSupportMessage(int $chatId, int $userId, string $text, string $username, string $fullName)
    {
        $admins = $this->config->getAdminIds();
        $msg = "📩 Support Msg\nFrom: $fullName (@$username) [$userId]\n\n$text";

        if (!empty($admins)) {
            $this->bot->sendMessage($admins[0], $msg); // Send to first admin
        }

        $this->bot->sendMessage($chatId, "✅ پیام شما ارسال شد.");
        $this->userRepo->clearState($userId);
    }

    public function startChat(int $adminId, int $targetUserId)
    {
        $this->userRepo->setState($adminId, ['chatting_with' => $targetUserId]);
        $this->userRepo->setState($targetUserId, ['chatting_with' => $adminId]);

        $this->bot->sendMessage($adminId, "Chat started with $targetUserId.");
        $this->bot->sendMessage($targetUserId, "Admin connected.");
    }

    public function sendReplyToUser(int $userId, string $text)
    {
        $replyMsg = "📩 پاسخ پشتیبانی:\n\n" . htmlspecialchars($text);
        $this->bot->sendMessage($userId, $replyMsg);
    }
}
