<?php

namespace App\Tests\Plan\Login;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Login\PasswordSetup;
use App\Tasks\Enum\WorkRole;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Пароль для входу в «План» (17.11.2026): реєстрація — Telegram, вхід — Telegram або
 * телефон + пароль. Пароль ставить лише сама людина за одноразовим посиланням, яке бот
 * надсилає в її Telegram: з бота (/parol), з Профілю, зі сторінки «Забули пароль?».
 */
class PasswordSetupTest extends WebTestCase
{
    private const string GOOD = 'Beton-2026-pk';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;
    private FakeNutgram $bot;
    /** @var list<int> */
    private array $ids = [];
    private int $mark = 0;

    protected function setUp(): void
    {
        $_SERVER['PLAN_TELEGRAM_BOT'] = $_ENV['PLAN_TELEGRAM_BOT'] = '1';
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->bot = Nutgram::fake();
        self::getContainer()->set(Nutgram::class, $this->bot);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->mark = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM plan_event');
        // Ліміти «Забули пароль?» живуть у кеші між тестами — чистий старт.
        self::getContainer()->get('cache.plan_login_limiter')->clear();
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('DELETE FROM plan_event WHERE id > ?', [$this->mark]);

        if ($this->ids !== []) {
            $list = implode(',', $this->ids);
            $this->db->executeStatement("DELETE FROM plan_password_token WHERE user_id IN ($list)");
            $this->db->executeStatement("DELETE FROM telegram_user WHERE id IN ($list)");
        }

        unset($_SERVER['PLAN_TELEGRAM_BOT'], $_ENV['PLAN_TELEGRAM_BOT']);
        parent::tearDown();
    }

    public function testForgotPasswordSendsLinkOnlyToTelegramAndAnswersTheSameForUnknownPhone(): void
    {
        $user = $this->person();

        $this->forgot('+38 ' . substr((string) $user->getPhoneNumber(), 2));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.note.info', 'бот Плану вже надіслав');
        $sent = $this->lastBotText();
        self::assertStringContainsString('/vhid/parol/', $sent);
        self::assertSame(1, $this->tokens($user));

        $before = $this->sentCount();
        $this->forgot('0990000001');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.note.info', 'бот Плану вже надіслав', 'та сама відповідь — не підказуємо, чи є номер');
        self::assertSame($before, $this->sentCount(), 'невідомому номеру нічого не шлемо');

        // Без CSRF — ні.
        $this->client->request('POST', '/vhid/parol', ['login' => (string) $user->getPhoneNumber(), '_csrf_token' => 'x']);
        self::assertSelectorTextContains('.note.error', 'Сторінка застаріла');
    }

    public function testForgotPasswordIsRateLimitedPerPersonSilently(): void
    {
        $user = $this->person();

        for ($i = 0; $i < 5; ++$i) {
            $this->forgot((string) $user->getPhoneNumber());
            self::assertResponseIsSuccessful();
        }

        self::assertSame(3, substr_count(implode("\n", $this->allBotTexts()), '/vhid/parol/'), 'на людину — 3 посилання на годину');
    }

    public function testLinkSetsPasswordOnceThenPhoneAndPasswordLogIn(): void
    {
        $user = $this->person();
        $url = $this->linkFor($user);
        $path = (string) parse_url($url, PHP_URL_PATH);

        // GET (прев'ю Telegram) нічого не витрачає.
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        foreach (['коротко1' => 'щонайменше', 'onlyletterslong' => 'літери, і цифри', 'abc' . substr((string) $user->getPhoneNumber(), -9) => 'номер телефону'] as $bad => $why) {
            $this->setPassword($path, $bad, $bad);
            self::assertResponseStatusCodeSame(422, $bad);
            self::assertSelectorTextContains('.note.error', $why);
        }

        $this->setPassword($path, self::GOOD, self::GOOD . 'x');
        self::assertSelectorTextContains('.note.error', 'не збігаються');

        $this->setPassword($path, self::GOOD, self::GOOD);
        self::assertResponseRedirects('/vhid?parol=1');
        $this->em->clear();
        $fresh = $this->em->find(TelegramUser::class, $user->getId());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($fresh, self::GOOD));
        self::assertStringContainsString('Пароль для входу в План встановлено', $this->lastBotText(), 'людина дізнається в Telegram');
        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM plan_event WHERE id > ? AND type = 'staff_changed' AND subject_id = ? AND payload::text LIKE '%password%' AND payload::text NOT LIKE ?",
            [$this->mark, $user->getId(), '%' . self::GOOD . '%'],
        ), 'у журналі — факт зміни, без значення');

        // Посилання одноразове.
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(410);
        $this->setPassword($path, 'Other-pass-99', 'Other-pass-99');
        self::assertResponseStatusCodeSame(410);

        // Вхід за телефоном у будь-якому записі.
        $crawler = $this->client->request('GET', '/vhid?parol=1');
        self::assertSelectorTextContains('.note.info', 'Пароль збережено');
        self::assertSame('0' . substr((string) $user->getPhoneNumber(), 3, 2), substr((string) $crawler->filter('details.pw-alt input[name=login]')->attr('value'), 0, 3), 'номер уже у формі');
        $this->client->request('POST', '/vhid', [
            'login' => '(0' . substr((string) $user->getPhoneNumber(), 3, 2) . ') ' . substr((string) $user->getPhoneNumber(), 5),
            'password' => self::GOOD,
            '_csrf_token' => (string) $crawler->filter('details.pw-alt input[name=_csrf_token]')->attr('value'),
        ]);
        self::assertResponseRedirects();
        $this->client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($user->getId(), $me['id']);
        self::assertTrue($me['hasPassword']);
    }

    public function testNewLinkCancelsTheOldOneAndArchivedTwinDoesNotShadowTheLiveCard(): void
    {
        $user = $this->person();
        $first = (string) parse_url($this->linkFor($user), PHP_URL_PATH);
        $second = (string) parse_url($this->linkFor($user), PHP_URL_PATH);
        $this->client->request('GET', $first);
        self::assertResponseStatusCodeSame(410);
        $this->client->request('GET', $second);
        self::assertResponseIsSuccessful();

        // Архівна заглушка з тим самим номером і меншим id (як після прив'язки Telegram).
        $stub = $this->person(phone: (string) $user->getPhoneNumber(), telegram: false);
        $stub->archive(null);
        $this->em->flush();
        self::assertSame($user->getId(), self::getContainer()->get(PasswordSetup::class)->findLive('phone:' . substr((string) $user->getPhoneNumber(), -9))?->getId());
    }

    public function testWrongPasswordsAreThrottledAndUnknownLoginLooksTheSame(): void
    {
        $user = $this->person();
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::GOOD));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/vhid');
        $csrf = (string) $crawler->filter('details.pw-alt input[name=_csrf_token]')->attr('value');

        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('POST', '/vhid', ['login' => (string) $user->getPhoneNumber(), 'password' => 'wrong-' . $i, '_csrf_token' => $csrf]);
            self::assertResponseStatusCodeSame(401);
            self::assertSelectorTextContains('.note.error', 'Невірний логін або пароль');
        }

        // Шоста спроба — навіть із правильним паролем — пауза.
        $this->client->request('POST', '/vhid', ['login' => (string) $user->getPhoneNumber(), 'password' => self::GOOD, '_csrf_token' => $csrf]);
        self::assertResponseStatusCodeSame(401);
        self::assertSelectorTextContains('.note.error', 'Забагато спроб');

        $this->client->request('POST', '/vhid', ['login' => '0990000002', 'password' => 'whatever-1', '_csrf_token' => $csrf]);
        self::assertSelectorTextContains('.note.error', 'Невірний логін або пароль');

        // Людина без пароля паролем не входить (лише Telegram).
        $nopass = $this->person();
        $this->client->request('POST', '/vhid', ['login' => (string) $nopass->getPhoneNumber(), 'password' => '', '_csrf_token' => $csrf]);
        self::assertResponseStatusCodeSame(401);
    }

    /** Старі паролі (адмін, консоль, демо) на «Плані» не діють; вхід паролем — повідомлення в Telegram. */
    public function testOnlySelfSetPasswordsWorkAndEveryPasswordLoginIsReported(): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $legacy = $this->person();
        $legacy->setPassword($hasher->hashPassword($legacy, self::GOOD));
        $noTelegram = $this->person(telegram: false);
        $noTelegram->setPassword($hasher->hashPassword($noTelegram, self::GOOD))->setPasswordSetAt(new DateTimeImmutable());
        $own = $this->person();
        $own->setPassword($hasher->hashPassword($own, self::GOOD))->setPasswordSetAt(new DateTimeImmutable());
        $this->em->flush();

        foreach ([$legacy, $noTelegram] as $user) {
            $crawler = $this->client->request('GET', '/vhid');
            $this->client->request('POST', '/vhid', ['login' => (string) $user->getPhoneNumber(), 'password' => self::GOOD, '_csrf_token' => (string) $crawler->filter('details.pw-alt input[name=_csrf_token]')->attr('value')]);
            self::assertResponseStatusCodeSame(401, 'старий пароль або без Telegram — ні');
        }

        $crawler = $this->client->request('GET', '/vhid');
        $this->client->request('POST', '/vhid', ['login' => (string) $own->getPhoneNumber(), 'password' => self::GOOD, '_csrf_token' => (string) $crawler->filter('details.pw-alt input[name=_csrf_token]')->attr('value')], server: ['HTTP_USER_AGENT' => 'pw-test-agent']);
        self::assertResponseRedirects();
        self::assertStringContainsString('Вхід у План за паролем', $this->lastBotText());
        self::assertStringContainsString('pw-test-agent', $this->lastBotText());
    }

    public function testProfileAndBotSendLinkOnlyToOwnTelegram(): void
    {
        $user = $this->person();
        $this->client->loginUser($user);
        $this->client->request('POST', '/api/plan/password/link', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/vhid/parol/', $this->lastBotText());

        $this->client->request('POST', '/api/plan/password/link', ['x' => 1]);
        self::assertResponseStatusCodeSame(415, 'форма з чужого сайту — ні');

        $noTelegram = $this->person(telegram: false);
        $this->client->loginUser($noTelegram);
        $this->client->request('POST', '/api/plan/password/link', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(409);
    }

    private function forgot(string $login): void
    {
        $crawler = $this->client->request('GET', '/vhid/parol');
        $this->client->request('POST', '/vhid/parol', ['login' => $login, '_csrf_token' => (string) $crawler->filter('input[name=_csrf_token]')->attr('value')]);
    }

    private function setPassword(string $path, string $password, string $repeat): void
    {
        $crawler = $this->client->request('GET', $path);
        $token = $crawler->filter('input[name=_csrf_token]')->count() > 0 ? (string) $crawler->filter('input[name=_csrf_token]')->attr('value') : 'gone';
        $this->client->request('POST', $path, ['password' => $password, 'repeat' => $repeat, '_csrf_token' => $token]);
    }

    private function linkFor(TelegramUser $user): string
    {
        return self::getContainer()->get(PasswordSetup::class)->issue($this->em->find(TelegramUser::class, $user->getId()));
    }

    private function tokens(TelegramUser $user): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_password_token WHERE user_id = ?', [$user->getId()]);
    }

    /** @return list<string> */
    private function allBotTexts(): array
    {
        $out = [];

        foreach ($this->bot->getRequestHistory() as $pair) {
            [$request] = array_values($pair);
            $data = FakeNutgram::getActualData($request);

            if (isset($data['text'])) {
                $out[] = (string) $data['text'];
            }
        }

        return $out;
    }

    private function lastBotText(): string
    {
        $all = $this->allBotTexts();

        return (string) end($all);
    }

    private function sentCount(): int
    {
        return count($this->allBotTexts());
    }

    private function person(?string $phone = null, bool $telegram = true): TelegramUser
    {
        $tg = (string) random_int(700_500_000, 700_599_999);
        $user = new TelegramUser()
            ->setTelegramId($telegram ? $tg : 'staff-' . bin2hex(random_bytes(4)))
            ->setChatId($telegram ? $tg : null)
            ->setFirstName('Тест-Пароль')
            ->setPhoneNumber($phone ?? '38067' . random_int(1000000, 9999999))
            ->setWorkRole(WorkRole::Manager);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();
        $this->ids[] = (int) $user->getId();

        return $user;
    }
}
