<?php

namespace App\Tests\Tasks;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Enum\SupplyStatus;
use App\Tasks\Command\TasksDemoSeedCommand;
use App\Tasks\Demo\DemoSupply;
use App\Tasks\Enum\WorkRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Оболонка «одного продукту» (потік S2): правило «без модалок», сторінка файлу
 * /crm/files/:id і демо-заявки, з якими «План» показують.
 */
class PlanShellS2Test extends WebTestCase
{
    private const string PREFIX = 'plan-s2-test-';

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
        $ids = $conn->fetchFirstColumn('SELECT id FROM telegram_user WHERE telegram_id LIKE ?', [self::PREFIX . '%']);

        if ($ids !== []) {
            $list = implode(',', array_map('intval', $ids));
            $conn->executeStatement("DELETE FROM task WHERE creator_id IN ($list)");
            $conn->executeStatement("DELETE FROM login_token WHERE user_id IN ($list)");
            $conn->executeStatement("DELETE FROM telegram_user WHERE id IN ($list)");
        }

        parent::tearDown();
    }

    /**
     * Правило Івана 14.11: жодних спливаючих вікон — кожне вікно є сторінкою, тож «Назад» працює.
     * Страж по вихідному коду фронтенду (зібраний public/crm у git не лежить).
     */
    public function testFrontendHasNoModalsOrNativeDialogs(): void
    {
        $root = self::getContainer()->getParameter('kernel.project_dir') . '/frontend/src';
        $forbidden = [
            '/t-modal-wrap/' => 'модальна обгортка',
            '/role="dialog"|aria-modal/' => 'діалог поверх сторінки',
            '/<Teleport\b/' => 'шар поверх сторінки (Teleport)',
            '/(?<![\w.])(?:window\.)?confirm\s*\(/' => 'confirm()',
            '/(?<![\w.])(?:window\.)?alert\s*\(/' => 'alert()',
            '/f-sheet-wrap/' => 'шторка дій',
        ];
        $found = [];

        foreach (new Finder()->files()->in($root)->name(['*.vue', '*.ts']) as $file) {
            foreach ($forbidden as $pattern => $what) {
                if (preg_match($pattern, $file->getContents())) {
                    $found[] = $file->getRelativePathname() . ': ' . $what;
                }
            }
        }

        self::assertSame([], $found);
    }

    /** Колишні модалки — тепер маршрути модулів, і кожен модуль описує свої в routes.ts. */
    public function testFormerModalsAreRoutes(): void
    {
        $src = self::getContainer()->getParameter('kernel.project_dir') . '/frontend/src';
        $routes = (string) file_get_contents($src . '/modules/tasks/routes.ts')
            . file_get_contents($src . '/modules/supply/routes.ts')
            . file_get_contents($src . '/modules/plan/routes.ts');

        foreach (["'/tasks/new'", "'/tasks/:id(\\\\d+)/edit'", "'/staff/new'", "'/staff/:id(\\\\d+)'", "'/supply/new'", "'/supply/board/:id(\\\\d+)?'", "'/files/:id(\\\\d+)'", "'/'", "'/requests/:id'"] as $path) {
            self::assertStringContainsString("path: $path", $routes, 'маршрут ' . $path);
        }

        $router = (string) file_get_contents($src . '/app/router.ts');
        self::assertStringNotContainsString('component:', $router, 'app/router.ts лише збирає маршрути модулів');
    }

    public function testFilePageMetaRespectsTaskVisibility(): void
    {
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $outsider = $this->user(WorkRole::Production, 'out');
        $this->client->loginUser($manager);
        $this->client->request('POST', '/api/tasks', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['title' => 'Файл для сторінки']));
        $task = json_decode((string) $this->client->getResponse()->getContent(), true);

        $copy = tempnam(sys_get_temp_dir(), 'up');
        copy(self::getContainer()->getParameter('kernel.project_dir') . '/demo/tasks/Плити_склад.jpg', $copy);
        $this->client->request('POST', '/api/tasks/' . $task['id'] . '/messages', [], ['files' => [new UploadedFile($copy, 'Плити_склад.jpg', 'image/jpeg', null, true)]]);
        $file = json_decode((string) $this->client->getResponse()->getContent(), true)['attachments'][0];

        $this->client->request('GET', '/api/tasks/files/' . $file['id']);
        self::assertResponseIsSuccessful();
        $meta = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($file['id'], $meta['file']['id']);
        self::assertSame($task['id'], $meta['task']['id']);
        self::assertSame('Файл для сторінки', $meta['task']['title']);

        $this->client->loginUser($outsider);
        $this->client->request('GET', '/api/tasks/files/' . $file['id']);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Демо-заявки мають бути правдоподібними: відомі люди й відділи, наявні файли,
     * лише дозволені переходи статусів, і до затвердження — обовʼязково закупівля.
     */
    public function testDemoSupplyRequestsFollowTheRealRules(): void
    {
        $dir = self::getContainer()->getParameter('kernel.project_dir') . '/demo/tasks/';
        $suppliers = array_column(DemoSupply::SUPPLIERS, 0);

        foreach (DemoSupply::REQUESTS as [$author, $dep, $item, , , , , , , , , $steps]) {
            self::assertArrayHasKey($author, TasksDemoSeedCommand::PEOPLE, $item);
            self::assertContains($dep, TasksDemoSeedCommand::DEPARTMENTS, $item);
            $status = SupplyStatus::New;
            $purchased = false;
            $last = 0;

            foreach ($steps as $step) {
                self::assertArrayHasKey($step[1], TasksDemoSeedCommand::PEOPLE, $item);
                self::assertGreaterThanOrEqual($last, end($step), $item . ': кроки за часом');
                $last = (int) end($step);

                if ($step[0] === 'purchase') {
                    $purchased = true;
                    self::assertContains($step[2], $suppliers);
                } elseif ($step[0] === 'file') {
                    self::assertFileExists($dir . $step[2]);
                } elseif ($step[0] === 'status') {
                    self::assertTrue($status->canTransitionTo($step[2]), sprintf('%s: %s → %s', $item, $status->value, $step[2]->value));
                    self::assertTrue(! $step[2]->requiresPurchase() || $purchased, $item . ': без закупівлі не затверджують');
                    $status = $step[2];
                }
            }
        }
    }

    private function user(WorkRole $role, string $name): TelegramUser
    {
        $user = new TelegramUser()->setPlanFullAccess(true)
            ->setTelegramId(self::PREFIX . $name . '-' . uniqid())
            ->setFirstName(ucfirst($name))
            ->setLastName('Тестовий')
            ->setWorkRole($role);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
