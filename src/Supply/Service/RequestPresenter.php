<?php

namespace App\Supply\Service;

use App\Entity\TelegramUser;
use App\Supply\Entity\Supplier;
use App\Supply\Entity\SupplyRequest;

/**
 * Перетворення сутностей у масиви для API.
 *
 * Робимо руками, а не серіалізатором з групами: контракт видно в одному файлі,
 * і жодне нове поле сутності не потрапляє у відповідь випадково.
 */
class RequestPresenter
{
    /** Рядок таблиці заявок. */
    public function listItem(SupplyRequest $request): array
    {
        return [
            'id' => $request->getId(),
            'number' => $request->getNumber(),
            'item' => $request->getItem(),
            'quantity' => (float)$request->getQuantity(),
            'unit' => $request->getUnit()->value,
            'unitLabel' => $request->getUnit()->label(),
            'quantityLabel' => $request->getQuantityLabel(),
            'status' => $request->getStatus()->value,
            'statusLabel' => $request->getStatus()->label(),
            'urgent' => $request->isUrgent(),
            'overdue' => $request->isOverdue(),
            'needBy' => $request->getNeedBy()?->format('Y-m-d'),
            'site' => $request->getSite(),
            'department' => $request->getDepartment()?->getName(),
            'departmentId' => $request->getDepartment()?->getId(),
            'author' => $this->user($request->getAuthor()),
            'createdAt' => $request->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /** Картка заявки: усе з рядка + примітка, дозволені переходи та єдина хронологія. */
    public function detail(SupplyRequest $request): array
    {
        return $this->listItem($request) + [
            'note' => $request->getNote(),
            'closedAt' => $request->getClosedAt()?->format(DATE_ATOM),
            'allowedTransitions' => array_map(
                static fn($status) => ['value' => $status->value, 'label' => $status->label()],
                $request->getStatus()->allowedTransitions(),
            ),
            'timeline' => $this->timeline($request),
        ];
    }

    /** Коментарі та зміни статусу одним списком за часом — як у картці бота. */
    public function timeline(SupplyRequest $request): array
    {
        $events = [];

        foreach ($request->getStatusLogs() as $log) {
            $events[] = [
                'type' => 'status',
                'at' => $log->getCreatedAt()->format(DATE_ATOM),
                'author' => $log->getAuthor() ? $this->user($log->getAuthor()) : null,
                'status' => $log->getStatusTo()->value,
                'statusLabel' => $log->getStatusTo()->label(),
                'statusFrom' => $log->getStatusFrom()?->value,
                'text' => $log->getComment(),
            ];
        }

        foreach ($request->getComments() as $comment) {
            $events[] = [
                'type' => 'comment',
                'at' => $comment->getCreatedAt()->format(DATE_ATOM),
                'author' => $comment->getAuthor() ? $this->user($comment->getAuthor()) : null,
                'text' => $comment->getText(),
            ];
        }

        usort($events, static fn(array $a, array $b) => $a['at'] <=> $b['at']);

        return $events;
    }

    public function supplier(Supplier $supplier): array
    {
        return [
            'id' => $supplier->getId(),
            'name' => $supplier->getName(),
            'edrpou' => $supplier->getEdrpou(),
            'phone' => $supplier->getPhone(),
            'contactPerson' => $supplier->getContactPerson(),
            'note' => $supplier->getNote(),
            'active' => $supplier->isActive(),
        ];
    }

    public function user(TelegramUser $user): array
    {
        return [
            'id' => $user->getId(),
            'name' => $user->displayName(),
            'phone' => $user->getPhoneNumber(),
            'role' => $user->getSupplyRole()->value,
            'roleLabel' => $user->getSupplyRole()->label(),
            'accessStatus' => $user->getAccessStatus()->value,
            'accessStatusLabel' => $user->getAccessStatus()->label(),
            'department' => $user->getDepartment()?->getName(),
            'departmentId' => $user->getDepartment()?->getId(),
        ];
    }
}
