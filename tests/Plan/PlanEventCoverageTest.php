<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Event\PlanEvent;
use App\Plan\EventStore\PlanEventStore;
use App\Plan\Subject\SubjectType;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Dto\PurchaseInput;
use App\Supply\Enum\SupplyAccent;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Enum\Unit;
use App\Supply\Service\AddComment;
use App\Supply\Service\AttachFile;
use App\Supply\Service\ChangeStatus;
use App\Supply\Service\CreateRequest;
use App\Supply\Service\MarkRequest;
use App\Supply\Service\RecordPurchase;
use App\Supply\Service\SupplierDirectory;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\TaskService;
use App\Tests\Warehouse\WarehouseFixtures;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\MovementType;
use App\Warehouse\Service\ActivityLog;
use App\Warehouse\Service\RecordMovement;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Приймання S0: ОДНА дія користувача = РІВНО ОДИН рядок plan_event потрібного
 * типу — для кожного сервісу, що змінює задачу, заявку чи склад (як
 * NotificationCoverageTest для сповіщень). Новий сервіс без події тут упаде.
 */
class PlanEventCoverageTest extends KernelTestCase
{
    use WarehouseFixtures;

    private EntityManagerInterface $em;
    private int $mark = 0;

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

    public function testEverySupplyActionWritesExactlyOneEvent(): void
    {
        $worker = $this->supplyUser(SupplyRole::Worker);
        $manager = $this->supplyUser(SupplyRole::Manager);
        $director = $this->supplyUser(SupplyRole::Director);
        $status = self::getContainer()->get(ChangeStatus::class);
        $purchases = self::getContainer()->get(RecordPurchase::class);
        $files = self::getContainer()->get(AttachFile::class);

        $this->begin();
        $request = (self::getContainer()->get(CreateRequest::class))($worker, new CreateRequestInput(item: 'Пісок річковий', quantity: '5', unit: Unit::CubicMeter));
        $created = $this->one('subject_created', SubjectType::Supply, $request->getId(), $worker);
        self::assertSame($request->getStatusLogs()->first()->getId(), $created->payload()['refId']);
        self::assertStringContainsString($request->getNumber(), $created->payload()['title']);

        $this->begin();
        $status($request, SupplyStatus::InProgress, $manager);
        $event = $this->one('status_changed', SubjectType::Supply, $request->getId(), $manager);
        self::assertSame(['new', 'in_progress'], [$event->payload()['from'], $event->payload()['to']]);

        $this->begin();
        (self::getContainer()->get(AddComment::class))($request, $manager, 'Шукаю постачальника');
        $this->one('comment_added', SubjectType::Supply, $request->getId(), $manager);

        $this->begin();
        $purchase = $purchases($request, $manager, $this->purchase(), notify: false);
        self::assertFalse($this->one('purchase_recorded', SubjectType::Supply, $request->getId(), $manager)->payload()['notify']);

        $this->begin();
        $status($request, SupplyStatus::Approval, $manager);
        $this->one('approval_requested', SubjectType::Supply, $request->getId(), $manager);

        $this->begin();
        $status($request, SupplyStatus::Approved, $director);
        $this->one('status_changed', SubjectType::Supply, $request->getId(), $director);

        $this->begin();
        $purchases->update($purchase, $manager, $this->purchase('9000'));
        self::assertTrue($this->one('purchase_recorded', SubjectType::Supply, $request->getId(), $manager)->payload()['updated']);

        $this->begin();
        $attachment = $files($request, $manager, '%PDF-1.4', 'Накладна.pdf', 'application/pdf');
        self::assertSame([$attachment->getId()], $this->one('file_added', SubjectType::Supply, $request->getId(), $manager)->payload()['fileIds']);

        $this->begin();
        $files->remove($attachment, $manager);
        self::assertSame(['Накладна.pdf'], $this->one('file_removed', SubjectType::Supply, $request->getId(), $manager)->payload()['names']);

        $this->begin();
        (self::getContainer()->get(MarkRequest::class))($request, SupplyAccent::cases()[1], $manager);
        self::assertSame(['accent'], $this->one('subject_updated', SubjectType::Supply, $request->getId(), $manager)->payload()['fields']);

        // Друга закупівля, щоб першу можна було скасувати в «погоджено».
        $second = $purchases($request, $manager, $this->purchase('100'));
        $this->begin();
        $purchases->remove($second, $manager);
        $this->one('purchase_removed', SubjectType::Supply, $request->getId(), $manager);

        foreach ([SupplyStatus::Paid, SupplyStatus::Delivery] as $to) {
            $status($request, $to, $manager);
        }

        $this->begin();
        $status($request, SupplyStatus::InStock, $manager);
        $this->one('subject_closed', SubjectType::Supply, $request->getId(), $manager);

        $other = (self::getContainer()->get(CreateRequest::class))($worker, new CreateRequestInput(item: 'Цемент', quantity: '1', unit: Unit::Piece));
        $this->begin();
        $status($other, SupplyStatus::Rejected, $manager, 'Є на складі');
        self::assertSame('Є на складі', $this->one('subject_closed', SubjectType::Supply, $other->getId(), $manager)->payload()['comment']);
    }

    public function testEveryTaskActionWritesExactlyOneEvent(): void
    {
        $manager = $this->worker(WorkRole::Manager);
        $member = $this->worker(WorkRole::Production);
        $extra = $this->worker(WorkRole::Accountant);
        $director = $this->worker(WorkRole::Director);
        $tasks = self::getContainer()->get(TaskService::class);

        $this->begin();
        $task = $tasks->create($manager, ['title' => 'Плити П-3', 'memberIds' => [$member->getId()]]);
        $this->one('subject_created', SubjectType::Task, $task->getId(), $manager);

        $this->begin();
        $tasks->update($task, $manager, ['description' => 'Новий опис']);
        self::assertSame(['description'], $this->one('subject_updated', SubjectType::Task, $task->getId(), $manager)->payload()['fields']);

        $this->begin();
        $tasks->update($task, $manager, ['memberIds' => [$member->getId(), $extra->getId()]]);
        self::assertSame([$extra->getId()], $this->one('member_added', SubjectType::Task, $task->getId(), $manager)->payload()['userIds']);

        $this->begin();
        $tasks->update($task, $manager, ['memberIds' => [$member->getId()]]);
        self::assertSame([$extra->getId()], $this->one('member_removed', SubjectType::Task, $task->getId(), $manager)->payload()['userIds']);

        $this->begin();
        $tasks->changeStatus($task, $manager, TaskStatus::InWork);
        $this->one('status_changed', SubjectType::Task, $task->getId(), $manager);

        $this->begin();
        $message = $tasks->post($task, $manager, 'Рахунок готовий', mentions: [(int) $director->getId()]);
        $event = $this->one('comment_added', SubjectType::Task, $task->getId(), $manager);
        self::assertSame([(int) $director->getId()], $event->payload()['mentions']);
        self::assertSame($message->getId(), $event->payload()['refId']);

        $this->begin();
        $file = tempnam(sys_get_temp_dir(), 'pln');
        file_put_contents($file, 'креслення');
        $tasks->post($task, $manager, '', [new UploadedFile($file, 'Креслення.txt', 'text/plain', null, true)]);
        self::assertSame(['Креслення.txt'], $this->one('file_added', SubjectType::Task, $task->getId(), $manager)->payload()['names']);

        $this->begin();
        $tasks->editMessage($message, $manager, 'Рахунок готовий, перевірте');
        $this->one('comment_edited', SubjectType::Task, $task->getId(), $manager);

        $this->begin();
        $tasks->deleteMessage($message, $manager);
        $this->one('comment_deleted', SubjectType::Task, $task->getId(), $manager);

        $this->begin();
        $tasks->changeStatus($task, $manager, TaskStatus::Approval);
        $this->one('approval_requested', SubjectType::Task, $task->getId(), $manager);

        $this->begin();
        $tasks->changeStatus($task, $director, TaskStatus::Rejected, 'Дорого');
        self::assertSame('Дорого', $this->one('subject_closed', SubjectType::Task, $task->getId(), $director)->payload()['comment']);

        // Особисті позначки (★, прочитано) — не події журналу.
        $this->begin();
        $tasks->favorite($task, $manager, true);
        $tasks->markRead($task, $manager);
        self::assertSame([], $this->since());
    }

    public function testWarehouseWritesOneEventPerAction(): void
    {
        $manager = $this->person();
        $warehouse = $this->warehouse();
        $item = $this->item();
        $log = self::getContainer()->get(ActivityLog::class);

        $this->begin();
        $movement = (self::getContainer()->get(RecordMovement::class))(MovementType::Receipt, new DateTime('2026-11-15'), null, $warehouse, [['item' => $item, 'quantity' => 1]], $manager);
        // Контролер після руху пише «рух» у журнал складу — у plan_event другої події не буде.
        $log->record(ActivityAction::Move, $movement, $manager, WhActivity::CHANNEL_ADMIN, 'ззовні → склад');
        $log->record(ActivityAction::Move, $item, $manager, WhActivity::CHANNEL_ADMIN, 'рух');
        $moved = $this->one('stock_moved', SubjectType::WhMovement, $movement->getId(), $manager);
        self::assertSame([$item->getId()], $moved->payload()['itemIds']);
        self::assertSame('receipt', $moved->payload()['movementType']);

        $cases = [
            [ActivityAction::Create, 'item_created'],
            [ActivityAction::Update, 'subject_updated'],
            [ActivityAction::DocumentAdd, 'file_added'],
            [ActivityAction::DocumentRemove, 'file_removed'],
            [ActivityAction::View, 'subject_viewed'],
            [ActivityAction::Scan, 'subject_viewed'],
        ];

        foreach ($cases as [$action, $type]) {
            $this->begin();
            $log->record($action, $item, $manager, WhActivity::CHANNEL_BOT, 'деталі');
            self::assertSame('bot', $this->one($type, SubjectType::WhItem, $item->getId(), $manager)->channel());
        }

        $this->begin();
        $log->record(ActivityAction::Create, $warehouse, $manager, WhActivity::CHANNEL_ADMIN);
        $this->one('subject_created', SubjectType::WhSite, $warehouse->getId(), $manager);
    }

    private function begin(): void
    {
        $this->mark = (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM plan_event');
    }

    /** @return list<PlanEvent> */
    private function since(): array
    {
        $store = self::getContainer()->get(PlanEventStore::class);

        return array_map(
            static fn ($id) => $store->load((int) $id) ?? throw new LogicException('gone'),
            $this->em->getConnection()->fetchFirstColumn('SELECT id FROM plan_event WHERE id > ? ORDER BY id', [$this->mark]),
        );
    }

    private function one(string $type, SubjectType $subject, ?int $id, TelegramUser $actor): PlanEvent
    {
        $events = $this->since();
        self::assertCount(1, $events, sprintf('Очікували рівно одну подію %s, отримали: %s', $type, implode(', ', array_map(static fn (PlanEvent $e) => $e::type(), $events))));
        self::assertSame($type, $events[0]::type());
        self::assertSame($subject, $events[0]->subject->type);
        self::assertSame($id, $events[0]->subject->id);
        self::assertSame($actor->getId(), $events[0]->actorId);

        return $events[0];
    }

    private function purchase(string $amount = '12500'): PurchaseInput
    {
        return new PurchaseInput(supplier: self::getContainer()->get(SupplierDirectory::class)->findOrCreate('ФОП Петренко ' . uniqid()), totalAmount: $amount);
    }

    private function supplyUser(SupplyRole $role): TelegramUser
    {
        return $this->person($role);
    }

    private function worker(WorkRole $role): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId('plan-' . uniqid('', true))
            ->setFirstName('Тест-План')
            ->setWorkRole($role);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
