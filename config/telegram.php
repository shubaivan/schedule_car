<?php
/** @var SergiX44\Nutgram\Nutgram $bot */

use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\RunningMode\Webhook;
use \App\Telegram\ScheduleCar\Command\Schedule;
use \App\Telegram\ScheduleCar\Command\ScheduleCar;
use \App\Telegram\ScheduleCar\Command\OwnSchedule;

Conversation::refreshOnDeserialize();

$bot->setRunningMode(Webhook::class);

$bot->registerCommand(StartCommand::class);
$bot->registerCommand(Schedule::class);

$bot->onCallbackQueryData('schedule-car', ScheduleCar::class);
$bot->onCommand('обрати машину', ScheduleCar::class);
$bot->onCallbackQueryData('own-schedule', OwnSchedule::class);