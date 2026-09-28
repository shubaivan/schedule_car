<?php

namespace App\Warehouse\Service;

use App\Entity\TelegramUser;
use App\Warehouse\Entity\WhCategory;
use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\CategoryScope;
use App\Warehouse\Enum\ItemState;
use App\Warehouse\Enum\SiteKind;
use App\Warehouse\Enum\Tracking;
use App\Warehouse\Exception\WarehouseException;
use App\Warehouse\Repository\WhCategoryRepository;
use App\Warehouse\Repository\WhItemRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Картки: позиції, клієнти, об'єкти. Приймає поля форми як є й перевіряє їх.
 *
 * Поля приходять рядками з форми адмінки — тут вони чистяться, а помилка
 * повертається текстом, який можна показати Лені без перекладу.
 */
class WarehouseDirectory
{
    public function __construct(
        private EntityManagerInterface $em,
        private WhItemRepository $items,
        private WhCategoryRepository $categories,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function saveItem(WhItem $item, array $data, TelegramUser $by): WhItem
    {
        $this->guard($by);

        $name = $this->text($data['name'] ?? null);

        if ($name === null) {
            throw new WarehouseException('Вкажіть назву позиції.');
        }

        $category = $this->category($data['categoryId'] ?? null, CategoryScope::Item);

        if ($category === null) {
            throw new WarehouseException('Оберіть категорію майна.');
        }

        $tracking = Tracking::tryFrom((string) ($data['tracking'] ?? '')) ?? Tracking::Unit;

        // Спосіб обліку міняти після першого руху не можна: історія поштучної
        // позиції і залишки «кількістю» читаються по-різному.
        if ($item->getId() !== null && $tracking !== $item->getTracking() && $this->hasMovements($item)) {
            throw new WarehouseException('Спосіб обліку не змінити: позиція вже має рухи.');
        }

        $item
            ->setName($name)
            ->setCategory($category)
            ->setTracking($tracking)
            ->setUnit($this->text($data['unit'] ?? null) ?? 'шт')
            ->setDescription($this->text($data['description'] ?? null))
            ->setSerialNumber($this->text($data['serialNumber'] ?? null))
            ->setSupplier($this->text($data['supplier'] ?? null))
            ->setPurchasePrice($this->money($data['purchasePrice'] ?? null, 'Ціна'))
            ->setPurchasedAt($this->date($data['purchasedAt'] ?? null))
            ->setRentalRate($this->money($data['rentalRate'] ?? null, 'Ставка оренди'))
            ->setAttributes($this->attributes($data));

        $state = ItemState::tryFrom((string) ($data['state'] ?? ''));

        // Списання — лише рухом «Списання»: так воно лишає слід в історії.
        if ($state !== null && $state !== ItemState::WrittenOff && $item->getState() !== ItemState::WrittenOff) {
            $item->setState($state);
        }

        $number = $this->text($data['inventoryNumber'] ?? null) ?? ($item->getInventoryNumber() ?: $this->nextNumber($category));
        $taken = $this->items->findOneBy(['inventoryNumber' => $number]);

        if ($taken !== null && $taken->getId() !== $item->getId()) {
            throw new WarehouseException(sprintf('Інвентарний номер %s уже в «%s».', $number, $taken->getName()));
        }

        $item->setInventoryNumber($number);

        if ($item->getId() === null) {
            $item->setCreatedBy($by);
            $this->em->persist($item);
        }

        $this->em->flush();

        return $item;
    }

    /** Наступний вільний номер категорії: ОП-0001, ОП-0002… Без префікса — INV-0001. */
    public function nextNumber(WhCategory $category): string
    {
        $prefix = $category->getPrefix() ?: 'INV';

        $max = (int) $this->em->getConnection()->fetchOne(
            "SELECT MAX(CAST(SUBSTRING(inventory_number FROM '^' || :prefix || '-(\\d+)$') AS INTEGER)) FROM wh_item",
            ['prefix' => preg_quote($prefix)],
        );

        return sprintf('%s-%04d', $prefix, $max + 1);
    }

    /** @param array<string, mixed> $data */
    public function saveCategory(WhCategory $category, array $data, TelegramUser $by): WhCategory
    {
        $this->guard($by);

        $name = $this->text($data['name'] ?? null);

        if ($name === null) {
            throw new WarehouseException('Вкажіть назву категорії.');
        }

        $scope = $category->getId() !== null
            ? $category->getScope()
            : (CategoryScope::tryFrom((string) ($data['scope'] ?? '')) ?? CategoryScope::Item);

        $taken = $this->categories->findOneBy(['scope' => $scope, 'name' => $name]);

        if ($taken !== null && $taken->getId() !== $category->getId()) {
            throw new WarehouseException(sprintf('Категорія «%s» уже є.', $name));
        }

        $prefix = $this->text($data['prefix'] ?? null);

        if ($prefix !== null) {
            $prefix = mb_strtoupper($prefix);

            if (! preg_match('/^[\p{Lu}]{2,4}$/u', $prefix)) {
                throw new WarehouseException('Префікс — 2–4 великі літери, наприклад ОП.');
            }
        }

        $attributes = array_values(array_filter(array_map(
            'trim',
            preg_split('/[,\n]/u', (string) ($data['attributes'] ?? '')) ?: [],
        ), static fn (string $a) => $a !== ''));

        $category
            ->setScope($scope)
            ->setName($name)
            ->setEmoji($this->text($data['emoji'] ?? null))
            ->setPrefix($scope === CategoryScope::Item ? $prefix : null)
            ->setAttributes($scope === CategoryScope::Item ? $attributes : [])
            ->setPosition((int) ($data['position'] ?? $category->getPosition()))
            ->setActive(! isset($data['active']) || (bool) $data['active']);

        if ($category->getId() === null) {
            $this->em->persist($category);
        }

        $this->em->flush();

        return $category;
    }

    /** @param array<string, mixed> $data */
    public function saveClient(WhClient $client, array $data, TelegramUser $by): WhClient
    {
        $this->guard($by);

        $name = $this->text($data['name'] ?? null);

        if ($name === null) {
            throw new WarehouseException('Вкажіть назву клієнта.');
        }

        $edrpou = $this->text($data['edrpou'] ?? null);

        if ($edrpou !== null && ! preg_match('/^\d{8}(\d{2})?$/', $edrpou)) {
            throw new WarehouseException('ЄДРПОУ — 8 цифр, ІПН — 10.');
        }

        $client
            ->setCategory($this->category($data['categoryId'] ?? null, CategoryScope::Client))
            ->setName($name)
            ->setEdrpou($edrpou)
            ->setPhone($this->text($data['phone'] ?? null))
            ->setContactPerson($this->text($data['contactPerson'] ?? null))
            ->setNote($this->text($data['note'] ?? null))
            ->setActive(! isset($data['active']) || (bool) $data['active']);

        if ($client->getId() === null) {
            $this->em->persist($client);
        }

        $this->em->flush();

        return $client;
    }

    /** @param array<string, mixed> $data */
    public function saveSite(WhSite $site, array $data, ?WhClient $client, TelegramUser $by): WhSite
    {
        $this->guard($by);

        $name = $this->text($data['name'] ?? null);

        if ($name === null) {
            throw new WarehouseException("Вкажіть назву об'єкта.");
        }

        $kind = SiteKind::tryFrom((string) ($data['kind'] ?? '')) ?? SiteKind::Site;

        if ($kind === SiteKind::Warehouse && $client !== null) {
            throw new WarehouseException('Склад — наш власний, клієнта в нього немає.');
        }

        $site
            ->setKind($kind)
            ->setCategory($kind === SiteKind::Site ? $this->category($data['categoryId'] ?? null, CategoryScope::Site) : null)
            ->setName($name)
            ->setAddress($this->text($data['address'] ?? null))
            ->setClient($client)
            ->setNote($this->text($data['note'] ?? null))
            ->setActive(! isset($data['active']) || (bool) $data['active']);

        if ($site->getId() === null) {
            $this->em->persist($site);
        }

        $this->em->flush();

        return $site;
    }

    private function category(mixed $id, CategoryScope $scope): ?WhCategory
    {
        if (! ctype_digit((string) $id)) {
            return null;
        }

        $category = $this->categories->find((int) $id);

        return $category?->getScope() === $scope ? $category : null;
    }

    /**
     * Характеристики з форми: attrName[] / attrValue[] — паралельні масиви.
     * Порожні рядки пропускаємо, щоб незаповнена підказка не ставала «Вага: ».
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function attributes(array $data): array
    {
        $names = (array) ($data['attrName'] ?? []);
        $values = (array) ($data['attrValue'] ?? []);
        $attributes = [];

        foreach ($names as $index => $name) {
            $name = trim((string) $name);
            $value = trim((string) ($values[$index] ?? ''));

            if ($name !== '' && $value !== '') {
                $attributes[mb_substr($name, 0, 60)] = mb_substr($value, 0, 255);
            }
        }

        return $attributes;
    }

    private function hasMovements(WhItem $item): bool
    {
        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM wh_movement_line WHERE item_id = :item LIMIT 1',
            ['item' => $item->getId()],
        );
    }

    private function guard(TelegramUser $by): void
    {
        if (! WarehouseSection::canManage($by)) {
            throw new WarehouseException('Склад ведуть менеджер, адміністратор або директор.');
        }
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function money(mixed $value, string $field): ?string
    {
        $value = str_replace([',', ' ', ' '], ['.', '', ''], trim((string) $value));

        if ($value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw new WarehouseException($field . ' — має бути числом, наприклад 1250.50.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function date(mixed $value): ?DateTime
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $date = DateTime::createFromFormat('!Y-m-d', $value);

        if ($date === false) {
            throw new WarehouseException('Дата — у форматі РРРР-ММ-ДД.');
        }

        return $date;
    }
}
