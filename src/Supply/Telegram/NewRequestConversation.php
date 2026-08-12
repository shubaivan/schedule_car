<?php

namespace App\Supply\Telegram;

use App\Service\ChatScreen;
use App\Service\TelegramUserService;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Enum\Unit;
use App\Supply\Exception\SupplyException;
use App\Supply\Service\CreateRequest;
use App\Supply\Service\RequestFormatter;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Подача заявки робітником: що → скільки → до якої дати → коментар.
 *
 * Уся форма живе в ОДНОМУ повідомленні, яке перемальовується на кожному кроці:
 * зверху накопичується вже введене, знизу — поточне питання. Відповіді текстом
 * прибираємо, щоб у чаті лишалась одна картка, а не стрічка з десяти реплік.
 */
class NewRequestConversation extends Conversation
{
    private const UNIT_PREFIX = 'u:';
    private const DATE_PREFIX = 'd:';
    private const NO_DATE = 'd:none';
    private const SKIP = 'skip';
    private const DAYS_OFFERED = 12;
    private const DAYS_PER_ROW = 3;

    private const WEEKDAYS = [1 => 'пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'нд'];

    protected ?string $step = 'askItem';

    public ?string $item = null;
    public ?string $quantity = null;
    public ?string $unit = null;
    public ?string $needBy = null;
    public bool $urgent = false;

    public function __construct(
        private CreateRequest $createRequest,
        private TelegramUserService $telegramUserService,
        private RequestFormatter $formatter,
        private ChatScreen $screen,
    ) {
    }

    public function askItem(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $this->render($bot, 'Що потрібно придбати? Напишіть назву та марку, наприклад: <i>Арматура 12 А500С</i>');

        $this->next('readItem');
    }

    public function readItem(Nutgram $bot): void
    {
        $text = trim((string)$bot->message()?->text);
        $this->forgetUserMessage($bot);

        if ($text === '') {
            $this->render($bot, '⚠️ Напишіть текстом, що саме потрібно.');

            return;
        }

        $this->item = mb_substr($text, 0, 255);

        $this->render($bot, 'Скільки потрібно? Введіть число, наприклад <i>2</i> або <i>2.5</i>');

        $this->next('readQuantity');
    }

    public function readQuantity(Nutgram $bot): void
    {
        $raw = str_replace(',', '.', trim((string)$bot->message()?->text));
        $this->forgetUserMessage($bot);

        if (!is_numeric($raw) || (float)$raw <= 0) {
            $this->render($bot, '⚠️ Потрібне число більше за нуль. Спробуйте ще раз.');

            return;
        }

        $this->quantity = $raw;

        $this->render($bot, 'Одиниця виміру:', $this->unitKeyboard());

        $this->next('readUnit');
    }

    public function readUnit(Nutgram $bot): void
    {
        $data = (string)($bot->callbackQuery()?->data ?? '');

        if (!str_starts_with($data, self::UNIT_PREFIX)) {
            $this->render($bot, '⚠️ Оберіть одиницю виміру кнопкою:', $this->unitKeyboard());

            return;
        }

        $unit = Unit::tryFrom(substr($data, strlen(self::UNIT_PREFIX)));

        if ($unit === null) {
            $this->render($bot, '⚠️ Оберіть одиницю виміру кнопкою:', $this->unitKeyboard());

            return;
        }

        $bot->answerCallbackQuery();
        $this->unit = $unit->value;

        $this->render($bot, 'До якої дати потрібно?', $this->dateKeyboard());

        $this->next('readNeedBy');
    }

    public function readNeedBy(Nutgram $bot): void
    {
        $data = (string)($bot->callbackQuery()?->data ?? '');
        $today = new \DateTime('today', new \DateTimeZone('Europe/Kyiv'));

        if (str_starts_with($data, self::DATE_PREFIX)) {
            $bot->answerCallbackQuery();
            $value = substr($data, strlen(self::DATE_PREFIX));

            if ($value === 'none') {
                $this->needBy = null;
                $this->urgent = false;
            } else {
                $date = \DateTime::createFromFormat('Y-m-d H:i:s', $value . ' 00:00:00', new \DateTimeZone('Europe/Kyiv'));

                if ($date === false) {
                    $this->render($bot, '⚠️ Оберіть дату кнопкою:', $this->dateKeyboard());

                    return;
                }

                $this->needBy = $date->format('Y-m-d');
                $this->urgent = $date <= $today;
            }
        } else {
            // Дозволяємо вписати дату руками: 20.08 або 20.08.2026.
            $text = trim((string)$bot->message()?->text);
            $this->forgetUserMessage($bot);
            $date = $this->parseDate($text, $today);

            if ($date === null) {
                $this->render($bot, '⚠️ Оберіть дату кнопкою або напишіть її як 20.08.2026:', $this->dateKeyboard());

                return;
            }

            $this->needBy = $date->format('Y-m-d');
            $this->urgent = $date <= $today;
        }

        $this->render(
            $bot,
            'Коментар до заявки (для чого, куди привезти) — або пропустіть:',
            InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('Пропустити', callback_data: self::SKIP),
            ),
        );

        $this->next('readNote');
    }

    public function readNote(Nutgram $bot): void
    {
        $note = null;

        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        } else {
            $note = trim((string)$bot->message()?->text) ?: null;
            $this->forgetUserMessage($bot);
        }

        $author = $this->telegramUserService->getCurrentUser();

        if ($author === null) {
            $this->render($bot, '⚠️ Не вдалось визначити користувача. Натисніть /start і спробуйте ще раз.');
            $this->end();

            return;
        }

        $input = new CreateRequestInput(
            item: (string)$this->item,
            quantity: (string)$this->quantity,
            unit: Unit::from((string)$this->unit),
            needBy: $this->needBy !== null
                ? new \DateTime($this->needBy, new \DateTimeZone('Europe/Kyiv'))
                : null,
            urgent: $this->urgent,
            note: $note,
        );

        try {
            // Готову картку заявнику надішле SupplyNotifier, тож форму прибираємо.
            ($this->createRequest)($author, $input);
            $this->screen->close($bot);
        } catch (SupplyException $e) {
            $this->render($bot, '⚠️ ' . $this->formatter->escape($e->getMessage()));
        }

        $this->end();
    }

    /** Одне повідомлення: зібране зверху, поточне питання знизу. */
    private function render(Nutgram $bot, string $question, ?InlineKeyboardMarkup $markup = null): void
    {
        $this->screen->render($bot, $this->summary() . "\n" . $question, $markup);
    }

    private function summary(): string
    {
        $lines = ['📦 <b>Нова заявка</b>', ''];

        if ($this->item !== null) {
            $lines[] = '✅ Що: <b>' . $this->formatter->escape($this->item) . '</b>';
        }

        if ($this->quantity !== null) {
            $unit = $this->unit !== null ? ' ' . Unit::from($this->unit)->label() : '';
            $lines[] = '✅ Скільки: <b>' . $this->formatter->escape($this->quantity) . $unit . '</b>';
        }

        if ($this->needBy !== null) {
            $lines[] = sprintf(
                '✅ Потрібно до: <b>%s</b>%s',
                $this->formatter->date(new \DateTime($this->needBy)),
                $this->urgent ? ' 🔥' : '',
            );
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /** Прибираємо відповідь користувача, щоб у чаті лишалась сама форма. */
    private function forgetUserMessage(Nutgram $bot): void
    {
        $chatId = $bot->chatId();
        $messageId = $bot->message()?->message_id;

        if ($chatId === null || $messageId === null) {
            return;
        }

        try {
            $bot->deleteMessage($chatId, $messageId);
        } catch (\Throwable) {
            // Не критично: повідомлення просто лишиться в чаті.
        }
    }

    private function unitKeyboard(): InlineKeyboardMarkup
    {
        $markup = InlineKeyboardMarkup::make();
        $row = [];

        foreach (Unit::cases() as $unit) {
            $row[] = InlineKeyboardButton::make($unit->label(), callback_data: self::UNIT_PREFIX . $unit->value);

            if (count($row) === 4) {
                $markup->addRow(...$row);
                $row = [];
            }
        }

        if ($row) {
            $markup->addRow(...$row);
        }

        return $markup;
    }

    /** Календарик на найближчі два тижні + «без терміну». */
    private function dateKeyboard(): InlineKeyboardMarkup
    {
        $markup = InlineKeyboardMarkup::make();
        $day = new \DateTime('today', new \DateTimeZone('Europe/Kyiv'));
        $row = [];

        for ($i = 0; $i < self::DAYS_OFFERED; $i++) {
            $label = sprintf(
                '%s%s %s',
                $i === 0 ? '🔥 ' : '',
                $day->format('d.m'),
                self::WEEKDAYS[(int)$day->format('N')],
            );

            $row[] = InlineKeyboardButton::make($label, callback_data: self::DATE_PREFIX . $day->format('Y-m-d'));

            if (count($row) === self::DAYS_PER_ROW) {
                $markup->addRow(...$row);
                $row = [];
            }

            $day->modify('+1 day');
        }

        if ($row) {
            $markup->addRow(...$row);
        }

        $markup->addRow(InlineKeyboardButton::make('Без конкретного терміну', callback_data: self::NO_DATE));

        return $markup;
    }

    private function parseDate(string $text, \DateTime $today): ?\DateTime
    {
        if (!preg_match('/^(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{4}))?$/', $text, $m)) {
            return null;
        }

        $year = isset($m[3]) ? (int)$m[3] : (int)$today->format('Y');
        $date = \DateTime::createFromFormat(
            'Y-n-j H:i:s',
            sprintf('%d-%d-%d 00:00:00', $year, (int)$m[2], (int)$m[1]),
            new \DateTimeZone('Europe/Kyiv'),
        );

        return $date ?: null;
    }
}
