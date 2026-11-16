<?php

namespace App\Plan\Counterparty\Demo;

use App\Entity\TelegramUser;
use App\Plan\Counterparty\Entity\Counterparty;
use App\Plan\Counterparty\Entity\CounterpartyContact;
use App\Plan\Counterparty\Enum\CounterpartyKind;
use App\Plan\Counterparty\Enum\LegalForm;
use App\Plan\Counterparty\Enum\PaymentTerms;
use App\Plan\Counterparty\Enum\VatStatus;
use App\Plan\Event\SubjectCreated;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Демо-контрагенти для tasks:demo-seed: клієнти з демо-угод (ті самі назви, тож
 * задачі привʼязуються до карток) і кілька постачальників. Коди — вигадані, але
 * з правильною контрольною цифрою. Лише для демо-стенду.
 */
final readonly class DemoCounterparties
{
    /** [назва, коротко, вид, форма, код, ПДВ, умови, днів, ліміт, менеджер, контакти [[імʼя, посада, телефон]]] */
    public const array ROWS = [
        ['ТОВ «Житлобуд-Черкаси»', 'Житлобуд', 'client', 'company', '45260189', 'payer', 'deferral', 14, '400000.00', 'prodazhi', [['Петро Іваненко', 'виконроб', '0671234501'], ['Олена Гнатюк', 'бухгалтер', '0472554433']]],
        ['ТОВ «Будінвест»', 'Будінвест', 'client', 'company', '41590833', 'payer', 'partial', null, '150000.00', 'prodazhi', [['Ігор Сидоренко', 'директор', '0501112233']]],
        ['ТОВ «Еко-Квартал»', null, 'client', 'company', '40166137', 'payer', 'prepayment', null, null, 'maryna.tkachenko', [['Анна Литвиненко', 'постачальник', '0939998877']]],
        ['КП «Міськшляхбуд»', 'Міськшляхбуд', 'client', 'company', '41860914', 'non_payer', 'deferral', 30, '250000.00', 'dmytro.lysenko', [['Василь Кравчук', 'головний інженер', '0472321100']]],
        ['ПрАТ «Смілянський елеватор»', 'Елеватор', 'client', 'company', '43909961', 'payer', 'on_delivery', null, null, 'maryna.tkachenko', []],
        ['ФОП Мельник О. В.', null, 'client', 'fop', '3797543230', 'non_payer', 'prepayment', null, null, 'prodazhi', [['Олександр Мельник', null, '0687776655']]],
        ['ФОП Савенко Р. М.', null, 'both', 'fop', '3194875742', 'non_payer', 'prepayment', null, null, 'dmytro.lysenko', []],
        ['ТОВ «Металопрокат Плюс»', 'Металопрокат', 'supplier', 'company', '40308241', 'payer', 'deferral', 21, null, 'olha.petrenko', [['Роман Дяченко', 'менеджер', '0675554433']]],
        ['ТОВ «Цемент-Схід»', 'Цемент-Схід', 'supplier', 'company', '46281943', 'payer', 'prepayment', null, null, 'olha.petrenko', [['Наталія Бойко', 'відділ продажу', '0661237788']]],
        ['ТОВ «Логістик-Парк»', null, 'both', 'company', '48219935', 'payer', 'on_delivery', null, null, 'bohdan.zakharchenko', []],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private PlanEventRecorder $events,
    ) {
    }

    /**
     * @param array<string, TelegramUser> $people логін демо-людини → людина
     *
     * @return int скільки карток створено
     */
    public function seed(array $people): int
    {
        $created = [];

        foreach (self::ROWS as [$name, $short, $kind, $form, $code, $vat, $terms, $days, $limit, $manager, $contacts]) {
            $c = new Counterparty()
                ->setName($name)
                ->setShortName($short)
                ->setKind(CounterpartyKind::from($kind))
                ->setLegalForm(LegalForm::from($form))
                ->setCode($code)
                ->setVatStatus(VatStatus::from($vat))
                ->setPaymentTerms(PaymentTerms::from($terms))
                ->setDeferralDays($days)
                ->setCreditLimit($limit)
                ->setManager($people[$manager] ?? null)
                ->setCreatedBy($people[$manager] ?? null)
                ->setLegalAddress('м. Черкаси');

            foreach ($contacts as $i => [$person, $role, $phone]) {
                $c->addContact(new CounterpartyContact($c, $person)->setRole($role)->setPhone('+38' . $phone)->setPrimary($i === 0)->setPosition($i));
            }

            $this->em->persist($c);
            $created[] = $c;
        }

        $this->em->flush();

        foreach ($created as $c) {
            $this->events->record(new SubjectCreated(SubjectRef::of(SubjectType::Counterparty, (int) $c->getId()), $c->getCreatedBy()?->getId(), title: $c->getName()));
        }

        // Демо-угоди з тими самими клієнтами — привʼязані до карток.
        $this->em->getConnection()->executeStatement('UPDATE task t SET counterparty_id = c.id FROM counterparty c WHERE t.counterparty_id IS NULL AND t.client = c.name');

        return count($created);
    }

    /** Лише демо-картки (за назвами з ROWS): справжніх контрагентів, якщо вони є, не чіпаємо. */
    public static function reset(EntityManagerInterface $em): void
    {
        $conn = $em->getConnection();
        $names = array_map(static fn (array $row): string => $row[0], self::ROWS);
        $ids = array_map('intval', $conn->fetchFirstColumn('SELECT id FROM counterparty WHERE name IN (?)', [$names], [ArrayParameterType::STRING]));

        if ($ids === []) {
            return;
        }

        foreach (['task', 'supply_supplier', 'wh_client'] as $table) {
            $conn->executeStatement(sprintf('UPDATE %s SET counterparty_id = NULL WHERE counterparty_id IN (?)', $table), [$ids], [ArrayParameterType::INTEGER]);
        }

        $conn->executeStatement("DELETE FROM plan_event WHERE subject_type = 'counterparty' AND subject_id IN (?)", [$ids], [ArrayParameterType::INTEGER]);
        $conn->executeStatement('DELETE FROM counterparty WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
    }
}
