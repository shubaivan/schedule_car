<?php

namespace App\Warehouse\Service;

use App\Entity\TelegramUser;
use App\Service\TeamChat;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;

/**
 * Склад у темі «Склад» робочої групи: нова позиція і кожен рух.
 *
 * Без грошей: ставки оренди бачать ті, кому вони потрібні, у картці, а група
 * — це «що приїхало й куди поїхало». Кнопка веде в картку в боті — тим самим
 * посиланням, що й «🔗 Поділитися», тож перехід потрапить у журнал.
 */
class WarehouseFeed
{
    public function __construct(
        private TeamChat $team,
        private WarehouseCards $cards,
        private WarehouseLinks $links,
    ) {
    }

    public function itemCreated(WhItem $item, TelegramUser $by): void
    {
        $this->team->post(
            TeamChat::WAREHOUSE,
            "➕ <b>Нова позиція</b>\n\n" . $this->cards->item($item, withMoney: false) . $this->by($by),
            $this->links->url($item),
        );
    }

    public function movementRecorded(WhMovement $movement, TelegramUser $by): void
    {
        $this->team->post(
            TeamChat::WAREHOUSE,
            $this->cards->movement($movement) . $this->by($by),
            $this->links->url($movement),
        );
    }

    private function by(TelegramUser $user): string
    {
        return "\n\n✍️ " . htmlspecialchars($user->displayName(), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
