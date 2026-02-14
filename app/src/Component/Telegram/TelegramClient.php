<?php

declare(strict_types=1);

namespace App\Component\Telegram;

use App\Service\Config\Config;
use DI\Attribute\Injectable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

#[Injectable]
class TelegramClient
{
    private Client $http;
    private string $baseUrl;

    public function __construct(
        private Config $config,
        private LoggerInterface $logger
    ) {
        $token = $this->config->get('telegram.bot_token', '');
        $this->baseUrl = "https://api.telegram.org/bot{$token}";
        $this->http = new Client([
            'timeout' => 90,
            'connect_timeout' => 30,
        ]);
    }

    public function sendMessage(int|string $chatId, string $text, ?string $parseMode = 'HTML', ?int $messageThreadId = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
        ];
        if ($messageThreadId !== null) {
            $params['message_thread_id'] = $messageThreadId;
        }
        return $this->request('sendMessage', $params);
    }

    public function setWebhook(string $url, ?string $secretToken = null): array
    {
        $params = ['url' => $url];
        if ($secretToken) {
            $params['secret_token'] = $secretToken;
        }
        return $this->request('setWebhook', $params);
    }

    public function deleteWebhook(bool $dropPendingUpdates = false): array
    {
        return $this->request('deleteWebhook', [
            'drop_pending_updates' => $dropPendingUpdates,
        ]);
    }

    public function getMe(): array
    {
        return $this->request('getMe');
    }

    public function getWebhookInfo(): array
    {
        return $this->request('getWebhookInfo');
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): array
    {
        $params = ['callback_query_id' => $callbackQueryId];
        if ($text) {
            $params['text'] = $text;
            $params['show_alert'] = $showAlert;
        }
        return $this->request('answerCallbackQuery', $params);
    }

    public function editMessageText(
        int|string $chatId,
        int $messageId,
        string $text,
        ?string $parseMode = 'HTML',
        ?array $replyMarkup = null
    ): array {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => $parseMode,
        ];
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->request('editMessageText', $params);
    }

    public function sendMessageWithKeyboard(
        int|string $chatId,
        string $text,
        array $keyboard,
        ?string $parseMode = 'HTML',
        ?int $messageThreadId = null
    ): array {
        $replyMarkup = isset($keyboard['inline_keyboard']) ? $keyboard : ['inline_keyboard' => $keyboard];

        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'reply_markup' => json_encode($replyMarkup),
        ];
        if ($messageThreadId !== null) {
            $params['message_thread_id'] = $messageThreadId;
        }
        return $this->request('sendMessage', $params);
    }

    public function deleteMessage(int|string $chatId, int $messageId): array
    {
        return $this->request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public function splitMessage(string $text, int $limit = 4096): array
    {
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $lines = explode("\n", $text);
        $chunks = [];
        $current = '';

        foreach ($lines as $line) {
            $candidate = $current === '' ? $line : $current . "\n" . $line;
            if (mb_strlen($candidate) > $limit) {
                if ($current !== '') {
                    $chunks[] = $current;
                }
                $current = mb_strlen($line) > $limit ? mb_substr($line, 0, $limit) : $line;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function request(string $method, array $params = []): array
    {
        try {
            $response = $this->http->post("{$this->baseUrl}/{$method}", [
                'form_params' => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!($data['ok'] ?? false)) {
                $this->logger->error('Telegram API error', [
                    'method' => $method,
                    'error' => $data['description'] ?? 'Unknown error',
                ]);
            }

            return $data;
        } catch (GuzzleException $e) {
            $this->logger->error('Telegram request failed', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }
}
