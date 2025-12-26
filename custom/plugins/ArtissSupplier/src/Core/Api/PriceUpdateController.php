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
        $confirmedMatches = $request->request->all('confirmedMatches');
        $userId = $context->getSource()->getUserId() ?? 'unknown';

        if (!$templateId || empty($confirmedMatches)) {
            return new JsonResponse(['error' => 'templateId and confirmedMatches are required'], 400);
        }

        try {
            $stats = $this->priceUpdateService->applyPrices(
                $templateId,
                $confirmedMatches,
                $userId,
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
