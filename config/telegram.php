<?php
/** @var SergiX44\Nutgram\Nutgram $bot */

use App\Supply\Telegram\AllRequests;
use App\Supply\Telegram\AttachConversation;
use App\Supply\Telegram\ChangeStatusAction;
use App\Supply\Telegram\CommentConversation;
use App\Supply\Telegram\CrmLoginAction;
use App\Supply\Telegram\MyRequests;
use App\Supply\Telegram\NewRequestConversation;
use App\Supply\Telegram\PurchaseConversation;
use App\Supply\Telegram\RejectConversation;
use App\Supply\Telegram\RequestView;
use App\Supply\Telegram\SupplyCallback;
use App\Supply\Telegram\SupplyMenu;
use App\Telegram\Access\AccessCallback;
use App\Telegram\Access\AccessDecision;
use App\Telegram\Access\RequireApproval;
use App\Telegram\Access\ShareContact;
use App\Fleet\Telegram\BookCarConversation;
use App\Fleet\Telegram\CancelTripAction;
use App\Fleet\Telegram\DriverTrips;
use App\Fleet\Telegram\FleetCallback;
use App\Fleet\Telegram\FleetSchedule;
use App\Fleet\Telegram\MyTrips;
use App\Telegram\Start\Command\FleetMenu;
use App\Telegram\Start\Command\MainMenu;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\RunningMode\Webhook;

Conversation::refreshOnDeserialize();

$bot->setRunningMode(Webhook::class);

##############
# Доступ: реєстрація за номером + підтвердження менеджером
##############
$bot->middleware(RequireApproval::class);

$bot->onContact(ShareContact::class);
$bot->onCallbackQueryData(AccessCallback::APPROVE_PREFIX . '{id}', AccessDecision::class);
$bot->onCallbackQueryData(AccessCallback::REJECT_PREFIX . '{id}', AccessDecision::class);

$bot->registerCommand(StartCommand::class);

##############
# Автопарк
##############
$bot->onCallbackQueryData(StartCommand::MAIN_MENU, MainMenu::class);
$bot->onCallbackQueryData(StartCommand::FLEET_MENU, FleetMenu::class);
$bot->onCommand('avtopark', FleetMenu::class);
$bot->onCallbackQueryData(FleetCallback::MENU, FleetMenu::class);
// Спільний розклад — головний екран автопарку: його гортають кнопками,
// а зсув у днях їде в самій callback_data, тож стан ніде не зберігається.
$bot->onCallbackQueryData(FleetCallback::SCHEDULE, FleetSchedule::class);
$bot->onCallbackQueryData(FleetCallback::SCHEDULE_PREFIX . '{offset}', FleetSchedule::class);
$bot->onCallbackQueryData(FleetCallback::BOOK, BookCarConversation::class);
$bot->onCallbackQueryData(FleetCallback::MY_TRIPS, MyTrips::class);
// Кнопку «Скасувати» форми перехоплює сама розмова; цей маршрут ловить її вже
// після її кінця — щоб на старому екрані кнопка не була мертвою.
$bot->onCallbackQueryData(FleetCallback::FORM_CANCEL, MyTrips::class);
$bot->onCallbackQueryData(FleetCallback::DRIVER_TRIPS, DriverTrips::class);
$bot->onCallbackQueryData(FleetCallback::CANCEL_PREFIX . '{id}', CancelTripAction::class);

##############
# Постачання
##############
$bot->onCommand('postachannia', SupplyMenu::class);
$bot->onCommand('crm', CrmLoginAction::class);
$bot->onCallbackQueryData(SupplyCallback::MENU, SupplyMenu::class);
// Кнопку «Скасувати» перехоплює сама розмова; цей маршрут ловить її вже після
// того, як розмова скінчилась, — щоб кнопка не лишалась мертвою.
$bot->onCallbackQueryData(SupplyCallback::CANCEL, SupplyMenu::class);
$bot->onCallbackQueryData(SupplyCallback::NEW_REQUEST, NewRequestConversation::class);
$bot->onCallbackQueryData(SupplyCallback::MY_REQUESTS, MyRequests::class);
$bot->onCallbackQueryData(SupplyCallback::ALL_REQUESTS, AllRequests::class);
$bot->onCallbackQueryData(SupplyCallback::CRM_LOGIN, CrmLoginAction::class);
$bot->onCallbackQueryData(SupplyCallback::VIEW_PREFIX . '{id}', RequestView::class);
$bot->onCallbackQueryData(SupplyCallback::STATUS_PREFIX . '{id}:{status}', ChangeStatusAction::class);
$bot->onCallbackQueryData(SupplyCallback::REJECT_PREFIX . '{id}', RejectConversation::class);
$bot->onCallbackQueryData(SupplyCallback::COMMENT_PREFIX . '{id}', CommentConversation::class);
$bot->onCallbackQueryData(SupplyCallback::PURCHASE_PREFIX . '{id}', PurchaseConversation::class);
$bot->onCallbackQueryData(SupplyCallback::ATTACH_PREFIX . '{id}', AttachConversation::class);
