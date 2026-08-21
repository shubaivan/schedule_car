<?php

namespace App\Tests\Fleet;

use App\Entity\Car;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Fleet\Service\TripFormatter;
use App\Repository\ScheduledSetRepository;
use App\Service\FleetDirectory;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Спільний розклад машин: його бачать усі — і заявник, і водій, і керівник.
 *
 * Головна вимога до розкладу: це календар завантаження. З нього має бути видно
 * не лише «зайнято», а куди їде машина, ким вона взята, за яким телефоном
 * шукати людину і хто цю машину веде.
 */
class ScheduleTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ScheduledSetRepository $sets;
    private FleetDirectory $fleet;
    private TripFormatter $formatter;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->sets = self::getContainer()->get(ScheduledSetRepository::class);
        $this->fleet = self::getContainer()->get(FleetDirectory::class);
        $this->formatter = self::getContainer()->get(TripFormatter::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testScheduleLineShowsDestinationWhoPhoneAndTask(): void
    {
        $set = $this->booking('AA5555BB', '+2 hours', 'Відвезти арматуру');
        $set->setDestination('вул. Заводська, 5');

        $line = $this->formatter->line($set);

        self::assertStringContainsString('AA5555BB', $line);
        self::assertStringContainsString('Заводська', $line, 'у розкладі має бути куди їде машина');
        self::assertStringContainsString('380631112299', $line, 'у розкладі має бути телефон');
        self::assertStringContainsString('Відвезти арматуру', $line, 'у розкладі має бути завдання');
    }

    /** Водій — половина відповіді «чи вільна машина»: зайнята й людина. */
    public function testCarHeadingShowsDriverWithPhone(): void
    {
        $set = $this->booking('AA5151BB', '+2 hours', 'Цемент');
        $driver = $this->user();

        $heading = $this->formatter->carHeading($set->getCar(), [$driver]);

        self::assertStringContainsString('AA5151BB', $heading);
        self::assertStringContainsString('Іван', $heading, 'у шапці машини має бути водій');
        self::assertStringContainsString('380631112299', $heading, 'і його телефон');
    }

    public function testCarWithoutDriverSaysSoInsteadOfSilence(): void
    {
        $set = $this->booking('AA5252BB', '+2 hours', 'Пісок');

        self::assertStringContainsString('не закріплений', $this->formatter->carHeading($set->getCar(), []));
    }

    /**
     * Броні, зроблені до появи окремого маршруту, лишились без нього. Показати
     * «маршрут не вказано» замість завдання означало б втратити єдине, що про
     * ту поїздку відомо.
     */
    public function testOldBookingWithoutDestinationShowsTaskAsRoute(): void
    {
        $set = $this->booking('AA5353BB', '+2 hours', 'Забрати двигун із СТО');

        self::assertSame('Забрати двигун із СТО', $this->formatter->destinationOf($set));
    }

    public function testWeekViewCollectsBookingsOfAllCars(): void
    {
        $this->booking('AA6666BB', '+1 hour', 'Цемент');
        $this->booking('AA7777BB', '+2 days', 'Пісок');
        // За межами тижня — у спільну картину не потрапляє.
        $this->booking('AA8888BB', '+20 days', 'Пізніше');

        $from = new DateTime('today', new DateTimeZone('Europe/Kyiv'));
        $found = $this->sets->findBetween($from, (clone $from)->modify('+7 days'));

        $numbers = array_map(static fn (ScheduledSet $s) => $s->getCar()->getCarNumber(), $found);

        self::assertContains('AA6666BB', $numbers);
        self::assertContains('AA7777BB', $numbers);
        self::assertNotContains('AA8888BB', $numbers);
    }

    /** Водій бачить рейси своєї машини, а не весь завод. */
    public function testDriverSeesOnlyHisCar(): void
    {
        $mine = $this->booking('AA9999BB', '+3 hours', 'Моя');
        $this->booking('AB1010BB', '+3 hours', 'Чужа');

        $found = $this->sets->findUpcomingByCar(
            $mine->getCar(),
            new DateTime('today', new DateTimeZone('Europe/Kyiv')),
        );

        self::assertCount(1, $found);
        self::assertSame('Моя', $found[0]->getTask());
    }

    /**
     * Ліміт восьми годин на добу. Правило жило в окремому валідаторі, і під час
     * переписування форми бронювання його ледь не загубили: нова форма спершу
     * зберігала бронь, нічого не перевіряючи.
     */
    public function testDailyLimitIsEightHours(): void
    {
        $car = $this->fleet->saveCar(null, ['carNumber' => 'AB2020BB']);
        $user = $this->user();
        $day = new DateTime('tomorrow', new DateTimeZone('Europe/Kyiv'));

        for ($hour = 8; $hour < 16; ++$hour) {
            $this->em->persist($this->set($car, $user, (clone $day)->setTime($hour, 0), 'Рейс'));
        }

        $this->em->flush();

        $ninth = $this->set($car, $user, (clone $day)->setTime(16, 0), 'Дев ятий');
        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($ninth);

        self::assertGreaterThan(0, $violations->count(), 'девʼяту годину поспіль бронювати не можна');
        self::assertStringContainsString('восьми годин', (string) $violations->get(0)->getMessage());
    }

    /**
     * Бронь без завдання — це нормально, кнопка «Пропустити» на те й є.
     *
     * Тест стоїть тут через реальну помилку: нове поле task вклинилось між
     * #[NotBlank] і полем car, атрибут перечепився на task, і будь-яке
     * бронювання без тексту завдання переставало проходити валідацію.
     */
    public function testBookingWithoutTaskIsValid(): void
    {
        $car = $this->fleet->saveCar(null, ['carNumber' => 'AB3030BB']);
        $at = (new DateTime('tomorrow', new DateTimeZone('Europe/Kyiv')))->setTime(9, 0);

        $set = $this->set($car, $this->user(), $at, 'з завданням')->setTask(null);

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($set);

        self::assertCount(0, $violations, (string) $violations);
    }

    private function booking(string $carNumber, string $when, string $task): ScheduledSet
    {
        $car = $this->fleet->saveCar(null, ['carNumber' => $carNumber, 'model' => 'Renault Master']);

        $at = (new DateTime('now', new DateTimeZone('Europe/Kyiv')))->modify($when);
        $at->setTime((int) $at->format('H'), 0);

        $set = $this->set($car, $this->user(), $at, $task);

        $this->em->persist($set);
        $this->em->flush();

        return $set;
    }

    private function user(): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId('trip-' . uniqid())
            ->setFirstName('Іван')
            ->setPhoneNumber('380631112299');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function set(Car $car, TelegramUser $user, DateTime $at, string $task): ScheduledSet
    {
        return (new ScheduledSet())
            ->setCar($car)
            ->setTelegramUserId($user)
            ->setYear((int) $at->format('Y'))
            ->setMonth((int) $at->format('m'))
            ->setDay((int) $at->format('d'))
            ->setHour((int) $at->format('H'))
            ->setScheduledAt($at)
            ->setTask($task);
    }
}
