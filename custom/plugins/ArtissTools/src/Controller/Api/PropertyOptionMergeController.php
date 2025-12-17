<?php declare(strict_types=1);

namespace ArtissTools\Controller\Api;

use ArtissTools\Service\PropertyOptionMergeService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class PropertyOptionMergeController extends AbstractController
{
    public function __construct(
        private readonly PropertyOptionMergeService $optionMergeService
    ) {
    }

    #[Route(
        path: '/api/_action/artiss-tools/merge-options/load-group-options',
        name: 'api.action.artiss_tools.merge_options.load_group_options',
        methods: ['POST']
    )]
    public function loadGroupOptions(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $groupId = $data['groupId'] ?? null;

            if (!$groupId || !Uuid::isValid($groupId)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid or missing groupId'
                ], 400);
            }

            $result = $this->optionMergeService->loadGroupOptions($groupId, $context);

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
        path: '/api/_action/artiss-tools/merge-options/scan',
        name: 'api.action.artiss_tools.merge_options.scan',
        methods: ['POST']
    )]
    public function scan(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $groupId = $data['groupId'] ?? null;
            $targetOptionId = $data['targetOptionId'] ?? null;
            $sourceOptionIds = $data['sourceOptionIds'] ?? [];

            if (!$groupId || !Uuid::isValid($groupId)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid or missing groupId'
                ], 400);
            }

            if (!$targetOptionId || !Uuid::isValid($targetOptionId)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid or missing targetOptionId'
                ], 400);
            }

            if (empty($sourceOptionIds)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'sourceOptionIds cannot be empty'
                ], 400);
            }

            foreach ($sourceOptionIds as $sourceOptionId) {
                if (!Uuid::isValid($sourceOptionId)) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Invalid sourceOptionId: ' . $sourceOptionId
                    ], 400);
                }
            }

            if (in_array($targetOptionId, $sourceOptionIds, true)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Target option cannot be in source options list'
                ], 400);
            }

            $result = $this->optionMergeService->scanMerge(
                $groupId,
                $targetOptionId,
                $sourceOptionIds,
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

    #[Route(
        path: '/api/_action/artiss-tools/merge-options/merge',
        name: 'api.action.artiss_tools.merge_options.merge',
        methods: ['POST']
    )]
    public function merge(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $groupId = $data['groupId'] ?? null;
            $targetOptionId = $data['targetOptionId'] ?? null;
            $sourceOptionIds = $data['sourceOptionIds'] ?? [];
            $dryRun = $data['dryRun'] ?? false;

            if ($dryRun) {
                return $this->scan($request, $context);
            }

            if (!$groupId || !Uuid::isValid($groupId)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid or missing groupId'
                ], 400);
            }

            if (!$targetOptionId || !Uuid::isValid($targetOptionId)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid or missing targetOptionId'
                ], 400);
            }

            if (empty($sourceOptionIds)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'sourceOptionIds cannot be empty'
                ], 400);
            }

            foreach ($sourceOptionIds as $sourceOptionId) {
                if (!Uuid::isValid($sourceOptionId)) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Invalid sourceOptionId: ' . $sourceOptionId
                    ], 400);
                }
            }

            if (in_array($targetOptionId, $sourceOptionIds, true)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Target option cannot be in source options list'
                ], 400);
            }

            $result = $this->optionMergeService->mergeOptions(
                $groupId,
                $targetOptionId,
                $sourceOptionIds,
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

