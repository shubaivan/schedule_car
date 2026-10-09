<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Стрічка подій у робочу групу клієнта — Telegram-групу з темами.
 *
 * Кожен розділ пише у свою тему: заявки — в «Заявки», склад — у «Склад»,
 * автопарк — в «Автопарк». Особисті сповіщення від цього не змінюються:
 * група — це спільна стрічка «що відбувається», а не заміна листа людині.
 *
 * Порожній TEAM_CHAT_ID — групи немає, усе мовчить (так у Буддеталі).
 * Порожня тема — повідомлення в General. id тем друкує createForumTopic один
 * раз; Telegram не вміє перелічити теми, тож вони живуть в .env.local.
 *
 * Помилка доставки ніколи не валить операцію: заявка чи рух уже записані.
 */
class TeamChat
{
    public const SUPPLY = 'supply';
    public const WAREHOUSE = 'warehouse';
    public const FLEET = 'fleet';

    public function __construct(
        private Nutgram $bot,
        private LoggerInterface $logger,
        #[Autowire('%env(TEAM_CHAT_ID)%')]
        private string $chatId,
        #[Autowire('%env(TEAM_CHAT_TOPIC_SUPPLY)%')]
        private string $supplyTopic,
        #[Autowire('%env(TEAM_CHAT_TOPIC_WAREHOUSE)%')]
        private string $warehouseTopic,
        #[Autowire('%env(TEAM_CHAT_TOPIC_FLEET)%')]
        private string $fleetTopic,
    ) {
    }

    public function isConfigured(): bool
    {
        return preg_match('/^-?\d+$/', trim($this->chatId)) === 1;
    }

    /** @param ?string $link куди веде кнопка «↗️ Відкрити» під повідомленням */
    public function post(string $topic, string $html, ?string $link = null, string $linkLabel = '↗️ Відкрити'): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        try {
            $this->bot->sendMessage(
                text: $html,
                chat_id: trim($this->chatId),
                message_thread_id: $this->topicId($topic),
                parse_mode: ParseMode::HTML,
                reply_markup: $link !== null
                    ? InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make($linkLabel, url: $link))
                    : null,
            );
        } catch (Throwable $e) {
            $this->logger->warning('team chat: не вдалось написати в групу', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** null — General: з неіснуючою темою Telegram відкидає повідомлення цілком. */
    private function topicId(string $topic): ?int
    {
        $id = trim(match ($topic) {
            self::SUPPLY => $this->supplyTopic,
            self::WAREHOUSE => $this->warehouseTopic,
            self::FLEET => $this->fleetTopic,
            default => '',
        });

        return ctype_digit($id) && (int) $id > 0 ? (int) $id : null;
    }
}
