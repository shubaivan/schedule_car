<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Service\CrmLoginLink;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Entity\Supplier;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\Unit;
use App\Supply\Service\CreateRequest;
use App\Supply\Service\SupplierDirectory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** API закупівель: як менеджер із CRM закриває заявку покупкою. */
class PurchaseApiTest extends WebTestCase
{
    private const MARKER = 'Тест-Закупівля';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(TelegramUser::class)->findBy(['first_name' => self::MARKER]) as $user) {
            foreach ($this->em->getRepository(SupplyRequest::class)->findBy(['author' => $user]) as $request) {
                $this->em->remove($request);
            }
            $this->em->remove($user);
        }

        foreach ($this->em->getRepository(Supplier::class)->findAll() as $supplier) {
            if (str_contains($supplier->getName(), self::MARKER)) {
                $this->em->remove($supplier);
            }
        }

        $this->em->flush();

        parent::tearDown();
    }

    public function testPurchaseIsRecordedAndOpensTheWayToPaid(): void
    {
        $request = $this->request();
        $supplier = $this->supplier();
        $this->login();

        $this->send('POST', sprintf('/api/supply/requests/%d/status', $request->getId()), ['to' => 'in_progress']);
        self::assertResponseIsSuccessful();

        // Без закупівлі «Оплачено» не приймається.
        $blocked = $this->send('POST', sprintf('/api/supply/requests/%d/status', $request->getId()), ['to' => 'paid']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('у кого купили', $blocked['error']);

        $withPurchase = $this->send('POST', sprintf('/api/supply/requests/%d/purchases', $request->getId()), [
            'supplierId' => $supplier->getId(),
            'quantity' => '5',
            'pricePerUnit' => '1200,50',
            'payment' => 'cash',
            'invoiceNumber' => 'РН-114',
            'purchasedAt' => '2026-08-12',
        ]);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $withPurchase['purchases']);
        self::assertSame(6002.5, $withPurchase['purchases'][0]['totalAmount'], 'сума дорахована з ціни й кількості');
        self::assertSame('Готівка', $withPurchase['purchases'][0]['paymentLabel']);
        self::assertSame('2026-08-12', $withPurchase['purchases'][0]['purchasedAt']);
        self::assertSame(6002.5, $withPurchase['purchaseTotal']);

        $paid = $this->send('POST', sprintf('/api/supply/requests/%d/status', $request->getId()), ['to' => 'paid']);
        self::assertResponseIsSuccessful();
        self::assertSame('paid', $paid['status']);
    }

    public function testPurchaseWithoutSupplierIsRefused(): void
    {
        $request = $this->request();
        $this->login();

        $error = $this->send('POST', sprintf('/api/supply/requests/%d/purchases', $request->getId()), [
            'totalAmount' => '100',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('постачальника', $error['error']);
    }

    public function testForeignPurchaseIdIsNotAccepted(): void
    {
        $mine = $this->request();
        $other = $this->request();
        $supplier = $this->supplier();
        $this->login();

        $created = $this->send('POST', sprintf('/api/supply/requests/%d/purchases', $other->getId()), [
            'supplierId' => $supplier->getId(),
            'totalAmount' => '100',
        ]);

        $purchaseId = $created['purchases'][0]['id'];

        $this->send('DELETE', sprintf('/api/supply/requests/%d/purchases/%d', $mine->getId(), $purchaseId), []);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testPurchaseCanBeRemovedWhileRequestIsOpen(): void
    {
        $request = $this->request();
        $supplier = $this->supplier();
        $this->login();

        $created = $this->send('POST', sprintf('/api/supply/requests/%d/purchases', $request->getId()), [
            'supplierId' => $supplier->getId(),
            'totalAmount' => '100',
        ]);

        $after = $this->send(
            'DELETE',
            sprintf('/api/supply/requests/%d/purchases/%d', $request->getId(), $created['purchases'][0]['id']),
            [],
        );

        self::assertResponseIsSuccessful();
        self::assertCount(0, $after['purchases']);
    }

    private function send(string $method, string $url, array $payload): array
    {
        $this->client->request(
            $method,
            $url,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function supplier(): Supplier
    {
        return self::getContainer()->get(SupplierDirectory::class)
            ->findOrCreate(self::MARKER . ' ФОП Петренко ' . uniqid());
    }

    private function request(): SupplyRequest
    {
        return (self::getContainer()->get(CreateRequest::class))(
            $this->user(SupplyRole::Worker),
            new CreateRequestInput(item: 'Пісок річковий', quantity: '5', unit: Unit::CubicMeter),
        );
    }

    private function login(): void
    {
        $url = self::getContainer()->get(CrmLoginLink::class)->issue($this->user(SupplyRole::Manager));
        $this->client->request('GET', '/crm/auth/' . substr($url, strrpos($url, '/') + 1));
    }

    private function user(SupplyRole $role): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId('purchase-api-' . uniqid())
            ->setFirstName(self::MARKER)
            ->setSupplyRole($role);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
