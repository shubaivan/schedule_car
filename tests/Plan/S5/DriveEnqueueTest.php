<?php

namespace App\Tests\Plan\S5;

use App\Plan\Drive\DriveClientFactory;
use App\Plan\Drive\DriveSync;
use App\Plan\Drive\Listener\DriveEnqueue;
use App\Plan\Drive\SyncDriveFiles;
use App\Plan\Drive\SyncDriveFilesHandler;
use App\Plan\Event\FileAdded;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Tasks\Enum\WorkRole;
use App\Tests\Plan\SpyBus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Новий файл — на Диск одразу, але лише у воркері (async): у sync-режимі (стенди,
 * HTTP-запит людини) Google не смикаємо, файл чекає крону.
 */
class DriveEnqueueTest extends KernelTestCase
{
    use S5Fixtures;

    private Connection $db;
    private string $dir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->db->beginTransaction();
        $this->isolateDriveQueue();
        $this->dir = sys_get_temp_dir() . '/s5-fake-drive-' . uniqid();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }

        new Filesystem()->remove($this->dir);
        parent::tearDown();
    }

    public function testOnlyWorkerUploadsRightAway(): void
    {
        $author = $this->person(WorkRole::Manager, 'mgr');
        $task = $this->task($author, 'Одразу на Диск');
        $file = $this->taskFile($task, $author);
        $event = new FileAdded(SubjectRef::task((int) $task->getId()), $author->getId(), [(int) $file->getId()]);
        $planFactory = new DriveClientFactory('', '', '', '', 'plan', $this->dir);
        $handler = new SyncDriveFilesHandler(self::getContainer()->get(DriveSync::class), $planFactory);
        // «Воркер async»: шина одразу віддає повідомлення обробнику.
        $bus = new SpyBus(static fn (object $m) => $m instanceof SyncDriveFiles ? $handler($m) : null);

        new DriveEnqueue($bus, $planFactory, $this->recorder('sync://'))->consume($event);
        self::assertSame([], $bus->messages, 'sync-режим: Google не смикаємо, лише черга');

        new DriveEnqueue($bus, new DriveClientFactory('', '', '', '', 'stand', $this->dir), $this->recorder('doctrine://default?queue_name=plan'))->consume($event);
        self::assertSame([], $bus->messages, 'стенд заявок: дерево «План» не створюємо');
        self::assertSame('pending', $this->state((int) $file->getId()));

        new DriveEnqueue($bus, $planFactory, $this->recorder('doctrine://default?queue_name=plan'))->consume($event);
        self::assertCount(1, $bus->messages);
        self::assertSame('done', $this->state((int) $file->getId()));
        self::assertFileExists($this->dir . '/index.json');
    }

    private function recorder(string $transport): PlanEventRecorder
    {
        return new PlanEventRecorder($this->db, new SpyBus(), new RequestStack(), new NullLogger(), $transport);
    }

    private function state(int $id): string
    {
        return (string) $this->db->fetchOne('SELECT drive_state FROM task_attachment WHERE id = ?', [$id]);
    }
}
