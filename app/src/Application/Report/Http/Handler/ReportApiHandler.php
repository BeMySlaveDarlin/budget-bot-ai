<?php

declare(strict_types=1);

namespace App\Application\Report\Http\Handler;

use App\Application\Report\DTO\ReportFilter;
use App\Application\Report\Http\Middleware\TelegramWebAppAuth;
use App\Application\Report\Repository\ReportRepository;
use App\Application\Report\Service\ReportService;
use App\Service\Attribute\Route;
use App\Service\Http\Context\Request\Request;
use App\Service\Http\Context\Response\Response;
use App\Service\Task\TaskManager;

final class ReportApiHandler
{
    private const MAX_CATEGORY_LENGTH = 200;
    private const MAX_WALLET_LENGTH = 200;
    private const MAX_DESCRIPTION_LENGTH = 1000;

    public function __construct(
        private ReportService $reportService,
        private ReportRepository $reportRepository,
        private TelegramWebAppAuth $auth,
        private TaskManager $taskManager,
    ) {}

    #[Route('/api/report/summary', 'GET')]
    public function summary(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $filter = ReportFilter::fromQueryParams($request->getQueryParams());

        $response->json($this->reportService->getSummary($chatId, $filter));
    }

    #[Route('/api/report/categories', 'GET')]
    public function categories(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $filter = ReportFilter::fromQueryParams($request->getQueryParams());

        $response->json($this->reportService->getCategoryBreakdown($chatId, $filter));
    }

    #[Route('/api/report/transactions', 'GET')]
    public function transactions(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $filter = ReportFilter::fromQueryParams($request->getQueryParams());

        $response->json($this->reportService->getTransactions($chatId, $filter));
    }

    #[Route('/api/report/trends', 'GET')]
    public function trends(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $filter = ReportFilter::fromQueryParams($request->getQueryParams());

        $response->json($this->reportService->getMonthlyTrends($chatId, $filter));
    }

    #[Route('/api/report/wallets', 'GET')]
    public function wallets(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $topicId = $request->getQueryParam('topic_id') !== null ? (int) $request->getQueryParam('topic_id') : null;
        $response->json($this->reportService->getWalletBalances($chatId, $topicId));
    }

    #[Route('/api/report/comparison', 'GET')]
    public function comparison(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $filter = ReportFilter::fromQueryParams($request->getQueryParams());

        $response->json($this->reportService->getComparison($chatId, $filter));
    }

    #[Route('/api/report/export', 'GET')]
    public function export(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $filter = ReportFilter::fromQueryParams($request->getQueryParams());
        $csv = $this->reportService->exportCsv($chatId, $filter);

        $response->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="report.csv"')
            ->withBody($csv);
    }

    #[Route('/api/report/refresh', 'POST')]
    public function refresh(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $filter = ReportFilter::fromQueryParams($request->getQueryParams());

        $response->json($this->reportService->refreshCategorization($chatId, $filter));
    }

    #[Route('/api/report/transactions', 'POST')]
    public function createTransaction(Request $request, Response $response): void
    {
        $requested = (int) $request->getQueryParam('chat_id', 0);
        $access = $this->auth->resolveAccess($request, $requested);
        if (!$access->granted) {
            $response->json(['error' => $access->denyError], $access->denyStatus);
            return;
        }

        $body = $request->getBody();
        $error = $this->validateTransactionBody($body);
        if ($error !== null) {
            $response->json(['error' => $error], 400);
            return;
        }

        $userId = (int) ($access->user['id'] ?? 0);
        $topicId = $request->getQueryParam('topic_id') !== null ? (int) $request->getQueryParam('topic_id') : null;

        $id = $this->reportRepository->createTransaction($access->chatId, $userId, [
            'type' => $body['type'],
            'amount' => (float) $body['amount'],
            'currency' => $body['currency'],
            'category' => $body['category'] ?? '',
            'description' => $body['description'] ?? '',
            'wallet' => $body['wallet'] ?? null,
        ], $topicId);

        $response->json(['id' => $id, 'success' => true]);
    }

    #[Route('/api/report/transactions', 'PUT')]
    public function updateTransaction(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $body = $request->getBody();

        $messageId = (int) ($body['message_id'] ?? 0);
        $itemIndex = (int) ($body['item_index'] ?? 0);
        if ($messageId === 0 || $itemIndex < 1) {
            $response->json(['error' => 'message_id and item_index required'], 400);
            return;
        }

        $error = $this->validateTransactionBody($body);
        if ($error !== null) {
            $response->json(['error' => $error], 400);
            return;
        }

        $success = $this->reportRepository->updateTransaction($messageId, $itemIndex, $chatId, [
            'type' => $body['type'],
            'amount' => (float) $body['amount'],
            'currency' => $body['currency'],
            'category' => $body['category'] ?? '',
            'description' => $body['description'] ?? '',
            'wallet' => $body['wallet'] ?? null,
        ]);

        $response->json(['success' => $success]);
    }

    #[Route('/api/report/transactions', 'DELETE')]
    public function deleteTransaction(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $messageId = (int) $request->getQueryParam('message_id', 0);
        $itemIndex = (int) $request->getQueryParam('item_index', 0);
        if ($messageId === 0 || $itemIndex < 1) {
            $response->json(['error' => 'message_id and item_index required'], 400);
            return;
        }

        $success = $this->reportRepository->deleteTransaction($messageId, $itemIndex, $chatId);

        $response->json(['success' => $success]);
    }

    #[Route('/api/report/rates', 'GET')]
    public function rates(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $currency = $request->getQueryParam('currency', 'THB');
        $topicId = $request->getQueryParam('topic_id') !== null ? (int) $request->getQueryParam('topic_id') : null;

        $response->json($this->reportService->getExchangeRates($chatId, $currency, $topicId));
    }

    #[Route('/api/report/rates', 'PUT')]
    public function upsertRate(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $body = $request->getBody();
        $from = strtoupper(trim($body['currency_from'] ?? ''));
        $to = strtoupper(trim($body['currency_to'] ?? ''));
        $rate = (float) ($body['rate'] ?? 0);

        if ($from === '' || $to === '' || $rate <= 0) {
            $response->json(['error' => 'currency_from, currency_to and rate (>0) required'], 400);
            return;
        }

        $this->reportService->upsertCustomRate($chatId, $from, $to, $rate);
        $response->json(['success' => true]);
    }

    #[Route('/api/report/rates', 'DELETE')]
    public function deleteRate(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $from = strtoupper(trim($request->getQueryParam('currency_from', '')));
        $to = strtoupper(trim($request->getQueryParam('currency_to', '')));

        if ($from === '' || $to === '') {
            $response->json(['error' => 'currency_from and currency_to required'], 400);
            return;
        }

        $success = $this->reportService->deleteCustomRate($chatId, $from, $to);
        $response->json(['success' => $success]);
    }

    #[Route('/api/report/suggestions', 'GET')]
    public function suggestions(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $topicId = $request->getQueryParam('topic_id') !== null ? (int) $request->getQueryParam('topic_id') : null;
        $response->json([
            'categories' => $this->reportRepository->getDistinctCategories($chatId, $topicId),
            'wallets' => $this->reportRepository->getDistinctWallets($chatId, $topicId),
        ]);
    }

    #[Route('/api/report/task-status', 'GET')]
    public function taskStatus(Request $request, Response $response): void
    {
        $chatId = $this->guard($request, $response);
        if ($chatId === null) {
            return;
        }

        $taskId = (int) $request->getQueryParam('task_id', 0);
        if ($taskId === 0) {
            $response->json(['error' => 'task_id required'], 400);
            return;
        }

        $task = $this->taskManager->getStatus($taskId);
        if (!$task) {
            $response->json(['error' => 'Task not found'], 404);
            return;
        }

        $response->json([
            'task_id' => $task['id'],
            'status' => $task['status'],
            'error' => $task['error_message'] ?? null,
            'created_at' => $task['created_at'] ?? null,
            'started_at' => $task['started_at'] ?? null,
            'completed_at' => $task['completed_at'] ?? null,
        ]);
    }

    #[Route('/api/report/{path:.*}', 'OPTIONS')]
    public function cors(Request $request, Response $response): void
    {
        $response->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, X-Telegram-Init-Data')
            ->status(204);
    }

    private function guard(Request $request, Response $response): ?int
    {
        $requested = (int) $request->getQueryParam('chat_id', 0);
        $access = $this->auth->resolveAccess($request, $requested);
        if (!$access->granted) {
            $response->json(['error' => $access->denyError], $access->denyStatus);
            return null;
        }

        return $access->chatId;
    }

    private function validateTransactionBody(array $body): ?string
    {
        $type = $body['type'] ?? '';
        if (!in_array($type, ['income', 'expense', 'balance'], true)) {
            return 'type must be income, expense or balance';
        }

        $amount = $body['amount'] ?? 0;
        if ((float) $amount <= 0) {
            return 'amount must be greater than 0';
        }

        if (empty($body['currency'])) {
            return 'currency required';
        }

        if ($type === 'balance' && empty($body['wallet'])) {
            return 'wallet required for balance type';
        }

        if (in_array($type, ['income', 'expense'], true) && empty($body['category'])) {
            return 'category required for income/expense type';
        }

        if (is_string($body['category'] ?? null) && mb_strlen($body['category']) > self::MAX_CATEGORY_LENGTH) {
            return 'category too long';
        }

        if (is_string($body['wallet'] ?? null) && mb_strlen($body['wallet']) > self::MAX_WALLET_LENGTH) {
            return 'wallet too long';
        }

        if (is_string($body['description'] ?? null) && mb_strlen($body['description']) > self::MAX_DESCRIPTION_LENGTH) {
            return 'description too long';
        }

        return null;
    }
}
