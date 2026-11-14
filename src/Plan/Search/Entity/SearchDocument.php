<?php

namespace App\Plan\Search\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Рядок пошукового індексу «Плану» — одна денормалізована таблиця на все:
 * задачі, коментарі, файли, заявки, склад, люди.
 *
 * Сутність лише описує схему (щоб `make:migration` не хотів таблицю видалити):
 * пише її виключно SearchProjector сирим SQL, читає PlanSearch. Індекси
 * pg_trgm / FTS / jsonb_path_ops — у міграції Version20261115120000 (атрибутами
 * їх не виразити, так само як trgm-індекси задач).
 *
 * Масиви id — jsonb, не int[]: DBAL не знає типу _int4 і впав би на інтроспекції
 * схеми (doctrine:migrations:diff) для всієї бази.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'search_document')]
#[ORM\UniqueConstraint(name: 'uniq_search_document_ref', columns: ['doc_type', 'ref_id'])]
#[ORM\Index(name: 'idx_search_document_subject', columns: ['subject_type', 'subject_id'])]
#[ORM\Index(name: 'idx_search_document_updated', columns: ['updated_at', 'id'])]
#[ORM\Index(name: 'idx_search_document_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_search_document_author', columns: ['author_id'])]
#[ORM\Index(name: 'idx_search_document_manager', columns: ['manager_id'])]
#[ORM\Index(name: 'idx_search_document_status', columns: ['status'])]
// Насправді GIN (pg_trgm / jsonb_path_ops) — так їх створює міграція. Тут вони описані
// лише для того, щоб doctrine:migrations:diff не пропонував їх видалити (DBAL не
// бачить методу індексу). Вирази-індекси (FTS, lower(client)) DBAL пропускає сам.
#[ORM\Index(name: 'idx_search_document_trgm', columns: ['search_text'])]
#[ORM\Index(name: 'idx_search_document_executors', columns: ['executor_ids'])]
#[ORM\Index(name: 'idx_search_document_departments', columns: ['department_ids'])]
#[ORM\Index(name: 'idx_search_document_visible', columns: ['visible_to'])]
class SearchDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'search_document_id_seq')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    /** task|comment|file|supply|supply_comment|supply_file|wh_item|wh_movement|wh_client|wh_site|wh_document|person|lead */
    #[ORM\Column(name: 'doc_type', length: 16)]
    private string $docType = '';

    #[ORM\Column(name: 'subject_type', length: 16)]
    private string $subjectType = '';

    #[ORM\Column(name: 'subject_id')]
    private int $subjectId = 0;

    /** id рядка-джерела: задачі, повідомлення, файлу, заявки… (унікальний у межах doc_type) */
    #[ORM\Column(name: 'ref_id')]
    private int $refId = 0;

    /** Заголовок збігу (назва задачі, ім'я файлу, «№ 042-2026 · Цемент»). */
    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $title = '';

    /** Текст для сниппета (опис, коментар, контекст файлу). */
    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $body = '';

    /** Контрагент / клієнт / об'єкт — для фільтра «контрагент». */
    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $client = '';

    /** Те, по чому шукаємо: нижній регістр, апострофи зведені (GIN pg_trgm). */
    #[ORM\Column(name: 'search_text', type: Types::TEXT, options: ['default' => ''])]
    private string $searchText = '';

    #[ORM\Column(name: 'author_id', nullable: true)]
    private ?int $authorId = null;

    #[ORM\Column(name: 'manager_id', nullable: true)]
    private ?int $managerId = null;

    /** @var list<int> */
    #[ORM\Column(name: 'executor_ids', type: Types::JSONB, options: ['default' => '[]'])]
    private array $executorIds = [];

    /** @var list<int> */
    #[ORM\Column(name: 'department_ids', type: Types::JSONB, options: ['default' => '[]'])]
    private array $departmentIds = [];

    /** `<тип>:<статус>` — task:in_work, supply:approval, wh_item:repair… */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(name: 'visible_all', options: ['default' => false])]
    private bool $visibleAll = false;

    /** @var list<int> */
    #[ORM\Column(name: 'visible_to', type: Types::JSONB, options: ['default' => '[]'])]
    private array $visibleTo = [];

    /** Можливість PlanCapability, без якої документ не видно (склад — warehouse.view). */
    #[ORM\Column(length: 24, nullable: true)]
    private ?string $capability = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    private function __construct()
    {
        $this->createdAt = $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id !== null ? (int) $this->id : null;
    }
}
