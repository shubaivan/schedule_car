<?php

namespace App\Tests\Plan\S5;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Enum\SupplyRole;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskAttachment;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\TaskService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Люди, задачі й файли для тестів S5. Усе — всередині транзакції тесту
 * (setUp: beginTransaction, tearDown: rollback), тож прибирати нічого не треба.
 */
trait S5Fixtures
{
    private EntityManagerInterface $em;

    private function person(WorkRole $role, string $name, SupplyRole $supply = SupplyRole::Worker, bool $superadmin = false): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId('s5-' . $name . '-' . uniqid())
            ->setFirstName(ucfirst($name))
            ->setLastName('S5')
            ->setWorkRole($role)
            ->setSupplyRole($supply)
            ->setSuperadmin($superadmin)
            ->setEmail('s5-' . $name . uniqid() . '@example.test');
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @param list<TelegramUser> $members */
    private function task(TelegramUser $author, string $title, array $members = []): Task
    {
        return $this->tasks()->create($author, ['title' => $title, 'memberIds' => array_map(static fn (TelegramUser $u) => $u->getId(), $members)]);
    }

    private function taskFile(Task $task, TelegramUser $by, string $name = 'кошторис.txt', string $contents = 'рядок кошторису'): TaskAttachment
    {
        $message = $this->tasks()->post($task, $by, 'файл');
        $path = (string) tempnam(sys_get_temp_dir(), 's5');
        file_put_contents($path, $contents);
        $mime = str_ends_with($name, '.png') ? 'image/png' : 'text/plain';

        return $this->tasks()->attach($message, $by, $path, $name, $mime);
    }

    private function tasks(): TaskService
    {
        return self::getContainer()->get(TaskService::class);
    }

    /** Файли з інших тестів (у спільній тестовій БД) не мають потрапляти в чергу цього тесту. */
    private function isolateDriveQueue(): void
    {
        foreach (['task_attachment', 'supply_attachment', 'wh_document'] as $table) {
            $this->em->getConnection()->executeStatement("UPDATE $table SET drive_state = 'skipped' WHERE drive_state = 'pending'");
        }
        $this->em->getConnection()->executeStatement('INSERT INTO plan_drive_status (id) VALUES (1) ON CONFLICT (id) DO NOTHING');
        $this->em->getConnection()->executeStatement('UPDATE plan_drive_status SET auth_broken_at = NULL, paused_until = NULL, last_error = NULL WHERE id = 1');
    }
}
