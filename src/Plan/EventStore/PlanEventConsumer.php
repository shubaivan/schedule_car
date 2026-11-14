<?php

namespace App\Plan\EventStore;

use App\Plan\Event\PlanEvent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Споживач подій «Плану»: проєкції (Хроніка, пошук), центр сповіщень, Telegram,
 * черга Drive. Реалізуй інтерфейс — тег додасться сам, FanOutHandler покличе
 * consume() для КОЖНОЇ записаної події; фільтруй через instanceof.
 *
 * Коли викликається:
 * - PLAN_EVENTS_TRANSPORT=sync:// (стенди заявок, тести) — у тому ж запиті, одразу
 *   ПІСЛЯ коміту транзакції, у якій подію записано (як прямі виклики нотифаєрів раніше);
 * - doctrine://…queue_name=plan (План) — у воркері messenger:consume plan.
 *
 * Виняток споживача ловиться й пишеться в лог: інші споживачі все одно
 * отримують подію, а сама дія вже збережена. Повторів немає — отже, споживач
 * із зовнішнім ефектом (Telegram) не задублює повідомлення.
 */
#[AutoconfigureTag('plan.event_consumer')]
interface PlanEventConsumer
{
    public function consume(PlanEvent $event): void;
}
