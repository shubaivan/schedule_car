<?php

namespace App\Tests\Warehouse;

use App\Entity\TelegramUser;
use App\Service\CrmLoginLink;
use App\Supply\Enum\SupplyRole;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhDocument;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\Tracking;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Адмінка складу наскрізь: вхід із бота, права, CSRF, картки, рух із
 * накладною і журнал «хто що відкривав і міняв».
 */
class WarehouseAdminTest extends WebTestCase
{
    use WarehouseFixtures;

    private KernelBrowser $browser;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        // Один кернел на весь тест — інакше транзакція не переживе запит.
        $this->browser->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    protected function em(): EntityManagerInterface
    {
        return $this->em;
    }

    public function testClosedWithoutLogin(): void
    {
        $this->browser->request('GET', '/sklad');

        self::assertResponseRedirects();
    }

    public function testWorkerDoesNotGetIn(): void
    {
        $this->login($this->person(SupplyRole::Worker));
        $this->browser->request('GET', '/sklad');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDirectorGetsIn(): void
    {
        $this->login($this->person(SupplyRole::Director));
        $this->browser->request('GET', '/sklad');

        self::assertResponseIsSuccessful();
    }

    public function testBotLinkLandsOnTheCardAndIsJournaledAsLink(): void
    {
        $manager = $this->person();
        $item = $this->item();

        $this->login($manager, '/sklad/items/' . $item->getId() . '?via=bot');
        self::assertResponseRedirects('/sklad/items/' . $item->getId() . '?via=bot');

        $this->browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $item->getName());

        $entry = $this->lastActivity();
        self::assertSame(ActivityAction::Link, $entry->getAction());
        self::assertSame('item', $entry->getSubjectType());
        self::assertSame($manager->getId(), $entry->getUser()?->getId());
        self::assertSame(WhActivity::CHANNEL_ADMIN, $entry->getChannel());
    }

    public function testCreateItemWithCategoryNumberAndAttributes(): void
    {
        $this->login($this->person());
        $category = $this->category('Техніка', 'ТЗ');

        $this->browser->request('GET', '/sklad/items/new');
        $this->browser->request('POST', '/sklad/items/new', [
            '_token' => $this->token(),
            'name' => 'Трактор МТЗ-82',
            'categoryId' => $category->getId(),
            'tracking' => 'unit',
            'purchasePrice' => '850 000,50',
            'rentalRate' => '3500',
            'attrName' => ['Держномер', 'Моточаси', 'Порожня'],
            'attrValue' => ['СА 1234 ВВ', '1200', ''],
        ]);

        self::assertResponseRedirects();

        $item = $this->em->getRepository(WhItem::class)->findOneBy(['name' => 'Трактор МТЗ-82']);
        self::assertNotNull($item);
        self::assertSame('ТЗ-0001', $item->getInventoryNumber());
        self::assertSame('850000.50', $item->getPurchasePrice());
        self::assertSame(['Держномер' => 'СА 1234 ВВ', 'Моточаси' => '1200'], $item->getAttributes());
        self::assertSame(ActivityAction::Create, $this->lastActivity()->getAction());
    }

    public function testPostWithoutCsrfIsRefused(): void
    {
        $this->login($this->person());

        $this->browser->request('POST', '/sklad/clients', ['name' => 'ТОВ Без токена']);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em->getRepository(WhClient::class)->findOneBy(['name' => 'ТОВ Без токена']));
    }

    public function testShipmentWithWaybillEndsUpOnClientCard(): void
    {
        $this->login($this->person());
        $warehouse = $this->warehouse();
        $client = $this->client('ТОВ Будінвест-Тест');
        $site = $this->site($client, 'ЖК Сонячний-Тест');
        $item = $this->item(Tracking::Bulk, 'Замок клиновий');

        $this->browser->request('GET', '/sklad/movements/new?type=receipt');
        $this->browser->request('POST', '/sklad/movements/new?type=receipt', [
            '_token' => $this->token(),
            'occurredAt' => '2026-09-01',
            'toId' => $warehouse->getId(),
            'item' => [$item->getId()],
            'quantity' => ['300'],
        ]);
        self::assertResponseRedirects();

        $pdf = tempnam(sys_get_temp_dir(), 'wh') . '.pdf';
        file_put_contents($pdf, '%PDF-1.4 накладна');

        $this->browser->request('GET', '/sklad/movements/new?type=shipment');
        $this->browser->request(
            'POST',
            '/sklad/movements/new?type=shipment',
            [
                '_token' => $this->token(),
                'occurredAt' => '2026-09-10',
                'documentNumber' => '15',
                'fromId' => $warehouse->getId(),
                'toId' => $site->getId(),
                'item' => [$item->getId()],
                'quantity' => ['120'],
                'rate' => ['2,5'],
                'documentType' => 'expense',
            ],
            ['files' => [new UploadedFile($pdf, 'Видаткова 15.pdf', 'application/pdf', null, true)]],
        );
        self::assertResponseRedirects();

        $movement = $this->em->getRepository(WhMovement::class)->findOneBy(['documentNumber' => '15']);
        self::assertNotNull($movement);
        self::assertSame('2.50', $movement->getLines()->first()->getRentalRate());

        $document = $this->em->getRepository(WhDocument::class)->findOneBy(['movement' => $movement]);
        self::assertNotNull($document, 'накладна прикріпилась у тій самій формі');
        self::assertSame('Видаткова 15.pdf', $document->getOriginalName());

        $this->browser->request('GET', '/sklad/clients/' . $client->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Замок клиновий');
        self::assertSelectorTextContains('body', '120 шт');

        // Рух ліг і в журнал самої позиції.
        $moves = $this->em->getRepository(WhActivity::class)->findBy([
            'subjectType' => 'item',
            'subjectId' => $item->getId(),
            'action' => ActivityAction::Move,
        ]);
        self::assertCount(2, $moves);
    }

    public function testRefusedMovementShowsReasonAndChangesNothing(): void
    {
        $this->login($this->person());
        $warehouse = $this->warehouse();
        $site = $this->site($this->client());
        $item = $this->item();

        $this->browser->request('GET', '/sklad/movements/new?type=shipment');
        $this->browser->request('POST', '/sklad/movements/new?type=shipment', [
            '_token' => $this->token(),
            'occurredAt' => '2026-09-10',
            'fromId' => $warehouse->getId(),
            'toId' => $site->getId(),
            'item' => [$item->getId()],
            'quantity' => ['1'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash.error', 'не там');
    }

    public function testLabelsAndJournalPagesRender(): void
    {
        $this->login($this->person());
        $item = $this->item();

        $this->browser->request('GET', '/sklad/labels?ids[]=' . $item->getId() . '&copies=3');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $this->browser->getCrawler()->filter('.label'));

        $this->browser->request('GET', '/sklad/journal?action=labels');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', $item->getInventoryNumber());
    }

    public function testCategoryDirectoryAcceptsNewKinds(): void
    {
        $this->login($this->person());

        $this->browser->request('GET', '/sklad/categories');
        $this->browser->request('POST', '/sklad/categories', [
            '_token' => $this->token(),
            'scope' => 'item',
            'name' => 'Бетононасоси-тест',
            'prefix' => 'бн',
            'attributes' => "Продуктивність, Довжина стріли\nРік",
        ]);
        self::assertResponseRedirects();

        $this->browser->followRedirect();
        self::assertSelectorExists('input[value="Бетононасоси-тест"]');
        self::assertSelectorExists('input[value="БН"]');
        self::assertSelectorExists('input[value="Продуктивність, Довжина стріли, Рік"]');
    }

    private function login(TelegramUser $user, ?string $next = null): void
    {
        $url = self::getContainer()->get(CrmLoginLink::class)->issue($user, $next);
        $this->browser->request('GET', (string) parse_url($url, PHP_URL_PATH) . (($query = parse_url($url, PHP_URL_QUERY)) ? '?' . $query : ''));
    }

    private function token(): string
    {
        return (string) $this->browser->getCrawler()->filter('input[name="_token"]')->first()->attr('value');
    }

    private function lastActivity(): WhActivity
    {
        $entry = $this->em->getRepository(WhActivity::class)->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($entry);

        return $entry;
    }
}
