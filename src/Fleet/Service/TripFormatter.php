<?php

namespace App\Fleet\Service;

use App\Entity\Car;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use DateTimeInterface;

/**
 * Як показувати поїздку. Одні й ті самі рядки бачать заявник, водій і керівник —
 * щоб у розмові всі говорили про одне й те саме.
 *
 * Порядок у рядку не випадковий: спершу час і куди їде машина, потім хто її взяв
 * і за яким телефоном. Розклад читають, щоб зрозуміти завантаження машини й
 * водія на день, тож маршрут стоїть попереду імені.
 */
class TripFormatter
{
    private const WEEKDAYS = [1 => 'пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'нд'];

    /**
     * Екрануємо тільки <, > і & — саме їх Telegram вимагає в HTML-розмітці.
     *
     * Апостроф і лапки лишаємо як є: у тексті повідомлення вони не всередині
     * атрибута, а перетворені на &#039; вони й показувались у чаті сирим кодом —
     * «Кар&#039;єр» замість «Кар'єр».
     */
    public function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Заголовок дня: «пн 18.08». */
    public function day(DateTimeInterface $date): string
    {
        return sprintf('%s %s', self::WEEKDAYS[(int) $date->format('N')], $date->format('d.m'));
    }

    public function hour(ScheduledSet $set): string
    {
        return str_pad((string) $set->getHour(), 2, '0', STR_PAD_LEFT) . ':00';
    }

    /**
     * Шапка машини в розкладі: сама машина і закріплений за нею водій.
     *
     * Водій тут — половина відповіді на питання «чи вільна машина завтра»:
     * зайнята не тільки техніка, зайнята й людина, яка нею їде.
     */
    /** @param TelegramUser[] $drivers водії, закріплені за цією машиною */
    public function carHeading(Car $car, array $drivers): string
    {
        $heading = '🚗 <b>' . $this->escape($car->label()) . '</b>';

        if ($drivers === []) {
            return $heading . "\n    водій: не закріплений";
        }

        $names = array_map(function (TelegramUser $driver): string {
            $phone = $driver->getPhoneNumber();

            return $this->escape($driver->displayName())
                . ($phone !== null && $phone !== '' ? ' · ' . $this->escape($phone) : '');
        }, $drivers);

        return $heading . "\n    " . (count($names) > 1 ? 'водії: ' : 'водій: ') . implode(', ', $names);
    }

    /**
     * Бронь під шапкою машини: час, куди їде, хто взяв і навіщо.
     *
     * @return string[] рядки, які вже згруповані під своєю машиною
     */
    public function slotLines(ScheduledSet $set): array
    {
        $lines = [sprintf('    <b>%s</b> → %s', $this->hour($set), $this->escape($this->destinationOf($set)))];
        $lines[] = '        ' . $this->who($set);

        $task = $set->getTask();

        if ($task !== null && $task !== '') {
            $lines[] = '        ↳ ' . $this->escape($task);
        }

        return $lines;
    }

    /**
     * Самодостатній рядок однієї поїздки: коли, яка машина, куди й хто взяв.
     *
     * Потрібен там, де поїздки не згруповані за машинами — у виборі години під
     * час бронювання й у сповіщеннях.
     */
    public function line(ScheduledSet $set): string
    {
        $line = sprintf(
            '%s · <b>%s</b> → %s',
            $this->hour($set),
            $this->escape($set->getCar()->getCarNumber()),
            $this->escape($this->destinationOf($set)),
        );

        $line .= "\n    " . $this->who($set);

        $task = $set->getTask();

        if ($task !== null && $task !== '') {
            $line .= "\n    ↳ " . $this->escape($task);
        }

        return $line;
    }

    /** Той самий рейс очима водія: машина його, тож у рядку сенсу немає. */
    public function driverLine(ScheduledSet $set): string
    {
        $line = sprintf(
            '%s %s → %s',
            $this->day($set->getScheduledDateTime()),
            $this->hour($set),
            $this->escape($this->destinationOf($set)),
        );

        $line .= "\n    " . $this->who($set);

        $task = $set->getTask();

        if ($task !== null && $task !== '') {
            $line .= "\n    ↳ " . $this->escape($task);
        }

        return $line;
    }

    /** Куди їде. Старі броні маршруту не мають — там його заміняє завдання. */
    public function destinationOf(ScheduledSet $set): string
    {
        $destination = $set->getDestination();

        if ($destination !== null && $destination !== '') {
            return $destination;
        }

        $task = $set->getTask();

        return $task !== null && $task !== '' ? $task : 'маршрут не вказано';
    }

    /** Хто забронював і за яким телефоном із ним говорити. */
    private function who(ScheduledSet $set): string
    {
        $user = $set->getTelegramUserId();
        $phone = $user->getPhoneNumber();

        return 'взяв(ла): ' . $this->escape($user->displayName())
            . ($phone !== null && $phone !== '' ? ' · ' . $this->escape($phone) : '');
    }
}
