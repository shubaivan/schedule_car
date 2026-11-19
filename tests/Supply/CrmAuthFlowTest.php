<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Service\CrmLoginLink;
use App\Supply\Enum\SupplyRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Наскрізна перевірка входу в CRM: файрвол, автентифікатор і права ролей
 * ламаються найтихіше, тому перевіряємо їх справжніми запитами.
 */
class CrmAuthFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(TelegramUser::class)->findBy(['first_name' => 'Тест-CRM']) as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();

        parent::tearDown();
    }

    public function testManagerLogsInByLinkAndTokenBurns(): void
    {
        $token = $this->tokenFor($this->user(SupplyRole::Manager));

        $this->client->request('GET', '/crm/auth/' . $token);
        self::assertResponseRedirects();

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        // Віддається оболонка SPA; ім'я користувача застосунок бере з /api/me.
        self::assertStringContainsString('id="app"', (string) $this->client->getResponse()->getContent());

        // Сесія жива — CRM відкривається без нового посилання.
        $this->client->request('GET', '/crm/');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        self::assertSame(
            'Тест-CRM',
            json_decode((string) $this->client->getResponse()->getContent(), true)['name'],
        );

        // А саме посилання вже згоріло.
        $this->client->request('GET', '/crm/auth/' . $token);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Краулер превʼю Telegram відкриває посилання раніше за людину —
     * і не має спалювати одноразовий токен.
     */
    public function testTelegramPreviewCrawlerDoesNotBurnTheToken(): void
    {
        $token = $this->tokenFor($this->user(SupplyRole::Manager));

        $this->client->request(
            'GET',
            '/crm/auth/' . $token,
            server: ['HTTP_USER_AGENT' => 'TelegramBot (like TwitterBot)'],
        );
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // Той самий токен досі робочий для людини.
        $this->client->request('GET', '/crm/auth/' . $token, server: ['HTTP_USER_AGENT' => 'Mozilla/5.0']);
        self::assertResponseRedirects();
    }

    /**
     * Робітник у CRM заходить нарівні з менеджером — заявки навмисно спільні.
     * Різницю робить не вхід, а те, що всередині доступне (див. SupplyApiTest).
     */
    public function testWorkerEntersCrmToSeeTheCommonPicture(): void
    {
        $token = $this->tokenFor($this->user(SupplyRole::Worker));

        $this->client->request('GET', '/crm/auth/' . $token);
        self::assertResponseRedirects();

        $this->client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        self::assertSame('worker', json_decode((string) $this->client->getResponse()->getContent(), true)['role']);
    }

    /**
     * Стара вкладка після деплою просить лінивий файл попередньої збірки. Його вже немає —
     * відповідь має бути 404, а не HTML-оболонка з 200 (інакше розділ мовчки не відкривається).
     * Звичайні адреси сторінок SPA, зокрема з крапкою в id, і далі отримують оболонку.
     */
    public function testMissingBuildFileIs404NotTheShell(): void
    {
        $this->client->request('GET', '/crm/auth/' . $this->tokenFor($this->user(SupplyRole::Manager)));
        self::assertResponseRedirects();

        foreach (['/crm/assets/ProfilePage-OLDHASH1.js', '/crm/assets/ProfilePage-OLDHASH1.css', '/crm/assets/nested/x.png', '/crm/old-entry.js'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $url);
        }

        foreach (['/crm/', '/crm/profile', '/crm/tasks/42', '/crm/staff/1.5'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }

    /** Без сесії людину відправляємо на сторінку входу з інструкцією, а не на сторінку помилки. */
    public function testCrmIsClosedWithoutLogin(): void
    {
        $this->client->request('GET', '/crm/');

        self::assertResponseRedirects('/vhid');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Поділіться номером', (string) $this->client->getResponse()->getContent());
    }

    /** А от API мусить відповідати саме 401 — на цей код реагує Vue-застосунок. */
    public function testApiAnswers401WithoutLogin(): void
    {
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertJson((string) $this->client->getResponse()->getContent());
    }

    private function tokenFor(TelegramUser $user): string
    {
        $url = self::getContainer()->get(CrmLoginLink::class)->issue($user);

        return substr($url, strrpos($url, '/') + 1);
    }

    private function user(SupplyRole $role): TelegramUser
    {
        $user = new TelegramUser()->setPlanFullAccess(true)
            ->setTelegramId('crm-' . uniqid())
            ->setFirstName('Тест-CRM')
            ->setSupplyRole($role);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
