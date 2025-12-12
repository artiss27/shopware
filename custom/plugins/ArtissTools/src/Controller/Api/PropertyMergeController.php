<?php declare(strict_types=1);

namespace ArtissTools\Controller\Api;

use ArtissTools\Service\PropertyGroupMergeService;
use ArtissTools\Service\CustomFieldSetMergeService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class PropertyMergeController extends AbstractController
{
    public function __construct(
        private readonly PropertyGroupMergeService $propertyGroupMergeService,
        private readonly CustomFieldSetMergeService $customFieldSetMergeService
    ) {
    }

    #[Route(
        path: '/api/_action/artiss-tools/property-groups',
        name: 'api.action.artiss_tools.property_groups.list',
        methods: ['GET']
    )]
    public function listPropertyGroups(Context $context): JsonResponse
    {
        try {
            $groups = $this->propertyGroupMergeService->getAllPropertyGroups($context);

            return new JsonResponse([
                'success' => true,
                'data' => $groups,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route(
        path: '/api/_action/artiss-tools/custom-field-sets',
        name: 'api.action.artiss_tools.custom_field_sets.list',
        methods: ['GET']
    )]
    public function listCustomFieldSets(Context $context): JsonResponse
    {
        try {
            $sets = $this->customFieldSetMergeService->getAllCustomFieldSets($context);

            return new JsonResponse([
                'success' => true,
                'data' => $sets,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route(
        path: '/api/_action/artiss-tools/merge',
        name: 'api.action.artiss_tools.merge',
        methods: ['POST']
    )]
    public function merge(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $entityType = $data['entityType'] ?? null;
            $targetId = $data['targetId'] ?? null;
            $sourceIds = $data['sourceIds'] ?? [];
            $dryRun = $data['dryRun'] ?? true;

            if (!$entityType || !$targetId || empty($sourceIds)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing required parameters: entityType, targetId, sourceIds',
                ], 400);
            }

            if ($entityType === 'property_group') {
                $result = $this->mergePropertyGroups($targetId, $sourceIds, $dryRun, $context);
            } elseif ($entityType === 'custom_field_set') {
                $result = $this->mergeCustomFieldSets($targetId, $sourceIds, $dryRun, $context);
            } else {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid entityType. Must be "property_group" or "custom_field_set"',
                ], 400);
            }

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
        path: '/api/_action/artiss-tools/custom-field-set/{id}/fields',
        name: 'api.action.artiss_tools.custom_field_set.fields',
        methods: ['GET']
    )]
    public function listCustomFieldSetFields(string $id, Context $context): JsonResponse
    {
        try {
            $fields = $this->customFieldSetMergeService->loadSetFields($id, $context);

            return new JsonResponse([
                'success' => true,
                'data' => $fields,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function mergePropertyGroups(
        string $targetId,
        array $sourceIds,
        bool $dryRun,
        Context $context
    ): array {
        // Find target group
        $targetGroup = $this->propertyGroupMergeService->findPropertyGroupById($targetId, $context);

        if (!$targetGroup) {
            throw new \RuntimeException(sprintf('Target property group "%s" not found', $targetId));
        }

        // Find source groups
        $sourceGroups = [];
        foreach ($sourceIds as $sourceId) {
            $sourceGroup = $this->propertyGroupMergeService->findPropertyGroupById($sourceId, $context);

            if (!$sourceGroup) {
                throw new \RuntimeException(sprintf('Source property group "%s" not found', $sourceId));
            }

            if ($sourceGroup->getId() === $targetGroup->getId()) {
                throw new \RuntimeException('Source group cannot be the same as target group');
            }

            $sourceGroups[] = $sourceGroup;
        }

        // Load target options
        $targetOptions = $this->propertyGroupMergeService->loadGroupOptions($targetGroup->getId(), $context);

        // Prepare merge plan
        $plan = $this->propertyGroupMergeService->prepareMergePlan(
            $targetGroup,
            $targetOptions,
            $sourceGroups,
            $context
        );

        // Execute if not dry-run
        if (!$dryRun) {
            $this->propertyGroupMergeService->executeMerge($plan);
        }

        return [
            'dryRun' => $dryRun,
            'target' => $plan['target'],
            'targetOptionsCount' => $plan['targetOptionsCount'],
            'sources' => $plan['sources'],
            'stats' => $plan['stats'],
        ];
    }

    private function mergeCustomFieldSets(
        string $targetId,
        array $sourceIds,
        bool $dryRun,
        Context $context
    ): array {
        // Find target set
        $targetSet = $this->customFieldSetMergeService->findCustomFieldSetById($targetId, $context);

        if (!$targetSet) {
            throw new \RuntimeException(sprintf('Target custom field set "%s" not found', $targetId));
        }

        // Find source sets
        $sourceSets = [];
        foreach ($sourceIds as $sourceId) {
            $sourceSet = $this->customFieldSetMergeService->findCustomFieldSetById($sourceId, $context);

            if (!$sourceSet) {
                throw new \RuntimeException(sprintf('Source custom field set "%s" not found', $sourceId));
            }

            if ($sourceSet->getId() === $targetSet->getId()) {
                throw new \RuntimeException('Source set cannot be the same as target set');
            }

            $sourceSets[] = $sourceSet;
        }

        // Load target fields
        $targetFields = $this->customFieldSetMergeService->loadSetFieldsAsEntities($targetSet->getId(), $context);

        // Prepare merge plan
        $plan = $this->customFieldSetMergeService->prepareMergePlan(
            $targetSet,
            $targetFields,
            $sourceSets,
            $context
        );

        // Execute if not dry-run
        if (!$dryRun) {
            $this->customFieldSetMergeService->executeMerge($plan);
        }

        return [
            'dryRun' => $dryRun,
            'target' => $plan['target'],
            'targetFieldsCount' => $plan['targetFieldsCount'],
            'sources' => $plan['sources'],
            'stats' => $plan['stats'],
        ];
    }
}
