<?php

declare(strict_types=1);

namespace App\Component\LLM\Exception;

class TokenLimitExceededException extends \RuntimeException
{
    public function __construct(
        public readonly int $used,
        public readonly int $limit
    ) {
        parent::__construct("Daily token limit exceeded: {$used}/{$limit}");
    }
}
