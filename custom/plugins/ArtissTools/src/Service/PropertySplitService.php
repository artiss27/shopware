<?php declare(strict_types=1);

namespace Artiss\ArtissTools\Service;

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
    public function previewSplit(string $sourceGroupId, array $optionIds, array $newGroupNames, Context $context): array
    {
        // Validate source group exists
        $sourceGroup = $this->loadPropertyGroupWithOptions($sourceGroupId, $context);
        if (!$sourceGroup) {
            throw new \InvalidArgumentException('Source property group not found');
        }

        // Validate all options belong to this group
        $this->validateOptionsBelongToGroup($optionIds, $sourceGroupId);

        // Validate at least one option selected
        if (empty($optionIds)) {
            throw new \InvalidArgumentException('No options selected for transfer');
        }

        // Validate new group names
        if (empty($newGroupNames) || !isset($newGroupNames['uk-UA'])) {
            throw new \InvalidArgumentException('New group name is required (at least uk-UA locale)');
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
            'newGroup' => [
                'names' => $newGroupNames,
                'optionsToMove' => count($optionIds)
            ],
            'selectedOptions' => array_values($selectedOptions),
            'affectedEntities' => $affectedStats
        ];
    }

    /**
     * Execute split operation
     */
    public function executeSplit(string $sourceGroupId, array $optionIds, array $newGroupNames, Context $context): array
    {
        // First run preview to validate
        $preview = $this->previewSplit($sourceGroupId, $optionIds, $newGroupNames, $context);

        // Load source group to copy technical fields
        $sourceGroup = $this->loadPropertyGroupWithOptions($sourceGroupId, $context);

        $this->connection->beginTransaction();

        try {
            // Create new property group
            $newGroupId = Uuid::randomHex();

            $groupData = [
                'id' => $newGroupId,
                'displayType' => $sourceGroup['displayType'] ?? 'text',
                'sortingType' => $sourceGroup['sortingType'] ?? 'alphanumeric',
                'position' => $sourceGroup['position'] ?? 1,
                'translations' => []
            ];

            // Add translations for new group
            foreach ($newGroupNames as $locale => $name) {
                $languageId = $this->getLanguageIdByLocale($locale);
                if ($languageId) {
                    $groupData['translations'][$languageId] = [
                        'name' => $name
                    ];
                }
            }

            $this->propertyGroupRepository->create([$groupData], $context);

            // Move options to new group
            $movedCount = $this->moveOptionsToGroup($optionIds, $newGroupId);

            $this->connection->commit();

            return [
                'success' => true,
                'newGroupId' => $newGroupId,
                'newGroupNames' => $newGroupNames,
                'movedOptions' => $movedCount,
                'affectedEntities' => $preview['affectedEntities'],
                'sourceGroup' => $preview['sourceGroup']
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
            ->setParameter('optionIds', Uuid::fromHexToBytesList($optionIds), Connection::PARAM_STR_ARRAY)
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

        foreach ($optionIds as $optionId) {
            $counts = $this->countAffectedProducts($optionId);
            $totalProducts += $counts['products'];
            $totalVariants += $counts['variants'];
        }

        return [
            'totalProducts' => $totalProducts,
            'totalVariants' => $totalVariants,
            'total' => $totalProducts + $totalVariants
        ];
    }

    /**
     * Count affected products/variants for a single option
     */
    private function countAffectedProducts(string $optionId): array
    {
        // Count in product_property (products)
        $qb = $this->connection->createQueryBuilder();
        $productCount = (int) $qb
            ->select('COUNT(DISTINCT product_id)')
            ->from('product_property')
            ->where('property_group_option_id = :optionId')
            ->setParameter('optionId', Uuid::fromHexToBytes($optionId))
            ->executeQuery()
            ->fetchOne();

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
            'products' => $productCount,
            'variants' => $variantCount,
            'total' => $productCount + $variantCount
        ];
    }

    /**
     * Move options to new group
     */
    private function moveOptionsToGroup(array $optionIds, string $newGroupId): int
    {
        $qb = $this->connection->createQueryBuilder();
        $affected = $qb
            ->update('property_group_option')
            ->set('property_group_id', ':newGroupId')
            ->where('id IN (:optionIds)')
            ->setParameter('newGroupId', Uuid::fromHexToBytes($newGroupId))
            ->setParameter('optionIds', Uuid::fromHexToBytesList($optionIds), Connection::PARAM_STR_ARRAY)
            ->executeStatement();

        return $affected;
    }

    /**
     * Get language ID by locale code
     */
    private function getLanguageIdByLocale(string $locale): ?string
    {
        $qb = $this->connection->createQueryBuilder();
        $languageId = $qb
            ->select('language.id')
            ->from('language')
            ->innerJoin('language', 'locale', 'locale', 'language.locale_id = locale.id')
            ->where('locale.code = :locale')
            ->setParameter('locale', $locale)
            ->executeQuery()
            ->fetchOne();

        return $languageId ? Uuid::fromBytesToHex($languageId) : null;
    }
}
