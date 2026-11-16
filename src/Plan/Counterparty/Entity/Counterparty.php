<?php

namespace App\Plan\Counterparty\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Entity\TelegramUser;
use App\Plan\Counterparty\Enum\CounterpartyKind;
use App\Plan\Counterparty\Enum\LegalForm;
use App\Plan\Counterparty\Enum\PaymentTerms;
use App\Plan\Counterparty\Enum\VatStatus;
use App\Plan\Counterparty\Repository\CounterpartyRepository;
use DateTime;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SortDirection;

/**
 * Контрагент заводу — одна картка на клієнта й постачальника (ТЗ §8).
 *
 * Готовий до імпорту з 1С: ЄДРПОУ/РНОКПП унікальний (NULL-и в унікальному
 * індексі Postgres не конфліктують — фізособа без коду можлива), код 1С —
 * окреме унікальне поле, назва зберігається ще й нормалізованою, щоб імпорт
 * і пошук знаходили «ТОВ "Альфа"» і «тов альфа» як одне.
 *
 * Не видаляється ніколи: на нього посилатимуться задачі, документи, договори.
 * Прибраний — архів (archivedAt).
 */
#[ORM\Entity(repositoryClass: CounterpartyRepository::class)]
#[ORM\Table(name: 'counterparty')]
#[ORM\UniqueConstraint(name: 'uniq_counterparty_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_counterparty_external_code', columns: ['external_code'])]
#[ORM\Index(name: 'idx_counterparty_name_normalized', columns: ['name_normalized'])]
#[ORM\Index(name: 'idx_counterparty_manager', columns: ['manager_id'])]
#[ORM\HasLifecycleCallbacks]
class Counterparty
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 8, enumType: CounterpartyKind::class, options: ['default' => 'client'])]
    private CounterpartyKind $kind = CounterpartyKind::Client;

    #[ORM\Column(name: 'legal_form', type: 'string', length: 8, enumType: LegalForm::class, options: ['default' => 'company'])]
    private LegalForm $legalForm = LegalForm::Company;

    /** Повна назва, як у документах: «ТОВ "Житлобуд-Черкаси"». */
    #[ORM\Column(type: 'string', length: 255)]
    private string $name = '';

    /** Як його називають у цеху: «Житлобуд». */
    #[ORM\Column(name: 'short_name', type: 'string', length: 120, nullable: true)]
    private ?string $shortName = null;

    /** Назва без регістру, лапок і пробілів — пошук і пошук дублів при імпорті. */
    #[ORM\Column(name: 'name_normalized', type: 'string', length: 255)]
    private string $nameNormalized = '';

    /** ЄДРПОУ (8 цифр) юрособи або РНОКПП (10 цифр) ФОП / фізособи. */
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $code = null;

    /** null — ще не з'ясували (часто при імпорті). */
    #[ORM\Column(name: 'vat_status', type: 'string', length: 12, nullable: true, enumType: VatStatus::class)]
    private ?VatStatus $vatStatus = null;

    /** ІПН платника ПДВ, 12 цифр. */
    #[ORM\Column(name: 'vat_number', type: 'string', length: 12, nullable: true)]
    private ?string $vatNumber = null;

    #[ORM\Column(name: 'legal_address', type: Types::TEXT, nullable: true)]
    private ?string $legalAddress = null;

    /** Куди возити / звідки забирати, якщо не збігається з юридичною. */
    #[ORM\Column(name: 'actual_address', type: Types::TEXT, nullable: true)]
    private ?string $actualAddress = null;

    /** Загальний телефон (приймальня, диспетчер), +380… */
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 34, nullable: true)]
    private ?string $iban = null;

    #[ORM\Column(name: 'bank_name', type: 'string', length: 255, nullable: true)]
    private ?string $bankName = null;

    #[ORM\Column(name: 'payment_terms', type: 'string', length: 16, nullable: true, enumType: PaymentTerms::class)]
    private ?PaymentTerms $paymentTerms = null;

    /** Днів відстрочки (лише для PaymentTerms::Deferral). */
    #[ORM\Column(name: 'deferral_days', type: Types::SMALLINT, nullable: true)]
    private ?int $deferralDays = null;

    /** Кредитний ліміт, грн; null — не встановлено (без кредиту). */
    #[ORM\Column(name: 'credit_limit', type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    private ?string $creditLimit = null;

    /** Відповідальний менеджер. */
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'manager_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $manager = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** Код контрагента в 1С — ключ повторного імпорту й звірки. */
    #[ORM\Column(name: 'external_code', type: 'string', length: 64, nullable: true)]
    private ?string $externalCode = null;

    #[ORM\Column(name: 'archived_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTime $archivedAt = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'archived_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $archivedBy = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $createdBy = null;

    /** Номер правки: форма надсилає той, з яким відкрилась, — стара форма не затре чужу правку. */
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    /** @var Collection<int, CounterpartyContact> */
    #[ORM\OneToMany(targetEntity: CounterpartyContact::class, mappedBy: 'counterparty', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => SortDirection::Ascending, 'id' => SortDirection::Ascending])]
    private Collection $contacts;

    public function __construct()
    {
        $this->contacts = new ArrayCollection();
    }

    /**
     * Та сама назва без регістру, лапок, пробілів і розділових знаків — як
     * Supplier::normalize: правову форму НЕ прибираємо («ТОВ Альфа» ≠ «ФОП Альфа»).
     */
    public static function normalize(string $name): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim($name)));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): CounterpartyKind
    {
        return $this->kind;
    }

    public function setKind(CounterpartyKind $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function getLegalForm(): LegalForm
    {
        return $this->legalForm;
    }

    public function setLegalForm(LegalForm $legalForm): self
    {
        $this->legalForm = $legalForm;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->nameNormalized = self::normalize($name);

        return $this;
    }

    public function getNameNormalized(): string
    {
        return $this->nameNormalized;
    }

    public function getShortName(): ?string
    {
        return $this->shortName;
    }

    public function setShortName(?string $shortName): self
    {
        $this->shortName = $shortName;

        return $this;
    }

    /** Те, що бачать у списках: коротка назва, а якщо її немає — повна. */
    public function displayName(): string
    {
        return $this->shortName ?? $this->name;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getVatStatus(): ?VatStatus
    {
        return $this->vatStatus;
    }

    public function setVatStatus(?VatStatus $vatStatus): self
    {
        $this->vatStatus = $vatStatus;

        return $this;
    }

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function setVatNumber(?string $vatNumber): self
    {
        $this->vatNumber = $vatNumber;

        return $this;
    }

    public function getLegalAddress(): ?string
    {
        return $this->legalAddress;
    }

    public function setLegalAddress(?string $legalAddress): self
    {
        $this->legalAddress = $legalAddress;

        return $this;
    }

    public function getActualAddress(): ?string
    {
        return $this->actualAddress;
    }

    public function setActualAddress(?string $actualAddress): self
    {
        $this->actualAddress = $actualAddress;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): self
    {
        $this->iban = $iban;

        return $this;
    }

    public function getBankName(): ?string
    {
        return $this->bankName;
    }

    public function setBankName(?string $bankName): self
    {
        $this->bankName = $bankName;

        return $this;
    }

    public function getPaymentTerms(): ?PaymentTerms
    {
        return $this->paymentTerms;
    }

    public function setPaymentTerms(?PaymentTerms $paymentTerms): self
    {
        $this->paymentTerms = $paymentTerms;

        return $this;
    }

    public function getDeferralDays(): ?int
    {
        return $this->deferralDays;
    }

    public function setDeferralDays(?int $deferralDays): self
    {
        $this->deferralDays = $deferralDays;

        return $this;
    }

    public function getCreditLimit(): ?string
    {
        return $this->creditLimit;
    }

    public function setCreditLimit(?string $creditLimit): self
    {
        $this->creditLimit = $creditLimit;

        return $this;
    }

    public function getManager(): ?TelegramUser
    {
        return $this->manager;
    }

    public function setManager(?TelegramUser $manager): self
    {
        $this->manager = $manager;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    public function getExternalCode(): ?string
    {
        return $this->externalCode;
    }

    public function setExternalCode(?string $externalCode): self
    {
        $this->externalCode = $externalCode;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    public function getArchivedAt(): ?DateTime
    {
        return $this->archivedAt;
    }

    public function getArchivedBy(): ?TelegramUser
    {
        return $this->archivedBy;
    }

    public function archive(?TelegramUser $by): self
    {
        $this->archivedAt = new DateTime('now', new DateTimeZone('Europe/Kyiv'));
        $this->archivedBy = $by;

        return $this;
    }

    public function restore(): self
    {
        $this->archivedAt = null;
        $this->archivedBy = null;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function bumpVersion(): self
    {
        ++$this->version;

        return $this;
    }

    public function getCreatedBy(): ?TelegramUser
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?TelegramUser $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /** @return Collection<int, CounterpartyContact> */
    public function getContacts(): Collection
    {
        return $this->contacts;
    }

    public function addContact(CounterpartyContact $contact): self
    {
        if (! $this->contacts->contains($contact)) {
            $this->contacts->add($contact);
        }

        return $this;
    }

    public function removeContact(CounterpartyContact $contact): self
    {
        $this->contacts->removeElement($contact);

        return $this;
    }
}
