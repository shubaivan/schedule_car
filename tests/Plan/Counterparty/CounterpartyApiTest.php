<?php

namespace App\Tests\Plan\Counterparty;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Plan\Counterparty\Entity\Counterparty;
use App\Plan\Counterparty\Entity\CounterpartyContact;
use App\Plan\Counterparty\Enum\CounterpartyKind;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectRegistry;
use App\Plan\Subject\SubjectType;
use App\Supply\Entity\Supplier;
use App\Supply\Enum\SupplyRole;
use App\Tasks\Entity\Task;
use App\Tasks\Enum\WorkRole;
use App\Warehouse\Entity\WhClient;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Довідник «Контрагенти»: хто бачить і що може, перевірка реквізитів і дублів,
 * фасети й пошук, архів, рівно одна подія журналу на дію, привʼязка задачі.
 */
class CounterpartyApiTest extends WebTestCase
{
    private const string API = '/api/plan/counterparties';
    /** Вигадані, але з правильною контрольною цифрою. */
    private const string EDRPOU = '12345678';
    private const string EDRPOU_2 = '30000005';
    private const string RNOKPP = '2914177764';
    private const string IBAN = 'UA743052990000026007233566001';

    private KernelBrowser $browser;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->browser->setServerParameter('HTTP_X_REQUESTED_WITH', 'XMLHttpRequest');
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testClosedWithoutLogin(): void
    {
        $this->browser->request('GET', self::API);

        self::assertContains($this->browser->getResponse()->getStatusCode(), [302, 401]);
    }

    /** @return iterable<string, array{WorkRole, SupplyRole, bool, bool}> */
    public static function roles(): iterable
    {
        yield 'виробництво' => [WorkRole::Production, SupplyRole::Worker, false, false];
        yield 'менеджер з продажу' => [WorkRole::Manager, SupplyRole::Worker, true, false];
        yield 'постачання (менеджер заявок)' => [WorkRole::Production, SupplyRole::Manager, true, false];
        yield 'бухгалтер' => [WorkRole::Accountant, SupplyRole::Worker, true, true];
        yield 'директор' => [WorkRole::Director, SupplyRole::Director, true, true];
        yield 'адмін' => [WorkRole::Admin, SupplyRole::Admin, true, true];
    }

    #[DataProvider('roles')]
    public function testWhoSeesAndWhoDecidesMoney(WorkRole $work, SupplyRole $supply, bool $sees, bool $finance): void
    {
        $existing = $this->counterparty('ТОВ «Наявний»');
        $this->browser->loginUser($this->person($work, $supply));

        foreach ([self::API, self::API . '/meta', self::API . '/' . $existing->getId()] as $url) {
            $this->browser->request('GET', $url);
            self::assertSame($sees ? 200 : 403, $this->browser->getResponse()->getStatusCode(), $url);
        }

        $this->send('POST', self::API, ['name' => 'ТОВ «Новий» ' . $work->value . $supply->value, 'kind' => 'client']);
        self::assertSame($sees ? 201 : 403, $this->browser->getResponse()->getStatusCode());

        if ($sees) {
            $this->send('GET', self::API . '/meta');
            self::assertSame(['edit' => true, 'finance' => $finance], $this->json()['can']);

            $this->send('PATCH', self::API . '/' . $existing->getId(), ['creditLimit' => '150000']);
            self::assertResponseStatusCodeSame($finance ? 200 : 403);

            $this->send('POST', self::API . '/' . $existing->getId() . '/archive');
            self::assertResponseStatusCodeSame($finance ? 200 : 403);
        }
    }

    public function testArchivedEmployeeIsLockedOut(): void
    {
        $manager = $this->person(WorkRole::Manager);
        $manager->archive(null);
        $this->em->flush();
        $this->browser->loginUser($manager);

        $this->browser->request('GET', self::API);

        self::assertNotSame(200, $this->browser->getResponse()->getStatusCode());
    }

    public function testWriteWithoutPlanHeaderIsRefused(): void
    {
        $this->browser->loginUser($this->person(WorkRole::Manager));
        $this->browser->setServerParameter('HTTP_X_REQUESTED_WITH', '');

        $this->send('POST', self::API, ['name' => 'ТОВ «Чужа форма»']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM counterparty WHERE name = 'ТОВ «Чужа форма»'"));
    }

    public function testCreateFullCardWithContactsAndOneEvent(): void
    {
        $manager = $this->person(WorkRole::Manager);
        $this->browser->loginUser($manager);
        $events = $this->events();

        $card = $this->send('POST', self::API, [
            'kind' => 'both',
            'legalForm' => 'company',
            'name' => 'ТОВ «Житлобуд-Черкаси»',
            'shortName' => 'Житлобуд',
            'code' => ' 1234 5678 ',
            'vatStatus' => 'payer',
            'vatNumber' => '123456789012',
            'legalAddress' => 'м. Черкаси, вул. Смілянська, 2',
            'phone' => '0472 55 44 33',
            'email' => 'Office@Zhytlobud.UA',
            'iban' => 'ua74 3052 9900 0002 6007 2335 6600 1',
            'bankName' => 'АТ КБ «ПриватБанк»',
            'contacts' => [
                ['name' => 'Петро Іваненко', 'role' => 'виконроб', 'phone' => '067 123 45 67'],
                ['name' => '', 'phone' => ''],
                ['name' => 'Олена', 'role' => 'бухгалтер', 'email' => 'buh@zhytlobud.ua', 'primary' => true],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(self::EDRPOU, $card['code']);
        self::assertSame('+380472554433', $card['phoneRaw']);
        self::assertSame('office@zhytlobud.ua', $card['email']);
        self::assertSame(self::IBAN, $card['iban']);
        self::assertSame('Житлобуд', $card['displayName']);
        self::assertSame($manager->getId(), $card['manager']['id'], 'Менеджер з продажу завів — він і відповідальний');
        self::assertCount(2, $card['contacts'], 'Порожній рядок форми пропущено');
        self::assertSame('+380671234567', $card['contacts'][0]['phoneRaw']);
        self::assertSame('Олена', $card['contact']['name'], 'Основний контакт — позначений');
        self::assertSame(['Картку створено'], array_column($card['history'], 'text'));
        self::assertSame($events + 1, $this->events(), 'Рівно одна подія');

        $registry = self::getContainer()->get(SubjectRegistry::class);
        $ref = SubjectRef::of(SubjectType::Counterparty, (int) $card['id']);
        self::assertSame('Житлобуд', $registry->title($ref));
        self::assertSame('/crm/counterparties/' . $card['id'], $registry->url($ref));
        self::assertTrue($registry->visibleTo($ref, $manager));
        self::assertFalse($registry->visibleTo($ref, $this->person(WorkRole::Production)));
    }

    public function testRequisitesAreChecked(): void
    {
        $this->browser->loginUser($this->person(WorkRole::Accountant));

        $this->send('POST', self::API, ['name' => 'ТОВ Опечатка', 'code' => '12345679']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('code', $this->json()['field']);
        self::assertStringContainsString('контрольної цифри', $this->json()['error']);

        $this->send('POST', self::API, ['name' => 'ТОВ Коротко', 'code' => '1234567']);
        self::assertStringContainsString('8 цифр', $this->json()['error']);

        // ФОП — 10 цифр РНОКПП, а не ЄДРПОУ.
        $this->send('POST', self::API, ['name' => 'ФОП Мельник О. В.', 'legalForm' => 'fop', 'code' => self::EDRPOU]);
        self::assertResponseStatusCodeSame(422);
        $this->send('POST', self::API, ['name' => 'ФОП Мельник О. В.', 'legalForm' => 'fop', 'code' => self::RNOKPP]);
        self::assertResponseStatusCodeSame(201);

        foreach ([['iban' => 'UA743052990000026007233566002'], ['email' => 'не-пошта'], ['phone' => '12'], ['vatStatus' => 'payer', 'vatNumber' => '123'], ['kind' => 'friend'], ['paymentTerms' => 'deferral', 'deferralDays' => 0]] as $bad) {
            $this->send('POST', self::API, ['name' => 'ТОВ Перевірка ' . key($bad), ...$bad]);
            self::assertResponseStatusCodeSame(422, (string) json_encode($bad));
        }

        $this->send('POST', self::API, ['name' => '   ']);
        self::assertSame('name', $this->json()['field']);
    }

    public function testDuplicatesPointToTheExistingCard(): void
    {
        $existing = $this->counterparty('ТОВ «Альфа»', self::EDRPOU);
        $nameOnly = $this->counterparty('Приватний замовник Коваль');
        $this->browser->loginUser($this->person(WorkRole::Manager));

        $this->send('POST', self::API, ['name' => 'Альфа ТОВ (інша назва)', 'code' => self::EDRPOU]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($existing->getId(), $this->json()['existingId']);

        // Та сама назва без коду — ймовірний дубль; інші лапки й регістр не рятують.
        $this->send('POST', self::API, ['name' => 'приватний замовник  КОВАЛЬ', 'legalForm' => 'person']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($nameOnly->getId(), $this->json()['existingId']);

        // З кодом — це вже інша юрособа з тією ж назвою.
        $this->send('POST', self::API, ['name' => 'ТОВ «Альфа»', 'code' => self::EDRPOU_2]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testFinanceChangesAreAuditedWithOldAndNewValue(): void
    {
        $c = $this->counterparty('ТОВ «Будінвест»');
        $this->browser->loginUser($this->person(WorkRole::Accountant));
        $events = $this->events();

        $card = $this->send('PATCH', self::API . '/' . $c->getId(), ['paymentTerms' => 'deferral', 'deferralDays' => 14, 'creditLimit' => '150 000,50']);

        self::assertResponseIsSuccessful();
        self::assertSame('150000.50', $card['creditLimit']);
        self::assertSame('Відстрочка платежу 14 дн.', $card['termsLabel']);
        self::assertSame($events + 1, $this->events());
        self::assertStringContainsString('кредитний ліміт: — → 150000.50', $card['history'][0]['text']);

        // Нічого не змінилось — нової події немає.
        $this->send('PATCH', self::API . '/' . $c->getId(), ['creditLimit' => '150000.5']);
        self::assertSame($events + 1, $this->events());

        // Без відстрочки дні не зберігаються.
        $card = $this->send('PATCH', self::API . '/' . $c->getId(), ['paymentTerms' => 'prepayment']);
        self::assertNull($card['deferralDays']);

        // Менеджер з продажу інші поля править, а ліміт — ні (той самий ліміт можна надіслати).
        $this->browser->loginUser($this->person(WorkRole::Manager));
        $this->send('PATCH', self::API . '/' . $c->getId(), ['note' => 'Платять вчасно', 'creditLimit' => '150000.50']);
        self::assertResponseIsSuccessful();
        $this->send('PATCH', self::API . '/' . $c->getId(), ['creditLimit' => '1000000']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testListSearchFacetsAndArchive(): void
    {
        $manager = $this->person(WorkRole::Manager);
        $zhytlobud = $this->counterparty('ТОВ «Житлобуд-Черкаси»', self::EDRPOU, manager: $manager);
        $metal = $this->counterparty('ТОВ «Металопрокат Плюс»', self::EDRPOU_2, 'supplier');
        $both = $this->counterparty('ФОП Савенко Р. М.', null, 'both');
        $this->contact($metal, 'Іван Степанович', '+380501112233');
        $this->browser->loginUser($this->person(WorkRole::Accountant));

        self::assertSame([$zhytlobud->getId()], $this->ids(['q' => 'житлобуд черкаси']));
        self::assertSame([$zhytlobud->getId()], $this->ids(['q' => '1234']), 'Код — за початком');
        self::assertSame([$metal->getId()], $this->ids(['q' => '111 22 33']), 'Телефон контактної особи');
        self::assertSame([$metal->getId()], $this->ids(['q' => 'степанович']), 'Імʼя контактної особи');
        self::assertEqualsCanonicalizing([$zhytlobud->getId(), $both->getId()], $this->ids(['kind' => 'client']), '«Обидва» — і серед клієнтів');
        self::assertEqualsCanonicalizing([$metal->getId(), $both->getId()], $this->ids(['kind' => 'supplier']));
        self::assertSame([$zhytlobud->getId()], $this->ids(['manager' => (string) $manager->getId()]));

        $this->send('GET', self::API . '?q=тов');
        self::assertSame(['all' => 2, 'client' => 1, 'supplier' => 1, 'archived' => 0], $this->json()['counts']);

        $this->send('POST', self::API . '/' . $metal->getId() . '/archive');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['archived']);
        self::assertSame(['Прибрано в архів'], array_column($this->json()['history'], 'text'), 'Фікстура створена в обхід сервісу — події створення немає');
        self::assertNotContains($metal->getId(), $this->ids([]));
        self::assertSame([$metal->getId()], $this->ids(['archived' => '1']));

        $this->send('PATCH', self::API . '/' . $metal->getId(), ['note' => 'x']);
        self::assertResponseStatusCodeSame(422, 'Архівну картку не правлять');

        $this->send('POST', self::API . '/' . $metal->getId() . '/restore');
        self::assertFalse($this->json()['archived']);
        self::assertContains($metal->getId(), $this->ids([]));
    }

    public function testTaskLinksToCardAndKeepsClientString(): void
    {
        $c = $this->counterparty('ТОВ «Еко-Квартал»');
        $manager = $this->person(WorkRole::Manager);
        $production = $this->person(WorkRole::Production);
        $this->browser->loginUser($manager);

        $task = $this->send('POST', '/api/tasks', ['title' => 'Плити ПК для Еко-Кварталу', 'counterpartyId' => $c->getId(), 'memberIds' => [$production->getId()]]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($c->getId(), $task['counterparty']['id']);
        self::assertSame('ТОВ «Еко-Квартал»', $task['client'], 'Рядок клієнта — з картки');

        // Стара задача з тим самим клієнтом рядком — у картці як «не привʼязана».
        $old = $this->send('POST', '/api/tasks', ['title' => 'Стара угода', 'client' => 'ТОВ «Еко-Квартал»']);
        $card = $this->send('GET', self::API . '/' . $c->getId());
        self::assertEqualsCanonicalizing([[$task['id'], true], [$old['id'], false]], array_map(static fn ($t) => [$t['id'], $t['linked']], $card['tasks']));

        // Клієнта переписали рядком — привʼязка знімається, рядок лишається.
        $updated = $this->send('PATCH', '/api/tasks/' . $task['id'], ['client' => 'Інший замовник']);
        self::assertNull($updated['counterparty']);
        self::assertSame('Інший замовник', $updated['client']);

        // Виробництво довідника не бачить — і привʼязати з нього не може.
        $this->browser->loginUser($production);
        $this->send('PATCH', '/api/tasks/' . $task['id'], ['counterpartyId' => $c->getId()]);
        self::assertSame(422, $this->browser->getResponse()->getStatusCode());
        self::assertNull($this->em->find(Task::class, $task['id'])?->getCounterparty());
    }

    /** Ревʼю 16.11: правка назви тим, хто довідника не бачить, не знімала привʼязку лише тоді, коли рядок = назві картки. */
    public function testLinkSurvivesEditsByPeopleWithoutDirectoryAndCardRename(): void
    {
        $c = $this->counterparty('ТОВ «Еко-Квартал»');
        $author = $this->person(WorkRole::Production);
        $manager = $this->person(WorkRole::Manager);
        $this->browser->loginUser($author);
        $task = $this->send('POST', '/api/tasks', ['title' => 'Плити', 'client' => 'Еко-квартал, секція 3', 'memberIds' => [$manager->getId()]]);

        $this->browser->loginUser($manager);
        $linked = $this->send('PATCH', '/api/tasks/' . $task['id'], ['client' => 'Еко-квартал, секція 3', 'counterpartyId' => $c->getId()]);
        self::assertSame($c->getId(), $linked['counterparty']['id'], 'Свій рядок клієнта лишається, картка — привʼязана');

        $c->setName('ТОВ «Еко-Квартал Плюс»');
        $this->em->flush();

        // Автор-виробничник (форма шле client без counterpartyId) править лише назву.
        $this->browser->loginUser($author);
        $same = $this->send('PATCH', '/api/tasks/' . $task['id'], ['title' => 'Плити ПК', 'client' => 'Еко-квартал, секція 3']);
        self::assertSame($c->getId(), $same['counterparty']['id'] ?? null, 'Рядок не змінився — привʼязка на місці');

        $changed = $this->send('PATCH', '/api/tasks/' . $task['id'], ['client' => 'Інший замовник']);
        self::assertNull($changed['counterparty']);
    }

    public function testStaleFormGetsConflictInsteadOfOverwriting(): void
    {
        $c = $this->counterparty('ТОВ «Будінвест»');
        $this->browser->loginUser($this->person(WorkRole::Accountant));
        $opened = $this->send('GET', self::API . '/' . $c->getId())['version'];

        $first = $this->send('PATCH', self::API . '/' . $c->getId(), ['note' => 'Оплата лише з рахунку', 'version' => $opened]);
        self::assertResponseIsSuccessful();
        self::assertSame($opened + 1, $first['version']);

        // Друга людина відкрила форму ще до цієї правки.
        $this->send('PATCH', self::API . '/' . $c->getId(), ['note' => 'Інше', 'version' => $opened]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('version', $this->json()['field']);
        self::assertSame('Оплата лише з рахунку', $this->send('GET', self::API . '/' . $c->getId())['note']);

        // Без змін — номер правки не росте.
        $this->send('PATCH', self::API . '/' . $c->getId(), ['note' => 'Оплата лише з рахунку', 'version' => $first['version']]);
        self::assertSame($first['version'], $this->json()['version']);
    }

    public function testLinkCommandMatchesByCodeThenByName(): void
    {
        $alpha = $this->counterparty('ТОВ «Альфа-Бетон»', self::EDRPOU);
        $beta = $this->counterparty('ТОВ «Бета»');
        $byCode = new Supplier()->setName('Альфа Бетон (стара назва)')->setEdrpou(self::EDRPOU);
        $byName = new WhClient()->setName('тов бета');
        $none = new Supplier()->setName('ФОП Невідомий');
        // Та сама назва, що в картки з кодом, але свій інший код — інша юрособа.
        $this->counterparty('ТОВ «Гамма»', self::EDRPOU_2);
        $otherCode = new Supplier()->setName('ТОВ Гамма')->setEdrpou('14360570');
        // Архівна картка не привʼязується.
        $this->counterparty('ТОВ «Дельта»')->archive(null);
        $archived = new WhClient()->setName('ТОВ Дельта');
        foreach ([$byCode, $byName, $none, $otherCode, $archived] as $e) {
            $this->em->persist($e);
        }
        $this->em->flush();

        $tester = new CommandTester(new Application(self::$kernel)->find('plan:counterparties:link'));
        $tester->execute(['--dry-run' => true]);
        self::assertStringContainsString('dry-run', $tester->getDisplay());
        self::assertNull($this->db()->fetchOne('SELECT counterparty_id FROM supply_supplier WHERE id = ?', [$byCode->getId()]));

        $tester->execute([]);
        self::assertSame($alpha->getId(), (int) $this->db()->fetchOne('SELECT counterparty_id FROM supply_supplier WHERE id = ?', [$byCode->getId()]));
        self::assertSame($beta->getId(), (int) $this->db()->fetchOne('SELECT counterparty_id FROM wh_client WHERE id = ?', [$byName->getId()]));
        self::assertNull($this->db()->fetchOne('SELECT counterparty_id FROM supply_supplier WHERE id = ?', [$none->getId()]));
        self::assertNull($this->db()->fetchOne('SELECT counterparty_id FROM supply_supplier WHERE id = ?', [$otherCode->getId()]), 'Різні коди — не звʼязуємо за назвою');
        self::assertNull($this->db()->fetchOne('SELECT counterparty_id FROM wh_client WHERE id = ?', [$archived->getId()]), 'Архівна картка');
        self::assertStringContainsString('інший код', $tester->getDisplay());

        $this->browser->loginUser($this->person(WorkRole::Director, SupplyRole::Director));
        $card = $this->send('GET', self::API . '/' . $alpha->getId());
        self::assertSame(['Альфа Бетон (стара назва)'], array_column($card['links']['suppliers'], 'name'));
    }

    private function person(WorkRole $work, SupplyRole $supply = SupplyRole::Worker): TelegramUser
    {
        $user = new TelegramUser()->setPlanFullAccess(true)
            ->setTelegramId('cp-' . uniqid('', true))
            ->setFirstName('Тест-Контрагент')
            ->setWorkRole($work)
            ->setSupplyRole($supply);
        $user->decideAccess(AccessStatus::Approved, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function counterparty(string $name, ?string $code = null, string $kind = 'client', ?TelegramUser $manager = null): Counterparty
    {
        $c = new Counterparty()->setName($name)->setCode($code)->setManager($manager)
            ->setKind(CounterpartyKind::from($kind));
        $this->em->persist($c);
        $this->em->flush();

        return $c;
    }

    private function contact(Counterparty $c, string $name, string $phone): void
    {
        $contact = new CounterpartyContact($c, $name)->setPhone($phone);
        $c->addContact($contact);
        $this->em->persist($contact);
        $this->em->flush();
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<int>
     */
    private function ids(array $query): array
    {
        $this->send('GET', self::API . ($query !== [] ? '?' . http_build_query($query) : ''));
        self::assertResponseIsSuccessful();

        // Лише свої: у тестовій БД можуть бути контрагенти інших тестів (вони відкочуються, але не demo-seed).
        return array_values(array_map(static fn ($r) => (int) $r['id'], $this->json()['items']));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, array $body = []): array
    {
        $this->browser->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: $method === 'GET' ? null : (string) json_encode($body));

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->browser->getResponse()->getContent(), true);
    }

    private function events(): int
    {
        return (int) $this->db()->fetchOne("SELECT COUNT(*) FROM plan_event WHERE subject_type = 'counterparty'");
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
