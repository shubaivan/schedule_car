<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Enum\SupplyRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Стенди заявок (TASKS_ENABLED=0): пошук і Хроніка «Плану» закриті, а заявки й
 * люди не пишуть ні search_document, ні plan_subject_activity — поведінка
 * стенду не змінилась.
 */
class S4StandModeTest extends WebTestCase
{
    private const ENV = ['TASKS_ENABLED' => '0', 'WAREHOUSE_ENABLED' => '0', 'CRM_HOME' => 'requests', 'PLAN_EVENTS_TRANSPORT' => 'sync://'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var array<string, array{0: mixed, 1: mixed}> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $name => $value) {
            $this->saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
            $_SERVER[$name] = $_ENV[$name] = $value;
        }

        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();

        foreach ($this->em->getRepository(TelegramUser::class)->findBy(['first_name' => 'Тест-S4-Стенд']) as $user) {
            foreach (['supply_comment', 'supply_status_log'] as $table) {
                $conn->executeStatement("DELETE FROM $table WHERE request_id IN (SELECT id FROM supply_request WHERE author_id = ?)", [$user->getId()]);
            }

            $conn->executeStatement('DELETE FROM supply_request WHERE author_id = ?', [$user->getId()]);
            $conn->executeStatement('DELETE FROM telegram_user WHERE id = ?', [$user->getId()]);
        }

        foreach ($this->saved as $name => [$server, $env]) {
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }

            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
        }

        parent::tearDown();
    }

    public function testStandKeepsNoPlanIndexAndClosesPlanApi(): void
    {
        $user = (new TelegramUser())->setTelegramId('s4-stand-' . uniqid())->setFirstName('Тест-S4-Стенд')->setSupplyRole(SupplyRole::Manager);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('POST', '/api/supply/requests', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['item' => 'Стенд S4', 'quantity' => '1', 'unit' => 'piece']));
        self::assertResponseStatusCodeSame(201);
        $id = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['id'];
        $this->client->request('POST', '/api/supply/requests/' . $id . '/comments', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['text' => 'Коментар стенду']));
        self::assertResponseIsSuccessful();

        $conn = $this->em->getConnection();
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM search_document WHERE subject_type = 'supply' AND subject_id = ?", [$id]));
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM plan_subject_activity WHERE subject_type = 'supply' AND subject_id = ?", [$id]));
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM search_document WHERE doc_type = 'person' AND ref_id = ?", [$user->getId()]));
        self::assertGreaterThan(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM plan_event WHERE subject_type = 'supply' AND subject_id = ?", [$id]), 'Журнал S0 пишеться як і раніше');

        $this->client->request('GET', '/api/plan/search?q=стенд');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/plan/chronicle');
        self::assertResponseStatusCodeSame(404);
    }
}
