<?php declare(strict_types=1);

namespace ArtissTools\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class PropertyOptionMergeService
{
    public function __construct(
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $propertyGroupOptionRepository,
        private readonly Connection $connection
    ) {
    }

    /**
     * Load all options for a property group with usage statistics
     */
    public function loadGroupOptions(string $groupId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('groupId', $groupId));
        $criteria->addAssociation('translations');

        $options = $this->propertyGroupOptionRepository->search($criteria, $context);
        $result = [];
        $optionsInfo = [];

        foreach ($options as $option) {
            $optionId = $option->getId();
            $optionName = $option->getTranslation('name') ?? $option->getName();

            $productCount = $this->countProductPropertyUsages($optionId);
            $variantCount = $this->countConfiguratorSettingUsages($optionId);

            $result[] = [
                'id' => $optionId,
                'name' => $optionName
            ];

            $optionsInfo[$optionId] = [
                'id' => $optionId,
                'name' => $optionName,
                'productCount' => $productCount,
                'variantCount' => $variantCount
            ];
        }

        return [
            'options' => $result,
            'optionsInfo' => $optionsInfo
        ];
    }

    /**
     * Scan merge operation (dry-run)
     */
    public function scanMerge(
        string $groupId,
        string $targetOptionId,
        array $sourceOptionIds,
        Context $context
    ): array {
        // Validate that all options belong to the same group
        $this->validateOptionsInGroup($groupId, array_merge([$targetOptionId], $sourceOptionIds), $context);

        $targetOption = $this->getOptionById($targetOptionId, $context);
        $sourceOptions = [];

        foreach ($sourceOptionIds as $sourceOptionId) {
            $sourceOptions[] = $this->getOptionById($sourceOptionId, $context);
        }

        $productPropertyCount = $this->countProductPropertyToUpdate($sourceOptionIds);
        $configuratorSettingCount = $this->countConfiguratorSettingToUpdate($sourceOptionIds);
        $affectedProductIds = $this->getAffectedProductIds($sourceOptionIds);

        return [
            'target' => [
                'id' => $targetOption->getId(),
                'name' => $targetOption->getTranslation('name') ?? $targetOption->getName()
            ],
            'sources' => array_map(function ($option) {
                return [
                    'id' => $option->getId(),
                    'name' => $option->getTranslation('name') ?? $option->getName()
                ];
            }, $sourceOptions),
            'stats' => [
                'productPropertyCount' => $productPropertyCount,
                'configuratorSettingCount' => $configuratorSettingCount,
                'affectedProductIds' => $affectedProductIds
            ]
        ];
    }

    /**
     * Execute merge operation
     */
    public function mergeOptions(
        string $groupId,
        string $targetOptionId,
        array $sourceOptionIds,
        Context $context
    ): array {
        // Validate
        $this->validateOptionsInGroup($groupId, array_merge([$targetOptionId], $sourceOptionIds), $context);

        $targetOptionBin = Uuid::fromHexToBytes($targetOptionId);
        $sourceOptionIdsBin = array_map(fn($id) => Uuid::fromHexToBytes($id), $sourceOptionIds);

        // Count before update
        $productPropertyCount = $this->countProductPropertyToUpdate($sourceOptionIds);
        $configuratorSettingCount = $this->countConfiguratorSettingToUpdate($sourceOptionIds);

        // Begin transaction to ensure atomicity
        $this->connection->beginTransaction();

        try {
            // Step 1: Delete source associations where target already exists on the same product
            $this->connection->executeStatement(
                'DELETE pp_source FROM product_property pp_source
                 INNER JOIN product_property pp_target 
                    ON pp_source.product_id = pp_target.product_id 
                    AND pp_source.product_version_id = pp_target.product_version_id
                 WHERE pp_source.property_group_option_id IN (:sourceIds)
                 AND pp_target.property_group_option_id = :targetId',
                [
                    'sourceIds' => $sourceOptionIdsBin,
                    'targetId' => $targetOptionBin
                ],
                [
                    'sourceIds' => ArrayParameterType::STRING
                ]
            );

            // Step 2: For products with multiple source options, keep only one (to avoid duplicates after update)
            // Get products that have multiple source options
            $productsWithMultipleSources = $this->connection->fetchAllAssociative(
                'SELECT product_id, product_version_id, MIN(property_group_option_id) as keep_option_id
                 FROM product_property 
                 WHERE property_group_option_id IN (:sourceIds)
                 GROUP BY product_id, product_version_id
                 HAVING COUNT(*) > 1',
                ['sourceIds' => $sourceOptionIdsBin],
                ['sourceIds' => ArrayParameterType::STRING]
            );

            // Delete duplicate source associations (keep only one per product)
            foreach ($productsWithMultipleSources as $row) {
                $this->connection->executeStatement(
                    'DELETE FROM product_property 
                     WHERE product_id = :productId 
                     AND product_version_id = :versionId
                     AND property_group_option_id IN (:sourceIds)
                     AND property_group_option_id != :keepId',
                    [
                        'productId' => $row['product_id'],
                        'versionId' => $row['product_version_id'],
                        'sourceIds' => $sourceOptionIdsBin,
                        'keepId' => $row['keep_option_id']
                    ],
                    [
                        'sourceIds' => ArrayParameterType::STRING
                    ]
                );
            }

            // Step 3: Now safely update remaining source options to target
            $this->connection->executeStatement(
                'UPDATE product_property 
                 SET property_group_option_id = :targetId
                 WHERE property_group_option_id IN (:sourceIds)',
                [
                    'targetId' => $targetOptionBin,
                    'sourceIds' => $sourceOptionIdsBin
                ],
                [
                    'sourceIds' => ArrayParameterType::STRING
                ]
            );

            // Same logic for product_configurator_setting
            // Step 1: Delete where target exists
            $this->connection->executeStatement(
                'DELETE pcs_source FROM product_configurator_setting pcs_source
                 INNER JOIN product_configurator_setting pcs_target 
                    ON pcs_source.product_id = pcs_target.product_id 
                    AND pcs_source.product_version_id = pcs_target.product_version_id
                 WHERE pcs_source.property_group_option_id IN (:sourceIds)
                 AND pcs_target.property_group_option_id = :targetId',
                [
                    'sourceIds' => $sourceOptionIdsBin,
                    'targetId' => $targetOptionBin
                ],
                [
                    'sourceIds' => ArrayParameterType::STRING
                ]
            );

            // Step 2: Handle multiple sources per product
            $configsWithMultipleSources = $this->connection->fetchAllAssociative(
                'SELECT product_id, product_version_id, MIN(property_group_option_id) as keep_option_id
                 FROM product_configurator_setting 
                 WHERE property_group_option_id IN (:sourceIds)
                 GROUP BY product_id, product_version_id
                 HAVING COUNT(*) > 1',
                ['sourceIds' => $sourceOptionIdsBin],
                ['sourceIds' => ArrayParameterType::STRING]
            );

            foreach ($configsWithMultipleSources as $row) {
                $this->connection->executeStatement(
                    'DELETE FROM product_configurator_setting 
                     WHERE product_id = :productId 
                     AND product_version_id = :versionId
                     AND property_group_option_id IN (:sourceIds)
                     AND property_group_option_id != :keepId',
                    [
                        'productId' => $row['product_id'],
                        'versionId' => $row['product_version_id'],
                        'sourceIds' => $sourceOptionIdsBin,
                        'keepId' => $row['keep_option_id']
                    ],
                    [
                        'sourceIds' => ArrayParameterType::STRING
                    ]
                );
            }

            // Step 3: Update remaining
            $this->connection->executeStatement(
                'UPDATE product_configurator_setting 
                 SET property_group_option_id = :targetId
                 WHERE property_group_option_id IN (:sourceIds)',
                [
                    'targetId' => $targetOptionBin,
                    'sourceIds' => $sourceOptionIdsBin
                ],
                [
                    'sourceIds' => ArrayParameterType::STRING
                ]
            );

            // Delete source options using repository
            $idsToDelete = [];
            foreach ($sourceOptionIds as $sourceOptionId) {
                $idsToDelete[] = ['id' => $sourceOptionId];
            }
            if (!empty($idsToDelete)) {
                $this->propertyGroupOptionRepository->delete($idsToDelete, $context);
            }

            $this->connection->commit();
        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw new \RuntimeException('Merge failed and was rolled back: ' . $e->getMessage(), 0, $e);
        }

        $targetOption = $this->getOptionById($targetOptionId, $context);

        return [
            'target' => [
                'id' => $targetOption->getId(),
                'name' => $targetOption->getTranslation('name') ?? $targetOption->getName()
            ],
            'stats' => [
                'productPropertyCount' => $productPropertyCount,
                'configuratorSettingCount' => $configuratorSettingCount,
                'deletedOptionIds' => $sourceOptionIds
            ]
        ];
    }

    /**
     * Validate that all options belong to the same group
     */
    private function validateOptionsInGroup(string $groupId, array $optionIds, Context $context): void
    {
        $criteria = new Criteria($optionIds);
        $options = $this->propertyGroupOptionRepository->search($criteria, $context);

        foreach ($options as $option) {
            if ($option->getGroupId() !== $groupId) {
                throw new \RuntimeException(
                    sprintf('Option %s does not belong to group %s', $option->getId(), $groupId)
                );
            }
        }
    }

    /**
     * Get option by ID
     */
    private function getOptionById(string $optionId, Context $context)
    {
        $criteria = new Criteria([$optionId]);
        $criteria->addAssociation('translations');

        $option = $this->propertyGroupOptionRepository->search($criteria, $context)->first();

        if (!$option) {
            throw new \RuntimeException(sprintf('Option %s not found', $optionId));
        }

        return $option;
    }

    /**
     * Count how many product_property records use these options
     */
    private function countProductPropertyToUpdate(array $optionIds): int
    {
        $optionIdsBin = array_map(fn($id) => Uuid::fromHexToBytes($id), $optionIds);

        $qb = $this->connection->createQueryBuilder();
        $count = $qb->select('COUNT(*)')
            ->from('product_property')
            ->where('property_group_option_id IN (:optionIds)')
            ->setParameter('optionIds', $optionIdsBin, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchOne();

        return (int) $count;
    }

    /**
     * Count how many product_configurator_setting records use these options
     */
    private function countConfiguratorSettingToUpdate(array $optionIds): int
    {
        $optionIdsBin = array_map(fn($id) => Uuid::fromHexToBytes($id), $optionIds);

        $qb = $this->connection->createQueryBuilder();
        $count = $qb->select('COUNT(*)')
            ->from('product_configurator_setting')
            ->where('property_group_option_id IN (:optionIds)')
            ->setParameter('optionIds', $optionIdsBin, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchOne();

        return (int) $count;
    }

    /**
     * Count product property usages for a single option
     */
    private function countProductPropertyUsages(string $optionId): int
    {
        $optionIdBin = Uuid::fromHexToBytes($optionId);

        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM product_property WHERE property_group_option_id = :optionId',
            ['optionId' => $optionIdBin]
        );

        return (int) $count;
    }

    /**
     * Count configurator setting usages for a single option
     */
    private function countConfiguratorSettingUsages(string $optionId): int
    {
        $optionIdBin = Uuid::fromHexToBytes($optionId);

        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM product_configurator_setting WHERE property_group_option_id = :optionId',
            ['optionId' => $optionIdBin]
        );

        return (int) $count;
    }

    /**
     * Get product IDs affected by source options
     */
    private function getAffectedProductIds(array $optionIds): array
    {
        $optionIdsBin = array_map(fn($id) => Uuid::fromHexToBytes($id), $optionIds);

        $qb = $this->connection->createQueryBuilder();
        $productIds = $qb->select('DISTINCT LOWER(HEX(product_id)) as product_id')
            ->from('product_property')
            ->where('property_group_option_id IN (:optionIds)')
            ->setParameter('optionIds', $optionIdsBin, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_column($productIds, 'product_id');
    }
}

