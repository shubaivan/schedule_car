<?php

namespace App\Repository;

use App\Entity\TelegramUser;
use App\Supply\Entity\Department;
use App\Supply\Enum\SupplyRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TelegramUser>
 *
 * @method TelegramUser|null find($id, $lockMode = null, $lockVersion = null)
 * @method TelegramUser|null findOneBy(array $criteria, array $orderBy = null)
 * @method TelegramUser[] findAll()
 * @method TelegramUser[] findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TelegramUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelegramUser::class);
    }

    public function getByTelegramId(string $telegramId): ?TelegramUser
    {
        return $this->createQueryBuilder('tu')
            ->where('tu.telegram_id = :telegram_id')
            ->setParameter('telegram_id', $telegramId)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Менеджери постачання — отримувачі сповіщень про нові заявки.
     *
     * @return TelegramUser[]
     */
    public function findSupplyManagers(): array
    {
        return $this->createQueryBuilder('tu')
            ->where('tu.supplyRole IN (:roles)')
            ->setParameter('roles', [SupplyRole::Manager, SupplyRole::Admin])
            ->getQuery()
            ->getResult();
    }

    /**
     * Пошук за хвостом номера: у базі телефон лежить самими цифрами, але той
     * самий номер міг зайти і як 380671112233, і як 0671112233.
     */
    public function findOneByPhoneTail(string $tail): ?TelegramUser
    {
        if ($tail === '') {
            return null;
        }

        return $this->createQueryBuilder('tu')
            ->where('tu.phone_number LIKE :tail')
            ->setParameter('tail', '%' . $tail)
            ->orderBy('tu.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Хто погоджує оплату. Адмін тут не для звітності, а як запасний ключ:
     * поки директора немає в боті, заявки не мають зависати «На затвердженні».
     *
     * @return TelegramUser[]
     */
    public function findSupplyDirectors(): array
    {
        return $this->createQueryBuilder('tu')
            ->where('tu.supplyRole IN (:roles)')
            ->setParameter('roles', [SupplyRole::Director, SupplyRole::Admin])
            ->getQuery()
            ->getResult();
    }

    /**
     * Список людей для CRM. Прибрані ховаються: рядок лишається заради історії
     * заявок, але в довіднику його не видно, поки не попросять окремо.
     *
     * @return TelegramUser[]
     */
    public function findPeople(bool $withArchived = false): array
    {
        $qb = $this->createQueryBuilder('u')->orderBy('u.first_name', 'ASC');

        if (! $withArchived) {
            $qb->andWhere('u.archivedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function countByDepartment(Department $department): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.department = :department')
            ->setParameter('department', $department)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(TelegramUser $telegramUser)
    {
        $this->getEntityManager()->persist($telegramUser);
        $this->getEntityManager()->flush();
    }
}
