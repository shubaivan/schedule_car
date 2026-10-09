<?php

namespace App\Warehouse\Controller;

use App\Supply\Repository\SupplierRepository;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\DocumentType;
use App\Warehouse\Enum\MovementType;
use App\Warehouse\Enum\SiteKind;
use App\Warehouse\Exception\WarehouseException;
use App\Warehouse\Repository\WhActivityRepository;
use App\Warehouse\Repository\WhClientRepository;
use App\Warehouse\Repository\WhItemRepository;
use App\Warehouse\Repository\WhMovementRepository;
use App\Warehouse\Repository\WhSiteRepository;
use App\Warehouse\Service\DocumentStore;
use App\Warehouse\Service\RecordMovement;
use App\Warehouse\Service\WarehouseDirectory;
use App\Warehouse\Service\WarehouseFeed;
use App\Warehouse\Service\WarehouseLinks;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Рухи: надходження, відвантаження, повернення, переміщення, списання.
 *
 * Накладну прикріплюють у тій самій формі, що й записують рух: окремим кроком
 * «потім додам» її забувають, а без неї рух через місяць нічим не підтвердиш.
 */
#[Route('/sklad/movements')]
class MovementController extends AbstractWarehouseController
{
    public function __construct(
        private WhMovementRepository $movements,
        private WhItemRepository $items,
        private WhSiteRepository $sites,
        private RecordMovement $record,
        private DocumentStore $documents,
        private EntityManagerInterface $em,
        private WhActivityRepository $journal,
        private WarehouseLinks $links,
        private WarehouseDirectory $directory,
        private WhClientRepository $clients,
        private SupplierRepository $suppliers,
        private WarehouseFeed $feed,
    ) {
    }

    #[Route('', name: 'wh_movements', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('warehouse/movements.html.twig', [
            'movements' => $this->movements->latest(200),
        ]);
    }

    #[Route('/new', name: 'wh_movement_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $type = MovementType::tryFrom((string) $request->get('type', '')) ?? MovementType::Shipment;
        $error = null;
        $values = $request->isMethod('POST') ? $this->fields($request) : null;

        if ($values !== null) {
            $this->checkCsrf($request);

            // Усе або нічого: рух без своєї накладної не записуємо, якщо файл не прийнявся.
            $this->em->beginTransaction();

            try {
                $movement = ($this->record)(
                    $type,
                    $this->date((string) ($values['occurredAt'] ?? '')),
                    $this->site($values['fromId'] ?? null),
                    $this->target($type, $values),
                    $this->lines($values),
                    $this->user(),
                    $values['documentNumber'] ?? null,
                    $type->needsFrom() ? null : $this->directory->supplier($values['supplierId'] ?? null, $this->user()),
                    $values['note'] ?? null,
                );

                $documentType = DocumentType::tryFrom((string) ($values['documentType'] ?? '')) ?? DocumentType::Waybill;

                foreach ($request->files->all('files') as $file) {
                    if ($file instanceof UploadedFile) {
                        $this->attach($movement, $file, $documentType);
                    }
                }

                $this->em->commit();
            } catch (WarehouseException $e) {
                $this->em->rollback();
                $this->em->clear();
                $error = $e->getMessage();
            }

            if ($error === null) {
                // Рух — у журнал самого руху і кожної позиції: картка щита має
                // показувати, хто й куди його відправив, без переходу в рух.
                $route = sprintf('%s → %s', $movement->getFromSite()?->getName() ?? 'ззовні', $movement->getToSite()?->getName() ?? 'списано');
                $this->log(ActivityAction::Move, $movement, $route);

                foreach ($movement->getLines() as $line) {
                    $this->log(ActivityAction::Move, $line->getItem(), sprintf('%s: %s, %d %s', $movement->getTitle(), $route, $line->getQuantity(), $line->getItem()->getUnit()));
                }

                $this->feed->movementRecorded($movement, $this->user());
                $this->addFlash('ok', sprintf('Записано: %s.', $movement->getTitle()));

                return $this->redirectToRoute('wh_movement', ['id' => $movement->getId()]);
            }
        }

        $preset = $request->query->getInt('item');

        return $this->render('warehouse/movement_form.html.twig', [
            'type' => $type,
            'types' => MovementType::cases(),
            'sites' => $this->sites->listed(),
            'items' => $this->items->movable(),
            'values' => $values,
            'preset' => $preset ?: null,
            'presetFrom' => $request->query->getInt('from') ?: null,
            'presetTo' => $request->query->getInt('to') ?: null,
            'documentTypes' => DocumentType::cases(),
            'error' => $error,
            'clients' => $this->clients->findBy(['active' => true], ['name' => 'ASC']),
            'supplierChoice' => $this->choice($values['supplierId'] ?? null, fn (int $id) => $this->suppliers->find($id)?->getName()),
            'toClientChoice' => $this->choice($values['toClientId'] ?? null, fn (int $id) => $this->clients->find($id)?->getName()),
            'today' => (new DateTime('today'))->format('Y-m-d'),
        ]);
    }

    #[Route('/{id}', name: 'wh_movement', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Request $request, WhMovement $movement): Response
    {
        $this->viewed($request, $movement);

        return $this->render('warehouse/movement.html.twig', [
            'movement' => $movement,
            'journal' => $this->journal->of('movement', (int) $movement->getId()),
            'shareUrl' => $this->links->url($movement),
            'documents' => $this->documents->of($movement),
            'documentTypes' => DocumentType::cases(),
        ]);
    }

    private function attach(WhMovement $movement, UploadedFile $file, DocumentType $type): void
    {
        if (! $file->isValid()) {
            throw new WarehouseException('Файл не долетів: ' . $file->getErrorMessage());
        }

        $this->documents->attach(
            $movement,
            $this->user(),
            (string) file_get_contents($file->getPathname()),
            (string) ($file->getClientOriginalName() ?: $file->getFilename()),
            (string) ($file->getMimeType() ?: $file->getClientMimeType()),
            $type,
        );
    }

    /**
     * Рядки форми: item[], quantity[], rate[] — паралельні масиви.
     *
     * @param array<string, mixed> $values
     *
     * @return list<array{item: \App\Warehouse\Entity\WhItem, quantity: int, rate: ?string}>
     */
    private function lines(array $values): array
    {
        $ids = (array) ($values['item'] ?? []);
        $quantities = (array) ($values['quantity'] ?? []);
        $rates = (array) ($values['rate'] ?? []);
        $lines = [];

        foreach ($ids as $index => $id) {
            if (! ctype_digit((string) $id)) {
                continue;
            }

            $item = $this->items->find((int) $id);

            if ($item === null) {
                throw new WarehouseException('Позицію не знайдено — оновіть сторінку.');
            }

            $quantity = trim((string) ($quantities[$index] ?? '1'));

            if (! ctype_digit($quantity)) {
                throw new WarehouseException(sprintf('Кількість для %s — ціле число.', $item->getLabel()));
            }

            $lines[] = ['item' => $item, 'quantity' => (int) $quantity, 'rate' => $rates[$index] ?? null];
        }

        return $lines;
    }

    /**
     * «Куди»: наявне місце або новий об'єкт, дописаний прямо у формі. Новий
     * об'єкт — того роду, якого вимагає рух (переміщення — об'єкт клієнта).
     *
     * @param array<string, mixed> $values
     */
    private function target(MovementType $type, array $values): ?WhSite
    {
        $value = $values['toId'] ?? null;

        if (! str_starts_with((string) $value, WarehouseDirectory::NEW)) {
            return $this->site($value);
        }

        $kind = $type->toKind() ?? SiteKind::Site;
        $client = $kind === SiteKind::Site ? $this->directory->client($values['toClientId'] ?? null, $this->user()) : null;

        return $this->directory->site($value, $kind, $client, $this->user());
    }

    /**
     * Значення select2 для повторного показу форми після помилки: те, що
     * обрали або дописали, — щоб не набирати вдруге.
     *
     * @param callable(int): ?string $name
     *
     * @return array{id: string, text: string}|null
     */
    private function choice(mixed $value, callable $name): ?array
    {
        $value = trim((string) $value);

        if (str_starts_with($value, WarehouseDirectory::NEW)) {
            return ['id' => $value, 'text' => substr($value, strlen(WarehouseDirectory::NEW))];
        }

        $text = ctype_digit($value) ? $name((int) $value) : null;

        return $text !== null ? ['id' => $value, 'text' => $text] : null;
    }

    private function site(mixed $id): ?WhSite
    {
        return ctype_digit((string) $id) ? $this->sites->find((int) $id) : null;
    }

    private function date(string $value): DateTime
    {
        $date = DateTime::createFromFormat('!Y-m-d', trim($value));

        if ($date === false) {
            throw new WarehouseException('Вкажіть дату руху.');
        }

        return $date;
    }
}
