<?php

namespace App\Warehouse\Api;

use App\Entity\TelegramUser;
use App\Plan\EventStore\PlanEventRecorder;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\DocumentType;
use App\Warehouse\Enum\MovementType;
use App\Warehouse\Enum\SiteKind;
use App\Warehouse\Exception\WarehouseException;
use App\Warehouse\Repository\WhItemRepository;
use App\Warehouse\Repository\WhSiteRepository;
use App\Warehouse\Service\ActivityLog;
use App\Warehouse\Service\DocumentStore;
use App\Warehouse\Service\RecordMovement;
use App\Warehouse\Service\WarehouseDirectory;
use App\Warehouse\Service\WarehouseFeed;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

/**
 * Новий рух зі сторінки «Плану» /sklad/movements/new — той самий шлях, що й
 * форма Twig-складу (MovementController::new): RecordMovement перевіряє й
 * пересуває, накладна прикріплюється в тій самій транзакції (рух без своєї
 * накладної не записуємо), потім рядки журналу складу на рух і на кожну
 * позицію, пост у тему «Склад».
 *
 * Подія «Плану» — рівно одна: StockMoved від RecordMovement. Рядки журналу
 * «Записав рух» у plan_event не дублюються (ActivityLog::event → null для Move).
 */
final class MovementWriter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecordMovement $record,
        private readonly DocumentStore $documents,
        private readonly WarehouseDirectory $directory,
        private readonly WhItemRepository $items,
        private readonly WhSiteRepository $sites,
        private readonly ActivityLog $activity,
        private readonly WarehouseFeed $feed,
        private readonly PlanEventRecorder $events,
    ) {
    }

    /**
     * @param array<string, mixed> $data поля форми (див. frontend MovementFormPage)
     * @param list<UploadedFile> $files
     */
    public function create(array $data, array $files, TelegramUser $by): WhMovement
    {
        $type = MovementType::tryFrom((string) ($data['type'] ?? '')) ?? throw new WarehouseException('Оберіть тип руху.');

        foreach ($files as $file) {
            if (! $file->isValid()) {
                throw new WarehouseException('Файл не долетів: ' . $file->getErrorMessage());
            }
        }

        try {
            // Через рекордер, а не сиру транзакцію EntityManager: тоді StockMoved роздається
            // споживачам (sync) лише після коміту ВСЬОГО — руху разом із накладною.
            $movement = $this->events->transactional(function () use ($type, $data, $files, $by): WhMovement {
                $movement = ($this->record)(
                    $type,
                    $this->date((string) ($data['occurredAt'] ?? '')),
                    $this->site($data['fromId'] ?? null),
                    $this->target($type, $data, $by),
                    $this->lines($data['lines'] ?? []),
                    $by,
                    isset($data['documentNumber']) ? (string) $data['documentNumber'] : null,
                    $type->needsFrom() ? null : $this->directory->supplier($data['supplierId'] ?? null, $by),
                    isset($data['note']) ? (string) $data['note'] : null,
                );

                $documentType = DocumentType::tryFrom((string) ($data['documentType'] ?? '')) ?? DocumentType::Waybill;

                foreach ($files as $file) {
                    $this->documents->attach(
                        $movement,
                        $by,
                        (string) file_get_contents($file->getPathname()),
                        (string) ($file->getClientOriginalName() ?: $file->getFilename()),
                        (string) ($file->getMimeType() ?: $file->getClientMimeType()),
                        $documentType,
                    );
                }

                // Як у Twig: рух — у журнал самого руху і кожної позиції, у тій самій транзакції.
                $route = WarehousePresenter::route($movement);
                $this->activity->recordStrict(ActivityAction::Move, $movement, $by, WhActivity::CHANNEL_ADMIN, $route);

                foreach ($movement->getLines() as $line) {
                    $this->activity->recordStrict(
                        ActivityAction::Move,
                        $line->getItem(),
                        $by,
                        WhActivity::CHANNEL_ADMIN,
                        sprintf('%s: %s, %d %s', $movement->getTitle(), $route, $line->getQuantity(), $line->getItem()->getUnit()),
                    );
                }

                return $movement;
            });
        } catch (Throwable $e) {
            // Рух і накладна відкотились — сутності в EntityManager уже не відповідають базі.
            $this->em->clear();

            throw $e;
        }

        $this->feed->movementRecorded($movement, $by);

        return $movement;
    }

    /**
     * Рядки руху: [{itemId, quantity, rate}].
     *
     * @return list<array{item: WhItem, quantity: int, rate: ?string}>
     */
    private function lines(mixed $lines): array
    {
        if (! is_array($lines)) {
            throw new WarehouseException('Додайте хоча б одну позицію.');
        }

        $out = [];

        foreach ($lines as $line) {
            if (! is_array($line) || ! ctype_digit((string) ($line['itemId'] ?? ''))) {
                continue;
            }

            $item = $this->items->find((int) $line['itemId']) ?? throw new WarehouseException('Позицію не знайдено — оновіть сторінку.');
            $quantity = trim((string) ($line['quantity'] ?? '1'));

            if (! ctype_digit($quantity)) {
                throw new WarehouseException(sprintf('Кількість для %s — ціле число.', $item->getLabel()));
            }

            $rate = $line['rate'] ?? null;
            $out[] = ['item' => $item, 'quantity' => (int) $quantity, 'rate' => $rate === null || $rate === '' ? null : (string) $rate];
        }

        return $out;
    }

    /**
     * «Куди»: наявне місце або новий об'єкт («new:Назва»), як у Twig-формі.
     *
     * @param array<string, mixed> $data
     */
    private function target(MovementType $type, array $data, TelegramUser $by): ?WhSite
    {
        $value = $data['toId'] ?? null;

        if (! str_starts_with((string) $value, WarehouseDirectory::NEW)) {
            return $this->site($value);
        }

        $kind = $type->toKind() ?? SiteKind::Site;
        $client = $kind === SiteKind::Site ? $this->directory->client($data['toClientId'] ?? null, $by) : null;

        return $this->directory->site($value, $kind, $client, $by);
    }

    private function site(mixed $id): ?WhSite
    {
        if ($id === null || $id === '') {
            return null;
        }

        return ctype_digit((string) $id)
            ? ($this->sites->find((int) $id) ?? throw new WarehouseException('Такого місця вже немає — оновіть сторінку.'))
            : throw new WarehouseException('Місце вказано неправильно — оберіть зі списку.');
    }

    private function date(string $value): DateTime
    {
        $date = DateTime::createFromFormat('!Y-m-d', trim($value));

        if ($date === false) {
            throw new WarehouseException('Вкажіть дату руху.');
        }

        return $date;
    }
}
