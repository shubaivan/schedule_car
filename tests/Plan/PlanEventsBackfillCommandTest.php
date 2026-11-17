<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Enum\Unit;
use App\Supply\Service\AddComment;
use App\Supply\Service\ChangeStatus;
use App\Supply\Service\CreateRequest;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\TaskService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** plan:events:backfill — історія модулів у журнал, повторний запуск нічого не дублює. */
class PlanEventsBackfillCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testBackfillsHistoryOnceAndKeepsLiveEvents(): void
    {
        $manager = $this->person(WorkRole::Manager, SupplyRole::Manager);
        $worker = $this->person(WorkRole::Production, SupplyRole::Worker);
        $tasks = self::getContainer()->get(TaskService::class);

        $task = $tasks->create($manager, ['title' => 'Плити історія', 'memberIds' => [$worker->getId()]]);
        $tasks->post($task, $worker, 'Старий коментар');
        $tasks->changeStatus($task, $manager, TaskStatus::Done);
        $request = (self::getContainer()->get(CreateRequest::class))($worker, new CreateRequestInput(item: 'Пісок', quantity: '3', unit: Unit::CubicMeter));
        (self::getContainer()->get(ChangeStatus::class))($request, SupplyStatus::InProgress, $manager);
        (self::getContainer()->get(AddComment::class))($request, $manager, 'Старе');
        $this->db->executeStatement(
            "INSERT INTO crm_login (id, user_id, success, ip, user_agent, at) VALUES (nextval('crm_login_id_seq'), ?, true, '10.0.0.5', 'Firefox', '2026-11-01 08:00:00')",
            [$manager->getId()],
        );

        // «До деплою S0» журналу не було.
        $this->db->executeStatement('DELETE FROM plan_event');

        $dry = $this->backfill(['--dry-run' => true]);
        self::assertStringContainsString('[dry-run]', $dry);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_event'), 'dry-run нічого не пише');

        $this->backfill();
        $taskTypes = $this->types('task', (int) $task->getId());
        self::assertSame(['subject_created', 'comment_added', 'subject_closed'], $taskTypes);
        self::assertSame(['subject_created', 'status_changed', 'comment_added'], $this->types('supply', (int) $request->getId()));
        self::assertSame(['user_logged_in'], $this->types('user', (int) $manager->getId()));

        $closed = $this->db->fetchAssociative(
            "SELECT payload->>'from' AS from_, payload->>'to' AS to_, payload->>'backfill' AS backfill, channel FROM plan_event WHERE type = 'subject_closed' AND subject_id = ?",
            [$task->getId()],
        );
        self::assertSame(['from_' => 'new', 'to_' => 'done', 'backfill' => 'true', 'channel' => 'cli'], $closed, 'Статуси розпізнано з тексту події');
        self::assertSame('10.0.0.5', $this->db->fetchOne("SELECT ip FROM plan_event WHERE type = 'user_logged_in' AND subject_id = ?", [$manager->getId()]));

        // Повтор — нічого нового.
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_event');
        $this->backfill();
        self::assertSame($total, (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_event'));

        // Після «живої» події історія новіша за неї не переноситься (її вже пишуть сервіси).
        $tasks->post($task, $manager, 'Новий після деплою');
        $this->backfill();
        self::assertSame(['subject_created', 'comment_added', 'subject_closed', 'comment_added'], $this->types('task', (int) $task->getId()));

        // Але межа — своя в кожного джерела: входи наживо ще не пишуться, тож вхід
        // після першої живої події задач усе одно переноситься.
        $this->db->executeStatement(
            "INSERT INTO crm_login (id, user_id, success, ip, user_agent, at) VALUES (nextval('crm_login_id_seq'), ?, true, '10.0.0.6', 'Firefox', '2030-01-01 08:00:00')",
            [$manager->getId()],
        );
        $this->backfill();
        self::assertSame(['user_logged_in', 'user_logged_in'], $this->types('user', (int) $manager->getId()));
    }

    /** @param array<string, mixed> $options */
    private function backfill(array $options = []): string
    {
        $tester = new CommandTester(new Application(self::$kernel)->find('plan:events:backfill'));
        $tester->execute($options);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }

    /** @return list<string> */
    private function types(string $subject, int $id): array
    {
        return $this->db->fetchFirstColumn('SELECT type FROM plan_event WHERE subject_type = ? AND subject_id = ? ORDER BY occurred_at, id', [$subject, $id]);
    }

    private function person(WorkRole $role, SupplyRole $supply): TelegramUser
    {
        $user = new TelegramUser()->setPlanFullAccess(true)
            ->setTelegramId('bf-' . uniqid('', true))
            ->setFirstName('Тест-Історія')
            ->setWorkRole($role)
            ->setSupplyRole($supply);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
