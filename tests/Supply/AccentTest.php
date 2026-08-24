<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Service\CrmLoginLink;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyAccent;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\Unit;
use App\Supply\Service\CreateRequest;
use App\Supply\Service\RequestFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Мітка менеджера для керівника (вимога клієнта від 21.08.2026).
 *
 * «Терміново» рахується з дати, яку ставить сам заявник, тож як сигнал воно не
 * працює: пріоритет розставляє той, хто бачить усі заявки разом.
 */
class AccentTest extends WebTestCase
{
    private const PREFIX = 'accent-test-';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(TelegramUser::class)->findAll() as $user) {
            if (! str_starts_with((string) $user->getTelegramId(), self::PREFIX)) {
                continue;
            }

            foreach ($this->em->getRepository(SupplyRequest::class)->findBy(['author' => $user]) as $request) {
                $this->em->remove($request);
            }

            $this->em->remove($user);
        }

        $this->em->flush();

        parent::tearDown();
    }

    public function testManagerSetsAndClearsMark(): void
    {
        $request = $this->request();
        $this->login(SupplyRole::Manager);

        $marked = $this->patch('/api/supply/requests/' . $request->getId() . '/accent', ['accent' => 'red']);
        self::assertResponseIsSuccessful();
        self::assertSame('red', $marked['accent']);
        self::assertSame('Терміновий рахунок', $marked['accentLabel']);

        $cleared = $this->patch('/api/supply/requests/' . $request->getId() . '/accent', ['accent' => 'none']);
        self::assertSame('none', $cleared['accent']);
        self::assertSame('', $cleared['accentEmoji']);
    }

    /** Мітка — інструмент менеджера: керівник її читає, але не ставить. */
    public function testDirectorCannotMark(): void
    {
        $request = $this->request();
        $this->login(SupplyRole::Director);

        $this->patch('/api/supply/requests/' . $request->getId() . '/accent', ['accent' => 'green']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testUnknownMarkIsRefused(): void
    {
        $request = $this->request();
        $this->login(SupplyRole::Manager);

        $this->patch('/api/supply/requests/' . $request->getId() . '/accent', ['accent' => 'blue']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** У боті мітку видно першим рядком картки, а «терміново» — дрібним підписом. */
    public function testCardShowsMarkInsteadOfFireEmoji(): void
    {
        $request = $this->request();
        $request->setAccent(SupplyAccent::Yellow)->setUrgent(true);

        $card = self::getContainer()->get(RequestFormatter::class)->card($request);

        self::assertStringContainsString('🟡 <b>Звернути увагу</b>', $card);
        self::assertStringNotContainsString('🔥', $card);
    }

    /** Помічені заявки йдуть угору списку: червона, жовта, зелена, решта. */
    public function testMarkedRequestsComeFirst(): void
    {
        $plain = $this->request();
        $marked = $this->request();
        $marked->setAccent(SupplyAccent::Red);
        $this->em->flush();

        $this->login(SupplyRole::Manager);

        $this->client->request('GET', '/api/supply/requests?q=' . urlencode('Шурупи'));
        $items = json_decode((string) $this->client->getResponse()->getContent(), true)['items'];
        $numbers = array_column($items, 'number');

        self::assertSame($marked->getNumber(), $numbers[0]);
        self::assertContains($plain->getNumber(), $numbers);
    }

    private function patch(string $url, array $payload): array
    {
        $this->client->request(
            'PATCH',
            $url,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function request(): SupplyRequest
    {
        return (self::getContainer()->get(CreateRequest::class))(
            $this->user(SupplyRole::Worker),
            new CreateRequestInput(item: 'Шурупи по металу', quantity: '10', unit: Unit::Piece),
        );
    }

    private function login(SupplyRole $role): void
    {
        $user = $this->user($role);
        $url = self::getContainer()->get(CrmLoginLink::class)->issue($user);
        $this->client->request('GET', '/crm/auth/' . substr($url, strrpos($url, '/') + 1));
    }

    private function user(SupplyRole $role): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId(self::PREFIX . uniqid())
            ->setFirstName('Тест-Мітка')
            ->setSupplyRole($role);
        $user->decideAccess(AccessStatus::Approved, null);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
