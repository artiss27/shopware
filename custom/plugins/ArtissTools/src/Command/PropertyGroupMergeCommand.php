<?php declare(strict_types=1);

namespace ArtissTools\Command;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
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
 *   Merges multiple property groups into a single target group.
 *   Consolidates duplicate options, moves unique options to target,
 *   updates all product references, and deletes source groups.
 *
 * Usage:
 *   bin/console artiss:property-group:merge [options]
 *
 * Options:
 *   --target=VALUE, -t VALUE    Target property group (ID or name)
 *   --sources=VALUE, -s VALUE   Source property groups separated by comma (ID or name)
 *   --locale=VALUE, -l VALUE    Locale for name search (e.g., uk-UA, ru-RU, en-GB)
 *   --dry-run                   Simulate the merge without making changes
 *   --force, -f                 Skip confirmation prompt
 *
 * Example:
 *   bin/console artiss:property-group:merge --target="Lighting" --sources="LED Features,Power Options" --locale=en-GB --dry-run --force
 */
#[AsCommand(
    name: 'artiss:property-group:merge',
    description: 'Merge multiple property groups into a single target group'
)]
class PropertyGroupMergeCommand extends Command
{
    private SymfonyStyle $io;
    private Context $context;
    private ?string $locale = null;
    private bool $dryRun = false;
    private bool $force = false;

    public function __construct(
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $propertyGroupOptionRepository,
        private readonly Connection $connection
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'Target property group (ID or name)')
            ->addOption('sources', 's', InputOption::VALUE_REQUIRED, 'Source property groups separated by comma (ID or name)')
            ->addOption('locale', 'l', InputOption::VALUE_OPTIONAL, 'Locale for name search (e.g., uk-UA, ru-RU, en-GB)', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate the merge without making changes')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt')
            ->setHelp(<<<'EOF'
The <info>artiss:property-group:merge</info> command merges multiple property groups into a single target group.

<comment>Purpose:</comment>
This command consolidates duplicate or similar property groups by:
- Merging options with matching names into existing target options
- Moving unique options to the target group
- Updating all product property and configurator setting references
- Deleting source groups and duplicate options after successful merge

<comment>Identification:</comment>
Groups can be identified by:
- <info>ID</info> (32 hex characters): 0011a6aec00309f0c18a8379023a5a14
- <info>Name</info> (translated name): "Lighting" or "Освітлення"

<comment>Usage Examples:</comment>

1. <info>Dry-run mode (recommended first):</info>
   <comment>bin/console artiss:property-group:merge \
     --target="0011a6aec00309f0c18a8379023a5a14" \
     --sources="010477c2a551698b636192c75cf535a8,025f96ebcc1d5426fde4af8fea4dd650" \
     --dry-run</comment>

2. <info>Merge by name:</info>
   <comment>bin/console artiss:property-group:merge \
     --target="Lighting" \
     --sources="LED Features,Power Options"</comment>

3. <info>Merge with locale specification:</info>
   <comment>bin/console artiss:property-group:merge \
     --target="Освітлення" \
     --sources="Захист від перегріву" \
     --locale=uk-UA</comment>

4. <info>Force mode (skip confirmation):</info>
   <comment>bin/console artiss:property-group:merge \
     --target="0011a6aec00309f0c18a8379023a5a14" \
     --sources="010477c2a551698b636192c75cf535a8" \
     --force</comment>

5. <info>Multiple sources:</info>
   <comment>bin/console artiss:property-group:merge \
     --target="Main Features" \
     --sources="Feature Set 1,Feature Set 2,Feature Set 3"</comment>

<comment>Merge Logic:</comment>
For each source group option:
- If an option with the same name exists in target → merge references and delete duplicate
- If option is unique → move to target group (keeping all product references)

After processing all sources:
- All product_property records point to target group options
- All product_configurator_setting records updated
- Source groups are deleted
- Duplicate options are removed

<comment>Safety:</comment>
- All operations run in a transaction (rollback on error)
- Use --dry-run to preview changes before applying
- Confirmation prompt by default (override with --force)

<comment>Important Notes:</comment>
- Target and source groups cannot overlap (same group cannot be both)
- Name search must return exactly one group (use ID if multiple matches)
- All database changes are atomic (all succeed or all rollback)
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

        $this->io->title('Property Group Merge Command');

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
            $this->io->error('At least one source group must be provided');
            return Command::FAILURE;
        }

        try {
            // Find target group
            $this->io->section('Finding target group');
            $targetGroup = $this->findPropertyGroup($targetValue);

            if (!$targetGroup) {
                $this->io->error(sprintf('Target group "%s" not found', $targetValue));
                return Command::FAILURE;
            }

            $this->io->success(sprintf('Target group: %s (ID: %s)',
                $targetGroup->getTranslation('name') ?? $targetGroup->getName(),
                $targetGroup->getId()
            ));

            // Find source groups
            $this->io->section('Finding source groups');
            $sourceGroups = [];

            foreach ($sourceValues as $sourceValue) {
                $sourceGroup = $this->findPropertyGroup($sourceValue);

                if (!$sourceGroup) {
                    $this->io->error(sprintf('Source group "%s" not found', $sourceValue));
                    return Command::FAILURE;
                }

                // Check if source is same as target
                if ($sourceGroup->getId() === $targetGroup->getId()) {
                    $this->io->error(sprintf(
                        'Source group "%s" (ID: %s) cannot be the same as target group',
                        $sourceGroup->getTranslation('name') ?? $sourceGroup->getName(),
                        $sourceGroup->getId()
                    ));
                    return Command::FAILURE;
                }

                $sourceGroups[] = $sourceGroup;
                $this->io->writeln(sprintf('- %s (ID: %s)',
                    $sourceGroup->getTranslation('name') ?? $sourceGroup->getName(),
                    $sourceGroup->getId()
                ));
            }

            // Load target group options
            $targetOptions = $this->loadGroupOptions($targetGroup->getId());

            // Analyze and prepare merge plan
            $mergePlan = $this->prepareMergePlan($targetGroup, $targetOptions, $sourceGroups);

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
                $this->io->success('Property groups merged successfully');
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

    private function findPropertyGroup(string $value): ?PropertyGroupEntity
    {
        // Check if value is ID (32 hex characters)
        if ($this->isHexId($value)) {
            $criteria = new Criteria();
            $criteria->setIds([strtolower($value)]);
            $criteria->addAssociation('options');

            $result = $this->propertyGroupRepository->search($criteria, $this->context);
            return $result->first();
        }

        // Search by name
        return $this->findPropertyGroupByName($value);
    }

    private function isHexId(string $value): bool
    {
        return strlen($value) === 32 && ctype_xdigit($value);
    }

    private function findPropertyGroupByName(string $name): ?PropertyGroupEntity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('options');

        // Load all groups and filter by translated name
        $allGroups = $this->propertyGroupRepository->search($criteria, $this->context);

        $matchingGroups = [];
        foreach ($allGroups as $group) {
            $translatedName = $group->getTranslation('name');
            $defaultName = $group->getName();

            if ($translatedName === $name || $defaultName === $name) {
                $matchingGroups[] = $group;
            }
        }

        if (count($matchingGroups) === 0) {
            return null;
        }

        if (count($matchingGroups) > 1) {
            $this->io->error(sprintf('Multiple groups found with name "%s":', $name));
            foreach ($matchingGroups as $group) {
                $this->io->writeln(sprintf('  - ID: %s, Name: %s',
                    $group->getId(),
                    $group->getTranslation('name') ?? $group->getName()
                ));
            }
            throw new \RuntimeException('Multiple groups found. Please use ID instead of name.');
        }

        return $matchingGroups[0];
    }

    private function loadGroupOptions(string $groupId): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('groupId', $groupId));
        $criteria->addAssociation('translations');

        $options = $this->propertyGroupOptionRepository->search($criteria, $this->context);

        $result = [];
        foreach ($options as $option) {
            $result[$option->getId()] = $option;
        }

        return $result;
    }

    private function prepareMergePlan(PropertyGroupEntity $targetGroup, array $targetOptions, array $sourceGroups): array
    {
        $plan = [
            'target' => $targetGroup,
            'targetOptions' => $targetOptions,
            'sources' => [],
            'stats' => [
                'totalSourceOptions' => 0,
                'optionsToMerge' => 0,
                'optionsToMove' => 0,
                'optionsToDelete' => 0,
                'productPropertyUpdates' => 0,
                'configuratorSettingUpdates' => 0,
                'groupsToDelete' => 0,
            ],
        ];

        foreach ($sourceGroups as $sourceGroup) {
            $sourceOptions = $this->loadGroupOptions($sourceGroup->getId());
            $sourceData = [
                'group' => $sourceGroup,
                'options' => $sourceOptions,
                'mergeActions' => [],
                'moveActions' => [],
            ];

            $plan['stats']['totalSourceOptions'] += count($sourceOptions);

            foreach ($sourceOptions as $sourceOption) {
                $sourceName = $this->getOptionName($sourceOption);
                $matchingTargetOption = $this->findOptionByName($targetOptions, $sourceName);

                if ($matchingTargetOption) {
                    // This option will be merged into existing target option
                    $affectedRecords = $this->countAffectedRecords($sourceOption->getId());

                    $sourceData['mergeActions'][] = [
                        'sourceOption' => $sourceOption,
                        'targetOption' => $matchingTargetOption,
                        'affectedRecords' => $affectedRecords,
                    ];

                    $plan['stats']['optionsToMerge']++;
                    $plan['stats']['optionsToDelete']++;
                    $plan['stats']['productPropertyUpdates'] += $affectedRecords['productProperty'];
                    $plan['stats']['configuratorSettingUpdates'] += $affectedRecords['configuratorSetting'];
                } else {
                    // This option will be moved to target group
                    $sourceData['moveActions'][] = [
                        'option' => $sourceOption,
                    ];

                    $plan['stats']['optionsToMove']++;
                }
            }

            $plan['sources'][] = $sourceData;
            $plan['stats']['groupsToDelete']++;
        }

        return $plan;
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
        $productPropertyCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM product_property WHERE property_group_option_id = :optionId',
            ['optionId' => hex2bin($optionId)]
        );

        $configuratorSettingCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM product_configurator_setting WHERE property_group_option_id = :optionId',
            ['optionId' => hex2bin($optionId)]
        );

        return [
            'productProperty' => $productPropertyCount,
            'configuratorSetting' => $configuratorSettingCount,
        ];
    }

    private function displayMergePlan(array $plan): void
    {
        $this->io->section('Merge Plan');

        $this->io->writeln(sprintf('<info>Target Group:</info> %s (ID: %s)',
            $plan['target']->getTranslation('name') ?? $plan['target']->getName(),
            $plan['target']->getId()
        ));

        $this->io->writeln(sprintf('<info>Current options in target:</info> %d', count($plan['targetOptions'])));
        $this->io->newLine();

        foreach ($plan['sources'] as $sourceData) {
            $sourceGroup = $sourceData['group'];
            $this->io->writeln(sprintf('<comment>Source Group:</comment> %s (ID: %s)',
                $sourceGroup->getTranslation('name') ?? $sourceGroup->getName(),
                $sourceGroup->getId()
            ));

            $this->io->writeln(sprintf('  Options: %d total', count($sourceData['options'])));
            $this->io->writeln(sprintf('  - %d will be merged with existing target options', count($sourceData['mergeActions'])));
            $this->io->writeln(sprintf('  - %d will be moved to target group as new options', count($sourceData['moveActions'])));

            if (!empty($sourceData['mergeActions'])) {
                $this->io->writeln('  Merge details:');
                foreach ($sourceData['mergeActions'] as $action) {
                    $this->io->writeln(sprintf('    • "%s" → existing option (affects %d product properties, %d configurator settings)',
                        $this->getOptionName($action['sourceOption']),
                        $action['affectedRecords']['productProperty'],
                        $action['affectedRecords']['configuratorSetting']
                    ));
                }
            }

            $this->io->newLine();
        }

        $this->io->section('Summary');
        $stats = $plan['stats'];
        $this->io->writeln(sprintf('Total source options: %d', $stats['totalSourceOptions']));
        $this->io->writeln(sprintf('Options to merge: %d', $stats['optionsToMerge']));
        $this->io->writeln(sprintf('Options to move: %d', $stats['optionsToMove']));
        $this->io->writeln(sprintf('Options to delete: %d', $stats['optionsToDelete']));
        $this->io->writeln(sprintf('Product property updates: %d', $stats['productPropertyUpdates']));
        $this->io->writeln(sprintf('Configurator setting updates: %d', $stats['configuratorSettingUpdates']));
        $this->io->writeln(sprintf('Groups to delete: %d', $stats['groupsToDelete']));
        $this->io->newLine();
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
                $sourceGroup = $sourceData['group'];
                $this->io->writeln(sprintf('Processing source group: %s',
                    $sourceGroup->getTranslation('name') ?? $sourceGroup->getName()
                ));

                // Process merge actions (update references and delete options)
                foreach ($sourceData['mergeActions'] as $action) {
                    $this->mergeOption($action['sourceOption'], $action['targetOption']);
                }

                // Process move actions (change group_id)
                foreach ($sourceData['moveActions'] as $action) {
                    $this->moveOption($action['option'], $plan['target']->getId());
                }

                // Delete source group
                $this->deletePropertyGroup($sourceGroup->getId());
            }

            $this->connection->commit();
            $this->io->success('All changes committed successfully');

        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw new \RuntimeException('Merge failed and was rolled back: ' . $e->getMessage(), 0, $e);
        }
    }

    private function mergeOption(PropertyGroupOptionEntity $sourceOption, PropertyGroupOptionEntity $targetOption): void
    {
        $sourceIdBin = hex2bin($sourceOption->getId());
        $targetIdBin = hex2bin($targetOption->getId());

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

        $this->io->writeln(sprintf('  ✓ Merged option "%s" into existing target option',
            $this->getOptionName($sourceOption)
        ));
    }

    private function moveOption(PropertyGroupOptionEntity $option, string $targetGroupId): void
    {
        $optionIdBin = hex2bin($option->getId());
        $targetGroupIdBin = hex2bin($targetGroupId);

        $this->connection->executeStatement(
            'UPDATE property_group_option SET property_group_id = :groupId WHERE id = :optionId',
            [
                'groupId' => $targetGroupIdBin,
                'optionId' => $optionIdBin,
            ]
        );

        $this->io->writeln(sprintf('  ✓ Moved option "%s" to target group',
            $this->getOptionName($option)
        ));
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

        $this->io->writeln(sprintf('  ✓ Deleted source group (ID: %s)', $groupId));
    }
}
