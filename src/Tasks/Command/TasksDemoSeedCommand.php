<?php

namespace App\Tasks\Command;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Entity\Department;
use App\Supply\Repository\DepartmentRepository;
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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Демо-дані для показу розділу «Задачі»: відділи, співробітники (вигадані
 * імена), мітки, угоди з чатом, фото й документами.
 *
 *     php bin/console tasks:demo-seed --password='…'
 *
 * Лише на демо-стенді: команда відмовиться, якщо в базі є живі люди з бота
 * (telegram_id не «demo-»). --reset прибирає попередні демо-дані.
 */
#[AsCommand(name: 'tasks:demo-seed', description: 'Заповнити демо-стенд задач прикладами')]
class TasksDemoSeedCommand extends Command
{
    private const DEPARTMENTS = [
        '1. Дирекція', '2. Відділ побудови', '3. Відділ продажу', '4. Фінансовий відділ', '5. Відділ закупок',
        '6. Технічний відділ', '7. Виробництво', '8. Відділ логістики', '9. Відділ економіки',
    ];

    /** login => [імʼя, прізвище, посада, роль, відділ] — імена вигадані. */
    private const PEOPLE = [
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

    private const LABELS = [
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

        $output->writeln(sprintf('Готово: %d людей, %d відділів, %d міток, задачі з файлами.', count($this->people), count($this->departments), count($this->labels)));

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
        $p = $this->people;

        // 1. Велика угода в роботі: специфікація, фото зі складу, рахунок.
        $t = $this->task(
            'Плити ПК 60-12 для ЖК «Сонячний» — 48 шт',
            'prodazhi',
            TaskStatus::InWork,
            -6,
            '+5 days',
            'ТОВ «Будінвест-Демо»',
            ['Терміново', 'Доставка'],
            ['3. Відділ продажу', '7. Виробництво'],
            ['buhgalter', 'viktor.polishchuk', 'bohdan.zakharchenko'],
            "Замовник просить 48 плит ПК 60-12-8 і 12 шт ПК 63-15-8. Відвантаження двома партіями.\nПотрібно: підтвердити строки виробництва, виставити рахунок, спланувати доставку.",
        );
        $this->say($t, 'prodazhi', -6 * 24 + 1, 'Колеги, специфікація від замовника в додатку. Серія 1.141-1, бетон B25.', ['Специфікація_ПК_60-12.pdf']);
        $this->say($t, 'viktor.polishchuk', -6 * 24 + 3, 'Переглянув. Армування стандартне, по ПК 63-15 потрібне креслення петель — уточню з ПТО.');
        $this->say($t, 'vyrobnytstvo', -5 * 24, 'На складі є 22 шт ПК 60-12 з минулої партії, решту формуємо з понеділка. Фото складу:', ['Плити_склад.jpg', 'Плити_відвантаження.jpg']);
        $this->say($t, 'buhgalter', -4 * 24, 'Рахунок виставлено, 50% передоплата.', ['Рахунок_на_оплату_118.pdf']);
        $this->say($t, 'prodazhi', -3 * 24, 'Замовник оплатив передоплату, гроші на рахунку. @Богдан Захарченко, плануй перший рейс на понеділок.', [], ['bohdan.zakharchenko']);
        $this->say($t, 'bohdan.zakharchenko', -2 * 24, 'Графік на тиждень — у таблиці. Два маніпулятори 17 і 18 листопада.', ['Графік_доставки_тиждень_47.xlsx']);
        $this->say($t, 'mykola.rudenko', -20, 'Перша партія ПК 63-15 відформована, пропарка до ранку.', ['Цех_ЗБВ.jpg']);

        // 2. Нова заявка — бачать лише позначені в ній.
        $t = $this->task(
            'Перемички 2ПБ 19-3 для приватного будинку',
            'maryna.tkachenko',
            TaskStatus::New,
            -1,
            '+7 days',
            'Приватний замовник, вул. Заводська 5',
            ['Може почекати'],
            [],
            ['vyrobnytstvo', 'bohdan.zakharchenko'],
            '40 перемичок 2ПБ 19-3, самовивіз або доставка бортовим авто.',
        );
        $this->say($t, 'maryna.tkachenko', -22, 'Клієнт надіслав фото маркування з минулого замовлення — потрібні такі самі.', ['Перемички_маркування.jpg']);
        $this->say($t, 'vyrobnytstvo', -5, 'Є в наявності 26 шт, решту зробимо за 3 дні.');

        // 3. Чекає рішення директора: КП і кошторис (офісні превʼю).
        $t = $this->task(
            'Сходові марші ЛМ для 9-поверхівки — погодження ціни',
            'prodazhi',
            TaskStatus::Approval,
            -3,
            '+2 days',
            'ТОВ «Житлобуд-Демо»',
            ['Звернути увагу', 'Чекаємо оплату'],
            ['3. Відділ продажу', '9. Відділ економіки'],
            ['dyrektor'],
            'Замовник просить знижку 3% при оплаті до 25.11. Економіка порахувала маржу — потрібне рішення директора.',
        );
        $this->say($t, 'prodazhi', -70, 'Комерційна пропозиція для замовника:', ['Комерційна_пропозиція_КП-77.docx']);
        $this->say($t, 'tetiana.kravets', -50, 'Маржа зі знижкою 3% — 18,4%. Нижче нашої планки 20%, але обсяг великий.');
        $this->say($t, 'viktor.polishchuk', -30, 'Форми для ЛМ 30.11 вільні з 20.11, строк 12 днів реальний.', ['Сходовий_марш_монтаж.jpg']);
        $this->event($t, 'prodazhi', -26, 'змінив(ла) статус: В роботі → На погодженні');
        $this->say($t, 'prodazhi', -25, '@Ірина Бондаренко, прошу погодити знижку 3%.', [], ['dyrektor']);

        // 4. Погоджено — металокаркас.
        $t = $this->task(
            'Металокаркас складу 24×60 м',
            'olha.petrenko',
            TaskStatus::Approved,
            -9,
            '+12 days',
            'ФОП Демченко (демо)',
            ['Потрібне креслення'],
            ['2. Відділ побудови', '5. Відділ закупок'],
            ['yurii.kuzmenko', 'ihor.marchenko'],
            'Каркас легкого складу, 12 рам. Метал закуповуємо під замовлення.',
        );
        $this->say($t, 'ihor.marchenko', -8 * 24, 'Візуалізація каркаса від проєктувальника:', ['Металокаркас_24x60.jpg']);
        $this->say($t, 'yurii.kuzmenko', -6 * 24, 'Пробний вузол зварили, шов рівний. Можна запускати серію.', ['Зварювання_вузла.jpg']);
        $this->event($t, 'dyrektor', -5 * 24, 'змінив(ла) статус: На погодженні → Погоджено');
        $this->say($t, 'dyrektor', -5 * 24 + 1, 'Погоджую. Закупівлю металу — сьогодні.');

        // 5. Виконана — лабораторія.
        $t = $this->task(
            'Випробування бетону B25 — партія 11/14',
            'viktor.polishchuk',
            TaskStatus::Done,
            -7,
            '-2 days',
            null,
            [],
            ['6. Технічний відділ'],
            ['vyrobnytstvo'],
            'Контроль міцності на 7 добу перед відвантаженням.',
        );
        $this->say($t, 'viktor.polishchuk', -6 * 24, 'Зразки відібрано, кубики в камері.', ['Лабораторія_проба.jpg']);
        $this->say($t, 'viktor.polishchuk', -3 * 24, 'Протокол: середня міцність 26,4 МПа. Партію допущено.', ['Протокол_випробувань_В25.pdf']);
        $this->event($t, 'viktor.polishchuk', -3 * 24 + 1, 'змінив(ла) статус: В роботі → Виконана');

        // 6. Очікування — розчин.
        $t = $this->task(
            'Розчин М100 — 12 м³ на обʼєкт',
            'dmytro.lysenko',
            TaskStatus::Waiting,
            -2,
            '+4 days',
            'ТОВ «Майстер-Буд-Демо»',
            ['Може почекати'],
            [],
            ['vyrobnytstvo', 'dyrektor'],
            'Клієнт ще не визначився з датою, просить тримати ціну.',
        );
        $this->say($t, 'dmytro.lysenko', -40, 'Фото з обʼєкта від клієнта:', ['Розчин_М100.jpg']);
        $this->event($t, 'dyrektor', -30, 'змінив(ла) статус: На погодженні → Очікування');
        $this->say($t, 'dyrektor', -29, 'Тримаємо ціну до кінця місяця, далі — новий прайс.');

        // 7. Прострочена рекламація.
        $t = $this->task(
            'Рекламація: скол на плиті ПК 63-15',
            'maryna.tkachenko',
            TaskStatus::InWork,
            -5,
            '-1 day',
            'ТОВ «Будінвест-Демо»',
            ['Терміново', 'Звернути увагу'],
            ['7. Виробництво'],
            ['viktor.polishchuk'],
            'Замовник надіслав фото пошкодження після розвантаження. Треба розібратись і відповісти.',
        );
        $this->say($t, 'maryna.tkachenko', -4 * 24, 'Фото від замовника:', ['Бетонний_вузол.jpg']);
        $this->say($t, 'viktor.polishchuk', -3 * 24, 'Скол на торці, схоже на удар під час строповки. Потрібні фото з машини.');
        $this->say($t, 'pavlo.lytvyn', -3 * 24 + 2, 'Водій каже, що вантажили без прокладок. Фото з машини не робили.');

        // 8. Відхилено — тендер.
        $t = $this->task(
            'Тендер: ЗБВ для школи (Prozorro)',
            'dmytro.lysenko',
            TaskStatus::Rejected,
            -12,
            null,
            'Відділ освіти (демо)',
            [],
            ['3. Відділ продажу'],
            ['dyrektor', 'tetiana.kravets'],
            'Пакет документів на участь у тендері.',
        );
        $this->say($t, 'tetiana.kravets', -11 * 24, 'Ціна тендера нижча за собівартість на 6%.');
        $this->event($t, 'dyrektor', -10 * 24, 'змінив(ла) статус: На погодженні → Відхилено');
        $this->say($t, 'dyrektor', -10 * 24 + 1, 'Не беремо. Повернемось, якщо переоголосять.');

        $this->em->flush();

        // Директор частину вже читав — щоб лічильники непрочитаного виглядали живо.
        foreach ($this->em->getRepository(Task::class)->findAll() as $task) {
            if ($task->getStatus() === TaskStatus::Done || $task->getStatus() === TaskStatus::Rejected) {
                foreach (['dyrektor', 'prodazhi', 'admin'] as $who) {
                    $this->tasks->markRead($task, $p[$who]);
                }
            }
        }
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
     * @param string[] $files
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

        foreach ($files as $file) {
            $path = $this->files . '/' . $file;

            if (! is_file($path)) {
                throw new RuntimeException('Немає демо-файлу ' . $path);
            }

            $this->tasks->attach($message, $author, $path, $file, (string) mime_content_type($path));
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
