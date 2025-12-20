<?php declare(strict_types=1);

namespace ArtissTools\Controller\Api;

use ArtissTools\Service\ProductMergeService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class ProductMergeController extends AbstractController
{
    public function __construct(
        private readonly ProductMergeService $productMergeService
    ) {
    }

    #[Route(
        path: '/api/_action/artiss-tools/products/merge-preview',
        name: 'api.action.artiss_tools.products.merge_preview',
        methods: ['POST']
    )]
    public function preview(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $mode = $data['mode'] ?? 'new';
            $selectedProductIds = $data['selectedProductIds'] ?? [];
            $targetParentId = $data['targetParentId'] ?? null;
            $newParentName = $data['newParentName'] ?? null;

            if (empty($selectedProductIds)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'No products selected'
                ], 400);
            }

            if ($mode === 'existing' && empty($targetParentId)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Target parent ID is required for existing parent mode'
                ], 400);
            }

            $variantFormingPropertyGroupIds = $data['variantFormingPropertyGroupIds'] ?? [];

            $preview = $this->productMergeService->generatePreview(
                $mode,
                $selectedProductIds,
                $targetParentId,
                $newParentName,
                $variantFormingPropertyGroupIds,
                $context
            );

            return new JsonResponse([
                'success' => true,
                'data' => $preview
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[Route(
        path: '/api/_action/artiss-tools/products/get-variant-forming-properties',
        name: 'api.action.artiss_tools.products.get_variant_forming_properties',
        methods: ['POST']
    )]
    public function getVariantFormingProperties(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $mode = $data['mode'] ?? 'new';
            $selectedProductIds = $data['selectedProductIds'] ?? [];
            $targetParentId = $data['targetParentId'] ?? null;

            if (empty($selectedProductIds)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'No products selected'
                ], 400);
            }

            $properties = $this->productMergeService->getVariantFormingProperties(
                $mode,
                $selectedProductIds,
                $targetParentId,
                $context
            );

            return new JsonResponse([
                'success' => true,
                'data' => $properties
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[Route(
        path: '/api/_action/artiss-tools/products/merge',
        name: 'api.action.artiss_tools.products.merge',
        methods: ['POST']
    )]
    public function merge(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $mode = $data['mode'] ?? 'new';
            $selectedProductIds = $data['selectedProductIds'] ?? [];
            $targetParentId = $data['targetParentId'] ?? null;
            $newParentName = $data['newParentName'] ?? null;
            $variantFormingPropertyGroupIds = $data['variantFormingPropertyGroupIds'] ?? [];

            if (empty($selectedProductIds)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'No products selected'
                ], 400);
            }

            if ($mode === 'existing' && empty($targetParentId)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Target parent ID is required for existing parent mode'
                ], 400);
            }

            $result = $this->productMergeService->merge(
                $mode,
                $selectedProductIds,
                $targetParentId,
                $newParentName,
                $variantFormingPropertyGroupIds,
                $context
            );

            return new JsonResponse([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

