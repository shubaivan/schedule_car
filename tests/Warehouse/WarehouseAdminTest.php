<?php

namespace App\Tests\Warehouse;

use App\Entity\TelegramUser;
use App\Service\CrmLoginLink;
use App\Supply\Enum\SupplyRole;
use App\Supply\Entity\Supplier;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhDocument;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\Tracking;
use App\Warehouse\Service\WarehouseDirectory;
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

    public function testSupplierIsPickedFromTheSharedDirectoryOrAddedOnTheFly(): void
    {
        $this->login($this->person());
        $category = $this->category('Опалубка', 'ОП');

        $this->browser->request('GET', '/sklad/items/new');
        $token = $this->token();
        $this->browser->request('POST', '/sklad/items/new', [
            '_token' => $token,
            'name' => 'Щит 1200×600 А',
            'categoryId' => $category->getId(),
            'tracking' => 'unit',
            'supplierId' => WarehouseDirectory::NEW . 'ФОП Петренко-Тест О.П.',
        ]);
        self::assertResponseRedirects();

        $first = $this->em->getRepository(WhItem::class)->findOneBy(['name' => 'Щит 1200×600 А'])->getSupplier();
        self::assertNotNull($first, 'дописаний постачальник заведений у довідник');

        // Те саме, набране інакше, — не другий постачальник, а той самий.
        $this->browser->request('POST', '/sklad/items/new', [
            '_token' => $token,
            'name' => 'Щит 1200×600 Б',
            'categoryId' => $category->getId(),
            'tracking' => 'unit',
            'supplierId' => WarehouseDirectory::NEW . 'фоп  петренко-тест оп',
        ]);
        self::assertResponseRedirects();
        self::assertSame($first->getId(), $this->em->getRepository(WhItem::class)->findOneBy(['name' => 'Щит 1200×600 Б'])->getSupplier()->getId());
        self::assertSame(1, $this->em->getRepository(Supplier::class)->count(['nameNormalized' => Supplier::normalize('ФОП Петренко-Тест О.П.')]));

        // І він є в підказці select2 — разом із постачальниками заявок.
        $this->browser->request('GET', '/sklad/suppliers?q=петренко-тест');
        self::assertResponseIsSuccessful();
        $results = json_decode((string) $this->browser->getResponse()->getContent(), true)['results'];
        self::assertSame([$first->getId()], array_column($results, 'id'));

        $this->browser->request('GET', '/sklad?q=петренко-тест');
        self::assertSelectorTextContains('table', 'Щит 1200×600 А');
    }

    public function testPhotosFromTheFormShowOnTheCardAndStayOnTheServer(): void
    {
        $this->login($this->person());
        $category = $this->category('Риштування', 'РШ');

        $png = tempnam(sys_get_temp_dir(), 'wh') . '.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        $this->browser->request('GET', '/sklad/items/new');
        $token = $this->token();
        $this->browser->request(
            'POST',
            '/sklad/items/new',
            ['_token' => $token, 'name' => 'Риштування рамне', 'categoryId' => $category->getId(), 'tracking' => 'unit'],
            ['photos' => [new UploadedFile($png, 'риштування.png', 'image/png', null, true)]],
        );
        self::assertResponseRedirects();

        $item = $this->em->getRepository(WhItem::class)->findOneBy(['name' => 'Риштування рамне']);
        $photo = $this->em->getRepository(WhDocument::class)->findOneBy(['item' => $item]);
        self::assertNotNull($photo);
        self::assertTrue($photo->isViewablePhoto());
        self::assertNotContains($photo, $this->em->getRepository(WhDocument::class)->findNotMirrored(500), 'фото позиції не їде на Google Диск');

        $this->browser->request('GET', '/sklad/items/' . $item->getId());
        self::assertCount(1, $this->browser->getCrawler()->filter('.gallery img'));

        $this->browser->request('GET', '/sklad?q=риштування рамне');
        self::assertCount(1, $this->browser->getCrawler()->filter('img.cover'));
    }

    public function testTransferGoesFromOneClientsSiteStraightToAnotherNewOne(): void
    {
        $this->login($this->person());
        $warehouse = $this->warehouse();
        $from = $this->site($this->client('ТОВ Альфа-Тест'), 'ЖК Альфа-Тест');
        $item = $this->item(Tracking::Unit, 'Щит переїзний', '40');

        $this->browser->request('GET', '/sklad/movements/new?type=receipt');
        $token = $this->token();
        $this->browser->request('POST', '/sklad/movements/new?type=receipt', [
            '_token' => $token, 'occurredAt' => '2026-09-01', 'toId' => $warehouse->getId(),
            'item' => [$item->getId()], 'quantity' => ['1'],
        ]);
        $this->browser->request('POST', '/sklad/movements/new?type=shipment', [
            '_token' => $token, 'occurredAt' => '2026-09-02', 'fromId' => $warehouse->getId(), 'toId' => $from->getId(),
            'item' => [$item->getId()], 'quantity' => ['1'],
        ]);
        self::assertResponseRedirects();

        // Новий об'єкт без клієнта не заводимо: оренда на ньому не рахувалась би.
        $this->browser->request('POST', '/sklad/movements/new?type=transfer', [
            '_token' => $token, 'occurredAt' => '2026-09-10', 'fromId' => $from->getId(),
            'toId' => WarehouseDirectory::NEW . 'ЖК Бета-Тест',
            'item' => [$item->getId()], 'quantity' => ['1'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash.error', 'чий він');

        $this->browser->request('POST', '/sklad/movements/new?type=transfer', [
            '_token' => $token, 'occurredAt' => '2026-09-10', 'documentNumber' => 'П-7', 'fromId' => $from->getId(),
            'toId' => WarehouseDirectory::NEW . 'ЖК Бета-Тест',
            'toClientId' => WarehouseDirectory::NEW . 'ТОВ Бета-Тест',
            'item' => [$item->getId()], 'quantity' => ['1'], 'rate' => ['55'],
        ]);
        self::assertResponseRedirects();

        $movement = $this->em->getRepository(WhMovement::class)->findOneBy(['documentNumber' => 'П-7']);
        self::assertSame('ТОВ Бета-Тест', $movement->getToSite()->getClient()->getName());
        self::assertSame('55.00', $movement->getLines()->first()->getRentalRate());
        $item = $this->em->find(WhItem::class, $item->getId());
        self::assertSame($movement->getToSite()->getId(), $item->getCurrentSite()->getId(), 'щит на новому об\'єкті, повз склад');

        $this->browser->request('GET', '/sklad/movements/' . $movement->getId());
        self::assertSelectorTextContains('body', 'Від клієнта');
        self::assertSelectorTextContains('body', 'ТОВ Альфа-Тест');
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
