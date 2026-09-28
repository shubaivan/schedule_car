<?php

namespace App\Command;

use App\Entity\Car;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Supply\Entity\Supplier;
use App\Supply\Entity\SupplyPurchase;
use App\Supply\Enum\SupplyRole;
use App\Supply\Service\SupplierDirectory;
use App\Warehouse\Entity\WhCategory;
use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\CategoryScope;
use App\Warehouse\Enum\MovementType;
use App\Warehouse\Enum\SiteKind;
use App\Warehouse\Enum\Tracking;
use App\Warehouse\Service\RecordMovement;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Зразки для навчання: кілька позицій складу, клієнт з об'єктом, рухи
 * й кілька перевезень в автопарку — щоб люди бачили, як виглядає заповнене.
 *
 * Усе назване «Зразок», тож його ні з чим не сплутати, а `--remove`
 * прибирає рівно те, що створила ця команда. Повторний запуск нічого не дублює.
 * Назви й адреси нейтральні: каркас спільний на двох клієнтів.
 */
#[AsCommand(name: 'demo:samples', description: 'Зразки для складу й автопарку (--remove — прибрати)')]
class DemoSamplesCommand extends Command
{
    private const CLIENT = 'ТОВ «Зразок-Буд»';
    private const SITE = 'ЖК «Зразковий», секція 1';
    private const SUPPLIER = 'ТОВ «Постачальник-Зразок»';
    private const NOTE = 'ЗРАЗОК — для прикладу, можна видалити.';
    private const CARS = [
        ['ЗРАЗОК-1', 'Самоскид MAN (зразок)'],
        ['ЗРАЗОК-2', 'Маніпулятор DAF (зразок)'],
    ];

    /** назва, категорія, облік, одиниця, ціна, оренда/доба, кількість на прихід, характеристики */
    private const ITEMS = [
        ['Ригель 2,0 м (зразок)', 'Опалубка', Tracking::Bulk, 'шт', '420.00', '3.00', 400, ['Довжина' => '2,0 м', 'Вага' => '8 кг']],
        ['Фанера ламінована 18 мм (зразок)', 'Опалубка', Tracking::Bulk, 'лист', '1650.00', '12.00', 120, ['Товщина' => '18 мм', 'Розмір' => '1250×2500 мм']],
        ['Стійка телескопічна 3,1 м (зразок)', 'Опалубка', Tracking::Bulk, 'шт', '980.00', '5.00', 300, ['Висота' => '1,8–3,1 м', 'Навантаження' => 'до 2 т']],
        ['Трактор МТЗ-82.1 (зразок)', 'Техніка', Tracking::Unit, 'шт', '950000.00', '3500.00', 1, ['Держномер' => 'ЗРАЗОК', 'Рік випуску' => '2012', 'Моточаси' => '4 300']],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private RecordMovement $record,
        private SupplierDirectory $suppliers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('remove', null, InputOption::VALUE_NONE, 'Прибрати зразки');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('remove')) {
            $this->remove();
            $io->success('Зразки прибрано.');

            return Command::SUCCESS;
        }

        $admin = $this->em->getRepository(TelegramUser::class)->findOneBy(['supplyRole' => SupplyRole::Admin], ['id' => 'ASC']);

        if ($admin === null) {
            $io->error('Потрібен хоча б один адміністратор у боті: від його імені записуються рухи й перевезення.');

            return Command::FAILURE;
        }

        $this->warehouse($admin, $io);
        $this->fleet($admin, $io);

        return Command::SUCCESS;
    }

    private function warehouse(TelegramUser $admin, SymfonyStyle $io): void
    {
        if ($this->em->getRepository(WhClient::class)->findOneBy(['name' => self::CLIENT]) !== null) {
            $io->note('Зразки складу вже є — пропускаю.');

            return;
        }

        $supplier = $this->suppliers->findOrCreate(self::SUPPLIER, $admin);
        $store = $this->em->getRepository(WhSite::class)->findOneBy(['kind' => SiteKind::Warehouse], ['id' => 'ASC']);
        $category = fn (CategoryScope $scope, string $name) => $this->em->getRepository(WhCategory::class)->findOneBy(['scope' => $scope, 'name' => $name]);

        $client = (new WhClient())
            ->setName(self::CLIENT)
            ->setCategory($category(CategoryScope::Client, 'Забудовник'))
            ->setEdrpou('12345678')
            ->setPhone('+380 00 000 00 00')
            ->setContactPerson('Прораб (зразок)')
            ->setNote(self::NOTE);
        $site = (new WhSite())
            ->setName(self::SITE)
            ->setClient($client)
            ->setCategory($category(CategoryScope::Site, 'Житловий комплекс'))
            ->setAddress('вул. Заводська, 5')
            ->setNote(self::NOTE);
        $this->em->persist($client);
        $this->em->persist($site);

        $items = [];

        foreach (self::ITEMS as $i => [$name, $cat, $tracking, $unit, $price, $rate, , $attributes]) {
            $item = (new WhItem())
                ->setInventoryNumber(sprintf('ЗР-%04d', $i + 1))
                ->setName($name)
                ->setCategory($category(CategoryScope::Item, $cat))
                ->setTracking($tracking)
                ->setUnit($unit)
                ->setPurchasePrice($price)
                ->setRentalRate($rate)
                ->setSupplier($supplier)
                ->setPurchasedAt(new DateTime('-20 days'))
                ->setAttributes($attributes)
                ->setDescription(self::NOTE)
                ->setCreatedBy($admin);
            $this->em->persist($item);
            $items[] = $item;
        }

        $this->em->flush();

        ($this->record)(
            MovementType::Receipt,
            new DateTime('-20 days'),
            null,
            $store,
            array_map(static fn (WhItem $item, array $row) => ['item' => $item, 'quantity' => $row[6]], $items, self::ITEMS),
            $admin,
            'ЗР-1',
            $supplier,
            self::NOTE,
        );

        ($this->record)(
            MovementType::Shipment,
            new DateTime('-10 days'),
            $store,
            $site,
            [
                ['item' => $items[0], 'quantity' => 120],
                ['item' => $items[1], 'quantity' => 40],
                ['item' => $items[2], 'quantity' => 150],
            ],
            $admin,
            'ЗР-2',
            null,
            self::NOTE,
        );

        ($this->record)(MovementType::Shipment, new DateTime('-5 days'), $store, $site, [['item' => $items[3], 'quantity' => 1]], $admin, 'ЗР-3', null, self::NOTE);
        ($this->record)(MovementType::Return, new DateTime('-2 days'), $site, $store, [['item' => $items[0], 'quantity' => 20]], $admin, 'ЗР-4', null, self::NOTE);

        $io->success(sprintf('Склад: 4 позиції, клієнт «%s» з об\'єктом, 4 рухи.', self::CLIENT));
    }

    private function fleet(TelegramUser $admin, SymfonyStyle $io): void
    {
        $cars = [];

        foreach (self::CARS as [$number, $model]) {
            $car = $this->em->getRepository(Car::class)->findOneBy(['carNumber' => $number])
                ?? (new Car())->setCarNumber($number)->setModel($model)->setActive(true);
            $this->em->persist($car);
            $cars[] = $car;
        }

        $this->em->flush();

        if ($this->em->getRepository(ScheduledSet::class)->findOneBy(['car' => $cars[0]]) !== null) {
            $io->note('Перевезення-зразки вже є — пропускаю.');

            return;
        }

        $trips = [
            [$cars[0], '+1 day', 9, self::SITE, 'Відвезти 40 листів фанери й 150 стійок (зразок)'],
            [$cars[0], '+1 day', 14, 'Основний склад', 'Забрати 20 ригелів з об\'єкта (зразок)'],
            [$cars[1], '+2 days', 10, self::SITE, 'Вивантажити опалубку маніпулятором (зразок)'],
            [$cars[1], '+3 days', 8, 'вул. Садова, 10', 'Доставка бетонних виробів (зразок)'],
        ];

        foreach ($trips as [$car, $day, $hour, $destination, $task]) {
            $when = (new DateTime($day, new DateTimeZone('Europe/Kyiv')))->setTime($hour, 0);
            $this->em->persist(
                (new ScheduledSet())
                    ->setCar($car)
                    ->setTelegramUserId($admin)
                    ->setYear((int) $when->format('Y'))
                    ->setMonth((int) $when->format('m'))
                    ->setDay((int) $when->format('d'))
                    ->setHour($hour)
                    ->setScheduledAt($when)
                    ->setDestination($destination)
                    ->setTask($task),
            );
        }

        $this->em->flush();
        $io->success('Автопарк: 2 машини й 4 перевезення на найближчі дні.');
    }

    private function remove(): void
    {
        $client = $this->em->getRepository(WhClient::class)->findOneBy(['name' => self::CLIENT]);
        $items = $this->em->getRepository(WhItem::class)->createQueryBuilder('i')
            ->andWhere('i.inventoryNumber LIKE :p')->setParameter('p', 'ЗР-%')
            ->getQuery()->getResult();

        // Рухи зразків: ті, де є хоч одна позиція-зразок.
        if ($items !== []) {
            $movements = $this->em->getRepository(WhMovement::class)->createQueryBuilder('m')
                ->innerJoin('m.lines', 'l')
                ->andWhere('l.item IN (:items)')->setParameter('items', $items)
                ->getQuery()->getResult();

            foreach ($movements as $movement) {
                $this->em->remove($movement);
            }

            $this->em->flush();

            foreach ($items as $item) {
                $this->em->remove($item);
            }
        }

        // Постачальник-зразок живе в спільному довіднику заявок: прибираємо,
        // лише якщо на нього не посилається жодна справжня закупівля.
        $supplier = $this->em->getRepository(Supplier::class)->findOneBy(['nameNormalized' => Supplier::normalize(self::SUPPLIER)]);

        if ($supplier !== null && $this->em->getRepository(SupplyPurchase::class)->count(['supplier' => $supplier]) === 0) {
            $this->em->remove($supplier);
        }

        if ($client !== null) {
            foreach ($client->getSites() as $site) {
                $this->em->remove($site);
            }

            $this->em->flush();
            $this->em->remove($client);
        }

        foreach (self::CARS as [$number]) {
            $car = $this->em->getRepository(Car::class)->findOneBy(['carNumber' => $number]);

            if ($car !== null) {
                foreach ($this->em->getRepository(ScheduledSet::class)->findBy(['car' => $car]) as $trip) {
                    $this->em->remove($trip);
                }

                $this->em->remove($car);
            }
        }

        $this->em->flush();
    }
}
