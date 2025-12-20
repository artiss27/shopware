<?php declare(strict_types=1);

namespace ArtissTools\Controller\Api;

use ArtissTools\Service\ImagesCleanupService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class ImagesCleanupController extends AbstractController
{
    public function __construct(
        private readonly ImagesCleanupService $imagesCleanupService
    ) {
    }

    #[Route(
        path: '/api/_action/artiss-tools/images/calculate-media-size',
        name: 'api.action.artiss_tools.images.calculate_media_size',
        methods: ['GET']
    )]
    public function calculateMediaSize(Request $request, Context $context): JsonResponse
    {
        try {
            $result = $this->imagesCleanupService->calculateMediaSize($context);

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

    #[Route(
        path: '/api/_action/artiss-tools/images/run-cleanup',
        name: 'api.action.artiss_tools.images.run_cleanup',
        methods: ['POST']
    )]
    public function runCleanup(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $result = $this->imagesCleanupService->runCleanupCommand($data, $context);

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

