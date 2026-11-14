<?php

namespace App\Tests\Warehouse;

use App\Warehouse\Demo\DemoWarehouse;
use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\SiteKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `tasks:demo-seed --reset` on «План» 14.11 died on a FK: during a demo someone
 * added a site to a demo client, and reset removed sites only by their demo names.
 */
class DemoWarehouseResetTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testResetAlsoRemovesSitesAddedToDemoClients(): void
    {
        $client = (new WhClient())->setName(DemoWarehouse::CLIENTS[0][0])->setEdrpou('00000000')->setPhone('380000000109');
        $this->em->persist($client);
        $this->em->persist((new WhSite())->setKind(SiteKind::Site)->setName(DemoWarehouse::CLIENTS[0][2])->setClient($client));
        $this->em->persist((new WhSite())->setKind(SiteKind::Site)->setName('Склад клієнта (е2е)')->setClient($client));
        $this->em->flush();
        $clientId = $client->getId();

        DemoWarehouse::reset($this->em);

        $conn = $this->em->getConnection();
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM wh_client WHERE id = ?', [$clientId]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM wh_site WHERE client_id = ?', [$clientId]));
    }
}
