<?php declare(strict_types=1);

namespace ArtissTools\Command;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\CustomFieldEntity;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Description:
 *   Merges multiple custom field sets into a single target set.
 *   Deletes duplicate fields by name, moves unique fields to target,
 *   preserves custom field values in entities, and cleans up source sets.
 *
 * Usage:
 *   bin/console artiss:custom-field:merge [options]
 *
 * Options:
 *   --target=VALUE, -t VALUE    Target custom field set (ID or name)
 *   --sources=VALUE, -s VALUE   Source custom field sets separated by comma (ID or name)
 *   --locale=VALUE, -l VALUE    Locale for name search (e.g., uk-UA, ru-RU, en-GB)
 *   --dry-run                   Simulate the merge without making changes
 *   --force, -f                 Skip confirmation prompt
 *
 * Example:
 *   bin/console artiss:custom-field:merge --target="supplier_fields" --sources="old_supplier_data,legacy_fields" --locale=en-GB --dry-run --force
 */
#[AsCommand(
    name: 'artiss:custom-field:merge',
    description: 'Merge multiple custom field sets into a single target set'
)]
class CustomFieldSetMergeCommand extends Command
{
    private SymfonyStyle $io;
    private Context $context;
    private ?string $locale = null;
    private bool $dryRun = false;
    private bool $force = false;

    public function __construct(
        private readonly EntityRepository $customFieldSetRepository,
        private readonly EntityRepository $customFieldRepository,
        private readonly Connection $connection
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'Target custom field set (ID or name)')
            ->addOption('sources', 's', InputOption::VALUE_REQUIRED, 'Source custom field sets separated by comma (ID or name)')
            ->addOption('locale', 'l', InputOption::VALUE_OPTIONAL, 'Locale for name search (e.g., uk-UA, ru-RU, en-GB)', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate the merge without making changes')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt')
            ->setHelp(<<<'EOF'
The <info>artiss:custom-field:merge</info> command merges multiple custom field sets into a single target set.

<comment>Purpose:</comment>
This command consolidates duplicate or similar custom field sets by:
- Deleting custom fields that already exist in the target set (by name)
- Moving unique custom fields to the target set
- Cleaning up source sets and their relations
- Preserving custom field values in entities (products, customers, etc.)

<comment>Identification:</comment>
Custom field sets can be identified by:
- <info>ID</info> (32 hex characters): 019ab732391f73a582dd7bd19abac725
- <info>Name</info> (technical name): supplier_fields
- <info>Label</info> (from config): "Supplier Information"

<comment>Usage Examples:</comment>

1. <info>Dry-run mode (recommended first):</info>
   <comment>bin/console artiss:custom-field:merge \
     --target="019ab732391f73a582dd7bd19abac725" \
     --sources="019b08798334723ba973133a1e0c21f2,23ed2aede0cfc728778f11636a690526" \
     --dry-run</comment>

2. <info>Merge by technical name:</info>
   <comment>bin/console artiss:custom-field:merge \
     --target="supplier_fields" \
     --sources="old_supplier_data,legacy_fields"</comment>

3. <info>Merge by label (displayed name):</info>
   <comment>bin/console artiss:custom-field:merge \
     --target="Supplier Information" \
     --sources="Product Properties"</comment>

4. <info>Force mode (skip confirmation):</info>
   <comment>bin/console artiss:custom-field:merge \
     --target="supplier_fields" \
     --sources="temporary_fields" \
     --force</comment>

5. <info>Multiple sources:</info>
   <comment>bin/console artiss:custom-field:merge \
     --target="product_custom_properties" \
     --sources="set1,set2,set3"</comment>

<comment>Merge Logic:</comment>
For each source set field:
- If a field with the same name exists in target → delete source field (duplicate)
- If field is unique → move to target set

After processing all sources:
- Unique fields moved to target set
- Duplicate fields deleted from source sets
- Source sets and their relations deleted
- Custom field values remain accessible in entities

<comment>Important Note - Custom Field Values:</comment>
Custom field values are stored in entity JSON columns using field names as keys.
When duplicate fields are deleted, their values remain in entities because:
- Values are stored by field NAME, not field ID
- Target set has a field with the same name
- Data remains accessible through the target field

Example:
- Source set has field "custom_color" with values in products
- Target set already has field "custom_color"
- After merge: source field deleted, but values still work via target field

<comment>Safety:</comment>
- All operations run in a transaction (rollback on error)
- Use --dry-run to preview changes before applying
- Confirmation prompt by default (override with --force)
- Custom field values in entities are preserved

<comment>Important Notes:</comment>
- Target and source sets cannot overlap (same set cannot be both)
- Name search must return exactly one set (use ID if multiple matches)
- All database changes are atomic (all succeed or all rollback)
- Field matching is by technical name (case-sensitive)

<comment>When to Use:</comment>
- Consolidating duplicate custom field sets
- Cleaning up after data migration
- Merging fields from deactivated plugins
- Organizing custom fields into logical groups
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->context = Context::createDefaultContext();
        $this->dryRun = $input->getOption('dry-run');
        $this->force = $input->getOption('force');
        $this->locale = $input->getOption('locale');

        $this->io->title('Custom Field Set Merge Command');

        if ($this->dryRun) {
            $this->io->warning('DRY-RUN MODE: No changes will be made to the database');
        }

        // Validate required options
        $targetValue = $input->getOption('target');
        $sourcesValue = $input->getOption('sources');

        if (!$targetValue || !$sourcesValue) {
            $this->io->error('Both --target and --sources options are required');
            return Command::FAILURE;
        }

        // Parse sources
        $sourceValues = array_map('trim', explode(',', $sourcesValue));

        if (empty($sourceValues)) {
            $this->io->error('At least one source set must be provided');
            return Command::FAILURE;
        }

        try {
            // Find target set
            $this->io->section('Finding target custom field set');
            $targetSet = $this->findCustomFieldSet($targetValue);

            if (!$targetSet) {
                $this->io->error(sprintf('Target custom field set "%s" not found', $targetValue));
                return Command::FAILURE;
            }

            $this->io->success(sprintf('Target set: %s (ID: %s)',
                $targetSet->getTranslation('config')['label'] ?? $targetSet->getName(),
                $targetSet->getId()
            ));

            // Find source sets
            $this->io->section('Finding source custom field sets');
            $sourceSets = [];

            foreach ($sourceValues as $sourceValue) {
                $sourceSet = $this->findCustomFieldSet($sourceValue);

                if (!$sourceSet) {
                    $this->io->error(sprintf('Source custom field set "%s" not found', $sourceValue));
                    return Command::FAILURE;
                }

                // Check if source is same as target
                if ($sourceSet->getId() === $targetSet->getId()) {
                    $this->io->error(sprintf(
                        'Source set "%s" (ID: %s) cannot be the same as target set',
                        $sourceSet->getTranslation('config')['label'] ?? $sourceSet->getName(),
                        $sourceSet->getId()
                    ));
                    return Command::FAILURE;
                }

                $sourceSets[] = $sourceSet;
                $this->io->writeln(sprintf('- %s (ID: %s)',
                    $sourceSet->getTranslation('config')['label'] ?? $sourceSet->getName(),
                    $sourceSet->getId()
                ));
            }

            // Load target set fields
            $targetFields = $this->loadSetFields($targetSet->getId());

            // Analyze and prepare merge plan
            $mergePlan = $this->prepareMergePlan($targetSet, $targetFields, $sourceSets);

            // Display merge plan
            $this->displayMergePlan($mergePlan);

            // Ask for confirmation unless --force is set
            if (!$this->force && !$this->dryRun) {
                if (!$this->confirmMerge()) {
                    $this->io->warning('Merge cancelled by user');
                    return Command::SUCCESS;
                }
            }

            // Execute merge
            if (!$this->dryRun) {
                $this->executeMerge($mergePlan);
                $this->io->success('Custom field sets merged successfully');
            } else {
                $this->io->success('DRY-RUN completed. No changes were made.');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->io->error('Error: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $this->io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function findCustomFieldSet(string $value): ?CustomFieldSetEntity
    {
        // Check if value is ID (32 hex characters)
        if ($this->isHexId($value)) {
            $criteria = new Criteria();
            $criteria->setIds([strtolower($value)]);
            $criteria->addAssociation('customFields');

            $result = $this->customFieldSetRepository->search($criteria, $this->context);
            return $result->first();
        }

        // Search by name
        return $this->findCustomFieldSetByName($value);
    }

    private function isHexId(string $value): bool
    {
        return strlen($value) === 32 && ctype_xdigit($value);
    }

    private function findCustomFieldSetByName(string $name): ?CustomFieldSetEntity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('customFields');

        // Load all sets and filter by name
        $allSets = $this->customFieldSetRepository->search($criteria, $this->context);

        $matchingSets = [];
        foreach ($allSets as $set) {
            $setName = $set->getName();
            $config = $set->getTranslation('config') ?? [];
            $label = $config['label'] ?? null;

            if ($setName === $name || $label === $name) {
                $matchingSets[] = $set;
            }
        }

        if (count($matchingSets) === 0) {
            return null;
        }

        if (count($matchingSets) > 1) {
            $this->io->error(sprintf('Multiple custom field sets found with name "%s":', $name));
            foreach ($matchingSets as $set) {
                $config = $set->getTranslation('config') ?? [];
                $this->io->writeln(sprintf('  - ID: %s, Name: %s, Label: %s',
                    $set->getId(),
                    $set->getName(),
                    $config['label'] ?? 'N/A'
                ));
            }
            throw new \RuntimeException('Multiple sets found. Please use ID instead of name.');
        }

        return $matchingSets[0];
    }

    private function loadSetFields(string $setId): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFieldSetId', $setId));

        $fields = $this->customFieldRepository->search($criteria, $this->context);

        $result = [];
        foreach ($fields as $field) {
            $result[$field->getId()] = $field;
        }

        return $result;
    }

    private function prepareMergePlan(CustomFieldSetEntity $targetSet, array $targetFields, array $sourceSets): array
    {
        $plan = [
            'target' => $targetSet,
            'targetFields' => $targetFields,
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
            $sourceFields = $this->loadSetFields($sourceSet->getId());
            $sourceData = [
                'set' => $sourceSet,
                'fields' => $sourceFields,
                'mergeActions' => [],
                'moveActions' => [],
            ];

            $plan['stats']['totalSourceFields'] += count($sourceFields);

            foreach ($sourceFields as $sourceField) {
                $sourceName = $sourceField->getName();
                $matchingTargetField = $this->findFieldByName($targetFields, $sourceName);

                if ($matchingTargetField) {
                    // This field will be merged (deleted, as target already has it)
                    $sourceData['mergeActions'][] = [
                        'sourceField' => $sourceField,
                        'targetField' => $matchingTargetField,
                    ];

                    $plan['stats']['fieldsToMerge']++;
                    $plan['stats']['fieldsToDelete']++;
                } else {
                    // This field will be moved to target set
                    $sourceData['moveActions'][] = [
                        'field' => $sourceField,
                    ];

                    $plan['stats']['fieldsToMove']++;
                }
            }

            $plan['sources'][] = $sourceData;
            $plan['stats']['setsToDelete']++;
        }

        return $plan;
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

    private function displayMergePlan(array $plan): void
    {
        $this->io->section('Merge Plan');

        $targetConfig = $plan['target']->getTranslation('config') ?? [];
        $this->io->writeln(sprintf('<info>Target Set:</info> %s (ID: %s)',
            $targetConfig['label'] ?? $plan['target']->getName(),
            $plan['target']->getId()
        ));

        $this->io->writeln(sprintf('<info>Current fields in target:</info> %d', count($plan['targetFields'])));
        $this->io->newLine();

        foreach ($plan['sources'] as $sourceData) {
            $sourceSet = $sourceData['set'];
            $sourceConfig = $sourceSet->getTranslation('config') ?? [];

            $this->io->writeln(sprintf('<comment>Source Set:</comment> %s (ID: %s)',
                $sourceConfig['label'] ?? $sourceSet->getName(),
                $sourceSet->getId()
            ));

            $this->io->writeln(sprintf('  Fields: %d total', count($sourceData['fields'])));
            $this->io->writeln(sprintf('  - %d will be skipped (already exist in target)', count($sourceData['mergeActions'])));
            $this->io->writeln(sprintf('  - %d will be moved to target set', count($sourceData['moveActions'])));

            if (!empty($sourceData['mergeActions'])) {
                $this->io->writeln('  Skipped fields (already exist):');
                foreach ($sourceData['mergeActions'] as $action) {
                    $this->io->writeln(sprintf('    • "%s" (target already has this field)',
                        $action['sourceField']->getName()
                    ));
                }
            }

            if (!empty($sourceData['moveActions'])) {
                $this->io->writeln('  Fields to move:');
                foreach ($sourceData['moveActions'] as $action) {
                    $this->io->writeln(sprintf('    • "%s"',
                        $action['field']->getName()
                    ));
                }
            }

            $this->io->newLine();
        }

        $this->io->section('Summary');
        $stats = $plan['stats'];
        $this->io->writeln(sprintf('Total source fields: %d', $stats['totalSourceFields']));
        $this->io->writeln(sprintf('Fields to skip (duplicates): %d', $stats['fieldsToMerge']));
        $this->io->writeln(sprintf('Fields to move: %d', $stats['fieldsToMove']));
        $this->io->writeln(sprintf('Fields to delete: %d', $stats['fieldsToDelete']));
        $this->io->writeln(sprintf('Sets to delete: %d', $stats['setsToDelete']));
        $this->io->newLine();

        $this->io->note('Note: Custom field values in entities (products, etc.) are stored with field names as keys. ' .
            'Duplicate fields will be deleted, but their values in entities will remain accessible through the target field with the same name.');
    }

    private function confirmMerge(): bool
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            '<question>Do you want to proceed with the merge? [y/N]</question> ',
            false
        );

        return $helper->ask($this->io->getInput(), $this->io->getOutput(), $question);
    }

    private function executeMerge(array $plan): void
    {
        $this->io->section('Executing Merge');

        $this->connection->beginTransaction();

        try {
            foreach ($plan['sources'] as $sourceData) {
                $sourceSet = $sourceData['set'];
                $this->io->writeln(sprintf('Processing source set: %s',
                    ($sourceSet->getTranslation('config') ?? [])['label'] ?? $sourceSet->getName()
                ));

                // Delete duplicate fields (that already exist in target)
                foreach ($sourceData['mergeActions'] as $action) {
                    $this->deleteCustomField($action['sourceField']);
                }

                // Move unique fields to target set
                foreach ($sourceData['moveActions'] as $action) {
                    $this->moveCustomField($action['field'], $plan['target']->getId());
                }

                // Delete source set relations
                $this->deleteCustomFieldSetRelations($sourceSet->getId());

                // Delete source set
                $this->deleteCustomFieldSet($sourceSet->getId());
            }

            $this->connection->commit();
            $this->io->success('All changes committed successfully');

        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw new \RuntimeException('Merge failed and was rolled back: ' . $e->getMessage(), 0, $e);
        }
    }

    private function deleteCustomField(CustomFieldEntity $field): void
    {
        $fieldIdBin = hex2bin($field->getId());

        // Delete field
        $this->connection->executeStatement(
            'DELETE FROM custom_field WHERE id = :fieldId',
            ['fieldId' => $fieldIdBin]
        );

        $this->io->writeln(sprintf('  ✓ Deleted duplicate field "%s"', $field->getName()));
    }

    private function moveCustomField(CustomFieldEntity $field, string $targetSetId): void
    {
        $fieldIdBin = hex2bin($field->getId());
        $targetSetIdBin = hex2bin($targetSetId);

        $this->connection->executeStatement(
            'UPDATE custom_field SET custom_field_set_id = :setId WHERE id = :fieldId',
            [
                'setId' => $targetSetIdBin,
                'fieldId' => $fieldIdBin,
            ]
        );

        $this->io->writeln(sprintf('  ✓ Moved field "%s" to target set', $field->getName()));
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

        // Delete translations
        $this->connection->executeStatement(
            'DELETE FROM custom_field_set_translation WHERE custom_field_set_id = :setId',
            ['setId' => $setIdBin]
        );

        // Delete set
        $this->connection->executeStatement(
            'DELETE FROM custom_field_set WHERE id = :setId',
            ['setId' => $setIdBin]
        );

        $this->io->writeln(sprintf('  ✓ Deleted source set (ID: %s)', $setId));
    }
}
