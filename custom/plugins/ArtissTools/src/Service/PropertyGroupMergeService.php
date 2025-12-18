<?php declare(strict_types=1);

namespace ArtissTools\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;

class PropertyGroupMergeService
{
    public function __construct(
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $propertyGroupOptionRepository,
        private readonly Connection $connection
    ) {
    }

    /**
     * Get all property groups with their options
     */
    public function getAllPropertyGroups(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addAssociation('options');

        $groups = $this->propertyGroupRepository->search($criteria, $context);

        $result = [];
        foreach ($groups as $group) {
            $result[] = [
                'id' => $group->getId(),
                'name' => $group->getTranslation('name') ?? $group->getName(),
                'displayType' => $group->getDisplayType(),
                'optionCount' => $group->getOptions() ? $group->getOptions()->count() : 0,
            ];
        }

        return $result;
    }

    /**
     * Find property group by ID
     */
    public function findPropertyGroupById(string $id, Context $context): ?PropertyGroupEntity
    {
        $criteria = new Criteria();
        $criteria->setIds([strtolower($id)]);
        $criteria->addAssociation('options');

        $result = $this->propertyGroupRepository->search($criteria, $context);
        return $result->first();
    }

    /**
     * Load group options
     */
    public function loadGroupOptions(string $groupId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('groupId', $groupId));
        $criteria->addAssociation('translations');

        $options = $this->propertyGroupOptionRepository->search($criteria, $context);

        $result = [];
        foreach ($options as $option) {
            $result[$option->getId()] = $option;
        }

        return $result;
    }

    /**
     * Prepare merge plan
     */
    public function prepareMergePlan(
        PropertyGroupEntity $targetGroup,
        array $targetOptions,
        array $sourceGroups,
        Context $context
    ): array {
        $plan = [
            'target' => [
                'id' => $targetGroup->getId(),
                'name' => $targetGroup->getTranslation('name') ?? $targetGroup->getName(),
            ],
            'targetOptionsCount' => count($targetOptions),
            'sources' => [],
            'stats' => [
                'totalSourceOptions' => 0,
                'optionsToMerge' => 0,
                'optionsToMove' => 0,
                'optionsToDelete' => 0,
                'productPropertyUpdates' => 0,
                'configuratorSettingUpdates' => 0,
                'groupsToDelete' => 0,
                'affectedProductIds' => [],
            ],
        ];

        $allProductIds = [];

        foreach ($sourceGroups as $sourceGroup) {
            $sourceOptions = $this->loadGroupOptions($sourceGroup->getId(), $context);
            $sourceData = [
                'id' => $sourceGroup->getId(),
                'name' => $sourceGroup->getTranslation('name') ?? $sourceGroup->getName(),
                'optionCount' => count($sourceOptions),
                'mergeActions' => [],
                'moveActions' => [],
            ];

            $plan['stats']['totalSourceOptions'] += count($sourceOptions);

            foreach ($sourceOptions as $sourceOption) {
                $sourceName = $this->getOptionName($sourceOption);
                $matchingTargetOption = $this->findOptionByName($targetOptions, $sourceName);

                // Get affected records for this option
                $affectedRecords = $this->countAffectedRecords($sourceOption->getId());
                
                // Collect all product IDs (for both merge and move)
                $allProductIds = array_merge($allProductIds, $affectedRecords['productIds']);

                if ($matchingTargetOption) {
                    $sourceData['mergeActions'][] = [
                        'sourceOptionId' => $sourceOption->getId(),
                        'sourceOptionName' => $sourceName,
                        'targetOptionId' => $matchingTargetOption->getId(),
                        'affectedRecords' => $affectedRecords,
                    ];

                    $plan['stats']['optionsToMerge']++;
                    $plan['stats']['optionsToDelete']++;
                    $plan['stats']['productPropertyUpdates'] += $affectedRecords['productProperty'];
                    $plan['stats']['configuratorSettingUpdates'] += $affectedRecords['configuratorSetting'];
                } else {
                    $sourceData['moveActions'][] = [
                        'optionId' => $sourceOption->getId(),
                        'optionName' => $sourceName,
                        'productCount' => $affectedRecords['productProperty'],
                    ];

                    $plan['stats']['optionsToMove']++;
                }
            }

            $plan['sources'][] = $sourceData;
            $plan['stats']['groupsToDelete']++;
        }

        $plan['stats']['affectedProductIds'] = array_values(array_unique($allProductIds));

        return $plan;
    }

    /**
     * Execute merge
     */
    public function executeMerge(array $plan): void
    {
        $this->connection->beginTransaction();

        try {
            foreach ($plan['sources'] as $sourceData) {
                // Process merge actions
                foreach ($sourceData['mergeActions'] as $action) {
                    $this->mergeOption(
                        $action['sourceOptionId'],
                        $action['targetOptionId']
                    );
                }

                // Process move actions
                foreach ($sourceData['moveActions'] as $action) {
                    $this->moveOption(
                        $action['optionId'],
                        $plan['target']['id']
                    );
                }

                // Delete source group
                $this->deletePropertyGroup($sourceData['id']);
            }

            $this->connection->commit();
        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    private function getOptionName(PropertyGroupOptionEntity $option): string
    {
        return $option->getTranslation('name') ?? $option->getName() ?? '';
    }

    private function findOptionByName(array $options, string $name): ?PropertyGroupOptionEntity
    {
        foreach ($options as $option) {
            if ($this->getOptionName($option) === $name) {
                return $option;
            }
        }

        return null;
    }

    private function countAffectedRecords(string $optionId): array
    {
        $optionIdBin = hex2bin($optionId);
        
        // Get product IDs
        $productIds = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT LOWER(HEX(product_id)) as product_id FROM product_property WHERE property_group_option_id = :optionId',
            ['optionId' => $optionIdBin]
        );
        $productIdList = array_column($productIds, 'product_id');

        $configuratorSettingCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM product_configurator_setting WHERE property_group_option_id = :optionId',
            ['optionId' => $optionIdBin]
        );

        return [
            'productProperty' => count($productIdList),
            'configuratorSetting' => $configuratorSettingCount,
            'productIds' => $productIdList,
        ];
    }

    private function mergeOption(string $sourceOptionId, string $targetOptionId): void
    {
        $sourceIdBin = hex2bin($sourceOptionId);
        $targetIdBin = hex2bin($targetOptionId);

        // Update product_property references
        $this->connection->executeStatement(
            'UPDATE product_property SET property_group_option_id = :targetId WHERE property_group_option_id = :sourceId',
            [
                'targetId' => $targetIdBin,
                'sourceId' => $sourceIdBin,
            ]
        );

        // Update product_configurator_setting references
        $this->connection->executeStatement(
            'UPDATE product_configurator_setting SET property_group_option_id = :targetId WHERE property_group_option_id = :sourceId',
            [
                'targetId' => $targetIdBin,
                'sourceId' => $sourceIdBin,
            ]
        );

        // Delete source option translations
        $this->connection->executeStatement(
            'DELETE FROM property_group_option_translation WHERE property_group_option_id = :optionId',
            ['optionId' => $sourceIdBin]
        );

        // Delete source option
        $this->connection->executeStatement(
            'DELETE FROM property_group_option WHERE id = :optionId',
            ['optionId' => $sourceIdBin]
        );
    }

    private function moveOption(string $optionId, string $targetGroupId): void
    {
        $optionIdBin = hex2bin($optionId);
        $targetGroupIdBin = hex2bin($targetGroupId);

        $this->connection->executeStatement(
            'UPDATE property_group_option SET property_group_id = :groupId WHERE id = :optionId',
            [
                'groupId' => $targetGroupIdBin,
                'optionId' => $optionIdBin,
            ]
        );
    }

    private function deletePropertyGroup(string $groupId): void
    {
        $groupIdBin = hex2bin($groupId);

        // Delete translations
        $this->connection->executeStatement(
            'DELETE FROM property_group_translation WHERE property_group_id = :groupId',
            ['groupId' => $groupIdBin]
        );

        // Delete group
        $this->connection->executeStatement(
            'DELETE FROM property_group WHERE id = :groupId',
            ['groupId' => $groupIdBin]
        );
    }
}
