<?php declare(strict_types=1);

namespace ArtissTools\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class PropertyCleanupService
{
    public function __construct(
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $propertyGroupOptionRepository,
        private readonly EntityRepository $customFieldSetRepository,
        private readonly EntityRepository $customFieldRepository,
        private readonly Connection $connection
    ) {
    }

    /**
     * Find unused property group options
     * Returns grouped by property_group
     */
    public function findUnusedPropertyOptions(Context $context): array
    {
        // Get all property groups with their options
        $criteria = new Criteria();
        $criteria->addAssociation('options.translations');

        $groups = $this->propertyGroupRepository->search($criteria, $context);

        $result = [];

        foreach ($groups as $group) {
            $groupData = [
                'groupId' => $group->getId(),
                'groupName' => $group->getTranslation('name') ?? $group->getName(),
                'unusedOptions' => [],
            ];

            if (!$group->getOptions()) {
                continue;
            }

            foreach ($group->getOptions() as $option) {
                $isUsed = $this->isPropertyOptionUsed($option->getId());

                if (!$isUsed) {
                    $groupData['unusedOptions'][] = [
                        'optionId' => $option->getId(),
                        'optionName' => $option->getTranslation('name') ?? $option->getName(),
                    ];
                }
            }

            // Only include groups that have unused options
            if (!empty($groupData['unusedOptions'])) {
                $result[] = $groupData;
            }
        }

        return $result;
    }

    /**
     * Check if property option is used
     */
    private function isPropertyOptionUsed(string $optionId): bool
    {
        $optionIdBin = hex2bin($optionId);

        // Check in product_property
        $productPropertyCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM product_property WHERE property_group_option_id = :optionId',
            ['optionId' => $optionIdBin]
        );

        if ($productPropertyCount > 0) {
            return true;
        }

        // Check in product_configurator_setting
        $configuratorSettingCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM product_configurator_setting WHERE property_group_option_id = :optionId',
            ['optionId' => $optionIdBin]
        );

        return $configuratorSettingCount > 0;
    }

    /**
     * Find unused custom field sets and fields
     */
    public function findUnusedCustomFields(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addAssociation('customFields');
        $criteria->addAssociation('relations');

        $customFieldSets = $this->customFieldSetRepository->search($criteria, $context);

        $result = [];

        foreach ($customFieldSets as $set) {
            $setData = [
                'setId' => $set->getId(),
                'setName' => $set->getName(),
                'relations' => $this->getSetRelations($set),
                'unusedFields' => [],
            ];

            // Check if set has relations
            $hasRelations = !empty($setData['relations']);

            if (!$set->getCustomFields()) {
                // Empty set
                if (!$hasRelations) {
                    // Set has no relations and no fields - mark for deletion
                    $setData['isEmpty'] = true;
                }
                continue;
            }

            foreach ($set->getCustomFields() as $field) {
                $config = $field->getConfig();
                $label = $config['label'] ?? [];

                // Get label in current language or first available
                $fieldLabel = '';
                if (!empty($label)) {
                    $fieldLabel = is_array($label) ? reset($label) : $label;
                }

                $isUsed = $this->isCustomFieldUsed(
                    $field->getName(),
                    $setData['relations']
                );

                if (!$isUsed) {
                    $setData['unusedFields'][] = [
                        'fieldId' => $field->getId(),
                        'fieldName' => $field->getName(),
                        'fieldLabel' => $fieldLabel,
                        'fieldType' => $field->getType(),
                    ];
                }
            }

            // Check if all fields are unused
            $totalFields = $set->getCustomFields()->count();
            $unusedFieldsCount = count($setData['unusedFields']);

            if ($unusedFieldsCount === $totalFields && !$hasRelations) {
                $setData['isEmpty'] = true;
            }

            // Only include sets that have unused fields
            if (!empty($setData['unusedFields']) || ($setData['isEmpty'] ?? false)) {
                $result[] = $setData;
            }
        }

        return $result;
    }

    /**
     * Get custom field set relations
     */
    private function getSetRelations($set): array
    {
        $relations = [];

        if ($set->getRelations()) {
            foreach ($set->getRelations() as $relation) {
                $entityName = $relation->getEntityName();
                if (!in_array($entityName, $relations)) {
                    $relations[] = $entityName;
                }
            }
        }

        return $relations;
    }

    /**
     * Check if custom field is used in any entity
     */
    private function isCustomFieldUsed(string $fieldName, array $entityNames): bool
    {
        // If no relations, check common entities
        if (empty($entityNames)) {
            $entityNames = ['product', 'category', 'customer', 'order'];
        }

        foreach ($entityNames as $entityName) {
            $tableName = $entityName;

            // Check if table exists
            $tableExists = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE()
                AND table_name = :tableName",
                ['tableName' => $tableName]
            );

            if (!$tableExists) {
                continue;
            }

            // Check if custom_fields column exists
            $columnExists = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                AND table_name = :tableName
                AND column_name = 'custom_fields'",
                ['tableName' => $tableName]
            );

            if (!$columnExists) {
                continue;
            }

            // Check if field is used in JSON
            $sql = "SELECT COUNT(*) FROM `{$tableName}`
                    WHERE custom_fields IS NOT NULL
                    AND JSON_CONTAINS_PATH(custom_fields, 'one', ?)";

            $count = (int) $this->connection->fetchOne(
                $sql,
                ['$.' . $fieldName]
            );

            if ($count > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete unused property options
     */
    public function deletePropertyOptions(array $optionIds, bool $deleteEmptyGroups = true): array
    {
        $this->connection->beginTransaction();

        try {
            $deletedOptions = [];
            $deletedGroups = [];

            foreach ($optionIds as $optionId) {
                // Double-check that option is still unused
                if ($this->isPropertyOptionUsed($optionId)) {
                    continue;
                }

                // Get group ID before deletion
                $groupId = $this->connection->fetchOne(
                    'SELECT property_group_id FROM property_group_option WHERE id = :optionId',
                    ['optionId' => hex2bin($optionId)]
                );

                $this->deletePropertyOption($optionId);
                $deletedOptions[] = $optionId;

                // Check if group is now empty
                if ($deleteEmptyGroups && $groupId) {
                    $groupIdHex = bin2hex($groupId);
                    $remainingOptions = (int) $this->connection->fetchOne(
                        'SELECT COUNT(*) FROM property_group_option WHERE property_group_id = :groupId',
                        ['groupId' => $groupId]
                    );

                    if ($remainingOptions === 0) {
                        $this->deletePropertyGroup($groupIdHex);
                        $deletedGroups[] = $groupIdHex;
                    }
                }
            }

            $this->connection->commit();

            return [
                'deletedOptions' => $deletedOptions,
                'deletedGroups' => $deletedGroups,
            ];

        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Delete unused custom fields
     */
    public function deleteCustomFields(array $fieldIds, array $setIds = []): array
    {
        $this->connection->beginTransaction();

        try {
            $deletedFields = [];
            $deletedSets = [];

            // Delete fields
            foreach ($fieldIds as $fieldId) {
                $this->deleteCustomField($fieldId);
                $deletedFields[] = $fieldId;
            }

            // Delete empty sets
            foreach ($setIds as $setId) {
                $remainingFields = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM custom_field WHERE custom_field_set_id = :setId',
                    ['setId' => hex2bin($setId)]
                );

                if ($remainingFields === 0) {
                    $this->deleteCustomFieldSet($setId);
                    $deletedSets[] = $setId;
                }
            }

            $this->connection->commit();

            return [
                'deletedFields' => $deletedFields,
                'deletedSets' => $deletedSets,
            ];

        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Delete single property option
     */
    private function deletePropertyOption(string $optionId): void
    {
        $optionIdBin = hex2bin($optionId);

        // Delete translations
        $this->connection->executeStatement(
            'DELETE FROM property_group_option_translation WHERE property_group_option_id = :optionId',
            ['optionId' => $optionIdBin]
        );

        // Delete option
        $this->connection->executeStatement(
            'DELETE FROM property_group_option WHERE id = :optionId',
            ['optionId' => $optionIdBin]
        );
    }

    /**
     * Delete property group
     */
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

    /**
     * Delete custom field
     */
    private function deleteCustomField(string $fieldId): void
    {
        $fieldIdBin = hex2bin($fieldId);

        // Delete field
        $this->connection->executeStatement(
            'DELETE FROM custom_field WHERE id = :fieldId',
            ['fieldId' => $fieldIdBin]
        );
    }

    /**
     * Delete custom field set
     */
    private function deleteCustomFieldSet(string $setId): void
    {
        $setIdBin = hex2bin($setId);

        // Delete relations
        $this->connection->executeStatement(
            'DELETE FROM custom_field_set_relation WHERE set_id = :setId',
            ['setId' => $setIdBin]
        );

        // Delete set
        $this->connection->executeStatement(
            'DELETE FROM custom_field_set WHERE id = :setId',
            ['setId' => $setIdBin]
        );
    }
}
