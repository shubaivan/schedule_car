<?php

namespace App\Tests\Plan\Notification;

use App\Plan\Notification\Entity\NotificationSetting;
use App\Plan\Notification\EventGroup;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Dto\PurchaseInput;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Enum\Unit;
use App\Supply\Service\AddComment;
use App\Supply\Service\ChangeStatus;
use App\Supply\Service\CreateRequest;
use App\Supply\Service\RecordPurchase;
use App\Supply\Service\SupplierDirectory;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\TaskService;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Хто потрапляє в центр сповіщень: усі «в темі» задачі чи заявки (автор,
 * виконавці, спостерігачі ★, менеджери, ті, хто коментував), крім автора дії;
 * директор — на погодженні й за налаштуванням «Усе»; нічого поза правами.
 */
class AudienceResolverTest extends KernelTestCase
{
    use NotificationFixtures;

    private EntityManagerInterface $em;
    private TaskService $tasks;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->set(Nutgram::class, Nutgram::fake());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->tasks = self::getContainer()->get(TaskService::class);
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

    public function testTaskReachesCreatorMembersAndWatchersButNotActorOrOutsiders(): void
    {
        $creator = $this->person(WorkRole::Manager);
        $executor = $this->person(WorkRole::Production);
        // Спостерігач ★ — той, хто бачить задачу, не будучи учасником (адмін).
        $watcher = $this->person(WorkRole::Admin);
        $outsider = $this->person(WorkRole::Accountant);
        $director = $this->person(WorkRole::Director);
        $pending = $this->person(WorkRole::Production, approved: false);
        $archived = $this->person(WorkRole::Production);

        $task = $this->tasks->create($creator, ['title' => 'Плити П-3', 'memberIds' => [$executor->getId(), $archived->getId(), $pending->getId()]]);
        self::assertSame([$executor->getId() => 'assigned', $archived->getId() => 'assigned'], $this->rowsForLastEvent(), 'Нова задача — учасникам, не автору; непідтвердженому — ні');

        $archived->archive();
        $this->tasks->favorite($task, $watcher, true);
        $this->em->flush();

        $this->tasks->post($task, $executor, 'Привезли плити');
        self::assertSame(
            [$creator->getId() => 'comment', $watcher->getId() => 'comment'],
            $this->rowsForLastEvent(),
            'Коментар виконавця — автору й спостерігачу ★; ні собі, ні стороннім, ні директору без «Усе», ні тому, хто в кошику',
        );
        self::assertArrayNotHasKey($outsider->getId(), $this->rowsForLastEvent());
        self::assertArrayNotHasKey($director->getId(), $this->rowsForLastEvent());
    }

    public function testMentionIsOneImportantNotification(): void
    {
        $creator = $this->person(WorkRole::Manager);
        $a = $this->person();
        $b = $this->person();
        $task = $this->tasks->create($creator, ['title' => 'Кільця', 'memberIds' => [$a->getId(), $b->getId()]]);

        $this->tasks->post($task, $creator, '@a подивись', mentions: [(int) $a->getId()]);
        self::assertSame([$a->getId() => 'mention', $b->getId() => 'comment'], $this->rowsForLastEvent());
        self::assertTrue(EventGroup::Mention->important());
        self::assertFalse(EventGroup::Comment->important());
    }

    /** ★ лишилась, а з учасників людину прибрали — задачу їй уже не видно, сповіщень теж немає. */
    public function testWatcherWhoLostAccessGetsNothing(): void
    {
        $creator = $this->person(WorkRole::Manager);
        $member = $this->person();
        $former = $this->person(WorkRole::Production);
        $task = $this->tasks->create($creator, ['title' => 'Секретна', 'memberIds' => [$member->getId(), $former->getId()]]);
        $this->tasks->favorite($task, $former, true);
        $this->tasks->update($task, $creator, ['memberIds' => [$member->getId()]]);

        $this->tasks->post($task, $creator, 'привіт');
        self::assertSame([$member->getId() => 'comment'], $this->rowsForLastEvent(), 'Сповіщення — лише тим, кому задачу видно');
    }

    public function testApprovalGoesToDecidersAsImportantAndToMembersAsStatus(): void
    {
        $creator = $this->person(WorkRole::Manager);
        $member = $this->person();
        $director = $this->person(WorkRole::Director);
        $admin = $this->person(WorkRole::Admin);
        $task = $this->tasks->create($creator, ['title' => 'Знижка 7%', 'memberIds' => [$member->getId()]]);

        $this->tasks->changeStatus($task, $creator, TaskStatus::Approval);
        self::assertSame(
            [$member->getId() => 'status', $director->getId() => 'approval', $admin->getId() => 'approval'],
            array_intersect_key($this->rowsForLastEvent(), array_flip([$member->getId(), $director->getId(), $admin->getId(), $creator->getId()])),
        );
    }

    public function testOnlyAddedPeopleHearAboutBeingAdded(): void
    {
        $creator = $this->person(WorkRole::Manager);
        $old = $this->person();
        $new = $this->person();
        $task = $this->tasks->create($creator, ['title' => 'Кошторис', 'memberIds' => [$old->getId()]]);

        $this->tasks->update($task, $creator, ['memberIds' => [$old->getId(), $new->getId()]]);
        self::assertSame([$new->getId() => 'assigned'], $this->rowsForLastEvent());
    }

    public function testDirectorWatchingEverythingGetsUninvolvedTaskEvents(): void
    {
        $creator = $this->person(WorkRole::Manager);
        $member = $this->person();
        $director = $this->person(WorkRole::Director);
        $accountant = $this->person(WorkRole::Accountant);
        $this->em->persist((new NotificationSetting($director))->setScope(NotificationSetting::SCOPE_ALL));
        // Бухгалтер теж «увімкнув Усе» в обхід UI — права бачити все в нього немає.
        $this->em->persist((new NotificationSetting($accountant))->setScope(NotificationSetting::SCOPE_ALL));
        $this->em->flush();

        $task = $this->tasks->create($creator, ['title' => 'Чужа для директора', 'memberIds' => [$member->getId()]]);
        $this->tasks->post($task, $member, 'Готово');
        $rows = $this->rowsForLastEvent();
        self::assertSame('comment', $rows[$director->getId()] ?? null, 'Директор із «Усе» бачить і не свої задачі');
        self::assertArrayNotHasKey($accountant->getId(), $rows);
    }

    public function testSupplyReachesAuthorManagersAndCommentersApprovalReachesDirectors(): void
    {
        $author = $this->person(WorkRole::Production, SupplyRole::Worker);
        $manager = $this->person(WorkRole::Manager, SupplyRole::Manager);
        $director = $this->person(WorkRole::Director, SupplyRole::Director);
        $worker = $this->person(WorkRole::Production, SupplyRole::Worker);

        $request = (self::getContainer()->get(CreateRequest::class))($author, new CreateRequestInput(item: 'Цемент М500', quantity: '3', unit: Unit::Ton));
        $rows = $this->rowsForLastEvent();
        self::assertSame('assigned', $rows[$manager->getId()] ?? null, 'Нова заявка — менеджерам');
        self::assertArrayNotHasKey($author->getId(), $rows, 'Автору дії — нічого');
        self::assertArrayNotHasKey($worker->getId(), $rows);

        (self::getContainer()->get(AddComment::class))($request, $manager, 'Уточніть марку');
        self::assertSame('comment', $this->rowsForLastEvent()[$author->getId()] ?? null);

        $change = self::getContainer()->get(ChangeStatus::class);
        $change($request, SupplyStatus::InProgress, $manager);
        $supplier = self::getContainer()->get(SupplierDirectory::class)->findOrCreate('ФОП Тест-Сповіщ ' . uniqid());
        (self::getContainer()->get(RecordPurchase::class))($request, $manager, new PurchaseInput(supplier: $supplier, totalAmount: '9000'), notify: false);
        $change($request, SupplyStatus::Approval, $manager);
        $rows = $this->rowsForLastEvent();
        self::assertSame('approval', $rows[$director->getId()] ?? null, 'Погодження заявки — директору, важливе');
        self::assertSame('status', $rows[$author->getId()] ?? null);
        self::assertArrayNotHasKey($worker->getId(), $rows);
    }
}
