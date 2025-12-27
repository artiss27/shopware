<?php declare(strict_types=1);

namespace Artiss\Supplier\Command;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Currency\CurrencyEntity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recalculates product prices from custom fields to product.price using current exchange rates
 *
 * Usage:
 *   bin/console supplier:recalculate-prices [options]
 *
 * Options:
 *   --dry-run          Show what would be updated without actually updating
 *   --limit=VALUE      Limit number of products to process
 *   --price-type=VALUE Which price to recalculate: purchase, retail, list (default: all)
 *
 * Example:
 *   bin/console supplier:recalculate-prices --dry-run
 *   bin/console supplier:recalculate-prices --price-type=retail --limit=100
 */
#[AsCommand(
    name: 'supplier:recalculate-prices',
    description: 'Recalculate product prices from custom fields using current exchange rates'
)]
class RecalculatePricesCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $currencyRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be updated without actually updating'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Limit number of products to process'
            )
            ->addOption(
                'price-type',
                't',
                InputOption::VALUE_REQUIRED,
                'Which price to recalculate: purchase, retail, list (default: retail)',
                'retail'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        $dryRun = $input->getOption('dry-run');
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;
        $priceType = $input->getOption('price-type');

        if (!in_array($priceType, ['purchase', 'retail', 'list', 'all'], true)) {
            $io->error('Invalid price type. Use: purchase, retail, list, or all');
            return Command::FAILURE;
        }

        $io->title('Recalculating Product Prices from Custom Fields');

        if ($dryRun) {
            $io->warning('DRY RUN MODE - No changes will be made');
        }

        // Load all currencies with exchange rates
        $currencies = $this->loadCurrencies($context);
        $io->info(sprintf('Loaded %d currencies', count($currencies)));

        // Find products with custom price fields
        $products = $this->findProductsWithCustomPrices($context, $limit);
        $io->info(sprintf('Found %d products with custom price fields', count($products)));

        if (empty($products)) {
            $io->success('No products to process');
            return Command::SUCCESS;
        }

        $io->progressStart(count($products));

        $stats = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $updateData = [];

        foreach ($products as $product) {
            $customFields = $product->getCustomFields() ?? [];

            // Read from flat structure (not nested product_prices object)
            $purchaseValue = $customFields['purchase_price_value'] ?? null;
            $retailValue = $customFields['retail_price_value'] ?? null;
            $listValue = $customFields['list_price_value'] ?? null;

            if ($purchaseValue === null && $retailValue === null && $listValue === null) {
                $stats['skipped']++;
                $io->progressAdvance();
                continue;
            }

            // Reconstruct productPrices array for compatibility
            $productPrices = [
                'purchase_price_value' => $purchaseValue,
                'purchase_price_currency' => $customFields['purchase_price_currency'] ?? 'UAH',
                'retail_price_value' => $retailValue,
                'retail_price_currency' => $customFields['retail_price_currency'] ?? 'UAH',
                'list_price_value' => $listValue,
                'list_price_currency' => $customFields['list_price_currency'] ?? 'UAH',
            ];

            $update = $this->calculatePriceUpdate($product, $productPrices, $currencies, $priceType);

            if ($update) {
                $updateData[] = $update;
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }

            $stats['processed']++;
            $io->progressAdvance();

            // Batch update every 100 products to avoid memory issues
            if (!$dryRun && count($updateData) >= 100) {
                try {
                    $this->productRepository->update($updateData, $context);
                    $updateData = [];
                } catch (\Exception $e) {
                    $io->error('Error updating products: ' . $e->getMessage());
                    $stats['errors']++;
                }
            }
        }

        // Final batch update
        if (!$dryRun && !empty($updateData)) {
            try {
                $this->productRepository->update($updateData, $context);
            } catch (\Exception $e) {
                $io->error('Error updating final batch: ' . $e->getMessage());
                $stats['errors']++;
            }
        }

        $io->progressFinish();

        // Display results
        $io->newLine(2);
        $io->section('Results');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Processed', $stats['processed']],
                ['Updated', $stats['updated']],
                ['Skipped', $stats['skipped']],
                ['Errors', $stats['errors']],
            ]
        );

        if ($dryRun) {
            $io->note('This was a dry run. Run without --dry-run to apply changes.');
        }

        $io->success('Price recalculation completed!');

        return Command::SUCCESS;
    }

    /**
     * Load all currencies with exchange rates
     */
    private function loadCurrencies(Context $context): array
    {
        $criteria = new Criteria();
        $currencies = $this->currencyRepository->search($criteria, $context);

        $currencyMap = [];
        foreach ($currencies as $currency) {
            /** @var CurrencyEntity $currency */
            $currencyMap[$currency->getIsoCode()] = $currency->getFactor();
        }

        return $currencyMap;
    }

    /**
     * Find products that have custom price fields
     */
    private function findProductsWithCustomPrices(Context $context, ?int $limit): array
    {
        $criteria = new Criteria();

        // Filter products that have any price custom fields (flat structure)
        $criteria->addFilter(
            new NotFilter(
                NotFilter::CONNECTION_AND,
                [
                    new EqualsFilter('customFields.purchase_price_value', null),
                    new EqualsFilter('customFields.retail_price_value', null),
                    new EqualsFilter('customFields.list_price_value', null),
                ]
            )
        );

        if ($limit) {
            $criteria->setLimit($limit);
        } else {
            $criteria->setLimit(5000); // Safe default limit
        }

        return $this->productRepository->search($criteria, $context)->getElements();
    }

    /**
     * Calculate price update for a product
     */
    private function calculatePriceUpdate(
        $product,
        array $productPrices,
        array $currencies,
        string $priceType
    ): ?array {
        $updates = [
            'id' => $product->getId(),
        ];

        $hasUpdates = false;

        // Process retail price (main product price)
        if (($priceType === 'retail' || $priceType === 'all')
            && isset($productPrices['retail_price_value'])
            && isset($productPrices['retail_price_currency'])
        ) {
            $retailValue = (float) $productPrices['retail_price_value'];
            $retailCurrency = $productPrices['retail_price_currency'];
            $factor = $currencies[$retailCurrency] ?? 1.0;

            // Convert to base currency (EUR/UAH depending on shop config)
            $basePrice = round($retailValue / $factor, 2);

            $priceData = [
                'currencyId' => Defaults::CURRENCY,
                'gross' => $basePrice,
                'net' => $basePrice,
                'linked' => false,
            ];

            // Add list price if available
            if (($priceType === 'list' || $priceType === 'all')
                && isset($productPrices['list_price_value'])
                && isset($productPrices['list_price_currency'])
            ) {
                $listValue = (float) $productPrices['list_price_value'];
                $listCurrency = $productPrices['list_price_currency'];
                $listFactor = $currencies[$listCurrency] ?? 1.0;
                $listBasePrice = round($listValue / $listFactor, 2);

                $priceData['listPrice'] = [
                    'currencyId' => Defaults::CURRENCY,
                    'gross' => $listBasePrice,
                    'net' => $listBasePrice,
                    'linked' => false,
                ];
            }

            $updates['price'] = [$priceData];
            $hasUpdates = true;
        }

        // Process purchase price
        if (($priceType === 'purchase' || $priceType === 'all')
            && isset($productPrices['purchase_price_value'])
            && isset($productPrices['purchase_price_currency'])
        ) {
            $purchaseValue = (float) $productPrices['purchase_price_value'];
            $purchaseCurrency = $productPrices['purchase_price_currency'];
            $factor = $currencies[$purchaseCurrency] ?? 1.0;

            $basePrice = $purchaseValue / $factor;

            $updates['purchasePrices'] = [
                [
                    'currencyId' => Defaults::CURRENCY,
                    'gross' => round($basePrice, 2),
                    'net' => round($basePrice, 2),
                    'linked' => false,
                ],
            ];
            $hasUpdates = true;
        }

        return $hasUpdates ? $updates : null;
    }
}
