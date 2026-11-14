<?php

namespace App\Tests\Plan\Notification;

use App\Plan\EventStore\FanOut;
use App\Plan\EventStore\FanOutHandler;
use App\Plan\Notification\Entity\NotificationSetting;
use App\Plan\Notification\EventGroup;
use App\Plan\Notification\NotificationPreferences;
use App\Plan\Notification\TelegramGate;
use App\Plan\Notification\UserPrefs;
use App\Plan\Realtime\MercurePublisher;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\TaskService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * Доставка: рівно один рядок центру й одне Telegram-повідомлення на людину й
 * подію (і при повторній доставці черги), налаштування й тихі години,
 * приватність живого каналу (Mercure — лише персональні топіки й лише id).
 */
class NotificationDeliveryTest extends KernelTestCase
{
    use NotificationFixtures;

    private EntityManagerInterface $em;
    private TaskService $tasks;
    private FakeNutgram $bot;
    private int $seen = 0;
    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->bot = Nutgram::fake();
        self::getContainer()->set(Nutgram::class, $this->bot);
        self::getContainer()->set(MercurePublisher::class, new MercurePublisher(
            new MockHub('http://127.0.0.1:3100/.well-known/mercure', new StaticTokenProvider('t'), function (Update $u): string {
                $this->published[] = $u;

                return 'id';
            }),
            new NullLogger(),
            'http://127.0.0.1:3100/.well-known/mercure',
            'https://plan.test',
        ));
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

    public function testExactlyOneRowAndOneTelegramPerPersonEvenOnRedelivery(): void
    {
        $creator = $this->person(WorkRole::Manager);
        $a = $this->person();
        $b = $this->person();
        $task = $this->tasks->create($creator, ['title' => 'Плити', 'memberIds' => [$a->getId(), $b->getId()]]);
        $this->telegram($this->bot, $this->seen);

        $this->tasks->post($task, $creator, 'Перевірте розміри', mentions: [(int) $a->getId()]);
        $event = $this->lastEventId();
        $chats = array_count_values(array_map(static fn (array $m) => (string) $m['chat_id'], $this->telegram($this->bot, $this->seen)));
        ksort($chats);
        $expected = [$a->getTelegramId() => 1, $b->getTelegramId() => 1];
        ksort($expected);
        self::assertSame($expected, $chats, 'Згаданому — одне повідомлення, решті — одне');
        self::assertSame([$a->getId() => 'mention', $b->getId() => 'comment'], $this->rowsFor($event));

        // Воркер упав до ack — той самий FanOut ще раз: ні нового рядка, ні другого Telegram.
        (self::getContainer()->get(FanOutHandler::class))(new FanOut($event));
        self::assertSame([], $this->telegram($this->bot, $this->seen));
        self::assertCount(2, $this->rowsFor($event));

        $delivered = $this->em->getConnection()->fetchFirstColumn('SELECT user_id FROM notification WHERE event_id = ? AND delivered_tg_at IS NOT NULL ORDER BY user_id', [$event]);
        self::assertSame([$a->getId(), $b->getId()], array_map('intval', $delivered), 'Доставку в Telegram позначено в рядку центру');
    }

    /** ★ лишилась, а з учасників прибрали — ні Telegram, ні центру (задачу вже не видно). */
    public function testFormerMemberWithStarGetsNoTelegram(): void
    {
        $creator = $this->person(WorkRole::Manager);
        $member = $this->person();
        $former = $this->person();
        $task = $this->tasks->create($creator, ['title' => 'Секретна', 'memberIds' => [$member->getId(), $former->getId()]]);
        $this->tasks->favorite($task, $former, true);
        $this->tasks->update($task, $creator, ['memberIds' => [$member->getId()]]);
        $this->telegram($this->bot, $this->seen);

        $this->tasks->post($task, $creator, 'Ціна для клієнта — 4800');
        $chats = array_map(static fn (array $m) => (string) $m['chat_id'], $this->telegram($this->bot, $this->seen));
        self::assertSame([$member->getTelegramId()], $chats);
        self::assertArrayNotHasKey($former->getId(), $this->rowsForLastEvent());
    }

    public function testChannelsAreSwitchedIndependently(): void
    {
        $creator = $this->person(WorkRole::Manager);
        $quietTg = $this->person();
        $noApp = $this->person();
        $muted = $this->person();
        $prefs = self::getContainer()->get(NotificationPreferences::class);
        $prefs->save($quietTg, ['groups' => ['comment' => ['telegram' => false]]], false);
        $prefs->save($noApp, ['groups' => ['comment' => ['app' => false]]], false);
        $prefs->setTelegram($muted, false);

        $task = $this->tasks->create($creator, ['title' => 'Опалубка', 'memberIds' => [$quietTg->getId(), $noApp->getId(), $muted->getId()]]);
        $this->telegram($this->bot, $this->seen);

        $this->tasks->post($task, $creator, 'Щити на об\'єкті');
        $chats = array_map(static fn (array $m) => (string) $m['chat_id'], $this->telegram($this->bot, $this->seen));
        self::assertSame([$noApp->getTelegramId()], $chats, 'Telegram: вимкнена група й /mute — без повідомлення; вимкнений центр Telegram не чіпає');
        self::assertSame([$quietTg->getId() => 'comment', $muted->getId() => 'comment'], $this->rowsForLastEvent(), 'Центр: вимкнено лише в того, хто вимкнув «У Плані»');
    }

    public function testQuietHoursMakeTelegramSilentAndOvernightRangeWorks(): void
    {
        $user = $this->person();
        $gate = self::getContainer()->get(TelegramGate::class);
        self::getContainer()->get(NotificationPreferences::class)->save($user, ['quietFrom' => '22:00', 'quietTo' => '07:30'], false);
        $kyiv = new DateTimeZone('Europe/Kyiv');

        self::assertTrue($gate->decide($user, EventGroup::Comment, new DateTimeImmutable('2026-11-15 23:10', $kyiv)), 'Уночі — тихо');
        self::assertTrue($gate->decide($user, EventGroup::Comment, new DateTimeImmutable('2026-11-15 07:29', $kyiv)));
        self::assertFalse($gate->decide($user, EventGroup::Comment, new DateTimeImmutable('2026-11-15 07:30', $kyiv)), 'Тихі години скінчились');
        self::assertFalse($gate->decide($user, EventGroup::Comment, new DateTimeImmutable('2026-11-15 13:00', $kyiv)));

        $day = new UserPrefs(quietFrom: 13 * 60, quietTo: 14 * 60);
        self::assertTrue($day->quietAt(new DateTimeImmutable('2026-11-15 13:30', $kyiv)));
        self::assertFalse($day->quietAt(new DateTimeImmutable('2026-11-15 14:00', $kyiv)));
        self::assertFalse((new UserPrefs())->quietAt(new DateTimeImmutable('2026-11-15 03:00', $kyiv)), 'Без налаштування — ніколи');
        self::assertSame(['quietFrom' => '22:00', 'quietTo' => '07:30'], array_intersect_key(self::getContainer()->get(NotificationPreferences::class)->of((int) $user->getId())->toArray(), ['quietFrom' => 1, 'quietTo' => 1]));
    }

    public function testQuietHoursNowSendSilently(): void
    {
        $creator = $this->person(WorkRole::Manager);
        $sleepy = $this->person();
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv'));
        $from = (int) $now->format('G') * 60 + (int) $now->format('i');
        // Тихі години «зараз ± година» — незалежно від того, коли запущено тест.
        self::getContainer()->get(NotificationPreferences::class)->save($sleepy, ['quietFrom' => UserPrefs::hhmm(($from + 1380) % 1440), 'quietTo' => UserPrefs::hhmm(($from + 60) % 1440)], false);

        $task = $this->tasks->create($creator, ['title' => 'Нічна', 'memberIds' => [$sleepy->getId()]]);
        $sent = $this->telegram($this->bot, $this->seen);
        self::assertCount(1, $sent);
        self::assertTrue($sent[0]['disable_notification'] ?? false, 'У тихі години — повідомлення без звуку');
        self::assertSame('assigned', $this->rowsForLastEvent()[$sleepy->getId()] ?? null, 'У центрі — як завжди');
        self::assertNotNull($task->getId());
    }

    public function testMercureGetsOnlyPersonalPrivateTopicsAndNoContent(): void
    {
        $creator = $this->person(WorkRole::Manager);
        $a = $this->person();
        $b = $this->person();
        $task = $this->tasks->create($creator, ['title' => 'Секретна назва ЖК Сонячний', 'memberIds' => [$a->getId(), $b->getId()]]);
        $this->published = [];

        $this->tasks->post($task, $creator, 'Пароль від воріт 4242', mentions: [(int) $a->getId()]);
        $event = $this->lastEventId();
        $published = $this->updates();

        self::assertCount(2, $published, 'Одне оновлення на одержувача');
        $topics = [];

        foreach ($published as $update) {
            self::assertTrue($update->isPrivate(), 'Лише приватні оновлення');
            self::assertCount(1, $update->getTopics(), 'Один персональний топік');
            $topics[] = $update->getTopics()[0];
            $data = json_decode($update->getData(), true);
            self::assertSame(['event', 'type', 'subject', 'unread', 'important'], array_keys($data));
            self::assertSame($event, $data['event']);
            self::assertSame(['type' => 'task', 'id' => $task->getId()], $data['subject']);
            self::assertStringNotContainsString('Пароль', $update->getData());
            self::assertStringNotContainsString('Сонячний', $update->getData());
            self::assertStringNotContainsString((string) $creator->getFirstName(), $update->getData());
        }

        sort($topics);
        self::assertSame(['https://plan.test/u/' . $a->getId(), 'https://plan.test/u/' . $b->getId()], $topics);
        $byTopic = [];

        foreach ($published as $update) {
            $byTopic[$update->getTopics()[0]] = json_decode($update->getData(), true)['important'];
        }

        self::assertTrue($byTopic['https://plan.test/u/' . $a->getId()], 'Згадка — важливе (жовтий тост)');
        self::assertFalse($byTopic['https://plan.test/u/' . $b->getId()]);
    }

    /**
     * @phpstan-impure
     *
     * @return list<Update>
     */
    private function updates(): array
    {
        return $this->published;
    }

    public function testScopeAllIsOnlyForThoseWhoSeeEverything(): void
    {
        $worker = $this->person();
        $director = $this->person(WorkRole::Director);
        $prefs = self::getContainer()->get(NotificationPreferences::class);

        self::assertSame(NotificationSetting::SCOPE_MINE, $prefs->save($worker, ['scope' => 'all'], false)->scope);
        self::assertSame(NotificationSetting::SCOPE_ALL, $prefs->save($director, ['scope' => 'all'], true)->scope);
    }
}
