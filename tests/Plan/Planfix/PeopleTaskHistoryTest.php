<?php

namespace App\Tests\Plan\Planfix;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Enum\SupplyRole;
use App\Supply\Service\PeopleDirectory;
use App\Tasks\Enum\WorkRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Людину, за якою є задачі (автор, учасник, автор повідомлення — зокрема перенесені
 * з Planfix), «Прибрати» лише архівує: видалення знищило б задачі каскадом.
 */
class PeopleTaskHistoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testTaskCreatorMemberAndAuthorAreArchivedNotDeleted(): void
    {
        $manager = $this->person(SupplyRole::Manager);
        $db = $this->em->getConnection();

        foreach (['creator', 'member', 'author'] as $role) {
            $person = $this->person(SupplyRole::Worker);
            $owner = $role === 'creator' ? $person : $manager;
            $task = (int) $db->fetchOne(
                "INSERT INTO task (id, creator_id, title, status, created_at, last_activity_at) VALUES (nextval('task_id_seq'), ?, 'Тест', 'new', LOCALTIMESTAMP(0), LOCALTIMESTAMP(0)) RETURNING id",
                [$owner->getId()],
            );

            if ($role === 'member') {
                $db->executeStatement("INSERT INTO task_member (id, task_id, user_id) VALUES (nextval('task_member_id_seq'), ?, ?)", [$task, $person->getId()]);
            }

            if ($role === 'author') {
                $db->executeStatement("INSERT INTO task_message (id, task_id, author_id, kind, text, mentions, created_at) VALUES (nextval('task_message_id_seq'), ?, ?, 'comment', 'x', '[]', LOCALTIMESTAMP(0))", [$task, $person->getId()]);
            }

            $deleted = self::getContainer()->get(PeopleDirectory::class)->remove($person, $manager);

            self::assertFalse($deleted, $role . ': архів, не видалення');
            self::assertNotFalse($db->fetchOne('SELECT 1 FROM task WHERE id = ?', [$task]), $role . ': задача на місці');
        }
    }

    private function person(SupplyRole $supply): TelegramUser
    {
        $user = new TelegramUser()
            ->setTelegramId((string) random_int(100000000, 999999999))
            ->setFirstName('Тест-Історія')
            ->setWorkRole($supply === SupplyRole::Manager ? WorkRole::Manager : WorkRole::Production)
            ->setSupplyRole($supply);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
