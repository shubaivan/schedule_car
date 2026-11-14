<?php

namespace App\Tests\Tasks;

use App\Tasks\Command\TasksDemoSeedCommand;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * After `tasks:demo-seed --reset` the audit page on «План» (14.11) still listed
 * events of previous seeds — no author, pointing at tasks that no longer exist.
 */
class DemoSeedOrphanEventsTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testPrunesEventsOfMissingSubjectsOnly(): void
    {
        $conn = $this->em->getConnection();
        $missing = (int) $conn->fetchOne('SELECT COALESCE(MAX(id), 0) + 1000 FROM task');
        $existingUser = (int) $conn->fetchOne('SELECT MIN(id) FROM telegram_user');
        self::assertGreaterThan(0, $existingUser, 'test DB has people');
        $insert = static fn (string $type, int $id) => (int) $conn->fetchOne(
            "INSERT INTO plan_event (id, type, subject_type, subject_id, occurred_at, payload, channel)
             VALUES (nextval('plan_event_id_seq'), 'comment_added', ?, ?, NOW(), '{}', 'web') RETURNING id",
            [$type, $id],
        );

        $orphanTask = $insert('task', $missing);
        $orphanSupply = $insert('supply', $missing);

        $kept = [$insert('user', $existingUser)];

        TasksDemoSeedCommand::pruneOrphanEvents($conn);

        $left = array_map('intval', $conn->fetchFirstColumn('SELECT id FROM plan_event WHERE id IN (?)', [[$orphanTask, $orphanSupply, ...$kept]], [ArrayParameterType::INTEGER]));
        sort($left);
        self::assertSame($kept, $left);
    }
}
