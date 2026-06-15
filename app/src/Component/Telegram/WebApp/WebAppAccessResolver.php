<?php

declare(strict_types=1);

namespace App\Component\Telegram\WebApp;

use App\Component\Telegram\Repository\ChatUserRepository;
use App\Service\Http\Context\Request\Request;
use DI\Attribute\Injectable;

#[Injectable]
final class WebAppAccessResolver
{
    public function __construct(
        private WebAppAuthenticator $authenticator,
        private ChatUserRepository $members,
    ) {
    }

    public function validate(Request $request, string $botToken): ?array
    {
        return $this->authenticator->validate($request, $botToken);
    }

    public function resolve(Request $request, string $botToken, int $requestedChatId): WebAppAccess
    {
        $user = $this->authenticator->validate($request, $botToken);
        if ($user === null) {
            return WebAppAccess::deny(401, 'Unauthorized');
        }

        if (($user['auth_type'] ?? null) === 'url_signature') {
            return WebAppAccess::grant((int) $user['chat_id'], $user);
        }

        if ($requestedChatId === 0) {
            return WebAppAccess::deny(400, 'chat_id required');
        }

        if ($this->members->findByChatAndUser($requestedChatId, (int) $user['id']) === null) {
            return WebAppAccess::deny(403, 'Forbidden');
        }

        return WebAppAccess::grant($requestedChatId, $user);
    }
}
