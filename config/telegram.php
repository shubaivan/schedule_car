<?php
/** @var SergiX44\Nutgram\Nutgram $bot */

use App\Supply\Telegram\ChangeStatusAction;
use App\Supply\Telegram\CommentConversation;
use App\Supply\Telegram\CrmLoginAction;
use App\Supply\Telegram\MyRequests;
use App\Supply\Telegram\NewRequestConversation;
use App\Supply\Telegram\RejectConversation;
use App\Supply\Telegram\RequestView;
use App\Supply\Telegram\SupplyCallback;
use App\Supply\Telegram\SupplyMenu;
use App\Telegram\Access\AccessCallback;
use App\Telegram\Access\AccessDecision;
use App\Telegram\Access\RequireApproval;
use App\Telegram\Access\ShareContact;
use App\Telegram\Start\Command\FleetMenu;
use App\Telegram\Start\Command\MainMenu;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\RunningMode\Webhook;
use \App\Telegram\ScheduleCar\Command\Schedule;
use \App\Telegram\ScheduleCar\Command\ScheduleCar;
use \App\Telegram\ScheduleCar\Command\OwnSchedule;
use App\Telegram\ScheduleCar\Command\DriverCar;

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
$bot->registerCommand(Schedule::class);

##############
# Автопарк
##############
$bot->onCallbackQueryData(StartCommand::MAIN_MENU, MainMenu::class);
$bot->onCallbackQueryData(StartCommand::FLEET_MENU, FleetMenu::class);
$bot->onCallbackQueryData('schedule-car', ScheduleCar::class);
$bot->onCallbackQueryData('driver', DriverCar::class);
$bot->onCommand('обрати машину', ScheduleCar::class);
$bot->onCallbackQueryData('own-schedule', OwnSchedule::class);

##############
# Постачання
##############
$bot->onCommand('postachannia', SupplyMenu::class);
$bot->onCommand('crm', CrmLoginAction::class);
$bot->onCallbackQueryData(SupplyCallback::MENU, SupplyMenu::class);
$bot->onCallbackQueryData(SupplyCallback::NEW_REQUEST, NewRequestConversation::class);
$bot->onCallbackQueryData(SupplyCallback::MY_REQUESTS, MyRequests::class);
$bot->onCallbackQueryData(SupplyCallback::CRM_LOGIN, CrmLoginAction::class);
$bot->onCallbackQueryData(SupplyCallback::VIEW_PREFIX . '{id}', RequestView::class);
$bot->onCallbackQueryData(SupplyCallback::STATUS_PREFIX . '{id}:{status}', ChangeStatusAction::class);
$bot->onCallbackQueryData(SupplyCallback::REJECT_PREFIX . '{id}', RejectConversation::class);
$bot->onCallbackQueryData(SupplyCallback::COMMENT_PREFIX . '{id}', CommentConversation::class);
