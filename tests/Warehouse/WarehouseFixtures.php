<?php

namespace App\Tests\Warehouse;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Supply\Enum\SupplyRole;
use App\Warehouse\Entity\WhCategory;
use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\CategoryScope;
use App\Warehouse\Enum\SiteKind;
use App\Warehouse\Enum\Tracking;
use Doctrine\ORM\EntityManagerInterface;

/** Спільні заготовки для тестів складу — усе всередині транзакції тесту. */
trait WarehouseFixtures
{
    abstract protected function em(): EntityManagerInterface;

    protected function person(SupplyRole $role = SupplyRole::Manager): TelegramUser
    {
        $user = (new TelegramUser())
            ->setTelegramId('wh-' . uniqid('', true))
            ->setFirstName('Тест-Склад')
            ->setSupplyRole($role);
        $user->decideAccess(AccessStatus::Approved, null);

        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    protected function category(string $name = 'Опалубка', string $prefix = 'ТСТ'): WhCategory
    {
        $category = (new WhCategory())
            ->setScope(CategoryScope::Item)
            ->setName($name . ' ' . uniqid())
            ->setPrefix($prefix);

        $this->em()->persist($category);
        $this->em()->flush();

        return $category;
    }

    protected function warehouse(string $name = 'Тест-склад'): WhSite
    {
        $site = (new WhSite())->setKind(SiteKind::Warehouse)->setName($name);
        $this->em()->persist($site);
        $this->em()->flush();

        return $site;
    }

    protected function site(?WhClient $client = null, string $name = 'ЖК Тестовий'): WhSite
    {
        $site = (new WhSite())->setKind(SiteKind::Site)->setName($name)->setClient($client);
        $this->em()->persist($site);
        $this->em()->flush();

        return $site;
    }

    protected function client(string $name = 'ТОВ Тест-Клієнт'): WhClient
    {
        $client = (new WhClient())->setName($name);
        $this->em()->persist($client);
        $this->em()->flush();

        return $client;
    }

    protected function item(Tracking $tracking = Tracking::Unit, string $name = 'Щит 1200×600', ?string $rate = null): WhItem
    {
        $item = (new WhItem())
            ->setInventoryNumber('T-' . uniqid())
            ->setName($name)
            ->setCategory($this->category())
            ->setTracking($tracking)
            ->setUnit($tracking === Tracking::Bulk ? 'шт' : 'шт')
            ->setRentalRate($rate);

        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }
}
