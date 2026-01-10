<?php declare(strict_types=1);

/**
 * Description:
 *   Regenerates variant product names based on parent name and option values.
 *   Useful for bulk regeneration after import or configuration changes.
 *
 * Usage:
 *   bin/console artiss:variant:regenerate-names [options]
 *
 * Options:
 *   --batch-size=N   Number of variants to process in one batch (default: 100)
 *   --dry-run        Show what would be updated without making changes
 *
 * Example:
 *   bin/console artiss:variant:regenerate-names --batch-size=50 --dry-run
 */

namespace ArtissStorefront\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'artiss:variant:regenerate-names',
    description: 'Regenerates variant product names based on parent name and option values'
)]
class RegenerateVariantNamesCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $languageRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Number of variants to process in one batch', 100)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be updated without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        $batchSize = (int) $input->getOption('batch-size');
        $dryRun = $input->getOption('dry-run');

        $io->title('Variant Name Regeneration');

        if ($dryRun) {
            $io->warning('DRY RUN MODE - No changes will be made');
        }

        $criteria = new Criteria();
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('parentId', null)
        ]));
        $criteria->setLimit($batchSize);
        $criteria->addAssociation('options.group');
        $criteria->addAssociation('options.translations');
        $criteria->addAssociation('translations');

        $totalVariants = $this->productRepository->search($criteria, $context)->getTotal();
        $io->info(sprintf('Found %d variant(s) to process', $totalVariants));

        $processed = 0;
        $updated = 0;
        $offset = 0;

        $progressBar = $io->createProgressBar($totalVariants);
        $progressBar->start();

        while (true) {
            $criteria->setOffset($offset);
            $variants = $this->productRepository->search($criteria, $context);

            if ($variants->count() === 0) {
                break;
            }

            foreach ($variants as $variant) {
                if (!$dryRun) {
                    $this->updateVariantName($variant, $context);
                }

                $updated++;
                $processed++;
                $progressBar->advance();
            }

            $offset += $batchSize;

            if ($processed >= $totalVariants) {
                break;
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->success(sprintf(
            'Processed %d variant(s): %d updated',
            $processed,
            $updated
        ));

        if ($dryRun) {
            $io->note('This was a dry run. Run without --dry-run to apply changes.');
        }

        return Command::SUCCESS;
    }

    private function updateVariantName($variant, Context $context): void
    {
        $parentId = $variant->getParentId();
        if ($parentId === null) {
            return;
        }

        $variantName = $this->generateVariantName($variant, $parentId, $context);
        if ($variantName === null) {
            return;
        }

        $currentName = $variant->getTranslated()['name'] ?? $variant->getName();
        if ($currentName === $variantName) {
            return;
        }

        $languages = $this->languageRepository->search(new Criteria(), $context);
        $translations = [];

        foreach ($languages as $language) {
            $translations[] = [
                'languageId' => $language->getId(),
                'name' => $variantName,
            ];
        }

        if (!empty($translations)) {
            $this->productRepository->update([
                [
                    'id' => $variant->getId(),
                    'translations' => $translations,
                ]
            ], $context);
        }
    }

    private function generateVariantName($variant, string $parentId, Context $context): ?string
    {
        $options = $variant->getOptions();
        if ($options === null || $options->count() === 0) {
            return null;
        }

        $parentCriteria = new Criteria([$parentId]);
        $parent = $this->productRepository->search($parentCriteria, $context)->first();

        if ($parent === null) {
            return null;
        }

        $parentName = $parent->getTranslated()['name'] ?? $parent->getName();
        if ($parentName === null) {
            return null;
        }

        $optionsByGroup = [];

        foreach ($options as $option) {
            $group = $option->getGroup();
            if ($group === null) {
                continue;
            }

            $groupTranslated = $group->getTranslated();
            $groupName = $groupTranslated['name'] ?? $group->getName() ?? '';
            $groupPosition = (int) ($groupTranslated['position'] ?? $group->getPosition() ?? 0);

            $optionTranslated = $option->getTranslated();
            $optionName = $optionTranslated['name'] ?? $option->getName();
            $optionPosition = (int) ($optionTranslated['position'] ?? $option->getPosition() ?? 0);

            if ($optionName === null || $optionName === '') {
                continue;
            }

            if (!isset($optionsByGroup[$groupName])) {
                $optionsByGroup[$groupName] = [
                    'position' => $groupPosition,
                    'options' => []
                ];
            }

            $optionsByGroup[$groupName]['options'][] = [
                'name' => $optionName,
                'position' => $optionPosition
            ];
        }

        if (empty($optionsByGroup)) {
            return null;
        }

        uasort($optionsByGroup, fn($a, $b) => $a['position'] <=> $b['position']);

        $optionNames = [];
        foreach ($optionsByGroup as &$groupData) {
            usort($groupData['options'], fn($a, $b) => $a['position'] <=> $b['position']);
            foreach ($groupData['options'] as $optionData) {
                $optionNames[] = $optionData['name'];
            }
        }
        unset($groupData);

        if (empty($optionNames)) {
            return null;
        }

        $variantName = $parentName . ' – ' . implode(' – ', $optionNames);

        if (mb_strlen($variantName) > 255) {
            $variantName = mb_substr($variantName, 0, 252) . '...';
        }

        return $variantName;
    }
}
