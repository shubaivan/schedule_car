<?php

namespace App\EventSubscriber;

use App\Entity\CrmLogin;
use App\Entity\TelegramUser;
use App\Plan\Event\LoginFailed;
use App\Plan\Event\UserLoggedIn;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Repository\TelegramUserRepository;
use App\Security\PasswordLoginAuthenticator;
use App\Service\CrmLoginLink;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Throwable;

/**
 * Пише кожен вхід у журнал crm_login — див. CrmLogin, навіщо, — і тією ж
 * транзакцією подію UserLoggedIn / LoginFailed у журнал аудиту «Плану»
 * (refId = crm_login.id: plan:events:backfill за ним бачить, що вхід уже
 * перенесено, і не дублює).
 *
 * Невдалий вхід за паролем із відомим логіном пишеться на ту людину (суб'єкт
 * user-N) — так видно, кого підбирають; невідомий логін — на «невідомого» (user-0),
 * сам набраний логін іде в name (обрізаний), пароль — ніколи.
 *
 * Краулер превʼю Telegram (він відкриває посилання раніше за людину) у журнал
 * не потрапляє: це не спроба входу, а шум, який заступив би справжні рядки.
 */
class CrmLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private PlanEventRecorder $events,
        private TelegramUserRepository $users,
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

        $method = $event->getAuthenticator() instanceof PasswordLoginAuthenticator ? 'password' : 'bot';

        $this->record($event->getRequest(), $user instanceof TelegramUser ? $user : null, true, null, $method);
    }

    public function onFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();

        if (str_contains((string) $request->headers->get('User-Agent'), 'TelegramBot')) {
            return;
        }

        $password = $event->getAuthenticator() instanceof PasswordLoginAuthenticator;

        $this->record($request, null, false, $event->getException()->getMessageKey(), $password ? 'password' : 'bot');
    }

    private function record(Request $request, ?TelegramUser $user, bool $success, ?string $reason, string $method): void
    {
        try {
            $login = new CrmLogin(
                $user,
                $success,
                $reason,
                $request->getClientIp(),
                $request->headers->get('User-Agent'),
                CrmLoginLink::safeNext($request->query->get('next')) ?? '/crm',
            );
            $this->em->persist($login);

            $this->events->transactional(function () use ($login, $user, $success, $reason, $method, $request): void {
                $this->em->flush();
                $agent = $login->getUserAgent();
                $ref = (int) $login->getId();

                if ($success && $user !== null) {
                    $this->events->record(new UserLoggedIn(SubjectRef::user((int) $user->getId()), $user->getId(), method: $method, userAgent: $agent, refId: $ref));

                    return;
                }

                $typed = $method === 'password' ? trim((string) $request->request->get('login')) : '';
                $known = $typed !== '' ? $this->knownLogin($typed) : null;

                $this->events->record(new LoginFailed(
                    $known !== null ? SubjectRef::user((int) $known->getId()) : SubjectRef::unknownUser(),
                    null,
                    name: $typed !== '' ? mb_substr($typed, 0, 120) : null,
                    reason: $reason,
                    userAgent: $agent,
                    refId: $ref,
                ));
            });
        } catch (Throwable $e) {
            // Журнал — не причина не пустити людину.
            $this->logger->warning('crm: вхід не записано в журнал', ['error' => $e->getMessage()]);
        }
    }

    /** Та сама логіка пошуку, що в PasswordLoginAuthenticator: email або хвіст телефону. */
    private function knownLogin(string $typed): ?TelegramUser
    {
        $typed = mb_strtolower($typed);

        if (str_contains($typed, '@')) {
            return $this->users->findOneBy(['email' => $typed]);
        }

        $digits = preg_replace('/\D+/', '', $typed) ?? '';

        return strlen($digits) >= 9 ? $this->users->findOneByPhoneTail(substr($digits, -9)) : null;
    }
}
