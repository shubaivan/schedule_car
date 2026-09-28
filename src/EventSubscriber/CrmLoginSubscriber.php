<?php

namespace App\EventSubscriber;

use App\Entity\CrmLogin;
use App\Entity\TelegramUser;
use App\Service\CrmLoginLink;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Throwable;

/**
 * Пише кожен вхід у журнал crm_login — див. CrmLogin, навіщо.
 *
 * Краулер превʼю Telegram (він відкриває посилання раніше за людину) у журнал
 * не потрапляє: це не спроба входу, а шум, який заступив би справжні рядки.
 */
class CrmLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onSuccess',
            LoginFailureEvent::class => 'onFailure',
        ];
    }

    public function onSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        $this->record($event->getRequest(), $user instanceof TelegramUser ? $user : null, true, null);
    }

    public function onFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();

        if (str_contains((string) $request->headers->get('User-Agent'), 'TelegramBot')) {
            return;
        }

        $this->record($request, null, false, $event->getException()->getMessageKey());
    }

    private function record(Request $request, ?TelegramUser $user, bool $success, ?string $reason): void
    {
        try {
            $this->em->persist(new CrmLogin(
                $user,
                $success,
                $reason,
                $request->getClientIp(),
                $request->headers->get('User-Agent'),
                CrmLoginLink::safeNext($request->query->get('next')) ?? '/crm',
            ));
            $this->em->flush();
        } catch (Throwable $e) {
            // Журнал — не причина не пустити людину.
            $this->logger->warning('crm: вхід не записано в журнал', ['error' => $e->getMessage()]);
        }
    }
}
