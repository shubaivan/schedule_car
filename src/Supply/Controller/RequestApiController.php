<?php

namespace App\Supply\Controller;

use App\Entity\TelegramUser;
use App\Supply\Dto\PurchaseInput;
use App\Supply\Entity\SupplyPurchase;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\PaymentType;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\SupplierRepository;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\AddComment;
use App\Supply\Service\ChangeStatus;
use App\Supply\Service\RecordPurchase;
use App\Supply\Service\RequestPresenter;
use DateTime;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** API стола заявок менеджера. Уся логіка — у сервісах, тут лише вхід/вихід. */
#[Route('/api/supply/requests')]
class RequestApiController extends AbstractController
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private SupplyRequestRepository $repository,
        private RequestPresenter $presenter,
    ) {
    }

    #[Route('', name: 'api_supply_requests', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $statuses = array_filter(array_map(
            static fn (string $value) => SupplyStatus::tryFrom($value),
            array_filter((array) $request->query->all('status')),
        ));

        $filters = [
            'status' => $statuses,
            'department' => $request->query->getInt('department') ?: null,
            'author' => $request->query->getInt('author') ?: null,
            'urgent' => $request->query->getBoolean('urgent') ?: null,
            'overdue' => $request->query->getBoolean('overdue') ?: null,
            'open' => $request->query->getBoolean('open') ?: null,
            'query' => $request->query->get('q') ?: null,
        ];

        $page = max(1, $request->query->getInt('page', 1));
        $result = $this->repository->search($filters, $page, self::PAGE_SIZE);

        return $this->json([
            'items' => array_map($this->presenter->listItem(...), $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            // Лічильники рахуємо без фільтра статусу, щоб вкладки не «худнули» самі під себе.
            'counts' => $this->repository->countByStatus(
                array_merge($filters, ['status' => [], 'open' => null]),
            ),
        ]);
    }

    #[Route('/{id}', name: 'api_supply_request', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function view(SupplyRequest $supplyRequest): JsonResponse
    {
        return $this->json($this->presenter->detail($supplyRequest));
    }

    #[Route('/{id}/status', name: 'api_supply_request_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function changeStatus(
        SupplyRequest $supplyRequest,
        Request $request,
        ChangeStatus $changeStatus,
    ): JsonResponse {
        $payload = $this->payload($request);
        $status = SupplyStatus::tryFrom((string) ($payload['to'] ?? ''));

        if ($status === null) {
            return $this->error('Невідомий статус.');
        }

        try {
            $changeStatus($supplyRequest, $status, $this->manager(), $payload['comment'] ?? null);
        } catch (SupplyException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json($this->presenter->detail($supplyRequest));
    }

    #[Route('/{id}/comments', name: 'api_supply_request_comment', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function comment(
        SupplyRequest $supplyRequest,
        Request $request,
        AddComment $addComment,
    ): JsonResponse {
        $payload = $this->payload($request);

        try {
            $addComment($supplyRequest, $this->manager(), (string) ($payload['text'] ?? ''));
        } catch (SupplyException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json($this->presenter->detail($supplyRequest));
    }

    #[Route('/{id}/purchases', name: 'api_supply_request_purchase', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addPurchase(
        SupplyRequest $supplyRequest,
        Request $request,
        RecordPurchase $recordPurchase,
        SupplierRepository $suppliers,
    ): JsonResponse {
        try {
            $input = $this->purchaseInput($this->payload($request), $suppliers);
            $recordPurchase($supplyRequest, $this->manager(), $input);
        } catch (SupplyException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json($this->presenter->detail($supplyRequest));
    }

    #[Route(
        '/{id}/purchases/{purchaseId}',
        name: 'api_supply_request_purchase_update',
        methods: ['PATCH'],
        requirements: ['id' => '\d+', 'purchaseId' => '\d+'],
    )]
    public function updatePurchase(
        SupplyRequest $supplyRequest,
        int $purchaseId,
        Request $request,
        RecordPurchase $recordPurchase,
        SupplierRepository $suppliers,
    ): JsonResponse {
        $purchase = $this->purchaseOf($supplyRequest, $purchaseId);

        if ($purchase === null) {
            return $this->error('Закупівлю не знайдено.');
        }

        try {
            $payload = $this->payload($request);
            // Постачальника можна не передавати — тоді лишається той самий.
            $payload['supplierId'] ??= $purchase->getSupplier()->getId();

            $recordPurchase->update($purchase, $this->manager(), $this->purchaseInput($payload, $suppliers));
        } catch (SupplyException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json($this->presenter->detail($supplyRequest));
    }

    #[Route(
        '/{id}/purchases/{purchaseId}',
        name: 'api_supply_request_purchase_delete',
        methods: ['DELETE'],
        requirements: ['id' => '\d+', 'purchaseId' => '\d+'],
    )]
    public function deletePurchase(
        SupplyRequest $supplyRequest,
        int $purchaseId,
        RecordPurchase $recordPurchase,
    ): JsonResponse {
        $purchase = $this->purchaseOf($supplyRequest, $purchaseId);

        if ($purchase === null) {
            return $this->error('Закупівлю не знайдено.');
        }

        try {
            $recordPurchase->remove($purchase, $this->manager());
        } catch (SupplyException $e) {
            return $this->error($e->getMessage());
        }

        return $this->json($this->presenter->detail($supplyRequest));
    }

    /** Шукаємо серед закупівель саме цієї заявки — чужу за id не підсунути. */
    private function purchaseOf(SupplyRequest $request, int $purchaseId): ?SupplyPurchase
    {
        foreach ($request->getPurchases() as $purchase) {
            if ($purchase->getId() === $purchaseId) {
                return $purchase;
            }
        }

        return null;
    }

    private function purchaseInput(array $payload, SupplierRepository $suppliers): PurchaseInput
    {
        $supplier = $suppliers->find((int) ($payload['supplierId'] ?? 0));

        if ($supplier === null) {
            throw new SupplyException('Оберіть постачальника зі списку.');
        }

        $payment = PaymentType::tryFrom((string) ($payload['payment'] ?? PaymentType::Bank->value));

        if ($payment === null) {
            throw new SupplyException('Невідомий спосіб оплати.');
        }

        $purchasedAt = null;

        if (! empty($payload['purchasedAt'])) {
            $purchasedAt = DateTime::createFromFormat(
                'Y-m-d H:i:s',
                $payload['purchasedAt'] . ' 00:00:00',
                new DateTimeZone('Europe/Kyiv'),
            ) ?: null;

            if ($purchasedAt === null) {
                throw new SupplyException('Дата закупівлі має бути у форматі РРРР-ММ-ДД.');
            }
        }

        return new PurchaseInput(
            supplier: $supplier,
            totalAmount: $this->text($payload, 'totalAmount'),
            quantity: $this->text($payload, 'quantity'),
            pricePerUnit: $this->text($payload, 'pricePerUnit'),
            payment: $payment,
            vatIncluded: (bool) ($payload['vatIncluded'] ?? true),
            invoiceNumber: $this->text($payload, 'invoiceNumber'),
            purchasedAt: $purchasedAt,
        );
    }

    private function text(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function manager(): TelegramUser
    {
        /** @var TelegramUser $user */
        $user = $this->getUser();

        return $user;
    }

    private function payload(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);

        return is_array($data) ? $data : [];
    }

    private function error(string $message): JsonResponse
    {
        return $this->json(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
