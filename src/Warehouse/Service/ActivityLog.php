<?php

namespace App\Warehouse\Service;

use App\Entity\TelegramUser;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Entity\WhCategory;
use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\ActivityAction;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Запис у журнал складу.
 *
 * Ніколи не фатальний: журнал — це вимірювання, а людина прийшла по картку.
 * Зламаний запис у журнал не має коштувати їй ні скану, ні збереження.
 */
class ActivityLog
{
    public const TYPES = [
        'item' => 'Майно',
        'client' => 'Клієнт',
        'site' => "Об'єкт",
        'movement' => 'Рух',
        'category' => 'Категорія',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function record(
        ActivityAction $action,
        WhItem|WhClient|WhSite|WhMovement|WhCategory $subject,
        ?TelegramUser $user,
        string $channel,
        ?string $details = null,
    ): void {
        try {
            $this->em->persist(new WhActivity(
                $action,
                self::typeOf($subject),
                (int) $subject->getId(),
                self::labelOf($subject),
                $user,
                $channel,
                $details,
            ));
            $this->em->flush();
        } catch (Throwable $e) {
            $this->logger->warning('warehouse: запис у журнал не вдався', [
                'action' => $action->value,
                'subject' => self::typeOf($subject) . '#' . $subject->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function typeOf(WhItem|WhClient|WhSite|WhMovement|WhCategory $subject): string
    {
        return match (true) {
            $subject instanceof WhItem => 'item',
            $subject instanceof WhClient => 'client',
            $subject instanceof WhSite => 'site',
            $subject instanceof WhMovement => 'movement',
            default => 'category',
        };
    }

    public static function labelOf(WhItem|WhClient|WhSite|WhMovement|WhCategory $subject): string
    {
        return match (true) {
            $subject instanceof WhItem => $subject->getLabel(),
            $subject instanceof WhClient => $subject->getName(),
            $subject instanceof WhSite => $subject->getLabel(),
            $subject instanceof WhMovement => $subject->getTitle(),
            default => $subject->getLabel(),
        };
    }
}
