<?php

namespace App\Twig;

use App\Entity\TelegramUser;
use App\Service\ShopLink;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Для верхнього ряду розділів у Twig-сторінках: чи показувати «🛒 Магазин». */
class AdminSectionsExtension extends AbstractExtension
{
    public function __construct(
        private ShopLink $shop,
        private Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('shop_enabled', $this->shopEnabled(...))];
    }

    public function shopEnabled(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof TelegramUser && $this->shop->availableTo($user);
    }
}
