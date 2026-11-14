<?php

namespace App\Tests\Plan\Notification;

use App\Plan\Push\Entity\PushSubscription;
use App\Plan\Push\PushSender;
use App\Tasks\Enum\WorkRole;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\VAPID;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Web Push: зашифроване повідомлення з VAPID-підписом іде на кожен пристрій
 * людини; мертву підписку (410) push-сервіс прибирає; без ключів — нічого.
 */
class PushSenderTest extends KernelTestCase
{
    use NotificationFixtures;

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

    protected function em(): EntityManagerInterface
    {
        return $this->em;
    }

    public function testEncryptedSignedPushAndDeadSubscriptionRemoved(): void
    {
        $keys = VAPID::createVapidKeys();
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'headers' => $options['normalized_headers'], 'body' => $options['body']];

            return new MockResponse('', ['http_code' => str_contains($url, '/gone') ? 410 : 201]);
        });
        $sender = new PushSender($this->em, new NullLogger(), new Psr18Client($http), $keys['publicKey'], $keys['privateKey'], 'https://plan.test');
        $user = $this->person(WorkRole::Manager);

        $sender->subscribe($user, 'https://push.example.test/send/alive', ...$this->browserKeys(), ua: 'Chrome');
        $sender->subscribe($user, 'https://push.example.test/send/gone', ...$this->browserKeys(), ua: 'Firefox');

        $delivered = $sender->send((int) $user->getId(), ['title' => 'Вас згадали · № 12', 'body' => 'Іван: глянь', 'url' => '/crm/tasks/board/12', 'tag' => 'task-12']);

        self::assertSame(1, $delivered);
        self::assertCount(2, $requests);
        $alive = array_values(array_filter($requests, static fn ($r) => str_ends_with($r['url'], '/alive')))[0];
        self::assertSame('POST', $alive['method']);
        self::assertStringStartsWith('Authorization: vapid t=', $alive['headers']['authorization'][0] ?? '', 'VAPID-підпис');
        self::assertSame('aes128gcm', explode(': ', $alive['headers']['content-encoding'][0])[1] ?? null);
        self::assertStringNotContainsString('Вас згадали', (string) $alive['body'], 'Тіло зашифроване');

        $left = $this->em->getRepository(PushSubscription::class)->findBy(['user' => $user]);
        self::assertCount(1, $left, '410 Gone — підписку видалено');
        self::assertSame('https://push.example.test/send/alive', $left[0]->getEndpoint());
        self::assertNotNull($left[0]->getLastOkAt());
    }

    public function testWithoutKeysPushIsOff(): void
    {
        $sender = new PushSender($this->em, new NullLogger(), new Psr18Client(new MockHttpClient()), '', '');

        self::assertFalse($sender->enabled());
        self::assertNull($sender->publicKey());
        self::assertSame(0, $sender->send(1, ['title' => 't', 'body' => 'b', 'url' => '/']));
    }

    /** @return array{p256dh: string, auth: string} ключі, які дав би браузер */
    private function browserKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        self::assertNotFalse($key);
        $ec = openssl_pkey_get_details($key)['ec'];
        $b64 = static fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return ['p256dh' => $b64("\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)), 'auth' => $b64(random_bytes(16))];
    }
}
