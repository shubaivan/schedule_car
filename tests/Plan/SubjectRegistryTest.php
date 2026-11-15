<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectRegistry;
use App\Plan\Subject\SubjectType;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\Unit;
use App\Supply\Service\CreateRequest;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\TaskService;
use App\Tests\Warehouse\WarehouseFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Спільний шар суб'єктів: назва, адреса, «хто в темі», хто бачить — для кожного модуля. */
class SubjectRegistryTest extends KernelTestCase
{
    use WarehouseFixtures;

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

    protected function em(): EntityManagerInterface
    {
        return $this->em;
    }

    public function testEveryModuleIsCovered(): void
    {
        $registry = self::getContainer()->get(SubjectRegistry::class);

        foreach (SubjectType::cases() as $type) {
            // Ліди підключить потік SL разом із сутністю lead.
            self::assertSame($type !== SubjectType::Lead, $registry->has($type), $type->value);
        }
    }

    public function testTaskSupplyAndWarehouseSubjects(): void
    {
        $registry = self::getContainer()->get(SubjectRegistry::class);
        $author = $this->worker(WorkRole::Manager, SupplyRole::Manager);
        $member = $this->worker(WorkRole::Production, SupplyRole::Worker);
        $outsider = $this->worker(WorkRole::Accountant, SupplyRole::Worker);
        $director = $this->worker(WorkRole::Director, SupplyRole::Director);

        $task = self::getContainer()->get(TaskService::class)->create($author, ['title' => 'Укриття', 'memberIds' => [$member->getId()]]);
        $ref = SubjectRef::task((int) $task->getId());
        self::assertSame('№ ' . $task->number() . ' · Укриття', $registry->title($ref));
        self::assertSame('/crm/tasks/board/' . $task->getId(), $registry->url($ref));
        self::assertEqualsCanonicalizing([$author->getId(), $member->getId()], $registry->audience($ref));
        self::assertTrue($registry->visibleTo($ref, $member));
        self::assertTrue($registry->visibleTo($ref, $director));
        self::assertFalse($registry->visibleTo($ref, $outsider));

        $request = (self::getContainer()->get(CreateRequest::class))($member, new CreateRequestInput(item: 'Пісок', quantity: '1', unit: Unit::Piece));
        $supply = SubjectRef::supply((int) $request->getId());
        self::assertStringContainsString($request->getNumber(), (string) $registry->title($supply));
        self::assertSame('/crm/requests/' . $request->getId(), $registry->url($supply));
        self::assertContains($member->getId(), $registry->audience($supply));
        self::assertContains($author->getId(), $registry->audience($supply), 'Менеджер постачання в темі');
        self::assertTrue($registry->visibleTo($supply, $outsider), 'Заявки відкриті всім допущеним');

        $item = $this->item();
        $whRef = SubjectRef::of(SubjectType::WhItem, (int) $item->getId());
        self::assertSame($item->getLabel(), $registry->title($whRef));
        self::assertSame('/sklad/items/' . $item->getId(), $registry->url($whRef));
        self::assertTrue($registry->visibleTo($whRef, $author));
        self::assertFalse($registry->visibleTo($whRef, $member));

        self::assertNull($registry->title(SubjectRef::task(999_999_999)));
    }

    private function worker(WorkRole $role, SupplyRole $supply): TelegramUser
    {
        $user = new TelegramUser()
            ->setTelegramId('subj-' . uniqid('', true))
            ->setFirstName('Тест-Суб')
            ->setWorkRole($role)
            ->setSupplyRole($supply);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
