<?php

namespace App\Tests\Supply;

use App\Supply\Entity\Supplier;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\SupplierRepository;
use App\Supply\Service\SupplierDirectory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Довідник постачальників: захист від дублів і пошук.
 *
 * Дублі тут — не косметика: якщо той самий ФОП заведений двічі, звіт
 * «скільки закупили у Петренка» рахує половину.
 */
class SupplierDirectoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SupplierDirectory $directory;
    private SupplierRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->directory = self::getContainer()->get(SupplierDirectory::class);
        $this->repository = self::getContainer()->get(SupplierRepository::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testCreateStoresContacts(): void
    {
        $supplier = $this->directory->create('ФОП Петренко О.П.', null, [
            'edrpou' => '1234567890',
            'phone' => ' +380671112233 ',
            'contactPerson' => 'Петренко Олег',
        ]);

        self::assertNotNull($supplier->getId());
        self::assertTrue($supplier->isActive());
        self::assertSame('+380671112233', $supplier->getPhone(), 'пробіли з країв зрізаються');
        self::assertSame('фоппетренкооп', $supplier->getNameNormalized());
    }

    /** @dataProvider sameSupplierWrittenDifferently */
    public function testDuplicateNameIsRejected(string $second): void
    {
        $this->directory->create('ФОП Петренко О.П.');

        $this->expectException(SupplyException::class);
        $this->expectExceptionMessage('вже є у довіднику');

        $this->directory->create($second);
    }

    public static function sameSupplierWrittenDifferently(): iterable
    {
        yield 'той самий рядок' => ['ФОП Петренко О.П.'];
        yield 'інший регістр' => ['фоп петренко о.п.'];
        yield 'подвійні пробіли' => ['ФОП  Петренко  О.П.'];
        yield 'без крапок' => ['ФОП Петренко ОП'];
        yield 'у лапках' => ['ФОП «Петренко О.П.»'];
    }

    public function testDifferentLegalFormIsNotADuplicate(): void
    {
        $this->directory->create('ФОП Альфа');
        $supplier = $this->directory->create('ТОВ Альфа');

        self::assertNotNull($supplier->getId(), 'ФОП і ТОВ — різні юридичні особи');
    }

    public function testSameEdrpouIsRejected(): void
    {
        $this->directory->create('ФОП Петренко О.П.', null, ['edrpou' => '1234567890']);

        $this->expectException(SupplyException::class);
        $this->expectExceptionMessage('той самий контрагент');

        $this->directory->create('Петренко (пісок)', null, ['edrpou' => '1234567890']);
    }

    public function testMalformedEdrpouIsRejected(): void
    {
        $this->expectException(SupplyException::class);

        $this->directory->create('ФОП Коваленко', null, ['edrpou' => 'не код']);
    }

    public function testFindOrCreateReusesExistingRecord(): void
    {
        $created = $this->directory->create('ФОП Петренко О.П.');
        $found = $this->directory->findOrCreate('фоп петренко о.п.');

        self::assertSame($created->getId(), $found->getId());
    }

    public function testRenameKeepsItsOwnNameFree(): void
    {
        $supplier = $this->directory->create('ФОП Петренко');

        // Правка без зміни назви не має спрацьовувати як дубль самого себе.
        $this->directory->update($supplier, ['name' => 'ФОП Петренко', 'phone' => '+380671112233']);

        self::assertSame('+380671112233', $supplier->getPhone());
    }

    public function testHiddenSupplierStaysInDirectoryButLeavesTheChoiceList(): void
    {
        $supplier = $this->directory->create('ФОП Петренко О.П.');
        $this->directory->update($supplier, ['active' => false]);

        self::assertNotNull($this->repository->findOneByName('ФОП Петренко О.П.'), 'запис лишається');
        self::assertSame([], array_filter(
            $this->repository->findActive(),
            static fn(Supplier $s) => $s->getId() === $supplier->getId(),
        ));
    }

    public function testSearchFindsByNameAndCode(): void
    {
        $this->directory->create('ФОП Петренко О.П.', null, ['edrpou' => '1234567890']);
        $this->directory->create('ТОВ Будматеріали');

        self::assertCount(1, $this->repository->search('петрен'));
        self::assertCount(1, $this->repository->search('123456'));
        self::assertCount(1, $this->repository->search('будмат'));
    }
}
