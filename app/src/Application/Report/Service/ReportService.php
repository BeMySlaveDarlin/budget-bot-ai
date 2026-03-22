<?php

declare(strict_types=1);

namespace App\Application\Report\Service;

use App\Application\Budget\Task\CategorizationTask;
use App\Application\Report\DTO\PeriodRange;
use App\Application\Report\DTO\ReportFilter;
use App\Application\Report\Repository\ReportRepository;
use App\Component\ExchangeRate\ExchangeRateService;
use App\Component\ExchangeRate\Repository\CustomExchangeRateRepository;
use App\Component\Telegram\Repository\ChatRepository;
use App\Service\Task\TaskManager;
use DI\Attribute\Injectable;
use Psr\Log\LoggerInterface;

#[Injectable]
final class ReportService
{
    public function __construct(
        private ReportRepository $reportRepository,
        private ChatRepository $chatRepository,
        private ExchangeRateService $exchangeRateService,
        private CustomExchangeRateRepository $customRateRepository,
        private TaskManager $taskManager,
        private LoggerInterface $logger,
    ) {}

    public function getSummary(int $chatId, ReportFilter $filter): array
    {
        $period = $this->getPeriod($chatId, $filter);
        $rows = $this->reportRepository->getSummary($chatId, $period->from, $period->to, $filter->topicId);

        $aggregated = [];
        foreach ($rows as $row) {
            $type = $row['type'];
            $amount = (float) $row['total'];
            $converted = $this->convertCurrency($amount, $row['currency'], $filter->currency, $chatId);

            if (!isset($aggregated[$type])) {
                $aggregated[$type] = ['total' => 0.0, 'count' => 0];
            }
            $aggregated[$type]['total'] += $converted;
            $aggregated[$type]['count'] += (int) $row['count'];
        }

        $result = [];
        foreach ($aggregated as $type => $data) {
            $result[] = [
                'type' => $type,
                'currency' => $filter->currency,
                'total' => round($data['total'], 2),
                'count' => $data['count'],
            ];
        }

        return [
            'period' => ['from' => $period->from, 'to' => $period->to],
            'currency' => $filter->currency,
            'items' => $result,
            'remainder' => $this->getRemainder($chatId, $filter->currency, $filter->topicId),
        ];
    }

    public function getCategoryBreakdown(int $chatId, ReportFilter $filter): array
    {
        $period = $this->getPeriod($chatId, $filter);
        $rows = $this->reportRepository->getCategoryBreakdown($chatId, $period->from, $period->to, $filter->type, $filter->topicId);

        $aggregated = [];
        foreach ($rows as $row) {
            $key = $row['category'] . '|' . $row['type'];
            $amount = (float) $row['total'];
            $converted = $this->convertCurrency($amount, $row['currency'], $filter->currency, $chatId);

            if (!isset($aggregated[$key])) {
                $aggregated[$key] = ['category' => $row['category'], 'type' => $row['type'], 'total' => 0.0, 'count' => 0];
            }
            $aggregated[$key]['total'] += $converted;
            $aggregated[$key]['count'] += (int) $row['count'];
        }

        $result = [];
        foreach ($aggregated as $data) {
            $result[] = [
                'category' => $data['category'],
                'type' => $data['type'],
                'currency' => $filter->currency,
                'total' => round($data['total'], 2),
                'count' => $data['count'],
            ];
        }

        usort($result, fn($a, $b) => $b['total'] <=> $a['total']);

        return [
            'period' => ['from' => $period->from, 'to' => $period->to],
            'currency' => $filter->currency,
            'items' => $result,
        ];
    }

    public function getTransactions(int $chatId, ReportFilter $filter): array
    {
        $period = $this->getPeriod($chatId, $filter);

        $rows = $this->reportRepository->getTransactions(
            $chatId,
            $period->from,
            $period->to,
            $filter->type,
            $filter->category,
            $filter->topicId
        );

        $items = [];
        foreach ($rows as $row) {
            $amount = (float) $row['amount'];
            $converted = $this->convertCurrency($amount, $row['currency'], $filter->currency, $chatId);

            $items[] = [
                'id' => (int) $row['id'],
                'type' => $row['type'],
                'category' => $row['category'],
                'amount' => round($converted, 2),
                'currency' => $filter->currency,
                'original_amount' => round($amount, 2),
                'original_currency' => $row['currency'],
                'description' => $row['description'],
                'wallet' => $row['wallet'],
                'created_at' => $row['created_at'],
            ];
        }

        return [
            'period' => ['from' => $period->from, 'to' => $period->to],
            'currency' => $filter->currency,
            'items' => $items,
        ];
    }

    public function getMonthlyTrends(int $chatId, ReportFilter $filter): array
    {
        $period = $this->getPeriod($chatId, $filter);
        $rows = $this->reportRepository->getMonthlyTrends($chatId, $period->from, $period->to, $filter->topicId);

        $aggregated = [];
        foreach ($rows as $row) {
            $key = $row['month'] . '|' . $row['type'];
            $amount = (float) $row['total'];
            $converted = $this->convertCurrency($amount, $row['currency'], $filter->currency, $chatId);

            if (!isset($aggregated[$key])) {
                $aggregated[$key] = ['month' => $row['month'], 'type' => $row['type'], 'total' => 0.0, 'count' => 0];
            }
            $aggregated[$key]['total'] += $converted;
            $aggregated[$key]['count'] += (int) $row['count'];
        }

        $result = [];
        foreach ($aggregated as $data) {
            $result[] = [
                'month' => $data['month'],
                'type' => $data['type'],
                'currency' => $filter->currency,
                'total' => round($data['total'], 2),
                'count' => $data['count'],
            ];
        }

        usort($result, fn($a, $b) => $a['month'] <=> $b['month'] ?: $a['type'] <=> $b['type']);

        return [
            'period' => ['from' => $period->from, 'to' => $period->to],
            'currency' => $filter->currency,
            'items' => $result,
        ];
    }

    public function getWalletBalances(int $chatId, ?int $topicId = null): array
    {
        return $this->reportRepository->getWalletBalances($chatId, $topicId);
    }

    public function getComparison(int $chatId, ReportFilter $current, ?int $previousOffsetMonths = null): array
    {
        $currentData = $this->getSummary($chatId, $current);

        $offsetMonths = $previousOffsetMonths ?? $current->months;
        $billingDay = $this->chatRepository->getBillingDay($chatId);
        $previousPeriod = PeriodRange::fromBillingDay($current->months, $billingDay, $offsetMonths);
        $previousRows = $this->reportRepository->getSummary($chatId, $previousPeriod->from, $previousPeriod->to, $current->topicId);

        $previousData = [];
        foreach ($previousRows as $row) {
            $amount = (float) $row['total'];
            $converted = $this->convertCurrency($amount, $row['currency'], $current->currency, $chatId);
            $previousData[$row['type']] = ($previousData[$row['type']] ?? 0.0) + $converted;
        }
        $previousData = array_map(fn($v) => round($v, 2), $previousData);

        $comparison = [];
        foreach ($currentData['items'] as $item) {
            $prevTotal = $previousData[$item['type']] ?? 0.0;
            $diff = $item['total'] - $prevTotal;
            $pctChange = $prevTotal > 0 ? round(($diff / $prevTotal) * 100, 1) : null;

            $comparison[] = [
                'type' => $item['type'],
                'current' => $item['total'],
                'previous' => $prevTotal,
                'diff' => round($diff, 2),
                'pct_change' => $pctChange,
                'currency' => $current->currency,
            ];
        }

        return [
            'current_period' => $currentData['period'],
            'previous_period' => ['from' => $previousPeriod->from, 'to' => $previousPeriod->to],
            'currency' => $current->currency,
            'items' => $comparison,
        ];
    }

    public function exportCsv(int $chatId, ReportFilter $filter): string
    {
        $period = $this->getPeriod($chatId, $filter);
        $rows = $this->reportRepository->getTransactionsForExport($chatId, $period->from, $period->to, $filter->topicId);

        $lines = ["type,category,amount,currency,description,wallet,date"];

        foreach ($rows as $row) {
            $description = str_replace('"', '""', $row['description'] ?? '');
            $lines[] = sprintf(
                '%s,%s,%s,%s,"%s",%s,%s',
                $row['type'],
                $row['category'],
                $row['amount'],
                $row['currency'],
                $description,
                $row['wallet'] ?? '',
                $row['created_at'],
            );
        }

        return implode("\n", $lines);
    }

    public function refreshCategorization(int $chatId, ReportFilter $filter): array
    {
        $period = $this->getPeriod($chatId, $filter);

        $this->logger->info('[ReportService] refreshCategorization START', [
            'chat_id' => $chatId,
            'months' => $filter->months,
            'currency' => $filter->currency,
            'topic_id' => $filter->topicId,
            'period_from' => $period->from,
            'period_to' => $period->to,
        ]);

        $cleared = $this->reportRepository->clearCategorization($chatId, $period->from, $period->to, $filter->topicId);

        $this->logger->info('[ReportService] Categorization cleared', [
            'chat_id' => $chatId,
            'cleared_count' => $cleared,
        ]);

        $taskId = $this->taskManager->dispatch(
            CategorizationTask::class,
            [
                'chat_id' => $chatId,
                'months' => $filter->months,
                'currency' => $filter->currency,
                'topic_id' => $filter->topicId,
            ],
            $chatId,
            'report_refresh'
        );

        $this->logger->info('[ReportService] CategorizationTask dispatched', [
            'chat_id' => $chatId,
            'task_id' => $taskId,
        ]);

        return [
            'cleared' => $cleared,
            'status' => 'processing',
            'task_id' => $taskId,
        ];
    }

    public function getRemainder(int $chatId, string $currency, ?int $topicId = null): ?array
    {
        $balances = $this->reportRepository->getLatestBalances($chatId, $topicId);

        if (empty($balances)) {
            return null;
        }

        $balanceSum = 0.0;
        $maxDate = '';
        foreach ($balances as $balance) {
            $balanceSum += $this->convertCurrency((float) $balance['amount'], $balance['currency'], $currency, $chatId);
            if ($balance['balance_at'] > $maxDate) {
                $maxDate = $balance['balance_at'];
            }
        }

        $transactions = $this->reportRepository->getTransactionsAfterDate($chatId, $maxDate, $topicId);

        $adjustments = 0.0;
        foreach ($transactions as $tx) {
            $amount = $this->convertCurrency((float) $tx['amount'], $tx['currency'], $currency, $chatId);
            if ($tx['type'] === 'income') {
                $adjustments += $amount;
            } elseif ($tx['type'] === 'expense') {
                $adjustments -= $amount;
            }
        }

        return [
            'remainder' => round($balanceSum + $adjustments, 2),
            'currency' => $currency,
        ];
    }

    public function getExchangeRates(int $chatId, string $currency, ?int $topicId = null): array
    {
        $currencies = $this->reportRepository->getDistinctCurrencies($chatId, $topicId);
        $customRates = $this->customRateRepository->getAllForChat($chatId);
        $customMap = [];
        foreach ($customRates as $cr) {
            $customMap[$cr['currency_from'] . '/' . $cr['currency_to']] = (float) $cr['rate'];
        }

        $rates = [];
        foreach ($currencies as $code) {
            if ($code === $currency) {
                continue;
            }

            $isCustom = false;
            $customKey = $code . '/' . $currency;
            $reverseKey = $currency . '/' . $code;

            if (isset($customMap[$customKey])) {
                $rate = $customMap[$customKey];
                $isCustom = true;
            } elseif (isset($customMap[$reverseKey]) && $customMap[$reverseKey] > 0) {
                $rate = 1.0 / $customMap[$reverseKey];
                $isCustom = true;
            } else {
                $rate = $this->exchangeRateService->convert(1.0, $code, $currency);
            }

            if ($rate === null) {
                continue;
            }

            $rates[] = [
                'from' => $code,
                'rate' => round($rate, 2),
                'custom' => $isCustom,
            ];
        }

        return [
            'currency' => $currency,
            'rates' => $rates,
            'updated_at' => $this->exchangeRateService->getLastUpdate(),
        ];
    }

    public function upsertCustomRate(int $chatId, string $currencyFrom, string $currencyTo, float $rate): void
    {
        $this->customRateRepository->upsert($chatId, $currencyFrom, $currencyTo, $rate);
    }

    public function deleteCustomRate(int $chatId, string $currencyFrom, string $currencyTo): bool
    {
        return $this->customRateRepository->delete($chatId, $currencyFrom, $currencyTo);
    }

    private function getPeriod(int $chatId, ReportFilter $filter): PeriodRange
    {
        if ($filter->from !== null && $filter->to !== null) {
            return new PeriodRange($filter->from . ' 00:00:00', $filter->to . ' 23:59:59');
        }

        $billingDay = $this->chatRepository->getBillingDay($chatId);
        return PeriodRange::fromBillingDay($filter->months, $billingDay);
    }

    private function convertCurrency(float $amount, string $from, string $to, ?int $chatId = null): float
    {
        if ($from === $to) {
            return $amount;
        }

        if ($chatId !== null) {
            return $this->exchangeRateService->convertForChat($amount, $from, $to, $chatId) ?? $amount;
        }

        return $this->exchangeRateService->convert($amount, $from, $to) ?? $amount;
    }
}
