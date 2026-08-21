<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Enum\Unit;
use App\Supply\Service\ChangeStatus;
use App\Supply\Service\CreateRequest;
use App\Supply\Service\SupplyNotifier;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Прострочена заявка мусить сама про себе нагадати.
 *
 * Сповіщення SupplyNotifier::overdue() існувало з самого початку, але його
 * ніхто не викликав: заявка тихо протухала. Тест стереже саме зчеплення —
 * команда знаходить прострочені й веде їх у сповіщення.
 */
class OverdueCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $command;
    private object $notifier;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->notifier = new class extends SupplyNotifier {
            /** @var string[] */
            public array $reminded = [];

            public function __construct()
            {
            }

            public function requestCreated(SupplyRequest $request): void
            {
            }

            public function statusChanged($request, $log): void
            {
            }

            public function overdue(SupplyRequest $request): void
            {
                $this->reminded[] = $request->getNumber();
            }
        };

        $container->set(SupplyNotifier::class, $this->notifier);

        $this->em = $container->get(EntityManagerInterface::class);
        $this->command = new CommandTester((new Application(self::$kernel))->find('supply:overdue'));
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testOverdueRequestIsRemindedAndFreshOneIsNot(): void
    {
        $author = $this->person();
        $late = $this->request($author, new DateTime('-3 days'));
        $fresh = $this->request($author, new DateTime('+5 days'));

        $this->command->execute([]);

        self::assertSame(0, $this->command->getStatusCode());
        self::assertContains($late->getNumber(), $this->notifier->reminded);
        self::assertNotContains($fresh->getNumber(), $this->notifier->reminded);
        self::assertStringContainsString($late->getNumber(), $this->command->getDisplay());
    }

    /** Закрита заявка вже нікого не цікавить, навіть якщо строк давно вийшов. */
    public function testClosedRequestIsSilent(): void
    {
        $author = $this->person();
        $manager = $this->person(SupplyRole::Manager);
        $closed = $this->request($author, new DateTime('-10 days'));

        (self::getContainer()->get(ChangeStatus::class))(
            $closed,
            SupplyStatus::Rejected,
            $manager,
            'Уже не потрібно',
        );

        $this->command->execute([]);

        self::assertNotContains($closed->getNumber(), $this->notifier->reminded);
    }

    /** Сухий запуск показує список, але нікого не смикає. */
    public function testDryRunSendsNothing(): void
    {
        $author = $this->person();
        $late = $this->request($author, new DateTime('-2 days'));

        $this->command->execute(['--dry-run' => true]);

        self::assertSame([], $this->notifier->reminded);
        self::assertStringContainsString($late->getNumber(), $this->command->getDisplay());
    }

    private function request(TelegramUser $author, DateTime $needBy): SupplyRequest
    {
        return (self::getContainer()->get(CreateRequest::class))(
            $author,
            new CreateRequestInput(
                item: 'Щебінь фракція 5-20',
                quantity: '4',
                unit: Unit::Ton,
                needBy: $needBy,
            ),
        );
    }

    private function person(SupplyRole $role = SupplyRole::Worker): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId('overdue-' . $role->value . '-' . uniqid())
            ->setFirstName($role->label())
            ->setSupplyRole($role);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
