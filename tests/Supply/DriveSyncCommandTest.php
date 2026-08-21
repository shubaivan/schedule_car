<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Entity\SupplyAttachment;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\Unit;
use App\Supply\Service\AttachFile;
use App\Supply\Service\CreateRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Крон копіювання в Google Drive вішається ЗАЗДАЛЕГІДЬ — ще до того, як у
 * клієнта зʼявиться Диск. Інакше про нього забувають рівно тоді, коли токен
 * нарешті прийшов.
 *
 * Ціна такого «крона наперед» — шум у лозі. Тому команда без Диска мовчить,
 * поки мовчати безпечно, і починає говорити, щойно зʼявився документ, який
 * нікуди не поїхав. У тестовому оточенні GOOGLE_DRIVE_* порожні, тож перевіряємо
 * саме цю поведінку.
 */
class DriveSyncCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AttachFile $attachFile;
    private CommandTester $command;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->attachFile = self::getContainer()->get(AttachFile::class);
        $this->command = new CommandTester((new Application(self::$kernel))->find('supply:drive-sync'));
        $this->em->beginTransaction();
        // Чужі документи з інших тестів зробили б «тишу» недосяжною.
        $this->em->createQuery('DELETE FROM ' . SupplyAttachment::class)->execute();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testWithoutDriveAndWithoutDocumentsItStaysSilent(): void
    {
        $this->command->execute([]);

        self::assertSame(0, $this->command->getStatusCode());
        self::assertStringNotContainsString('не налаштований', $this->command->getDisplay());
    }

    /** А от документ без копії — це вже те, про що крон мусить сказати. */
    public function testDocumentWithoutCopyIsReported(): void
    {
        [$worker, $manager] = $this->users();
        ($this->attachFile)(
            $this->request($worker),
            $manager,
            'зміст накладної',
            'накладна.pdf',
            'application/pdf',
        );

        $this->command->execute([]);

        self::assertSame(0, $this->command->getStatusCode(), 'крон не має падати без Диска');
        self::assertStringContainsString('не налаштований', $this->command->getDisplay());
        self::assertStringContainsString('без копії: 1', $this->command->getDisplay());
    }

    private function request(TelegramUser $author): SupplyRequest
    {
        return (self::getContainer()->get(CreateRequest::class))(
            $author,
            new CreateRequestInput(item: 'Арматура', quantity: '1', unit: Unit::Ton),
        );
    }

    /** @return array{0: TelegramUser, 1: TelegramUser} */
    private function users(): array
    {
        $users = [];

        foreach ([SupplyRole::Worker, SupplyRole::Manager] as $role) {
            $user = (new TelegramUser())
                ->setTelegramId('drive-' . $role->value . '-' . uniqid())
                ->setFirstName($role->label())
                ->setSupplyRole($role);

            $this->em->persist($user);
            $users[] = $user;
        }

        $this->em->flush();

        return $users;
    }
}
