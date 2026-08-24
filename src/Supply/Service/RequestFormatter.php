<?php

namespace App\Supply\Service;

use App\Supply\Entity\SupplyRequest;
use DateTimeInterface;

/** Один текст картки заявки на всі повідомлення бота — щоб вигляд не розповзався. */
class RequestFormatter
{
    private const MONTHS = [
        1 => 'січня', 'лютого', 'березня', 'квітня', 'травня', 'червня',
        'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня',
    ];

    public function card(SupplyRequest $request, bool $forManager = false): string
    {
        $lines = [];

        $accent = $request->getAccent();

        $lines[] = sprintf(
            '%s <b>Заявка №%s</b>',
            $accent->isSet() ? $accent->emoji() : '📄',
            $this->escape($request->getNumber()),
        );

        // Мітку ставить менеджер саме для керівника, тому вона стоїть одразу під
        // номером — вище за все, що заповнив заявник.
        if ($accent->isSet()) {
            $lines[] = sprintf('%s <b>%s</b>', $accent->emoji(), $accent->label());
        }
        $lines[] = sprintf('📦 %s — <b>%s</b>', $this->escape($request->getItem()), $request->getQuantityLabel());

        if ($request->getSite()) {
            $lines[] = '🏗 Об\'єкт: ' . $this->escape($request->getSite());
        }

        if ($request->getNeedBy()) {
            $lines[] = sprintf(
                '📅 Потрібно до: <b>%s</b>%s%s',
                $this->date($request->getNeedBy()),
                $request->isUrgent() ? ' <i>(терміново)</i>' : '',
                $request->isOverdue() ? ' ⚠️ <b>прострочено</b>' : '',
            );
        }

        if ($forManager) {
            $lines[] = '👤 Заявник: ' . $this->escape($request->getAuthor()->displayName());
            if ($request->getAuthor()->getPhoneNumber()) {
                $lines[] = '📞 ' . $this->escape($request->getAuthor()->getPhoneNumber());
            }
            if ($request->getDepartment()) {
                $lines[] = '🏢 Підрозділ: ' . $this->escape($request->getDepartment()->getName());
            }
        }

        if ($request->getNote()) {
            $lines[] = '📝 ' . $this->escape($request->getNote());
        }

        // Постачальника й суму бачать усі, зокрема заявник: він має розуміти,
        // що його заявку закрили конкретною покупкою.
        foreach ($request->getPurchases() as $purchase) {
            $line = sprintf(
                '🧾 %s — <b>%s</b>',
                $this->escape($purchase->getSupplier()->getName()),
                $purchase->getTotalLabel(),
            );

            if ($purchase->getInvoiceNumber()) {
                $line .= ' · накладна ' . $this->escape($purchase->getInvoiceNumber());
            }

            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = 'Статус: <b>' . $request->getStatus()->labelWithEmoji() . '</b>';

        return implode("\n", $lines);
    }

    /** Короткий рядок для списку «Мої заявки». */
    public function line(SupplyRequest $request): string
    {
        return sprintf(
            '%s%s <b>№%s</b> · %s — %s · %s',
            $request->getAccent()->emoji(),
            $request->getStatus()->emoji(),
            $this->escape($request->getNumber()),
            $this->escape($request->getItem()),
            $request->getQuantityLabel(),
            $request->getStatus()->label(),
        );
    }

    /**
     * Одна хронологія: коментарі та зміни статусу разом, за часом.
     * У картці не має бути «коментарі окремо, історія окремо».
     */
    public function timeline(SupplyRequest $request, int $limit = 20): string
    {
        $events = [];

        foreach ($request->getStatusLogs() as $log) {
            if ($log->getStatusFrom() === null) {
                continue; // створення вже видно з самої картки
            }

            $text = sprintf('%s <b>%s</b>', $log->getStatusTo()->emoji(), $log->getStatusTo()->label());
            if ($log->getComment()) {
                $text .= ': <i>' . $this->escape($log->getComment()) . '</i>';
            }

            $events[] = ['at' => $log->getCreatedAt(), 'who' => $log->getAuthor()?->displayName(), 'text' => $text];
        }

        foreach ($request->getComments() as $comment) {
            $events[] = [
                'at' => $comment->getCreatedAt(),
                'who' => $comment->getAuthor()?->displayName(),
                'text' => '💬 ' . $this->escape($comment->getText()),
            ];
        }

        if (! $events) {
            return '';
        }

        usort($events, static fn (array $a, array $b) => $a['at'] <=> $b['at']);
        $events = array_slice($events, -$limit);

        $lines = ['', '<b>Хронологія:</b>'];
        foreach ($events as $event) {
            $lines[] = sprintf(
                '%s — %s%s',
                $event['at']->format('d.m H:i'),
                $event['text'],
                $event['who'] ? ' <i>(' . $this->escape($event['who']) . ')</i>' : '',
            );
        }

        return implode("\n", $lines);
    }

    /** «20 серпня 2026». */
    public function date(DateTimeInterface $date): string
    {
        return sprintf(
            '%d %s %d',
            (int) $date->format('j'),
            self::MONTHS[(int) $date->format('n')],
            (int) $date->format('Y'),
        );
    }

    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
