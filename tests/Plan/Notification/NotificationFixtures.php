<?php

namespace App\Tests\Plan\Notification;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Enum\SupplyRole;
use App\Tasks\Enum\WorkRole;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Testing\FakeNutgram;

/** Люди й лічильники для тестів сповіщень (усе — у транзакції тесту, відкат після). */
trait NotificationFixtures
{
    abstract protected function em(): EntityManagerInterface;

    private function person(WorkRole $role = WorkRole::Production, SupplyRole $supply = SupplyRole::Worker, bool $approved = true, string $name = 'Тест-Сповіщ'): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId((string) random_int(100_000_000, 999_999_999))
            ->setFirstName($name)
            ->setWorkRole($role)
            ->setSupplyRole($supply);

        if ($approved) {
            $user->decideAccess(AccessStatus::Approved, null);
        }

        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /** @return array<int, string> userId → event_group для останньої події (або заданої) */
    private function rowsFor(int $eventId): array
    {
        $out = [];

        foreach ($this->em()->getConnection()->fetchAllAssociative('SELECT user_id, event_group FROM notification WHERE event_id = ? ORDER BY user_id', [$eventId]) as $row) {
            $out[(int) $row['user_id']] = (string) $row['event_group'];
        }

        return $out;
    }

    private function lastEventId(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT MAX(id) FROM plan_event');
    }

    /** @return array<int, string> */
    private function rowsForLastEvent(): array
    {
        return $this->rowsFor($this->lastEventId());
    }

    /**
     * @phpstan-impure
     *
     * @return list<array<string, mixed>> sendMessage-запити з останнього виклику
     */
    private function telegram(FakeNutgram $bot, int &$seen): array
    {
        $history = $bot->getRequestHistory();
        $out = [];

        foreach (array_slice($history, $seen) as $pair) {
            [$request] = array_values($pair);
            $out[] = FakeNutgram::getActualData($request);
        }

        $seen = count($history);

        return $out;
    }
}
