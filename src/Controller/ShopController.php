<?php

namespace App\Controller;

use App\Entity\CrmLogin;
use App\Entity\TelegramUser;
use App\Service\ShopLink;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Перехід із CRM в адмінку магазину — див. ShopLink. */
class ShopController extends AbstractController
{
    #[Route('/shop', name: 'app_shop', methods: ['GET'])]
    public function go(Request $request, ShopLink $shop, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        if (! $user instanceof TelegramUser || ! $shop->availableTo($user)) {
            throw $this->createAccessDeniedException('Магазин ведуть менеджер, адміністратор або директор.');
        }

        $to = ShopLink::safeTarget($request->query->get('to'));

        // Перехід у магазин — теж вхід, тільки в іншу частину: у журналі входів він окремим рядком.
        $em->persist(new CrmLogin($user, true, null, $request->getClientIp(), $request->headers->get('User-Agent'), '🛒 ' . $to));
        $em->flush();

        return new RedirectResponse($shop->url($user, $to));
    }
}
