<?php

namespace App\Tests\Plan\Notification;

use App\Entity\TelegramUser;
use App\Plan\Login\PlanLoginBot;
use App\Plan\Notification\NotificationPreferences;
use App\Plan\Telegram\LinkResult;
use App\Plan\Telegram\PlanBot;
use App\Plan\Telegram\PlanBotFallback;
use App\Plan\Telegram\PlanBotMode;
use App\Plan\Telegram\TelegramLink;
use App\Service\TelegramUserService;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\TaskNotifier;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Прив'язка бота Плану з профілю: одноразовий токен → /start plink_… → людині
 * записано справжній Telegram; повтор, прострочення, чужий Telegram — відмова.
 * І сам бот: /start, /mute, /help — без меню заявок.
 */
class TelegramLinkTest extends KernelTestCase
{
    use NotificationFixtures;

    private EntityManagerInterface $em;
    private TelegramLink $link;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->set(Nutgram::class, Nutgram::fake());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->link = self::getContainer()->get(TelegramLink::class);
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

    public function testLinkFlowReplacesTheStubAndIsOneTime(): void
    {
        $user = $this->webUser();
        self::assertFalse(TaskNotifier::reachable($user), 'До прив\'язки бот цій людині не пише');

        $issued = $this->link->issue($user);
        self::assertMatchesRegularExpression('~^https://t\.me/testbot\?start=plink_[a-f0-9]{32}$~', $issued['url']);
        $token = substr($issued['url'], strpos($issued['url'], 'plink_') + 6);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM telegram_link_token WHERE token_hash = ?', [$token]), 'У базі лише хеш');

        // Перше повідомлення незнайомця — бот уже завів «порожній» рядок (RequestSubscriber).
        $stub = self::getContainer()->get(TelegramUserService::class)->initUser(['id' => '700100200', 'first_name' => 'Ivan', 'chat_id' => '700100200']);

        [$result, $linked] = $this->link->consume($token, '700100200', '700100200');
        self::assertSame(LinkResult::Linked, $result);
        self::assertSame($user->getId(), $linked?->getId());
        self::assertSame('700100200', $user->getTelegramId());
        self::assertSame('700100200', $user->getChatId());
        self::assertTrue(TaskNotifier::reachable($user));
        self::assertTrue($stub->isArchived(), 'Порожній запис бота — у кошик');
        self::assertStringStartsWith('unlinked-', (string) $stub->getTelegramId());

        self::assertSame(LinkResult::Invalid, $this->link->consume($token, '700100200', '700100200')[0], 'Токен одноразовий');

        $this->link->unlink($user);
        self::assertFalse(TelegramLink::isLinked($user));
        self::assertNull($user->getChatId());
    }

    public function testExpiredTokenAndForeignTelegramAreRefused(): void
    {
        $user = $this->webUser();
        $old = $this->link->issue($user, new DateTimeImmutable('-1 hour'));
        $token = substr($old['url'], strpos($old['url'], 'plink_') + 6);
        self::assertSame(LinkResult::Invalid, $this->link->consume($token, '700100300', '700100300')[0], '15 хвилин минуло');

        $other = $this->person(WorkRole::Manager);
        $fresh = $this->link->issue($user);
        $token = substr($fresh['url'], strpos($fresh['url'], 'plink_') + 6);
        [$result] = $this->link->consume($token, (string) $other->getTelegramId(), (string) $other->getTelegramId());
        self::assertSame(LinkResult::Taken, $result, 'Telegram живої іншої людини не забираємо');
        self::assertFalse(TelegramLink::isLinked($user));
        self::assertSame(LinkResult::Invalid, $this->link->consume('nonsense', '1', '1')[0]);
    }

    public function testPlanBotLinksMutesAndHelpsWithoutSupplyMenus(): void
    {
        $user = $this->webUser();
        $token = substr($this->link->issue($user)['url'], -32);
        $users = self::getContainer()->get(TelegramUserService::class);
        $users->initUser(['id' => '700100400', 'first_name' => 'Ivan', 'chat_id' => '700100400']);

        $bot = $this->bot($users);
        $bot->hearText('/start plink_' . $token)->reply();
        $reply = $this->lastText($bot);
        self::assertStringContainsString('Telegram підключено', $reply);
        self::assertSame('700100400', $user->getTelegramId());

        // Наступні повідомлення — уже від прив'язаної людини.
        $users->initUser(['id' => '700100400', 'chat_id' => '700100400']);
        $bot = $this->bot($users);
        $bot->hearText('/mute')->reply();
        self::assertStringContainsString('вимкнено', $this->lastText($bot));
        self::assertFalse(self::getContainer()->get(NotificationPreferences::class)->of((int) $user->getId())->telegram);

        $bot = $this->bot($users);
        $bot->hearText('/mute')->reply();
        self::assertStringContainsString('увімкнено', $this->lastText($bot));

        $bot = $this->bot($users);
        $bot->hearText('/help')->reply();
        self::assertStringContainsString('/mute', $this->lastText($bot));

        $bot = $this->bot($users);
        $bot->hearText('/start')->reply();
        $start = $this->lastText($bot);
        self::assertStringContainsString('сповіщення Плану', $start);
        self::assertStringNotContainsString('Постачання', $start, 'Меню заявок у боті Плану немає');
    }

    public function testStrangerGetsLoginInstructionsAndShareContactButton(): void
    {
        $users = self::getContainer()->get(TelegramUserService::class);
        $users->initUser(['id' => '700100500', 'first_name' => 'Хтось', 'chat_id' => '700100500']);
        $bot = $this->bot($users, 700100500);
        $bot->hearText('привіт')->reply();

        $reply = $this->lastText($bot);
        self::assertStringContainsString('Увійти через Telegram', $reply);
        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        self::assertStringContainsString('request_contact', json_encode(FakeNutgram::getActualData($request)) ?: '', 'Кнопка «Поділитися номером»');
    }

    private function bot(TelegramUserService $users, int $id = 700100400): FakeNutgram
    {
        $bot = Nutgram::fake();
        $bot->setCommonUser(User::make($id, false, 'Ivan'));
        $bot->setCommonChat(Chat::make($id, ChatType::PRIVATE));
        $bot->middleware(new PlanBot(new PlanBotMode(true), $this->link, $users, self::getContainer()->get(NotificationPreferences::class), self::getContainer()->get(PlanLoginBot::class), 'https://plan.test'));
        $bot->onCommand('start {payload}', new PlanBotFallback());
        $bot->onCommand('start', new PlanBotFallback());
        $bot->fallback(new PlanBotFallback());

        return $bot;
    }

    private function lastText(FakeNutgram $bot): string
    {
        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));

        return (string) (FakeNutgram::getActualData($request)['text'] ?? '');
    }

    private function webUser(): TelegramUser
    {
        $user = $this->person(WorkRole::Manager);
        $user->setTelegramId('staff-' . bin2hex(random_bytes(6)))->setChatId(null)->setEmail('s1-' . uniqid() . '@zavod.test');
        $this->em->flush();

        return $user;
    }
}
