<?php

namespace App\Tests\Warehouse;

use App\Entity\CrmLogin;
use App\Service\CrmLoginLink;
use App\Service\ShopLink;
use App\Supply\Enum\SupplyRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * «🛒 Магазин»: CRM — єдиний вхід, в адмінку сайту веде підписаний пропуск.
 * Сайт перевіряє той самий підпис (CrmSsoAuthenticator у buddet_v7) — формат
 * тут і там мусить збігатися байт у байт.
 */
class ShopLinkTest extends WebTestCase
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

    public function testPassIsSignedShortLivedAndCarriesTheTelegramAccount(): void
    {
        $user = $this->person();
        $url = (new ShopLink('https://shop.test/', 'secret'))->url($user, '/admin/products', 1_000_000);

        self::assertStringStartsWith('https://shop.test/admin/sso?t=', $url);

        [$payload, $signature] = explode('.', substr($url, strlen('https://shop.test/admin/sso?t=')));
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, 'secret', true)), '+/', '-_'), '=');
        self::assertSame($expected, $signature);

        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        self::assertSame($user->getTelegramId(), $data['tg']);
        self::assertSame(1_000_060, $data['exp']);
        self::assertSame('/admin/products', $data['to']);
        self::assertNotEmpty($data['nonce']);
    }

    public function testTargetStaysInsideShopAdmin(): void
    {
        self::assertSame('/admin/products', ShopLink::safeTarget('/admin/products'));
        self::assertSame('/admin/orders', ShopLink::safeTarget('https://evil.example'));
        self::assertSame('/admin/orders', ShopLink::safeTarget('/api/v1/user'));
        self::assertSame('/admin/orders', ShopLink::safeTarget(null));
    }

    public function testManagerIsSentToShopAndTheJumpIsJournaled(): void
    {
        $manager = $this->person();
        $this->login($manager);

        $this->browser->request('GET', '/api/me');
        self::assertTrue(json_decode((string) $this->browser->getResponse()->getContent(), true)['shop']);

        $this->browser->request('GET', '/shop?to=/admin/products');
        self::assertResponseRedirects();
        self::assertStringStartsWith('https://shop.test/admin/sso?t=', (string) $this->browser->getResponse()->headers->get('Location'));

        $last = $this->em->getRepository(CrmLogin::class)->findOneBy([], ['id' => 'DESC']);
        self::assertSame('🛒 /admin/products', $last?->getTarget());
    }

    public function testWorkerHasNoShop(): void
    {
        $this->login($this->person(SupplyRole::Worker));

        $this->browser->request('GET', '/shop');
        self::assertResponseStatusCodeSame(403);
    }

    private function login($user): void
    {
        $url = self::getContainer()->get(CrmLoginLink::class)->issue($user);
        $this->browser->request('GET', (string) parse_url($url, PHP_URL_PATH));
    }
}
