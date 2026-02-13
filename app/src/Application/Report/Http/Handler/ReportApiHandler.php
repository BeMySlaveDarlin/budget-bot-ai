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

final class ReportApiHandler
{
    public function __construct(
        private ReportService $reportService,
        private ReportRepository $reportRepository,
        private TelegramWebAppAuth $auth,
    ) {}

    #[Route('/api/report/summary', 'GET')]
    public function summary(Request $request, Response $response): void
    {
        $user = $this->auth->validate($request);
        if (!$user) {
            $response->json(['error' => 'Unauthorized'], 401);
            return;
        }

        $chatId = (int) $request->getQueryParam('chat_id', 0);
        if ($chatId === 0) {
            $response->json(['error' => 'chat_id required'], 400);
            return;
        }

        $filter = ReportFilter::fromQueryParams($request->getQueryParams());

        $response->json($this->reportService->getSummary($chatId, $filter));
    }

    #[Route('/api/report/categories', 'GET')]
    public function categories(Request $request, Response $response): void
    {
        $user = $this->auth->validate($request);
        if (!$user) {
            $response->json(['error' => 'Unauthorized'], 401);
            return;
        }

        $chatId = (int) $request->getQueryParam('chat_id', 0);
        if ($chatId === 0) {
            $response->json(['error' => 'chat_id required'], 400);
            return;
        }

        $filter = ReportFilter::fromQueryParams($request->getQueryParams());

        $response->json($this->reportService->getCategoryBreakdown($chatId, $filter));
    }

    #[Route('/api/report/transactions', 'GET')]
    public function transactions(Request $request, Response $response): void
    {
        $user = $this->auth->validate($request);
        if (!$user) {
            $response->json(['error' => 'Unauthorized'], 401);
            return;
        }

        $chatId = (int) $request->getQueryParam('chat_id', 0);
        if ($chatId === 0) {
            $response->json(['error' => 'chat_id required'], 400);
            return;
        }

        $filter = ReportFilter::fromQueryParams($request->getQueryParams());

        $response->json($this->reportService->getTransactions($chatId, $filter));
    }

    #[Route('/api/report/trends', 'GET')]
    public function trends(Request $request, Response $response): void
    {
        $user = $this->auth->validate($request);
        if (!$user) {
            $response->json(['error' => 'Unauthorized'], 401);
            return;
        }

        $chatId = (int) $request->getQueryParam('chat_id', 0);
        if ($chatId === 0) {
            $response->json(['error' => 'chat_id required'], 400);
            return;
        }

        $filter = ReportFilter::fromQueryParams($request->getQueryParams());

        $response->json($this->reportService->getMonthlyTrends($chatId, $filter));
    }

    #[Route('/api/report/wallets', 'GET')]
    public function wallets(Request $request, Response $response): void
    {
        $user = $this->auth->validate($request);
        if (!$user) {
            $response->json(['error' => 'Unauthorized'], 401);
            return;
        }

        $chatId = (int) $request->getQueryParam('chat_id', 0);
        if ($chatId === 0) {
            $response->json(['error' => 'chat_id required'], 400);
            return;
        }

        $response->json($this->reportService->getWalletBalances($chatId));
    }

    #[Route('/api/report/comparison', 'GET')]
    public function comparison(Request $request, Response $response): void
    {
        $user = $this->auth->validate($request);
        if (!$user) {
            $response->json(['error' => 'Unauthorized'], 401);
            return;
        }

        $chatId = (int) $request->getQueryParam('chat_id', 0);
        if ($chatId === 0) {
            $response->json(['error' => 'chat_id required'], 400);
            return;
        }

        $filter = ReportFilter::fromQueryParams($request->getQueryParams());

        $response->json($this->reportService->getComparison($chatId, $filter));
    }

    #[Route('/api/report/export', 'GET')]
    public function export(Request $request, Response $response): void
    {
        $user = $this->auth->validate($request);
        if (!$user) {
            $response->json(['error' => 'Unauthorized'], 401);
            return;
        }

        $chatId = (int) $request->getQueryParam('chat_id', 0);
        if ($chatId === 0) {
            $response->json(['error' => 'chat_id required'], 400);
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
        $user = $this->auth->validate($request);
        if (!$user) {
            $response->json(['error' => 'Unauthorized'], 401);
            return;
        }

        $chatId = (int) $request->getQueryParam('chat_id', 0);
        if ($chatId === 0) {
            $response->json(['error' => 'chat_id required'], 400);
            return;
        }

        $filter = ReportFilter::fromQueryParams($request->getQueryParams());

        $response->json($this->reportService->refreshCategorization($chatId, $filter));
    }

    #[Route('/api/report/transactions', 'POST')]
    public function createTransaction(Request $request, Response $response): void
    {
        $user = $this->auth->validate($request);
        if (!$user) {
            $response->json(['error' => 'Unauthorized'], 401);
            return;
        }

        $chatId = (int) $request->getQueryParam('chat_id', 0);
        if ($chatId === 0) {
            $response->json(['error' => 'chat_id required'], 400);
            return;
        }

        $body = $request->getBody();
        $error = $this->validateTransactionBody($body);
        if ($error !== null) {
            $response->json(['error' => $error], 400);
            return;
        }

        $userId = (int) ($user['id'] ?? 0);

        $id = $this->reportRepository->createTransaction($chatId, $userId, [
            'type' => $body['type'],
            'amount' => (float) $body['amount'],
            'currency' => $body['currency'],
            'category' => $body['category'] ?? '',
            'description' => $body['description'] ?? '',
            'wallet' => $body['wallet'] ?? null,
        ]);

        $response->json(['id' => $id, 'success' => true]);
    }

    #[Route('/api/report/transactions', 'PUT')]
    public function updateTransaction(Request $request, Response $response): void
    {
        $user = $this->auth->validate($request);
        if (!$user) {
            $response->json(['error' => 'Unauthorized'], 401);
            return;
        }

        $body = $request->getBody();

        $messageId = (int) ($body['message_id'] ?? 0);
        $itemIndex = (int) ($body['item_index'] ?? 0);
        if ($messageId === 0 || $itemIndex === 0) {
            $response->json(['error' => 'message_id and item_index required'], 400);
            return;
        }

        $error = $this->validateTransactionBody($body);
        if ($error !== null) {
            $response->json(['error' => $error], 400);
            return;
        }

        $success = $this->reportRepository->updateTransaction($messageId, $itemIndex, [
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
        $user = $this->auth->validate($request);
        if (!$user) {
            $response->json(['error' => 'Unauthorized'], 401);
            return;
        }

        $messageId = (int) $request->getQueryParam('message_id', 0);
        $itemIndex = (int) $request->getQueryParam('item_index', 0);
        if ($messageId === 0 || $itemIndex === 0) {
            $response->json(['error' => 'message_id and item_index required'], 400);
            return;
        }

        $success = $this->reportRepository->deleteTransaction($messageId, $itemIndex);

        $response->json(['success' => $success]);
    }

    #[Route('/api/report/suggestions', 'GET')]
    public function suggestions(Request $request, Response $response): void
    {
        $user = $this->auth->validate($request);
        if (!$user) {
            $response->json(['error' => 'Unauthorized'], 401);
            return;
        }

        $chatId = (int) $request->getQueryParam('chat_id', 0);
        if ($chatId === 0) {
            $response->json(['error' => 'chat_id required'], 400);
            return;
        }

        $response->json([
            'categories' => $this->reportRepository->getDistinctCategories($chatId),
            'wallets' => $this->reportRepository->getDistinctWallets($chatId),
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

        return null;
    }
}
