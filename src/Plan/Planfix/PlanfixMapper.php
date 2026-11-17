<?php

namespace App\Plan\Planfix;

use App\Tasks\Entity\TaskMessage;
use App\Tasks\Enum\TaskStatus;
use DateTime;
use DateTimeZone;

/**
 * Чисті правила перетворення вивантаження Planfix на задачі «Плану» — без бази.
 *
 * Угода чи задача: у вивантаженні типу немає; угода — це задача з шаблоном
 * замовлення (поле оплати 57554 або виробниче 57556) або зі статусом-етапом воронки.
 * Назви користувацьких полів у вивантаженні теж відсутні — FIELDS складено за
 * вмістом, правити тут.
 */
final class PlanfixMapper
{
    public const string KIND_DEAL = 'deal';
    public const string KIND_TASK = 'task';

    public const string EVENTS_NONE = 'none';
    public const string EVENTS_MEANINGFUL = 'meaningful';
    public const string EVENTS_ALL = 'all';

    /** Що робимо з дією: коментар, системна подія або пропуск. */
    public const string ACTION_COMMENT = 'comment';
    public const string ACTION_EVENT = 'event';
    public const string ACTION_SKIP = 'skip';

    public const string LABEL_ALL = 'Planfix';
    public const string LABEL_URGENT = 'Planfix: термінова';
    public const string STAGE_PREFIX = 'Етап: ';

    /** Хто людина в Planfix — від цього підпис заглушки в Плані. */
    public const string PERSON_ACTIVE = 'active';
    public const string PERSON_FORMER = 'former';
    public const string PERSON_CONTACT = 'contact';

    private const array PERSON_NAMES = [
        self::PERSON_ACTIVE => 'Співробітник Planfix',
        self::PERSON_FORMER => 'Колишній співробітник',
        self::PERSON_CONTACT => 'Контакт з сайту',
    ];

    public const string FILE_NOTE = '[файл з Planfix не перенесено]';

    /** Базові статуси Planfix (рос. назви системних статусів). */
    private const array STATUSES = [
        'Новая' => TaskStatus::New,
        'Черновик' => TaskStatus::New,
        'В работе' => TaskStatus::InWork,
        'Выполненная' => TaskStatus::Done,
        'Завершенная' => TaskStatus::Done,
        'Отмененная' => TaskStatus::Rejected,
    ];

    /** Етапи воронки угод — у «Плані» це «В роботі» + мітка «Етап: …». */
    public const array STAGES = [
        'Виявлення потреби', 'Прорахунок', 'Ціноутворення', 'Створення та відправка КП',
        'Виставлення рахунку', 'Створення та відправка Рахунку', 'Чекаємо на оплату',
        'Планирование производства', 'Производится сейчас', 'Виробництво', 'ВТК', 'Логіст',
        'Завершення замовлення',
    ];

    /** Поля, за якими задача — угода (шаблон замовлення / виробничого замовлення). */
    private const array DEAL_FIELDS = ['57554', '57556'];

    /** id поля → назва в описі (здогадка за вмістом). Порожні значення й «0» не виводимо. */
    public const array FIELDS = [
        '57592' => 'Компанія',
        '57594' => 'Контактна особа',
        '57596' => 'Сфера діяльності',
        '57598' => 'Що будують',
        '57604' => 'Групи товарів',
        '57602' => 'Обʼєкт / примітка',
        '57640' => 'Мета дзвінка',
        '57652' => 'Дата дзвінка',
        '57646' => 'Коментар',
        '57648' => 'Результат дзвінка',
        '57642' => 'Наступний дзвінок',
        '57554' => 'Оплата',
        '57552' => 'Сума',
        '53298' => 'Сума (стара)',
        '56156' => '№ рахунку / документа',
        '53306' => 'Початок',
        '53308' => 'Кінець',
        '53304' => 'Відповідь',
        '53296' => 'Примітка',
        '57544' => 'Поле 57544',
        '57546' => 'Поле 57546',
        '57556' => 'Шифр виробу',
        '57558' => 'Кількість',
        '57560' => 'Кількість (2)',
        '57574' => 'Проліт',
        '57576' => 'Тікет',
        '57578' => 'Акаунт',
        '57580' => 'Витрачено часу',
        '57582' => 'Оцінка часу',
        '57584' => 'Пріоритет',
        '57586' => 'Дата тікета',
    ];

    /** Компанія, далі контактна особа — у task.client. */
    private const array CLIENT_FIELDS = ['57592', '57594'];

    /** Дії, які лише шумлять у чаті (нагадування, прострочки, прийняття). */
    private const array NOISE = [
        'TaskAccepted', 'TaskOverdued', 'TaskCloseToDeadline', 'Reminder', 'TaskNotAcceptedInTime',
        'ChangeDateRequest', 'ChangeDateRequestInactive', 'ChangeDateRequestResult', 'TaskAccessRequest',
        'TaskAccessRequest_Inactive', 'AccessRequestResult', 'EmailSent', 'TaskCheckChanged',
    ];

    /** Події з людьми: тип → підпис. */
    private const array MEMBER_EVENTS = [
        'WorkerEmployed' => 'виконавці +',
        'WorkerUnemployed' => 'виконавці −',
        'MemberAdded' => 'учасники +',
        'MemberRemoved' => 'учасники −',
        'AuditorAdded' => 'аудитори +',
        'AuditorRemoved' => 'аудитори −',
    ];

    /** Плейсхолдери локалізації Planfix у «змінено»: видима частина — нове значення. */
    private const array CHANGED_FIELDS = [
        '{log29}' => 'назва',
        '{log33}' => 'проєкт',
        '{log35}' => 'опис',
        '{log37}' => 'важливість',
    ];

    /** Мітка етапу воронки — одна на staging, --apply і звіт (task_label.name ≤ 60). */
    public static function stageLabel(string $stage): string
    {
        return mb_substr(self::STAGE_PREFIX . $stage, 0, 60);
    }

    /** Підпис заглушки: «Колишній співробітник» + «#id» (імʼя й прізвище в telegram_user). */
    public static function personName(string $role): string
    {
        return self::PERSON_NAMES[$role] ?? self::PERSON_NAMES[self::PERSON_FORMER];
    }

    /**
     * Контакт (заявка з сайту чи листа), а не співробітник: немає в users.json, ніколи не
     * виконавець, сам нічого не писав (жодного Comment/Action) і всі його дії — лише
     * в задачах, де він автор (створення заявки сценарієм).
     */
    public static function personRole(bool $active, bool $everWorker, bool $wrote, bool $onlyOwnTasks, bool $ownsTasks): string
    {
        if ($active) {
            return self::PERSON_ACTIVE;
        }

        return ! $everWorker && ! $wrote && $onlyOwnTasks && $ownsTasks ? self::PERSON_CONTACT : self::PERSON_FORMER;
    }

    public static function date(?string $value): ?DateTime
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $date = DateTime::createFromFormat('!d-m-Y H:i', $value, new DateTimeZone('Europe/Kyiv'))
            ?: DateTime::createFromFormat('!d-m-Y', $value, new DateTimeZone('Europe/Kyiv'));

        return $date ?: null;
    }

    /** @param array<string, mixed> $task */
    public static function kind(array $task): string
    {
        $fields = is_array($task['customData'] ?? null) ? $task['customData'] : [];

        foreach (self::DEAL_FIELDS as $id) {
            if (array_key_exists($id, $fields)) {
                return self::KIND_DEAL;
            }
        }

        return in_array((string) ($task['status'] ?? ''), self::STAGES, true) ? self::KIND_DEAL : self::KIND_TASK;
    }

    /** @return array{0: TaskStatus, 1: ?string} статус і етап воронки (для мітки) */
    public static function status(string $planfix): array
    {
        if (isset(self::STATUSES[$planfix])) {
            return [self::STATUSES[$planfix], null];
        }

        // Етап воронки — і невідомий статус теж: робота триває, назву бачимо міткою.
        return [TaskStatus::InWork, $planfix !== '' ? $planfix : null];
    }

    /**
     * Поля угоди рядками «Назва: значення» у порядку FIELDS.
     *
     * @param array<array-key, mixed> $fields
     *
     * @return list<string>
     */
    public static function fieldLines(array $fields): array
    {
        $lines = [];

        foreach (self::FIELDS as $id => $label) {
            $value = self::text((string) ($fields[$id] ?? ''));

            if ($value === '' || $value === '0' || $value === '0.00' || mb_strtolower($value) === 'no name') {
                continue;
            }

            $lines[] = $label . ': ' . self::oneLine($value, 500);
        }

        return $lines;
    }

    /** @param array<array-key, mixed> $fields */
    public static function client(array $fields): ?string
    {
        foreach (self::CLIENT_FIELDS as $id) {
            $value = self::oneLine(self::text((string) ($fields[$id] ?? '')), 255);

            if ($value !== '' && mb_strtolower($value) !== 'no name') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Опис задачі: шапка з номером Planfix, поля угоди, далі оригінальний опис.
     *
     * @param list<string> $fieldLines
     */
    public static function description(int $number, string $kind, ?string $project, ?int $parentNumber, string $createdAt, array $fieldLines, string $original): string
    {
        $head = sprintf('Із Planfix № %d · %s', $number, $kind === self::KIND_DEAL ? 'угода' : 'задача');

        if ($project !== null && $project !== '') {
            $head .= ' · проєкт «' . $project . '»';
        }

        $head .= ' · створено ' . $createdAt;
        $parts = [$head];

        if ($parentNumber !== null) {
            $parts[] = 'Підзадача Planfix № ' . $parentNumber;
        }

        if ($fieldLines !== []) {
            $parts[] = ($kind === self::KIND_DEAL ? 'Поля угоди:' : 'Поля:') . "\n" . implode("\n", $fieldLines);
        }

        $body = self::text($original);

        if ($body !== '') {
            $parts[] = $body;
        }

        return implode("\n\n", $parts);
    }

    public static function title(string $title, int $number): string
    {
        $title = self::oneLine(self::text($title), 255);

        return $title !== '' ? $title : sprintf('Без назви (Planfix № %d)', $number);
    }

    /**
     * Що стане з дією в чаті задачі.
     *
     * @return array{0: string, 1: ?string, 2: string} дія (comment|event|skip), event_type, текст
     */
    public static function action(string $type, string $html, string $events = self::EVENTS_MEANINGFUL): array
    {
        if ($type === 'Comment' || $type === 'Action') {
            $text = self::text($html);

            return $text === '' ? [self::ACTION_SKIP, null, ''] : [self::ACTION_COMMENT, null, $text];
        }

        // Створення — своя подія на кожну задачу (не з дії: у 897 дій це контакти, а не задачі).
        if ($type === 'TaskCreated' || $events === self::EVENTS_NONE) {
            return [self::ACTION_SKIP, null, ''];
        }

        if (isset(self::MEMBER_EVENTS[$type])) {
            $who = self::names($html);

            return [self::ACTION_EVENT, TaskMessage::EVENT_MEMBERS, 'Planfix: ' . self::MEMBER_EVENTS[$type] . ($who !== '' ? ' ' . $who : '')];
        }

        if ($type === 'TaskChanged') {
            $changed = self::changed($html);

            // «Змінено» без жодної деталі (у вивантаженні таких більшість) — лише шум.
            return $changed === null && $events !== self::EVENTS_ALL
                ? [self::ACTION_SKIP, null, '']
                : [self::ACTION_EVENT, TaskMessage::EVENT_UPDATED, 'Planfix: задачу змінено' . ($changed !== null ? ' (' . $changed . ')' : '')];
        }

        if ($events !== self::EVENTS_ALL) {
            return [self::ACTION_SKIP, null, ''];
        }

        if ($type === 'StatusChanged') {
            return [self::ACTION_EVENT, TaskMessage::EVENT_STATUS, 'Planfix: статус змінено'];
        }

        $text = self::text($html);

        return [self::ACTION_EVENT, TaskMessage::EVENT_UPDATED, 'Planfix: ' . $type . ($text !== '' ? ' — ' . self::oneLine($text, 300) : '')];
    }

    public static function isNoise(string $type): bool
    {
        return in_array($type, self::NOISE, true);
    }

    /**
     * HTML Planfix → простий текст. Посилання на файли Planfix (з токеном planfixauth)
     * замінюються позначкою, плейсхолдери локалізації ({log26}, {event21}…) прибираються.
     */
    public static function text(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $s = str_replace(["\r\n", "\r"], "\n", $html);
        // Файли й картинки Planfix: тіла у вивантаженні немає, а в посиланні — токен доступу.
        $s = (string) preg_replace('~<a\b[^>]*action=getfile[^>]*>.*?</a>~isu', ' ' . self::FILE_NOTE . ' ', $s);
        $s = (string) preg_replace('~<img\b[^>]*action=getfile[^>]*>~isu', ' ' . self::FILE_NOTE . ' ', $s);
        $s = (string) preg_replace('~<img\b[^>]*>~isu', ' [зображення] ', $s);
        $s = (string) preg_replace_callback('~<a\b[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)</a>~isu', static function (array $m): string {
            $label = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Внутрішні посилання Planfix (люди, задачі, контакти) — лише текст.
            if (! preg_match('~^https?://~i', $href) || str_contains($href, 'planfix.com')) {
                return $label;
            }

            return $label === '' || $label === $href ? $href : $label . ' (' . $href . ')';
        }, $s);
        $s = (string) preg_replace('~<\s*br\s*/?>~iu', "\n", $s);
        $s = (string) preg_replace('~<\s*li\b[^>]*>~iu', "\n• ", $s);
        $s = (string) preg_replace('~</\s*(p|div|ul|ol|tr|h[1-6]|blockquote)\s*>~iu', "\n", $s);
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace("\u{00A0}", ' ', $s);
        // Залишки токенів у «голих» посиланнях.
        $s = (string) preg_replace('~https?://\S*planfixauth=\S*~iu', self::FILE_NOTE, $s);
        $s = (string) preg_replace('~\{[a-z]+\d+\}:?~u', '', $s);
        $s = (string) preg_replace('~[ \t]+~u', ' ', $s);
        $s = (string) preg_replace('~ *\n *~u', "\n", $s);
        $s = (string) preg_replace("~\n{3,}~u", "\n\n", $s);

        return trim($s);
    }

    public static function oneLine(string $text, int $max): string
    {
        $text = trim((string) preg_replace('~\s+~u', ' ', $text));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    /** «Більше не є виконавцями: <a>Імʼя</a>, [Відділ]» → «Імʼя, [Відділ]». */
    private static function names(string $html): string
    {
        $first = preg_split('~<\s*br\s*/?>~iu', $html)[0] ?? $html;
        preg_match_all('~<a\b[^>]*>(.*?)</a>|(\[[^\]]+\])~isu', $first, $m, PREG_SET_ORDER);
        $names = [];

        foreach ($m as $hit) {
            $name = trim(html_entity_decode(strip_tags($hit[1] !== '' ? $hit[1] : ($hit[2] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return self::oneLine(implode(', ', $names), 500);
    }

    /** «Задачу змінено»: видима частина — нові значення; старі (log-extra) відкидаємо. */
    private static function changed(string $html): ?string
    {
        $visible = preg_split('~<div class=["\']log-expand~iu', $html)[0] ?? $html;
        preg_match_all('~\[<i>(\{log\d+\})</i>\]:\s*(.*?)(?=\[<i>\{log|$)~isu', $visible, $m, PREG_SET_ORDER);
        $parts = [];

        foreach ($m as $hit) {
            $label = self::CHANGED_FIELDS[$hit[1]] ?? 'поле';
            // Опис цілком лежить в описі задачі — у чаті лише факт зміни.
            $value = $hit[1] === '{log35}' ? '' : self::oneLine(self::text($hit[2]), 200);
            $parts[] = $value === '' ? $label : $label . ' → ' . $value;
        }

        return $parts !== [] ? implode('; ', $parts) : null;
    }
}
