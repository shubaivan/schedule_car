<?php

namespace App\Plan\Drive;

use DateTimeInterface;

/**
 * Дерево тек «Плану» на Диску — одне місце, щоб його можна було перевірити тестом:
 *
 *     План / 2026 / Задачі / №0012 Плити для обʼєкта / 2026-11-17 креслення.pdf
 *     План / 2026 / Постачання / №042-2026 Цемент М400 / 2026-11-17 Накладна 042-2026.pdf
 *     План / 2026 / Склад / Клієнти / ТОВ Будінвест / 2026-11-17 Договір — ….pdf
 *     План / 2026 / Ліди / 2026-11-15 Олег / транскрипт.txt
 *
 * Рік — за датою створення суб'єкта; перейменування задачі теку не рухає
 * (на Диску це архів, а не дзеркало назв). Файл — з датою завантаження спереду
 * (dated), щоб у теці було видно, що коли прийшло; збіг імен — « (id)» (withId).
 *
 * Розділ постачання — «Постачання», як у меню (17.11.2026; до того — «Заявки»).
 * Теки на Диску шукаються за назвою, тож нові файли підуть у нову теку «Постачання»;
 * стара «Заявки» лишається як є (у ній тестові файли до очищення 17.11, у базі на
 * них уже немає посилань).
 */
final readonly class DrivePaths
{
    public const string TASKS = 'Задачі';
    public const string SUPPLY = 'Постачання';
    public const string WAREHOUSE = 'Склад';
    public const string LEADS = 'Ліди';

    public function __construct(private string $rootFolder = 'План')
    {
    }

    /**
     * @param list<string> $rest
     *
     * @return list<string>
     */
    public function under(DateTimeInterface $created, string $section, array $rest): array
    {
        $path = [$created->format('Y'), $section, ...$rest];

        if (trim($this->rootFolder) !== '') {
            array_unshift($path, $this->rootFolder);
        }

        return array_map(self::safe(...), $path);
    }

    /** @return list<string> */
    public function task(DateTimeInterface $created, string $number, string $title): array
    {
        return $this->under($created, self::TASKS, [sprintf('№%s %s', $number, $title)]);
    }

    /** @return list<string> */
    public function supply(DateTimeInterface $created, string $number, string $item): array
    {
        return $this->under($created, self::SUPPLY, [sprintf('№%s %s', str_replace('/', '-', $number), $item)]);
    }

    /**
     * @param list<string> $warehousePath шлях складу без кореня «Склад» (Клієнти/…, Позиції/…, Надходження/…)
     *
     * @return list<string>
     */
    public function warehouse(DateTimeInterface $created, array $warehousePath): array
    {
        return $this->under($created, self::WAREHOUSE, $warehousePath);
    }

    /** Для потоку SL (ліди): тека діалогу «2026-11-15 Олег». @return list<string> */
    public function lead(DateTimeInterface $received, string $name): array
    {
        return $this->under($received, self::LEADS, [sprintf('%s %s', $received->format('Y-m-d'), $name)]);
    }

    /** Скісні риски в назві теки Диска заборонені, зайві пробіли — шум; 120 символів досить. */
    public static function safe(string $name): string
    {
        $name = str_replace(['/', '\\'], '-', trim($name));
        $name = mb_substr((string) preg_replace('/\s+/u', ' ', $name), 0, 120);

        return $name !== '' ? $name : '—';
    }

    /** «акт.pdf» → «2026-11-17 акт.pdf»: дата завантаження (київська) спереду. */
    public static function dated(DateTimeInterface $uploaded, string $name): string
    {
        // Спершу ті самі заміни, що й safe() (скісні, зайві пробіли), — потім міряємо й ріжемо.
        $full = (string) preg_replace('/\s+/u', ' ', str_replace(['/', '\\'], '-', $uploaded->format('Y-m-d') . ' ' . trim($name)));
        $extension = pathinfo($full, PATHINFO_EXTENSION);

        // Задовга назва: обрізаємо основу, а розширення лишаємо — інакше на Диску «….pd» без типу.
        if (mb_strlen($full) > 120 && $extension !== '' && mb_strlen($extension) <= 10) {
            $base = mb_substr($full, 0, mb_strlen($full) - mb_strlen($extension) - 1);

            return self::safe(mb_substr($base, 0, 119 - mb_strlen($extension)) . '.' . $extension);
        }

        return self::safe($full);
    }

    /** «акт.pdf» → «акт (17).pdf», коли в теці вже є файл із тим самим ім'ям. */
    public static function withId(string $name, int $id): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = $extension !== '' ? mb_substr($name, 0, mb_strlen($name) - mb_strlen($extension) - 1) : $name;

        return sprintf('%s (%d)%s', $base, $id, $extension !== '' ? '.' . $extension : '');
    }
}
