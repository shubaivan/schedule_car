<?php

namespace App\Warehouse\Controller;

use App\Entity\TelegramUser;
use App\Warehouse\Entity\WhActivity;
use App\Warehouse\Entity\WhCategory;
use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Service\ActivityLog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Спільне для сторінок складу в адмінці.
 *
 * Хто сюди потрапляє, вирішує access_control (^/sklad — менеджер, адмін,
 * директор), а чи розділ узагалі ввімкнено — WarehouseGate.
 */
abstract class AbstractWarehouseController extends AbstractController
{
    protected const CSRF = 'warehouse';

    /** Мітка в адресі картки, на яку привело посилання з бота. */
    public const VIA_BOT = 'bot';

    protected ActivityLog $activity;

    #[Required]
    public function setActivityLog(ActivityLog $activity): void
    {
        $this->activity = $activity;
    }

    protected function log(
        ActivityAction $action,
        WhItem|WhClient|WhSite|WhMovement|WhCategory $subject,
        ?string $details = null,
    ): void {
        $this->activity->record($action, $subject, $this->user(), WhActivity::CHANNEL_ADMIN, $details);
    }

    /**
     * Картку відкрили. Якщо на неї привело посилання з бота (?via=bot) —
     * це перехід, і в журналі він окремим рядком: так видно, хто звідки прийшов.
     */
    protected function viewed(Request $request, WhItem|WhClient|WhSite|WhMovement $subject): void
    {
        $request->query->get('via') === self::VIA_BOT
            ? $this->log(ActivityAction::Link, $subject, 'з бота в адмінку')
            : $this->log(ActivityAction::View, $subject);
    }

    protected function user(): TelegramUser
    {
        $user = $this->getUser();

        if (! $user instanceof TelegramUser) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }

    /** Кожна форма складу несе токен; без нього POST не приймаємо. */
    protected function checkCsrf(Request $request): void
    {
        if (! $this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Форма застаріла — оновіть сторінку й спробуйте ще раз.');
        }
    }

    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        return $request->request->all();
    }
}
