<?php declare(strict_types=1);

namespace ArtissTools\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\CustomFieldEntity;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;

class CustomFieldSetMergeService
{
    public function __construct(
        private readonly EntityRepository $customFieldSetRepository,
        private readonly EntityRepository $customFieldRepository,
        private readonly Connection $connection
    ) {
    }

    /**
     * Get all custom field sets with their fields
     */
    public function getAllCustomFieldSets(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addAssociation('customFields');

        $sets = $this->customFieldSetRepository->search($criteria, $context);

        $result = [];
        foreach ($sets as $set) {
            $config = $set->getTranslation('config') ?? [];
            $result[] = [
                'id' => $set->getId(),
                'name' => $set->getName(),
                'label' => $config['label'] ?? $set->getName(),
                'fieldCount' => $set->getCustomFields() ? $set->getCustomFields()->count() : 0,
            ];
        }

        return $result;
    }

    /**
     * Find custom field set by ID
     */
    public function findCustomFieldSetById(string $id, Context $context): ?CustomFieldSetEntity
    {
        $criteria = new Criteria();
        $criteria->setIds([strtolower($id)]);
        $criteria->addAssociation('customFields');

        $result = $this->customFieldSetRepository->search($criteria, $context);
        return $result->first();
    }

    /**
     * Load set fields
     */
    public function loadSetFields(string $setId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFieldSetId', $setId));

        $fields = $this->customFieldRepository->search($criteria, $context);

        $result = [];
        foreach ($fields as $field) {
            $config = $field->getConfig() ?? [];

            // Extract label - it might be a translation array or a string
            $label = $field->getName();
            if (isset($config['label'])) {
                if (is_array($config['label'])) {
                    // Try to get label for current language or fallback to first available
                    $label = $config['label']['en-GB'] ??
                             $config['label']['de-DE'] ??
                             $config['label']['ru-RU'] ??
                             $config['label']['uk-UA'] ??
                             reset($config['label']) ??
                             $field->getName();
                } else {
                    $label = $config['label'];
                }
            }

            $result[] = [
                'id' => $field->getId(),
                'name' => $field->getName(),
                'label' => $label,
                'type' => $field->getType(),
            ];
        }

        return $result;
    }

    /**
     * Load set fields as entities (for internal use)
     */
    public function loadSetFieldsAsEntities(string $setId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFieldSetId', $setId));

        $fields = $this->customFieldRepository->search($criteria, $context);

        $result = [];
        foreach ($fields as $field) {
            $result[$field->getId()] = $field;
        }

        return $result;
    }

    /**
     * Prepare merge plan
     */
    public function prepareMergePlan(
        CustomFieldSetEntity $targetSet,
        array $targetFields,
        array $sourceSets,
        Context $context
    ): array {
        $targetConfig = $targetSet->getTranslation('config') ?? [];

        $plan = [
            'target' => [
                'id' => $targetSet->getId(),
                'name' => $targetSet->getName(),
                'label' => $targetConfig['label'] ?? $targetSet->getName(),
            ],
            'targetFieldsCount' => count($targetFields),
            'sources' => [],
            'stats' => [
                'totalSourceFields' => 0,
                'fieldsToMerge' => 0,
                'fieldsToMove' => 0,
                'fieldsToDelete' => 0,
                'setsToDelete' => 0,
            ],
        ];

        foreach ($sourceSets as $sourceSet) {
            $sourceFields = $this->loadSetFieldsAsEntities($sourceSet->getId(), $context);
            $sourceConfig = $sourceSet->getTranslation('config') ?? [];

            $sourceData = [
                'id' => $sourceSet->getId(),
                'name' => $sourceSet->getName(),
                'label' => $sourceConfig['label'] ?? $sourceSet->getName(),
                'fieldCount' => count($sourceFields),
                'mergeActions' => [],
                'moveActions' => [],
            ];

            $plan['stats']['totalSourceFields'] += count($sourceFields);

            foreach ($sourceFields as $sourceField) {
                $sourceName = $sourceField->getName();
                $matchingTargetField = $this->findFieldByName($targetFields, $sourceName);

                if ($matchingTargetField) {
                    $sourceData['mergeActions'][] = [
                        'sourceFieldId' => $sourceField->getId(),
                        'sourceFieldName' => $sourceName,
                        'targetFieldId' => $matchingTargetField->getId(),
                    ];

                    $plan['stats']['fieldsToMerge']++;
                    $plan['stats']['fieldsToDelete']++;
                } else {
                    $sourceData['moveActions'][] = [
                        'fieldId' => $sourceField->getId(),
                        'fieldName' => $sourceName,
                    ];

                    $plan['stats']['fieldsToMove']++;
                }
            }

            $plan['sources'][] = $sourceData;
            $plan['stats']['setsToDelete']++;
        }

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
                // Delete duplicate fields
                foreach ($sourceData['mergeActions'] as $action) {
                    $this->deleteCustomField($action['sourceFieldId']);
                }

                // Move unique fields
                foreach ($sourceData['moveActions'] as $action) {
                    $this->moveCustomField(
                        $action['fieldId'],
                        $plan['target']['id']
                    );
                }

                // Delete source set relations
                $this->deleteCustomFieldSetRelations($sourceData['id']);

                // Delete source set
                $this->deleteCustomFieldSet($sourceData['id']);
            }

            $this->connection->commit();
        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    private function findFieldByName(array $fields, string $name): ?CustomFieldEntity
    {
        foreach ($fields as $field) {
            if ($field->getName() === $name) {
                return $field;
            }
        }

        return null;
    }

    private function deleteCustomField(string $fieldId): void
    {
        $fieldIdBin = hex2bin($fieldId);

        $this->connection->executeStatement(
            'DELETE FROM custom_field WHERE id = :fieldId',
            ['fieldId' => $fieldIdBin]
        );
    }

    private function moveCustomField(string $fieldId, string $targetSetId): void
    {
        $fieldIdBin = hex2bin($fieldId);
        $targetSetIdBin = hex2bin($targetSetId);

        $this->connection->executeStatement(
            'UPDATE custom_field SET custom_field_set_id = :setId WHERE id = :fieldId',
            [
                'setId' => $targetSetIdBin,
                'fieldId' => $fieldIdBin,
            ]
        );
    }

    private function deleteCustomFieldSetRelations(string $setId): void
    {
        $setIdBin = hex2bin($setId);

        $this->connection->executeStatement(
            'DELETE FROM custom_field_set_relation WHERE set_id = :setId',
            ['setId' => $setIdBin]
        );
    }

    private function deleteCustomFieldSet(string $setId): void
    {
        $setIdBin = hex2bin($setId);

        $this->connection->executeStatement(
            'DELETE FROM custom_field_set WHERE id = :setId',
            ['setId' => $setIdBin]
        );
    }
}
