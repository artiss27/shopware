<?php declare(strict_types=1);

/**
 * Description:
 *   Recursively assigns CMS layout to categories based on product presence.
 *   Processes all child categories of specified parent and assigns layout
 *   depending on whether category has direct products or not.
 *
 * Usage:
 *   bin/console artiss:category:assign-layout [options]
 *
 * Options:
 *   --parent-category=ID, -p ID   Parent category ID to start from (required)
 *   --layout=ID, -l ID            CMS layout ID to assign (required)
 *   --has-products                Assign layout only to categories WITH direct products
 *                                 (if omitted, assigns to categories WITHOUT products)
 *   --dry-run                     Show what would be changed without actually updating
 *
 * Example:
 *   bin/console artiss:category:assign-layout --parent-category=bddb333ca5a76567039086737011aa72 --layout=019b85abda167e70bb0231bd59462d83 --has-products --dry-run
 */

namespace ArtissTools\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'artiss:category:assign-layout',
    description: 'Recursively assigns CMS layout to categories based on product presence'
)]
class CategoryLayoutAssignCommand extends Command
{
    private EntityRepository $categoryRepository;
    private EntityRepository $productRepository;

    public function __construct(
        EntityRepository $categoryRepository,
        EntityRepository $productRepository
    ) {
        parent::__construct();
        $this->categoryRepository = $categoryRepository;
        $this->productRepository = $productRepository;
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'parent-category',
                'p',
                InputOption::VALUE_REQUIRED,
                'Parent category ID to start from'
            )
            ->addOption(
                'layout',
                'l',
                InputOption::VALUE_REQUIRED,
                'CMS layout ID to assign'
            )
            ->addOption(
                'has-products',
                null,
                InputOption::VALUE_NONE,
                'Assign layout only to categories WITH direct products (if omitted, assigns to categories WITHOUT products)'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be changed without actually updating'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        $parentCategoryId = $input->getOption('parent-category');
        $layoutId = $input->getOption('layout');
        $requireProducts = $input->getOption('has-products');
        $dryRun = $input->getOption('dry-run');

        if (!$parentCategoryId) {
            $io->error('Option --parent-category is required');
            return Command::FAILURE;
        }

        if (!$layoutId) {
            $io->error('Option --layout is required');
            return Command::FAILURE;
        }

        $io->title('Category Layout Assignment');
        $io->text([
            sprintf('Parent Category ID: %s', $parentCategoryId),
            sprintf('Layout ID: %s', $layoutId),
            sprintf('Mode: Assign to categories %s products', $requireProducts ? 'WITH' : 'WITHOUT'),
            sprintf('Dry Run: %s', $dryRun ? 'Yes' : 'No'),
        ]);

        $categoriesProcessed = 0;
        $categoriesUpdated = 0;
        $categoriesSkipped = 0;

        $allChildCategories = $this->getAllChildCategories($parentCategoryId, $context);

        $io->progressStart(count($allChildCategories));

        foreach ($allChildCategories as $category) {
            $categoriesProcessed++;

            $hasProducts = $this->categoryHasDirectProducts($category['id'], $context);

            $shouldAssign = $requireProducts ? $hasProducts : !$hasProducts;

            if ($shouldAssign) {
                if (!$dryRun) {
                    $this->categoryRepository->update([
                        [
                            'id' => $category['id'],
                            'cmsPageId' => $layoutId,
                        ]
                    ], $context);
                }

                $categoriesUpdated++;
                $io->text(sprintf(
                    '[%s] %s - %s (products: %s)',
                    $dryRun ? 'DRY RUN' : 'UPDATED',
                    $category['id'],
                    $category['name'],
                    $hasProducts ? 'yes' : 'no'
                ));
            } else {
                $categoriesSkipped++;
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        $io->success([
            sprintf('Total categories processed: %d', $categoriesProcessed),
            sprintf('Categories %s: %d', $dryRun ? 'would be updated' : 'updated', $categoriesUpdated),
            sprintf('Categories skipped: %d', $categoriesSkipped),
        ]);

        return Command::SUCCESS;
    }

    private function getAllChildCategories(string $parentId, Context $context): array
    {
        $children = [];
        $this->collectChildCategories($parentId, $context, $children);
        return $children;
    }

    private function collectChildCategories(string $parentId, Context $context, array &$children): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', $parentId));

        $result = $this->categoryRepository->search($criteria, $context);

        foreach ($result->getElements() as $category) {
            $children[] = [
                'id' => $category->getId(),
                'name' => $category->getTranslated()['name'] ?? $category->getName() ?? 'Unknown',
            ];

            $this->collectChildCategories($category->getId(), $context, $children);
        }
    }

    private function categoryHasDirectProducts(string $categoryId, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('categories.id', $categoryId));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->setLimit(1);

        $result = $this->productRepository->search($criteria, $context);

        return $result->getTotal() > 0;
    }
}
