<?php

namespace App\Warehouse\Api;

use App\Entity\TelegramUser;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Service\ActivityLog;
use DateTime;
use Doctrine\DBAL\Connection;

/**
 * «Відкрив картку» з «Плану» — у журнал складу (і через ActivityLog у plan_event
 * як SubjectViewed), але не частіше, ніж раз на THROTTLE_MINUTES на людину й картку.
 *
 * У Twig-складі кожне відкриття — окрема сторінка, і рядок журналу на неї чесний.
 * У «Плані» ту саму картку гортають у розділеному екрані туди-сюди, перемальовують
 * після кожної дії, а стрічка опитує сервер — рядок на кожен рендер затопив би
 * і журнал складу, і аудит суперадміна. Тому перегляд пише фронт ОДИН раз при
 * відкритті (POST …/viewed), а тут ще й зрізаємо повтори.
 */
final class ViewJournal
{
    public const int THROTTLE_MINUTES = 10;

    public function __construct(
        private readonly ActivityLog $activity,
        private readonly Connection $connection,
    ) {
    }

    /** @return bool true — записали; false — така сама відмітка вже є за останні хвилини */
    public function viewed(WhItem|WhMovement $subject, TelegramUser $user): bool
    {
        $type = ActivityLog::typeOf($subject);

        $recent = $this->connection->fetchOne(
            'SELECT 1 FROM wh_activity WHERE subject_type = :type AND subject_id = :id AND user_id = :user AND action = :action AND at >= :since LIMIT 1',
            [
                'type' => $type,
                'id' => $subject->getId(),
                'user' => $user->getId(),
                'action' => ActivityAction::View->value,
                'since' => (new DateTime(sprintf('-%d minutes', self::THROTTLE_MINUTES)))->format('Y-m-d H:i:s'),
            ],
        );

        if ($recent !== false) {
            return false;
        }

        $this->activity->record(ActivityAction::View, $subject, $user, WhActivity::CHANNEL_ADMIN, 'у «Плані»');

        return true;
    }
}
