<?php

namespace App\Plan\Counterparty\Service;

use App\Entity\TelegramUser;
use App\Plan\Counterparty\Entity\Counterparty;
use App\Plan\Counterparty\Entity\CounterpartyContact;
use App\Plan\Event\StatusChanged;
use App\Plan\Event\SubjectClosed;
use App\Plan\Event\SubjectCreated;
use App\Plan\Event\SubjectUpdated;
use App\Plan\People\PhoneFormat;
use App\Plan\Subject\SubjectType;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Repository\TaskVisibility;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/** JSON контрагента для «Плану»: рядок списку, картка з контактами, задачами й історією. */
final readonly class CounterpartyPresenter
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array<string, mixed> */
    public function row(Counterparty $c): array
    {
        $primary = $this->primaryContact($c);

        return [
            'id' => $c->getId(),
            'name' => $c->getName(),
            'shortName' => $c->getShortName(),
            'displayName' => $c->displayName(),
            'kind' => $c->getKind()->value,
            'kindLabel' => $c->getKind()->label(),
            'legalForm' => $c->getLegalForm()->value,
            'legalFormLabel' => $c->getLegalForm()->label(),
            'code' => $c->getCode(),
            'codeLabel' => $c->getLegalForm()->codeLabel(),
            'vatStatus' => $c->getVatStatus()?->value,
            'vatLabel' => $c->getVatStatus()?->label(),
            'paymentTerms' => $c->getPaymentTerms()?->value,
            'termsLabel' => $this->terms($c),
            'creditLimit' => $c->getCreditLimit(),
            'manager' => $this->person($c->getManager()),
            'phone' => PhoneFormat::display($c->getPhone()),
            'contact' => $primary === null ? null : $this->contact($primary),
            'contactsCount' => $c->getContacts()->count(),
            'archived' => $c->isArchived(),
        ];
    }

    /** @return array<string, mixed> */
    public function card(Counterparty $c, TelegramUser $viewer): array
    {
        return [
            ...$this->row($c),
            'vatNumber' => $c->getVatNumber(),
            'legalAddress' => $c->getLegalAddress(),
            'actualAddress' => $c->getActualAddress(),
            'phoneRaw' => $c->getPhone(),
            'tel' => PhoneFormat::tel($c->getPhone()),
            'email' => $c->getEmail(),
            'iban' => $c->getIban(),
            'bankName' => $c->getBankName(),
            'deferralDays' => $c->getDeferralDays(),
            'note' => $c->getNote(),
            'externalCode' => $c->getExternalCode(),
            'contacts' => array_map($this->contact(...), $c->getContacts()->toArray()),
            'createdAt' => $this->date($c->getCreatedAt()),
            'createdBy' => $this->person($c->getCreatedBy()),
            'archivedAt' => $this->date($c->getArchivedAt()),
            'archivedBy' => $this->person($c->getArchivedBy()),
            'tasks' => $this->tasks($c, $viewer),
            'links' => $this->links($c),
            'history' => $this->history($c),
        ];
    }

    /** @return array<string, mixed> */
    public function contact(CounterpartyContact $x): array
    {
        return [
            'id' => $x->getId(),
            'name' => $x->getName(),
            'role' => $x->getRole(),
            'phone' => PhoneFormat::display($x->getPhone()),
            'phoneRaw' => $x->getPhone(),
            'tel' => PhoneFormat::tel($x->getPhone()),
            'email' => $x->getEmail(),
            'primary' => $x->isPrimary(),
        ];
    }

    /** @return array{id: int|null, name: string}|null */
    public function person(?TelegramUser $user): ?array
    {
        return $user === null ? null : ['id' => $user->getId(), 'name' => $user->shortName()];
    }

    private function primaryContact(Counterparty $c): ?CounterpartyContact
    {
        $first = null;

        foreach ($c->getContacts() as $contact) {
            if ($contact->isPrimary()) {
                return $contact;
            }

            $first ??= $contact;
        }

        return $first;
    }

    private function terms(Counterparty $c): ?string
    {
        $terms = $c->getPaymentTerms();

        if ($terms === null) {
            return null;
        }

        return $c->getDeferralDays() !== null ? sprintf('%s %d дн.', $terms->label(), $c->getDeferralDays()) : $terms->label();
    }

    /**
     * Задачі (угоди) з цим контрагентом — у межах того, що людина бачить. Привʼязані
     * до картки й ті, де клієнта записано тим самим рядком, але ще не привʼязано
     * (старі задачі) — їх можна привʼязати з форми задачі.
     *
     * @return list<array<string, mixed>>
     */
    private function tasks(Counterparty $c, TelegramUser $viewer): array
    {
        [$visible, $params] = TaskVisibility::sql($viewer, 't');
        $names = array_values(array_unique(array_filter([mb_strtolower(trim($c->getName())), mb_strtolower(trim((string) $c->getShortName()))])));

        $rows = $this->db->fetchAllAssociative(
            sprintf(
                'SELECT t.id, t.title, t.status, t.counterparty_id, t.last_activity_at FROM task t
                 WHERE (t.counterparty_id = :cp OR (t.counterparty_id IS NULL AND LOWER(TRIM(t.client)) = ANY(CAST(:names AS text[])))) AND %s
                 ORDER BY t.last_activity_at DESC LIMIT 30',
                $visible,
            ),
            [...$params, 'cp' => $c->getId(), 'names' => '{' . implode(',', array_map(static fn (string $n) => '"' . addcslashes($n, '"\\') . '"', $names)) . '}'],
        );

        return array_map(static function (array $r): array {
            $status = TaskStatus::tryFrom((string) $r['status']);

            return [
                'id' => (int) $r['id'],
                'number' => sprintf('%04d', (int) $r['id']),
                'title' => (string) $r['title'],
                'statusLabel' => $status?->label() ?? (string) $r['status'],
                'closed' => $status?->isClosed() ?? false,
                'linked' => $r['counterparty_id'] !== null,
            ];
        }, $rows);
    }

    /**
     * Ті самі люди в старих довідниках: постачальник заявок і клієнт складу, привʼязані
     * до картки (plan:counterparties:link).
     *
     * @return array{suppliers: list<array{id: int, name: string}>, warehouseClients: list<array{id: int, name: string}>}
     */
    private function links(Counterparty $c): array
    {
        $map = static fn (array $rows): array => array_map(static fn (array $r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);

        return [
            'suppliers' => $map($this->db->fetchAllAssociative('SELECT id, name FROM supply_supplier WHERE counterparty_id = ? ORDER BY name', [$c->getId()])),
            'warehouseClients' => $map($this->db->fetchAllAssociative('SELECT id, name FROM wh_client WHERE counterparty_id = ? ORDER BY name', [$c->getId()])),
        ];
    }

    /**
     * Історія картки з журналу подій (plan_event): хто створив, що змінював, архів.
     *
     * @return list<array{at: string, who: string|null, text: string}>
     */
    private function history(Counterparty $c): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT e.type, e.occurred_at, e.payload, NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), '') AS who
             FROM plan_event e LEFT JOIN telegram_user u ON u.id = e.actor_id
             WHERE e.subject_type = ? AND e.subject_id = ? ORDER BY e.id DESC LIMIT 50",
            [SubjectType::Counterparty->value, $c->getId()],
        );

        return array_map(static function (array $r): array {
            $payload = json_decode((string) $r['payload'], true);
            $payload = is_array($payload) ? $payload : [];

            return [
                // occurred_at — київський час без зони (як усі дати в БД).
                'at' => new DateTimeImmutable((string) $r['occurred_at'], new DateTimeZone('Europe/Kyiv'))->format(DateTimeInterface::ATOM),
                'who' => $r['who'] !== null ? (string) $r['who'] : null,
                'text' => match ($r['type']) {
                    SubjectCreated::TYPE => 'Картку створено',
                    SubjectUpdated::TYPE => 'Змінено: ' . (is_string($payload['summary'] ?? null) && $payload['summary'] !== '' ? $payload['summary'] : implode(', ', (array) ($payload['fields'] ?? []))),
                    SubjectClosed::TYPE => 'Прибрано в архів',
                    StatusChanged::TYPE => 'Повернуто з архіву',
                    default => (string) $r['type'],
                },
            ];
        }, $rows);
    }

    private function date(?DateTimeInterface $date): ?string
    {
        return $date?->format(DateTimeInterface::ATOM);
    }
}
