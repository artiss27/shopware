<?php declare(strict_types=1);

namespace ArtissTools\Command;

use ArtissTools\Service\PropertyCleanupService;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Description:
 *   Finds and deletes unused property group options, empty property groups,
 *   and optionally unused custom fields and custom field sets.
 *
 * Usage:
 *   bin/console artiss:properties:cleanup-unused [options]
 *
 * Options:
 *   --mode=VALUE                Mode: properties, custom-fields, or all (default: properties)
 *   --dry-run                   Analysis only, no deletion
 *   --only-list                 Output list of candidates without deletion
 *   --include-empty-groups      Delete empty property groups (default: true)
 *   --include-custom-fields     Include custom fields in "all" mode
 *   --force                     Delete without confirmation
 *
 * Example:
 *   bin/console artiss:properties:cleanup-unused --mode=all --dry-run --include-empty-groups --include-custom-fields --force
 */
#[AsCommand(
    name: 'artiss:properties:cleanup-unused',
    description: 'Find and delete unused property group options and custom fields'
)]
class PropertyCleanupCommand extends Command
{
    private SymfonyStyle $io;
    private Context $context;
    private bool $dryRun = false;
    private bool $onlyList = false;
    private bool $force = false;
    private bool $includeEmptyGroups = true;

    public function __construct(
        private readonly PropertyCleanupService $cleanupService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mode', null, InputOption::VALUE_OPTIONAL, 'Mode: properties, custom-fields, or all', 'properties')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analysis only, no deletion')
            ->addOption('only-list', null, InputOption::VALUE_NONE, 'Output list of candidates without deletion')
            ->addOption('include-empty-groups', null, InputOption::VALUE_OPTIONAL, 'Delete empty property groups', true)
            ->addOption('include-custom-fields', null, InputOption::VALUE_NONE, 'Include custom fields in "all" mode')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Delete without confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->context = Context::createDefaultContext();
        $this->dryRun = $input->getOption('dry-run');
        $this->onlyList = $input->getOption('only-list');
        $this->force = $input->getOption('force');
        $this->includeEmptyGroups = filter_var($input->getOption('include-empty-groups'), FILTER_VALIDATE_BOOLEAN);

        $mode = $input->getOption('mode');

        $this->io->title('Property & Custom Fields Cleanup');

        if ($this->dryRun) {
            $this->io->warning('DRY-RUN MODE: No changes will be made to the database');
        }

        if ($this->onlyList) {
            $this->io->note('LIST-ONLY MODE: Showing candidates without deletion');
        }

        try {
            switch ($mode) {
                case 'properties':
                    return $this->processProperties();
                case 'custom-fields':
                    return $this->processCustomFields();
                case 'all':
                    $includeCustomFields = $input->getOption('include-custom-fields');
                    $result = $this->processProperties();
                    if ($result === Command::SUCCESS && $includeCustomFields) {
                        $result = $this->processCustomFields();
                    }
                    return $result;
                default:
                    $this->io->error(sprintf('Invalid mode: %s. Use: properties, custom-fields, or all', $mode));
                    return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->io->error('Error: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $this->io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function processProperties(): int
    {
        $this->io->section('Scanning Property Groups');

        $unusedData = $this->cleanupService->findUnusedPropertyOptions($this->context);

        if (empty($unusedData)) {
            $this->io->success('No unused property options found!');
            return Command::SUCCESS;
        }

        // Display results
        $this->displayUnusedProperties($unusedData);

        if ($this->onlyList) {
            return Command::SUCCESS;
        }

        // Interactive mode or force mode
        $optionsToDelete = $this->selectOptionsToDelete($unusedData);

        if (empty($optionsToDelete)) {
            $this->io->info('No options selected for deletion');
            return Command::SUCCESS;
        }

        // Confirmation
        if (!$this->force && !$this->dryRun) {
            if (!$this->confirmDeletion(count($optionsToDelete), 'property options')) {
                $this->io->warning('Deletion cancelled by user');
                return Command::SUCCESS;
            }
        }

        // Execute deletion
        if (!$this->dryRun) {
            $result = $this->cleanupService->deletePropertyOptions($optionsToDelete, $this->includeEmptyGroups);

            $this->io->success(sprintf(
                'Deleted %d property options',
                count($result['deletedOptions'])
            ));

            if (!empty($result['deletedGroups'])) {
                $this->io->success(sprintf(
                    'Deleted %d empty property groups',
                    count($result['deletedGroups'])
                ));
            }
        } else {
            $this->io->success(sprintf(
                'DRY-RUN: Would delete %d property options',
                count($optionsToDelete)
            ));
        }

        return Command::SUCCESS;
    }

    private function processCustomFields(): int
    {
        $this->io->section('Scanning Custom Fields');

        $unusedData = $this->cleanupService->findUnusedCustomFields($this->context);

        if (empty($unusedData)) {
            $this->io->success('No unused custom fields found!');
            return Command::SUCCESS;
        }

        // Display results
        $this->displayUnusedCustomFields($unusedData);

        if ($this->onlyList) {
            return Command::SUCCESS;
        }

        // Interactive mode or force mode
        [$fieldsToDelete, $setsToDelete] = $this->selectCustomFieldsToDelete($unusedData);

        if (empty($fieldsToDelete) && empty($setsToDelete)) {
            $this->io->info('No fields selected for deletion');
            return Command::SUCCESS;
        }

        // Confirmation
        if (!$this->force && !$this->dryRun) {
            $totalItems = count($fieldsToDelete) + count($setsToDelete);
            if (!$this->confirmDeletion($totalItems, 'custom fields/sets')) {
                $this->io->warning('Deletion cancelled by user');
                return Command::SUCCESS;
            }
        }

        // Execute deletion
        if (!$this->dryRun) {
            $result = $this->cleanupService->deleteCustomFields($fieldsToDelete, $setsToDelete);

            $this->io->success(sprintf(
                'Deleted %d custom fields',
                count($result['deletedFields'])
            ));

            if (!empty($result['deletedSets'])) {
                $this->io->success(sprintf(
                    'Deleted %d custom field sets',
                    count($result['deletedSets'])
                ));
            }
        } else {
            $this->io->success(sprintf(
                'DRY-RUN: Would delete %d custom fields and %d sets',
                count($fieldsToDelete),
                count($setsToDelete)
            ));
        }

        return Command::SUCCESS;
    }

    private function displayUnusedProperties(array $unusedData): void
    {
        $totalOptions = 0;

        foreach ($unusedData as $groupData) {
            $this->io->section(sprintf(
                'Group: %s (ID: %s)',
                $groupData['groupName'],
                $groupData['groupId']
            ));

            $table = new Table($this->io);
            $table->setHeaders(['Option ID', 'Option Name']);

            foreach ($groupData['unusedOptions'] as $option) {
                $table->addRow([
                    $option['optionId'],
                    $option['optionName'],
                ]);
                $totalOptions++;
            }

            $table->render();
        }

        $this->io->note(sprintf('Total unused options: %d', $totalOptions));
    }

    private function displayUnusedCustomFields(array $unusedData): void
    {
        $totalFields = 0;

        foreach ($unusedData as $setData) {
            $isEmpty = $setData['isEmpty'] ?? false;
            $relations = !empty($setData['relations']) ? implode(', ', $setData['relations']) : 'none';

            $this->io->section(sprintf(
                'Set: %s (ID: %s) [Relations: %s]%s',
                $setData['setName'],
                $setData['setId'],
                $relations,
                $isEmpty ? ' [EMPTY - will be deleted]' : ''
            ));

            if (!empty($setData['unusedFields'])) {
                $table = new Table($this->io);
                $table->setHeaders(['Field ID', 'Field Name', 'Label', 'Type']);

                foreach ($setData['unusedFields'] as $field) {
                    $table->addRow([
                        $field['fieldId'],
                        $field['fieldName'],
                        $field['fieldLabel'],
                        $field['fieldType'],
                    ]);
                    $totalFields++;
                }

                $table->render();
            }
        }

        $this->io->note(sprintf('Total unused fields: %d', $totalFields));
    }

    private function selectOptionsToDelete(array $unusedData): array
    {
        if ($this->force) {
            // Select all options
            $allOptions = [];
            foreach ($unusedData as $groupData) {
                foreach ($groupData['unusedOptions'] as $option) {
                    $allOptions[] = $option['optionId'];
                }
            }
            return $allOptions;
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $selectedOptions = [];

        foreach ($unusedData as $groupData) {
            $this->io->writeln('');
            $this->io->writeln(sprintf(
                '<comment>Group: %s</comment>',
                $groupData['groupName']
            ));

            // Ask for each option in this group
            foreach ($groupData['unusedOptions'] as $option) {
                $question = new ConfirmationQuestion(
                    sprintf('  Delete option "%s"? [y/N] ', $option['optionName']),
                    false
                );

                if ($helper->ask($this->io->getInput(), $this->io->getOutput(), $question)) {
                    $selectedOptions[] = $option['optionId'];
                }
            }
        }

        return $selectedOptions;
    }

    private function selectCustomFieldsToDelete(array $unusedData): array
    {
        if ($this->force) {
            // Select all fields and empty sets
            $allFields = [];
            $allSets = [];

            foreach ($unusedData as $setData) {
                foreach ($setData['unusedFields'] as $field) {
                    $allFields[] = $field['fieldId'];
                }

                if ($setData['isEmpty'] ?? false) {
                    $allSets[] = $setData['setId'];
                }
            }

            return [$allFields, $allSets];
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $selectedFields = [];
        $selectedSets = [];

        foreach ($unusedData as $setData) {
            $this->io->writeln('');
            $this->io->writeln(sprintf(
                '<comment>Set: %s</comment>',
                $setData['setName']
            ));

            // Ask for each field in this set
            foreach ($setData['unusedFields'] as $field) {
                $question = new ConfirmationQuestion(
                    sprintf('  Delete field "%s" (%s)? [y/N] ', $field['fieldLabel'], $field['fieldName']),
                    false
                );

                if ($helper->ask($this->io->getInput(), $this->io->getOutput(), $question)) {
                    $selectedFields[] = $field['fieldId'];
                }
            }

            // Ask about deleting the set if it's empty
            if ($setData['isEmpty'] ?? false) {
                $question = new ConfirmationQuestion(
                    sprintf('  Delete empty set "%s"? [y/N] ', $setData['setName']),
                    false
                );

                if ($helper->ask($this->io->getInput(), $this->io->getOutput(), $question)) {
                    $selectedSets[] = $setData['setId'];
                }
            }
        }

        return [$selectedFields, $selectedSets];
    }

    private function confirmDeletion(int $count, string $type): bool
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            sprintf(
                '<question>Are you sure you want to delete %d %s? This action cannot be undone! [y/N]</question> ',
                $count,
                $type
            ),
            false
        );

        return $helper->ask($this->io->getInput(), $this->io->getOutput(), $question);
    }
}
