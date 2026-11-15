<?php

namespace App\Plan\Login;

use App\Entity\TelegramUser;
use App\Plan\Event\JoinDecided;
use App\Plan\Event\JoinRequested;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Login\Entity\JoinRequest;
use App\Plan\Subject\SubjectRef;
use App\Plan\Telegram\LinkResult;
use App\Plan\Telegram\TelegramLink;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Exception\TaskException;
use App\Tasks\Service\StaffDirectory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SortDirection;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Заявки на приєднання до «Плану» (plan_join_request).
 *
 * Подає бот (PlanLoginBot), коли номера людини немає серед співробітників. Вирішують
 * лише суперадміни — у боті кнопками («Затвердити» = роль «Виробництво», без відділу;
 * змінити — у картці) або в Плані (/crm/admin/join/<id>: роль, відділ, ім'я).
 * Затвердити = завести співробітника (StaffDirectory::create, подія StaffChanged) і
 * одразу прив'язати його Telegram — далі людина просто входить через Telegram.
 *
 * Обмеження на один Telegram: одна заявка на розгляді; не більше MAX_PER_DAY за добу;
 * після відмови — нова не раніше ніж за добу.
 */
final class JoinRequests
{
    public const int MAX_PER_DAY = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanEventRecorder $events,
        private readonly StaffDirectory $staff,
        private readonly TelegramLink $link,
    ) {
    }

    /**
     * @param array{id: string, chatId: ?string, firstName: ?string, lastName: ?string, username: ?string} $from
     *
     * @return array{JoinSubmit, ?JoinRequest}
     */
    public function submit(array $from, string $phone, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $digits = (string) preg_replace('/\D+/', '', $phone);
        $repo = $this->em->getRepository(JoinRequest::class);

        $pending = $repo->findOneBy(['telegramId' => $from['id'], 'status' => JoinRequestStatus::Pending]);
        if ($pending instanceof JoinRequest) {
            return [JoinSubmit::AlreadyPending, $pending];
        }

        $since = $now->modify('-1 day')->format('Y-m-d H:i:s');
        $db = $this->em->getConnection();
        $recent = (int) $db->fetchOne('SELECT COUNT(*) FROM plan_join_request WHERE telegram_id = ? AND created_at > ?', [$from['id'], $since]);
        $rejected = (int) $db->fetchOne("SELECT COUNT(*) FROM plan_join_request WHERE telegram_id = ? AND status = 'rejected' AND decided_at > ?", [$from['id'], $since]);

        if ($recent >= self::MAX_PER_DAY || $rejected > 0) {
            return [JoinSubmit::Limited, null];
        }

        $request = new JoinRequest($from['id'], $from['chatId'], $from['firstName'], $from['lastName'], $from['username'], $digits, $now);

        $this->events->transactional(function () use ($request): void {
            $this->em->persist($request);
            $this->em->flush();
            $this->events->record(new JoinRequested(
                SubjectRef::unknownUser(),
                null,
                requestId: (int) $request->getId(),
                name: $request->name(),
                phone: self::maskPhone($request->getPhone()),
                username: $request->getUsername(),
            ));
        });

        return [JoinSubmit::Created, $request];
    }

    public function find(int $id): ?JoinRequest
    {
        return $this->em->find(JoinRequest::class, $id);
    }

    /** @return list<JoinRequest> спершу ті, що чекають; далі вирішені — нові зверху */
    public function list(?JoinRequestStatus $status = null, int $limit = 200): array
    {
        $qb = $this->em->getRepository(JoinRequest::class)->createQueryBuilder('j')
            ->addOrderBy("CASE WHEN j.status = 'pending' THEN 0 ELSE 1 END")
            ->addOrderBy('j.id', SortDirection::Descending)
            ->setMaxResults($limit);

        if ($status !== null) {
            $qb->andWhere('j.status = :status')->setParameter('status', $status);
        }

        /** @var list<JoinRequest> */
        return $qb->getQuery()->getResult();
    }

    public function createdSince(DateTimeImmutable $since): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM plan_join_request WHERE created_at > ?', [$since->format('Y-m-d H:i:s')]);
    }

    public function pendingCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM plan_join_request WHERE status = 'pending'");
    }

    /**
     * @param array{role?: string, departmentId?: int, firstName?: string, lastName?: string, position?: string} $data
     */
    public function approve(JoinRequest $request, TelegramUser $by, array $data = [], ?DateTimeImmutable $now = null): TelegramUser
    {
        self::guard($by);
        $now ??= new DateTimeImmutable();
        $role = WorkRole::tryFrom((string) ($data['role'] ?? WorkRole::Production->value)) ?? throw new TaskException('Невідома роль.');

        return $this->events->transactional(function () use ($request, $by, $data, $role, $now): TelegramUser {
            $this->claim($request, $now);

            $card = [
                'firstName' => trim((string) ($data['firstName'] ?? '')) !== '' ? (string) $data['firstName'] : ($request->getFirstName() ?? $request->name()),
                'lastName' => array_key_exists('lastName', $data) ? (string) $data['lastName'] : (string) $request->getLastName(),
                'phone' => $request->getPhone(),
                'role' => $role->value,
            ];
            if ((int) ($data['departmentId'] ?? 0) > 0) {
                $card['departmentId'] = (int) $data['departmentId'];
            }
            if (trim((string) ($data['position'] ?? '')) !== '') {
                $card['position'] = (string) $data['position'];
            }

            $user = $this->staff->create($card, $by);

            if ($this->link->attach($user, $request->getTelegramId(), $request->getChatId()) === LinkResult::Taken) {
                throw new TaskException('Цей Telegram уже прив\'язано до іншого співробітника.');
            }

            $request->approve($user, $by, $now);
            $this->em->flush();
            $this->events->record(new JoinDecided(SubjectRef::user((int) $user->getId()), $by->getId(), requestId: (int) $request->getId(), decision: 'approved', name: $user->shortName(), role: $role->value));

            return $user;
        });
    }

    public function reject(JoinRequest $request, TelegramUser $by, ?string $reason = null, ?DateTimeImmutable $now = null): void
    {
        self::guard($by);
        $now ??= new DateTimeImmutable();

        $this->events->transactional(function () use ($request, $by, $reason, $now): void {
            $this->claim($request, $now);
            $request->reject($by, $reason, $now);
            $this->em->flush();
            $this->events->record(new JoinDecided(SubjectRef::unknownUser(), $by->getId(), requestId: (int) $request->getId(), decision: 'rejected', name: $request->name(), reason: $request->getReason()));
        });
    }

    /** +380 •• ••• •6 66 — для журналу й повідомлень, де повний номер не потрібен. */
    public static function maskPhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        return strlen($digits) < 4 ? '•••' : '+' . str_repeat('•', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    /** +380 63 302 26 66 — для суперадміна, який вирішує заявку. */
    public static function formatPhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '38' . $digits;
        }

        return strlen($digits) === 12
            ? sprintf('+%s %s %s %s %s', substr($digits, 0, 3), substr($digits, 3, 2), substr($digits, 5, 3), substr($digits, 8, 2), substr($digits, 10, 2))
            : '+' . $digits;
    }

    private static function guard(TelegramUser $by): void
    {
        if (! $by->isSuperadmin() || $by->isArchived() || ! $by->isApproved()) {
            throw new AccessDeniedException('Заявки на приєднання розглядає суперадмін.');
        }
    }

    /** Атомарно «забрати» заявку на рішення: двоє суперадмінів одночасно — вирішить один. */
    private function claim(JoinRequest $request, DateTimeImmutable $now): void
    {
        $taken = $this->em->getConnection()->executeStatement(
            "UPDATE plan_join_request SET decided_at = ? WHERE id = ? AND status = 'pending' AND decided_at IS NULL",
            [$now->format('Y-m-d H:i:s'), (int) $request->getId()],
        );

        if ($taken !== 1) {
            $this->em->refresh($request);

            throw new TaskException(sprintf('Заявку вже розглянуто: %s.', mb_strtolower($request->getStatus()->label())));
        }
    }
}
