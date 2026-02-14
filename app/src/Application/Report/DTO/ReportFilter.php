<?php

declare(strict_types=1);

namespace App\Application\Report\DTO;

final readonly class ReportFilter
{
    public function __construct(
        public int $months = 1,
        public string $currency = 'THB',
        public ?string $type = null,
        public ?string $category = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?int $topicId = null,
    ) {}

    public static function fromQueryParams(array $params): self
    {
        $from = isset($params['from']) ? (string) $params['from'] : null;
        $to = isset($params['to']) ? (string) $params['to'] : null;

        if ($from !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = null;
        }
        if ($to !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = null;
        }

        return new self(
            months: max(1, min(12, (int) ($params['months'] ?? 1))),
            currency: strtoupper((string) ($params['currency'] ?? 'THB')),
            type: isset($params['type']) ? (string) $params['type'] : null,
            category: isset($params['category']) ? (string) $params['category'] : null,
            from: $from,
            to: $to,
            topicId: isset($params['topic_id']) ? (int) $params['topic_id'] : null,
        );
    }
}
