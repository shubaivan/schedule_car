<?php

namespace App\Tasks\Command;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Entity\Department;
use App\Supply\Repository\DepartmentRepository;
use App\Tasks\Demo\DemoDeals;
use App\Tasks\Demo\DemoFiles;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskLabel;
use App\Tasks\Entity\TaskMessage;
use App\Tasks\Entity\TaskRead;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\DeferredPreviews;
use App\Tasks\Service\TaskService;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Демо-дані для показу «Плану»: відділи, співробітники (вигадані імена),
 * мітки й ~23 угоди заводу (DemoDeals) з чатом, фото й документами.
 * Частину файлів (PDF, «фото») малює DemoFiles прямо під час засіву.
 *
 *     php bin/console tasks:demo-seed --password='…'
 *
 * Лише на демо-стенді: команда відмовиться, якщо в базі є живі люди з бота
 * (telegram_id не «demo-»). --reset прибирає попередні демо-дані.
 */
#[AsCommand(name: 'tasks:demo-seed', description: 'Заповнити демо-стенд задач прикладами')]
class TasksDemoSeedCommand extends Command
{
    public const DEPARTMENTS = [
        '1. Дирекція', '2. Відділ побудови', '3. Відділ продажу', '4. Фінансовий відділ', '5. Відділ закупок',
        '6. Технічний відділ', '7. Виробництво', '8. Відділ логістики', '9. Відділ економіки',
    ];

    /** login => [імʼя, прізвище, посада, роль, відділ] — імена вигадані. */
    public const PEOPLE = [
        'admin' => ['Андрій', 'Коваль', 'Адміністратор системи', WorkRole::Admin, '1. Дирекція'],
        'dyrektor' => ['Ірина', 'Бондаренко', 'Директор', WorkRole::Director, '1. Дирекція'],
        'prodazhi' => ['Максим', 'Савчук', 'Менеджер з продажу', WorkRole::Manager, '3. Відділ продажу'],
        'maryna.tkachenko' => ['Марина', 'Ткаченко', 'Менеджер з продажу', WorkRole::Manager, '3. Відділ продажу'],
        'dmytro.lysenko' => ['Дмитро', 'Лисенко', 'Менеджер лідогенерації', WorkRole::Manager, '3. Відділ продажу'],
        'buhgalter' => ['Оксана', 'Шевчук', 'Головний бухгалтер', WorkRole::Accountant, '4. Фінансовий відділ'],
        'tetiana.kravets' => ['Тетяна', 'Кравець', 'Економіст', WorkRole::Accountant, '9. Відділ економіки'],
        'olha.petrenko' => ['Ольга', 'Петренко', 'Менеджер із закупівель', WorkRole::Manager, '5. Відділ закупок'],
        'viktor.polishchuk' => ['Віктор', 'Поліщук', 'Технолог', WorkRole::Production, '6. Технічний відділ'],
        'vyrobnytstvo' => ['Сергій', 'Гончар', 'Начальник цеху ЗБВ', WorkRole::Production, '7. Виробництво'],
        'mykola.rudenko' => ['Микола', 'Руденко', 'Майстер дільниці', WorkRole::Production, '7. Виробництво'],
        'yurii.kuzmenko' => ['Юрій', 'Кузьменко', 'Майстер арматурного цеху', WorkRole::Production, '7. Виробництво'],
        'bohdan.zakharchenko' => ['Богдан', 'Захарченко', 'Логіст', WorkRole::Manager, '8. Відділ логістики'],
        'ihor.marchenko' => ['Ігор', 'Марченко', 'Інженер ПТО', WorkRole::Production, '2. Відділ побудови'],
        'pavlo.lytvyn' => ['Павло', 'Литвин', 'Менеджер з продажу', WorkRole::Manager, '3. Відділ продажу'],
    ];

    public const LABELS = [
        ['Терміново', '#ef4444'], ['Звернути увагу', '#f59e0b'], ['Може почекати', '#10b981'],
        ['Чекаємо оплату', '#6366f1'], ['Доставка', '#0ea5e9'], ['Потрібне креслення', '#8b5cf6'],
    ];

    /** @var array<string, TelegramUser> */
    private array $people = [];
    /** @var array<string, Department> */
    private array $departments = [];
    /** @var array<string, TaskLabel> */
    private array $labels = [];

    public function __construct(
        private EntityManagerInterface $em,
        private DepartmentRepository $departmentRepository,
        private TaskService $tasks,
        private DeferredPreviews $deferred,
        private UserPasswordHasherInterface $hasher,
        #[Autowire('%kernel.project_dir%/demo/tasks')]
        private string $files,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('password', null, InputOption::VALUE_REQUIRED, 'Спільний пароль демо-співробітників (≥ 10 символів)');
        $this->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Домен демо-логінів', 'zavod.test');
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Прибрати попередні демо-задачі й людей');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише перевірити, чи можна');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $conn = $this->em->getConnection();
        $live = (int) $conn->fetchOne("SELECT COUNT(*) FROM telegram_user WHERE telegram_id NOT LIKE 'demo-%'");

        if ($live > 0) {
            $output->writeln(sprintf('<error>У базі %d живих користувачів — це не демо-стенд. Нічого не роблю.</error>', $live));

            return Command::FAILURE;
        }

        $password = (string) $input->getOption('password');

        if (mb_strlen($password) < 10) {
            $output->writeln('<error>--password: щонайменше 10 символів.</error>');

            return Command::INVALID;
        }

        if ($input->getOption('dry-run')) {
            $output->writeln('Можна: живих користувачів немає.');

            return Command::SUCCESS;
        }

        $existing = (int) $conn->fetchOne('SELECT COUNT(*) FROM task');

        if ($existing > 0 && ! $input->getOption('reset')) {
            $output->writeln('<error>Задачі вже є. Запустіть з --reset, щоб перестворити.</error>');

            return Command::FAILURE;
        }

        if ($input->getOption('reset')) {
            $conn->executeStatement('DELETE FROM task');
            $conn->executeStatement('DELETE FROM task_label');
            $conn->executeStatement("UPDATE telegram_user SET archived_by_id = NULL WHERE telegram_id LIKE 'demo-%'");
            $conn->executeStatement("DELETE FROM login_token WHERE user_id IN (SELECT id FROM telegram_user WHERE telegram_id LIKE 'demo-%')");
            $conn->executeStatement("DELETE FROM telegram_user WHERE telegram_id LIKE 'demo-%'");
            $this->em->clear();
        }

        $this->seedDirectories($password, (string) $input->getOption('domain'));
        $this->seedTasks();
        $this->deferred->flush();

        $output->writeln(sprintf('Готово: людей — %d, відділів — %d, міток — %d, угод — %d.', count($this->people), count($this->departments), count($this->labels), count(DemoDeals::all())));

        return Command::SUCCESS;
    }

    private function seedDirectories(string $password, string $domain): void
    {
        foreach (self::DEPARTMENTS as $name) {
            $department = $this->departmentRepository->findOneByName($name) ?? (new Department())->setName($name);
            $department->setActive(true);
            $this->em->persist($department);
            $this->departments[$name] = $department;
        }

        foreach (self::LABELS as $i => [$name, $color]) {
            $this->labels[$name] = new TaskLabel($name, $color, $i);
            $this->em->persist($this->labels[$name]);
        }

        $n = 0;

        foreach (self::PEOPLE as $login => [$first, $last, $position, $role, $department]) {
            ++$n;
            $user = (new TelegramUser())
                ->setTelegramId('demo-' . $login)
                ->setFirstName($first)
                ->setLastName($last)
                ->setPosition($position)
                ->setWorkRole($role)
                ->setDepartment($this->departments[$department])
                ->setEmail($login . '@' . $domain)
                ->setPhoneNumber(sprintf('3800000000%02d', $n))
                ->setLanguageCode('uk');
            $user->setSupplyRole(match ($role) {
                WorkRole::Admin => \App\Supply\Enum\SupplyRole::Admin,
                WorkRole::Director => \App\Supply\Enum\SupplyRole::Director,
                default => \App\Supply\Enum\SupplyRole::Worker,
            });
            $user->decideAccess(AccessStatus::Approved, null);
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $user->touchLastSeen();
            $this->em->persist($user);
            $this->people[$login] = $user;
        }

        $this->em->flush();

        // У кошику — як у ПланФіксі: людина пішла, її листування лишилось.
        $this->people['pavlo.lytvyn']->archive($this->people['admin']);
        $this->em->flush();
    }

    private function seedTasks(): void
    {
        $files = new DemoFiles();
        $generated = DemoDeals::generated();
        $tmp = sys_get_temp_dir() . '/plan-demo-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($tmp);

        try {
            foreach (DemoDeals::all() as $i => $deal) {
                $task = $this->task(
                    $deal['title'],
                    $deal['creator'],
                    $deal['status'],
                    $deal['days'],
                    $deal['due'],
                    $deal['client'],
                    $deal['labels'],
                    $deal['departments'],
                    $deal['members'],
                    $deal['description'],
                );

                foreach ($deal['messages'] as $row) {
                    if ($row[0] === '@event') {
                        $this->event($task, $row[1], $row[2], $row[3]);

                        continue;
                    }

                    $paths = [];

                    foreach ($row[3] ?? [] as $name) {
                        $paths[$name] = $this->demoFile($name, $generated, $files, $tmp, $i);
                    }

                    $this->say($task, $row[0], $row[1], $row[2], $paths, $row[4] ?? []);
                }

                $this->em->flush();

                // Закриті угоди всі прочитали; у відкритих лишаємо «+N», щоб Хроніка жила.
                $readers = $deal['read'] ?? [];

                if ($task->getStatus()->isClosed()) {
                    $readers = [...$readers, 'dyrektor', 'prodazhi', 'admin'];
                }

                foreach (array_unique($readers) as $who) {
                    if ($task->hasMember($this->people[$who]) || $this->people[$who]->getWorkRole()->seesAllTasks()) {
                        $this->tasks->markRead($task, $this->people[$who]);
                    }
                }
            }

            $this->em->flush();
        } finally {
            (new Filesystem())->remove($tmp);
        }
    }

    /** @param array<string, mixed> $generated */
    private function demoFile(string $name, array $generated, DemoFiles $files, string $tmp, int $seed): string
    {
        $path = $this->files . '/' . $name;

        if (is_file($path)) {
            return $path;
        }

        $spec = $generated[$name] ?? throw new RuntimeException('Немає демо-файлу ' . $name);
        $path = $tmp . '/' . $name;

        if (! is_file($path)) {
            if ($spec[0] === 'pdf') {
                $files->pdf($path, $spec[1]);
            } else {
                $files->photo($path, $spec[1], $spec[2], $seed + 1);
            }
        }

        return $path;
    }

    /**
     * @param string[] $labels
     * @param string[] $departments
     * @param string[] $members
     */
    private function task(string $title, string $creator, TaskStatus $status, int $daysAgo, ?string $due, ?string $client, array $labels, array $departments, array $members, string $description): Task
    {
        $task = $this->tasks->create($this->people[$creator], [
            'title' => $title,
            'description' => $description,
            'client' => $client,
            'labelIds' => array_map(fn ($l) => $this->labels[$l]->getId(), $labels),
            'departmentIds' => array_map(fn ($d) => $this->departments[$d]->getId(), $departments),
            'memberIds' => array_map(fn ($m) => $this->people[$m]->getId(), $members),
        ]);

        $created = $this->at($daysAgo * 24);
        $task->setCreatedAt($created)->touch($created)->setStatus($status);
        $task->setDueAt($due === null ? null : (new DateTime('today 17:00', new DateTimeZone('Europe/Kyiv')))->modify($due));

        foreach ($this->em->getRepository(TaskMessage::class)->findBy(['task' => $task]) as $message) {
            $message->setCreatedAt($created);
        }

        $this->em->flush();

        return $task;
    }

    /**
     * @param array<string, string> $files імʼя у чаті => шлях до файлу
     * @param string[] $mentions
     */
    private function say(Task $task, string $who, int $hoursAgo, string $text, array $files = [], array $mentions = []): void
    {
        $author = $this->people[$who];

        if (! $task->hasMember($author)) {
            $task->addMember($author);
        }

        $message = new TaskMessage($task, $author, $text, TaskMessage::KIND_COMMENT, array_map(fn ($m) => (int) $this->people[$m]->getId(), $mentions));
        $message->setCreatedAt($this->at($hoursAgo));
        $this->em->persist($message);
        $this->em->flush();

        foreach ($files as $name => $path) {
            $this->tasks->attach($message, $author, $path, $name, (string) mime_content_type($path));
        }

        $task->touch($this->at($hoursAgo));
        $this->em->flush();

        // Автор свої повідомлення прочитав.
        $read = $this->em->getRepository(TaskRead::class)->findOneBy(['task' => $task, 'user' => $author]) ?? new TaskRead($task, $author);
        $read->readUpTo((int) $message->getId());
        $this->em->persist($read);
    }

    private function event(Task $task, string $who, int $hoursAgo, string $text): void
    {
        $message = new TaskMessage($task, $this->people[$who], $text, TaskMessage::KIND_EVENT);
        $message->setCreatedAt($this->at($hoursAgo));
        $this->em->persist($message);
        $task->touch($this->at($hoursAgo));
    }

    private function at(int $hoursAgo): DateTime
    {
        // Робочий час: зсув від 16:00 сьогодні, щоб не було повідомлень о 3-й ночі.
        $base = new DateTime('today 16:00', new DateTimeZone('Europe/Kyiv'));

        if ($base > new DateTime('now', new DateTimeZone('Europe/Kyiv'))) {
            $base->modify('-1 day');
        }

        return $base->modify(sprintf('%+d hours', $hoursAgo));
    }
}
