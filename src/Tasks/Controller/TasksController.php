<?php

namespace App\Tasks\Controller;

use App\Entity\TelegramUser;
use App\Tasks\Exception\TaskException;
use App\Tasks\Service\TasksSection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/** Спільне для API задач: вимикач розділу, поточна людина, помилки. */
abstract class TasksController extends AbstractController
{
    protected TasksSection $section;
    protected EntityManagerInterface $em;

    #[Required]
    public function setUp(TasksSection $section, EntityManagerInterface $em): void
    {
        $this->section = $section;
        $this->em = $em;
    }

    protected function me(): TelegramUser
    {
        if (! $this->section->enabled()) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();

        if (! $user instanceof TelegramUser || $user->isArchived()) {
            throw $this->createAccessDeniedException();
        }

        // «Остання активність» у списку співробітників — не частіше ніж раз на 5 хвилин.
        $seen = $user->getLastSeenAt();

        if ($seen === null || $seen->getTimestamp() < time() - 300) {
            $user->touchLastSeen();
            $this->em->flush();
        }

        return $user;
    }

    /** @return array<string, mixed> */
    protected function payload(Request $request): array
    {
        if (str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            $data = json_decode($request->getContent(), true);

            return is_array($data) ? $data : [];
        }

        return $request->request->all();
    }

    protected function error(TaskException|string $error, int $status = Response::HTTP_UNPROCESSABLE_ENTITY): JsonResponse
    {
        return $this->json(['error' => $error instanceof TaskException ? $error->getMessage() : $error], $status);
    }
}
