<?php

namespace App\Warehouse\Service;

use App\Entity\TelegramUser;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhMovementLine;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\ItemState;
use App\Warehouse\Enum\MovementType;
use App\Warehouse\Exception\WarehouseException;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Записати рух майна: перевірити, що він можливий, і пересунути позиції.
 *
 * Усі перевірки — тут, а не у формі: рух, який не зійдеться з фактом
 * (щит «поїхав» зі складу, де його немає; повернули більше замків, ніж
 * відвантажили), зламає і картку позиції, і залишки об'єкта.
 */
class RecordMovement
{
    public function __construct(
        private EntityManagerInterface $em,
        private WarehouseStock $stock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array{item: WhItem, quantity: int, rate?: ?string}> $lines
     */
    public function __invoke(
        MovementType $type,
        DateTime $occurredAt,
        ?WhSite $from,
        ?WhSite $to,
        array $lines,
        TelegramUser $by,
        ?string $documentNumber = null,
        ?string $counterparty = null,
        ?string $note = null,
    ): WhMovement {
        if (! WarehouseSection::canManage($by)) {
            throw new WarehouseException('Рухи на складі записують менеджер, адміністратор або директор.');
        }

        $this->checkPlaces($type, $from, $to);

        if ($lines === []) {
            throw new WarehouseException('Додайте хоча б одну позицію.');
        }

        $movement = (new WhMovement())
            ->setType($type)
            ->setOccurredAt($occurredAt)
            ->setFromSite($from)
            ->setToSite($to)
            ->setDocumentNumber($this->clean($documentNumber))
            ->setCounterparty($this->clean($counterparty))
            ->setNote($this->clean($note))
            ->setCreatedBy($by);

        $seen = [];

        foreach ($lines as $line) {
            $item = $line['item'];
            $quantity = (int) $line['quantity'];
            $key = (int) $item->getId();

            if (isset($seen[$key])) {
                throw new WarehouseException(sprintf('Позиція %s у списку двічі — об\'єднайте рядки.', $item->getLabel()));
            }

            $seen[$key] = true;

            $this->checkLine($item, $quantity, $from, $type);

            $movement->addLine(
                (new WhMovementLine())
                    ->setItem($item)
                    ->setQuantity($quantity)
                    ->setRentalRate($this->rate($line['rate'] ?? null, $item, $to)),
            );
        }

        foreach ($movement->getLines() as $line) {
            $item = $line->getItem();

            if ($type === MovementType::WriteOff && $item->isUnit()) {
                $item->setState(ItemState::WrittenOff);
            }

            if ($item->isUnit()) {
                $item->placeAt($to, $occurredAt);
            }
        }

        $this->em->persist($movement);
        $this->em->flush();

        $this->logger->info('warehouse: записано рух', [
            'movement' => $movement->getId(),
            'type' => $type->value,
            'from' => $from?->getName(),
            'to' => $to?->getName(),
            'lines' => count($lines),
            'by' => $by->displayName(),
        ]);

        return $movement;
    }

    private function checkPlaces(MovementType $type, ?WhSite $from, ?WhSite $to): void
    {
        if ($type->needsFrom() && $from === null) {
            throw new WarehouseException('Вкажіть, звідки везуть.');
        }

        if ($type->needsTo() && $to === null) {
            throw new WarehouseException('Вкажіть, куди везуть.');
        }

        if (! $type->needsFrom() && $from !== null) {
            throw new WarehouseException('Надходження приходить ззовні — поле «звідки» лишіть порожнім, а постачальника впишіть окремо.');
        }

        if (! $type->needsTo() && $to !== null) {
            throw new WarehouseException('Списане нікуди не їде — поле «куди» лишіть порожнім.');
        }

        if ($from !== null && $to !== null && $from->getId() === $to->getId()) {
            throw new WarehouseException('«Звідки» і «куди» — те саме місце.');
        }

        if ($type->fromKind() !== null && $from !== null && $from->getKind() !== $type->fromKind()) {
            throw new WarehouseException(sprintf('%s йде зі: %s.', $type->label(), mb_strtolower($type->fromKind()->label())));
        }

        if ($type->toKind() !== null && $to !== null && $to->getKind() !== $type->toKind()) {
            throw new WarehouseException(sprintf('%s йде на: %s.', $type->label(), mb_strtolower($type->toKind()->label())));
        }

        if ($to !== null && ! $to->isActive()) {
            throw new WarehouseException(sprintf('Місце «%s» закрите — відкрийте його знову або оберіть інше.', $to->getName()));
        }
    }

    private function checkLine(WhItem $item, int $quantity, ?WhSite $from, MovementType $type): void
    {
        if ($item->getState() === ItemState::WrittenOff) {
            throw new WarehouseException(sprintf('%s списано — рухати його вже не можна.', $item->getLabel()));
        }

        if ($quantity < 1) {
            throw new WarehouseException(sprintf('Кількість для %s має бути більшою за нуль.', $item->getLabel()));
        }

        if ($item->isUnit()) {
            if ($quantity !== 1) {
                throw new WarehouseException(sprintf('%s рахується поштучно — у рядку лише 1.', $item->getLabel()));
            }

            $here = $item->getCurrentSite();

            if ($type === MovementType::Receipt && $here !== null) {
                throw new WarehouseException(sprintf('%s уже надійшло і зараз: %s.', $item->getLabel(), $here->getLabel()));
            }

            if ($from !== null && $here?->getId() !== $from->getId()) {
                throw new WarehouseException(sprintf(
                    '%s зараз не там: %s.',
                    $item->getLabel(),
                    $here !== null ? $here->getLabel() : 'ще не надійшло на склад',
                ));
            }

            return;
        }

        if ($from !== null) {
            $available = $this->stock->balanceAt($item, $from);

            if ($available < $quantity) {
                throw new WarehouseException(sprintf(
                    '%s: на місці «%s» є %d %s, а в русі %d.',
                    $item->getLabel(),
                    $from->getName(),
                    $available,
                    $item->getUnit(),
                    $quantity,
                ));
            }
        }
    }

    /**
     * Ставка оренди має сенс лише там, де майно їде до клієнта. Не вказали —
     * береться ставка з картки позиції.
     */
    private function rate(?string $rate, WhItem $item, ?WhSite $to): ?string
    {
        if ($to === null || $to->getClient() === null) {
            return null;
        }

        $rate = $rate !== null ? str_replace([',', ' '], ['.', ''], trim($rate)) : '';

        if ($rate === '') {
            return $item->getRentalRate();
        }

        if (! is_numeric($rate) || (float) $rate < 0) {
            throw new WarehouseException(sprintf('Ставка оренди для %s — не число.', $item->getLabel()));
        }

        return number_format((float) $rate, 2, '.', '');
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
