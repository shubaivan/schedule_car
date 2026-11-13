<?php

namespace App\Tasks\Service;

use App\Tasks\Entity\TaskAttachment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Офісні превʼю — після відповіді (kernel.terminate іде вже після
 * fastcgi_finish_request): людина не чекає на LibreOffice, а за кілька секунд
 * чат підтягує готову мініатюру. Не встигли (зайнятий конвертер) — доробить
 * крон `tasks:previews`.
 */
class DeferredPreviews
{
    /** @var list<int> */
    private array $queue = [];

    public function __construct(
        private PreviewGenerator $generator,
        private EntityManagerInterface $em,
    ) {
    }

    public function push(TaskAttachment $attachment): void
    {
        $this->queue[] = (int) $attachment->getId();
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        $this->flush();
    }

    public function flush(): void
    {
        $queue = $this->queue;
        $this->queue = [];

        foreach ($queue as $id) {
            $attachment = $this->em->find(TaskAttachment::class, $id);

            if ($attachment === null || $attachment->getPreviewState() !== TaskAttachment::PREVIEW_PENDING) {
                continue;
            }

            if ($this->generator->generate($attachment)) {
                $this->em->flush();
            }
        }
    }
}
