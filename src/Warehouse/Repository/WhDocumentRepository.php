<?php

namespace App\Warehouse\Repository;

use App\Warehouse\Entity\WhDocument;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Enum\DocumentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WhDocument> */
class WhDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WhDocument::class);
    }

    /**
     * @return WhDocument[] Документи, копія яких ще не поїхала на Google Диск.
     *
     * Фото самої позиції (щит, риштування) не їдуть: це картинка для впізнавання,
     * а не документ, і поки що вони живуть лише на сервері. Фото накладної чи
     * акта на клієнті, об'єкті або русі — документ, і копіюється як завжди.
     */
    public function findNotMirrored(int $limit): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.driveFileId IS NULL')
            ->andWhere('d.item IS NULL OR d.type <> :photo')->setParameter('photo', DocumentType::Photo)
            ->orderBy('d.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Перше (найсвіжіше) фото кожної позиції — мініатюра в списку майна.
     *
     * @param WhItem[] $items
     *
     * @return array<int, WhDocument> id позиції → фото
     */
    public function coverPhotos(array $items): array
    {
        if ($items === []) {
            return [];
        }

        /** @var WhDocument[] $photos */
        $photos = $this->createQueryBuilder('d')
            ->andWhere('d.item IN (:items)')->setParameter('items', $items)
            ->andWhere('d.type = :photo')->setParameter('photo', DocumentType::Photo)
            ->andWhere('d.mime IN (:mimes)')->setParameter('mimes', WhDocument::VIEWABLE_IMAGES)
            ->orderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();

        $covers = [];

        foreach ($photos as $photo) {
            $covers[$photo->getItem()->getId()] ??= $photo;
        }

        return $covers;
    }
}
