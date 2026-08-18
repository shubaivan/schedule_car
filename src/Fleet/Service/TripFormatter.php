<?php

namespace App\Fleet\Service;

use App\Entity\ScheduledSet;
use DateTimeInterface;

/**
 * Як показувати поїздку. Одні й ті самі рядки бачать заявник, водій і керівник —
 * щоб у розмові всі говорили про одне й те саме.
 */
class TripFormatter
{
    private const WEEKDAYS = [1 => 'пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'нд'];

    public function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Заголовок дня: «пн 18.08». */
    public function day(DateTimeInterface $date): string
    {
        return sprintf('%s %s', self::WEEKDAYS[(int) $date->format('N')], $date->format('d.m'));
    }

    /**
     * Рядок розкладу: час, машина, хто взяв, телефон і завдання.
     *
     * Телефон і завдання тут не випадково: побачивши, що машина зайнята, людина
     * має одразу знати, з ким домовлятись і чи можна поїхати разом.
     */
    public function line(ScheduledSet $set): string
    {
        $user = $set->getTelegramUserId();
        $phone = $user->getPhoneNumber();

        $line = sprintf(
            '%s:00 · <b>%s</b> · %s',
            str_pad((string) $set->getHour(), 2, '0', STR_PAD_LEFT),
            $this->escape($set->getCar()->getCarNumber()),
            $this->escape($user->displayName()),
        );

        if ($phone !== null && $phone !== '') {
            $line .= ' · ' . $this->escape($phone);
        }

        $task = $set->getTask();

        if ($task !== null && $task !== '') {
            $line .= "\n    ↳ " . $this->escape($task);
        }

        return $line;
    }

    /** Той самий рядок, але для водія: машина його, тож у ній сенсу немає. */
    public function driverLine(ScheduledSet $set): string
    {
        $user = $set->getTelegramUserId();
        $phone = $user->getPhoneNumber();

        $line = sprintf(
            '%s %s:00 · %s',
            $this->day($set->getScheduledDateTime()),
            str_pad((string) $set->getHour(), 2, '0', STR_PAD_LEFT),
            $this->escape($user->displayName()),
        );

        if ($phone !== null && $phone !== '') {
            $line .= ' · ' . $this->escape($phone);
        }

        $task = $set->getTask();

        if ($task !== null && $task !== '') {
            $line .= "\n    ↳ " . $this->escape($task);
        }

        return $line;
    }
}
