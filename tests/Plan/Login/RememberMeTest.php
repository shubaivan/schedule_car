<?php

namespace App\Tests\Plan\Login;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Login\TelegramLogin;
use App\Tasks\Enum\WorkRole;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Вхід на 30 днів (Олександр 17.11: «постійно викидає» — сесія PHP згасала за ~24 хв).
 * Кука REMEMBERME (HttpOnly, SameSite=Lax, Secure на https) відновлює вхід без сесії;
 * «Вийти» її стирає; новий пароль, «Це був не я», кошик — і кука недійсна.
 */
class RememberMeTest extends WebTestCase
{
    private const string PASSWORD = 'Beton-2026-pk';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;
    /** @var list<int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $_SERVER['PLAN_TELEGRAM_BOT'] = $_ENV['PLAN_TELEGRAM_BOT'] = '1';
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set(Nutgram::class, Nutgram::fake());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        self::getContainer()->get('cache.plan_login_limiter')->clear();
    }

    protected function tearDown(): void
    {
        if ($this->ids !== []) {
            $list = implode(',', $this->ids);
            $this->db->executeStatement("DELETE FROM plan_event WHERE subject_type = 'user' AND subject_id IN ($list)");
            $this->db->executeStatement("DELETE FROM plan_login_nonce WHERE user_id IN ($list)");
            $this->db->executeStatement("DELETE FROM crm_login WHERE user_id IN ($list)");
            $this->db->executeStatement("DELETE FROM telegram_user WHERE id IN ($list)");
        }

        unset($_SERVER['PLAN_TELEGRAM_BOT'], $_ENV['PLAN_TELEGRAM_BOT']);
        parent::tearDown();
    }

    public function testPasswordLoginIsRememberedForThirtyDaysWithSafeCookie(): void
    {
        $user = $this->person();
        $cookie = $this->loginByPassword($user);

        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure(), 'на https — лише Secure');
        self::assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $days = ((int) $cookie->getExpiresTime() - time()) / 86400;
        self::assertGreaterThan(29, $days);
        self::assertLessThan(31, $days);

        // Сесія згасла (новий браузерний профіль із самою кукою) — вхід відновлюється.
        self::assertSame($user->getId(), $this->meWith($cookie));
    }

    public function testTelegramLoginIsRememberedToo(): void
    {
        $user = $this->person();
        $crawler = $this->client->request('GET', '/vhid');
        $csrf = (string) $crawler->filter('#tg-login')->attr('data-csrf');
        $this->client->request('POST', '/vhid/telegram/start', server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
        $url = (string) json_decode((string) $this->client->getResponse()->getContent(), true)['url'];
        $login = self::getContainer()->get(TelegramLogin::class);
        $attempt = $login->claim(substr($url, strpos($url, 'login_') + 6), (string) $user->getTelegramId());
        self::assertNotNull($attempt);
        self::assertTrue($login->confirm($attempt, $user));
        $this->client->request('POST', '/vhid/telegram/complete', ['_csrf_token' => $csrf]);
        self::assertResponseRedirects();

        $cookie = $this->client->getCookieJar()->get('REMEMBERME', '/', 'localhost');
        self::assertNotNull($cookie, 'вхід через Telegram теж запам\'ятовано');
        self::assertSame($user->getId(), $this->meWith($cookie));

        // «Це був не я» на цьому вході — кука на всіх пристроях більше не діє.
        self::assertNotNull($login->revoke((int) $attempt->getId(), (string) $user->getTelegramId()));
        self::assertNull($this->meWith($cookie));
    }

    public function testLogoutNewPasswordAndArchiveEndTheRememberedLogin(): void
    {
        $user = $this->person();

        // «Вийти» стирає куку.
        $this->loginByPassword($user);
        $this->client->request('GET', '/crm/logout');
        $gone = $this->client->getResponse()->headers->getCookies();
        self::assertNotEmpty(array_filter($gone, static fn ($c) => $c->getName() === 'REMEMBERME' && $c->isCleared()), 'кука стерта');

        // Новий пароль — стара кука недійсна.
        $cookie = $this->loginByPassword($user);
        $fresh = $this->em->find(TelegramUser::class, $user->getId());
        $fresh->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($fresh, 'Other-pass-2026'));
        $this->em->flush();
        self::assertNull($this->meWith($cookie), 'після зміни пароля — вхід заново');

        // Кошик — теж.
        $fresh = $this->em->find(TelegramUser::class, $user->getId());
        $fresh->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($fresh, self::PASSWORD));
        $this->em->flush();
        $cookie = $this->loginByPassword($fresh);
        $fresh = $this->em->find(TelegramUser::class, $user->getId());
        $fresh->archive(null);
        $this->em->flush();
        self::assertNull($this->meWith($cookie));
    }

    private function loginByPassword(TelegramUser $user): Cookie
    {
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/vhid');
        $this->client->request('POST', '/vhid', [
            'login' => (string) $user->getPhoneNumber(),
            'password' => self::PASSWORD,
            '_csrf_token' => (string) $crawler->filter('details.pw-alt input[name=_csrf_token]')->attr('value'),
        ]);
        self::assertResponseRedirects();
        $cookie = $this->client->getCookieJar()->get('REMEMBERME', '/', 'localhost');
        self::assertNotNull($cookie);

        return $cookie;
    }

    /** Хто я — у «новому браузері» лише з кукою запам'ятованого входу (сесії немає). */
    private function meWith(Cookie $cookie): ?int
    {
        $this->em->clear();
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($cookie);
        $this->client->request('GET', '/api/me');

        if ($this->client->getResponse()->getStatusCode() !== 200) {
            return null;
        }

        return (int) json_decode((string) $this->client->getResponse()->getContent(), true)['id'];
    }

    private function person(): TelegramUser
    {
        $tg = (string) random_int(700_600_000, 700_699_999);
        $user = new TelegramUser()
            ->setTelegramId($tg)
            ->setChatId($tg)
            ->setFirstName('Тест-Сесія')
            ->setPhoneNumber('38067' . random_int(1000000, 9999999))
            ->setWorkRole(WorkRole::Manager)
            ->setPasswordSetAt(new DateTimeImmutable());
        $user->decideAccess(AccessStatus::Approved, null);
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->ids[] = (int) $user->getId();

        return $user;
    }
}
