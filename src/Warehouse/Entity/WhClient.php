<?php

namespace App\Warehouse\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Warehouse\Repository\WhClientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Клієнт, якому даємо майно в оренду чи возимо на його об'єкти.
 *
 * Об'єктів у клієнта може бути багато, тому адреса живе не тут, а в WhSite.
 * Договори й інші документи клієнта кріпляться сюди ж (WhDocument).
 */
#[ORM\Entity(repositoryClass: WhClientRepository::class)]
#[ORM\Table(name: 'wh_client')]
#[ORM\HasLifecycleCallbacks]
class WhClient
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $name = '';

    /** ЄДРПОУ (8 цифр) або ІПН (10 цифр). */
    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    private ?string $edrpou = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(name: 'contact_person', type: 'string', length: 255, nullable: true)]
    private ?string $contactPerson = null;

    /** Забудовник, підрядник, приватна особа — з довідника. */
    #[ORM\ManyToOne(targetEntity: WhCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?WhCategory $category = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** Прибраний зникає зі списків вибору, але лишається в історії рухів. */
    #[ORM\Column(type: 'boolean', nullable: false, options: ['default' => true])]
    private bool $active = true;

    /** @var Collection<int, WhSite> */
    #[ORM\OneToMany(targetEntity: WhSite::class, mappedBy: 'client')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $sites;

    public function __construct()
    {
        $this->sites = new ArrayCollection();
    }

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

        return $this;
    }

    public function getEdrpou(): ?string
    {
        return $this->edrpou;
    }

    public function setEdrpou(?string $edrpou): self
    {
        $this->edrpou = $edrpou;

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

    public function getContactPerson(): ?string
    {
        return $this->contactPerson;
    }

    public function setContactPerson(?string $contactPerson): self
    {
        $this->contactPerson = $contactPerson;

        return $this;
    }

    public function getCategory(): ?WhCategory
    {
        return $this->category;
    }

    public function setCategory(?WhCategory $category): self
    {
        $this->category = $category;

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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    /** @return Collection<int, WhSite> */
    public function getSites(): Collection
    {
        return $this->sites;
    }
}
