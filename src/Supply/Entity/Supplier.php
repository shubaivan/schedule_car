<?php

namespace App\Supply\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Entity\TelegramUser;
use App\Supply\Repository\SupplierRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Постачальник: у кого саме купили — «ФОП Петренко О.П.», «ТОВ Будматеріали».
 *
 * Довідник, а не поле-рядок у заявці: інакше «ФОП Петренко», «фоп петренко» і
 * «Петренко ФОП» стають трьома різними постачальниками, і звіт «скільки взяли
 * у Петренка за квартал» порахувати неможливо.
 */
#[ORM\Entity(repositoryClass: SupplierRepository::class)]
#[ORM\Table(name: 'supply_supplier')]
// Один код — один контрагент. У Postgres NULL-и в унікальному індексі
// не конфліктують, тож постачальники без коду співіснують вільно.
#[ORM\UniqueConstraint(name: 'supply_supplier_edrpou_idx', columns: ['edrpou'])]
#[ORM\HasLifecycleCallbacks]
class Supplier
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Як пишуть у документах — цю назву бачить людина. */
    #[NotBlank]
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $name;

    /**
     * Та сама назва без регістру, зайвих пробілів і розділових знаків.
     * Унікальна: саме вона ловить повторний запис того самого постачальника.
     */
    #[ORM\Column(name: 'name_normalized', type: 'string', length: 255, unique: true, nullable: false)]
    private string $nameNormalized;

    /** ЄДРПОУ (8 цифр) або ІПН підприємця (10 цифр) — за наявності. */
    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    private ?string $edrpou = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(name: 'contact_person', type: 'string', length: 255, nullable: true)]
    private ?string $contactPerson = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** Прибираємо зі списку вибору, але не видаляємо: на нього посилаються закупівлі. */
    #[ORM\Column(type: 'boolean', nullable: false, options: ['default' => true])]
    private bool $active = true;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $createdBy = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getEdrpou(): ?string
    {
        return $this->edrpou;
    }

    public function setEdrpou(?string $edrpou): self
    {
        $edrpou = $edrpou !== null ? trim($edrpou) : null;
        $this->edrpou = $edrpou === '' ? null : $edrpou;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $phone = $phone !== null ? trim($phone) : null;
        $this->phone = $phone === '' ? null : $phone;

        return $this;
    }

    public function getContactPerson(): ?string
    {
        return $this->contactPerson;
    }

    public function setContactPerson(?string $contactPerson): self
    {
        $contactPerson = $contactPerson !== null ? trim($contactPerson) : null;
        $this->contactPerson = $contactPerson === '' ? null : $contactPerson;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $note = $note !== null ? trim($note) : null;
        $this->note = $note === '' ? null : $note;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

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

    /**
     * Ключ для пошуку повторів: лише літери й цифри в нижньому регістрі.
     *
     * Пробіли й розділові знаки викидаємо повністю, а не зводимо до одного
     * пробілу: інакше «ФОП Петренко О.П.» і «ФОП Петренко ОП» — це два різні
     * постачальники, і звіт по Петренку рахує половину закупівель.
     *
     * Правову форму навмисно НЕ прибираємо: «ТОВ Альфа» і «ФОП Альфа» —
     * різні юридичні особи, склеювати їх не можна.
     */
    public static function normalize(string $name): string
    {
        return (string)preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim($name)));
    }
}
