<?php

namespace App\Tests\Supply;

use App\Entity\TelegramUser;
use App\Service\CrmLoginLink;
use App\Supply\Entity\Supplier;
use App\Supply\Enum\SupplyRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** API довідника постачальників. */
class SupplierApiTest extends WebTestCase
{
    private const MARKER = 'Тест-Постачальник';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(Supplier::class)->findAll() as $supplier) {
            if (str_contains($supplier->getName(), self::MARKER)) {
                $this->em->remove($supplier);
            }
        }

        foreach ($this->em->getRepository(TelegramUser::class)->findBy(['first_name' => self::MARKER]) as $user) {
            $this->em->remove($user);
        }

        $this->em->flush();

        parent::tearDown();
    }

    public function testDirectoryIsClosedWithoutLogin(): void
    {
        $this->client->request('GET', '/api/supply/suppliers');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCreateAndList(): void
    {
        $this->login();

        $created = $this->post('/api/supply/suppliers', [
            'name' => self::MARKER . ' ФОП Петренко О.П.',
            'edrpou' => '1234567890',
            'phone' => '+380671112233',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertTrue($created['active']);
        self::assertSame('1234567890', $created['edrpou']);

        $list = $this->get('/api/supply/suppliers?q=' . urlencode('Петренко'));

        self::assertCount(1, $list['items']);
        self::assertSame($created['id'], $list['items'][0]['id']);
    }

    public function testDuplicateIsRefusedWithReadableError(): void
    {
        $this->login();

        $this->post('/api/supply/suppliers', ['name' => self::MARKER . ' ФОП Петренко О.П.']);

        $error = $this->post('/api/supply/suppliers', ['name' => self::MARKER . ' фоп  петренко  оп']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('вже є у довіднику', $error['error']);
    }

    public function testMalformedCodeIsRefused(): void
    {
        $this->login();

        $error = $this->post('/api/supply/suppliers', [
            'name' => self::MARKER . ' ТОВ Будматеріали',
            'edrpou' => '12',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertArrayHasKey('error', $error);
    }

    public function testHiddenSupplierLeavesTheChoiceListButStaysInDirectory(): void
    {
        $this->login();

        $created = $this->post('/api/supply/suppliers', ['name' => self::MARKER . ' ТОВ Будматеріали']);

        $this->patch('/api/supply/suppliers/' . $created['id'], ['active' => false, 'phone' => '+380441234567']);

        $forChoice = $this->get('/api/supply/suppliers?q=' . urlencode('Будматеріали') . '&active=1');
        self::assertCount(0, $forChoice['items'], 'прихований не пропонується при заповненні');

        $directory = $this->get('/api/supply/suppliers?q=' . urlencode('Будматеріали'));
        self::assertCount(1, $directory['items'], 'але лишається в довіднику');
        self::assertFalse($directory['items'][0]['active']);
        self::assertSame('+380441234567', $directory['items'][0]['phone']);
    }

    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return json_decode((string)$this->client->getResponse()->getContent(), true);
    }

    private function post(string $url, array $payload): array
    {
        return $this->send('POST', $url, $payload);
    }

    private function patch(string $url, array $payload): array
    {
        return $this->send('PATCH', $url, $payload);
    }

    private function send(string $method, string $url, array $payload): array
    {
        $this->client->request(
            $method,
            $url,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload),
        );

        return json_decode((string)$this->client->getResponse()->getContent(), true) ?? [];
    }

    private function login(): void
    {
        $user = (new TelegramUser())
            ->setTelegramId('supplier-api-' . uniqid())
            ->setFirstName(self::MARKER)
            ->setSupplyRole(SupplyRole::Manager);

        $this->em->persist($user);
        $this->em->flush();

        $url = self::getContainer()->get(CrmLoginLink::class)->issue($user);
        $this->client->request('GET', '/crm/auth/' . substr($url, strrpos($url, '/') + 1));
    }
}
