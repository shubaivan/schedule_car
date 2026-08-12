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

    public function testWorkerHasNoCrmAccess(): void
    {
        $token = $this->tokenFor($this->user(SupplyRole::Worker));

        $this->client->request('GET', '/crm/auth/' . $token);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** Без сесії людину відправляємо на титульну з інструкцією, а не на сторінку помилки. */
    public function testCrmIsClosedWithoutLogin(): void
    {
        $this->client->request('GET', '/crm/');

        self::assertResponseRedirects('/');

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
        $user = (new TelegramUser())
            ->setTelegramId('crm-' . uniqid())
            ->setFirstName('Тест-CRM')
            ->setSupplyRole($role);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
