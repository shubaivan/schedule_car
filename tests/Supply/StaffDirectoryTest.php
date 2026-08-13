<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Service\AccessService;
use App\Supply\Enum\SupplyRole;
use App\Supply\Exception\SupplyException;
use App\Supply\Service\StaffDirectory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Довідник телефонів: заводимо номер із роллю — людина отримує її сама.
 *
 * Так на завод заходить директор: менеджер вносить номер Наталії Григорівни,
 * і після реєстрації в боті вона одразу погоджує оплати, без правок на сервері.
 */
class StaffDirectoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private StaffDirectory $directory;
    private AccessService $access;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->directory = self::getContainer()->get(StaffDirectory::class);
        $this->access = self::getContainer()->get(AccessService::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testPhoneFromDirectoryGivesRoleOnRegistration(): void
    {
        $phone = '+380' . random_int(100000000, 999999999);
        $this->directory->save($phone, SupplyRole::Director, 'Наталія Григорівна');

        $user = $this->user();
        // Telegram віддає контакт без «+», і це має бути той самий номер.
        $this->access->registerPhone($user, ltrim($phone, '+'));

        self::assertSame(SupplyRole::Director, $user->getSupplyRole());
        self::assertTrue($user->isApproved(), 'людину з довідника не треба підтверджувати вручну');
    }

    /** Номер занесли, коли людина вже в боті — роль має приїхати одразу. */
    public function testEntryAppliesToAlreadyRegisteredUser(): void
    {
        $phone = '+380' . random_int(100000000, 999999999);

        $user = $this->user();
        $this->access->registerPhone($user, $phone);
        self::assertSame(SupplyRole::Worker, $user->getSupplyRole());

        $entry = $this->directory->save($phone, SupplyRole::Director, 'Наталія Григорівна');

        self::assertSame(SupplyRole::Director, $user->getSupplyRole());
        self::assertSame($user->getId(), $entry->getAppliedTo()?->getId());
        self::assertNotNull($entry->getAppliedAt());
    }

    /** Той самий номер у різних записах не плодиться. */
    public function testSameNumberUpdatesTheEntry(): void
    {
        $phone = '0' . random_int(100000000, 999999999);

        $first = $this->directory->save($phone, SupplyRole::Worker, 'Хтось');
        $second = $this->directory->save('+38' . $phone, SupplyRole::Manager, 'Він самий');

        self::assertSame($first->getId(), $second->getId());
        self::assertSame(SupplyRole::Manager, $second->getRole());
    }

    public function testManagerCannotHandOutAdminRole(): void
    {
        $manager = $this->user(SupplyRole::Manager);

        $this->expectException(SupplyException::class);
        $this->expectExceptionMessage('адміністратор');

        $this->directory->save('0671112233', SupplyRole::Admin, by: $manager);
    }

    public function testShortNumberIsRefused(): void
    {
        $this->expectException(SupplyException::class);
        $this->expectExceptionMessage('щонайменше 9 цифр');

        $this->directory->save('12345', SupplyRole::Worker);
    }

    private function user(SupplyRole $role = SupplyRole::Worker): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId('staff-' . uniqid())
            ->setFirstName('Тест-Довідник')
            ->setSupplyRole($role);

        if ($role !== SupplyRole::Worker) {
            $user->decideAccess(AccessStatus::Approved, null);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
