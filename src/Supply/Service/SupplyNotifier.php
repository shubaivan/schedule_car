<?php

namespace App\Supply\Service;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use App\Supply\Entity\SupplyAttachment;
use App\Supply\Entity\SupplyComment;
use App\Supply\Entity\SupplyPurchase;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Entity\SupplyStatusLog;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Telegram\SupplyCallback;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/**
 * Єдина точка сповіщень по заявках. Викликається лише з CreateRequest,
 * ChangeStatus, AddComment і RecordPurchase — якщо розсіяти sendMessage по
 * хендлерах, через місяць половина подій тихо перестане доходити до заявника.
 *
 * Вимога клієнта жорстка: заявник має знати про КОЖНУ зміну своєї заявки.
 * Перелік подій, які сюди доходять: створення, будь-яка зміна статусу,
 * коментар другої сторони, запис/правка/скасування закупівлі, прострочення.
 *
 * Помилка доставки (бот заблокований, чат не знайдено) не валить операцію:
 * заявка вже збережена, а проблема потрапляє в лог.
 */
class SupplyNotifier
{
    public function __construct(
        private Nutgram $bot,
        private TelegramUserRepository $userRepository,
        private RequestFormatter $formatter,
        private LoggerInterface $logger,
    ) {
    }

    public function requestCreated(SupplyRequest $request): void
    {
        $this->send(
            $request->getAuthor(),
            "✅ <b>Заявку прийнято</b>\n\n" . $this->formatter->card($request),
            $this->authorKeyboard($request),
        );

        $managerText = ($request->isUrgent() ? "🔥 <b>ТЕРМІНОВА нова заявка</b>\n\n" : "🆕 <b>Нова заявка</b>\n\n")
            . $this->formatter->card($request, forManager: true);

        foreach ($this->userRepository->findSupplyManagers() as $manager) {
            $this->send($manager, $managerText, $this->managerKeyboard($request));
        }
    }

    public function statusChanged(SupplyRequest $request, SupplyStatusLog $log): void
    {
        $author = $request->getAuthor();

        $text = sprintf(
            "%s <b>Заявка №%s — %s</b>\n\n%s",
            $log->getStatusTo()->emoji(),
            $this->formatter->escape($request->getNumber()),
            $this->formatter->escape($log->getStatusTo()->label()),
            $this->formatter->card($request),
        );

        if ($log->getComment()) {
            $label = $log->getStatusTo() === SupplyStatus::Rejected ? 'Причина' : 'Коментар';
            $text .= sprintf("\n\n%s: <i>%s</i>", $label, $this->formatter->escape($log->getComment()));
        }

        if ($log->getStatusTo() === SupplyStatus::InStock) {
            $text .= "\n\n📦 Матеріал на складі — можна забирати.";
        }

        // Сповіщаємо заявника; менеджер сам щойно натиснув кнопку.
        if ($log->getAuthor()?->getId() !== $author->getId()) {
            $this->send($author, $text, $this->authorKeyboard($request));
        }

        if ($log->getStatusTo() === SupplyStatus::Approval) {
            $this->askDirectors($request);
        }
    }

    /**
     * Заявка чекає рішення про оплату — питаємо тих, хто його ухвалює.
     *
     * Якщо погоджувачів у боті ще немає, це не тиха втрата: заявка лишається
     * «На затвердженні», а в лозі видно, що спитати не було кого.
     */
    private function askDirectors(SupplyRequest $request): void
    {
        $directors = $this->userRepository->findSupplyDirectors();

        if (! $directors) {
            $this->logger->warning('supply: заявка чекає погодження, а погоджувачів немає', [
                'number' => $request->getNumber(),
            ]);

            return;
        }

        $text = sprintf(
            "⏳ <b>Потрібне ваше погодження оплати</b>\nЗаявка №%s\n\n%s",
            $this->formatter->escape($request->getNumber()),
            $this->formatter->card($request, forManager: true),
        );

        foreach ($directors as $director) {
            $this->send($director, $text, $this->managerKeyboard($request));
        }
    }

    public function commentAdded(SupplyComment $comment): void
    {
        $request = $comment->getRequest();
        $author = $comment->getAuthor();

        $text = sprintf(
            "💬 <b>Новий коментар до заявки №%s</b>\n%s — %s\n\n<i>%s</i>",
            $this->formatter->escape($request->getNumber()),
            $this->formatter->escape($request->getItem()),
            $request->getQuantityLabel(),
            $this->formatter->escape($comment->getText()),
        );

        $from = $author ? $this->formatter->escape($author->displayName()) : 'Система';
        $text .= "\n\n👤 " . $from;

        // Писав заявник — сповіщаємо менеджерів; писав менеджер — сповіщаємо заявника.
        if ($author && $author->getId() === $request->getAuthor()->getId()) {
            foreach ($this->userRepository->findSupplyManagers() as $manager) {
                $this->send($manager, $text, $this->managerKeyboard($request));
            }

            return;
        }

        $this->send($request->getAuthor(), $text, $this->authorKeyboard($request));
    }

    /**
     * Записали (або виправили) закупівлю без зміни статусу.
     *
     * Заявник має знати, у кого і за скільки купили, навіть якщо статус ще не
     * рухався: домовились із постачальником сьогодні, а оплата пройде завтра.
     */
    public function purchaseRecorded(SupplyPurchase $purchase, bool $updated = false): void
    {
        $request = $purchase->getRequest();

        $text = sprintf(
            "🧾 <b>%s за заявкою №%s</b>\n\n%s",
            $updated ? 'Змінено закупівлю' : 'Записано закупівлю',
            $this->formatter->escape($request->getNumber()),
            $this->formatter->card($request),
        );

        $this->send($request->getAuthor(), $text, $this->authorKeyboard($request));
    }

    /**
     * Закупівлю прибрали — заявник бачив суму, тож має побачити й скасування.
     *
     * Постачальник і сума приходять рядками: викликається вже після видалення,
     * інакше картка в повідомленні показувала б скасовану закупівлю.
     */
    public function purchaseRemoved(SupplyRequest $request, string $supplier, string $total): void
    {
        $text = sprintf(
            "🧾 <b>Закупівлю за заявкою №%s скасовано</b>\n%s — %s\n\n%s",
            $this->formatter->escape($request->getNumber()),
            $this->formatter->escape($supplier),
            $this->formatter->escape($total),
            $this->formatter->card($request),
        );

        $this->send($request->getAuthor(), $text, $this->authorKeyboard($request));
    }

    /** До заявки прикріпили документ — заявник має бачити накладну так само, як статус. */
    public function fileAttached(SupplyAttachment $attachment): void
    {
        $request = $attachment->getRequest();

        $text = sprintf(
            "%s <b>Документ до заявки №%s</b>\n%s — %s\n\n%s",
            $attachment->getType()->emoji(),
            $this->formatter->escape($request->getNumber()),
            $this->formatter->escape($attachment->getType()->label()),
            $this->formatter->escape($attachment->getOriginalName()),
            $this->formatter->card($request),
        );

        $this->send($request->getAuthor(), $text, $this->authorKeyboard($request));
    }

    public function fileRemoved(SupplyRequest $request, string $name): void
    {
        $text = sprintf(
            "🗑 <b>Документ до заявки №%s прибрано</b>\n%s",
            $this->formatter->escape($request->getNumber()),
            $this->formatter->escape($name),
        );

        $this->send($request->getAuthor(), $text, $this->authorKeyboard($request));
    }

    /** Нагадування по простроченій заявці — і заявнику, і менеджерам. */
    public function overdue(SupplyRequest $request): void
    {
        $text = sprintf(
            "⚠️ <b>Заявка №%s прострочена</b>\n\n%s",
            $this->formatter->escape($request->getNumber()),
            $this->formatter->card($request, forManager: true),
        );

        $this->send($request->getAuthor(), $text, $this->authorKeyboard($request));

        foreach ($this->userRepository->findSupplyManagers() as $manager) {
            $this->send($manager, $text, $this->managerKeyboard($request));
        }
    }

    private function authorKeyboard(SupplyRequest $request): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('💬 Коментар', callback_data: SupplyCallback::comment((int) $request->getId())),
            InlineKeyboardButton::make('📋 Мої заявки', callback_data: SupplyCallback::MY_REQUESTS),
        );
    }

    private function managerKeyboard(SupplyRequest $request): InlineKeyboardMarkup
    {
        $id = (int) $request->getId();
        $markup = InlineKeyboardMarkup::make();

        $row = [];
        foreach ($request->getStatus()->allowedTransitions() as $next) {
            if ($next === SupplyStatus::Rejected) {
                continue;
            }
            $row[] = InlineKeyboardButton::make(
                $next->labelWithEmoji(),
                callback_data: SupplyCallback::status($id, $next->value),
            );
            if (count($row) === 2) {
                $markup->addRow(...$row);
                $row = [];
            }
        }
        if ($row) {
            $markup->addRow(...$row);
        }

        if ($request->getStatus()->canTransitionTo(SupplyStatus::Rejected)) {
            $markup->addRow(
                InlineKeyboardButton::make('⛔ Відхилити', callback_data: SupplyCallback::reject($id)),
            );
        }

        $markup->addRow(
            InlineKeyboardButton::make('💬 Коментар', callback_data: SupplyCallback::comment($id)),
        );

        return $markup;
    }

    private function send(TelegramUser $user, string $text, ?InlineKeyboardMarkup $markup = null): void
    {
        $chatId = $user->getChatId() ?: $user->getTelegramId();

        if (! $chatId) {
            $this->logger->warning('supply: у користувача немає chat_id', ['user' => $user->getId()]);

            return;
        }

        try {
            $this->bot->sendMessage(
                text: $text,
                chat_id: $chatId,
                parse_mode: ParseMode::HTML,
                reply_markup: $markup,
            );
        } catch (Throwable $e) {
            $this->logger->warning('supply: не вдалось надіслати сповіщення', [
                'user' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
