<?php

namespace App\Warehouse\Api;

use App\Entity\TelegramUser;
use App\Tasks\Service\TaskPresenter;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Entity\WhCategory;
use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhDocument;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhMovementLine;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\MovementType;
use DateTimeInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Склад у JSON для «Плану»: ті самі сутності й ті самі підписи, що й на Twig-сторінках
 * /sklad (getLabel, getTitle, label() енумів), — щоб у двох інтерфейсах одна річ
 * називалась однаково. Людей малює TaskPresenter — як у задачах і заявках.
 */
final class WarehousePresenter
{
    public function __construct(
        private readonly TaskPresenter $people,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function person(?TelegramUser $user): ?array
    {
        return $this->people->person($user);
    }

    /** @return array<string, mixed> */
    public function category(WhCategory $category): array
    {
        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'emoji' => $category->getEmoji(),
            'prefix' => $category->getPrefix(),
            'attributes' => $category->getAttributes(),
            'active' => $category->isActive(),
        ];
    }

    /** @return array<string, mixed> */
    public function site(WhSite $site): array
    {
        return [
            'id' => $site->getId(),
            'kind' => $site->getKind()->value,
            'name' => $site->getName(),
            'label' => $site->getLabel(),
            'address' => $site->getAddress(),
            'client' => $site->getClient() !== null ? ['id' => $site->getClient()->getId(), 'name' => $site->getClient()->getName()] : null,
            'active' => $site->isActive(),
        ];
    }

    /** @return array<string, mixed> */
    public function client(WhClient $client): array
    {
        return [
            'id' => $client->getId(),
            'name' => $client->getName(),
            'edrpou' => $client->getEdrpou(),
            'phone' => $client->getPhone(),
            'active' => $client->isActive(),
        ];
    }

    /**
     * Рядок списку позицій.
     *
     * @param array<int, int> $balances site_id => кількість (лише для «кількістю»)
     * @param array<int, WhSite> $sites
     *
     * @return array<string, mixed>
     */
    public function itemRow(WhItem $item, array $balances, array $sites, ?WhDocument $cover): array
    {
        $total = $item->isUnit() ? ($item->getCurrentSite() !== null ? 1 : 0) : array_sum($balances);
        $places = [];

        if ($item->isUnit()) {
            if ($item->getCurrentSite() !== null) {
                $places[] = ['site' => $item->getCurrentSite()->getLabel(), 'quantity' => 1];
            }
        } else {
            foreach ($balances as $siteId => $quantity) {
                if (isset($sites[$siteId])) {
                    $places[] = ['site' => $sites[$siteId]->getLabel(), 'quantity' => $quantity];
                }
            }
        }

        return [
            'id' => $item->getId(),
            'inventoryNumber' => $item->getInventoryNumber(),
            'name' => $item->getName(),
            'label' => $item->getLabel(),
            'category' => ['id' => $item->getCategory()->getId(), 'name' => $item->getCategory()->getName(), 'emoji' => $item->getCategory()->getEmoji()],
            'tracking' => $item->getTracking()->value,
            'trackingLabel' => $item->getTracking()->label(),
            'unit' => $item->getUnit(),
            'state' => $item->getState()->value,
            'stateLabel' => $item->getState()->label(),
            'total' => $total,
            'places' => $places,
            'currentSite' => $item->getCurrentSite() !== null ? $this->site($item->getCurrentSite()) : null,
            'currentSince' => $item->getCurrentSince()?->format('Y-m-d'),
            'cover' => $cover !== null ? $this->documentUrl($cover) : null,
            'updatedAt' => $item->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Картка позиції: усе з рядка списку плюс характеристики, гроші, залишки по місцях.
     *
     * @param list<array{site: WhSite, quantity: int}> $places
     * @param WhDocument[] $documents
     * @param WhMovement[] $history
     *
     * @return array<string, mixed>
     */
    public function item(WhItem $item, int $total, array $places, array $documents, array $history, ?string $shareUrl): array
    {
        $rows = [];

        foreach ($places as $place) {
            $rows[] = [
                'site' => $this->site($place['site']),
                'quantity' => $place['quantity'],
                'since' => $item->isUnit() ? $item->getCurrentSince()?->format('Y-m-d') : null,
            ];
        }

        return [
            'id' => $item->getId(),
            'inventoryNumber' => $item->getInventoryNumber(),
            'name' => $item->getName(),
            'label' => $item->getLabel(),
            'category' => $this->category($item->getCategory()),
            'tracking' => $item->getTracking()->value,
            'trackingLabel' => $item->getTracking()->label(),
            'unit' => $item->getUnit(),
            'state' => $item->getState()->value,
            'stateLabel' => $item->getState()->label(),
            'description' => $item->getDescription(),
            'attributes' => $item->getAttributes(),
            'serialNumber' => $item->getSerialNumber(),
            'supplier' => $item->getSupplier() !== null ? ['id' => $item->getSupplier()->getId(), 'name' => $item->getSupplier()->getName()] : null,
            'purchasePrice' => $item->getPurchasePrice(),
            'purchasedAt' => $item->getPurchasedAt()?->format('Y-m-d'),
            'rentalRate' => $item->getRentalRate(),
            'total' => $total,
            'places' => $rows,
            'documents' => array_map($this->document(...), $documents),
            'history' => array_map(fn (WhMovement $m) => $this->movementRow($m, $item), $history),
            'createdBy' => $this->person($item->getCreatedBy()),
            'createdAt' => $item->getCreatedAt()->format(DateTimeInterface::ATOM),
            'qr' => $this->urls->generate('api_wh_item_qr', ['id' => $item->getId()]),
            'labels' => $this->urls->generate('wh_labels', ['ids' => [$item->getId()]]),
            'twig' => $this->urls->generate('wh_item', ['id' => $item->getId()]),
            'shareUrl' => $shareUrl,
        ];
    }

    /**
     * Рядок списку рухів. З $for — кількість саме цієї позиції в русі (історія картки).
     *
     * @return array<string, mixed>
     */
    public function movementRow(WhMovement $movement, ?WhItem $for = null): array
    {
        $lines = $movement->getLines()->toArray();
        $quantity = null;

        if ($for !== null) {
            foreach ($lines as $line) {
                if ($line->getItem()->getId() === $for->getId()) {
                    $quantity = $line->getQuantity();
                }
            }
        }

        return [
            'id' => $movement->getId(),
            'type' => $movement->getType()->value,
            'typeLabel' => $movement->getType()->label(),
            'emoji' => $movement->getType()->emoji(),
            'title' => $movement->getTitle(),
            'occurredAt' => $movement->getOccurredAt()->format('Y-m-d'),
            'documentNumber' => $movement->getDocumentNumber(),
            'from' => $movement->getFromSite() !== null ? $this->site($movement->getFromSite()) : null,
            'to' => $movement->getToSite() !== null ? $this->site($movement->getToSite()) : null,
            'route' => self::route($movement),
            'client' => $movement->getClient()?->getName(),
            'supplier' => $movement->getSupplier()?->getName(),
            'lines' => count($lines),
            'units' => array_sum(array_map(static fn (WhMovementLine $l) => $l->getQuantity(), $lines)),
            'quantity' => $quantity,
            'unit' => $for?->getUnit(),
            'createdBy' => $this->person($movement->getCreatedBy()),
        ];
    }

    /**
     * Рух повністю: рядки з позиціями, документи, журнал.
     *
     * @param WhDocument[] $documents
     * @param WhActivity[] $journal
     *
     * @return array<string, mixed>
     */
    public function movement(WhMovement $movement, array $documents, array $journal): array
    {
        return [
            ...$this->movementRow($movement),
            'note' => $movement->getNote(),
            'items' => array_map(fn (WhMovementLine $l) => [
                'id' => $l->getId(),
                'item' => ['id' => $l->getItem()->getId(), 'inventoryNumber' => $l->getItem()->getInventoryNumber(), 'name' => $l->getItem()->getName(), 'unit' => $l->getItem()->getUnit()],
                'quantity' => $l->getQuantity(),
                'rentalRate' => $l->getRentalRate(),
            ], $movement->getLines()->toArray()),
            'documents' => array_map($this->document(...), $documents),
            'journal' => array_map($this->activity(...), $journal),
            'twig' => $this->urls->generate('wh_movement', ['id' => $movement->getId()]),
        ];
    }

    /** @return array<string, mixed> */
    public function document(WhDocument $document): array
    {
        $url = $this->documentUrl($document);

        return [
            'id' => $document->getId(),
            'name' => $document->getOriginalName(),
            'type' => $document->getType()->value,
            'typeLabel' => $document->getType()->label(),
            'mime' => $document->getMime(),
            'size' => $document->getSize(),
            'sizeLabel' => $document->getSizeLabel(),
            'photo' => $document->isViewablePhoto(),
            'url' => $url,
            'download' => $url . '?download=1',
            'driveUrl' => $document->getDriveUrl(),
            'uploadedBy' => $document->getUploadedBy()?->shortName(),
        ];
    }

    /** @return array<string, mixed> */
    public function activity(WhActivity $entry): array
    {
        return [
            'id' => $entry->getId(),
            'action' => $entry->getAction()->value,
            'actionLabel' => $entry->getAction()->label(),
            'emoji' => $entry->getAction()->emoji(),
            'subjectType' => $entry->getSubjectType(),
            'subjectId' => $entry->getSubjectId(),
            'subjectLabel' => $entry->getSubjectLabel(),
            'user' => $this->person($entry->getUser()),
            'channel' => $entry->getChannel(),
            'channelLabel' => $entry->getChannel() === WhActivity::CHANNEL_BOT ? 'Telegram-бот' : 'План',
            'details' => $entry->getDetails(),
            'at' => $entry->getAt()->format(DateTimeInterface::ATOM),
        ];
    }

    /** Типи рухів із правилами «звідки → куди» — форма руху показує лише потрібні поля. */
    public static function movementTypes(): array
    {
        return array_map(static fn (MovementType $t) => [
            'value' => $t->value,
            'label' => $t->label(),
            'emoji' => $t->emoji(),
            'needsFrom' => $t->needsFrom(),
            'needsTo' => $t->needsTo(),
            'fromKind' => $t->fromKind()?->value,
            'toKind' => $t->toKind()?->value,
        ], MovementType::cases());
    }

    /** «Склад → ЖК Лісовий» — як у журналі Twig-складу. */
    public static function route(WhMovement $movement): string
    {
        return sprintf('%s → %s', $movement->getFromSite()?->getName() ?? 'ззовні', $movement->getToSite()?->getName() ?? 'списано');
    }

    private function documentUrl(WhDocument $document): string
    {
        return $this->urls->generate('api_wh_document', ['id' => $document->getId()]);
    }
}
