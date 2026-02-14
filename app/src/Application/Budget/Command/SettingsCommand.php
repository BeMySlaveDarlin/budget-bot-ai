<?php

declare(strict_types=1);

namespace App\Application\Budget\Command;

use App\Application\Budget\Command\Attribute\BotCommand;
use App\Application\Budget\Command\Contract\BotCommandInterface;
use App\Application\Budget\DTO\CommandContext;
use App\Component\Telegram\Repository\ChatRepository;
use App\Service\Settings\Repository\ChatPromptRepository;
use App\Service\Config\Config;
use DI\Attribute\Injectable;

#[Injectable]
#[BotCommand(command: 'settings', description: 'Настройки чата', adminOnly: true)]
class SettingsCommand implements BotCommandInterface
{
    private const array CURRENCIES = ['THB', 'USD', 'EUR', 'RUB'];
    private const array PROMPT_TYPES = ['stats', 'ai'];

    public function __construct(
        private ChatPromptRepository $promptRepo,
        private ChatRepository $chatRepo,
        private Config $config
    ) {
    }

    public function execute(CommandContext $ctx): ?string
    {
        $args = trim($ctx->args);

        if (empty($args)) {
            return $this->showSettings($ctx);
        }

        $parts = explode(' ', $args, 3);
        $action = $parts[0] ?? '';

        if ($action === 'prompt') {
            $type = $parts[1] ?? '';
            $text = $parts[2] ?? '';

            return $this->handlePrompt($ctx, $type, $text);
        }

        if ($action === 'categories') {
            $text = $parts[1] ?? '';

            return $this->handleCategories($ctx, $text);
        }

        if ($action === 'billing') {
            $day = $parts[1] ?? '';

            return $this->handleBillingDay($ctx, $day);
        }

        if ($action === 'period') {
            $period = $parts[1] ?? '';

            return $this->handlePlanningPeriod($ctx, $period);
        }

        return $this->showSettings($ctx);
    }

    private function showSettings(CommandContext $ctx): string
    {
        $chatId = $ctx->getChatId();
        $currency = $ctx->getCurrency();
        $billingDay = $this->chatRepo->getBillingDay($chatId);
        $planningPeriod = $this->chatRepo->getPlanningPeriod($chatId);
        $categories = $this->chatRepo->getCategories($chatId)
            ?? $this->config->get('llm.default_categories');
        $prompts = $this->promptRepo->getAllForChat($chatId, $ctx->getTopicId());
        $promptMap = array_column($prompts, 'prompt_text', 'prompt_type');

        $text = "<b>⚙️ Настройки чата</b>\n\n";
        $text .= "<b>💵 Валюта:</b> {$currency}\n";
        $text .= "<b>📅 Начало месяца:</b> {$billingDay}-е число\n";
        $text .= "<b>📆 Период планирования:</b> {$planningPeriod} мес.\n\n";

        $text .= "<b>📂 Категории расходов:</b>\n";
        $text .= "<code>{$categories}</code>\n\n";

        $text .= "<b>🤖 Промпты AI:</b>\n";
        $statsStatus = isset($promptMap['stats']) ? '✅ кастомный' : '📝 default';
        $aiStatus = isset($promptMap['ai']) ? '✅ кастомный' : '📝 default';
        $text .= "• stats: {$statsStatus}\n";
        $text .= "• ai: {$aiStatus}\n\n";

        $text .= "<b>Команды:</b>\n";
        $text .= "<code>/settings billing 1-28</code>\n";
        $text .= "<code>/settings period 1-12</code>\n";
        $text .= "<code>/settings categories список</code>\n";
        $text .= "<code>/settings prompt stats|ai</code>";

        return $text;
    }

    private function handleBillingDay(CommandContext $ctx, string $dayStr): string
    {
        if (empty($dayStr)) {
            $current = $this->chatRepo->getBillingDay($ctx->getChatId());
            return "📅 Начало месяца: <b>{$current}-е число</b>\n\n" .
                   "Установить: <code>/settings billing 1-28</code>";
        }

        $day = (int) $dayStr;
        if ($day < 1 || $day > 28) {
            return "❌ День должен быть от 1 до 28";
        }

        $this->chatRepo->setBillingDay($ctx->getChatId(), $day);

        return "✅ Начало месяца: <b>{$day}-е число</b>";
    }

    private function handlePlanningPeriod(CommandContext $ctx, string $periodStr): string
    {
        if (empty($periodStr)) {
            $current = $this->chatRepo->getPlanningPeriod($ctx->getChatId());
            return "📆 Период планирования: <b>{$current} мес.</b>\n\n" .
                   "Установить: <code>/settings period 1-12</code>";
        }

        $period = (int) $periodStr;
        if ($period < 1 || $period > 12) {
            return "❌ Период должен быть от 1 до 12 месяцев";
        }

        $this->chatRepo->setPlanningPeriod($ctx->getChatId(), $period);

        return "✅ Период планирования: <b>{$period} мес.</b>";
    }

    private function handlePrompt(CommandContext $ctx, string $type, string $text): string
    {
        if (!in_array($type, self::PROMPT_TYPES)) {
            return "Тип промпта: <code>stats</code> или <code>ai</code>";
        }

        if (empty($text)) {
            return $this->showPrompt($ctx, $type);
        }

        $topicId = $ctx->getTopicId();

        if ($text === 'reset') {
            $this->promptRepo->deletePrompt($ctx->getChatId(), $type, $topicId);
            return "✅ Промпт <b>{$type}</b> сброшен на default";
        }

        $this->promptRepo->setPrompt($ctx->getChatId(), $type, $text, $topicId);
        $preview = mb_strlen($text) > 100 ? mb_substr($text, 0, 100) . '...' : $text;

        return "✅ Промпт <b>{$type}</b> установлен:\n\n<code>{$preview}</code>";
    }

    private function showPrompt(CommandContext $ctx, string $type): string
    {
        $custom = $this->promptRepo->getPrompt($ctx->getChatId(), $type, $ctx->getTopicId());

        if ($custom) {
            return "<b>Промпт {$type} (кастомный):</b>\n\n<code>{$custom}</code>\n\n" .
                   "<code>/settings prompt {$type} reset</code> - сбросить";
        }

        $configKey = $type === 'stats' ? 'llm.prompts.stats' : 'llm.prompts.ai_assistant';
        $default = $this->config->get($configKey, '');

        return "<b>Промпт {$type} (default):</b>\n\n<code>{$default}</code>";
    }

    private function handleCategories(CommandContext $ctx, string $text): string
    {
        if (empty($text)) {
            $current = $this->chatRepo->getCategories($ctx->getChatId());
            $default = $this->config->get('llm.default_categories');

            if ($current) {
                return "<b>📂 Категории (кастомные):</b>\n<code>{$current}</code>\n\n" .
                       "<code>/settings categories reset</code> - сбросить";
            }

            return "<b>📂 Категории (default):</b>\n<code>{$default}</code>\n\n" .
                   "Установить: <code>/settings categories еда,транспорт,...</code>";
        }

        if ($text === 'reset') {
            $settings = $this->chatRepo->getSettings($ctx->getChatId());
            unset($settings['categories']);
            $this->chatRepo->updateSettings($ctx->getChatId(), $settings);

            return "✅ Категории сброшены на default";
        }

        $categories = preg_replace('/\s*,\s*/', ',', trim($text));
        $this->chatRepo->setCategories($ctx->getChatId(), $categories);

        return "✅ Категории установлены:\n<code>{$categories}</code>";
    }

    public function getKeyboard(CommandContext $ctx): ?array
    {
        if (!empty(trim($ctx->args))) {
            return null;
        }

        $currency = $ctx->getCurrency();

        $currencyButtons = [];
        foreach (self::CURRENCIES as $cur) {
            $currencyButtons[] = [
                'text' => ($currency === $cur ? '✅ ' : '') . $cur,
                'callback_data' => "settings:currency:{$cur}",
            ];
        }

        return [
            'inline_keyboard' => [
                $currencyButtons,
            ],
        ];
    }
}
