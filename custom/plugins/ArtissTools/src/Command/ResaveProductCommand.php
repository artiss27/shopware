<?php declare(strict_types=1);

namespace ArtissTools\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Description:
 *   Resave product(s) without changes to trigger all Shopware events.
 *   Useful for reindexing, cache invalidation, or triggering subscribers.
 *
 * Usage:
 *   bin/console artiss:product:resave [product-id] [options]
 *
 * Options:
 *   --all                    Resave all products instead of single product
 *   --batch-size=N           Number of products to process in one batch (default: 100)
 *
 * Example:
 *   bin/console artiss:product:resave 02e3f6feb4faaa2640981c0e3c6ea1c7
 *   bin/console artiss:product:resave --all --batch-size=50
 */
#[AsCommand(
    name: 'artiss:product:resave',
    description: 'Resave product(s) without changes to trigger Shopware events'
)]
class ResaveProductCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $productRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('product-id', InputArgument::OPTIONAL, 'Product ID to resave')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Resave all products')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Number of products to process in one batch', 100);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        $productId = $input->getArgument('product-id');
        $all = $input->getOption('all');
        $batchSize = (int) $input->getOption('batch-size');

        $io->title('Product Resave');

        if ($all) {
            return $this->resaveAllProducts($io, $context, $batchSize);
        }

        if ($productId === null) {
            $io->error('Either provide product-id argument or use --all option');
            return Command::FAILURE;
        }

        return $this->resaveSingleProduct($io, $context, $productId);
    }

    private function resaveSingleProduct(SymfonyStyle $io, Context $context, string $productId): int
    {
        $criteria = new Criteria([$productId]);
        $product = $this->productRepository->search($criteria, $context)->first();

        if ($product === null) {
            $io->error(sprintf('Product with ID "%s" not found', $productId));
            return Command::FAILURE;
        }

        $io->info(sprintf('Found product: %s', $product->getProductNumber()));

        $this->productRepository->update([['id' => $productId]], $context);

        $io->success(sprintf(
            'Product "%s" resaved successfully. All events have been triggered.',
            $product->getProductNumber()
        ));

        $io->table(
            ['Field', 'Value'],
            [
                ['ID', $product->getId()],
                ['Product Number', $product->getProductNumber()],
                ['Name', $product->getTranslated()['name'] ?? $product->getName() ?? 'N/A'],
                ['Active', $product->getActive() ? 'Yes' : 'No'],
                ['Stock', (string) $product->getStock()],
            ]
        );

        return Command::SUCCESS;
    }

    private function resaveAllProducts(SymfonyStyle $io, Context $context, int $batchSize): int
    {
        $io->section('Resaving all products');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', null));
        $totalProducts = $this->productRepository->searchIds($criteria, $context)->getTotal();

        if ($totalProducts === 0) {
            $io->warning('No products found');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d product(s) to resave', $totalProducts));

        $progressBar = $io->createProgressBar($totalProducts);
        $progressBar->start();

        $processed = 0;
        $offset = 0;

        while ($offset < $totalProducts) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('parentId', null));
            $criteria->setLimit($batchSize);
            $criteria->setOffset($offset);

            $productIds = $this->productRepository->searchIds($criteria, $context)->getIds();

            if (empty($productIds)) {
                break;
            }

            $updates = [];
            foreach ($productIds as $productId) {
                $updates[] = ['id' => $productId];
            }

            if (!empty($updates)) {
                try {
                    $this->productRepository->update($updates, $context);
                    $processed += count($updates);
                    $progressBar->advance(count($updates));
                } catch (\Exception $e) {
                    $io->warning(sprintf(
                        'Error updating batch at offset %d: %s',
                        $offset,
                        $e->getMessage()
                    ));
                }

                unset($updates, $productIds);
            }

            $offset += $batchSize;

            if ($processed >= $totalProducts) {
                break;
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->success(sprintf(
            'Resaved %d product(s) successfully. All events have been triggered.',
            $processed
        ));

        return Command::SUCCESS;
    }
}
