<?php

declare(strict_types=1);

namespace App\Component\Telegram\WebApp;

use App\Component\Telegram\Repository\UserRepository;
use App\Service\Http\Context\Request\Request;
use DI\Attribute\Injectable;

#[Injectable]
final class WebAppAuthenticator
{
    public function __construct(
        private UserRepository $userRepository
    ) {
    }

    public function validate(Request $request, string $botToken): ?array
    {
        if ($botToken === '') {
            return null;
        }

        $result = $this->validateInitData($request, $botToken);
        if ($result) {
            return $result;
        }

        return $this->validateUrlSignature($request, $botToken);
    }

    private function validateInitData(Request $request, string $botToken): ?array
    {
        $initData = $request->getHeader('x-telegram-init-data');
        if (empty($initData)) {
            return null;
        }

        parse_str($initData, $params);

        foreach ($params as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                return null;
            }
        }

        $hash = $params['hash'] ?? null;
        if (empty($hash)) {
            return null;
        }

        unset($params['hash']);
        ksort($params);

        $dataCheckString = implode(
            "\n",
            array_map(
                fn(string $key, string $value) => "{$key}={$value}",
                array_keys($params),
                array_values($params)
            )
        );

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
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

    private function validateUrlSignature(Request $request, string $botToken): ?array
    {
        $chatId = $request->getQueryParam('chat_id', '');
        $ts = $request->getQueryParam('ts', '');
        $sig = $request->getQueryParam('sig', '');

        if (!is_string($chatId) || !is_string($ts) || !is_string($sig)) {
            return null;
        }

        if ($chatId === '' || $ts === '' || $sig === '') {
            return null;
        }

        if (abs(time() - (int) $ts) > 86400) {
            return null;
        }

        $expected = hash_hmac('sha256', "{$chatId}:{$ts}", $botToken);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        return ['id' => 0, 'chat_id' => (int) $chatId, 'auth_type' => 'url_signature'];
    }
}
