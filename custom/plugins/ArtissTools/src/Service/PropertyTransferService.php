<?php declare(strict_types=1);

namespace ArtissTools\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * PropertyTransferService
 *
 * Service for transferring properties and custom fields between different types.
 * Supports 4 transfer modes:
 * 1. Property → Custom field
 * 2. Property → Property
 * 3. Custom field → Property
 * 4. Custom field → Custom field
 */
class PropertyTransferService
{
    private const MODE_PROPERTY_TO_CUSTOM_FIELD = 'property_to_custom_field';
    private const MODE_PROPERTY_TO_PROPERTY = 'property_to_property';
    private const MODE_CUSTOM_FIELD_TO_PROPERTY = 'custom_field_to_property';
    private const MODE_CUSTOM_FIELD_TO_CUSTOM_FIELD = 'custom_field_to_custom_field';

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $propertyGroupOptionRepository,
        private readonly EntityRepository $customFieldRepository,
        private readonly EntityRepository $productRepository
    ) {
    }

    /**
     * Execute transfer operation
     */
    public function transfer(array $params, bool $dryRun = false, Context $context): array
    {
        $mode = $params['mode'] ?? null;

        return match ($mode) {
            self::MODE_PROPERTY_TO_CUSTOM_FIELD => $this->transferPropertyToCustomField($params, $dryRun, $context),
            self::MODE_PROPERTY_TO_PROPERTY => $this->transferPropertyToProperty($params, $dryRun, $context),
            self::MODE_CUSTOM_FIELD_TO_PROPERTY => $this->transferCustomFieldToProperty($params, $dryRun, $context),
            self::MODE_CUSTOM_FIELD_TO_CUSTOM_FIELD => $this->transferCustomFieldToCustomField($params, $dryRun, $context),
            default => throw new \InvalidArgumentException('Invalid transfer mode')
        };
    }

    /**
     * Mode 1: Property → Custom field
     */
    private function transferPropertyToCustomField(array $params, bool $dryRun, Context $context): array
    {
        $sourceGroupId = $params['sourceGroupId'] ?? null;
        $optionIds = $params['optionIds'] ?? [];
        $targetFieldName = $params['targetFieldName'] ?? null;
        $move = $params['move'] ?? false;
        $deleteEmptySource = $params['deleteEmptySource'] ?? false;

        $stats = [
            'affectedProducts' => 0,
            'valuesRead' => 0,
            'valuesWritten' => 0,
            'optionsDeleted' => 0,
            'groupsDeleted' => 0
        ];

        if (!$dryRun) {
            $this->connection->beginTransaction();
        }

        try {
            // Get all products with selected property options
            $products = $this->getProductsWithPropertyOptions($optionIds);

            foreach ($products as $product) {
                $productId = Uuid::fromBytesToHex($product['id']);
                $optionNames = $this->getProductOptionNames($productId, $optionIds);

                if (empty($optionNames)) {
                    continue;
                }

                $stats['affectedProducts']++;
                $stats['valuesRead'] += count($optionNames);

                if (!$dryRun) {
                    // Write to custom field
                    $this->writeProductCustomField($productId, $targetFieldName, $optionNames);
                    $stats['valuesWritten']++;

                    // Remove property associations if move=true
                    if ($move) {
                        $this->removeProductPropertyAssociations($productId, $optionIds);
                    }
                }
            }

            // Cleanup if deleteEmptySource=true
            if ($deleteEmptySource && !$dryRun) {
                $deletedOptions = $this->deleteUnusedOptions($optionIds);
                $stats['optionsDeleted'] = $deletedOptions;

                if ($this->isGroupEmpty($sourceGroupId)) {
                    $this->deletePropertyGroup($sourceGroupId, $context);
                    $stats['groupsDeleted'] = 1;
                }
            }

            if (!$dryRun) {
                $this->connection->commit();
            }

            return ['success' => true, 'stats' => $stats];

        } catch (\Exception $e) {
            if (!$dryRun) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Mode 2: Property → Property
     */
    private function transferPropertyToProperty(array $params, bool $dryRun, Context $context): array
    {
        $sourceGroupId = $params['sourceGroupId'] ?? null;
        $targetGroupId = $params['targetGroupId'] ?? null;
        $optionIds = $params['optionIds'] ?? [];
        $move = $params['move'] ?? false;
        $deleteEmptySource = $params['deleteEmptySource'] ?? false;

        $stats = [
            'affectedProducts' => 0,
            'optionsMapped' => 0,
            'optionsCreated' => 0,
            'optionsDeleted' => 0,
            'groupsDeleted' => 0
        ];

        if (!$dryRun) {
            $this->connection->beginTransaction();
        }

        try {
            $products = $this->getProductsWithPropertyOptions($optionIds);
            $targetOptions = $this->getGroupOptionsMap($targetGroupId);

            foreach ($products as $product) {
                $productId = Uuid::fromBytesToHex($product['id']);
                $sourceOptions = $this->getProductOptions($productId, $optionIds);

                if (empty($sourceOptions)) {
                    continue;
                }

                $stats['affectedProducts']++;

                foreach ($sourceOptions as $sourceOption) {
                    $optionName = $sourceOption['name'];

                    // Find or create target option
                    $targetOptionId = $targetOptions[$optionName] ?? null;

                    if (!$targetOptionId && !$dryRun) {
                        // Create new option in target group
                        $targetOptionId = $this->createPropertyOption(
                            $targetGroupId,
                            $sourceOption,
                            $context
                        );
                        $targetOptions[$optionName] = $targetOptionId;
                        $stats['optionsCreated']++;
                    }

                    if ($targetOptionId && !$dryRun) {
                        // Add association to target option
                        $this->addProductPropertyAssociation($productId, $targetOptionId);
                        $stats['optionsMapped']++;
                    }
                }

                if ($move && !$dryRun) {
                    $this->removeProductPropertyAssociations($productId, $optionIds);
                }
            }

            if ($deleteEmptySource && !$dryRun) {
                $deletedOptions = $this->deleteUnusedOptions($optionIds);
                $stats['optionsDeleted'] = $deletedOptions;

                if ($this->isGroupEmpty($sourceGroupId)) {
                    $this->deletePropertyGroup($sourceGroupId, $context);
                    $stats['groupsDeleted'] = 1;
                }
            }

            if (!$dryRun) {
                $this->connection->commit();
            }

            return ['success' => true, 'stats' => $stats];

        } catch (\Exception $e) {
            if (!$dryRun) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Mode 3: Custom field → Property
     */
    private function transferCustomFieldToProperty(array $params, bool $dryRun, Context $context): array
    {
        $sourceFieldName = $params['sourceFieldName'] ?? null;
        $targetGroupId = $params['targetGroupId'] ?? null;
        $move = $params['move'] ?? false;
        $deleteEmptySource = $params['deleteEmptySource'] ?? false;

        $stats = [
            'affectedProducts' => 0,
            'valuesRead' => 0,
            'optionsCreated' => 0,
            'associationsCreated' => 0,
            'fieldsDeleted' => 0
        ];

        if (!$dryRun) {
            $this->connection->beginTransaction();
        }

        try {
            $products = $this->getProductsWithCustomField($sourceFieldName);
            $targetOptions = $this->getGroupOptionsMap($targetGroupId);

            foreach ($products as $product) {
                $productId = Uuid::fromBytesToHex($product['id']);
                $customFields = json_decode($product['custom_fields'] ?? '{}', true);
                $sourceValue = $customFields[$sourceFieldName] ?? null;

                if ($sourceValue === null) {
                    continue;
                }

                $stats['affectedProducts']++;
                $values = is_array($sourceValue) ? $sourceValue : [$sourceValue];
                $stats['valuesRead'] += count($values);

                foreach ($values as $value) {
                    $value = (string) $value;
                    $targetOptionId = $targetOptions[$value] ?? null;

                    if (!$targetOptionId && !$dryRun) {
                        $targetOptionId = $this->createPropertyOptionByName(
                            $targetGroupId,
                            $value,
                            $context
                        );
                        $targetOptions[$value] = $targetOptionId;
                        $stats['optionsCreated']++;
                    }

                    if ($targetOptionId && !$dryRun) {
                        $this->addProductPropertyAssociation($productId, $targetOptionId);
                        $stats['associationsCreated']++;
                    }
                }

                if ($move && !$dryRun) {
                    unset($customFields[$sourceFieldName]);
                    $this->updateProductCustomFields($productId, $customFields);
                }
            }

            if ($deleteEmptySource && !$dryRun) {
                $this->deleteCustomField($sourceFieldName, $context);
                $stats['fieldsDeleted'] = 1;
            }

            if (!$dryRun) {
                $this->connection->commit();
            }

            return ['success' => true, 'stats' => $stats];

        } catch (\Exception $e) {
            if (!$dryRun) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Mode 4: Custom field → Custom field
     */
    private function transferCustomFieldToCustomField(array $params, bool $dryRun, Context $context): array
    {
        $sourceFieldName = $params['sourceFieldName'] ?? null;
        $targetFieldName = $params['targetFieldName'] ?? null;
        $move = $params['move'] ?? false;
        $deleteEmptySource = $params['deleteEmptySource'] ?? false;

        $stats = [
            'affectedProducts' => 0,
            'valuesTransferred' => 0,
            'fieldsDeleted' => 0
        ];

        if (!$dryRun) {
            $this->connection->beginTransaction();
        }

        try {
            $products = $this->getProductsWithCustomField($sourceFieldName);

            foreach ($products as $product) {
                $productId = Uuid::fromBytesToHex($product['id']);
                $customFields = json_decode($product['custom_fields'] ?? '{}', true);
                $sourceValue = $customFields[$sourceFieldName] ?? null;

                if ($sourceValue === null) {
                    continue;
                }

                $stats['affectedProducts']++;

                if (!$dryRun) {
                    $customFields[$targetFieldName] = $sourceValue;
                    $stats['valuesTransferred']++;

                    if ($move) {
                        unset($customFields[$sourceFieldName]);
                    }

                    $this->updateProductCustomFields($productId, $customFields);
                }
            }

            if ($deleteEmptySource && !$dryRun) {
                $this->deleteCustomField($sourceFieldName, $context);
                $stats['fieldsDeleted'] = 1;
            }

            if (!$dryRun) {
                $this->connection->commit();
            }

            return ['success' => true, 'stats' => $stats];

        } catch (\Exception $e) {
            if (!$dryRun) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    // Helper methods continue in next part...

    private function getProductsWithPropertyOptions(array $optionIds): array
    {
        $qb = $this->connection->createQueryBuilder();
        return $qb
            ->select('DISTINCT product.id, product.custom_fields')
            ->from('product')
            ->innerJoin('product', 'product_property', 'pp', 'product.id = pp.product_id')
            ->where('pp.property_group_option_id IN (:optionIds)')
            ->andWhere('product.version_id = :versionId')
            ->setParameter('optionIds', Uuid::fromHexToBytesList($optionIds), ArrayParameterType::STRING)
            ->setParameter('versionId', Uuid::fromHexToBytes(Uuid::randomHex()))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function getProductsWithCustomField(string $fieldName): array
    {
        $qb = $this->connection->createQueryBuilder();
        return $qb
            ->select('id, custom_fields')
            ->from('product')
            ->where('JSON_EXTRACT(custom_fields, :path) IS NOT NULL')
            ->andWhere('version_id = :versionId')
            ->setParameter('path', '$.' . $fieldName)
            ->setParameter('versionId', Uuid::fromHexToBytes(Uuid::randomHex()))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function getProductOptionNames(string $productId, array $optionIds): array
    {
        $qb = $this->connection->createQueryBuilder();
        $results = $qb
            ->select('pgo_translation.name')
            ->from('product_property', 'pp')
            ->innerJoin('pp', 'property_group_option', 'pgo', 'pp.property_group_option_id = pgo.id')
            ->innerJoin('pgo', 'property_group_option_translation', 'pgo_translation', 'pgo.id = pgo_translation.property_group_option_id')
            ->where('pp.product_id = :productId')
            ->andWhere('pp.property_group_option_id IN (:optionIds)')
            ->setParameter('productId', Uuid::fromHexToBytes($productId))
            ->setParameter('optionIds', Uuid::fromHexToBytesList($optionIds), ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_column($results, 'name');
    }

    private function getProductOptions(string $productId, array $optionIds): array
    {
        $qb = $this->connection->createQueryBuilder();
        return $qb
            ->select('pgo.id, pgo_translation.name')
            ->from('product_property', 'pp')
            ->innerJoin('pp', 'property_group_option', 'pgo', 'pp.property_group_option_id = pgo.id')
            ->innerJoin('pgo', 'property_group_option_translation', 'pgo_translation', 'pgo.id = pgo_translation.property_group_option_id')
            ->where('pp.product_id = :productId')
            ->andWhere('pp.property_group_option_id IN (:optionIds)')
            ->setParameter('productId', Uuid::fromHexToBytes($productId))
            ->setParameter('optionIds', Uuid::fromHexToBytesList($optionIds), ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function getGroupOptionsMap(string $groupId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $results = $qb
            ->select('pgo.id, pgo_translation.name')
            ->from('property_group_option', 'pgo')
            ->innerJoin('pgo', 'property_group_option_translation', 'pgo_translation', 'pgo.id = pgo_translation.property_group_option_id')
            ->where('pgo.property_group_id = :groupId')
            ->setParameter('groupId', Uuid::fromHexToBytes($groupId))
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($results as $result) {
            $map[$result['name']] = Uuid::fromBytesToHex($result['id']);
        }
        return $map;
    }

    private function writeProductCustomField(string $productId, string $fieldName, array $values): void
    {
        $qb = $this->connection->createQueryBuilder();
        $currentFields = $qb
            ->select('custom_fields')
            ->from('product')
            ->where('id = :productId')
            ->setParameter('productId', Uuid::fromHexToBytes($productId))
            ->executeQuery()
            ->fetchOne();

        $customFields = json_decode($currentFields ?? '{}', true);
        $customFields[$fieldName] = $values;

        $this->connection->createQueryBuilder()
            ->update('product')
            ->set('custom_fields', ':customFields')
            ->where('id = :productId')
            ->setParameter('customFields', json_encode($customFields))
            ->setParameter('productId', Uuid::fromHexToBytes($productId))
            ->executeStatement();
    }

    private function updateProductCustomFields(string $productId, array $customFields): void
    {
        $this->connection->createQueryBuilder()
            ->update('product')
            ->set('custom_fields', ':customFields')
            ->where('id = :productId')
            ->setParameter('customFields', json_encode($customFields))
            ->setParameter('productId', Uuid::fromHexToBytes($productId))
            ->executeStatement();
    }

    private function removeProductPropertyAssociations(string $productId, array $optionIds): void
    {
        $this->connection->createQueryBuilder()
            ->delete('product_property')
            ->where('product_id = :productId')
            ->andWhere('property_group_option_id IN (:optionIds)')
            ->setParameter('productId', Uuid::fromHexToBytes($productId))
            ->setParameter('optionIds', Uuid::fromHexToBytesList($optionIds), ArrayParameterType::STRING)
            ->executeStatement();
    }

    private function addProductPropertyAssociation(string $productId, string $optionId): void
    {
        // Check if association already exists
        $exists = $this->connection->createQueryBuilder()
            ->select('1')
            ->from('product_property')
            ->where('product_id = :productId')
            ->andWhere('property_group_option_id = :optionId')
            ->setParameter('productId', Uuid::fromHexToBytes($productId))
            ->setParameter('optionId', Uuid::fromHexToBytes($optionId))
            ->executeQuery()
            ->fetchOne();

        if (!$exists) {
            $this->connection->createQueryBuilder()
                ->insert('product_property')
                ->values([
                    'product_id' => ':productId',
                    'product_version_id' => ':versionId',
                    'property_group_option_id' => ':optionId'
                ])
                ->setParameter('productId', Uuid::fromHexToBytes($productId))
                ->setParameter('versionId', Uuid::fromHexToBytes(Uuid::randomHex()))
                ->setParameter('optionId', Uuid::fromHexToBytes($optionId))
                ->executeStatement();
        }
    }

    private function createPropertyOption(string $groupId, array $sourceOption, Context $context): string
    {
        $optionId = Uuid::randomHex();

        $this->propertyGroupOptionRepository->create([[
            'id' => $optionId,
            'propertyGroupId' => $groupId,
            'name' => $sourceOption['name']
        ]], $context);

        return $optionId;
    }

    private function createPropertyOptionByName(string $groupId, string $name, Context $context): string
    {
        $optionId = Uuid::randomHex();

        $this->propertyGroupOptionRepository->create([[
            'id' => $optionId,
            'propertyGroupId' => $groupId,
            'name' => $name
        ]], $context);

        return $optionId;
    }

    private function deleteUnusedOptions(array $optionIds): int
    {
        $unusedOptions = [];

        foreach ($optionIds as $optionId) {
            if (!$this->isOptionUsed($optionId)) {
                $unusedOptions[] = $optionId;
            }
        }

        if (empty($unusedOptions)) {
            return 0;
        }

        $this->connection->createQueryBuilder()
            ->delete('property_group_option')
            ->where('id IN (:optionIds)')
            ->setParameter('optionIds', Uuid::fromHexToBytesList($unusedOptions), ArrayParameterType::STRING)
            ->executeStatement();

        return count($unusedOptions);
    }

    private function isOptionUsed(string $optionId): bool
    {
        // Check product_property
        $usedInProducts = $this->connection->createQueryBuilder()
            ->select('1')
            ->from('product_property')
            ->where('property_group_option_id = :optionId')
            ->setParameter('optionId', Uuid::fromHexToBytes($optionId))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($usedInProducts) {
            return true;
        }

        // Check product_configurator_setting
        $usedInConfigurator = $this->connection->createQueryBuilder()
            ->select('1')
            ->from('product_configurator_setting')
            ->where('property_group_option_id = :optionId')
            ->setParameter('optionId', Uuid::fromHexToBytes($optionId))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return (bool) $usedInConfigurator;
    }

    private function isGroupEmpty(string $groupId): bool
    {
        $count = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('property_group_option')
            ->where('property_group_id = :groupId')
            ->setParameter('groupId', Uuid::fromHexToBytes($groupId))
            ->executeQuery()
            ->fetchOne();

        return $count == 0;
    }

    private function deletePropertyGroup(string $groupId, Context $context): void
    {
        $this->propertyGroupRepository->delete([['id' => $groupId]], $context);
    }

    private function deleteCustomField(string $fieldName, Context $context): void
    {
        // Find custom field by name
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $fieldName));

        $field = $this->customFieldRepository->search($criteria, $context)->first();

        if ($field) {
            $this->customFieldRepository->delete([['id' => $field->getId()]], $context);
        }
    }
}
