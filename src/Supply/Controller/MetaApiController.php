<?php

namespace App\Supply\Controller;

use App\Entity\TelegramUser;
use App\Plan\Access\PlanAccess;
use App\Plan\Access\PlanSections;
use App\Plan\Login\PasswordSetup;
use App\Plan\Zvyazok\ZvyazokAccess;
use App\Service\FleetSection;
use App\Service\ShopLink;
use App\Supply\Enum\SupplyAccent;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Enum\Unit;
use App\Supply\Repository\DepartmentRepository;
use App\Supply\Service\RequestPresenter;
use App\Tasks\Service\TasksSection;
use App\Warehouse\Service\WarehouseSection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Хто я + усі довідники одним запитом на старті CRM. */
#[Route('/api')]
class MetaApiController extends AbstractController
{
    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(RequestPresenter $presenter, WarehouseSection $warehouse, ShopLink $shop, TasksSection $tasks, FleetSection $fleet, PlanAccess $access, PlanSections $sections, ZvyazokAccess $zvyazok, #[Autowire('%password_login_enabled%')] bool $passwordLogin = false): JsonResponse
    {
        /** @var TelegramUser $user */
        $user = $this->getUser();

        // Які розділи показати у верхньому ряду: склад (/sklad) і магазин (/shop).
        return $this->json([
            ...$presenter->user($user),
            'warehouse' => $warehouse->inMenuFor($user) && $sections->full($user),
            // Склад у «Плані»: розділ увімкнено; бачить / веде — capabilities warehouse.view / warehouse.edit.
            'warehouseEnabled' => $warehouse->isEnabled(),
            'shop' => $shop->availableTo($user) && $sections->full($user),
            // Розділ «Задачі» і з чого відкривати CRM (CRM_HOME).
            'tasks' => $tasks->enabled(),
            'home' => $tasks->home(),
            // У «Плані» людина — «Менеджер з продажу», а не роль із заявок («Робітник»).
            'workRole' => $user->getWorkRole()->value,
            'workRoleLabel' => $user->getWorkRole()->label(),
            'position' => $user->getPosition(),
            'fleet' => $fleet->isEnabled() && $sections->full($user),
            // Повний доступ (PlanSections): без нього — лише Огляд, Хроніка, Задачі, Пошук, Сповіщення, Люди.
            'fullAccess' => $sections->full($user),
            // Вхід за телефоном і паролем (PasswordSetup): чи задано пароль і чи може бот надіслати посилання.
            // «Звʼязок»: до погодження — лише повний доступ, потім PLAN_ZVYAZOK_FOR_ALL=1.
            'zvyazok' => $zvyazok->can($user),
            'hasPassword' => $user->getPassword() !== null,
            'passwordLink' => $passwordLogin && PasswordSetup::eligible($user),
            // Матриця можливостей «Плану» (PlanAccess): що показувати в меню, напр. audit.view → «Адмін».
            'capabilities' => $access->matrix($user),
        ]);
    }

    #[Route('/supply/meta', name: 'api_supply_meta', methods: ['GET'])]
    public function meta(DepartmentRepository $departments): JsonResponse
    {
        return $this->json([
            'statuses' => array_map(
                static fn (SupplyStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'emoji' => $status->emoji(),
                    'final' => $status->isFinal(),
                ],
                SupplyStatus::cases(),
            ),
            'accents' => array_map(
                static fn (SupplyAccent $accent) => [
                    'value' => $accent->value,
                    'label' => $accent->label(),
                    'emoji' => $accent->emoji(),
                ],
                SupplyAccent::cases(),
            ),
            'units' => array_map(
                static fn (Unit $unit) => ['value' => $unit->value, 'label' => $unit->label()],
                Unit::cases(),
            ),
            'departments' => array_map(
                static fn ($department) => ['id' => $department->getId(), 'name' => $department->getName()],
                $departments->findActive(),
            ),
        ]);
    }
}
