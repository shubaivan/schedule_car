<?php

namespace App\Tasks\Command;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Counterparty\Demo\DemoCounterparties;
use App\Plan\Drive\DriveSync;
use App\Plan\EventStore\PlanEventRecorder;
use App\Supply\Entity\Department;
use App\Supply\Enum\SupplyRole;
use App\Supply\Repository\DepartmentRepository;
use App\Supply\Repository\SupplierRepository;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\AttachFile;
use App\Tasks\Demo\DemoDeals;
use App\Tasks\Demo\DemoFiles;
use App\Tasks\Demo\DemoSupply;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskLabel;
use App\Tasks\Entity\TaskMessage;
use App\Tasks\Entity\TaskMessageEdit;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Service\DeferredPreviews;
use App\Tasks\Service\TaskService;
use App\Warehouse\Demo\DemoWarehouse;
use App\Warehouse\Service\ActivityLog;
use App\Warehouse\Service\DocumentStore;
use App\Warehouse\Service\RecordMovement;
use DateTime;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
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
 *     printf '%s\n' "$PASS" | php bin/console tasks:demo-seed --password-stdin   # бойовий сервер: пароля нема в `ps` та історії
 *
 * Лише на демо-стенді: команда відмовиться, якщо в базі є живі люди з бота
 * (telegram_id не «demo-»). --reset прибирає попередні демо-дані.
 * Засіяні файли на Google Диск не йдуть (drive_state = skipped, DriveSync::keepOffDrive).
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
        private PlanEventRecorder $events,
        private DeferredPreviews $deferred,
        private UserPasswordHasherInterface $hasher,
        #[Autowire('%kernel.project_dir%/demo/tasks')]
        private string $files,
        #[Autowire('%kernel.environment%')]
        private string $environment = 'dev',
        /** На prod засів дозволено лише там, де явно сказано: це демо-стенд (PLAN_DEMO_STAND=1). */
        #[Autowire('%env(default::PLAN_DEMO_STAND)%')]
        private ?string $demoStand = null,
        // Демо-заявки (DemoSupply). Наприкінці й необовʼязкові — щоб не ламати існуючі виклики конструктора.
        private ?SupplyRequestRepository $supplyRequests = null,
        private ?SupplierRepository $suppliers = null,
        private ?AttachFile $attachFile = null,
        // Демо-склад (DemoWarehouse) — так само необовʼязково й наприкінці.
        private ?RecordMovement $recordMovement = null,
        private ?ActivityLog $warehouseLog = null,
        private ?DocumentStore $warehouseDocuments = null,
        // Демо-файли не мають їхати на Google Диск клієнта (рішення Івана 15.11).
        private ?DriveSync $drive = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('password', null, InputOption::VALUE_REQUIRED, 'Спільний пароль демо-співробітників (≥ 10 символів)');
        $this->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Прочитати пароль із першого рядка stdin (замість --password)');
        $this->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Домен демо-логінів', 'zavod.test');
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Прибрати попередні демо-задачі й людей');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише перевірити, чи можна');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $conn = $this->em->getConnection();

        // Порожня бойова база (одразу після розгортання) теж «без живих людей» —
        // тому на prod потрібна ще й явна позначка демо-стенду.
        if ($this->environment === 'prod' && $this->demoStand !== '1') {
            $output->writeln('<error>APP_ENV=prod без PLAN_DEMO_STAND=1 — це не демо-стенд. Нічого не роблю.</error>');

            return Command::FAILURE;
        }

        $live = (int) $conn->fetchOne("SELECT COUNT(*) FROM telegram_user WHERE telegram_id NOT LIKE 'demo-%'");

        if ($live > 0) {
            $output->writeln(sprintf('<error>У базі %d живих користувачів — це не демо-стенд. Нічого не роблю.</error>', $live));

            return Command::FAILURE;
        }

        if ($input->getOption('password-stdin')) {
            $stream = ($input instanceof StreamableInputInterface ? $input->getStream() : null) ?? STDIN;
            $password = rtrim((string) fgets($stream), "\r\n");
        } else {
            $password = (string) $input->getOption('password');
        }

        if (mb_strlen($password) < 10) {
            $output->writeln('<error>Пароль (--password або --password-stdin): щонайменше 10 символів.</error>');

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

        // Усе або нічого: якщо засів упаде посередині (GD, шрифт), база лишиться як була.
        // Через журнал подій: сповіщення про засіяні задачі — лише після коміту.
        $requests = 0;
        $warehouse = ['items' => 0, 'movements' => 0];
        $mark = (int) $conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM plan_event');
        $driveMarks = $this->drive?->marks() ?? [];
        $offDrive = 0;
        $counterparties = 0;
        $this->events->transactional(function () use ($input, $password, &$requests, &$warehouse, $driveMarks, &$offDrive, &$counterparties): void {
            $this->reset($input->getOption('reset') === true);
            $this->seedDirectories($password, (string) $input->getOption('domain'));
            $this->seedTasks();
            // Контрагенти з тими самими назвами, що в угодах, — задачі привʼязуються до карток.
            $counterparties = new DemoCounterparties($this->em, $this->events)->seed($this->people);
            // Заявки — в тій самій оболонці «Плану», тож і в демо вони мають бути.
            if ($this->supplyRequests !== null && $this->suppliers !== null && $this->attachFile !== null) {
                $requests = new DemoSupply($this->em, $this->supplyRequests, $this->suppliers, $this->attachFile, $this->files)
                    ->seed($this->people, $this->departments);
            }
            // Склад — теж частина «Плану»: позиції, залишки й місяць рухів.
            if ($this->recordMovement !== null && $this->warehouseLog !== null && $this->warehouseDocuments !== null) {
                $warehouse = new DemoWarehouse($this->em, $this->recordMovement, $this->warehouseLog, $this->warehouseDocuments, $this->files)
                    ->seed($this->people);
            }
            // Демо — не робота: жоден засіяний файл не йде на Диск. У тій самій транзакції,
            // тож воркер, що отримає SyncDriveFiles після коміту, вже бачить skipped.
            $this->em->flush();
            $offDrive = $this->drive?->keepOffDrive($driveMarks) ?? 0;
        });

        $this->deferred->flush();
        $this->alignEventTimes($mark);

        $output->writeln(sprintf('Готово: людей — %d, відділів — %d, міток — %d, угод — %d, заявок — %d, позицій складу — %d, рухів — %d, контрагентів — %d, файлів поза Диском — %d.', count($this->people), count($this->departments), count($this->labels), count(DemoDeals::all()), $requests, $warehouse['items'], $warehouse['movements'], $counterparties, $offDrive));

        return Command::SUCCESS;
    }

    /**
     * Сервіси пишуть події «зараз», а засів потім відсуває задачі, повідомлення й файли
     * в минуле. Щоб Хроніка, сповіщення й аудит демо мали правдиві дати, події засіву
     * (id > $mark) беруть час зі своїх рядків-джерел. Далі за runbook:
     * plan:events:backfill → plan:chronicle:rebuild → plan:search:reindex.
     */
    private function alignEventTimes(int $mark): void
    {
        $conn = $this->em->getConnection();
        // Правки демо-повідомлень (enliven) — через 25 хв після самого повідомлення, а не «зараз».
        $conn->executeStatement("UPDATE task_message_edit e SET edited_at = m.created_at + INTERVAL '25 minutes' FROM task_message m WHERE m.id = e.message_id AND m.task_id IN (SELECT id FROM task)");
        $conn->executeStatement("UPDATE task_message SET edited_at = created_at + INTERVAL '25 minutes' WHERE edited_at IS NOT NULL");
        // Події задач, прив'язані до повідомлення стрічки (створення, статус, файли).
        $conn->executeStatement(
            "UPDATE plan_event e SET occurred_at = m.created_at FROM task_message m
              WHERE e.id > ? AND e.subject_type = 'task' AND e.type NOT IN ('comment_edited', 'comment_deleted')
                AND e.payload->>'refId' IS NOT NULL AND m.id = (e.payload->>'refId')::int",
            [$mark],
        );
        // Правка — час правки (той самий, що в task_message_edit: backfill за ним упізнає подію й не дублює).
        $conn->executeStatement(
            "UPDATE plan_event e SET occurred_at = m.edited_at FROM task_message m
              WHERE e.id > ? AND e.type = 'comment_edited' AND m.id = (e.payload->>'refId')::int AND m.edited_at IS NOT NULL",
            [$mark],
        );
        // Файли заявок — час самого файлу.
        $conn->executeStatement(
            "UPDATE plan_event e SET occurred_at = a.created_at FROM supply_attachment a
              WHERE e.id > ? AND e.subject_type = 'supply' AND e.type = 'file_added' AND a.id = (e.payload->'fileIds'->>0)::int",
            [$mark],
        );
        // Події засіву — це «історія» демо: plan:events:backfill не має брати їх за початок
        // живого журналу (інакше старші повідомлення задач не перенесе).
        $conn->executeStatement("UPDATE plan_event SET payload = payload || '{\"demoSeed\": true}'::jsonb WHERE id > ?", [$mark]);
        // Рядки-джерела пошуку (search_document.created_at, фасет дат): файли задач — з повідомленням,
        // склад — коли його записали в журнал складу, люди — за півтора місяця до демо.
        $conn->executeStatement('UPDATE task_attachment a SET created_at = m.created_at FROM task_message m WHERE m.id = a.message_id');
        $conn->executeStatement(
            "UPDATE wh_item i SET created_at = e.at, updated_at = e.at FROM (SELECT subject_id, MIN(occurred_at) AS at FROM plan_event
              WHERE subject_type = 'wh_item' AND id > ? GROUP BY subject_id) e WHERE e.subject_id = i.id",
            [$mark],
        );
        $conn->executeStatement(
            "UPDATE wh_movement mv SET created_at = COALESCE(e.at, mv.occurred_at + INTERVAL '10 hours'), updated_at = COALESCE(e.at, mv.occurred_at + INTERVAL '10 hours')
               FROM wh_movement x LEFT JOIN (SELECT subject_id, MIN(occurred_at) AS at FROM plan_event WHERE subject_type = 'wh_movement' AND id > ? GROUP BY subject_id) e ON e.subject_id = x.id
              WHERE x.id = mv.id",
            [$mark],
        );
        $conn->executeStatement(
            "UPDATE wh_document d SET created_at = COALESCE(mv.created_at, i.created_at + INTERVAL '15 minutes', d.created_at), updated_at = COALESCE(mv.created_at, i.created_at + INTERVAL '15 minutes', d.created_at)
               FROM wh_document x LEFT JOIN wh_movement mv ON mv.id = x.movement_id LEFT JOIN wh_item i ON i.id = x.item_id WHERE x.id = d.id",
        );
        $start = $conn->fetchOne('SELECT MIN(created_at) FROM wh_item');
        if ($start !== false && $start !== null) {
            $conn->executeStatement("UPDATE wh_client SET created_at = CAST(? AS timestamp) - INTERVAL '1 day', updated_at = CAST(? AS timestamp) - INTERVAL '1 day'", [$start, $start]);
            $conn->executeStatement("UPDATE wh_site SET created_at = CAST(? AS timestamp) - INTERVAL '1 day', updated_at = CAST(? AS timestamp) - INTERVAL '1 day'", [$start, $start]);
        }
        $conn->executeStatement("UPDATE telegram_user SET created_at = DATE_TRUNC('day', LOCALTIMESTAMP) - INTERVAL '45 days' + id * INTERVAL '7 minutes' WHERE telegram_id LIKE 'demo-%'");
        // Рядок центру сповіщень — тоді ж, коли й подія.
        $conn->executeStatement('UPDATE notification n SET created_at = e.occurred_at FROM plan_event e WHERE e.id = n.event_id AND e.id > ?', [$mark]);
    }

    private function reset(bool $reset): void
    {
        $conn = $this->em->getConnection();

        if ($reset) {
            DemoCounterparties::reset($this->em);
            $conn->executeStatement('DELETE FROM task');
            $conn->executeStatement('DELETE FROM task_label');
            // Склад — до людей: рухи й журнал складу прив'язані до демо-людей.
            DemoWarehouse::reset($this->em);
            DemoSupply::reset($this->em);
            $conn->executeStatement("UPDATE telegram_user SET archived_by_id = NULL WHERE telegram_id LIKE 'demo-%'");
            $conn->executeStatement("DELETE FROM login_token WHERE user_id IN (SELECT id FROM telegram_user WHERE telegram_id LIKE 'demo-%')");
            // Журнал входів демо-людей: без цього рядки лишаються з user_id = NULL, і backfill
            // показує успішні входи як «Невдалий вхід» невідомого.
            $conn->executeStatement("DELETE FROM crm_login WHERE user_id IN (SELECT id FROM telegram_user WHERE telegram_id LIKE 'demo-%') OR (success AND user_id IS NULL)");
            $conn->executeStatement("DELETE FROM telegram_user WHERE telegram_id LIKE 'demo-%'");
            self::pruneOrphanEvents($conn);
            // Номери задач (№ 0001…) — це id: після очищення починаємо знову з першого,
            // а не з №0143 після кількох пересівів. Лише коли таблиця справді порожня.
            if ((int) $conn->fetchOne('SELECT COUNT(*) FROM task') === 0) {
                $conn->executeStatement("SELECT setval('task_id_seq', 1, false)");
            }

            $this->em->clear();
        }
    }

    /**
     * Журнал подій демо-стенду без сиріт: події задач, заявок і людей, яких уже немає
     * (інакше після кожного --reset у журналі аудиту й історії лишаються рядки
     * попередніх засівів — без автора, на неіснуючі задачі).
     */
    public static function pruneOrphanEvents(Connection $conn): int
    {
        return (int) $conn->executeStatement(
            "DELETE FROM plan_event e WHERE
                (e.subject_type = 'task' AND NOT EXISTS (SELECT 1 FROM task t WHERE t.id = e.subject_id))
             OR (e.subject_type = 'supply' AND NOT EXISTS (SELECT 1 FROM supply_request r WHERE r.id = e.subject_id))
             OR (e.subject_type = 'user' AND NOT EXISTS (SELECT 1 FROM telegram_user u WHERE u.id = e.subject_id))
             OR (e.subject_type = 'counterparty' AND NOT EXISTS (SELECT 1 FROM counterparty c WHERE c.id = e.subject_id))",
        );
    }

    private function seedDirectories(string $password, string $domain): void
    {
        foreach (self::DEPARTMENTS as $name) {
            $department = $this->departmentRepository->findOneByName($name) ?? new Department()->setName($name);
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
            $user = new TelegramUser()
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
                WorkRole::Admin => SupplyRole::Admin,
                WorkRole::Director => SupplyRole::Director,
                default => SupplyRole::Worker,
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
        new Filesystem()->mkdir($tmp);

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
                    if ($task->hasMember($this->people[$who]) || $this->people[$who]->seesAllTasks()) {
                        $this->tasks->markRead($task, $this->people[$who]);
                    }
                }
            }

            $this->em->flush();
            $this->enliven();
        } finally {
            new Filesystem()->remove($tmp);
        }
    }

    /** Логіни, під якими показують демо: кожен має побачити живу Хроніку й «хто прочитав». */
    public const DEMO_LOGINS = ['admin', 'dyrektor', 'prodazhi', 'buhgalter', 'vyrobnytstvo'];

    /**
     * Робочий документ, а не голий чат: відповіді з цитатою, закріплене, правки з історією,
     * ★ на повідомленнях і задачах, різні межі прочитаного у колег (кружечки «хто прочитав»)
     * і непрочитане «+N» у кожного демо-логіна. Детерміновано — однаково на кожному засіві.
     */
    private function enliven(): void
    {
        /** @var list<Task> $tasks */
        $tasks = $this->em->getRepository(Task::class)->findBy([], ['id' => 'ASC']);
        $admin = $this->people['admin'];
        $favorites = array_fill_keys(self::DEMO_LOGINS, 0);
        $stars = array_fill_keys(self::DEMO_LOGINS, 0);

        foreach ($tasks as $i => $task) {
            /** @var list<TaskMessage> $all */
            $all = $this->em->getRepository(TaskMessage::class)->findBy(['task' => $task], ['id' => 'ASC']);
            $comments = array_values(array_filter($all, static fn (TaskMessage $m) => ! $m->isEvent() && $m->getDeletedAt() === null));
            $count = count($comments);

            // Відповідь із цитатою: друге повідомлення відповідає першому.
            if ($count >= 2 && $i % 2 === 0 && $comments[1]->getAuthor() !== $comments[0]->getAuthor()) {
                $comments[1]->setReplyTo($comments[0]);
            }

            if ($count >= 4 && $i % 3 === 0 && $comments[3]->getAuthor() !== $comments[2]->getAuthor()) {
                $comments[3]->setReplyTo($comments[2]);
            }

            // Закріплене: перший документ задачі (специфікація, рахунок), інколи ще й останнє слово.
            if ($count >= 3 && $i % 3 !== 2) {
                $withFiles = array_values(array_filter($comments, static fn (TaskMessage $m) => ! $m->getAttachments()->isEmpty()));
                ($withFiles[0] ?? $comments[0])->pin($task->getCreator());

                if ($i % 5 === 0 && $comments[$count - 1] !== ($withFiles[0] ?? $comments[0])) {
                    $comments[$count - 1]->pin($task->getCreator());
                }
            }

            // Правка з історією: виправили кількість (перше число в тексті).
            if ($count >= 3 && $i % 4 === 1) {
                foreach (array_reverse($comments) as $m) {
                    if (preg_match('/\d+/', $m->getText()) === 1) {
                        $old = (string) preg_replace_callback('/\d+/', static fn (array $d) => (string) ((int) $d[0] + 2), $m->getText(), 1);
                        $this->em->persist(new TaskMessageEdit($m, $m->getAuthor(), $old));
                        $m->edit($m->getText());

                        break;
                    }
                }
            }

            // Адмін — не учасник угод; у кількох він стежить (учасник), щоб і в нього Хроніка жила.
            if ($i % 6 === 2 && ! $task->hasMember($admin)) {
                $task->addMember($admin);
            }

            // Колеги (не демо-логіни) дочитали хто до кінця, хто до середини — звідси кружечки «хто прочитав».
            foreach ($task->getMembers() as $member) {
                $user = $member->getUser();
                $login = substr((string) $user->getTelegramId(), 5);

                if (in_array($login, self::DEMO_LOGINS, true) || $count === 0) {
                    continue;
                }

                $upTo = (crc32($login . $i) % 3 === 0) ? $comments[intdiv($count - 1, 2)] : $comments[$count - 1];
                $this->tasks->markRead($task, $user, (int) $upTo->getId());
            }

            foreach (self::DEMO_LOGINS as $login) {
                $user = $this->people[$login];
                $involved = $task->getCreator() === $user || $task->hasMember($user);

                // ★ задачі: дві відкриті угоди кожному (адмін і директор — і не свої: вони бачать усе).
                if ($favorites[$login] < 2 && ! $task->getStatus()->isClosed() && ($involved || ($login === 'admin' && $i % 4 === 0))) {
                    $this->tasks->favorite($task, $user, true);
                    ++$favorites[$login];
                }

                // ★ повідомлення: перший чужий документ у задачі, де людина бере участь.
                if ($stars[$login] < 2 && $involved) {
                    foreach ($comments as $m) {
                        if ($m->getAuthor() !== $user && ! $m->getAttachments()->isEmpty()) {
                            $this->tasks->star($m, $user, true);
                            ++$stars[$login];

                            break;
                        }
                    }
                }
            }
        }

        $this->em->flush();
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
        $task->setDueAt($due === null ? null : new DateTime('today 17:00', new DateTimeZone('Europe/Kyiv'))->modify($due));

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
        $this->tasks->markRead($task, $author, (int) $message->getId());
    }

    private function event(Task $task, string $who, int $hoursAgo, string $text): void
    {
        $message = new TaskMessage($task, $this->people[$who], $text, TaskMessage::KIND_EVENT);
        $message->setCreatedAt($this->at($hoursAgo));
        // Тип події — як у живих змін статусу: Хроніка показує закриття інакше, ніж зміну статусу.
        $closed = array_filter(TaskStatus::cases(), static fn (TaskStatus $s) => $s->isClosed() && str_ends_with($text, '→ ' . $s->label()));
        $message->setEventType($closed !== [] ? TaskMessage::EVENT_CLOSED : TaskMessage::EVENT_STATUS);
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
