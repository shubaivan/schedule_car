<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\Unit;
use App\Supply\Service\CreateRequest;
use App\Supply\Service\RequestFormatter;
use App\Supply\Service\SupplyNotifier;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Кому йде нагадування про прострочення.
 *
 * Вимога клієнта від 31.08.2026: тільки менеджеру з постачання. До того
 * повідомлення отримували ще й заявник та адмін — у боті на шість людей це
 * виглядало як розсилка всім, і її перестали читати.
 */
class OverdueRecipientsTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testOnlyManagersAreNotified(): void
    {
        $author = $this->person(SupplyRole::Worker);
        $manager = $this->person(SupplyRole::Manager);
        $director = $this->person(SupplyRole::Director);
        $admin = $this->person(SupplyRole::Admin);
        $archived = $this->person(SupplyRole::Manager);
        $archived->archive();
        $this->em->flush();

        $bot = Nutgram::fake();
        $notifier = new SupplyNotifier(
            $bot,
            self::getContainer()->get(TelegramUserRepository::class),
            self::getContainer()->get(RequestFormatter::class),
            new NullLogger(),
        );

        $notifier->overdue($this->request($author));

        $chatIds = [];
        foreach ($bot->getRequestHistory() as $reqRes) {
            [$sent] = array_values($reqRes);
            $chatIds[] = (string) (FakeNutgram::getActualData($sent)['chat_id'] ?? '');
        }

        self::assertContains($manager->getTelegramId(), $chatIds, 'Менеджер не отримав нагадування.');
        self::assertNotContains($author->getTelegramId(), $chatIds, 'Заявника більше не смикаємо.');
        self::assertNotContains($director->getTelegramId(), $chatIds, 'Директору нагадування не потрібне.');
        self::assertNotContains($admin->getTelegramId(), $chatIds, 'Адмін — службовий акаунт, не отримувач.');
        self::assertNotContains($archived->getTelegramId(), $chatIds, 'Прибрана людина не має отримувати нічого.');
    }

    private function request(TelegramUser $author): SupplyRequest
    {
        return (self::getContainer()->get(CreateRequest::class))(
            $author,
            new CreateRequestInput(
                item: 'Цемент М500',
                quantity: '10',
                unit: Unit::Ton,
                needBy: new DateTime('-4 days'),
            ),
        );
    }

    private function person(SupplyRole $role): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId('overdue-to-' . $role->value . '-' . uniqid())
            ->setFirstName($role->label())
            ->setSupplyRole($role);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
