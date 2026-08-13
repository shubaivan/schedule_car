<?php

namespace App\Supply\Telegram;

use App\Service\ChatScreen;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Service\RequestFormatter;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Спільний вигляд списку заявок: рядок на заявку зверху, кнопки з номерами знизу.
 *
 * Однаковий і для «Мої заявки», і для «Усі заявки» — різняться вони лише
 * набором заявок та кнопками під списком.
 */
class RequestListScreen
{
    /** Номери заявок трицифрові, тож три кнопки в ряд читаються без переносів. */
    private const BUTTONS_PER_ROW = 3;

    public function __construct(
        private RequestFormatter $formatter,
        private ChatScreen $screen,
    ) {
    }

    /**
     * @param SupplyRequest[] $requests
     * @param InlineKeyboardButton[][] $extraRows кнопки під списком
     */
    public function render(
        Nutgram $bot,
        string $title,
        array $requests,
        string $empty,
        array $extraRows = [],
    ): void {
        $markup = InlineKeyboardMarkup::make();

        if (! $requests) {
            $this->addRows($markup, $extraRows);
            $this->screen->render($bot, $empty, $markup);

            return;
        }

        $lines = [$title, ''];
        $row = [];

        foreach ($requests as $request) {
            $lines[] = $this->formatter->line($request);

            $row[] = InlineKeyboardButton::make(
                '№' . $request->getNumber(),
                callback_data: SupplyCallback::view((int) $request->getId()),
            );

            if (count($row) === self::BUTTONS_PER_ROW) {
                $markup->addRow(...$row);
                $row = [];
            }
        }

        if ($row) {
            $markup->addRow(...$row);
        }

        $this->addRows($markup, $extraRows);

        $this->screen->render($bot, implode("\n", $lines), $markup);
    }

    /** @param InlineKeyboardButton[][] $rows */
    private function addRows(InlineKeyboardMarkup $markup, array $rows): void
    {
        foreach ($rows as $row) {
            if ($row) {
                $markup->addRow(...$row);
            }
        }
    }
}
