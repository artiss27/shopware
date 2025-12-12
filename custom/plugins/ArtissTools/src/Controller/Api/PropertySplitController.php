<?php declare(strict_types=1);

namespace Artiss\ArtissTools\Controller\Api;

use Artiss\ArtissTools\Service\PropertySplitService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class PropertySplitController extends AbstractController
{
    private PropertySplitService $splitService;

    public function __construct(PropertySplitService $splitService)
    {
        $this->splitService = $splitService;
    }

    /**
     * Load property group with all options
     */
    #[Route(path: '/api/_action/artiss-tools/split/load-group', name: 'api.action.artiss-tools.split.load-group', methods: ['POST'])]
    public function loadGroup(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $groupId = $data['groupId'] ?? null;

            if (!$groupId) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Group ID is required'
                ], 400);
            }

            $result = $this->splitService->loadPropertyGroupWithOptions($groupId, $context);

            if (!$result) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Property group not found'
                ], 404);
            }

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

    /**
     * Preview split operation (dry-run)
     */
    #[Route(path: '/api/_action/artiss-tools/split/preview', name: 'api.action.artiss-tools.split.preview', methods: ['POST'])]
    public function preview(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $sourceGroupId = $data['sourceGroupId'] ?? null;
            $optionIds = $data['optionIds'] ?? [];
            $newGroupNames = $data['newGroupNames'] ?? [];

            if (!$sourceGroupId) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Source group ID is required'
                ], 400);
            }

            $result = $this->splitService->previewSplit(
                $sourceGroupId,
                $optionIds,
                $newGroupNames,
                $context
            );

            return new JsonResponse([
                'success' => true,
                'data' => $result
            ]);

        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Execute split operation
     */
    #[Route(path: '/api/_action/artiss-tools/split/execute', name: 'api.action.artiss-tools.split.execute', methods: ['POST'])]
    public function execute(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $sourceGroupId = $data['sourceGroupId'] ?? null;
            $optionIds = $data['optionIds'] ?? [];
            $newGroupNames = $data['newGroupNames'] ?? [];

            if (!$sourceGroupId) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Source group ID is required'
                ], 400);
            }

            $result = $this->splitService->executeSplit(
                $sourceGroupId,
                $optionIds,
                $newGroupNames,
                $context
            );

            return new JsonResponse([
                'success' => true,
                'data' => $result
            ]);

        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
