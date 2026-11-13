<?php

namespace App\Tasks\Service;

use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Звʼязка співробітника з CRM і його Telegram.
 *
 * Адмін заводить людину в «Співробітниках» (службовий telegram_id «staff-…»),
 * людина пізніше заходить у бота й ділиться номером — бот створює свій запис.
 * Щоб не було двох «Сергіїв Гончарів», переносимо все з картки CRM у запис
 * бота (задачі, повідомлення, файли, роль, відділ, пароль), а картку CRM
 * прибираємо. Запис бота лишається головним: на нього вже тримається сесія бота.
 */
class StaffLinker
{
    public const PLACEHOLDER = 'staff-';

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /** @return bool true — знайшли картку CRM і злили її в цей запис */
    public function link(TelegramUser $user): bool
    {
        $tail = substr((string) preg_replace('/\D+/', '', (string) $user->getPhoneNumber()), -9);

        if (strlen($tail) < 9 || str_starts_with((string) $user->getTelegramId(), self::PLACEHOLDER)) {
            return false;
        }

        /** @var TelegramUser|null $card */
        $card = $this->em->createQueryBuilder()
            ->select('u')->from(TelegramUser::class, 'u')
            ->andWhere('u.telegram_id LIKE :placeholder')
            ->andWhere('u.phone_number LIKE :tail')
            ->andWhere('u.archivedAt IS NULL')
            ->andWhere('u.id <> :me')
            ->setParameter('placeholder', self::PLACEHOLDER . '%')
            ->setParameter('tail', '%' . $tail)
            ->setParameter('me', $user->getId())
            ->orderBy('u.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        if ($card === null) {
            return false;
        }

        $this->em->flush();
        $conn = $this->em->getConnection();
        $from = (int) $card->getId();
        $to = (int) $user->getId();

        $conn->transactional(static function ($conn) use ($from, $to): void {
            $conn->executeStatement('UPDATE task SET creator_id = :to WHERE creator_id = :from', compact('from', 'to'));
            $conn->executeStatement('DELETE FROM task_member WHERE user_id = :from AND task_id IN (SELECT task_id FROM task_member WHERE user_id = :to)', compact('from', 'to'));
            $conn->executeStatement('UPDATE task_member SET user_id = :to WHERE user_id = :from', compact('from', 'to'));
            $conn->executeStatement('DELETE FROM task_read WHERE user_id = :from AND task_id IN (SELECT task_id FROM task_read WHERE user_id = :to)', compact('from', 'to'));
            $conn->executeStatement('UPDATE task_read SET user_id = :to WHERE user_id = :from', compact('from', 'to'));
            $conn->executeStatement('UPDATE task_message SET author_id = :to WHERE author_id = :from', compact('from', 'to'));
            $conn->executeStatement('UPDATE task_attachment SET uploaded_by_id = :to WHERE uploaded_by_id = :from', compact('from', 'to'));
            $conn->executeStatement('UPDATE telegram_user SET archived_by_id = :to WHERE archived_by_id = :from', compact('from', 'to'));
            $conn->executeStatement('UPDATE telegram_user SET access_decided_by_id = :to WHERE access_decided_by_id = :from', compact('from', 'to'));
            $conn->executeStatement('DELETE FROM login_token WHERE user_id = :from', ['from' => $from]);
        });

        // Те, що вписав адмін, важливіше за імʼя з профілю Telegram.
        $email = $card->getEmail();
        $card->setEmail(null);
        $this->em->flush();

        $user->setFirstName($card->getFirstName() ?? $user->getFirstName())
            ->setLastName($card->getLastName() ?? $user->getLastName())
            ->setPosition($card->getPosition())
            ->setWorkRole($card->getWorkRole())
            ->setDepartment($card->getDepartment() ?? $user->getDepartment())
            ->setEmail($email)
            ->setPassword($card->getPassword());
        $user->decideAccess(AccessStatus::Approved, $card->getAccessDecidedBy());

        $this->em->remove($card);
        $this->em->flush();

        $this->logger->info('staff: картку CRM привʼязано до Telegram', ['card' => $from, 'user' => $to]);

        return true;
    }
}
