<?php

namespace App\Supply\Service;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use App\Supply\Entity\Department;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\DepartmentRepository;
use App\Supply\Repository\SupplyRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Довідник підрозділів: завести, перейменувати, прибрати.
 *
 * Головне правило — історію не стираємо. Підрозділ, на якому висять заявки чи
 * люди, назовсім не видаляється: він лише зникає зі списку вибору, інакше в
 * старих заявках з'явилась би дірка замість цеху.
 */
class DepartmentDirectory
{
    public function __construct(
        private EntityManagerInterface $em,
        private DepartmentRepository $repository,
        private SupplyRequestRepository $requests,
        private TelegramUserRepository $users,
        private LoggerInterface $logger,
    ) {
    }

    /** @return Department[] разом із прихованими: ними теж керують у CRM */
    public function all(): array
    {
        return $this->repository->findBy([], ['name' => 'ASC']);
    }

    public function create(string $name, ?TelegramUser $by = null): Department
    {
        $name = $this->name($name);
        $existing = $this->repository->findOneByName($name);

        if ($existing !== null) {
            // Повторне заведення прихованого — це прохання його повернути.
            if (! $existing->isActive()) {
                $existing->setActive(true);
                $this->em->flush();

                return $existing;
            }

            throw new SupplyException(sprintf('Підрозділ «%s» уже є у списку.', $existing->getName()));
        }

        $department = (new Department())->setName($name);

        $this->em->persist($department);
        $this->em->flush();

        $this->logger->info('supply: додано підрозділ', ['name' => $name, 'by' => $by?->displayName()]);

        return $department;
    }

    public function rename(Department $department, string $name, ?TelegramUser $by = null): Department
    {
        $name = $this->name($name);
        $duplicate = $this->repository->findOneByName($name);

        if ($duplicate !== null && $duplicate->getId() !== $department->getId()) {
            throw new SupplyException(sprintf('Підрозділ «%s» уже є у списку.', $duplicate->getName()));
        }

        $department->setName($name);
        $this->em->flush();

        $this->logger->info('supply: підрозділ перейменовано', ['name' => $name, 'by' => $by?->displayName()]);

        return $department;
    }

    public function setActive(Department $department, bool $active): Department
    {
        $department->setActive($active);
        $this->em->flush();

        return $department;
    }

    /**
     * Прибрати підрозділ.
     *
     * @return bool true — видалено назовсім, false — лишився в історії, але зник зі списку вибору
     */
    public function remove(Department $department, ?TelegramUser $by = null): bool
    {
        $usage = $this->usage($department);

        if ($usage['requests'] > 0 || $usage['people'] > 0) {
            $this->setActive($department, false);

            $this->logger->info('supply: підрозділ приховано', [
                'name' => $department->getName(),
                'requests' => $usage['requests'],
                'people' => $usage['people'],
                'by' => $by?->displayName(),
            ]);

            return false;
        }

        $name = $department->getName();

        $this->em->remove($department);
        $this->em->flush();

        $this->logger->info('supply: підрозділ видалено', ['name' => $name, 'by' => $by?->displayName()]);

        return true;
    }

    /** @return array{requests: int, people: int} скільки всього тримається за цей підрозділ */
    public function usage(Department $department): array
    {
        return [
            'requests' => $this->requests->countByDepartment($department),
            'people' => $this->users->countByDepartment($department),
        ];
    }

    private function name(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new SupplyException('Вкажіть назву підрозділу.');
        }

        return $name;
    }
}
