<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Enum\SupplyRole;
use App\Tasks\Enum\WorkRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Спільне для тестів пошуку й Хроніки (S4): люди з префіксом, вхід, запити до
 * API і прибирання всього, що вони створили (задачі, заявки, склад, рядки індексу).
 */
trait S4Fixtures
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $ids = array_map('intval', $conn->fetchFirstColumn('SELECT id FROM telegram_user WHERE telegram_id LIKE ?', [self::PREFIX . '%']));

        if ($ids !== []) {
            $list = implode(',', $ids);
            $tasks = $conn->fetchFirstColumn("SELECT id FROM task WHERE creator_id IN ($list)");
            $requests = $conn->fetchFirstColumn("SELECT id FROM supply_request WHERE author_id IN ($list)");

            foreach ([['task', $tasks], ['supply', $requests]] as [$type, $subjectIds]) {
                if ($subjectIds !== []) {
                    $in = implode(',', array_map('intval', $subjectIds));
                    $conn->executeStatement("DELETE FROM search_document WHERE subject_type = '$type' AND subject_id IN ($in)");
                    $conn->executeStatement("DELETE FROM plan_subject_activity WHERE subject_type = '$type' AND subject_id IN ($in)");
                }
            }

            $conn->executeStatement("DELETE FROM task WHERE creator_id IN ($list)");

            if ($requests !== []) {
                $in = implode(',', array_map('intval', $requests));

                foreach (['supply_comment', 'supply_status_log', 'supply_attachment', 'supply_purchase'] as $table) {
                    $conn->executeStatement("DELETE FROM $table WHERE request_id IN ($in)");
                }

                $conn->executeStatement("DELETE FROM supply_request WHERE id IN ($in)");
            }

            $conn->executeStatement("DELETE FROM search_document WHERE doc_type = 'person' AND ref_id IN ($list)");
            $conn->executeStatement("DELETE FROM telegram_user WHERE id IN ($list)");
        }

        parent::tearDown();
    }

    private function user(WorkRole $role, string $name, SupplyRole $supply = SupplyRole::Worker): TelegramUser
    {
        $user = new TelegramUser()
            ->setTelegramId(self::PREFIX . $name . '-' . uniqid())
            ->setFirstName(ucfirst($name))
            ->setLastName('Пошук')
            ->setWorkRole($role)
            ->setSupplyRole($supply)
            ->setEmail(self::PREFIX . $name . uniqid() . '@example.test');
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function login(TelegramUser $user): void
    {
        $this->client->loginUser($user);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function get(string $url, array $query = []): array
    {
        $this->client->request('GET', $url . ($query !== [] ? '?' . http_build_query($query) : ''));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->json();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, array $body = []): array
    {
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function search(array $query): array
    {
        return $this->get('/api/plan/search', $query);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, int>
     */
    private static function facet(array $result, string $name, string $key = 'key'): array
    {
        $out = [];

        foreach ($result['facets'][$name] as $row) {
            $out[(string) $row[$key]] = (int) $row['count'];
        }

        return $out;
    }
}
