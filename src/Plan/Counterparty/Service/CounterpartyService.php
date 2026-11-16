<?php

namespace App\Plan\Counterparty\Service;

use App\Entity\TelegramUser;
use App\Plan\Access\PlanAccess;
use App\Plan\Access\PlanCapability;
use App\Plan\Counterparty\Entity\Counterparty;
use App\Plan\Counterparty\Entity\CounterpartyContact;
use App\Plan\Counterparty\Enum\CounterpartyKind;
use App\Plan\Counterparty\Enum\LegalForm;
use App\Plan\Counterparty\Enum\PaymentTerms;
use App\Plan\Counterparty\Enum\VatStatus;
use App\Plan\Counterparty\Repository\CounterpartyRepository;
use App\Plan\Event\StatusEvent;
use App\Plan\Event\SubjectCreated;
use App\Plan\Event\SubjectUpdated;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\People\PhoneFormat;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use App\Tasks\Enum\WorkRole;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Усе, що змінює картку контрагента. Перевіряє реквізити (контрольні цифри
 * ЄДРПОУ/РНОКПП, IBAN), ловить дублі (той самий код, та сама назва без коду) і
 * пише рівно одну подію в plan_event на дію — у тій самій транзакції. Журнал
 * подій і є аудитом: «хто підняв кредитний ліміт» видно в summary.
 *
 * Умови оплати, відстрочку, кредитний ліміт і архів змінює лише той, хто має
 * counterparties.finance (бухгалтер, директор, адмін).
 */
class CounterpartyService
{
    /** Поля, які без права finance змінити не можна. */
    public const array FINANCE_FIELDS = ['paymentTerms', 'deferralDays', 'creditLimit'];

    private const int MAX_CONTACTS = 20;

    /** Назви полів у журналі змін (summary події). */
    private const array LABELS = [
        'kind' => 'вид',
        'legalForm' => 'форма',
        'name' => 'назва',
        'shortName' => 'коротка назва',
        'code' => 'код',
        'vatStatus' => 'ПДВ',
        'vatNumber' => 'ІПН ПДВ',
        'legalAddress' => 'юр. адреса',
        'actualAddress' => 'факт. адреса',
        'phone' => 'телефон',
        'email' => 'email',
        'iban' => 'IBAN',
        'bankName' => 'банк',
        'paymentTerms' => 'умови оплати',
        'deferralDays' => 'відстрочка, днів',
        'creditLimit' => 'кредитний ліміт',
        'manager' => 'менеджер',
        'note' => 'примітки',
        'externalCode' => 'код 1С',
        'contacts' => 'контакти',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CounterpartyRepository $repository,
        private readonly PlanEventRecorder $events,
        private readonly PlanAccess $access,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(TelegramUser $author, array $data): Counterparty
    {
        $this->guard($author, PlanCapability::CounterpartiesEdit);

        $counterparty = new Counterparty()->setCreatedBy($author);

        if (! array_key_exists('managerId', $data) && $author->getWorkRole() === WorkRole::Manager) {
            // Менеджер з продажу заводить свого клієнта — він і відповідальний.
            $data['managerId'] = $author->getId();
        }

        $this->apply($counterparty, $author, $data, creating: true);
        $this->em->persist($counterparty);

        $this->save(function () use ($counterparty, $author): void {
            $this->em->flush();
            $this->events->record(new SubjectCreated($this->ref($counterparty), $author->getId(), title: $counterparty->getName()));
        });

        return $counterparty;
    }

    /** @param array<string, mixed> $data */
    public function update(Counterparty $counterparty, TelegramUser $user, array $data): Counterparty
    {
        $this->guard($user, PlanCapability::CounterpartiesEdit);

        // Уся правка — під блокуванням рядка картки: два одночасні збереження йдуть по черзі,
        // а форма, відкрита до чужої правки (інша version), отримує 409, а не тихо затирає її
        // (разом із контактом, якого в старій формі ще не було).
        try {
            $this->save(function () use ($counterparty, $user, $data): void {
                $this->em->refresh($counterparty, LockMode::PESSIMISTIC_WRITE);

                if ($counterparty->isArchived()) {
                    throw new CounterpartyException('Контрагент в архіві — спершу поверніть його з архіву.');
                }

                if (isset($data['version']) && is_numeric($data['version']) && (int) $data['version'] !== $counterparty->getVersion()) {
                    throw new CounterpartyException('Картку щойно змінив хтось інший — оновіть сторінку, щоб не затерти його правки.', 'version', conflict: true);
                }

                $before = $this->snapshot($counterparty);
                $this->apply($counterparty, $user, $data, creating: false);
                $after = $this->snapshot($counterparty);
                $changed = array_keys(array_diff_assoc($after, $before));

                if ($changed === []) {
                    return;
                }

                $summary = implode('; ', array_map(
                    fn (string $field) => match ($field) {
                        'contacts', 'note' => self::LABELS[$field] . ' змінено',
                        default => sprintf('%s: %s → %s', self::LABELS[$field], $this->shown($field, $before[$field]), $this->shown($field, $after[$field])),
                    },
                    $changed,
                ));

                $counterparty->bumpVersion();
                $this->em->flush();
                $this->events->record(new SubjectUpdated($this->ref($counterparty), $user->getId(), fields: $changed, summary: mb_substr($summary, 0, 1000)));
            });
        } catch (CounterpartyException $e) {
            // Відкат — у БД; сутність теж повертаємо, щоб у цьому запиті ніхто не зберіг напівправку.
            if ($this->em->isOpen() && $this->em->contains($counterparty)) {
                $this->em->refresh($counterparty);
            }

            throw $e;
        }

        return $counterparty;
    }

    /** Значення поля для summary журналу: «—» замість порожнього, менеджер — без id. */
    private function shown(string $field, string $value): string
    {
        if ($field === 'manager') {
            $value = (string) preg_replace('/#\d+$/', '', $value);
        }

        return $value !== '' ? $value : '—';
    }

    public function archive(Counterparty $counterparty, TelegramUser $user): Counterparty
    {
        $this->guard($user, PlanCapability::CounterpartiesFinance, 'Прибрати в архів може бухгалтер, директор або адміністратор.');

        if ($counterparty->isArchived()) {
            return $counterparty;
        }

        $counterparty->archive($user);
        $this->events->transactional(function () use ($counterparty, $user): void {
            $this->em->flush();
            $this->events->record(StatusEvent::for($this->ref($counterparty), $user->getId(), 'active', 'archived', closes: true, asksApproval: false));
        });

        return $counterparty;
    }

    public function restore(Counterparty $counterparty, TelegramUser $user): Counterparty
    {
        $this->guard($user, PlanCapability::CounterpartiesFinance, 'Повернути з архіву може бухгалтер, директор або адміністратор.');

        if (! $counterparty->isArchived()) {
            return $counterparty;
        }

        $counterparty->restore();
        $this->events->transactional(function () use ($counterparty, $user): void {
            $this->em->flush();
            $this->events->record(StatusEvent::for($this->ref($counterparty), $user->getId(), 'archived', 'active', closes: false, asksApproval: false));
        });

        return $counterparty;
    }

    /** @param array<string, mixed> $data */
    private function apply(Counterparty $c, TelegramUser $user, array $data, bool $creating): void
    {
        $has = static fn (string $key): bool => array_key_exists($key, $data);

        if (! $this->access->can($user, PlanCapability::CounterpartiesFinance)) {
            foreach (self::FINANCE_FIELDS as $field) {
                if ($has($field) && $this->financeValue($field, $data[$field]) !== $this->financeValue($field, $this->current($c, $field))) {
                    throw new CounterpartyException('Умови оплати, відстрочку й кредитний ліміт встановлює бухгалтер або директор.', $field, forbidden: true);
                }
            }
        }

        if ($has('kind')) {
            $c->setKind(CounterpartyKind::tryFrom((string) $data['kind']) ?? throw new CounterpartyException('Оберіть, хто це: клієнт, постачальник чи обидва.', 'kind'));
        }

        if ($has('legalForm')) {
            $c->setLegalForm(LegalForm::tryFrom((string) $data['legalForm']) ?? throw new CounterpartyException('Оберіть форму: юрособа, ФОП чи фізособа.', 'legalForm'));
        }

        if ($has('name') || $creating) {
            $name = $this->text($data['name'] ?? null, 255);

            if ($name === null) {
                throw new CounterpartyException('Вкажіть назву контрагента.', 'name');
            }

            $c->setName($name);
        }

        if ($has('shortName')) {
            $c->setShortName($this->text($data['shortName'], 120));
        }

        if ($has('code') || $has('legalForm')) {
            $c->setCode($this->code($c, $has('code') ? $data['code'] : $c->getCode()));
        }

        if ($has('vatStatus')) {
            $vat = (string) $data['vatStatus'];
            $c->setVatStatus($vat === '' ? null : (VatStatus::tryFrom($vat) ?? throw new CounterpartyException('Невідомий статус ПДВ.', 'vatStatus')));
        }

        if ($has('vatNumber') || $has('vatStatus')) {
            $vatNumber = TaxCode::digits($has('vatNumber') ? $this->str($data['vatNumber']) : $c->getVatNumber());

            if ($vatNumber !== null && ! TaxCode::isVatNumber($vatNumber)) {
                throw new CounterpartyException('ІПН платника ПДВ має 12 цифр.', 'vatNumber');
            }

            // Неплатнику ПДВ номер платника не потрібен — не тримаємо застарілий.
            $c->setVatNumber($c->getVatStatus() === VatStatus::Payer ? $vatNumber : null);
        }

        foreach (['legalAddress' => 2000, 'actualAddress' => 2000, 'bankName' => 255, 'note' => 5000] as $field => $max) {
            if ($has($field)) {
                $value = $this->text($data[$field], $max);
                match ($field) {
                    'legalAddress' => $c->setLegalAddress($value),
                    'actualAddress' => $c->setActualAddress($value),
                    'bankName' => $c->setBankName($value),
                    'note' => $c->setNote($value),
                };
            }
        }

        if ($has('phone')) {
            $c->setPhone($this->phone($data['phone'], 'phone'));
        }

        if ($has('email')) {
            $c->setEmail($this->email($data['email'], 'email'));
        }

        if ($has('iban')) {
            $iban = TaxCode::iban($this->str($data['iban']));

            if ($iban !== null && ! TaxCode::isIban($iban)) {
                throw new CounterpartyException('IBAN має бути у форматі UA + 27 цифр і з правильною контрольною сумою.', 'iban');
            }

            $c->setIban($iban);
        }

        if ($has('paymentTerms')) {
            $terms = (string) $data['paymentTerms'];
            $c->setPaymentTerms($terms === '' ? null : (PaymentTerms::tryFrom($terms) ?? throw new CounterpartyException('Невідомі умови оплати.', 'paymentTerms')));
        }

        if ($has('deferralDays') || $has('paymentTerms')) {
            $days = $has('deferralDays') ? $data['deferralDays'] : $c->getDeferralDays();

            if ($c->getPaymentTerms() === PaymentTerms::Deferral) {
                $days = is_numeric($days) ? (int) $days : 0;

                if ($days < 1 || $days > 365) {
                    throw new CounterpartyException('Відстрочка — від 1 до 365 днів.', 'deferralDays');
                }

                $c->setDeferralDays($days);
            } else {
                $c->setDeferralDays(null);
            }
        }

        if ($has('creditLimit')) {
            $c->setCreditLimit($this->money($data['creditLimit']));
        }

        // Той самий менеджер (навіть якщо його вже в архіві) — не зміна: форма надсилає все.
        if ($has('managerId') && (int) $data['managerId'] !== (int) $c->getManager()?->getId()) {
            $c->setManager($this->manager($data['managerId']));
        }

        if ($has('externalCode')) {
            $external = $this->text($data['externalCode'], 64);
            $same = $external !== null ? $this->repository->findOneByExternalCode($external) : null;

            if ($same !== null && $same !== $c) {
                throw new CounterpartyException(sprintf('Код 1С «%s» уже має «%s».', $external, $same->displayName()), 'externalCode', $same->getId());
            }

            $c->setExternalCode($external);
        }

        if ($has('contacts')) {
            $this->contacts($c, is_array($data['contacts']) ? $data['contacts'] : []);
        }

        $this->duplicates($c);
    }

    /** Код за формою: ЄДРПОУ юрособи або РНОКПП ФОП / фізособи, з контрольною цифрою. */
    private function code(Counterparty $c, mixed $raw): ?string
    {
        $code = TaxCode::digits($this->str($raw));

        if ($code === null) {
            return null;
        }

        $form = $c->getLegalForm();
        $valid = $form === LegalForm::Company ? TaxCode::isEdrpou($code) : TaxCode::isRnokpp($code);

        if (! $valid) {
            $length = $form === LegalForm::Company ? 8 : 10;

            throw new CounterpartyException(
                strlen($code) !== $length
                    ? sprintf('%s має %d цифр (зараз %d).', $form->codeLabel(), $length, strlen($code))
                    : sprintf('%s %s не проходить перевірку контрольної цифри — перевірте, чи немає опечатки.', $form->codeLabel(), $code),
                'code',
            );
        }

        return $code;
    }

    /** Той самий код — та сама юрособа; та сама назва без коду — ймовірно, картку завели вдруге. */
    private function duplicates(Counterparty $c): void
    {
        if ($c->getCode() !== null) {
            $same = $this->repository->findOneByCode($c->getCode());

            if ($same !== null && $same !== $c) {
                throw new CounterpartyException(
                    sprintf('%s %s уже є в картці «%s»%s.', $c->getLegalForm()->codeLabel(), $c->getCode(), $same->displayName(), $same->isArchived() ? ' (в архіві)' : ''),
                    'code',
                    $same->getId(),
                );
            }

            return;
        }

        foreach ($this->repository->sameName($c->getNameNormalized(), $c->getId()) as $same) {
            if ($same->getCode() === null) {
                throw new CounterpartyException(
                    sprintf('«%s» уже є%s. Відкрийте наявну картку або вкажіть ЄДРПОУ / РНОКПП, якщо це інший контрагент.', $same->getName(), $same->isArchived() ? ' в архіві' : ''),
                    'name',
                    $same->getId(),
                );
            }
        }
    }

    /** @param array<mixed> $rows */
    private function contacts(Counterparty $c, array $rows): void
    {
        if (count($rows) > self::MAX_CONTACTS) {
            throw new CounterpartyException(sprintf('Не більше %d контактних осіб.', self::MAX_CONTACTS), 'contacts');
        }

        $existing = [];

        foreach ($c->getContacts() as $contact) {
            $existing[(int) $contact->getId()] = $contact;
        }

        $keep = [];
        $primarySeen = false;
        $position = 0;

        foreach (array_values($rows) as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $this->text($row['name'] ?? null, 255);
            $phone = $this->phone($row['phone'] ?? null, 'contacts');
            $email = $this->email($row['email'] ?? null, 'contacts');
            $role = $this->text($row['role'] ?? null, 120);

            if ($name === null && $phone === null && $email === null && $role === null) {
                continue; // порожній рядок форми
            }

            if ($name === null) {
                throw new CounterpartyException(sprintf('Контакт %d: вкажіть ім\'я.', $i + 1), 'contacts');
            }

            $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
            $contact = $existing[$id] ?? new CounterpartyContact($c, $name);
            $primary = ! empty($row['primary']) && ! $primarySeen;
            $primarySeen = $primarySeen || $primary;

            $contact->setName($name)->setRole($role)->setPhone($phone)->setEmail($email)->setPrimary($primary)->setPosition($position++);
            $c->addContact($contact);
            $keep[] = $contact;
        }

        foreach ($c->getContacts()->toArray() as $contact) {
            if (! in_array($contact, $keep, true)) {
                $c->removeContact($contact);
            }
        }

        // Один контакт — він і основний.
        if (! $primarySeen && count($keep) === 1) {
            $keep[0]->setPrimary(true);
        }
    }

    private function manager(mixed $id): ?TelegramUser
    {
        if ($id === null || $id === '' || (int) $id === 0) {
            return null;
        }

        $manager = $this->em->find(TelegramUser::class, (int) $id);

        if ($manager === null || $manager->isArchived() || ! $manager->isApproved()) {
            throw new CounterpartyException('Такого працівника немає або він в архіві.', 'managerId');
        }

        return $manager;
    }

    private function money(mixed $raw): ?string
    {
        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $this->str($raw) ?? '');

        if ($value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0 || (float) $value >= 1e12) {
            throw new CounterpartyException('Кредитний ліміт — сума в гривнях, наприклад 150000.', 'creditLimit');
        }

        return number_format(round((float) $value, 2), 2, '.', '');
    }

    private function phone(mixed $raw, string $field): ?string
    {
        $value = $this->str($raw);

        if ($value === null) {
            return null;
        }

        $e164 = PhoneFormat::e164($value);

        if ($e164 === null || strlen($e164) > 15) {
            throw new CounterpartyException(sprintf('Телефон «%s» не схожий на номер.', $value), $field);
        }

        return '+' . $e164;
    }

    private function email(mixed $raw, string $field): ?string
    {
        $value = $this->str($raw);

        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) > 180 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new CounterpartyException(sprintf('Email «%s» не схожий на адресу.', $value), $field);
        }

        return mb_strtolower($value);
    }

    private function str(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = $this->str($value);

        return $value !== null ? mb_substr($value, 0, $max) : null;
    }

    /** Значення фінансового поля, зведене до порівнюваного вигляду. */
    private function financeValue(string $field, mixed $value): string
    {
        return match ($field) {
            'paymentTerms' => $value instanceof PaymentTerms ? $value->value : (string) $value,
            'deferralDays' => $value === null || $value === '' ? '' : (string) (int) $value,
            'creditLimit' => (function () use ($value): string {
                try {
                    return (string) $this->money($value);
                } catch (CounterpartyException) {
                    return '?';
                }
            })(),
            default => (string) $value,
        };
    }

    private function current(Counterparty $c, string $field): mixed
    {
        return match ($field) {
            'paymentTerms' => $c->getPaymentTerms(),
            'deferralDays' => $c->getDeferralDays(),
            'creditLimit' => $c->getCreditLimit(),
            default => null,
        };
    }

    /** @return array<string, string> поле → значення для журналу змін */
    private function snapshot(Counterparty $c): array
    {
        $contacts = array_map(
            static fn (CounterpartyContact $x) => implode('|', [$x->getPosition(), $x->getName(), $x->getRole(), $x->getPhone(), $x->getEmail(), $x->isPrimary() ? '1' : '0']),
            $c->getContacts()->toArray(),
        );

        return [
            'kind' => $c->getKind()->label(),
            'legalForm' => $c->getLegalForm()->label(),
            'name' => $c->getName(),
            'shortName' => (string) $c->getShortName(),
            'code' => (string) $c->getCode(),
            'vatStatus' => (string) $c->getVatStatus()?->label(),
            'vatNumber' => (string) $c->getVatNumber(),
            'legalAddress' => (string) $c->getLegalAddress(),
            'actualAddress' => (string) $c->getActualAddress(),
            'phone' => (string) $c->getPhone(),
            'email' => (string) $c->getEmail(),
            'iban' => (string) $c->getIban(),
            'bankName' => (string) $c->getBankName(),
            'paymentTerms' => (string) $c->getPaymentTerms()?->label(),
            'deferralDays' => (string) $c->getDeferralDays(),
            'creditLimit' => (string) $c->getCreditLimit(),
            // За id: два працівники з однаковим імʼям — теж зміна (у summary — лише імʼя, див. shown()).
            'manager' => $c->getManager() === null ? '' : $c->getManager()->shortName() . '#' . $c->getManager()->getId(),
            'note' => md5((string) $c->getNote()),
            'externalCode' => (string) $c->getExternalCode(),
            'contacts' => md5(implode("\n", $contacts)),
        ];
    }

    /**
     * Запис + подія однією транзакцією. Двоє одночасно завели той самий ЄДРПОУ чи код 1С —
     * перевірка дублів обох пропустила, унікальний індекс зупинив другого: людська помилка, не 500.
     */
    private function save(callable $work): void
    {
        try {
            $this->events->transactional($work);
        } catch (UniqueConstraintViolationException $e) {
            $external = str_contains($e->getMessage(), 'uniq_counterparty_external_code');

            throw new CounterpartyException(
                $external
                    ? 'Контрагент з таким кодом 1С щойно зʼявився — оновіть сторінку й знайдіть його в списку.'
                    : 'Контрагент з таким ЄДРПОУ / РНОКПП щойно зʼявився — оновіть сторінку й знайдіть його в списку.',
                $external ? 'externalCode' : 'code',
                conflict: true,
            );
        }
    }

    private function guard(TelegramUser $user, PlanCapability $capability, string $message = 'Контрагентів ведуть продажі, бухгалтерія, постачання й керівництво.'): void
    {
        if (! $this->access->can($user, $capability)) {
            throw new CounterpartyException($message, forbidden: true);
        }
    }

    private function ref(Counterparty $c): SubjectRef
    {
        return SubjectRef::of(SubjectType::Counterparty, (int) $c->getId());
    }
}
