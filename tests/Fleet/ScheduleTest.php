<?php

namespace App\Tests\Fleet;

use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Fleet\Service\TripFormatter;
use App\Repository\ScheduledSetRepository;
use App\Service\FleetDirectory;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Спільний розклад машин: його бачать усі — і заявник, і водій, і керівник.
 *
 * Головна вимога до рядка розкладу: з нього має бути зрозуміло не лише «зайнято»,
 * а й ким, за яким телефоном і навіщо — інакше домовитись без дзвінка в контору
 * неможливо.
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

    public function testScheduleLineShowsWhoPhoneAndTask(): void
    {
        $set = $this->booking('AA5555BB', '+2 hours', 'Відвезти арматуру на Амет-Хана');

        $line = $this->formatter->line($set);

        self::assertStringContainsString('AA5555BB', $line);
        self::assertStringContainsString('380631112299', $line, 'у розкладі має бути телефон');
        self::assertStringContainsString('Відвезти арматуру', $line, 'у розкладі має бути завдання');
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

    private function booking(string $carNumber, string $when, string $task): ScheduledSet
    {
        $car = $this->fleet->saveCar(null, ['carNumber' => $carNumber, 'model' => 'Renault Master']);

        $user = (new TelegramUser())
            ->setTelegramId('trip-' . uniqid())
            ->setFirstName('Іван')
            ->setPhoneNumber('380631112299');

        $this->em->persist($user);

        $at = (new DateTime('now', new DateTimeZone('Europe/Kyiv')))->modify($when);
        $at->setTime((int) $at->format('H'), 0);

        $set = (new ScheduledSet())
            ->setCar($car)
            ->setTelegramUserId($user)
            ->setYear((int) $at->format('Y'))
            ->setMonth((int) $at->format('m'))
            ->setDay((int) $at->format('d'))
            ->setHour((int) $at->format('H'))
            ->setScheduledAt($at)
            ->setTask($task);

        $this->em->persist($set);
        $this->em->flush();

        return $set;
    }
}
