<?php

declare(strict_types=1);

namespace App\Application\Report\Http\Middleware;

use App\Component\Telegram\Repository\UserRepository;
use App\Service\Config\Config;
use App\Service\Http\Context\Request\Request;
use DI\Attribute\Injectable;

#[Injectable]
final class TelegramWebAppAuth
{
    private string $botToken;

    public function __construct(
        private UserRepository $userRepository,
        Config $config
    ) {
        $this->botToken = $config->get('telegram.bot_token', '');
    }

    public function validate(Request $request): ?array
    {
        $result = $this->validateInitData($request);
        if ($result) {
            return $result;
        }

        return $this->validateUrlSignature($request);
    }

    private function validateInitData(Request $request): ?array
    {
        $initData = $request->getHeader('x-telegram-init-data');
        if (empty($initData)) {
            return null;
        }

        parse_str($initData, $params);

        $hash = $params['hash'] ?? null;
        if (empty($hash)) {
            return null;
        }

        unset($params['hash']);
        ksort($params);

        $dataCheckString = implode("\n", array_map(
            fn(string $key, string $value) => "{$key}={$value}",
            array_keys($params),
            array_values($params)
        ));

        $secretKey = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $computedHash = bin2hex(hash_hmac('sha256', $dataCheckString, $secretKey, true));

        if (!hash_equals($computedHash, $hash)) {
            return null;
        }

        $authDate = (int) ($params['auth_date'] ?? 0);
        if (time() - $authDate > 86400) {
            return null;
        }

        $user = json_decode($params['user'] ?? '{}', true);
        if (empty($user['id'])) {
            return null;
        }

        return $this->userRepository->findByTelegramId((int) $user['id']);
    }

    private function validateUrlSignature(Request $request): ?array
    {
        $chatId = $request->getQueryParam('chat_id', '');
        $ts = $request->getQueryParam('ts', '');
        $sig = $request->getQueryParam('sig', '');

        if ($chatId === '' || $ts === '' || $sig === '') {
            return null;
        }

        if (abs(time() - (int) $ts) > 86400) {
            return null;
        }

        $expected = hash_hmac('sha256', "{$chatId}:{$ts}", $this->botToken);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        return ['id' => 0, 'chat_id' => (int) $chatId, 'auth_type' => 'url_signature'];
    }
}
