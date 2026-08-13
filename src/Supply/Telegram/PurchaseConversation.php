<?php

namespace App\Supply\Telegram;

use App\Service\ChatScreen;
use App\Service\TelegramUserService;
use App\Supply\Dto\PurchaseInput;
use App\Supply\Entity\Supplier;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\SupplierRepository;
use App\Supply\Repository\SupplyPurchaseRepository;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\ChangeStatus;
use App\Supply\Service\RecordPurchase;
use App\Supply\Service\RequestFormatter;
use App\Supply\Service\SupplierDirectory;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/**
 * «У кого купили»: постачальник → сума → накладна.
 *
 * Запускається двома шляхами. Перший — менеджер тисне «Оплачено» (чи інший
 * статус, який вимагає закупівлі) на заявці, де закупівлі ще немає: замість
 * відмови бот одразу питає потрібне й після відповіді сам переводить статус.
 * Другий — кнопка «🧾 Закупівля» на картці, коли статус міняти ще рано.
 *
 * Постачальника обираємо кнопкою з тих, у кого купували востаннє: у 90 %
 * випадків це влучання з одного дотику, а не пошук у довіднику.
 */
class PurchaseConversation extends Conversation
{
    private const SUPPLIER_PREFIX = 'b:s:';
    private const NEW_SUPPLIER = 'b:new';
    private const FIND_SUPPLIER = 'b:find';
    private const SKIP = 'b:skip';
    private const CANCEL = 'b:cancel';
    private const SUGGESTED = 6;

    protected ?string $step = 'askSupplier';

    public ?int $requestId = null;
    /** Статус, у який треба перевести заявку після запису закупівлі. */
    public ?string $targetStatus = null;
    public ?int $supplierId = null;
    public ?string $supplierName = null;
    public ?string $totalAmount = null;

    public function __construct(
        private SupplyRequestRepository $requests,
        private SupplierRepository $suppliers,
        private SupplyPurchaseRepository $purchases,
        private SupplierDirectory $directory,
        private RecordPurchase $recordPurchase,
        private ChangeStatus $changeStatus,
        private TelegramUserService $telegramUserService,
        private RequestFormatter $formatter,
        private RequestView $view,
        private ChatScreen $screen,
    ) {
    }

    public function askSupplier(Nutgram $bot, string|int $id = 0, ?string $targetStatus = null): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        // Дані приходять або з кнопки картки, або від ChangeStatusAction.
        if ((int) $id !== 0) {
            $this->requestId = (int) $id;
            $this->targetStatus = $targetStatus;
        }

        if ($this->request() === null) {
            $this->screen->render($bot, '⚠️ Заявку не знайдено.');
            $this->end();

            return;
        }

        $this->render($bot, 'У кого купили?', $this->supplierKeyboard($this->purchases->recentSuppliers(self::SUGGESTED)));

        $this->next('readSupplier');
    }

    public function readSupplier(Nutgram $bot): void
    {
        $data = (string) ($bot->callbackQuery()->data ?? '');

        if ($data === self::CANCEL) {
            $this->cancel($bot);

            return;
        }

        if ($data === self::NEW_SUPPLIER) {
            $bot->answerCallbackQuery();
            $this->render($bot, 'Напишіть назву постачальника, наприклад: <i>ФОП Петренко О.П.</i>');
            $this->next('readNewSupplier');

            return;
        }

        if ($data === self::FIND_SUPPLIER) {
            $bot->answerCallbackQuery();
            $this->render($bot, 'Напишіть частину назви або ЄДРПОУ:');
            $this->next('readSupplierQuery');

            return;
        }

        if (str_starts_with($data, self::SUPPLIER_PREFIX)) {
            $bot->answerCallbackQuery();
            $supplier = $this->suppliers->find((int) substr($data, strlen(self::SUPPLIER_PREFIX)));

            if ($supplier === null) {
                $this->render($bot, '⚠️ Постачальника не знайдено. Оберіть іншого:', $this->supplierKeyboard(
                    $this->purchases->recentSuppliers(self::SUGGESTED),
                ));

                return;
            }

            $this->remember($supplier);
            $this->askAmount($bot);

            return;
        }

        // Написали текстом замість кнопки — шукаємо за цим текстом.
        $text = trim((string) $bot->message()?->text);
        $this->forgetUserMessage($bot);

        if ($text === '') {
            $this->render($bot, 'Оберіть постачальника кнопкою або напишіть частину назви:', $this->supplierKeyboard(
                $this->purchases->recentSuppliers(self::SUGGESTED),
            ));

            return;
        }

        $this->showSearch($bot, $text);
    }

    public function readSupplierQuery(Nutgram $bot): void
    {
        $text = trim((string) $bot->message()?->text);
        $this->forgetUserMessage($bot);

        if ($text === '') {
            $this->render($bot, 'Напишіть частину назви або ЄДРПОУ:');

            return;
        }

        $this->showSearch($bot, $text);
        $this->next('readSupplier');
    }

    public function readNewSupplier(Nutgram $bot): void
    {
        $text = trim((string) $bot->message()?->text);
        $this->forgetUserMessage($bot);

        if ($text === '') {
            $this->render($bot, 'Напишіть назву постачальника:');

            return;
        }

        try {
            // findOrCreate, а не create: назва вже може бути в довіднику, і
            // менеджер не має впертись у помилку про дубль.
            $this->remember($this->directory->findOrCreate($text, $this->telegramUserService->getCurrentUser()));
        } catch (SupplyException $e) {
            $this->render($bot, '⚠️ ' . $this->formatter->escape($e->getMessage()));

            return;
        }

        $this->askAmount($bot);
    }

    public function readAmount(Nutgram $bot): void
    {
        $data = (string) ($bot->callbackQuery()->data ?? '');

        if ($data === self::CANCEL) {
            $this->cancel($bot);

            return;
        }

        $text = trim((string) $bot->message()?->text);
        $this->forgetUserMessage($bot);

        if ($text === '') {
            $this->render($bot, '⚠️ Напишіть суму числом, наприклад <i>12500</i> або <i>12 500,50</i>', $this->cancelKeyboard());

            return;
        }

        $this->totalAmount = $text;

        $this->render(
            $bot,
            'Номер накладної або рахунку — або пропустіть:',
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('Пропустити', callback_data: self::SKIP))
                ->addRow(InlineKeyboardButton::make('✖️ Скасувати', callback_data: self::CANCEL)),
        );

        $this->next('readInvoice');
    }

    public function readInvoice(Nutgram $bot): void
    {
        $data = (string) ($bot->callbackQuery()->data ?? '');

        if ($data === self::CANCEL) {
            $this->cancel($bot);

            return;
        }

        $invoice = null;

        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        } else {
            $invoice = trim((string) $bot->message()?->text) ?: null;
            $this->forgetUserMessage($bot);
        }

        $this->save($bot, $invoice);
    }

    private function save(Nutgram $bot, ?string $invoice): void
    {
        $request = $this->request();
        $supplier = $this->supplierId !== null ? $this->suppliers->find($this->supplierId) : null;
        $manager = $this->telegramUserService->getCurrentUser();

        if ($request === null || $supplier === null || $manager === null) {
            $this->render($bot, '⚠️ Заявку або постачальника не знайдено.');
            $this->end();

            return;
        }

        try {
            ($this->recordPurchase)($request, $manager, new PurchaseInput(
                supplier: $supplier,
                totalAmount: $this->totalAmount,
                invoiceNumber: $invoice,
            ), notify: $this->targetStatus === null);

            // Статус міняємо тільки тепер: сповіщення заявнику піде одне й уже
            // з постачальником і сумою в картці.
            if ($this->targetStatus !== null) {
                $status = SupplyStatus::from($this->targetStatus);
                ($this->changeStatus)($request, $status, $manager);
            }
        } catch (SupplyException $e) {
            // Найчастіше це неправильна сума — питаємо її ще раз, а не втрачаємо
            // все введене.
            $this->render($bot, '⚠️ ' . $this->formatter->escape($e->getMessage()) . "\n\nНапишіть суму ще раз:", $this->cancelKeyboard());
            $this->next('readAmount');

            return;
        }

        $this->view->show($bot, $request, $manager);
        $this->end();
    }

    private function askAmount(Nutgram $bot): void
    {
        $this->render($bot, 'На яку суму? Напишіть числом, наприклад <i>12500</i> або <i>12 500,50</i>', $this->cancelKeyboard());

        $this->next('readAmount');
    }

    private function showSearch(Nutgram $bot, string $query): void
    {
        $found = $this->suppliers->search($query, self::SUGGESTED);

        if (! $found) {
            $this->render($bot, sprintf(
                'За запитом «%s» нікого не знайшов. Додати як нового?',
                $this->formatter->escape($query),
            ), InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('➕ Додати нового', callback_data: self::NEW_SUPPLIER))
                ->addRow(InlineKeyboardButton::make('✖️ Скасувати', callback_data: self::CANCEL)));

            return;
        }

        $this->render($bot, 'Знайшов:', $this->supplierKeyboard($found));
    }

    /** Накопичене зверху, поточне питання знизу — як у формі нової заявки. */
    private function render(Nutgram $bot, string $question, ?InlineKeyboardMarkup $markup = null): void
    {
        $request = $this->request();

        $lines = ['🧾 <b>Закупівля</b>'];

        if ($request !== null) {
            $lines[] = sprintf(
                'Заявка №%s · %s — %s',
                $this->formatter->escape($request->getNumber()),
                $this->formatter->escape($request->getItem()),
                $request->getQuantityLabel(),
            );
        }

        $lines[] = '';

        if ($this->supplierName !== null) {
            $lines[] = '✅ Постачальник: <b>' . $this->formatter->escape($this->supplierName) . '</b>';
        }

        if ($this->totalAmount !== null) {
            $lines[] = '✅ Сума: <b>' . $this->formatter->escape($this->totalAmount) . '</b>';
        }

        $lines[] = '';
        $lines[] = $question;

        $this->screen->render($bot, implode("\n", $lines), $markup);
    }

    /** @param Supplier[] $suppliers */
    private function supplierKeyboard(array $suppliers): InlineKeyboardMarkup
    {
        $markup = InlineKeyboardMarkup::make();

        foreach ($suppliers as $supplier) {
            $markup->addRow(InlineKeyboardButton::make(
                $supplier->getName(),
                callback_data: self::SUPPLIER_PREFIX . $supplier->getId(),
            ));
        }

        return $markup
            ->addRow(
                InlineKeyboardButton::make('🔍 Пошук', callback_data: self::FIND_SUPPLIER),
                InlineKeyboardButton::make('➕ Новий', callback_data: self::NEW_SUPPLIER),
            )
            ->addRow(InlineKeyboardButton::make('✖️ Скасувати', callback_data: self::CANCEL));
    }

    private function cancelKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('✖️ Скасувати', callback_data: self::CANCEL),
        );
    }

    private function cancel(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();

        $request = $this->request();
        $user = $this->telegramUserService->getCurrentUser();

        if ($request !== null && $user !== null) {
            $this->view->show($bot, $request, $user);
        } else {
            $this->screen->close($bot);
        }

        $this->end();
    }

    private function remember(Supplier $supplier): void
    {
        $this->supplierId = $supplier->getId();
        $this->supplierName = $supplier->getName();
    }

    private function request(): ?SupplyRequest
    {
        return $this->requestId !== null ? $this->requests->find($this->requestId) : null;
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
        } catch (Throwable) {
            // Не критично: повідомлення просто лишиться в чаті.
        }
    }
}
