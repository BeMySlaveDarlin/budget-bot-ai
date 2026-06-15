<?php

declare(strict_types=1);

namespace App\Component\Telegram;

use App\Service\Config\Config;
use DI\Attribute\Injectable;

#[Injectable]
final class WebhookTokenValidator
{
    public function __construct(
        private Config $config
    ) {
    }

    public function validate(string $expectedConfigKey, string $actualToken): bool
    {
        $expected = (string) $this->config->get($expectedConfigKey, '');
        if ($expected === '' || $actualToken === '') {
            return false;
        }

        return hash_equals($expected, $actualToken);
    }
}
