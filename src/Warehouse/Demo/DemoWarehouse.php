<?php

namespace App\Warehouse\Demo;

use App\Entity\TelegramUser;
use App\Supply\Entity\Supplier;
use App\Supply\Enum\SupplyRole;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Entity\WhCategory;
use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\CategoryScope;
use App\Warehouse\Enum\DocumentType;
use App\Warehouse\Enum\ItemState;
use App\Warehouse\Enum\MovementType;
use App\Warehouse\Enum\SiteKind;
use App\Warehouse\Enum\Tracking;
use App\Warehouse\Service\ActivityLog;
use App\Warehouse\Service\DocumentStore;
use App\Warehouse\Service\RecordMovement;
use DateTime;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Демо-склад для «Плану»: залізобетонні вироби заводу (кільця, плити, ФБС, дорожні
 * плити), матеріали (цемент, арматура, щебінь, пісок), форми й техніка — і місяць
 * рухів: надходження від постачальників і з виробництва, відвантаження клієнтам,
 * повернення, передача в цех, списання бою.
 *
 * Рухи пишемо справжнім RecordMovement: він перевіряє залишки (демо не може
 * «відвантажити більше, ніж є») і пише подію StockMoved — стрічка позиції в
 * «Плані» показує живу історію. Журнал складу — ActivityLog, як у Twig-формі.
 * Потім час подій і журналу зсуваємо назад SQL-ом до дати руху.
 *
 * Імена клієнтів, об'єктів і людей вигадані, але без «(демо)» — стенд і так демо,
 * а дані мають виглядати як справжній завод (рішення Івана 15.11). Ті самі клієнти,
 * що й у задачах, — щоб пошук «усе по клієнту» збирав одне ім'я, а не два.
 * Категорії, які вже є в базі (Опалубка, Техніка, Інструмент), беремо наявні — без дублів.
 */
final class DemoWarehouse
{
    public const CATEGORIES = [
        // [назва, emoji, префікс, характеристики]
        ['Залізобетонні вироби', '🏗️', 'ЗБВ', ['Марка бетону', 'Вага, кг', 'Розміри']],
        ['Матеріали', '🪨', 'МАТ', ['Фасування', 'ГОСТ/ДСТУ']],
        ['Опалубка', '🔲', 'ОП', ['Розміри', 'Матеріал']],
        ['Техніка', '🚜', 'ТХ', ['Держномер', 'Моточаси']],
        ['Інструмент', '🛠', 'ІН', []],
    ];

    /**
     * Назви попередніх засівів (з «(демо)») — щоб --reset прибрав і їх на стенді,
     * засіяному старою версією.
     */
    public const LEGACY_CATEGORIES = ['Залізобетонні вироби (демо)', 'Матеріали (демо)', 'Опалубка і форми (демо)', 'Техніка (демо)', 'Інструмент (демо)'];
    public const LEGACY_SITES = ['Склад готової продукції (демо)', 'Склад матеріалів (демо)', 'Цех ЗБВ (демо)', 'ЖК «Лісовий», секція 2 (демо)', 'Каналізаційна мережа, вул. Польова (демо)', 'Приватний будинок, с. Демо (демо)'];
    public const LEGACY_CLIENTS = ['ТОВ «Будінвест» (демо)', 'КП «Водоканал» (демо)', 'ФОП Гриценко О. В. (демо)'];

    /** Базові категорії позицій із міграції — засів їх використовує, але ніколи не видаляє. */
    public const BASE_CATEGORIES = ['Опалубка', 'Техніка', 'Обладнання', 'Риштування', 'Інструмент', 'Інше'];

    public const WAREHOUSES = ['Склад готової продукції', 'Склад матеріалів'];

    /** [клієнт, ЄДРПОУ, об'єкт, адреса] */
    public const CLIENTS = [
        ['ТОВ «Будінвест»', null, 'ЖК «Лісовий», секція 2', 'вул. Заводська, 5'],
        ['КП «Міськшляхбуд»', null, 'Реконструкція вул. Польової', 'вул. Польова'],
        ['ФОП Мельник О. В.', null, 'Приватний будинок, с. Ракове', 'с. Ракове, вул. Садова, 12'],
    ];

    /** Власний об'єкт без клієнта — цех, куди матеріали йдуть у виробництво. */
    public const WORKSHOP = 'Цех ЗБВ';

    /**
     * [інв. №, назва, категорія (індекс), облік, одиниця, ціна, ставка оренди/добу, характеристики, стан, фото].
     */
    public const ITEMS = [
        ['ЗБВ-0001', 'Кільце колодязне КС 10-9', 0, Tracking::Bulk, 'шт', '1450.00', null, ['Марка бетону' => 'В25', 'Вага, кг' => '600', 'Розміри' => 'Ø1000×890'], ItemState::Active, null],
        ['ЗБВ-0002', 'Кільце колодязне КС 15-9', 0, Tracking::Bulk, 'шт', '2350.00', null, ['Марка бетону' => 'В25', 'Вага, кг' => '1100', 'Розміри' => 'Ø1500×890'], ItemState::Active, null],
        ['ЗБВ-0003', 'Плита перекриття ПК 60-12-8', 0, Tracking::Bulk, 'шт', '9800.00', null, ['Марка бетону' => 'В20', 'Вага, кг' => '2400', 'Розміри' => '5980×1190×220'], ItemState::Active, 'Плити_склад.jpg'],
        ['ЗБВ-0004', 'Плита перекриття ПК 42-15-8', 0, Tracking::Bulk, 'шт', '8600.00', null, ['Марка бетону' => 'В20', 'Розміри' => '4180×1490×220'], ItemState::Active, null],
        ['ЗБВ-0005', 'Блок фундаментний ФБС 24-4-6', 0, Tracking::Bulk, 'шт', '2100.00', null, ['Марка бетону' => 'В15', 'Вага, кг' => '1300', 'Розміри' => '2380×400×580'], ItemState::Active, null],
        ['ЗБВ-0006', 'Блок фундаментний ФБС 12-4-6', 0, Tracking::Bulk, 'шт', '1150.00', null, ['Марка бетону' => 'В15', 'Розміри' => '1180×400×580'], ItemState::Active, null],
        ['ЗБВ-0007', 'Плита дорожня ПД 2-6', 0, Tracking::Bulk, 'шт', '3900.00', null, ['Марка бетону' => 'В30', 'Розміри' => '2000×1000×160'], ItemState::Active, null],
        ['ЗБВ-0008', 'Кришка колодязя ПП 10-1', 0, Tracking::Bulk, 'шт', '780.00', null, ['Розміри' => 'Ø1160×150'], ItemState::Active, null],
        ['МАТ-0001', 'Цемент М500 Д0, мішок 25 кг', 1, Tracking::Bulk, 'міш.', '215.00', null, ['Фасування' => '25 кг'], ItemState::Active, null],
        ['МАТ-0002', 'Арматура Ø12 А500С', 1, Tracking::Bulk, 'кг', '32.00', null, ['ГОСТ/ДСТУ' => 'ДСТУ 3760'], ItemState::Active, null],
        ['МАТ-0003', 'Арматура Ø8 А240С', 1, Tracking::Bulk, 'кг', '34.50', null, [], ItemState::Active, null],
        ['МАТ-0004', 'Щебінь гранітний 5-20', 1, Tracking::Bulk, 'т', '890.00', null, [], ItemState::Active, null],
        ['МАТ-0005', 'Пісок митий', 1, Tracking::Bulk, 'т', '420.00', null, [], ItemState::Active, null],
        ['МАТ-0006', 'Сітка кладочна 50×50, 4 мм', 1, Tracking::Bulk, 'шт', '165.00', null, [], ItemState::Active, null],
        ['ОП-0001', 'Форма металева для кілець КС 10-9', 2, Tracking::Unit, 'шт', '68000.00', null, ['Розміри' => 'Ø1000', 'Матеріал' => 'сталь 6 мм'], ItemState::Active, 'Цех_ЗБВ.jpg'],
        ['ОП-0002', 'Форма для блоків ФБС 24', 2, Tracking::Unit, 'шт', '54000.00', null, ['Матеріал' => 'сталь'], ItemState::Active, null],
        ['ОП-0003', 'Форма для плит ПК 60-12', 2, Tracking::Unit, 'шт', '125000.00', null, [], ItemState::Repair, null],
        ['ТХ-0001', 'Вібратор глибинний ЕВ-270', 3, Tracking::Unit, 'шт', '14500.00', '350.00', ['Моточаси' => '410'], ItemState::Active, null],
        ['ТХ-0002', 'Навантажувач фронтальний XCMG LW300', 3, Tracking::Unit, 'шт', '1850000.00', null, ['Моточаси' => '2870'], ItemState::Active, null],
        ['ІН-0001', 'Нівелір оптичний', 4, Tracking::Unit, 'шт', '9200.00', '120.00', [], ItemState::Active, null],
    ];

    /**
     * Рухи: [днів тому, тип, звідки, куди, хто, № документа, постачальник, примітка, [[інв. №, кількість, ставка?]…]].
     * Місця: 'gp' / 'mat' — склади, 'c0'…'c2' — об'єкти клієнтів, 'shop' — цех.
     */
    public const MOVEMENTS = [
        [30, MovementType::Receipt, null, 'mat', 'olha.petrenko', 'БС-4410', 'ТОВ «Будмаркет-Схід»', null, [['МАТ-0001', 400], ['МАТ-0004', 120], ['МАТ-0005', 80]]],
        [29, MovementType::Receipt, null, 'mat', 'olha.petrenko', null, null, 'Обладнання цеху — облік із запуску складу', [['ТХ-0001', 1], ['ТХ-0002', 1], ['ІН-0001', 1], ['ОП-0001', 1], ['ОП-0002', 1], ['ОП-0003', 1]]],
        [28, MovementType::Receipt, null, 'mat', 'olha.petrenko', 'МП-2190', 'ТОВ «Металопрокат Плюс»', null, [['МАТ-0002', 2400], ['МАТ-0003', 900], ['МАТ-0006', 150]]],
        [27, MovementType::Receipt, null, 'gp', 'vyrobnytstvo', 'ВР-27', null, 'З виробництва, зміни 1–2', [['ЗБВ-0001', 60], ['ЗБВ-0002', 30], ['ЗБВ-0003', 48], ['ЗБВ-0004', 24], ['ЗБВ-0005', 160], ['ЗБВ-0006', 80], ['ЗБВ-0007', 40], ['ЗБВ-0008', 40]]],
        [26, MovementType::Transfer, 'mat', 'shop', 'bohdan.zakharchenko', null, null, 'Форми — у цех на зміну', [['ОП-0001', 1], ['ОП-0002', 1]]],
        [20, MovementType::Shipment, 'gp', 'c0', 'bohdan.zakharchenko', 'ВН-101', null, 'Два рейси маніпулятором', [['ЗБВ-0003', 24], ['ЗБВ-0005', 80]]],
        [18, MovementType::Shipment, 'gp', 'c1', 'bohdan.zakharchenko', 'ВН-104', null, null, [['ЗБВ-0001', 40], ['ЗБВ-0008', 20]]],
        [18, MovementType::Shipment, 'mat', 'c1', 'bohdan.zakharchenko', 'ВН-105', null, 'Вібратор в оренду на час монтажу', [['ТХ-0001', 1, '350']]],
        [15, MovementType::Transfer, 'mat', 'shop', 'olha.petrenko', null, null, 'Матеріали у виробництво', [['МАТ-0001', 120], ['МАТ-0004', 40], ['МАТ-0005', 30]]],
        [12, MovementType::Shipment, 'gp', 'c2', 'bohdan.zakharchenko', 'ВН-108', null, 'Самовивіз клієнтом', [['ЗБВ-0006', 24], ['ЗБВ-0004', 6]]],
        [9, MovementType::Return, 'c1', 'gp', 'bohdan.zakharchenko', 'ПВ-12', null, 'Два кільця не підійшли за висотою', [['ЗБВ-0001', 2]]],
        [9, MovementType::Return, 'c1', 'mat', 'bohdan.zakharchenko', 'ПВ-13', null, 'Вібратор повернули після монтажу', [['ТХ-0001', 1]]],
        [7, MovementType::WriteOff, 'gp', null, 'admin', 'АС-3', null, 'Бій при розвантаженні, акт складено', [['ЗБВ-0002', 2]]],
        [5, MovementType::Receipt, null, 'mat', 'olha.petrenko', 'БС-4477', 'ТОВ «Будмаркет-Схід»', 'Цемент М500 за заявкою виробництва', [['МАТ-0001', 200]]],
        [3, MovementType::Shipment, 'gp', 'c0', 'bohdan.zakharchenko', 'ВН-117', null, null, [['ЗБВ-0003', 18], ['ЗБВ-0005', 40]]],
        [1, MovementType::Transfer, 'mat', 'shop', 'olha.petrenko', null, null, 'Арматура на каркаси плит', [['МАТ-0002', 600], ['МАТ-0006', 40]]],
        [0, MovementType::Shipment, 'gp', 'c1', 'bohdan.zakharchenko', 'ВН-121', null, null, [['ЗБВ-0002', 12]]],
    ];

    /** Скани наклейок із бота: [інв. №, хто, днів тому]. */
    public const SCANS = [
        ['ОП-0001', 'mykola.rudenko', 25],
        ['ТХ-0001', 'bohdan.zakharchenko', 17],
        ['ТХ-0001', 'bohdan.zakharchenko', 9],
        ['ОП-0003', 'viktor.polishchuk', 4],
    ];

    private int $step = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecordMovement $record,
        private readonly ActivityLog $activity,
        private readonly DocumentStore $documents,
        private readonly string $files,
    ) {
    }

    /** Прибрати демо-склад. Викликати ДО видалення демо-людей (рухи й журнал прив'язані до них). */
    public static function reset(EntityManagerInterface $em): void
    {
        $conn = $em->getConnection();
        $demo = "SELECT id FROM telegram_user WHERE telegram_id LIKE 'demo-%'";
        $conn->executeStatement("DELETE FROM plan_event WHERE subject_type LIKE 'wh\\_%' AND actor_id IN ($demo)");
        $conn->executeStatement("DELETE FROM wh_activity WHERE user_id IN ($demo)");
        $conn->executeStatement("DELETE FROM wh_movement WHERE created_by_id IN ($demo)");
        $conn->executeStatement("DELETE FROM wh_item WHERE created_by_id IN ($demo)");
        // Також об'єкти, які додали демо-клієнтам під час показу/тестів (інакше FK не дасть прибрати клієнта).
        $conn->executeStatement(
            'DELETE FROM wh_site WHERE name IN (?) OR client_id IN (SELECT id FROM wh_client WHERE name IN (?))',
            [[...self::WAREHOUSES, self::WORKSHOP, ...array_column(self::CLIENTS, 2), ...self::LEGACY_SITES], [...array_column(self::CLIENTS, 0), ...self::LEGACY_CLIENTS]],
            [ArrayParameterType::STRING, ArrayParameterType::STRING],
        );
        $conn->executeStatement('DELETE FROM wh_client WHERE name IN (?)', [[...array_column(self::CLIENTS, 0), ...self::LEGACY_CLIENTS]], [ArrayParameterType::STRING]);
        // Лише категорії, які засів сам створив (наявні в базі — Опалубка, Техніка… — лишаються),
        // і лише якщо на них уже нічого не посилається.
        $conn->executeStatement(
            'DELETE FROM wh_category c WHERE c.name IN (?) AND NOT EXISTS (SELECT 1 FROM wh_item i WHERE i.category_id = c.id)',
            [[...self::createdCategories(), ...self::LEGACY_CATEGORIES]],
            [ArrayParameterType::STRING],
        );
    }

    /**
     * Категорії засіву, яких немає серед базових (міграція створює Опалубку, Техніку,
     * Інструмент…): лише їх --reset має право прибрати.
     *
     * @return list<string>
     */
    private static function createdCategories(): array
    {
        return array_values(array_diff(array_column(self::CATEGORIES, 0), self::BASE_CATEGORIES));
    }

    /**
     * @param array<string, TelegramUser> $people логін => демо-людина
     *
     * @return array{items: int, movements: int}
     */
    public function seed(array $people): array
    {
        // Завсклад у демо — логіст: він возить і відвантажує. Склад ведуть менеджери (WarehouseSection).
        $people['bohdan.zakharchenko']->setSupplyRole(SupplyRole::Manager);
        $people['olha.petrenko']->setSupplyRole(SupplyRole::Manager);
        // Хто пише прихід із виробництва — начальник цеху; у демо він теж веде склад.
        $people['vyrobnytstvo']->setSupplyRole(SupplyRole::Manager);
        $this->em->flush();

        $categories = [];
        $existing = $this->em->getRepository(WhCategory::class);

        foreach (self::CATEGORIES as $i => [$name, $emoji, $prefix, $attributes]) {
            // Категорія вже є (базові з міграції) — беремо її, лише доповнюємо характеристики.
            $category = $existing->findOneBy(['scope' => CategoryScope::Item, 'name' => $name]);

            if ($category !== null) {
                $category->setAttributes(array_values(array_unique([...$category->getAttributes(), ...$attributes])));
                $categories[$i] = $category;

                continue;
            }

            $categories[$i] = (new WhCategory())->setScope(CategoryScope::Item)->setName($name)->setEmoji($emoji)->setPrefix($prefix)->setAttributes($attributes)->setPosition($i);
            $this->em->persist($categories[$i]);
        }

        $sites = [
            'gp' => (new WhSite())->setKind(SiteKind::Warehouse)->setName(self::WAREHOUSES[0])->setAddress('вул. Заводська, 1'),
            'mat' => (new WhSite())->setKind(SiteKind::Warehouse)->setName(self::WAREHOUSES[1])->setAddress('вул. Заводська, 1'),
            'shop' => (new WhSite())->setKind(SiteKind::Site)->setName(self::WORKSHOP)->setNote('Власний цех — сюди матеріали йдуть у виробництво.'),
        ];

        foreach (self::CLIENTS as $i => [$name, $edrpou, $site, $address]) {
            $client = (new WhClient())->setName($name)->setEdrpou($edrpou);
            $this->em->persist($client);
            $sites['c' . $i] = (new WhSite())->setKind(SiteKind::Site)->setName($site)->setAddress($address)->setClient($client);
        }

        foreach ($sites as $site) {
            $this->em->persist($site);
        }

        $this->em->flush();
        // Демо не має виглядати чернеткою: жодних двох категорій чи місць з однаковою назвою.
        $dupes = $this->em->getConnection()->fetchFirstColumn(
            "SELECT 'категорія «' || name || '»' FROM wh_category GROUP BY scope, name HAVING COUNT(*) > 1
             UNION ALL SELECT 'місце «' || name || '»' FROM wh_site GROUP BY name HAVING COUNT(*) > 1",
        );

        if ($dupes !== []) {
            throw new RuntimeException('Демо-склад: дублікати назв — ' . implode(', ', $dupes));
        }

        $creator = $people['olha.petrenko'];
        $items = [];

        foreach (self::ITEMS as [$number, $name, $category, $tracking, $unit, $price, $rate, $attributes, $state, $photo]) {
            $item = (new WhItem())
                ->setInventoryNumber($number)
                ->setName($name)
                ->setCategory($categories[$category])
                ->setTracking($tracking)
                ->setUnit($unit)
                ->setPurchasePrice($price)
                ->setRentalRate($rate)
                ->setAttributes($attributes)
                ->setState($state)
                ->setCreatedBy($creator);
            $this->em->persist($item);
            $this->em->flush();
            $items[$number] = $item;
            $this->at(31, function () use ($item, $creator, $photo): void {
                $this->activity->record(ActivityAction::Create, $item, $creator, WhActivity::CHANNEL_ADMIN);

                if ($photo !== null && is_file($this->files . '/' . $photo)) {
                    $this->documents->attach($item, $creator, (string) file_get_contents($this->files . '/' . $photo), $photo, 'image/jpeg', DocumentType::Photo);
                    $this->activity->record(ActivityAction::DocumentAdd, $item, $creator, WhActivity::CHANNEL_ADMIN, DocumentType::Photo->label() . ': ' . $photo);
                }
            });
        }

        $suppliers = $this->em->getRepository(Supplier::class);
        $today = new DateTime('today', new DateTimeZone('Europe/Kyiv'));

        // Одна хронологія рухів і сканів — щоб id подій ішли в тому ж порядку, що й час.
        $timeline = [];

        foreach (self::MOVEMENTS as $i => $movement) {
            $timeline[] = [$movement[0], 0, $i];
        }

        foreach (self::SCANS as $i => $scan) {
            $timeline[] = [$scan[2], 1, $i];
        }

        usort($timeline, static fn (array $a, array $b) => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);

        foreach ($timeline as [$daysAgo, $kind, $i]) {
            if ($kind === 1) {
                [$number, $who] = self::SCANS[$i];
                $this->at($daysAgo, fn () => $this->activity->record(ActivityAction::Scan, $items[$number], $people[$who], WhActivity::CHANNEL_BOT, 'наклейка'));

                continue;
            }

            [, $type, $from, $to, $who, $doc, $supplier, $note, $lines] = self::MOVEMENTS[$i];
            $by = $people[$who];

            $this->at($daysAgo, function () use ($type, $today, $daysAgo, $from, $to, $sites, $lines, $items, $by, $doc, $supplier, $suppliers, $note): void {
                $movement = ($this->record)(
                    $type,
                    (clone $today)->modify(sprintf('-%d days', $daysAgo)),
                    $from !== null ? $sites[$from] : null,
                    $to !== null ? $sites[$to] : null,
                    array_map(static fn (array $l) => ['item' => $items[$l[0]], 'quantity' => $l[1], 'rate' => $l[2] ?? null], $lines),
                    $by,
                    $doc,
                    $supplier !== null ? $suppliers->findOneBy(['name' => $supplier]) : null,
                    $note,
                );
                $route = sprintf('%s → %s', $movement->getFromSite()?->getName() ?? 'ззовні', $movement->getToSite()?->getName() ?? 'списано');
                $this->activity->record(ActivityAction::Move, $movement, $by, WhActivity::CHANNEL_ADMIN, $route);

                foreach ($movement->getLines() as $line) {
                    $this->activity->record(ActivityAction::Move, $line->getItem(), $by, WhActivity::CHANNEL_ADMIN, sprintf('%s: %s, %d %s', $movement->getTitle(), $route, $line->getQuantity(), $line->getItem()->getUnit()));
                }
            });
        }

        $this->backdate();

        return ['items' => count(self::ITEMS), 'movements' => count(self::MOVEMENTS)];
    }

    /**
     * Виконати крок засіву і зсунути все, що він записав у журнал складу й plan_event,
     * на «днів тому» (о 9:00 + кілька хвилин на кожен крок — щоб порядок зберігся).
     */
    private function at(int $daysAgo, callable $work): void
    {
        $conn = $this->em->getConnection();
        $activity = (int) $conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM wh_activity');
        $events = (int) $conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM plan_event');

        $work();

        // Не в майбутньому: сьогоднішні кроки — не пізніше «зараз».
        $at = "LEAST((NOW() AT TIME ZONE 'Europe/Kyiv')::date - CAST(:days AS int) + time '08:00' + make_interval(mins => CAST(:mins AS int)), (NOW() AT TIME ZONE 'Europe/Kyiv')::timestamp(0))";
        $params = ['days' => $daysAgo, 'mins' => (++$this->step * 7) % 600];
        $conn->executeStatement("UPDATE wh_activity SET at = $at WHERE id > :after", [...$params, 'after' => $activity]);
        $conn->executeStatement("UPDATE plan_event SET occurred_at = $at WHERE id > :after", [...$params, 'after' => $events]);
    }

    private function backdate(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("UPDATE wh_item SET created_at = (NOW() AT TIME ZONE 'Europe/Kyiv') - interval '31 days', updated_at = (NOW() AT TIME ZONE 'Europe/Kyiv') - interval '31 days' WHERE created_by_id IN (SELECT id FROM telegram_user WHERE telegram_id LIKE 'demo-%')");
    }
}
