<?php

namespace App\Tests\Plan\Login;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Login\Entity\JoinRequest;
use App\Plan\Login\JoinRequests;
use App\Plan\Login\JoinRequestStatus;
use App\Plan\Login\JoinSubmit;
use App\Plan\Login\PlanLoginBot;
use App\Plan\Login\TelegramLogin;
use App\Plan\Notification\NotificationPreferences;
use App\Plan\Telegram\PlanBot;
use App\Plan\Telegram\PlanBotFallback;
use App\Plan\Telegram\PlanBotMode;
use App\Plan\Telegram\TelegramLink;
use App\Service\TelegramUserService;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Exception\TaskException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * «Увійти через Telegram» від початку до кінця, без HTTP: сайт (TelegramLogin::start /
 * status / complete) ↔ бот Плану (PlanBot → PlanLoginBot) ↔ заявки на приєднання.
 */
class TelegramLoginFlowTest extends KernelTestCase
{
    private const int TG = 700300100;

    private EntityManagerInterface $em;
    private TelegramLogin $login;
    private FakeNutgram $outbox;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->outbox = Nutgram::fake();
        self::getContainer()->set(Nutgram::class, $this->outbox);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->login = self::getContainer()->get(TelegramLogin::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testKnownTelegramConfirmsOnStartAndTheBrowserLogsInOnce(): void
    {
        $user = $this->staff('0631112201', telegramId: (string) self::TG);
        $session = self::session();
        $started = $this->login->start($session, '10.0.0.1', 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0');

        self::assertMatchesRegularExpression('~^https://t\.me/testbot\?start=login_[a-f0-9]{32}$~', $started['url']);
        self::assertSame(['state' => 'pending', 'code' => $started['code']], $this->login->status($session));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM plan_login_nonce WHERE nonce_hash = ?', [self::nonce($started['url'])]), 'У базі лише хеш');

        $bot = $this->bot();
        $bot->hearText('/start login_' . self::nonce($started['url']))->reply();
        $ask = $this->lastSent($bot);
        self::assertStringContainsString('Натисніть код', (string) $ask['text']);
        self::assertStringNotContainsString($started['code'], (string) $ask['text'], 'Код не в тексті — лише серед трьох кнопок');
        self::assertStringContainsString('Chrome, Android', (string) $ask['text']);
        self::assertStringContainsString('IP 10.0.0.1', (string) $ask['text']);
        self::assertSame(3, substr_count((string) json_encode($ask['reply_markup']), PlanLoginBot::CODE), 'Три коди на вибір');
        self::assertStringContainsString(PlanLoginBot::CODE . $this->attemptId($started['url']) . ':' . $started['code'], (string) json_encode($ask['reply_markup']));
        self::assertSame('pending', $this->login->status($session)['state'], 'Сам /start нічого не підтверджує');

        $this->pressCode($started['url']);
        self::assertSame('confirmed', $this->login->status($session)['state']);
        $done = $this->login->complete($session);
        self::assertNotNull($done);
        self::assertSame($user->getId(), $done[0]->getId());
        self::assertNull($this->login->complete($session), 'Спроба одноразова');
        self::assertSame(['state' => 'none'], $this->login->status($session));
    }

    public function testFirstLoginSharesContactLinksTheCardAndNextLoginSkipsIt(): void
    {
        $user = $this->staff('0632223302');
        $this->stranger();
        $session = self::session();
        $started = $this->login->start($session, null, null);

        $bot = $this->bot();
        $bot->hearText('/start login_' . self::nonce($started['url']))->reply();
        $ask = $this->lastSent($bot);
        self::assertStringContainsString('поділіться номером', (string) $ask['text']);
        self::assertStringContainsString('request_contact', (string) json_encode($ask['reply_markup']));
        self::assertSame('pending', $this->login->status($session)['state']);

        $bot = $this->bot();
        $bot->hearMessage(['contact' => ['phone_number' => '+380632223302', 'first_name' => 'Іван', 'user_id' => self::TG]])->reply();
        self::assertStringContainsString('Натисніть код', (string) $this->lastSent($bot)['text']);
        self::assertSame((string) self::TG, $user->getTelegramId(), 'Telegram прив\'язано до картки за номером');
        self::assertSame('staff_changed', $this->lastEventType(), 'Прив\'язка за номером — у журналі');
        $this->pressCode($started['url']);
        self::assertSame($user->getId(), $this->login->complete($session)[0]->getId() ?? null);

        // Другий вхід: бот уже знає цей Telegram — без контакту.
        $this->users()->initUser(['id' => (string) self::TG, 'chat_id' => (string) self::TG]);
        $session = self::session();
        $started = $this->login->start($session, null, null);
        $bot = $this->bot();
        $bot->hearText('/start login_' . self::nonce($started['url']))->reply();
        self::assertStringContainsString('Натисніть код', (string) $this->lastSent($bot)['text']);
        $this->pressCode($started['url']);
        self::assertSame('confirmed', $this->login->status($session)['state']);
    }

    public function testBareStartThenContactLinksAndOffersOneTapLoginFromTheBot(): void
    {
        // Як Сашко 15.11: відкрив бота сам (без сайту), натиснув /start.
        $user = $this->staff('0933504499');
        $this->stranger();
        $bot = $this->bot();
        $bot->hearText('/start')->reply();
        self::assertStringContainsString('request_contact', (string) json_encode($this->lastSent($bot)['reply_markup'] ?? null));

        $tokens = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM login_token WHERE user_id = ?', [$user->getId()]);
        $bot = $this->bot();
        $bot->hearMessage(['contact' => ['phone_number' => '380933504499', 'first_name' => 'Олександр', 'user_id' => self::TG]])->reply();
        self::assertStringContainsString('Увійти в План', (string) $this->lastSent($bot)['text']);
        self::assertSame((string) self::TG, $user->getTelegramId());
        self::assertSame($tokens + 1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM login_token WHERE user_id = ?', [$user->getId()]), 'Одноразове посилання входу видано');

        // Наступний голий /start — одразу кнопка входу.
        $this->users()->initUser(['id' => (string) self::TG, 'chat_id' => (string) self::TG]);
        $bot = $this->bot();
        $bot->hearText('/start')->reply();
        self::assertStringContainsString('Увійти в План', (string) $this->lastSent($bot)['text']);
    }

    public function testWrongCodeCancelsTheAttemptPhishingCase(): void
    {
        $this->staff('0639990011', telegramId: (string) self::TG);
        // Зловмисник почав вхід у СВОЄМУ браузері й надіслав посилання жертві.
        $attacker = self::session();
        $started = $this->login->start($attacker, '6.6.6.6', null);
        $bot = $this->bot();
        $bot->hearText('/start login_' . self::nonce($started['url']))->reply();

        $wrong = $started['code'] === '0000' ? '0001' : '0000';
        $bot = $this->bot();
        $bot->hearCallbackQueryData(PlanLoginBot::CODE . $this->attemptId($started['url']) . ':' . $wrong)->reply();
        self::assertSame('denied', $this->login->status($attacker)['state']);
        self::assertNull($this->login->complete($attacker));
        self::assertStringContainsString('невірний код', $this->lastFailure());

        // Після відмови правильний код уже нічого не дає.
        $this->pressCode($started['url']);
        self::assertSame('denied', $this->login->status($attacker)['state']);
    }

    public function testTailMatchUsesTheFullNumberAndIgnoresArchivedTwins(): void
    {
        $this->staff('48632223399');            // +48 з тим самим хвостом
        $old = $this->staff('0632223399');
        $old->archive();
        $live = $this->staff('380632223399');
        $this->em->flush();

        $found = $this->login->staffByPhone('+380 63 222 33 99');
        self::assertCount(1, $found);
        self::assertSame($live->getId(), $found[0]->getId());
        self::assertSame([], $this->login->staffByPhone('+44 63 222 33 99'));
    }

    public function testForeignContactExpiredLinkAndSecondTelegramAreRefused(): void
    {
        $this->staff('0633334403');
        $this->stranger();
        $session = self::session();
        $nonce = self::nonce($this->login->start($session, null, null)['url']);

        $bot = $this->bot();
        $bot->hearText('/start login_' . $nonce)->reply();
        $bot = $this->bot();
        $bot->hearMessage(['contact' => ['phone_number' => '380633334403', 'first_name' => 'Хтось', 'user_id' => 111]])->reply();
        self::assertStringContainsString('власний номер', (string) $this->lastSent($bot)['text']);
        self::assertSame('pending', $this->login->status($session)['state'], 'Чужий контакт нічого не підтвердив');
        self::assertSame('надіслано чужий контакт', $this->lastFailure());

        // Той самий nonce з іншого Telegram — ні.
        self::assertNull($this->login->claim($nonce, '999000111'));

        // Протермінований.
        $old = self::nonce($this->login->start(self::session(), null, null, new DateTimeImmutable('-10 minutes'))['url']);
        $bot = $this->bot();
        $bot->hearText('/start login_' . $old)->reply();
        self::assertStringContainsString('вже не діє', (string) $this->lastSent($bot)['text']);
        self::assertNull($this->login->claim('not-a-nonce', (string) self::TG));
    }

    public function testArchivedOrDuplicatedPhoneIsRefused(): void
    {
        $archived = $this->staff('0634445504');
        $archived->archive();
        $this->em->flush();
        $this->stranger();
        $session = self::session();
        $nonce = self::nonce($this->login->start($session, null, null)['url']);

        $bot = $this->bot();
        $bot->hearText('/start login_' . $nonce)->reply();
        $bot = $this->bot();
        $bot->hearMessage(['contact' => ['phone_number' => '380634445504', 'first_name' => 'А', 'user_id' => self::TG]])->reply();
        self::assertStringContainsString('закрито', (string) $this->lastSent($bot)['text']);
        self::assertSame('denied', $this->login->status($session)['state']);
        self::assertFalse(TelegramLink::isLinked($archived));
    }

    public function testUnknownPhoneSendsAJoinRequestToSuperadminsAndApprovalLetsThemIn(): void
    {
        $admin = $this->staff('0635556605', telegramId: '700300999', superadmin: true);
        $this->stranger();
        $session = self::session();
        $nonce = self::nonce($this->login->start($session, null, null)['url']);

        $bot = $this->bot();
        $bot->hearText('/start login_' . $nonce)->reply();
        $bot = $this->bot();
        $bot->hearMessage(['contact' => ['phone_number' => '380671234567', 'first_name' => 'Новенький', 'user_id' => self::TG]])->reply();
        self::assertStringContainsString('Заявку на приєднання надіслано', (string) $this->lastSent($bot)['text']);
        self::assertSame('join', $this->login->status($session)['state'], 'Браузер бачить «заявку надіслано»');

        $request = $this->em->getRepository(JoinRequest::class)->findOneBy(['telegramId' => (string) self::TG]);
        self::assertInstanceOf(JoinRequest::class, $request);
        self::assertSame('380671234567', $request->getPhone());
        self::assertSame('Тест', $request->getFirstName(), 'Ім\'я — з профілю Telegram');

        $toAdmin = $this->sentTo($this->outbox, '700300999');
        self::assertNotNull($toAdmin, 'Суперадмін отримав заявку в боті');
        self::assertStringContainsString('+380 67 123 45 67', (string) $toAdmin['text']);
        self::assertStringContainsString(PlanLoginBot::JOIN_APPROVE . $request->getId(), (string) json_encode($toAdmin['reply_markup']));
        self::assertSame('join_requested', $this->lastEventType());

        // Повторний контакт — та сама заявка, нової немає.
        $bot = $this->bot();
        $bot->hearMessage(['contact' => ['phone_number' => '380671234567', 'first_name' => 'Новенький', 'user_id' => self::TG]])->reply();
        self::assertStringContainsString('вже на розгляді', (string) $this->lastSent($bot)['text']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM plan_join_request WHERE telegram_id = ?', [(string) self::TG]));

        // Не суперадмін кнопку «Затвердити» не натисне.
        $this->staff('0636667706', telegramId: '700300888');
        $bot = $this->bot(700300888);
        $bot->hearCallbackQueryData(PlanLoginBot::JOIN_APPROVE . $request->getId())->reply();
        self::assertSame(JoinRequestStatus::Pending, $request->getStatus());

        // Суперадмін затверджує в боті → картка «Виробництво», Telegram прив'язано, людині — повідомлення.
        $bot = $this->bot(700300999);
        $bot->hearCallbackQueryData(PlanLoginBot::JOIN_APPROVE . $request->getId())->reply();
        $this->em->refresh($request);
        self::assertSame(JoinRequestStatus::Approved, $request->getStatus());
        $joined = $request->getUser();
        self::assertNotNull($joined);
        self::assertSame(WorkRole::Production, $joined->getWorkRole());
        self::assertSame((string) self::TG, $joined->getTelegramId());
        self::assertTrue($joined->isApproved());
        self::assertSame($admin->getId(), $request->getDecidedBy()?->getId());
        self::assertStringContainsString('затверджено', (string) $this->sentTo($this->outbox, (string) self::TG)['text']);
        self::assertSame('join_decided', $this->lastEventType());

        // Тепер вхід — одразу.
        $this->users()->initUser(['id' => (string) self::TG, 'chat_id' => (string) self::TG]);
        $session = self::session();
        $nonce = self::nonce($this->login->start($session, null, null)['url']);
        $bot = $this->bot();
        $bot->hearText('/start login_' . $nonce)->reply();
        $this->pressCode('login_' . $nonce);
        self::assertSame($joined->getId(), $this->login->complete($session)[0]->getId() ?? null);
    }

    public function testRejectAndRateLimit(): void
    {
        $admin = $this->staff('0637778807', telegramId: '700300777', superadmin: true);
        $joins = self::getContainer()->get(JoinRequests::class);
        $from = ['id' => '700300555', 'chatId' => '700300555', 'firstName' => 'Спам', 'lastName' => null, 'username' => 'spam'];

        [, $request] = $joins->submit($from, '380501112233');
        self::assertNotNull($request);
        $joins->reject($request, $admin, 'Не наш працівник');
        self::assertSame(JoinRequestStatus::Rejected, $request->getStatus());
        self::assertSame('Не наш працівник', $request->getReason());

        [$again] = $joins->submit($from, '380501112233');
        self::assertSame(JoinSubmit::Limited, $again, 'Після відмови — не раніше ніж за добу');

        $this->expectException(TaskException::class);
        $joins->approve($request, $admin);
    }

    public function testSysAdminDecidesJoinRequestsButCannotGrantTaskRoles(): void
    {
        $sys = $this->staff('0637778811')->setWorkRole(WorkRole::SysAdmin);
        $manager = $this->staff('0637778812');
        $this->em->flush();
        $joins = self::getContainer()->get(JoinRequests::class);

        [, $request] = $joins->submit(['id' => '700300888', 'chatId' => '700300888', 'firstName' => 'Новий', 'lastName' => 'Робітник', 'username' => null], '380501114455');
        self::assertNotNull($request);

        try {
            $joins->approve($request, $manager, ['role' => 'production']);
            self::fail('менеджер заявки не розглядає');
        } catch (AccessDeniedException) {
        }

        try {
            $joins->approve($request, $sys, ['role' => 'director']);
            self::fail('системний адмін не видає ролей, що бачать усі задачі');
        } catch (AccessDeniedException) {
        }

        $this->em->clear();
        $request = $this->em->find(JoinRequest::class, $request->getId());
        $sys = $this->em->find(TelegramUser::class, $sys->getId());
        $user = $joins->approve($request, $sys, ['role' => 'production']);
        self::assertSame(WorkRole::Production, $user->getWorkRole());
    }

    public function testSysAdminGetsTheJoinRequestInTheBotAndDecidesWithItsButtons(): void
    {
        $this->staff('0635556621', telegramId: '700300991', superadmin: true);
        $sys = $this->staff('0635556622', telegramId: '700300992')->setWorkRole(WorkRole::SysAdmin);
        $unlinkedSys = $this->staff('0635556623')->setWorkRole(WorkRole::SysAdmin);
        $archivedSys = $this->staff('0635556624', telegramId: '700300994')->setWorkRole(WorkRole::SysAdmin);
        $archivedSys->archive();
        $this->staff('0635556625', telegramId: '700300995'); // менеджер — заявок не розглядає
        $this->em->flush();
        $this->stranger();
        $session = self::session();
        $nonce = self::nonce($this->login->start($session, null, null)['url']);

        $bot = $this->bot();
        $bot->hearText('/start login_' . $nonce)->reply();
        $bot = $this->bot();
        $bot->hearMessage(['contact' => ['phone_number' => '380671239876', 'first_name' => 'Новенький', 'user_id' => self::TG]])->reply();
        $request = $this->em->getRepository(JoinRequest::class)->findOneBy(['telegramId' => (string) self::TG]);
        self::assertInstanceOf(JoinRequest::class, $request);

        foreach (['700300991' => 'суперадмін', '700300992' => 'системний адмін'] as $chat => $who) {
            $sent = $this->sentTo($this->outbox, (string) $chat);
            self::assertNotNull($sent, $who . ' отримав заявку в боті');
            self::assertStringContainsString('Заявка на приєднання до Плану №' . $request->getId(), (string) $sent['text']);
            self::assertStringContainsString(PlanLoginBot::JOIN_APPROVE . $request->getId(), (string) json_encode($sent['reply_markup']));
            self::assertStringContainsString(PlanLoginBot::JOIN_REJECT . $request->getId(), (string) json_encode($sent['reply_markup']));
        }
        self::assertSame(1, $this->countSentTo($this->outbox, '700300991'), 'Суперадміну — одне повідомлення');
        self::assertSame(1, $this->countSentTo($this->outbox, '700300992'), 'Системному адміну — одне повідомлення');
        self::assertNull($this->sentTo($this->outbox, '700300994'), 'Архівному — ні');
        self::assertNull($this->sentTo($this->outbox, '700300995'), 'Менеджеру — ні');
        self::assertNull($this->sentTo($this->outbox, (string) $unlinkedSys->getTelegramId()), 'Без Telegram — ні');

        // Системний адмін затверджує кнопкою в боті → «Виробництво»; суперадміну — хто вирішив.
        $bot = $this->bot(700300992);
        $bot->hearCallbackQueryData(PlanLoginBot::JOIN_APPROVE . $request->getId())->reply();
        $this->em->refresh($request);
        self::assertSame(JoinRequestStatus::Approved, $request->getStatus());
        self::assertSame($sys->getId(), $request->getDecidedBy()?->getId());
        self::assertSame(WorkRole::Production, $request->getUser()?->getWorkRole());
        self::assertStringContainsString('затверджено', (string) $this->sentTo($this->outbox, (string) self::TG)['text']);
        self::assertStringContainsString('ℹ️ Заявку №' . $request->getId(), (string) $this->sentTo($this->outbox, '700300991')['text']);
        self::assertSame(1, $this->countSentTo($this->outbox, '700300992'), 'Тому, хто вирішив, «ℹ️» не шлемо');
    }

    public function testSysAdminRejectsWithTheBotButton(): void
    {
        $sys = $this->staff('0635556631', telegramId: '700300981')->setWorkRole(WorkRole::SysAdmin);
        $this->em->flush();
        $joins = self::getContainer()->get(JoinRequests::class);
        [, $request] = $joins->submit(['id' => '700300982', 'chatId' => '700300982', 'firstName' => 'Чужий', 'lastName' => null, 'username' => null], '380501119988');
        self::assertNotNull($request);

        $bot = $this->bot(700300981);
        $bot->hearCallbackQueryData(PlanLoginBot::JOIN_REJECT . $request->getId())->reply();
        $this->em->refresh($request);
        self::assertSame(JoinRequestStatus::Rejected, $request->getStatus());
        self::assertSame($sys->getId(), $request->getDecidedBy()?->getId());
        self::assertStringContainsString('відхилено', (string) $this->sentTo($this->outbox, '700300982')['text']);
    }

    public function testNotMeAfterLoginRevokesThatSession(): void
    {
        $this->staff('0638889908', telegramId: (string) self::TG);
        $session = self::session();
        $nonce = self::nonce($this->login->start($session, null, null)['url']);
        $bot = $this->bot();
        $bot->hearText('/start login_' . $nonce)->reply();
        $this->pressCode('login_' . $nonce);
        [, $attempt] = $this->login->complete($session) ?? [null, 0];
        self::assertGreaterThan(0, $attempt);
        self::assertFalse($this->login->isRevoked($attempt));

        // Чужий Telegram чужу спробу не скасує.
        self::assertNull($this->login->revoke($attempt, '123'));

        $bot = $this->bot();
        $bot->hearCallbackQueryData(PlanLoginBot::NOT_ME . $attempt)->reply();
        self::assertTrue($this->login->isRevoked($attempt));
        self::assertStringContainsString('сесію завершено', $this->lastFailure());
    }

    private function bot(int $id = self::TG): FakeNutgram
    {
        $users = $this->users();
        $users->initUser(['id' => (string) $id, 'first_name' => 'Тест', 'chat_id' => (string) $id]);
        $bot = Nutgram::fake();
        $bot->setCommonUser(User::make($id, false, 'Тест'));
        $bot->setCommonChat(Chat::make($id, ChatType::PRIVATE));
        $c = self::getContainer();
        $bot->middleware(new PlanBot(new PlanBotMode(true), $c->get(TelegramLink::class), $users, $c->get(NotificationPreferences::class), $c->get(PlanLoginBot::class), 'https://plan.test'));
        $bot->onCommand('start {payload}', new PlanBotFallback());
        $bot->onCommand('start', new PlanBotFallback());
        $bot->fallback(new PlanBotFallback());

        return $bot;
    }

    private function attemptId(string $url): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT id FROM plan_login_nonce WHERE nonce_hash = ?', [hash('sha256', self::nonce($url))]);
    }

    /** Людина натискає в боті правильний код (той, що бачить на сайті). */
    private function pressCode(string $url, int $tg = self::TG): void
    {
        $code = (string) $this->em->getConnection()->fetchOne('SELECT code FROM plan_login_nonce WHERE nonce_hash = ?', [hash('sha256', self::nonce($url))]);
        $bot = $this->bot($tg);
        $bot->hearCallbackQueryData(PlanLoginBot::CODE . $this->attemptId($url) . ':' . $code)->reply();
    }

    private function users(): TelegramUserService
    {
        return self::getContainer()->get(TelegramUserService::class);
    }

    /** «Порожній» запис, який бот заводить незнайомцю на першому повідомленні. */
    private function stranger(): void
    {
        $this->users()->initUser(['id' => (string) self::TG, 'first_name' => 'Тест', 'chat_id' => (string) self::TG]);
    }

    private function staff(string $phone, ?string $telegramId = null, bool $superadmin = false): TelegramUser
    {
        $user = new TelegramUser()->setPlanFullAccess(true)
            ->setTelegramId($telegramId ?? 'staff-' . bin2hex(random_bytes(6)))
            ->setChatId($telegramId)
            ->setFirstName('Тест-Вхід')
            ->setPhoneNumber($phone)
            ->setEmail('tg-login-' . bin2hex(random_bytes(4)) . '@zavod.test')
            ->setWorkRole(WorkRole::Manager)
            ->setSuperadmin($superadmin);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private static function session(): Session
    {
        return new Session(new MockArraySessionStorage());
    }

    private static function nonce(string $url): string
    {
        return substr($url, strpos($url, TelegramLogin::PREFIX) + strlen(TelegramLogin::PREFIX));
    }

    /** @return array<string, mixed> */
    private function lastSent(FakeNutgram $bot): array
    {
        $out = [];

        foreach ($bot->getRequestHistory() as $pair) {
            [$request] = array_values($pair);
            $data = FakeNutgram::getActualData($request);

            if (isset($data['text'])) {
                $out = $data;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null останній sendMessage у цей чат */
    private function sentTo(FakeNutgram $bot, string $chatId): ?array
    {
        $found = null;

        foreach ($bot->getRequestHistory() as $pair) {
            [$request] = array_values($pair);
            $data = FakeNutgram::getActualData($request);

            if ((string) ($data['chat_id'] ?? '') === $chatId && isset($data['text'])) {
                $found = $data;
            }
        }

        return $found;
    }

    private function countSentTo(FakeNutgram $bot, string $chatId): int
    {
        $n = 0;

        foreach ($bot->getRequestHistory() as $pair) {
            [$request] = array_values($pair);
            $data = FakeNutgram::getActualData($request);
            $n += (string) ($data['chat_id'] ?? '') === $chatId && isset($data['text']) ? 1 : 0;
        }

        return $n;
    }

    private function lastEventType(): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT type FROM plan_event ORDER BY id DESC LIMIT 1');
    }

    private function lastFailure(): string
    {
        $payload = (string) $this->em->getConnection()->fetchOne("SELECT payload FROM plan_event WHERE type = 'login_failed' ORDER BY id DESC LIMIT 1");

        return (string) (json_decode($payload, true)['reason'] ?? '');
    }
}
