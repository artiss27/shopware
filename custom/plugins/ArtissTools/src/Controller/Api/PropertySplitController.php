<?php declare(strict_types=1);

namespace ArtissTools\Controller\Api;

use ArtissTools\Service\PropertySplitService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class PropertySplitController extends AbstractController
{
    public function __construct(
        private readonly PropertySplitService $propertySplitService
    ) {
    }

    #[Route(
        path: '/api/_action/artiss-tools/split/load-group',
        name: 'api.action.artiss_tools.split.load_group',
        methods: ['POST']
    )]
    public function loadGroup(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $groupId = $data['groupId'] ?? null;

            if (!$groupId) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing required parameter: groupId',
                ], 400);
            }

            $result = $this->propertySplitService->loadPropertyGroupWithOptions($groupId, $context);

            return new JsonResponse([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route(
        path: '/api/_action/artiss-tools/split/preview',
        name: 'api.action.artiss_tools.split.preview',
        methods: ['POST']
    )]
    public function preview(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $sourceGroupId = $data['sourceGroupId'] ?? null;
            $targetGroupId = $data['targetGroupId'] ?? null;
            $optionIds = $data['optionIds'] ?? [];

            if (!$sourceGroupId || !$targetGroupId || empty($optionIds)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing required parameters: sourceGroupId, targetGroupId, optionIds',
                ], 400);
            }

            $result = $this->propertySplitService->previewSplit(
                $sourceGroupId,
                $targetGroupId,
                $optionIds,
                $context
            );

            return new JsonResponse([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route(
        path: '/api/_action/artiss-tools/split/execute',
        name: 'api.action.artiss_tools.split.execute',
        methods: ['POST']
    )]
    public function execute(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $sourceGroupId = $data['sourceGroupId'] ?? null;
            $targetGroupId = $data['targetGroupId'] ?? null;
            $optionIds = $data['optionIds'] ?? [];

            if (!$sourceGroupId || !$targetGroupId || empty($optionIds)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing required parameters: sourceGroupId, targetGroupId, optionIds',
                ], 400);
            }

            $result = $this->propertySplitService->executeSplit(
                $sourceGroupId,
                $targetGroupId,
                $optionIds,
                $context
            );

            return new JsonResponse([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
