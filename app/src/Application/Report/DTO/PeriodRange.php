<?php

declare(strict_types=1);

namespace App\Application\Report\DTO;

final readonly class PeriodRange
{
    public function __construct(
        public string $from,
        public string $to,
    ) {}

    public static function fromBillingDay(int $months, int $billingDay, int $offsetMonths = 0): self
    {
        $today = new \DateTimeImmutable();

        if ($offsetMonths > 0) {
            $today = $today->modify("-{$offsetMonths} months");
        }

        $currentDay = (int) $today->format('d');

        if ($currentDay >= $billingDay) {
            $periodEnd = $today->modify('first day of next month')->modify('+' . ($billingDay - 1) . ' days');
            $periodStart = $today->modify('first day of this month')->modify('+' . ($billingDay - 1) . ' days');
        } else {
            $periodEnd = $today->modify('first day of this month')->modify('+' . ($billingDay - 1) . ' days');
            $periodStart = $today->modify('first day of last month')->modify('+' . ($billingDay - 1) . ' days');
        }

        $periodsBack = $months - 1;
        if ($periodsBack > 0) {
            $periodStart = $periodStart->modify("-{$periodsBack} months");
        }

        return new self(
            $periodStart->format('Y-m-d 00:00:00'),
            $periodEnd->format('Y-m-d 00:00:00'),
        );
    }
}
