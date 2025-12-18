<?php declare(strict_types=1);

namespace ArtissTools\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * PropertySplitService
 *
 * Service for splitting property groups.
 * Allows splitting one property_group into two by moving selected options to a new group.
 *
 * Features:
 * - Load property group with all options
 * - Create new property group with translations
 * - Move selected options to new group
 * - Preserve all product/variant relationships
 * - Support dry-run mode for preview
 * - Count affected products/variants
 */
class PropertySplitService
{
    private Connection $connection;
    private EntityRepository $propertyGroupRepository;
    private EntityRepository $propertyGroupOptionRepository;

    public function __construct(
        Connection $connection,
        EntityRepository $propertyGroupRepository,
        EntityRepository $propertyGroupOptionRepository
    ) {
        $this->connection = $connection;
        $this->propertyGroupRepository = $propertyGroupRepository;
        $this->propertyGroupOptionRepository = $propertyGroupOptionRepository;
    }

    /**
     * Load property group with all its options
     */
    public function loadPropertyGroupWithOptions(string $groupId, Context $context): ?array
    {
        $criteria = new Criteria([$groupId]);
        $criteria->addAssociation('options');
        $criteria->addAssociation('translations');

        $result = $this->propertyGroupRepository->search($criteria, $context);
        $group = $result->first();

        if (!$group) {
            return null;
        }

        $options = [];
        if ($group->getOptions()) {
            foreach ($group->getOptions() as $option) {
                $productCount = $this->countAffectedProducts($option->getId());

                $options[] = [
                    'id' => $option->getId(),
                    'name' => $option->getName(),
                    'position' => $option->getPosition(),
                    'productCount' => $productCount['total']
                ];
            }
        }

        return [
            'id' => $group->getId(),
            'name' => $group->getName(),
            'displayType' => $group->getDisplayType(),
            'sortingType' => $group->getSortingType(),
            'description' => $group->getDescription(),
            'position' => $group->getPosition(),
            'options' => $options
        ];
    }

    /**
     * Preview split operation (dry-run)
     */
    public function previewSplit(string $sourceGroupId, string $targetGroupId, array $optionIds, Context $context): array
    {
        // Validate source group exists
        $sourceGroup = $this->loadPropertyGroupWithOptions($sourceGroupId, $context);
        if (!$sourceGroup) {
            throw new \InvalidArgumentException('Source property group not found');
        }

        // Validate target group exists
        $targetGroup = $this->loadPropertyGroupWithOptions($targetGroupId, $context);
        if (!$targetGroup) {
            throw new \InvalidArgumentException('Target property group not found');
        }

        // Validate source and target are different
        if ($sourceGroupId === $targetGroupId) {
            throw new \InvalidArgumentException('Source and target groups must be different');
        }

        // Validate all options belong to source group
        $this->validateOptionsBelongToGroup($optionIds, $sourceGroupId);

        // Validate at least one option selected
        if (empty($optionIds)) {
            throw new \InvalidArgumentException('No options selected for transfer');
        }

        // Count affected products/variants
        $affectedStats = $this->calculateAffectedEntities($optionIds);

        // Filter selected options
        $selectedOptions = array_filter($sourceGroup['options'], function ($option) use ($optionIds) {
            return in_array($option['id'], $optionIds);
        });

        return [
            'sourceGroup' => [
                'id' => $sourceGroup['id'],
                'name' => $sourceGroup['name'],
                'totalOptions' => count($sourceGroup['options']),
                'remainingOptions' => count($sourceGroup['options']) - count($optionIds)
            ],
            'targetGroup' => [
                'id' => $targetGroup['id'],
                'name' => $targetGroup['name'],
                'currentOptions' => count($targetGroup['options']),
                'optionsToMove' => count($optionIds),
                'totalAfterMove' => count($targetGroup['options']) + count($optionIds)
            ],
            'selectedOptions' => array_values($selectedOptions),
            'affectedEntities' => $affectedStats
        ];
    }

    /**
     * Execute split operation
     */
    public function executeSplit(string $sourceGroupId, string $targetGroupId, array $optionIds, Context $context): array
    {
        // First run preview to validate
        $preview = $this->previewSplit($sourceGroupId, $targetGroupId, $optionIds, $context);

        $this->connection->beginTransaction();

        try {
            // Move options to target group
            $movedCount = $this->moveOptionsToGroup($optionIds, $targetGroupId);

            $this->connection->commit();

            return [
                'success' => true,
                'movedOptions' => $movedCount,
                'affectedEntities' => $preview['affectedEntities'],
                'sourceGroup' => $preview['sourceGroup'],
                'targetGroup' => $preview['targetGroup']
            ];

        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Validate that all options belong to the specified group
     */
    private function validateOptionsBelongToGroup(array $optionIds, string $groupId): void
    {
        $qb = $this->connection->createQueryBuilder();
        $invalidOptions = $qb
            ->select('id')
            ->from('property_group_option')
            ->where('id IN (:optionIds)')
            ->andWhere('property_group_id != :groupId')
            ->setParameter('optionIds', Uuid::fromHexToBytesList($optionIds), ArrayParameterType::STRING)
            ->setParameter('groupId', Uuid::fromHexToBytes($groupId))
            ->executeQuery()
            ->fetchAllAssociative();

        if (!empty($invalidOptions)) {
            throw new \InvalidArgumentException('Some options do not belong to the source group');
        }
    }

    /**
     * Calculate affected products and variants
     */
    private function calculateAffectedEntities(array $optionIds): array
    {
        $totalProducts = 0;
        $totalVariants = 0;
        $allProductIds = [];

        foreach ($optionIds as $optionId) {
            $counts = $this->countAffectedProducts($optionId);
            $totalProducts += $counts['products'];
            $totalVariants += $counts['variants'];
            $allProductIds = array_merge($allProductIds, $counts['productIds']);
        }

        $allProductIds = array_unique($allProductIds);

        return [
            'totalProducts' => count($allProductIds),
            'totalVariants' => $totalVariants,
            'total' => count($allProductIds) + $totalVariants,
            'productIds' => array_values($allProductIds)
        ];
    }

    /**
     * Count affected products/variants for a single option
     */
    private function countAffectedProducts(string $optionId): array
    {
        // Get product IDs from product_property
        $qb = $this->connection->createQueryBuilder();
        $productIds = $qb
            ->select('DISTINCT LOWER(HEX(product_id)) as product_id')
            ->from('product_property')
            ->where('property_group_option_id = :optionId')
            ->setParameter('optionId', Uuid::fromHexToBytes($optionId))
            ->executeQuery()
            ->fetchAllAssociative();

        $productIdList = array_column($productIds, 'product_id');

        // Count in product_configurator_setting (variants)
        $qb = $this->connection->createQueryBuilder();
        $variantCount = (int) $qb
            ->select('COUNT(DISTINCT id)')
            ->from('product_configurator_setting')
            ->where('property_group_option_id = :optionId')
            ->setParameter('optionId', Uuid::fromHexToBytes($optionId))
            ->executeQuery()
            ->fetchOne();

        return [
            'products' => count($productIdList),
            'variants' => $variantCount,
            'total' => count($productIdList) + $variantCount,
            'productIds' => $productIdList
        ];
    }

    /**
     * Move options to target group
     */
    private function moveOptionsToGroup(array $optionIds, string $targetGroupId): int
    {
        $qb = $this->connection->createQueryBuilder();
        $affected = $qb
            ->update('property_group_option')
            ->set('property_group_id', ':targetGroupId')
            ->where('id IN (:optionIds)')
            ->setParameter('targetGroupId', Uuid::fromHexToBytes($targetGroupId))
            ->setParameter('optionIds', Uuid::fromHexToBytesList($optionIds), ArrayParameterType::STRING)
            ->executeStatement();

        return $affected;
    }
}
