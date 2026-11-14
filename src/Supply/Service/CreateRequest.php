<?php

namespace App\Supply\Service;

use App\Entity\TelegramUser;
use App\Plan\Event\SubjectCreated;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Supply\Dto\CreateRequestInput;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Entity\SupplyStatusLog;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\SupplyRequestRepository;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Створення заявки. Викликається і з бота, і з CRM — уся логіка тут,
 * інтерфейси лише збирають CreateRequestInput.
 */
class CreateRequest
{
    /** Довільний сталий ключ advisory-локу на видачу номера заявки. */
    private const NUMBER_LOCK_KEY = 848_201_001;

    public function __construct(
        private EntityManagerInterface $em,
        private SupplyRequestRepository $repository,
        private PlanEventRecorder $events,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TelegramUser $author, CreateRequestInput $input): SupplyRequest
    {
        $item = trim($input->item);
        if ($item === '') {
            throw new SupplyException('Не вказано, що саме потрібно.');
        }

        if (! is_numeric($input->quantity) || (float) $input->quantity <= 0) {
            throw new SupplyException('Кількість має бути числом більшим за нуль.');
        }

        $request = (new SupplyRequest())
            ->setAuthor($author)
            ->setDepartment($input->department ?? $author->getDepartment())
            ->setItem($item)
            ->setQuantity((string) $input->quantity)
            ->setUnit($input->unit)
            ->setSite($input->site !== null ? trim($input->site) : null)
            ->setNeedBy($input->needBy)
            ->setUrgent($input->urgent)
            ->setNote($input->note !== null ? trim($input->note) : null)
            ->setStatus(SupplyStatus::New);

        $log = (new SupplyStatusLog())
            ->setStatusFrom(null)
            ->setStatusTo(SupplyStatus::New)
            ->setAuthor($author);

        $request->addStatusLog($log);

        $this->em->persist($request);
        $this->em->persist($log);

        $this->saveWithNumber($request, $log);

        $this->logger->info('supply: створено заявку', [
            'number' => $request->getNumber(),
            'item' => $request->getItem(),
            'author' => $author->displayName(),
        ]);

        // Сповіщення заявнику й менеджерам — SupplyNotificationListener на подію
        // SubjectCreated, лише після коміту (відкат — жодного хибного повідомлення).

        return $request;
    }

    /**
     * Номер видається читанням максимуму, тож двоє одночасних заявників могли б
     * узяти один і той самий. Серіалізуємо видачу advisory-локом на час транзакції:
     * ловити унікальний індекс і повторювати не можна — після помилки Doctrine
     * закриває EntityManager.
     */
    private function saveWithNumber(SupplyRequest $request, SupplyStatusLog $log): void
    {
        $year = (int) (new DateTime('now', new DateTimeZone('Europe/Kyiv')))->format('Y');

        $this->events->transactional(function () use ($request, $log, $year): void {
            $this->em->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(?)',
                [self::NUMBER_LOCK_KEY],
            );

            $request->setNumber($this->repository->nextNumber($year));
            $this->em->flush();

            $this->events->record(new SubjectCreated(
                SubjectRef::supply((int) $request->getId()),
                $request->getAuthor()->getId(),
                title: sprintf('№ %s · %s', $request->getNumber(), $request->getItem()),
                refId: $log->getId(),
            ));
        });
    }
}
