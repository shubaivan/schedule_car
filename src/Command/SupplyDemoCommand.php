<?php

namespace App\Command;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use App\Service\CrmLoginLink;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Entity\Department;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Enum\Unit;
use App\Supply\Repository\DepartmentRepository;
use App\Supply\Service\ChangeStatus;
use App\Supply\Service\CreateRequest;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Наповнення стенда демо-даними: підрозділи, люди й кілька заявок у різних статусах.
 * Ідемпотентна — повторний запуск нічого не дублює.
 */
#[AsCommand(name: 'supply:demo', description: 'Демо-дані для стенда постачання')]
class SupplyDemoCommand extends Command
{
    private const DEPARTMENTS = ['Цех №1', 'Цех №2', 'Дільниця благоустрою', 'Транспортний відділ'];

    public function __construct(
        private EntityManagerInterface $em,
        private DepartmentRepository $departments,
        private TelegramUserRepository $users,
        private CreateRequest $createRequest,
        private ChangeStatus $changeStatus,
        private CrmLoginLink $loginLink,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (self::DEPARTMENTS as $name) {
            if ($this->departments->findOneBy(['name' => $name]) === null) {
                $this->em->persist((new Department())->setName($name));
            }
        }
        $this->em->flush();

        $manager = $this->user('demo-manager', 'Олександр', 'Менеджер', SupplyRole::Manager);
        $worker = $this->user('demo-worker', 'Петро', 'Мураха', SupplyRole::Worker);
        $worker->setDepartment($this->departments->findOneBy(['name' => 'Цех №2']));
        $this->em->flush();

        if (! $this->em->getRepository(\App\Supply\Entity\SupplyRequest::class)->count([])) {
            $samples = [
                ['Арматура 12 А500С', '2.5', Unit::Ton, true, SupplyStatus::InProgress],
                ['Цемент М400', '40', Unit::Pack, false, SupplyStatus::Paid],
                ['Пісок річковий', '10', Unit::CubicMeter, false, SupplyStatus::Delivery],
                ['Дошка обрізна 25мм', '3', Unit::CubicMeter, false, SupplyStatus::New],
                ['Рукавиці робочі', '50', Unit::Piece, false, SupplyStatus::InStock],
            ];

            foreach ($samples as [$item, $quantity, $unit, $urgent, $target]) {
                $request = ($this->createRequest)($worker, new CreateRequestInput(
                    item: $item,
                    quantity: $quantity,
                    unit: $unit,
                    needBy: new DateTime($urgent ? '+1 day' : '+' . random_int(3, 14) . ' days'),
                    urgent: $urgent,
                    site: 'Цех №2',
                ));

                // Крокуємо ланцюжком статусів уперед, поки не дійдемо до потрібного.
                $status = $request->getStatus();
                for ($guard = 0; $status !== $target && $guard < 10; ++$guard) {
                    $next = $status->allowedTransitions();
                    $step = in_array($target, $next, true) ? $target : ($next[0] ?? null);

                    if ($step === null) {
                        break;
                    }

                    ($this->changeStatus)($request, $step, $manager);
                    $status = $step;
                }
            }
        }

        $io->success('Демо-дані готові.');
        $io->writeln('Вхід у CRM менеджером: ' . $this->loginLink->issue($manager));

        return Command::SUCCESS;
    }

    private function user(string $telegramId, string $first, string $last, SupplyRole $role): TelegramUser
    {
        $user = $this->users->getByTelegramId($telegramId);

        if ($user === null) {
            $user = (new TelegramUser())->setTelegramId($telegramId);
            $this->em->persist($user);
        }

        $user->setFirstName($first)->setLastName($last)->setSupplyRole($role);
        $this->em->flush();

        return $user;
    }
}
