<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\EventStore\FanOutHandler;
use App\Plan\EventStore\PlanEventRecorder;
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
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineQueue;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Telegram ніколи не дублюється: подія доходить до нотифаєра рівно одним шляхом
 * (слухач журналу), а не «і сервіс, і слухач».
 */
class NoDoubleNotificationTest extends KernelTestCase
{
    /** Хто, крім самих нотифаєрів, має право їх викликати. */
    private const array ALLOWED = [
        'SupplyNotifier' => [
            'src/Supply/Service/SupplyNotifier.php',
            'src/Supply/Listener/SupplyNotificationListener.php',
            // Прострочення — не доменна подія, а ранкове нагадування крону.
            'src/Command/SupplyOverdueCommand.php',
        ],
        'TaskNotifier' => [
            'src/Tasks/Service/TaskNotifier.php',
            'src/Tasks/Listener/TaskNotificationListener.php',
        ],
    ];

    private EntityManagerInterface $em;
    private FakeNutgram $bot;
    private int $seen = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->bot = Nutgram::fake();
        self::getContainer()->set(Nutgram::class, $this->bot);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    /** Сервіс, що знову почне кликати нотифаєр напряму, тут упаде. */
    public function testOnlyListenersReachTheNotifiers(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = substr($file->getPathname(), strlen($root) + 1);
            // Лише код: згадки в коментарях не рахуються.
            $code = '';

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= is_array($token) ? $token[1] : $token;
                }
            }

            foreach (self::ALLOWED as $class => $allowed) {
                if (preg_match('/\b' . $class . '\b/', $code) === 1 && ! in_array($path, $allowed, true)) {
                    $offenders[] = $path . ' → ' . $class;
                }
            }
        }

        self::assertSame([], $offenders, 'Нотифаєр кличуть лише слухачі журналу (інакше Telegram подвоїться)');
    }

    /**
     * Подію роздають лише після коміту transactional() рекордера. Транзакція, відкрита
     * в обхід нього, зробила б розсилку ДО коміту (і після відкату) — таких у src немає.
     */
    public function testNoRawTransactionsAroundEventWriters(): void
    {
        $root = dirname(__DIR__, 2);
        $allowed = [
            'src/Plan/EventStore/PlanEventRecorder.php',
            // Лише люди й паролі, подій не пише.
            'src/Tasks/Command/PlanUserCreateCommand.php',
        ];
        $offenders = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')) as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);

            if ($file->getExtension() === 'php' && ! in_array($path, $allowed, true) &&
                preg_match('/->(beginTransaction|wrapInTransaction)\(/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, 'Транзакції навколо змін — через PlanEventRecorder::transactional()');
    }

    public function testEachTaskActionReachesEveryoneExactlyOnce(): void
    {
        $manager = $this->person(WorkRole::Manager);
        $member = $this->person(WorkRole::Production);
        $other = $this->person(WorkRole::Accountant);
        $director = $this->person(WorkRole::Director);
        $tasks = self::getContainer()->get(TaskService::class);

        $task = $tasks->create($manager, ['title' => 'Кільця КС', 'memberIds' => [$member->getId(), $other->getId()]]);
        self::assertSame($this->expect([$member->getTelegramId() => 1, $other->getTelegramId() => 1]), $this->sent());

        $tasks->post($task, $manager, 'Перше повідомлення', mentions: [(int) $other->getId()]);
        self::assertSame($this->expect([$member->getTelegramId() => 1, $other->getTelegramId() => 1]), $this->sent(), 'Згаданий учасник — одне «вас згадали», не два');

        $tasks->changeStatus($task, $manager, TaskStatus::Approval);
        $sent = $this->sent();
        self::assertSame(1, $sent[$director->getTelegramId()] ?? 0, 'Директор — одне «потрібне рішення»');
        self::assertSame([1], array_values(array_unique($sent)), 'Кожен — рівно один раз');
        self::assertArrayNotHasKey($manager->getTelegramId(), $sent, 'Автору дії — нічого');

        $tasks->update($task, $manager, ['memberIds' => [$member->getId(), $other->getId(), $director->getId()]]);
        self::assertSame([$director->getTelegramId() => 1], $this->sent());

        // Правка й видалення — без Telegram, як і було.
        $message = $tasks->post($task, $member, 'Друге');
        $this->sent();
        $tasks->editMessage($message, $member, 'Друге, виправлене');
        $tasks->deleteMessage($message, $member);
        self::assertSame([], $this->sent());
    }

    public function testEachSupplyActionReachesEveryoneExactlyOnce(): void
    {
        $worker = $this->person(WorkRole::Production, SupplyRole::Worker);
        $manager = $this->person(WorkRole::Manager, SupplyRole::Manager);

        $request = (self::getContainer()->get(CreateRequest::class))($worker, new CreateRequestInput(item: 'Арматура 12', quantity: '2', unit: Unit::Ton));
        $created = $this->sent();
        self::assertSame(1, $created[$worker->getTelegramId()] ?? 0, 'Заявнику — одне «Заявку прийнято»');
        self::assertSame([1], array_values(array_unique($created)));

        (self::getContainer()->get(ChangeStatus::class))($request, SupplyStatus::InProgress, $manager);
        self::assertSame([$worker->getTelegramId() => 1], $this->sent());

        (self::getContainer()->get(AddComment::class))($request, $manager, 'Привезуть у четвер');
        self::assertSame([$worker->getTelegramId() => 1], $this->sent());
    }

    /**
     * Режим «Плану» (doctrine-черга + воркер): у запиті Telegram не йде зовсім,
     * воркер шле кожному рівно один раз; повторна обробка того ж повідомлення
     * черги неможлива — його підтверджено (ack).
     */
    public function testAsyncWorkerSendsOnceAndRequestSendsNothing(): void
    {
        $queue = 'plan_worker_' . uniqid();
        $db = $this->em->getConnection();
        $transport = new DoctrineTransport(new DoctrineQueue(['queue_name' => $queue, 'auto_setup' => false], $db), new PhpSerializer());
        self::getContainer()->set(PlanEventRecorder::class, new PlanEventRecorder(
            $db,
            new SpyBus(static fn (object $m) => $transport->send(Envelope::wrap($m))),
            new RequestStack(),
            new NullLogger(),
            'doctrine://default?queue_name=' . $queue,
        ));

        $manager = $this->person(WorkRole::Manager);
        $member = $this->person(WorkRole::Production);
        $tasks = self::getContainer()->get(TaskService::class);
        $task = $tasks->create($manager, ['title' => 'Через воркер', 'memberIds' => [$member->getId()]]);
        $tasks->post($task, $manager, 'Повідомлення');
        self::assertSame([], $this->sent(), 'HTTP-запит Telegram не шле — це робота воркера');

        // «messenger:consume plan»
        $handler = self::getContainer()->get(FanOutHandler::class);
        $handled = 0;

        while ($envelopes = iterator_to_array($transport->get())) {
            foreach ($envelopes as $envelope) {
                $handler($envelope->getMessage());
                $transport->ack($envelope);
                ++$handled;
            }
        }

        self::assertSame(2, $handled);
        self::assertSame([$member->getTelegramId() => 2], $this->sent(), '«Вас додали» + «Нове повідомлення», кожне раз');
    }

    /** @param array<string, int> $counts @return array<string, int> */
    private function expect(array $counts): array
    {
        ksort($counts);

        return $counts;
    }

    /**
     * @phpstan-impure
     *
     * @return array<string, int> chat_id → скільки повідомлень пішло з останнього виклику
     */
    private function sent(): array
    {
        $count = [];
        $history = $this->bot->getRequestHistory();

        foreach (array_slice($history, $this->seen) as $pair) {
            [$request] = array_values($pair);
            $chat = (string) (FakeNutgram::getActualData($request)['chat_id'] ?? '');
            $count[$chat] = ($count[$chat] ?? 0) + 1;
        }

        $this->seen = count($history);
        ksort($count);

        return $count;
    }

    private function person(WorkRole $role, SupplyRole $supply = SupplyRole::Worker): TelegramUser
    {
        $user = new TelegramUser()
            ->setTelegramId((string) random_int(100_000_000, 999_999_999))
            ->setFirstName('Тест-Дубль')
            ->setWorkRole($role)
            ->setSupplyRole($supply);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
