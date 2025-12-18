<?php declare(strict_types=1);

namespace ArtissTools\Controller\Api;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class ProductsController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $productRepository
    ) {
    }

    #[Route(
        path: '/api/_action/artiss-tools/products/load-by-ids',
        name: 'api.action.artiss_tools.products.load_by_ids',
        methods: ['POST']
    )]
    public function loadByIds(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $productIds = $data['productIds'] ?? [];

            if (empty($productIds)) {
                return new JsonResponse([
                    'success' => true,
                    'data' => ['products' => []]
                ]);
            }

            // Validate and filter IDs
            $validIds = array_filter($productIds, function ($id) {
                return is_string($id) && Uuid::isValid($id);
            });

            if (empty($validIds)) {
                return new JsonResponse([
                    'success' => true,
                    'data' => ['products' => []]
                ]);
            }

            $criteria = new Criteria($validIds);

            $products = $this->productRepository->search($criteria, $context);

            $result = [];
            foreach ($products as $product) {
                $result[] = [
                    'id' => $product->getId(),
                    'productNumber' => $product->getProductNumber(),
                    'name' => $product->getTranslation('name') ?? $product->getName() ?? $product->getProductNumber()
                ];
            }

            return new JsonResponse([
                'success' => true,
                'data' => ['products' => $result]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

