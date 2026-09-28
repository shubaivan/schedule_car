<?php

namespace App\Warehouse\Controller;

use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\CategoryScope;
use App\Warehouse\Enum\DocumentType;
use App\Warehouse\Enum\SiteKind;
use App\Warehouse\Exception\WarehouseException;
use App\Warehouse\Repository\WhActivityRepository;
use App\Warehouse\Repository\WhCategoryRepository;
use App\Warehouse\Repository\WhClientRepository;
use App\Warehouse\Repository\WhMovementRepository;
use App\Warehouse\Repository\WhSiteRepository;
use App\Warehouse\Service\DocumentStore;
use App\Warehouse\Service\WarehouseDirectory;
use App\Warehouse\Service\WarehouseLinks;
use App\Warehouse\Service\WarehouseStock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Клієнти й місця (склади та об'єкти).
 *
 * Картка клієнта збирає все про нього в одному місці: його об'єкти, що на
 * них зараз стоїть, рухи й документи — договори, накладні, видаткові.
 */
#[Route('/sklad')]
class ClientController extends AbstractWarehouseController
{
    public function __construct(
        private WhClientRepository $clients,
        private WhSiteRepository $sites,
        private WhMovementRepository $movements,
        private WarehouseDirectory $directory,
        private WarehouseStock $stock,
        private DocumentStore $documents,
        private WhCategoryRepository $categories,
        private WhActivityRepository $journal,
        private WarehouseLinks $links,
    ) {
    }

    #[Route('/clients', name: 'wh_clients', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $error = null;
        $values = null;

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request);
            $values = $this->fields($request);

            try {
                $client = $this->directory->saveClient(new WhClient(), $values, $this->user());
                $this->log(ActivityAction::Create, $client);
                $this->addFlash('ok', sprintf('Клієнта «%s» додано. Тепер додайте його об\'єкти.', $client->getName()));

                return $this->redirectToRoute('wh_client', ['id' => $client->getId()]);
            } catch (WarehouseException $e) {
                $error = $e->getMessage();
            }
        }

        $query = trim((string) $request->query->get('q', ''));

        return $this->render('warehouse/clients.html.twig', [
            'clients' => $this->clients->search($query),
            'q' => $query,
            'clientCategories' => $this->categories->of(CategoryScope::Client),
            'error' => $error,
            'values' => $values,
        ]);
    }

    #[Route('/clients/{id}', name: 'wh_client', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function show(Request $request, WhClient $client): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request);

            try {
                $this->directory->saveClient($client, $this->fields($request), $this->user());
                $this->log(ActivityAction::Update, $client);
                $this->addFlash('ok', 'Картку клієнта збережено.');

                return $this->redirectToRoute('wh_client', ['id' => $client->getId()]);
            } catch (WarehouseException $e) {
                $error = $e->getMessage();
            }
        }

        if ($request->isMethod('GET')) {
            $this->viewed($request, $client);
        }

        $contents = [];

        foreach ($client->getSites() as $site) {
            $contents[(int) $site->getId()] = $this->stock->contentsOf($site);
        }

        return $this->render('warehouse/client.html.twig', [
            'client' => $client,
            'contents' => $contents,
            'movements' => $this->movements->ofClient($client),
            'documents' => $this->documents->of($client),
            'documentTypes' => DocumentType::cases(),
            'clientCategories' => $this->categories->of(CategoryScope::Client),
            'siteCategories' => $this->categories->of(CategoryScope::Site),
            'journal' => $this->journal->of('client', (int) $client->getId()),
            'shareUrl' => $this->links->url($client),
            'error' => $error,
        ]);
    }

    #[Route('/sites', name: 'wh_sites', methods: ['GET', 'POST'])]
    public function sites(Request $request): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request);
            $values = $this->fields($request);
            $client = ctype_digit((string) ($values['clientId'] ?? '')) ? $this->clients->find((int) $values['clientId']) : null;

            try {
                $site = $this->directory->saveSite(new WhSite(), $values, $client, $this->user());
                $this->log(ActivityAction::Create, $site);
                $this->addFlash('ok', sprintf('Місце «%s» додано.', $site->getName()));

                // Об'єкт додавали з картки клієнта — туди й повертаємось.
                return $client !== null
                    ? $this->redirectToRoute('wh_client', ['id' => $client->getId()])
                    : $this->redirectToRoute('wh_site', ['id' => $site->getId()]);
            } catch (WarehouseException $e) {
                $error = $e->getMessage();

                if ($client !== null) {
                    $this->addFlash('error', $error);

                    return $this->redirectToRoute('wh_client', ['id' => $client->getId()]);
                }
            }
        }

        $sites = $this->sites->listed(false);

        return $this->render('warehouse/sites.html.twig', [
            'sites' => $sites,
            'counts' => array_map(fn (WhSite $site) => count($this->stock->contentsOf($site)), $sites),
            'clients' => $this->clients->findBy(['active' => true], ['name' => 'ASC']),
            'kinds' => SiteKind::cases(),
            'siteCategories' => $this->categories->of(CategoryScope::Site),
            'error' => $error,
        ]);
    }

    #[Route('/sites/{id}', name: 'wh_site', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function site(Request $request, WhSite $site): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request);
            $values = $this->fields($request);
            $client = ctype_digit((string) ($values['clientId'] ?? '')) ? $this->clients->find((int) $values['clientId']) : null;

            try {
                $this->directory->saveSite($site, $values, $client, $this->user());
                $this->log(ActivityAction::Update, $site);
                $this->addFlash('ok', 'Збережено.');

                return $this->redirectToRoute('wh_site', ['id' => $site->getId()]);
            } catch (WarehouseException $e) {
                $error = $e->getMessage();
            }
        }

        if ($request->isMethod('GET')) {
            $this->viewed($request, $site);
        }

        return $this->render('warehouse/site.html.twig', [
            'site' => $site,
            'contents' => $this->stock->contentsOf($site),
            'movements' => $this->movements->ofSite($site),
            'documents' => $this->documents->of($site),
            'documentTypes' => DocumentType::cases(),
            'clients' => $this->clients->findBy([], ['name' => 'ASC']),
            'kinds' => SiteKind::cases(),
            'siteCategories' => $this->categories->of(CategoryScope::Site),
            'journal' => $this->journal->of('site', (int) $site->getId()),
            'shareUrl' => $this->links->url($site),
            'error' => $error,
        ]);
    }
}
