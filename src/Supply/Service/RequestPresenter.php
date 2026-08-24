<?php

namespace App\Supply\Service;

use App\Entity\TelegramUser;
use App\Supply\Entity\Supplier;
use App\Supply\Entity\SupplyAttachment;
use App\Supply\Entity\SupplyPurchase;
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
            'quantity' => (float) $request->getQuantity(),
            'unit' => $request->getUnit()->value,
            'unitLabel' => $request->getUnit()->label(),
            'quantityLabel' => $request->getQuantityLabel(),
            'status' => $request->getStatus()->value,
            'statusLabel' => $request->getStatus()->label(),
            'urgent' => $request->isUrgent(),
            'accent' => $request->getAccent()->value,
            'accentLabel' => $request->getAccent()->label(),
            'accentEmoji' => $request->getAccent()->emoji(),
            'overdue' => $request->isOverdue(),
            'needBy' => $request->getNeedBy()?->format('Y-m-d'),
            'site' => $request->getSite(),
            'department' => $request->getDepartment()?->getName(),
            'departmentId' => $request->getDepartment()?->getId(),
            'author' => $this->user($request->getAuthor()),
            'createdAt' => $request->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * Картка заявки: усе з рядка + примітка, дозволені переходи та єдина хронологія.
     *
     * Переходи фільтруємо під того, хто дивиться: у CRM кнопки малюються прямо з
     * allowedTransitions, тож директор не має бачити кнопок менеджера, і навпаки.
     * Без глядача (демо, тести) віддаємо повний набір статусів.
     */
    public function detail(SupplyRequest $request, ?TelegramUser $viewer = null): array
    {
        return $this->listItem($request) + [
            'note' => $request->getNote(),
            'closedAt' => $request->getClosedAt()?->format(DATE_ATOM),
            'purchases' => array_map($this->purchase(...), $request->getPurchases()->toArray()),
            'purchaseTotal' => (float) $request->getPurchaseTotal(),
            'attachments' => array_map($this->attachment(...), $request->getAttachments()->toArray()),
            'allowedTransitions' => array_map(
                static fn ($status) => ['value' => $status->value, 'label' => $status->label()],
                $this->transitionsFor($request, $viewer),
            ),
            'timeline' => $this->timeline($request),
        ];
    }

    /**
     * Переходи, доступні саме цьому користувачу.
     *
     * @return \App\Supply\Enum\SupplyStatus[]
     */
    private function transitionsFor(SupplyRequest $request, ?TelegramUser $viewer): array
    {
        $status = $request->getStatus();

        if ($viewer === null) {
            return $status->allowedTransitions();
        }

        $role = $viewer->getSupplyRole();

        return array_values(array_filter(
            $status->allowedTransitions(),
            static fn ($next) => $role->canMoveRequest($status, $next),
        ));
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

        usort($events, static fn (array $a, array $b) => $a['at'] <=> $b['at']);

        return $events;
    }

    public function purchase(SupplyPurchase $purchase): array
    {
        return [
            'id' => $purchase->getId(),
            'supplier' => $this->supplier($purchase->getSupplier()),
            'quantity' => $purchase->getQuantity() !== null ? (float) $purchase->getQuantity() : null,
            'pricePerUnit' => $purchase->getPricePerUnit() !== null ? (float) $purchase->getPricePerUnit() : null,
            'totalAmount' => (float) $purchase->getTotalAmount(),
            'totalLabel' => $purchase->getTotalLabel(),
            'currency' => $purchase->getCurrency(),
            'vatIncluded' => $purchase->isVatIncluded(),
            'invoiceNumber' => $purchase->getInvoiceNumber(),
            'purchasedAt' => $purchase->getPurchasedAt()?->format('Y-m-d'),
            'payment' => $purchase->getPayment()->value,
            'paymentLabel' => $purchase->getPayment()->label(),
        ];
    }

    public function attachment(SupplyAttachment $attachment): array
    {
        return [
            'id' => $attachment->getId(),
            'type' => $attachment->getType()->value,
            'typeLabel' => $attachment->getType()->label(),
            'name' => $attachment->getOriginalName(),
            'size' => $attachment->getSize(),
            'sizeLabel' => $attachment->getSizeLabel(),
            'mime' => $attachment->getMime(),
            'uploadedBy' => $attachment->getUploadedBy()?->displayName(),
            'uploadedAt' => $attachment->getCreatedAt()->format(DATE_ATOM),
            // null означає «ще не поїхав у Drive», а не «помилка».
            'driveUrl' => $attachment->getDriveUrl(),
        ];
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
            // Ім'я та прізвище окремо — картку людини правлять по полях.
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'phone' => $user->getPhoneNumber(),
            'role' => $user->getSupplyRole()->value,
            'roleLabel' => $user->getSupplyRole()->label(),
            'accessStatus' => $user->getAccessStatus()->value,
            'accessStatusLabel' => $user->getAccessStatus()->label(),
            'department' => $user->getDepartment()?->getName(),
            'departmentId' => $user->getDepartment()?->getId(),
            'archived' => $user->isArchived(),
        ];
    }
}
