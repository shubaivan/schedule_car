<?php

namespace App\Plan\EventStore;

/**
 * Споживач, якому повтор безпечний: проєкції (пошук, Хроніка) і центр сповіщень
 * пишуть upsert / ON CONFLICT і зовнішніх ефектів удруге не дають (Mercure й Web
 * Push у NotificationFanout ідуть лише для щойно вставлених рядків).
 *
 * FanOutHandler для них: позначка «опрацьовано» (plan_event_consumed) — ПІСЛЯ
 * успіху, а збій — повтор через Messenger (а в sync-режимі й після вичерпаних
 * повторів — plan:projections:reconcile). Споживачі з зовнішнім ефектом (Telegram)
 * цей інтерфейс НЕ реалізують: для них «краще втратити, ніж задублювати».
 */
interface IdempotentConsumer extends PlanEventConsumer
{
}
