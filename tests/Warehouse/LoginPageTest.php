<?php

namespace App\Tests\Warehouse;

use App\Entity\CrmLogin;
use App\Service\CrmLoginLink;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Сторінка входу і журнал входів: кожна спроба — рядок, із тим, хто і звідки. */
class LoginPageTest extends WebTestCase
{
    use WarehouseFixtures;

    private KernelBrowser $browser;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
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

    public function testLoginPageExplainsWhereTheLinkComesFrom(): void
    {
        $this->browser->request('GET', '/vhid');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="https://t.me/testbot"]');
        self::assertSelectorTextContains('ol', 'Вхід у CRM');
    }

    /** Відкрив закриту картку без сесії → увійшов з бота → опинився на тій самій картці. */
    public function testAfterLoginYouLandWhereYouWereGoing(): void
    {
        $item = $this->item();

        $this->browser->request('GET', '/sklad/items/' . $item->getId());
        self::assertResponseRedirects('/vhid');

        $this->browser->request('GET', $this->path(self::getContainer()->get(CrmLoginLink::class)->issue($this->person())));
        self::assertResponseRedirects('/sklad/items/' . $item->getId());
    }

    public function testSuccessAndFailureAreBothJournaled(): void
    {
        $manager = $this->person();
        $url = $this->path(self::getContainer()->get(CrmLoginLink::class)->issue($manager, '/sklad'));

        $this->browser->request('GET', $url, server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1', 'REMOTE_ADDR' => '203.0.113.7']);
        self::assertResponseRedirects('/sklad');

        // Те саме посилання вдруге — уже використане.
        $this->browser->request('GET', $url);
        self::assertResponseStatusCodeSame(401);
        self::assertSelectorTextContains('.note.error', 'застаріло');

        /** @var CrmLogin[] $rows */
        $rows = $this->em->getRepository(CrmLogin::class)->findBy([], ['id' => 'DESC'], 2);

        self::assertFalse($rows[0]->isSuccess());
        self::assertNull($rows[0]->getUser());

        self::assertTrue($rows[1]->isSuccess());
        self::assertSame($manager->getId(), $rows[1]->getUser()?->getId());
        self::assertSame('/sklad', $rows[1]->getTarget());
        self::assertSame('203.0.113.7', $rows[1]->getIp());
        self::assertSame('iPhone · Safari', $rows[1]->getDevice());
    }

    public function testLoginsPageShowsWhoCameIn(): void
    {
        $manager = $this->person();
        $this->browser->request('GET', $this->path(self::getContainer()->get(CrmLoginLink::class)->issue($manager)));

        $this->browser->request('GET', '/sklad/logins');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', $manager->displayName());
    }

    public function testLogoutLandsOnLoginPage(): void
    {
        $this->browser->request('GET', $this->path(self::getContainer()->get(CrmLoginLink::class)->issue($this->person())));
        $this->browser->request('GET', '/crm/logout');

        self::assertResponseRedirects('/vhid?out=1');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.note.info', 'Ви вийшли');
    }

    private function path(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        return parse_url($url, PHP_URL_PATH) . ($query ? '?' . $query : '');
    }
}
