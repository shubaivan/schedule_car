<?php

namespace App\Tests\Telegram;

use App\Supply\Telegram\SupplyCallback;
use App\Supply\Telegram\SupplyMenu;
use App\Telegram\Start\Command\StartCommand;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Правило: екранів без виходу не буває.
 *
 * 18.08 з розділу «Постачання», списків і картки заявки повернутись було
 * нікуди — рятувала лише команда /start, про яку людина має здогадатись.
 */
class NavigationTest extends TestCase
{
    public function testSupplySectionHasWayHome(): void
    {
        $this->assertContains(
            StartCommand::MAIN_MENU,
            $this->callbacks(SupplyMenu::keyboard()),
            'З розділу «Постачання» немає виходу на головну.',
        );
    }

    public function testNavRowIsBackPlusHome(): void
    {
        $row = StartCommand::navRow(SupplyCallback::MENU, '⬅️ Постачання');

        $this->assertCount(2, $row);
        $this->assertSame(SupplyCallback::MENU, $row[0]->callback_data);
        $this->assertSame(StartCommand::MAIN_MENU, $row[1]->callback_data);
    }

    public function testMainMenuLeadsToBothSections(): void
    {
        $callbacks = $this->callbacks(StartCommand::mainMenuKeyboard());

        $this->assertContains(SupplyCallback::MENU, $callbacks);
        $this->assertContains(StartCommand::FLEET_MENU, $callbacks);
    }

    /**
     * Вимкнений автопарк не лишає по собі мертвої кнопки: її просто немає,
     * а старі кнопки в чаті ловить FleetEnabled і показує пояснення.
     */
    public function testMainMenuHidesFleetWhenSectionIsOff(): void
    {
        $callbacks = $this->callbacks(StartCommand::mainMenuKeyboard(false));

        $this->assertContains(SupplyCallback::MENU, $callbacks);
        $this->assertNotContains(StartCommand::FLEET_MENU, $callbacks);
    }

    /** @return string[] */
    private function callbacks(InlineKeyboardMarkup $markup): array
    {
        $callbacks = [];

        foreach ($markup->inline_keyboard as $row) {
            foreach ($row as $button) {
                if ($button->callback_data !== null) {
                    $callbacks[] = $button->callback_data;
                }
            }
        }

        return $callbacks;
    }
}
