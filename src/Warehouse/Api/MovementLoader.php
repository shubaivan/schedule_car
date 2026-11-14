<?php

namespace App\Warehouse\Api;

use App\Warehouse\Entity\WhMovement;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use SortDirection;

/**
 * Рухи для списків «Плану» без N+1: спершу id (з фільтрами й LIMIT), потім самі
 * рухи одним запитом разом із рядками, позиціями, місцями, клієнтами й автором.
 * (LIMIT разом із fetch-join колекції обрізав би рядки, тому — два кроки.).
 */
final class MovementLoader
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Заготовка для відбору id: псевдонім руху — m, місця — f і t, постачальник — s. */
    public function filter(): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('m.id')
            ->from(WhMovement::class, 'm')
            ->leftJoin('m.fromSite', 'f')
            ->leftJoin('m.toSite', 't')
            ->leftJoin('m.supplier', 's')
            ->orderBy('m.occurredAt', SortDirection::Descending)
            ->addOrderBy('m.id', SortDirection::Descending);
    }

    /** @return WhMovement[] у порядку відбору */
    public function load(QueryBuilder $ids, int $limit): array
    {
        $found = array_map('intval', $ids->setMaxResults($limit)->getQuery()->getSingleColumnResult());

        if ($found === []) {
            return [];
        }

        /** @var WhMovement[] $movements */
        $movements = $this->em->createQueryBuilder()
            ->select('m', 'l', 'i', 'f', 'fc', 't', 'tc', 's', 'u')
            ->from(WhMovement::class, 'm')
            ->leftJoin('m.lines', 'l')
            ->leftJoin('l.item', 'i')
            ->leftJoin('m.fromSite', 'f')
            ->leftJoin('f.client', 'fc')
            ->leftJoin('m.toSite', 't')
            ->leftJoin('t.client', 'tc')
            ->leftJoin('m.supplier', 's')
            ->leftJoin('m.createdBy', 'u')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $found, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        $byId = [];

        foreach ($movements as $movement) {
            $byId[(int) $movement->getId()] = $movement;
        }

        return array_values(array_filter(array_map(static fn (int $id) => $byId[$id] ?? null, $found)));
    }
}
