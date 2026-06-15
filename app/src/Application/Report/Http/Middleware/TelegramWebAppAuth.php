<?php

declare(strict_types=1);

namespace App\Application\Report\Http\Middleware;

use App\Component\Telegram\WebApp\WebAppAccess;
use App\Component\Telegram\WebApp\WebAppAccessResolver;
use App\Service\Config\Config;
use App\Service\Http\Context\Request\Request;
use DI\Attribute\Injectable;

#[Injectable]
final class TelegramWebAppAuth
{
    private string $botToken;

    public function __construct(
        private WebAppAccessResolver $resolver,
        Config $config
    ) {
        $this->botToken = $config->get('telegram.budget_bot_token', '');
    }

    public function validate(Request $request): ?array
    {
        return $this->resolver->validate($request, $this->botToken);
    }

    public function resolveAccess(Request $request, int $requestedChatId): WebAppAccess
    {
        return $this->resolver->resolve($request, $this->botToken, $requestedChatId);
    }
}
