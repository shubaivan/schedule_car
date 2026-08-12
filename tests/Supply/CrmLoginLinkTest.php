<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Repository\LoginTokenRepository;
use App\Service\CrmLoginLink;
use App\Supply\Enum\SupplyRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Вхід у CRM за одноразовим посиланням із бота. */
class CrmLoginLinkTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CrmLoginLink $loginLink;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->loginLink = self::getContainer()->get(CrmLoginLink::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testLinkLogsInOnceAndThenBurns(): void
    {
        $manager = $this->manager();

        $url = $this->loginLink->issue($manager);
        self::assertMatchesRegularExpression('~/crm/auth/[a-f0-9]{64}$~', $url);

        $token = substr($url, strrpos($url, '/') + 1);

        self::assertSame($manager->getId(), $this->loginLink->consume($token)?->getId());
        self::assertNull($this->loginLink->consume($token), 'повторний перехід за посиланням має провалитись');
    }

    public function testUnknownTokenIsRejected(): void
    {
        self::assertNull($this->loginLink->consume(str_repeat('a', 64)));
    }

    public function testRawTokenIsNotStored(): void
    {
        $manager = $this->manager();
        $url = $this->loginLink->issue($manager);
        $token = substr($url, strrpos($url, '/') + 1);

        $stored = self::getContainer()->get(LoginTokenRepository::class)->findByHash(hash('sha256', $token));

        self::assertNotNull($stored);
        self::assertNotSame($token, $stored->getTokenHash(), 'у базі має лежати лише hash');
    }

    private function manager(): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId('mgr-' . uniqid())
            ->setFirstName('Менеджер')
            ->setSupplyRole(SupplyRole::Manager);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
