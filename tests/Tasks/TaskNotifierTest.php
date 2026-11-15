<?php

namespace App\Tests\Tasks;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Repository\TelegramUserRepository;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskMessage;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\TaskNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Хто отримує сповіщення по задачі в Telegram. */
class TaskNotifierTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FakeNutgram $bot;
    private TaskNotifier $notifier;
    /** @var TelegramUser[] */
    private array $made = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->bot = Nutgram::fake();
        $this->notifier = new TaskNotifier(
            $this->bot,
            self::getContainer()->get(TelegramUserRepository::class),
            self::getContainer()->get(UrlGeneratorInterface::class),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $user) {
            $this->em->getConnection()->executeStatement('DELETE FROM task WHERE creator_id = ?', [$user->getId()]);
        }
        foreach ($this->made as $user) {
            $this->em->getConnection()->executeStatement('DELETE FROM telegram_user WHERE id = ?', [$user->getId()]);
        }
        parent::tearDown();
    }

    public function testMessageGoesToMembersExceptAuthorAndMentionIsMarked(): void
    {
        $author = $this->person(WorkRole::Manager);
        $member = $this->person(WorkRole::Production);
        $mentioned = $this->person(WorkRole::Accountant);
        $crmOnly = $this->person(WorkRole::Production, 'staff-' . uniqid());
        $outsider = $this->person(WorkRole::Production);

        $task = $this->task($author, [$member, $mentioned, $crmOnly]);
        $message = new TaskMessage($task, $author, 'Рахунок <готовий>', TaskMessage::KIND_COMMENT, [(int) $mentioned->getId()]);
        $this->em->persist($message);
        $this->em->flush();

        $this->notifier->message($message);
        $sent = $this->sent();

        self::assertArrayHasKey($member->getTelegramId(), $sent);
        self::assertStringContainsString('Нове повідомлення', $sent[$member->getTelegramId()]);
        self::assertStringContainsString('&lt;готовий&gt;', $sent[$member->getTelegramId()], 'HTML екрановано');
        self::assertStringContainsString('Вас згадали', $sent[$mentioned->getTelegramId()]);
        self::assertArrayNotHasKey($author->getTelegramId(), $sent, 'Автору — ні');
        self::assertArrayNotHasKey($outsider->getTelegramId(), $sent, 'Сторонньому — ні');
        self::assertArrayNotHasKey($crmOnly->getTelegramId(), $sent, 'Картці без Telegram — ні');
    }

    public function testApprovalReachesDirectorEvenIfNotMember(): void
    {
        $manager = $this->person(WorkRole::Manager);
        $director = $this->person(WorkRole::Director);
        $task = $this->task($manager, []);
        $task->setStatus(TaskStatus::Approval);

        $this->notifier->statusChanged($task, TaskStatus::InWork, $manager);

        self::assertStringContainsString('Потрібне ваше рішення', $this->sent()[$director->getTelegramId()] ?? '');
    }

    /** @return array<string, string> chat_id => текст */
    private function sent(): array
    {
        $result = [];

        foreach ($this->bot->getRequestHistory() as $reqRes) {
            [$request] = array_values($reqRes);
            $data = FakeNutgram::getActualData($request);
            $result[(string) ($data['chat_id'] ?? '')] = (string) ($data['text'] ?? '');
        }

        return $result;
    }

    /** @param TelegramUser[] $members */
    private function task(TelegramUser $creator, array $members): Task
    {
        $task = new Task('Плити для обʼєкта', $creator);
        $task->addMember($creator);
        foreach ($members as $m) {
            $task->addMember($m);
        }
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function person(WorkRole $role, ?string $telegramId = null): TelegramUser
    {
        $user = new TelegramUser()
            ->setTelegramId($telegramId ?? (string) random_int(900000000, 999999999))
            ->setFirstName($role->label())
            ->setWorkRole($role);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();
        $this->made[] = $user;

        return $user;
    }
}
