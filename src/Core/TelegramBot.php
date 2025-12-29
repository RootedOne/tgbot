<?php

namespace Src\Core;

class TelegramBot
{
    private $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function request(string $method, array $data = [])
    {
        $url = "https://api.telegram.org/bot" . $this->token . "/" . $method;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        // Add timeout to prevent hanging
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $res = curl_exec($ch);

        if (curl_errno($ch)) {
            error_log("TelegramBot cURL Error: " . curl_error($ch));
            curl_close($ch);
            return null;
        }

        curl_close($ch);
        return json_decode($res);
    }

    public function sendMessage(int $chatId, string $text, $replyMarkup = null, string $parseMode = 'HTML')
    {
        return $this->request('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $replyMarkup,
            'parse_mode' => $parseMode
        ]);
    }

    public function editMessageText(int $chatId, int $messageId, string $text, $replyMarkup = null, string $parseMode = 'HTML')
    {
        return $this->request('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'reply_markup' => $replyMarkup,
            'parse_mode' => $parseMode
        ]);
    }

    public function editMessageCaption(int $chatId, int $messageId, string $caption, $replyMarkup = null, string $parseMode = 'HTML')
    {
        return $this->request('editMessageCaption', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'caption' => $caption,
            'reply_markup' => $replyMarkup,
            'parse_mode' => $parseMode
        ]);
    }

    public function editMessageReplyMarkup(int $chatId, int $messageId, $replyMarkup = null)
    {
        return $this->request('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false)
    {
        $data = ['callback_query_id' => $callbackQueryId];
        if ($text) {
            $data['text'] = $text;
            $data['show_alert'] = $showAlert;
        }
        return $this->request('answerCallbackQuery', $data);
    }

    public function sendPhoto(int $chatId, string $photoFileId, string $caption = '', $replyMarkup = null, string $parseMode = 'HTML')
    {
        return $this->request('sendPhoto', [
            'chat_id' => $chatId,
            'photo' => $photoFileId,
            'caption' => $caption,
            'reply_markup' => $replyMarkup,
            'parse_mode' => $parseMode
        ]);
    }

    public function copyMessage(int $fromChatId, int $toChatId, int $messageId)
    {
        return $this->request('copyMessage', [
            'from_chat_id' => $fromChatId,
            'chat_id' => $toChatId,
            'message_id' => $messageId
        ]);
    }
}
