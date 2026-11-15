<?php

namespace App\Tests\Tasks;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use App\Service\AccessService;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskMessage;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Exception\TaskException;
use App\Tasks\Service\StaffDirectory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Людину завели в CRM, потім вона зайшла в бота — має лишитись ОДИН запис
 * з її задачами, роллю й відділом.
 */
class StaffLinkerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var int[] */
    private array $ids = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->ids as $id) {
            $conn->executeStatement('DELETE FROM task WHERE creator_id = ?', [$id]);
        }
        foreach ($this->ids as $id) {
            $conn->executeStatement('DELETE FROM telegram_user WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    public function testBotRegistrationMergesCrmCard(): void
    {
        $admin = $this->admin();
        $phone = '38067' . random_int(1000000, 9999999);
        $card = self::getContainer()->get(StaffDirectory::class)->create([
            'firstName' => 'Сергій', 'lastName' => 'Гончар', 'position' => 'Начальник цеху',
            'role' => 'production', 'phone' => $phone, 'email' => 'linker' . uniqid() . '@example.test', 'password' => 'secret-pass-1',
        ], $admin);
        $this->ids[] = (int) $card->getId();

        $task = new Task('Партія ПК', $card);
        $task->addMember($card);
        $this->em->persist($task);
        $this->em->persist(new TaskMessage($task, $card, 'Відформовано'));
        $this->em->flush();
        $cardId = $card->getId();
        $email = $card->getEmail();

        $bot = new TelegramUser()->setTelegramId((string) random_int(100000000, 899999999))->setFirstName('Serhii');
        $this->em->persist($bot);
        $this->em->flush();
        $this->ids[] = (int) $bot->getId();

        self::getContainer()->get(AccessService::class)->registerPhone($bot, '+' . $phone);
        $this->em->clear();

        self::assertNull($this->em->find(TelegramUser::class, $cardId), 'Картку CRM прибрано');
        $merged = $this->em->find(TelegramUser::class, $bot->getId());
        self::assertTrue($merged->isApproved());
        self::assertSame('Сергій', $merged->getFirstName());
        self::assertSame(WorkRole::Production, $merged->getWorkRole());
        self::assertSame($email, $merged->getEmail());
        self::assertNotNull($merged->getPassword());

        $task = $this->em->find(Task::class, $task->getId());
        self::assertSame($merged->getId(), $task->getCreator()->getId());
        self::assertTrue($task->hasMember($merged));
        $author = $this->em->getRepository(TaskMessage::class)->findOneBy(['task' => $task])->getAuthor();
        self::assertSame($merged->getId(), $author?->getId());
    }

    public function testCrmRefusesSecondCardForSamePhone(): void
    {
        $admin = $this->admin();
        $phone = '38050' . random_int(1000000, 9999999);
        $existing = new TelegramUser()->setTelegramId((string) random_int(100000000, 899999999))->setPhoneNumber($phone)->setFirstName('Вже є');
        $this->em->persist($existing);
        $this->em->flush();
        $this->ids[] = (int) $existing->getId();

        $this->expectException(TaskException::class);
        self::getContainer()->get(StaffDirectory::class)->create(['firstName' => 'Дубль', 'phone' => '0' . substr($phone, 3)], $admin);
    }

    private function admin(): TelegramUser
    {
        $admin = new TelegramUser()->setTelegramId('linker-admin-' . uniqid())->setFirstName('Адмін')->setWorkRole(WorkRole::Admin);
        $this->em->persist($admin);
        $this->em->flush();
        $this->ids[] = (int) $admin->getId();
        self::getContainer()->get(TelegramUserRepository::class);

        return $admin;
    }
}
