<?php

declare(strict_types=1);

namespace App\Application\Report\Http\Handler;

use App\Service\Attribute\Route;
use App\Service\Http\Context\Request\Request;
use App\Service\Http\Context\Response\Response;

#[Route('/report', 'GET')]
final class ReportPageHandler
{
    public function handle(Request $request, Response $response): void
    {
        $html = file_get_contents(__DIR__ . '/../../View/report.html');

        $response->status(200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->withBody($html ?: '');
    }
}
