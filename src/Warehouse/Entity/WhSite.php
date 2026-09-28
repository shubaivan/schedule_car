<?php

namespace App\Warehouse\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Warehouse\Enum\SiteKind;
use App\Warehouse\Repository\WhSiteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Місце, де може бути майно: склад або об'єкт.
 *
 * Об'єкт без клієнта — власний (наш ЖК, наш цех). Об'єкт із клієнтом —
 * його будівництво, куди ми відвантажили чи здали в оренду.
 */
#[ORM\Entity(repositoryClass: WhSiteRepository::class)]
#[ORM\Table(name: 'wh_site')]
#[ORM\Index(name: 'wh_site_client_idx', columns: ['client_id'])]
#[ORM\HasLifecycleCallbacks]
class WhSite
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 16, enumType: SiteKind::class, nullable: false)]
    private SiteKind $kind = SiteKind::Site;

    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $name = '';

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\ManyToOne(targetEntity: WhClient::class, inversedBy: 'sites')]
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?WhClient $client = null;

    /** ЖК, приватний будинок, дорога — з довідника. */
    #[ORM\ManyToOne(targetEntity: WhCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?WhCategory $category = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: 'boolean', nullable: false, options: ['default' => true])]
    private bool $active = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): SiteKind
    {
        return $this->kind;
    }

    public function setKind(SiteKind $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function isWarehouse(): bool
    {
        return $this->kind === SiteKind::Warehouse;
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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;

        return $this;
    }

    public function getClient(): ?WhClient
    {
        return $this->client;
    }

    public function setClient(?WhClient $client): self
    {
        $this->client = $client;

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

    /** «ЖК Сонячний (ТОВ Будінвест)» — як місце називається у списках і в боті. */
    public function getLabel(): string
    {
        if ($this->isWarehouse()) {
            return '🏠 ' . $this->name;
        }

        return $this->client !== null
            ? sprintf('%s (%s)', $this->name, $this->client->getName())
            : $this->name . ' (власний)';
    }
}
