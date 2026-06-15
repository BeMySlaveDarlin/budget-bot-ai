<?php

declare(strict_types=1);

namespace App\Application\Meals\Http\Handler;

use App\Service\Attribute\Route;
use App\Service\Http\Context\Request\Request;
use App\Service\Http\Context\Response\Response;

#[Route('/meals', 'GET')]
final class MealAppPageHandler
{
    public function handle(Request $request, Response $response): void
    {
        $html = file_get_contents(__DIR__ . '/../../View/meals.html');

        $response->status(200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->withBody($html ?: '');
    }
}
