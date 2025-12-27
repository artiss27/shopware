<?php declare(strict_types=1);

namespace Artiss\Supplier\Core\Api;

use Artiss\Supplier\Service\Parser\ParserRegistry;
use Artiss\Supplier\Service\PriceUpdate\PriceUpdateService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class PriceUpdateController extends AbstractController
{
    public function __construct(
        private readonly PriceUpdateService $priceUpdateService,
        private readonly ParserRegistry $parserRegistry,
        private readonly EntityRepository $mediaRepository
    ) {
    }

    /**
     * Get file preview for column mapping
     */
    #[Route(
        path: '/api/_action/supplier/price-update/preview-file',
        name: 'api.supplier.price_update.preview_file',
        methods: ['POST']
    )]
    public function previewFile(Request $request, Context $context): JsonResponse
    {
        $mediaId = $request->request->get('mediaId');
        $previewRows = (int) ($request->request->get('previewRows') ?? 5);

        if (!$mediaId) {
            return new JsonResponse(['error' => 'mediaId is required'], 400);
        }

        try {
            $media = $this->mediaRepository->search(new Criteria([$mediaId]), $context)->first();

            if ($media === null) {
                return new JsonResponse(['error' => 'Media not found'], 404);
            }

            $preview = $this->parserRegistry->preview($media, $previewRows);

            return new JsonResponse($preview);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Parse and normalize price list
     */
    #[Route(
        path: '/api/_action/supplier/price-update/parse',
        name: 'api.supplier.price_update.parse',
        methods: ['POST']
    )]
    public function parse(Request $request, Context $context): JsonResponse
    {
        $templateId = $request->request->get('templateId');
        $mediaId = $request->request->get('mediaId');
        $forceRefresh = (bool) ($request->request->get('forceRefresh') ?? false);

        if (!$templateId || !$mediaId) {
            return new JsonResponse(['error' => 'templateId and mediaId are required'], 400);
        }

        try {
            $normalizedData = $this->priceUpdateService->parseAndNormalize(
                $templateId,
                $mediaId,
                $forceRefresh,
                $context
            );

            return new JsonResponse([
                'success' => true,
                'data' => $normalizedData,
                'count' => count($normalizedData),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Match products and get preview
     */
    #[Route(
        path: '/api/_action/supplier/price-update/match-preview',
        name: 'api.supplier.price_update.match_preview',
        methods: ['POST']
    )]
    public function matchPreview(Request $request, Context $context): JsonResponse
    {
        $templateId = $request->request->get('templateId');

        if (!$templateId) {
            return new JsonResponse(['error' => 'templateId is required'], 400);
        }

        try {
            $preview = $this->priceUpdateService->matchProductsPreview($templateId, $context);

            return new JsonResponse($preview);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update matched product manually
     */
    #[Route(
        path: '/api/_action/supplier/price-update/update-match',
        name: 'api.supplier.price_update.update_match',
        methods: ['POST']
    )]
    public function updateMatch(Request $request, Context $context): JsonResponse
    {
        $templateId = $request->request->get('templateId');
        $productId = $request->request->get('productId');
        $supplierCode = $request->request->get('supplierCode');

        if (!$templateId || !$productId || !$supplierCode) {
            return new JsonResponse(['error' => 'templateId, productId and supplierCode are required'], 400);
        }

        try {
            $this->priceUpdateService->updateMatchedProduct(
                $templateId,
                $productId,
                $supplierCode,
                $context
            );

            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Auto-match products by name similarity
     */
    #[Route(
        path: '/api/_action/supplier/price-update/auto-match',
        name: 'api.supplier.price_update.auto_match',
        methods: ['POST']
    )]
    public function autoMatch(Request $request, Context $context): JsonResponse
    {
        $templateId = $request->request->get('templateId');
        $batchSize = (int) ($request->request->get('batchSize') ?? 50);
        $offset = (int) ($request->request->get('offset') ?? 0);

        if (!$templateId) {
            return new JsonResponse(['error' => 'templateId is required'], 400);
        }

        try {
            $result = $this->priceUpdateService->autoMatchProducts(
                $templateId,
                $context,
                $batchSize,
                $offset
            );

            return new JsonResponse([
                'success' => true,
                'matched' => $result['matched'],
                'stats' => $result['stats'],
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Confirm all pending matches
     */
    #[Route(
        path: '/api/_action/supplier/price-update/confirm-matches',
        name: 'api.supplier.price_update.confirm_matches',
        methods: ['POST']
    )]
    public function confirmMatches(Request $request, Context $context): JsonResponse
    {
        $templateId = $request->request->get('templateId');
        $matchesToConfirm = $request->request->all('matches') ?? [];

        if (!$templateId) {
            return new JsonResponse(['error' => 'templateId is required'], 400);
        }

        try {
            $result = $this->priceUpdateService->confirmAllMatches(
                $templateId,
                $matchesToConfirm,
                $context
            );

            return new JsonResponse([
                'success' => true,
                'confirmed' => $result['confirmed'],
                'total_mappings' => $result['total_mappings'],
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Apply prices to products
     */
    #[Route(
        path: '/api/_action/supplier/price-update/apply',
        name: 'api.supplier.price_update.apply',
        methods: ['POST']
    )]
    public function apply(Request $request, Context $context): JsonResponse
    {
        $templateId = $request->request->get('templateId');
        $confirmedMatches = $request->request->all('confirmedMatches') ?? [];
        $userId = $context->getSource()->getUserId() ?? null;

        if (!$templateId) {
            return new JsonResponse(['error' => 'templateId is required'], 400);
        }

        try {
            // If no confirmedMatches provided, get all matched from preview
            if (empty($confirmedMatches)) {
                $preview = $this->priceUpdateService->matchProductsPreview($templateId, $context);
                $confirmedMatches = [];

                foreach ($preview['matched'] as $match) {
                    // Include all matched items (status === 'matched' and has product_id)
                    if ($match['status'] === 'matched' && $match['product_id']) {
                        $confirmedMatches[] = [
                            'product_id' => $match['product_id'],
                            'supplier_code' => $match['supplier_code'],
                            'new_prices' => $match['new_prices'],
                            'availability' => $match['availability'] ?? null,
                            'is_confirmed' => true, // Mark all as confirmed to save mapping
                        ];
                    }
                }
            }

            if (empty($confirmedMatches)) {
                return new JsonResponse(['error' => 'No matched products to apply'], 400);
            }

            $stats = $this->priceUpdateService->applyPrices(
                $templateId,
                $confirmedMatches,
                $userId ?? 'unknown',
                $context
            );

            return new JsonResponse([
                'success' => true,
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Recalculate prices from custom fields using current exchange rates
     */
    #[Route(
        path: '/api/_action/supplier/price-update/recalculate',
        name: 'api.supplier.price_update.recalculate',
        methods: ['POST']
    )]
    public function recalculatePrices(Request $request, Context $context): JsonResponse
    {
        $priceType = $request->request->get('priceType', 'retail');
        $limit = $request->request->get('limit');

        try {
            $stats = $this->priceUpdateService->recalculatePricesFromCustomFields(
                $priceType,
                $limit ? (int) $limit : null,
                $context
            );

            return new JsonResponse([
                'success' => true,
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get supported file types
     */
    #[Route(
        path: '/api/_action/supplier/price-update/supported-types',
        name: 'api.supplier.price_update.supported_types',
        methods: ['GET']
    )]
    public function getSupportedTypes(): JsonResponse
    {
        return new JsonResponse([
            'extensions' => $this->parserRegistry->getSupportedExtensions(),
            'parsers' => $this->parserRegistry->getParserInfo(),
        ]);
    }
}
