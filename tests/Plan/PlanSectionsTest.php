<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Enum\SupplyRole;
use App\Tasks\Enum\WorkRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Приховані розділи «Плану» (Олександр, 17.11.2026): без повного доступу — лише Огляд,
 * Хроніка, Задачі, Пошук, Сповіщення, Люди. Постачання, Склад, Контрагенти, Автопарк і Диск
 * закриті і в меню (/api/me), і в API (403), і в Пошуку / Хроніці. Повний доступ — прапорець
 * картки (видає лише суперадмін) або роль суперадміна.
 */
class PlanSectionsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        parent::tearDown();
    }

    /** Навіть директор і менеджер постачання без прапорця не бачать прихованих розділів. */
    public function testWithoutFullAccessHiddenSectionsAreClosedEverywhere(): void
    {
        foreach ([[WorkRole::Director, SupplyRole::Director], [WorkRole::Manager, SupplyRole::Manager], [WorkRole::Accountant, SupplyRole::Worker]] as [$work, $supply]) {
            $user = $this->user($work, $supply, full: false);
            $this->client->loginUser($user);

            $me = $this->json('GET', '/api/me');
            self::assertFalse($me['fullAccess'], $work->value);
            self::assertFalse($me['fleet']);
            self::assertFalse($me['warehouse']);
            foreach (['plan.full', 'supply.manage', 'supply.approve', 'warehouse.view', 'warehouse.edit', 'counterparties.view', 'counterparties.edit', 'counterparties.finance'] as $cap) {
                self::assertFalse($me['capabilities'][$cap], $work->value . ' ' . $cap);
            }

            foreach (['/api/supply/requests', '/api/supply/suppliers', '/api/supply/reports', '/api/warehouse/items', '/api/warehouse/meta', '/api/plan/counterparties', '/api/fleet/cars', '/api/plan/drive', '/sklad'] as $url) {
                $this->client->request('GET', $url);
                self::assertResponseStatusCodeSame(403, $work->value . ' ' . $url);
            }

            $this->client->request('POST', '/api/supply/requests', server: ['CONTENT_TYPE' => 'application/json'], content: '{"item":"Цемент","quantity":1,"unit":"t"}');
            self::assertResponseStatusCodeSame(403, 'створити заявку теж не можна');

            // Базові розділи працюють.
            foreach (['/api/supply/meta', '/api/tasks/board', '/api/plan/chronicle', '/api/plan/notifications', '/api/staff', '/api/plan/overview'] as $url) {
                $this->client->request('GET', $url);
                self::assertResponseIsSuccessful($work->value . ' ' . $url);
            }

            $groups = array_column($this->json('GET', '/api/plan/search/meta')['types'], 'key');
            self::assertContains('task', $groups);
            self::assertNotContains('supply', $groups);
            self::assertNotContains('warehouse', $groups);

            $overview = $this->json('GET', '/api/plan/overview');
            self::assertFalse($overview['supply']['approve'] ?? false, 'на Огляді — без погодження заявок');
            self::assertNull($overview['warehouse'] ?? null, 'на Огляді — без складу');
        }
    }

    public function testFlagOrSuperadminOpensEverything(): void
    {
        $flagged = $this->user(WorkRole::Manager, SupplyRole::Manager, full: true);
        $super = $this->user(WorkRole::Production, SupplyRole::Worker, full: false)->setSuperadmin(true);
        $this->em->flush();

        foreach ([$flagged, $super] as $user) {
            $this->client->loginUser($user);
            self::assertTrue($this->json('GET', '/api/me')['fullAccess']);
            $this->client->request('GET', '/api/supply/requests');
            self::assertResponseIsSuccessful();
        }

        $this->client->loginUser($flagged);
        $this->client->request('GET', '/api/warehouse/items');
        self::assertResponseIsSuccessful('менеджер постачання з прапорцем веде склад');
        $this->client->request('GET', '/api/plan/counterparties');
        self::assertResponseIsSuccessful();
    }

    /** Прапорець видає й знімає лише суперадмін; адмін — ні, ні при заведенні, ні в картці. */
    public function testOnlySuperadminGrantsFullAccess(): void
    {
        $admin = $this->user(WorkRole::Admin, SupplyRole::Admin, full: false);
        $super = $this->user(WorkRole::Admin, SupplyRole::Admin, full: false)->setSuperadmin(true);
        $target = $this->user(WorkRole::Manager, SupplyRole::Worker, full: false);
        $this->em->flush();

        $this->client->loginUser($admin);
        $row = $this->row($target);
        self::assertNull($row['fullAccess'], 'адміну прапорець не показуємо');
        self::assertFalse($row['fullAccessEditable']);
        $this->client->request('PATCH', '/api/staff/' . $target->getId(), server: ['CONTENT_TYPE' => 'application/json'], content: '{"fullAccess":true}');
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->flag($target));

        $this->client->request('POST', '/api/staff', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['firstName' => 'Новий', 'phone' => '0501112299', 'role' => 'manager', 'fullAccess' => true], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $createdId = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['id'];
        self::assertFalse((bool) $this->db->fetchOne('SELECT plan_full_access FROM telegram_user WHERE id = ?', [$createdId]), 'адмін при заведенні прапорця не видає');

        $this->client->loginUser($super);
        self::assertFalse($this->row($target)['fullAccess']);
        self::assertTrue($this->row($target)['fullAccessEditable']);
        $this->client->request('PATCH', '/api/staff/' . $target->getId(), server: ['CONTENT_TYPE' => 'application/json'], content: '{"fullAccess":true}');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->flag($target));
        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM plan_event WHERE type = 'staff_changed' AND subject_id = ? AND payload::text LIKE '%fullAccess%'",
            [$target->getId()],
        ), 'зміна прапорця — у журналі аудиту');
    }

    private function flag(TelegramUser $user): bool
    {
        return (bool) $this->db->fetchOne('SELECT plan_full_access FROM telegram_user WHERE id = ?', [$user->getId()]);
    }

    /** @return array<string, mixed> */
    private function row(TelegramUser $target): array
    {
        foreach ($this->json('GET', '/api/staff')['items'] as $row) {
            if ($row['id'] === $target->getId()) {
                return $row;
            }
        }

        self::fail('людини немає в списку');
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $url): array
    {
        $this->client->request($method, $url);
        self::assertResponseIsSuccessful($url);

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function user(WorkRole $work, SupplyRole $supply, bool $full): TelegramUser
    {
        $user = new TelegramUser()
            ->setTelegramId('sections-' . uniqid('', true))
            ->setFirstName('Розділи')
            ->setLastName('Тест')
            ->setPhoneNumber('38050' . random_int(1000000, 9999999))
            ->setWorkRole($work)
            ->setSupplyRole($supply)
            ->setPlanFullAccess($full);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
