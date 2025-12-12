<?php declare(strict_types=1);

namespace ArtissTools\Controller\Api;

use ArtissTools\Service\PropertyCleanupService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class PropertyCleanupController extends AbstractController
{
    public function __construct(
        private readonly PropertyCleanupService $cleanupService
    ) {
    }

    /**
     * Scan for unused property options and custom fields
     */
    #[Route(
        path: '/api/_action/artiss-tools/cleanup/scan',
        name: 'api.action.artiss_tools.cleanup.scan',
        methods: ['POST']
    )]
    public function scan(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $includeCustomFields = $data['includeCustomFields'] ?? false;

            $result = [
                'unusedPropertyOptions' => [],
                'unusedCustomFields' => [],
            ];

            // Scan property options
            $result['unusedPropertyOptions'] = $this->cleanupService->findUnusedPropertyOptions($context);

            // Scan custom fields if requested
            if ($includeCustomFields) {
                $result['unusedCustomFields'] = $this->cleanupService->findUnusedCustomFields($context);
            }

            // Calculate statistics
            $stats = $this->calculateStatistics($result);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'unusedPropertyOptions' => $result['unusedPropertyOptions'],
                    'unusedCustomFields' => $result['unusedCustomFields'],
                    'stats' => $stats,
                ],
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete selected property options and custom fields
     */
    #[Route(
        path: '/api/_action/artiss-tools/cleanup/delete',
        name: 'api.action.artiss_tools.cleanup.delete',
        methods: ['POST']
    )]
    public function delete(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $propertyOptionIds = $data['propertyOptionIds'] ?? [];
            $customFieldIds = $data['customFieldIds'] ?? [];
            $customFieldSetIds = $data['customFieldSetIds'] ?? [];
            $deleteEmptyGroups = $data['deleteEmptyGroups'] ?? true;

            $result = [
                'deletedPropertyOptions' => [],
                'deletedPropertyGroups' => [],
                'deletedCustomFields' => [],
                'deletedCustomFieldSets' => [],
                'errors' => [],
            ];

            // Delete property options
            if (!empty($propertyOptionIds)) {
                try {
                    $propertyResult = $this->cleanupService->deletePropertyOptions(
                        $propertyOptionIds,
                        $deleteEmptyGroups
                    );

                    $result['deletedPropertyOptions'] = $propertyResult['deletedOptions'];
                    $result['deletedPropertyGroups'] = $propertyResult['deletedGroups'];

                } catch (\Exception $e) {
                    $result['errors'][] = 'Property deletion error: ' . $e->getMessage();
                }
            }

            // Delete custom fields
            if (!empty($customFieldIds) || !empty($customFieldSetIds)) {
                try {
                    $customFieldResult = $this->cleanupService->deleteCustomFields(
                        $customFieldIds,
                        $customFieldSetIds
                    );

                    $result['deletedCustomFields'] = $customFieldResult['deletedFields'];
                    $result['deletedCustomFieldSets'] = $customFieldResult['deletedSets'];

                } catch (\Exception $e) {
                    $result['errors'][] = 'Custom field deletion error: ' . $e->getMessage();
                }
            }

            $success = empty($result['errors']);

            return new JsonResponse([
                'success' => $success,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate statistics from scan results
     */
    private function calculateStatistics(array $result): array
    {
        $stats = [
            'totalUnusedPropertyOptions' => 0,
            'totalPropertyGroups' => 0,
            'totalUnusedCustomFields' => 0,
            'totalCustomFieldSets' => 0,
            'totalEmptySets' => 0,
        ];

        // Count property options
        foreach ($result['unusedPropertyOptions'] as $groupData) {
            $stats['totalPropertyGroups']++;
            $stats['totalUnusedPropertyOptions'] += count($groupData['unusedOptions']);
        }

        // Count custom fields
        foreach ($result['unusedCustomFields'] as $setData) {
            $stats['totalCustomFieldSets']++;
            $stats['totalUnusedCustomFields'] += count($setData['unusedFields']);

            if ($setData['isEmpty'] ?? false) {
                $stats['totalEmptySets']++;
            }
        }

        return $stats;
    }
}
