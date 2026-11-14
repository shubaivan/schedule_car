<?php

namespace App\Tests\Tasks;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Entity\Department;
use App\Tasks\Command\TasksDemoSeedCommand;
use App\Tasks\Demo\DemoDeals;
use App\Tasks\Demo\DemoFiles;
use App\Tasks\Enum\WorkRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Оболонка «Плану»: підпис ролі в шапці, щільна картка дошки (відділи, файли)
 * і демо-засів (дані узгоджені, файли малюються, живу базу не чіпає).
 */
class PlanShellTest extends WebTestCase
{
    private const PREFIX = 'plan-shell-test-';

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
            $conn->executeStatement("DELETE FROM telegram_user WHERE id IN ($list)");
        }

        $conn->executeStatement("DELETE FROM supply_department WHERE name LIKE 'Шел-відділ %'");

        parent::tearDown();
    }

    public function testHeaderShowsWorkRoleInsteadOfSupplyRole(): void
    {
        // У заявках людина «Робітник» (роль за замовчуванням), у Плані — менеджер з продажу.
        $manager = $this->user(WorkRole::Manager, 'mgr');
        $manager->setPosition('Менеджер з продажу');
        $this->em->flush();

        $this->client->loginUser($manager);
        $me = $this->get('/api/me');

        self::assertSame('Робітник', $me['roleLabel']);
        self::assertSame('manager', $me['workRole']);
        self::assertSame('Менеджер з продажу', $me['workRoleLabel']);
        self::assertSame('Менеджер з продажу', $me['position']);
    }

    public function testBoardCardCarriesDepartmentsAndFileStats(): void
    {
        $sales = (new Department())->setName('Шел-відділ 3. Продажі ' . uniqid());
        $plant = (new Department())->setName('Шел-відділ 7. Виробництво ' . uniqid());
        $this->em->persist($sales);
        $this->em->persist($plant);
        $manager = $this->user(WorkRole::Manager, 'mgr', $sales);
        $worker = $this->user(WorkRole::Production, 'wrk', $plant);

        $this->client->loginUser($manager);
        $byDepartment = $this->send('/api/tasks', ['title' => 'Кільця КС 10-9', 'departmentIds' => [$plant->getId(), $sales->getId()]]);
        $byPeople = $this->send('/api/tasks', ['title' => 'Перемички', 'memberIds' => [$worker->getId()]]);

        $dir = self::getContainer()->getParameter('kernel.project_dir') . '/demo/tasks/';
        $this->client->request('POST', '/api/tasks/' . $byDepartment['id'] . '/messages', ['text' => 'Документи'], [
            'files' => [$this->upload($dir . 'Плити_склад.jpg', 'image/jpeg'), $this->upload($dir . 'Рахунок_на_оплату_118.pdf', 'application/pdf')],
        ]);
        self::assertResponseStatusCodeSame(201);
        $message = $this->json();

        $card = $this->card($byDepartment['id']);
        // Відділи — за номером у назві: «3. …» раніше за «7. …».
        self::assertSame([$sales->getName(), $plant->getName()], $card['departmentNames']);
        self::assertSame(['count' => 2, 'pdf' => 1, 'images' => 1], $card['fileStats']);

        // Задача на людей — на картці відділи цих людей (без постановника).
        $card = $this->card($byPeople['id']);
        self::assertSame([$plant->getName()], $card['departmentNames']);
        self::assertSame(0, $card['fileStats']['count']);

        // Видалене повідомлення забирає й файли з лічильника.
        $this->client->request('DELETE', '/api/tasks/' . $byDepartment['id'] . '/messages/' . $message['id']);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->card($byDepartment['id'])['fileStats']['count']);
    }

    public function testRootOpensThePlanWhenItIsTheHome(): void
    {
        $home = self::getContainer()->get(\App\Controller\HomeController::class);

        $plan = $home->index('testbot', new \App\Tasks\Service\TasksSection(true, 'tasks'));
        self::assertSame(302, $plan->getStatusCode());
        self::assertSame('/crm/tasks', $plan->headers->get('Location'));

        // Стенд заявок (CRM_HOME=requests) і вимкнений План — як і було, титулка заявок.
        self::assertSame(200, $home->index('testbot', new \App\Tasks\Service\TasksSection(true, 'requests'))->getStatusCode());
        self::assertSame(200, $home->index('testbot', new \App\Tasks\Service\TasksSection(false, 'tasks'))->getStatusCode());
    }

    public function testDemoSeedRefusesOnDatabaseWithLivePeople(): void
    {
        $this->user(WorkRole::Manager, 'live');

        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('tasks:demo-seed'));
        $tester->execute(['--password' => 'demo-pass-12345', '--reset' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('не демо-стенд', $tester->getDisplay());
        self::assertGreaterThan(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM telegram_user WHERE telegram_id LIKE 'plan-shell-test-%'"));
    }

    public function testDemoSeedRefusesOnProdWithoutDemoFlag(): void
    {
        $prod = new TasksDemoSeedCommand(
            self::getContainer()->get(EntityManagerInterface::class),
            self::getContainer()->get(\App\Supply\Repository\DepartmentRepository::class),
            self::getContainer()->get(\App\Tasks\Service\TaskService::class),
            self::getContainer()->get(\App\Tasks\Service\DeferredPreviews::class),
            self::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class),
            '/nonexistent',
            'prod',
            null,
        );
        $tester = new CommandTester($prod);
        $tester->execute(['--password' => 'demo-pass-12345', '--dry-run' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('PLAN_DEMO_STAND', $tester->getDisplay());
    }

    public function testDemoDealsReferenceKnownPeopleLabelsDepartmentsAndFiles(): void
    {
        $deals = DemoDeals::all();
        self::assertGreaterThanOrEqual(20, count($deals));
        self::assertLessThanOrEqual(25, count($deals));

        $labels = array_column(TasksDemoSeedCommand::LABELS, 0);
        $dir = self::getContainer()->getParameter('kernel.project_dir') . '/demo/tasks/';
        $generated = DemoDeals::generated();
        $statuses = [];

        foreach ($deals as $deal) {
            $statuses[$deal['status']->value] = true;
            self::assertArrayHasKey($deal['creator'], TasksDemoSeedCommand::PEOPLE, $deal['title']);

            foreach ($deal['members'] as $login) {
                self::assertArrayHasKey($login, TasksDemoSeedCommand::PEOPLE, $deal['title']);
            }

            foreach ($deal['labels'] as $label) {
                self::assertContains($label, $labels, $deal['title']);
            }

            foreach ($deal['departments'] as $department) {
                self::assertContains($department, TasksDemoSeedCommand::DEPARTMENTS, $deal['title']);
            }

            $previous = PHP_INT_MIN;

            foreach ($deal['messages'] as $row) {
                $hours = $row[0] === '@event' ? $row[2] : $row[1];
                self::assertGreaterThanOrEqual($previous, $hours, 'Повідомлення по порядку: ' . $deal['title']);
                $previous = $hours;
                self::assertArrayHasKey($row[0] === '@event' ? $row[1] : $row[0], TasksDemoSeedCommand::PEOPLE);

                foreach ($row[3] ?? [] as $file) {
                    if ($row[0] !== '@event') {
                        self::assertTrue(is_file($dir . $file) || isset($generated[$file]), 'Немає файлу ' . $file);
                    }
                }
            }
        }

        // Кожна колонка дошки має що показати.
        foreach (['new', 'in_work', 'approval', 'approved', 'waiting', 'done', 'rejected'] as $status) {
            self::assertArrayHasKey($status, $statuses);
        }
    }

    public function testDemoFilesDrawPdfAndPhoto(): void
    {
        $files = new DemoFiles();
        $dir = sys_get_temp_dir() . '/plan-shell-test-' . uniqid();
        mkdir($dir);

        try {
            $files->pdf($dir . '/doc.pdf', [
                ['title' => 'Рахунок № 1', 'lines' => ['Покупець: ТОВ «Тест»'], 'table' => [['Назва', 'К-ть'], ['Кільце КС 10-9', '4']]],
                ['title' => 'Креслення', 'drawing' => 'КС 10-9'],
            ]);
            $pdf = (string) file_get_contents($dir . '/doc.pdf');
            self::assertStringStartsWith('%PDF-1.4', $pdf);
            self::assertSame(2, substr_count($pdf, '/Type /Page '));
            self::assertStringEndsWith("%%EOF\n", $pdf);

            $pdfinfo = (new ExecutableFinder())->find('pdfinfo');

            if ($pdfinfo !== null) {
                $process = new Process([$pdfinfo, $dir . '/doc.pdf']);
                $process->mustRun();
                self::assertMatchesRegularExpression('/^Pages:\s+2$/m', $process->getOutput());
            }

            $files->photo($dir . '/photo.jpg', 'rings', 'Склад · КС 10-9');
            $size = getimagesize($dir . '/photo.jpg');
            self::assertIsArray($size);
            self::assertSame([1400, 1050, IMAGETYPE_JPEG], [$size[0], $size[1], $size[2]]);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    /** @return array<string, mixed> */
    private function card(int $taskId): array
    {
        foreach ($this->get('/api/tasks/board') as $column) {
            foreach ($column as $item) {
                if ($item['id'] === $taskId) {
                    return $item;
                }
            }
        }

        self::fail('Задачі ' . $taskId . ' немає на дошці');
    }

    private function user(WorkRole $role, string $name, ?Department $department = null): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId(self::PREFIX . $name . '-' . uniqid())
            ->setFirstName(ucfirst($name))
            ->setLastName('Тестовий')
            ->setWorkRole($role)
            ->setDepartment($department)
            ->setEmail(self::PREFIX . $name . uniqid() . '@example.test');
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function upload(string $path, string $mime): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'up');
        copy($path, $copy);

        return new UploadedFile($copy, basename($path), $mime, null, true);
    }

    /** @return array<string, mixed> */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function send(string $url, array $body): array
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));
        self::assertResponseStatusCodeSame(201);

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
