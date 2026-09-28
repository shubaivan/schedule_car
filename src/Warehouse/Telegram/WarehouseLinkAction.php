<?php

namespace App\Warehouse\Telegram;

use App\Service\ChatScreen;
use App\Service\CrmLoginLink;
use App\Service\FleetSection;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Service\ActivityLog;
use App\Warehouse\Service\WarehouseCards;
use App\Warehouse\Service\WarehouseLinks;
use App\Warehouse\Service\WarehouseSection;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Усе, що приходить як «/start <payload>»: скан наклейки чи перехід за
 * посиланням на картку складу (див. WarehouseLinks).
 *
 * Маршрут `start {payload}` Nutgram якорить, тож голий /start і далі йде в
 * StartCommand. Чужий чи зіпсований payload веде в головне меню: людина
 * хотіла бота — вона його отримує.
 *
 * Кожен скан і кожен перехід пишеться в журнал — із тим, хто це був.
 * Сюди доходять лише підтверджені працівники (RequireApproval).
 */
class WarehouseLinkAction
{
    private const CLASSES = [
        WarehouseLinks::KIND_SCAN => WhItem::class,
        WarehouseLinks::KIND_ITEM => WhItem::class,
        WarehouseLinks::KIND_CLIENT => WhClient::class,
        WarehouseLinks::KIND_SITE => WhSite::class,
        WarehouseLinks::KIND_MOVEMENT => WhMovement::class,
    ];

    public function __construct(
        private ChatScreen $screen,
        private TelegramUserService $telegramUserService,
        private WarehouseCards $cards,
        private CrmLoginLink $loginLink,
        private WarehouseSection $section,
        private FleetSection $fleet,
        private ActivityLog $activity,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Nutgram $bot, string $payload = ''): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $link = WarehouseLinks::parse($payload);
        $subject = $link !== null && $this->section->isEnabled()
            ? $this->em->find(self::CLASSES[$link['kind']], $link['id'])
            : null;

        if ($subject === null) {
            $this->screen->render($bot, 'Вітаю! Оберіть розділ:', StartCommand::mainMenuKeyboard(
                $this->fleet->isEnabled(),
                $this->section->inMenuFor($user),
            ));

            return;
        }

        $manager = WarehouseSection::canManage($user);

        $this->activity->record(
            $link['kind'] === WarehouseLinks::KIND_SCAN ? ActivityAction::Scan : ActivityAction::Link,
            $subject,
            $user,
            WhActivity::CHANNEL_BOT,
        );

        // Клієнт і рух — це контакти й гроші: їх бачить лише той, хто веде склад.
        if (! $manager && ($subject instanceof WhClient || $subject instanceof WhMovement)) {
            $this->screen->render(
                $bot,
                '🔒 Цю картку складу відкривають менеджер, адміністратор або директор.',
                InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
            );

            return;
        }

        $text = match (true) {
            $subject instanceof WhItem => $this->cards->item($subject, $manager),
            $subject instanceof WhClient => $this->cards->client($subject),
            $subject instanceof WhSite => $this->cards->site($subject, $manager),
            default => $this->cards->movement($subject),
        };

        $markup = InlineKeyboardMarkup::make();

        if ($manager && $user !== null) {
            $markup->addRow(InlineKeyboardButton::make(
                '🔐 Відкрити в адмінці',
                url: $this->loginLink->issue($user, $this->adminPath($subject) . '?via=bot'),
            ));
        }

        $markup->addRow(StartCommand::homeButton());

        $this->screen->render($bot, $text, $markup);
    }

    private function adminPath(WhItem|WhClient|WhSite|WhMovement $subject): string
    {
        return match (true) {
            $subject instanceof WhItem => '/sklad/items/' . $subject->getId(),
            $subject instanceof WhClient => '/sklad/clients/' . $subject->getId(),
            $subject instanceof WhSite => '/sklad/sites/' . $subject->getId(),
            default => '/sklad/movements/' . $subject->getId(),
        };
    }
}
