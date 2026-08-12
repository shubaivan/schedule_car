<?php

namespace App\Supply\Service;

use App\Entity\TelegramUser;
use App\Supply\Entity\SupplyComment;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Exception\SupplyException;
use Doctrine\ORM\EntityManagerInterface;

/** Коментар у стрічці заявки + сповіщення другій стороні. */
class AddComment
{
    public function __construct(
        private EntityManagerInterface $em,
        private SupplyNotifier $notifier,
    ) {
    }

    public function __invoke(SupplyRequest $request, TelegramUser $author, string $text): SupplyComment
    {
        $text = trim($text);

        if ($text === '') {
            throw new SupplyException('Коментар порожній.');
        }

        if (!$this->canComment($request, $author)) {
            throw new SupplyException('Коментувати заявку можуть лише її автор і менеджер із постачання.');
        }

        $comment = (new SupplyComment())
            ->setAuthor($author)
            ->setText($text);

        $request->addComment($comment);

        $this->em->persist($comment);
        $this->em->flush();

        $this->notifier->commentAdded($comment);

        return $comment;
    }

    private function canComment(SupplyRequest $request, TelegramUser $author): bool
    {
        return $author->getSupplyRole()->canManage()
            || $request->getAuthor()->getId() === $author->getId();
    }
}
