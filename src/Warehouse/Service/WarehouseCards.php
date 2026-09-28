<?php

namespace App\Warehouse\Service;

use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\ItemState;
use App\Warehouse\Repository\WhDocumentRepository;
use App\Warehouse\Repository\WhMovementRepository;
use DateTime;

/**
 * Картки складу в боті: відповідь на скан наклейки чи перехід за посиланням.
 *
 * Ціни, ставки й контакти клієнтів — лише тим, хто веде склад. Працівник на
 * об'єкті сканує, щоб зрозуміти «чий це щит і звідки він тут», а не скільки
 * він коштує.
 */
class WarehouseCards
{
    public function __construct(
        private WarehouseStock $stock,
        private WhMovementRepository $movements,
        private WhDocumentRepository $documents,
    ) {
    }

    public function item(WhItem $item, bool $withMoney, ?DateTime $today = null): string
    {
        $today ??= new DateTime('today');

        $lines = [
            sprintf('%s <b>%s</b>', $item->getCategory()->getEmoji() ?? '📦', $this->e($item->getName())),
            sprintf('%s · %s · %s', $this->e($item->getInventoryNumber()), $this->e($item->getCategory()->getName()), mb_strtolower($item->getTracking()->label())),
        ];

        foreach ($item->getAttributes() as $name => $value) {
            $lines[] = sprintf('%s: %s', $this->e((string) $name), $this->e((string) $value));
        }

        if ($item->getSerialNumber() !== null) {
            $lines[] = 'Зав. №: ' . $this->e($item->getSerialNumber());
        }

        if ($item->getState() !== ItemState::Active) {
            $lines[] = ($item->getState() === ItemState::WrittenOff ? '🗑 ' : '🛠 ') . '<b>' . $item->getState()->label() . '</b>';
        }

        $lines[] = '';
        $places = $this->stock->placesOf($item);

        if ($item->isUnit()) {
            $site = $item->getCurrentSite();

            if ($site === null) {
                $lines[] = $item->getState() === ItemState::WrittenOff ? '📍 Списано' : '📍 Ще не надійшло на склад';
            } else {
                $lines[] = '📍 <b>' . $this->e($site->getLabel()) . '</b>';

                if ($site->getAddress() !== null) {
                    $lines[] = '   ' . $this->e($site->getAddress());
                }

                if ($item->getCurrentSince() !== null) {
                    $days = (int) $item->getCurrentSince()->diff($today)->days;
                    $lines[] = sprintf('   з %s (%s)', $item->getCurrentSince()->format('d.m.Y'), $this->days($days));
                }
            }
        } elseif ($places === []) {
            $lines[] = '📍 Залишків немає';
        } else {
            $lines[] = '📍 <b>Де лежить:</b>';

            foreach ($places as $place) {
                $lines[] = sprintf('   %s — %d %s', $this->e($place['site']->getLabel()), $place['quantity'], $this->e($item->getUnit()));
            }

            $lines[] = sprintf('   Разом: %d %s', $this->stock->total($item), $this->e($item->getUnit()));
        }

        if ($withMoney) {
            $money = [];

            if ($item->getPurchasePrice() !== null) {
                $money[] = 'ціна ' . $this->money($item->getPurchasePrice()) . ($item->isUnit() ? '' : '/' . $this->e($item->getUnit()));
            }

            if ($item->getRentalRate() !== null) {
                $money[] = 'оренда ' . $this->money($item->getRentalRate()) . '/доба';
            }

            if ($money !== []) {
                $lines[] = '';
                $lines[] = '💰 ' . implode(' · ', $money);
            }

            if ($item->getSupplier() !== null) {
                $lines[] = '🏭 ' . $this->e($item->getSupplier()->getName());
            }
        }

        $history = $this->movements->historyOf($item, 5);

        if ($history !== []) {
            $lines[] = '';
            $lines[] = '<b>Останні рухи:</b>';

            foreach ($history as $movement) {
                $lines[] = $this->movementLine($movement, $item);
            }
        }

        $documents = count($this->documents->findBy(['item' => $item]));

        if ($documents > 0) {
            $lines[] = '';
            $lines[] = sprintf('📎 Документів у картці: %d', $documents);
        }

        return implode("\n", $lines);
    }

    public function client(WhClient $client): string
    {
        $lines = [
            sprintf('%s <b>%s</b>', $client->getCategory()?->getEmoji() ?? '🤝', $this->e($client->getName())),
        ];

        $facts = array_filter([
            $client->getCategory()?->getName(),
            $client->getEdrpou() !== null ? 'ЄДРПОУ ' . $client->getEdrpou() : null,
        ]);

        if ($facts !== []) {
            $lines[] = $this->e(implode(' · ', $facts));
        }

        foreach (array_filter([$client->getContactPerson(), $client->getPhone()]) as $contact) {
            $lines[] = '📞 ' . $this->e($contact);
        }

        $lines[] = '';

        if ($client->getSites()->isEmpty()) {
            $lines[] = "Об'єктів ще немає.";
        } else {
            $lines[] = "<b>Об'єкти:</b>";

            foreach ($client->getSites() as $site) {
                $lines[] = sprintf('🏗 %s — позицій: %d', $this->e($site->getName()), count($this->stock->contentsOf($site)));
            }
        }

        $documents = count($this->documents->findBy(['client' => $client]));

        if ($documents > 0) {
            $lines[] = '';
            $lines[] = sprintf('📎 Документів клієнта: %d', $documents);
        }

        return implode("\n", $lines);
    }

    public function site(WhSite $site, bool $withMoney): string
    {
        $lines = [sprintf('%s <b>%s</b>', $site->isWarehouse() ? '🏠' : ($site->getCategory()?->getEmoji() ?? '🏗'), $this->e($site->getName()))];

        if ($site->getClient() !== null) {
            $lines[] = '🤝 ' . $this->e($site->getClient()->getName());
        }

        if ($site->getAddress() !== null) {
            $lines[] = '📍 ' . $this->e($site->getAddress());
        }

        $contents = $this->stock->contentsOf($site);
        $lines[] = '';

        if ($contents === []) {
            $lines[] = 'Зараз тут нічого немає.';
        } else {
            $lines[] = sprintf('<b>Зараз тут (%d):</b>', count($contents));

            foreach (array_slice($contents, 0, 30) as $row) {
                $rate = $withMoney && $row['rate'] !== null ? ' · ' . $this->money($row['rate']) . '/доба' : '';
                $lines[] = sprintf(
                    '• %s — %d %s%s',
                    $this->e($row['item']->getLabel()),
                    $row['quantity'],
                    $this->e($row['item']->getUnit()),
                    $rate,
                );
            }

            if (count($contents) > 30) {
                $lines[] = sprintf('… і ще %d — повний список в адмінці', count($contents) - 30);
            }
        }

        return implode("\n", $lines);
    }

    public function movement(WhMovement $movement): string
    {
        $lines = [
            sprintf('%s <b>%s</b>', $movement->getType()->emoji(), $this->e($movement->getTitle())),
            $this->e(sprintf(
                '%s → %s',
                $movement->getFromSite()?->getLabel() ?? ($movement->getSupplier()?->getName() ?? 'ззовні'),
                $movement->getToSite()?->getLabel() ?? 'списано',
            )),
            '',
        ];

        foreach ($movement->getLines() as $line) {
            $lines[] = sprintf('• %s — %d %s', $this->e($line->getItem()->getLabel()), $line->getQuantity(), $this->e($line->getItem()->getUnit()));
        }

        if ($movement->getNote() !== null) {
            $lines[] = '';
            $lines[] = '📝 ' . $this->e($movement->getNote());
        }

        $documents = count($this->documents->findBy(['movement' => $movement]));

        if ($documents > 0) {
            $lines[] = '';
            $lines[] = sprintf('📎 Документів: %d', $documents);
        }

        return implode("\n", $lines);
    }

    private function movementLine(WhMovement $movement, WhItem $item): string
    {
        $quantity = '';

        if (! $item->isUnit()) {
            foreach ($movement->getLines() as $line) {
                if ($line->getItem()->getId() === $item->getId()) {
                    $quantity = sprintf(' · %d %s', $line->getQuantity(), $item->getUnit());
                }
            }
        }

        $route = match (true) {
            $movement->getFromSite() !== null && $movement->getToSite() !== null => $movement->getFromSite()->getName() . ' → ' . $movement->getToSite()->getName(),
            $movement->getToSite() !== null => '→ ' . $movement->getToSite()->getName(),
            $movement->getFromSite() !== null => $movement->getFromSite()->getName() . ' →',
            default => '',
        };

        return $this->e(sprintf(
            '%s %s %s%s%s',
            $movement->getType()->emoji(),
            $movement->getOccurredAt()->format('d.m'),
            $movement->getType()->label(),
            $route !== '' ? ': ' . $route : '',
            $quantity,
        ));
    }

    private function days(int $days): string
    {
        $mod10 = $days % 10;
        $mod100 = $days % 100;

        $word = match (true) {
            $mod10 === 1 && $mod100 !== 11 => 'доба',
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => 'доби',
            default => 'діб',
        };

        return $days . ' ' . $word;
    }

    private function money(string $amount): string
    {
        return number_format((float) $amount, 2, ',', ' ') . ' грн';
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
