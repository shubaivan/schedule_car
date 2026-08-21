<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Enum\SupplyRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Запасний шлях підтвердження реєстрації.
 *
 * Кнопка «підтвердити» приходить менеджеру одним повідомленням у момент
 * реєстрації. На заводі те повідомлення губиться в чаті — і людина висить
 * в «очікує» без жодного способу її зрушити: у CRM підтвердження немає.
 */
class AccessCommandTest extends KernelTestCase
{
    private const MARKER = 'Тест-Доступ';

    private EntityManagerInterface $em;
    private CommandTester $command;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->command = new CommandTester((new Application(self::$kernel))->find('supply:access'));
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testPendingPersonGetsAccessAndRole(): void
    {
        $admin = $this->person('admin-' . uniqid(), SupplyRole::Admin, AccessStatus::Approved);
        $waiting = $this->person('vlad-' . uniqid(), SupplyRole::Worker, AccessStatus::Pending);

        $this->command->execute([
            'who' => (string) $waiting->getId(),
            '--role' => 'manager',
            '--by' => (string) $admin->getId(),
        ]);

        self::assertSame(0, $this->command->getStatusCode());
        $this->em->refresh($waiting);

        self::assertTrue($waiting->isApproved(), 'доступ мав відкритись');
        self::assertSame(SupplyRole::Manager, $waiting->getSupplyRole());
    }

    /** Людина може бути ще й без телефону — саме на цьому вони й зависають. */
    public function testPersonIsFoundByUsername(): void
    {
        $admin = $this->person('admin2-' . uniqid(), SupplyRole::Admin, AccessStatus::Approved);
        $waiting = $this->person('nickname-' . uniqid(), SupplyRole::Worker, AccessStatus::Pending);

        $this->command->execute([
            'who' => '@' . $waiting->getUsername(),
            '--by' => (string) $admin->getId(),
        ]);

        self::assertSame(0, $this->command->getStatusCode());
        $this->em->refresh($waiting);
        self::assertTrue($waiting->isApproved());
    }

    public function testUnknownPersonIsReportedInsteadOfCrashing(): void
    {
        $admin = $this->person('admin3-' . uniqid(), SupplyRole::Admin, AccessStatus::Approved);

        $this->command->execute(['who' => '@немає-такого', '--by' => (string) $admin->getId()]);

        self::assertSame(1, $this->command->getStatusCode());
        self::assertStringContainsString('немає', $this->command->getDisplay());
    }

    private function person(string $username, SupplyRole $role, AccessStatus $status): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId('access-cmd-' . uniqid())
            ->setFirstName(self::MARKER)
            ->setUsername($username)
            ->setSupplyRole($role);

        $user->decideAccess($status, null);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
