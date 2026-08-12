<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Service\AccessService;
use App\Supply\Enum\SupplyRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Реєстрація в боті та підтвердження доступу менеджером.
 * Телефон із SUPPLY_MANAGER_PHONES (див. .env.test) стає менеджером одразу.
 */
class AccessFlowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AccessService $access;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->access = self::getContainer()->get(AccessService::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testNewUserWaitsForApproval(): void
    {
        $user = $this->user();

        $this->access->registerPhone($user, '+380631112233');

        self::assertSame(AccessStatus::Pending, $user->getAccessStatus());
        self::assertSame(SupplyRole::Worker, $user->getSupplyRole());
        self::assertFalse($user->isApproved());
        self::assertSame('380631112233', $user->getPhoneNumber());
    }

    public function testBootstrapPhoneBecomesManagerImmediately(): void
    {
        $user = $this->user();

        $this->access->registerPhone($user, '+380670000000');

        self::assertSame(AccessStatus::Approved, $user->getAccessStatus());
        self::assertSame(SupplyRole::Manager, $user->getSupplyRole());
    }

    /** Той самий номер приходить у різних форматах — порівнюємо хвіст. */
    public function testBootstrapPhoneMatchesAnyFormat(): void
    {
        foreach (['0670000000', '380670000000', '+38 (067) 000-00-00'] as $format) {
            $user = $this->user();
            $this->access->registerPhone($user, $format);

            self::assertSame(
                SupplyRole::Manager,
                $user->getSupplyRole(),
                sprintf('формат «%s» мав збігтися зі списком менеджерів', $format),
            );
        }
    }

    public function testManagerApprovesAndRejects(): void
    {
        $manager = $this->user();
        $this->access->registerPhone($manager, '+380670000000');

        $approved = $this->user();
        $this->access->registerPhone($approved, '+380631112233');
        $this->access->decide($approved, AccessStatus::Approved, $manager);

        self::assertTrue($approved->isApproved());
        self::assertSame($manager->getId(), $approved->getAccessDecidedBy()?->getId());
        self::assertNotNull($approved->getAccessDecidedAt());

        $rejected = $this->user();
        $this->access->registerPhone($rejected, '+380639998877');
        $this->access->decide($rejected, AccessStatus::Rejected, $manager);

        self::assertTrue($rejected->isRejected());
    }

    /** Відхилений може спробувати ще раз — і знову потрапляє на розгляд. */
    public function testRejectedUserCanRegisterAgain(): void
    {
        $manager = $this->user();
        $this->access->registerPhone($manager, '+380670000000');

        $user = $this->user();
        $this->access->registerPhone($user, '+380631112233');
        $this->access->decide($user, AccessStatus::Rejected, $manager);

        $this->access->registerPhone($user, '+380631112233');

        self::assertSame(AccessStatus::Pending, $user->getAccessStatus());
    }

    private function user(): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId('acc-' . uniqid())
            ->setFirstName('Новачок');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
