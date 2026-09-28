<?php

namespace App\Warehouse\Controller;

use App\Warehouse\Entity\WhCategory;
use App\Warehouse\Enum\ActivityAction;
use App\Warehouse\Enum\CategoryScope;
use App\Warehouse\Exception\WarehouseException;
use App\Warehouse\Repository\WhCategoryRepository;
use App\Warehouse\Service\WarehouseDirectory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Довідники категорій: види майна, клієнтів і об'єктів.
 *
 * Категорії не видаляємо — прибрана зникає зі списків вибору, а позиції й
 * клієнти, що вже в ній, лишаються як є.
 */
#[Route('/sklad/categories')]
class CategoryController extends AbstractWarehouseController
{
    public function __construct(
        private WhCategoryRepository $categories,
        private WarehouseDirectory $directory,
    ) {
    }

    #[Route('', name: 'wh_categories', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request);
            $values = $this->fields($request);
            $category = ctype_digit((string) ($values['id'] ?? '')) ? $this->categories->find((int) $values['id']) : null;

            try {
                $saved = $this->directory->saveCategory($category ?? new WhCategory(), $values, $this->user());
                $this->log($category === null ? ActivityAction::Create : ActivityAction::Update, $saved);
                $this->addFlash('ok', sprintf('Збережено: %s.', $saved->getLabel()));

                return $this->redirectToRoute('wh_categories');
            } catch (WarehouseException $e) {
                $error = $e->getMessage();
            }
        }

        $groups = [];

        foreach (CategoryScope::cases() as $scope) {
            $groups[] = ['scope' => $scope, 'categories' => $this->categories->of($scope, false)];
        }

        return $this->render('warehouse/categories.html.twig', ['groups' => $groups, 'error' => $error]);
    }
}
