<?php

namespace App\Supply\Service;

use App\Entity\TelegramUser;
use App\Supply\Entity\Supplier;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\SupplierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Довідник постачальників: заведення, редагування й захист від дублів.
 *
 * Викликається і з CRM, і з бота (менеджер додає постачальника прямо під час
 * обробки заявки), тож перевірки живуть тут, а не в контролері.
 */
class SupplierDirectory
{
    public function __construct(
        private EntityManagerInterface $em,
        private SupplierRepository $repository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{edrpou?: ?string, phone?: ?string, contactPerson?: ?string, note?: ?string} $fields
     */
    public function create(string $name, ?TelegramUser $by = null, array $fields = []): Supplier
    {
        $name = trim($name);

        if ($name === '') {
            throw new SupplyException('Вкажіть назву постачальника.');
        }

        $duplicate = $this->repository->findOneByName($name);

        if ($duplicate !== null) {
            throw new SupplyException(sprintf('Постачальник «%s» вже є у довіднику.', $duplicate->getName()));
        }

        $supplier = (new Supplier())
            ->setName($name)
            ->setCreatedBy($by);

        $this->apply($supplier, $fields);

        $this->em->persist($supplier);
        $this->em->flush();

        $this->logger->info('supply: додано постачальника', [
            'name' => $supplier->getName(),
            'edrpou' => $supplier->getEdrpou(),
            'by' => $by?->displayName(),
        ]);

        return $supplier;
    }

    /**
     * @param array{name?: string, edrpou?: ?string, phone?: ?string, contactPerson?: ?string, note?: ?string, active?: bool} $fields
     */
    public function update(Supplier $supplier, array $fields): Supplier
    {
        if (array_key_exists('name', $fields)) {
            $name = trim((string)$fields['name']);

            if ($name === '') {
                throw new SupplyException('Вкажіть назву постачальника.');
            }

            $duplicate = $this->repository->findOneByName($name);

            if ($duplicate !== null && $duplicate->getId() !== $supplier->getId()) {
                throw new SupplyException(sprintf('Постачальник «%s» вже є у довіднику.', $duplicate->getName()));
            }

            $supplier->setName($name);
        }

        if (array_key_exists('active', $fields)) {
            // Не видаляємо: на постачальника посилаються закупівлі минулих заявок.
            $supplier->setActive((bool)$fields['active']);
        }

        $this->apply($supplier, $fields);

        $this->em->flush();

        return $supplier;
    }

    /**
     * Знайти за назвою або завести нового — сценарій бота, де менеджер пише
     * назву вручну й не має отримувати помилку через уже наявний запис.
     */
    public function findOrCreate(string $name, ?TelegramUser $by = null): Supplier
    {
        return $this->repository->findOneByName($name) ?? $this->create($name, $by);
    }

    private function apply(Supplier $supplier, array $fields): void
    {
        if (array_key_exists('edrpou', $fields)) {
            $edrpou = $fields['edrpou'] !== null ? trim((string)$fields['edrpou']) : null;

            if ($edrpou !== null && $edrpou !== '') {
                if (!preg_match('/^\d{8,10}$/', $edrpou)) {
                    throw new SupplyException('ЄДРПОУ — 8 цифр, ІПН підприємця — 10. Інакше залиште поле порожнім.');
                }

                $sameCode = $this->repository->findOneByEdrpou($edrpou);

                if ($sameCode !== null && $sameCode->getId() !== $supplier->getId()) {
                    throw new SupplyException(sprintf(
                        'Цей код уже записаний за «%s» — це той самий контрагент.',
                        $sameCode->getName(),
                    ));
                }
            }

            $supplier->setEdrpou($edrpou);
        }

        if (array_key_exists('phone', $fields)) {
            $supplier->setPhone($fields['phone'] !== null ? (string)$fields['phone'] : null);
        }

        if (array_key_exists('contactPerson', $fields)) {
            $supplier->setContactPerson($fields['contactPerson'] !== null ? (string)$fields['contactPerson'] : null);
        }

        if (array_key_exists('note', $fields)) {
            $supplier->setNote($fields['note'] !== null ? (string)$fields['note'] : null);
        }
    }
}
