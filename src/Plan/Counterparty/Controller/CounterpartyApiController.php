<?php

namespace App\Plan\Counterparty\Controller;

use App\Entity\TelegramUser;
use App\Plan\Access\PlanAccess;
use App\Plan\Access\PlanCapability;
use App\Plan\Counterparty\Entity\Counterparty;
use App\Plan\Counterparty\Enum\CounterpartyKind;
use App\Plan\Counterparty\Enum\LegalForm;
use App\Plan\Counterparty\Enum\PaymentTerms;
use App\Plan\Counterparty\Enum\VatStatus;
use App\Plan\Counterparty\Repository\CounterpartyRepository;
use App\Plan\Counterparty\Service\CounterpartyException;
use App\Plan\Counterparty\Service\CounterpartyFilter;
use App\Plan\Counterparty\Service\CounterpartyPresenter;
use App\Plan\Counterparty\Service\CounterpartyService;
use App\Repository\TelegramUserRepository;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Довідник «Контрагенти» для «Плану» (/api/plan/counterparties). На стендах
 * заявок (TASKS_ENABLED=0) увесь /api/plan — 404 (PlanSectionGate).
 *
 * Бачать і правлять: продажі, бухгалтерія, постачання, керівництво
 * (PLAN_COUNTERPARTIES_VIEW / _EDIT); гроші й архів — PLAN_COUNTERPARTIES_FINANCE.
 * Записи без заголовка X-Requested-With не приймаються (як склад — замість CSRF).
 */
#[Route('/api/plan/counterparties')]
#[IsGranted('PLAN_COUNTERPARTIES_VIEW')]
final class CounterpartyApiController extends AbstractController
{
    public function __construct(
        private readonly CounterpartyRepository $repository,
        private readonly CounterpartyService $service,
        private readonly CounterpartyPresenter $presenter,
        private readonly PlanAccess $access,
    ) {
    }

    /** Довідники форми й фасетів: види, форми, ПДВ, умови оплати, менеджери, права. */
    #[Route('/meta', name: 'api_plan_counterparties_meta', methods: ['GET'])]
    public function meta(TelegramUserRepository $users): JsonResponse
    {
        $options = static fn (array $cases): array => array_map(static fn ($c) => ['value' => $c->value, 'label' => $c->label()], $cases);
        $managers = array_values(array_filter($users->findPeople(), fn (TelegramUser $u) => $this->access->can($u, PlanCapability::CounterpartiesView)));

        return $this->json([
            'kinds' => $options(CounterpartyKind::cases()),
            'legalForms' => array_map(static fn (LegalForm $f) => ['value' => $f->value, 'label' => $f->label(), 'codeLabel' => $f->codeLabel()], LegalForm::cases()),
            'vatStatuses' => $options(VatStatus::cases()),
            'paymentTerms' => $options(PaymentTerms::cases()),
            'managers' => array_map($this->presenter->person(...), $managers),
            'can' => [
                'edit' => $this->access->can($this->me(), PlanCapability::CounterpartiesEdit),
                'finance' => $this->access->can($this->me(), PlanCapability::CounterpartiesFinance),
            ],
            'me' => $this->me()->getId(),
        ]);
    }

    #[Route('', name: 'api_plan_counterparties', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filter = CounterpartyFilter::fromQuery($request->query, $this->me()->getId());
        $items = $this->repository->search($filter);

        return $this->json([
            'items' => array_map($this->presenter->row(...), $items),
            'counts' => $this->repository->counts($filter),
            'limit' => CounterpartyRepository::PAGE,
        ]);
    }

    #[Route('/{id}', name: 'api_plan_counterparty', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Counterparty $counterparty): JsonResponse
    {
        return $this->json($this->presenter->card($counterparty, $this->me()));
    }

    #[Route('', name: 'api_plan_counterparty_create', methods: ['POST'])]
    #[IsGranted('PLAN_COUNTERPARTIES_EDIT')]
    public function create(Request $request): JsonResponse
    {
        return $this->write($request, fn (array $data) => $this->service->create($this->me(), $data), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_plan_counterparty_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('PLAN_COUNTERPARTIES_EDIT')]
    public function update(Counterparty $counterparty, Request $request): JsonResponse
    {
        return $this->write($request, fn (array $data) => $this->service->update($counterparty, $this->me(), $data));
    }

    #[Route('/{id}/archive', name: 'api_plan_counterparty_archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archive(Counterparty $counterparty, Request $request): JsonResponse
    {
        return $this->write($request, fn () => $this->service->archive($counterparty, $this->me()));
    }

    #[Route('/{id}/restore', name: 'api_plan_counterparty_restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function restore(Counterparty $counterparty, Request $request): JsonResponse
    {
        return $this->write($request, fn () => $this->service->restore($counterparty, $this->me()));
    }

    /** @param callable(array<string, mixed>): Counterparty $work */
    private function write(Request $request, callable $work, int $status = Response::HTTP_OK): JsonResponse
    {
        // HTML-форма з чужого сайту цього заголовка поставити не може (як WarehouseGate).
        if ($request->headers->get('X-Requested-With') !== 'XMLHttpRequest') {
            return $this->json(['error' => 'Запит не з «Плану» — оновіть сторінку.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $data = $request->getContent() === '' ? [] : json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->json(['error' => 'Не вдалося прочитати дані форми.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $counterparty = $work(is_array($data) ? $data : []);
        } catch (CounterpartyException $e) {
            return $this->json(
                ['error' => $e->getMessage(), 'field' => $e->field, 'existingId' => $e->existingId],
                match (true) {
                    $e->forbidden => Response::HTTP_FORBIDDEN,
                    $e->existingId !== null => Response::HTTP_CONFLICT,
                    default => Response::HTTP_UNPROCESSABLE_ENTITY,
                },
            );
        }

        return $this->json($this->presenter->card($counterparty, $this->me()), $status);
    }

    private function me(): TelegramUser
    {
        $user = $this->getUser();

        if (! $user instanceof TelegramUser) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }
}
