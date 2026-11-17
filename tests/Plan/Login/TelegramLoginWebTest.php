<?php

namespace App\Tests\Plan\Login;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Login\JoinRequests;
use App\Plan\Login\TelegramLogin;
use App\Tasks\Enum\WorkRole;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * «Увійти через Telegram» через HTTP: сторінка /vhid, CSRF і ліміти JSON-маршрутів,
 * вхід підтвердженою спробою (і лише тією сесією, що її почала), «Це був не я»,
 * заявки на приєднання в API, пароля на «Плані» немає зовсім.
 */
class TelegramLoginWebTest extends WebTestCase
{
    private const string PASSWORD = 'correct-horse-42';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $mark = 0;
    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        foreach (['PLAN_TELEGRAM_BOT' => '1'] as $key => $value) {
            $_SERVER[$key] = $_ENV[$key] = $value;
        }

        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set(Nutgram::class, Nutgram::fake());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->mark = (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM plan_event');
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM plan_event WHERE id > ?', [$this->mark]);
        $joined = $conn->fetchFirstColumn("SELECT user_id FROM plan_join_request WHERE telegram_id LIKE '7004%' AND user_id IS NOT NULL");
        $conn->executeStatement("DELETE FROM plan_join_request WHERE telegram_id LIKE '7004%'");
        $ids = array_map('intval', [...$this->users, ...$joined]);

        if ($ids !== []) {
            $list = implode(',', $ids);
            $conn->executeStatement("DELETE FROM plan_login_nonce WHERE user_id IN ($list)");
            $conn->executeStatement("DELETE FROM crm_login WHERE user_id IN ($list)");
            $conn->executeStatement("DELETE FROM telegram_user WHERE id IN ($list)");
        }

        foreach (['PLAN_TELEGRAM_BOT'] as $key) {
            unset($_SERVER[$key], $_ENV[$key]);
        }

        parent::tearDown();
    }

    public function testLoginPageOffersTelegramFirstAndPasswordAsFallback(): void
    {
        $crawler = $this->client->request('GET', '/vhid');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#tg-go', 'Увійти через Telegram');
        self::assertNotEmpty($crawler->filter('#tg-login')->attr('data-csrf'));
        self::assertCount(0, $crawler->filter('form.pw, input[type=password]'), 'Пароля на «Плані» немає зовсім');
        self::assertCount(1, $crawler->filter('script[src^="/plan-login.js"]'));
    }

    public function testStartNeedsCsrfAndTheConfirmedAttemptLogsInOnlyThisSession(): void
    {
        $this->client->request('POST', '/vhid/telegram/start');
        self::assertResponseStatusCodeSame(403, 'Без CSRF — ні');

        $started = $this->start();
        self::assertMatchesRegularExpression('~^https://t\.me/testbot\?start=login_[a-f0-9]{32}$~', $started['url']);
        self::assertMatchesRegularExpression('/^\d{4}$/', $started['code']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $started['qr']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('pending', $this->loginState());

        // Не підтверджено — входу немає.
        $this->complete();
        self::assertResponseStatusCodeSame(401);

        $started = $this->start();
        $user = $this->staff(telegramId: '700400101');
        $this->confirm($started['url'], $user);
        self::assertSame('confirmed', $this->loginState());

        // Інший браузер (своя сесія) з тим самим nonce не увійде.
        $other = new Session(new MockArraySessionStorage());
        self::assertSame(['state' => 'none'], self::getContainer()->get(TelegramLogin::class)->status($other));
        self::assertNull(self::getContainer()->get(TelegramLogin::class)->complete($other));

        $this->complete();
        self::assertResponseRedirects('/crm');
        $this->client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        self::assertSame($user->getId(), json_decode((string) $this->client->getResponse()->getContent(), true)['id'] ?? null);

        $login = $this->em->getConnection()->fetchAssociative("SELECT payload FROM plan_event WHERE type = 'user_logged_in' AND id > ? ORDER BY id DESC LIMIT 1", [$this->mark]);
        self::assertIsArray($login);
        self::assertSame('telegram', json_decode((string) $login['payload'], true)['method']);
    }

    public function testNotMeEndsTheSessionOpenedByThatAttempt(): void
    {
        $user = $this->staff(telegramId: '700400202');
        $started = $this->start();
        $this->confirm($started['url'], $user);
        $this->complete();
        self::assertResponseRedirects('/crm');

        $attempt = (int) $this->em->getConnection()->fetchOne("SELECT id FROM plan_login_nonce WHERE user_id = ? AND status = 'used'", [$user->getId()]);
        self::getContainer()->get(TelegramLogin::class)->revoke($attempt, '700400202');

        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401, 'Сесію, відкриту відкликаною спробою, завершено');
    }

    public function testJoinRequestsApiIsForSuperadminsOnly(): void
    {
        $joins = self::getContainer()->get(JoinRequests::class);
        [, $request] = $joins->submit(['id' => '700400303', 'chatId' => '700400303', 'firstName' => 'Петро', 'lastName' => 'Новий', 'username' => null], '380931231231');
        self::assertNotNull($request);

        $manager = $this->staff(telegramId: '700400304');
        $this->client->loginUser($manager);
        $this->client->request('GET', '/api/plan/join-requests');
        self::assertResponseStatusCodeSame(403);

        $super = $this->staff(telegramId: '700400305', superadmin: true);
        $this->client->loginUser($super);
        $this->client->request('GET', '/api/plan/join-requests');
        self::assertResponseIsSuccessful();
        $list = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertGreaterThanOrEqual(1, $list['pending']);
        self::assertContains('+380 93 123 12 31', array_column($list['items'], 'phone'));

        $this->client->request('POST', '/api/plan/join-requests/' . $request->getId() . '/approve', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['role' => 'accountant', 'firstName' => 'Петро', 'lastName' => 'Новий']) ?: '');
        self::assertResponseIsSuccessful();
        $row = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('approved', $row['status']);
        self::assertSame('Бухгалтер', $row['userRole']);

        $this->client->request('POST', '/api/plan/join-requests/' . $request->getId() . '/reject', ['reason' => 'x']);
        self::assertResponseStatusCodeSame(415, 'Звичайна форма (не JSON) рішення не приймає');

        $this->client->request('POST', '/api/plan/join-requests/' . $request->getId() . '/reject', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(422, 'Двічі не вирішити');
    }

    public function testPasswordIsUnreachableEvenForSuperadmins(): void
    {
        $super = $this->staff(telegramId: '700400407', superadmin: true, password: true);
        $this->client->request('POST', '/vhid', ['login' => $super->getEmail(), 'password' => self::PASSWORD, '_csrf_token' => 'x']);
        self::assertResponseIsSuccessful('Просто сторінка входу — автентифікатор пароля вимкнено');
        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
    }

    /** @return array{url: string, code: string, qr: string} */
    private function start(): array
    {
        $this->client->request('POST', '/vhid/telegram/start', server: ['HTTP_X_CSRF_TOKEN' => $this->csrf('tg_login')]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function loginState(): string
    {
        $this->client->request('GET', '/vhid/telegram/status');

        return (string) json_decode((string) $this->client->getResponse()->getContent(), true)['state'];
    }

    private function complete(): void
    {
        $this->client->request('POST', '/vhid/telegram/complete', ['_csrf_token' => $this->csrf('tg_login')]);
    }

    /** Те, що робить бот: забрати спробу на Telegram людини й підтвердити. */
    private function confirm(string $url, TelegramUser $user): void
    {
        $login = self::getContainer()->get(TelegramLogin::class);
        $attempt = $login->claim(substr($url, strpos($url, 'login_') + 6), (string) $user->getTelegramId());
        self::assertNotNull($attempt);
        self::assertTrue($login->confirm($attempt, $user));
    }

    /** CSRF-токен із самої сторінки входу (сесійний, як у браузера). */
    private function csrf(string $id): string
    {
        $crawler = $this->client->request('GET', '/vhid');

        return (string) $crawler->filter('#tg-login')->attr('data-csrf');
    }

    private function staff(string $telegramId, bool $superadmin = false, bool $password = false): TelegramUser
    {
        $user = new TelegramUser()->setPlanFullAccess(true)
            ->setTelegramId($telegramId)
            ->setChatId($telegramId)
            ->setFirstName('Тест-ВхідWeb')
            ->setEmail('tgw-' . $telegramId . '@zavod.test')
            ->setWorkRole(WorkRole::Manager)
            ->setSuperadmin($superadmin);
        $user->decideAccess(AccessStatus::Approved, null);

        if ($password) {
            $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        }

        $this->em->persist($user);
        $this->em->flush();
        $this->users[] = (int) $user->getId();

        return $user;
    }
}
