<?php

namespace App\Warehouse\Entity;

use App\Warehouse\Enum\CategoryScope;
use App\Warehouse\Repository\WhCategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Категорія з довідника: вид майна, клієнта чи об'єкта.
 *
 * Довідник, а не перелік у коді: сьогодні це опалубка й трактор, завтра —
 * риштування, генератори чи бетононасос; клієнти — забудовники, підрядники,
 * приватні особи; об'єкти — ЖК, приватні будинки, дороги. Нову категорію
 * менеджер додає в адмінці сам, без розробника.
 *
 * Для майна категорія ще й задає префікс інвентарного номера (ОП-0001) і
 * підказує, які характеристики заповнювати (розміри для щита, моточаси для
 * трактора) — самі значення живуть у картці позиції.
 */
#[ORM\Entity(repositoryClass: WhCategoryRepository::class)]
#[ORM\Table(name: 'wh_category')]
#[ORM\UniqueConstraint(name: 'wh_category_scope_name_idx', columns: ['scope', 'name'])]
class WhCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 16, enumType: CategoryScope::class, nullable: false)]
    private CategoryScope $scope = CategoryScope::Item;

    #[ORM\Column(type: 'string', length: 120, nullable: false)]
    private string $name = '';

    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    private ?string $emoji = null;

    /** Лише для майна: префікс інвентарного номера, 2–4 літери. */
    #[ORM\Column(type: 'string', length: 8, nullable: true)]
    private ?string $prefix = null;

    /**
     * Лише для майна: які характеристики підказувати у формі — «Розміри», «Вага».
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, nullable: false, options: ['default' => '[]'])]
    private array $attributes = [];

    #[ORM\Column(type: 'integer', nullable: false, options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: 'boolean', nullable: false, options: ['default' => true])]
    private bool $active = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScope(): CategoryScope
    {
        return $this->scope;
    }

    public function setScope(CategoryScope $scope): self
    {
        $this->scope = $scope;

        return $this;
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

    public function getEmoji(): ?string
    {
        return $this->emoji;
    }

    public function setEmoji(?string $emoji): self
    {
        $this->emoji = $emoji;

        return $this;
    }

    public function getPrefix(): ?string
    {
        return $this->prefix;
    }

    public function setPrefix(?string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    /** @return list<string> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** @param list<string> $attributes */
    public function setAttributes(array $attributes): self
    {
        $this->attributes = array_values($attributes);

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

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

    public function getLabel(): string
    {
        return trim(($this->emoji ?? '') . ' ' . $this->name);
    }
}
