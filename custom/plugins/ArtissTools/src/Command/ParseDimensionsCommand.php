<?php declare(strict_types=1);

namespace ArtissTools\Command;

use ArtissTools\Service\DimensionParserService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Parse dimensions from custom fields and property group options and save to product dimensions
 *
 * 
 * @example
 *   # Parse dimensions from custom field and property group with cleanup
 *   bin/console artiss:parse-dimensions --custom-field=razmery --property-group=f4d5ec3eed5e469644c6de30b41ab275 --cleanup
 * 
 * @example
 *   # Dry run to test parsing
 *   bin/console artiss:parse-dimensions --custom-field=razmery --property-group=f4d5ec3eed5e469644c6de30b41ab275 --dry-run
 * 
 * @example
 *   # Process with custom batch size and limit
 *   bin/console artiss:parse-dimensions --custom-field=razmery --property-group=f4d5ec3eed5e469644c6de30b41ab275 --cleanup --batch-size=500 --limit=5000
 * 
 * @example
 *   # Process only custom field without property group
 *   bin/console artiss:parse-dimensions --custom-field=razmery --cleanup
 */
#[AsCommand(
    name: 'artiss:parse-dimensions',
    description: 'Parse dimensions from custom fields and save to product dimensions'
)]
class ParseDimensionsCommand extends Command
{
    private SymfonyStyle $io;
    private Context $context;
    private string $logFile;
    private int $processed = 0;
    private int $success = 0;
    private int $skipped = 0;
    private int $errors = 0;

    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly DimensionParserService $dimensionParser,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        string $projectDir
    ) {
        parent::__construct();
        $this->logFile = $projectDir . '/var/log/dimension_parse_errors.log';
    }

    protected function configure(): void
    {
        $this
            ->addOption('custom-field', 'c', InputOption::VALUE_REQUIRED, 'Technical name of custom field (e.g., razmery)')
            ->addOption('property-group', 'p', InputOption::VALUE_REQUIRED, 'Property group ID (e.g., f4d5ec3eed5e469644c6de30b41ab275)')
            ->addOption('property-option', null, InputOption::VALUE_REQUIRED, 'DEPRECATED: Use --property-group instead. Property group ID')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Remove custom field and property option after processing')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate without making changes')
            ->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'Batch size for processing (default: 1000)', '1000')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limit total number of products to process (0 = all)', '0')
            ->addOption('product-id', null, InputOption::VALUE_OPTIONAL, 'Process specific product by ID')
            ->setHelp(<<<'EOF'
Parse dimensions from custom fields and property group options and save to product dimensions (width, height, length).

Dimension formats: "549х595х570 мм", "549x595x570 mm", "35х30 см", "220 мм"
- 1 dimension: width=0, height=0, length=value
- 2 dimensions: width=first, height=0, length=second
- 3 dimensions: width=first, height=second, length=third

With --cleanup option, custom field and all property options from the specified property group are removed after successful parsing.
Parse errors are logged to var/log/dimension_parse_errors.log

Note: --property-group accepts a property GROUP ID. All property options from that group will be removed.

Examples:
  <info>bin/console artiss:parse-dimensions --custom-field=razmery --property-group=f4d5ec3eed5e469644c6de30b41ab275 --cleanup</info>
  <info>bin/console artiss:parse-dimensions --custom-field=razmery --dry-run</info>
  <info>bin/console artiss:parse-dimensions --custom-field=razmery --property-group=f4d5ec3eed5e469644c6de30b41ab275 --cleanup --batch-size=500 --limit=5000</info>
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->context = Context::createDefaultContext();
        $dryRun = $input->getOption('dry-run');
        $cleanup = $input->getOption('cleanup');
        $batchSize = (int) $input->getOption('batch-size');
        $totalLimit = (int) $input->getOption('limit');
        $specificProductId = $input->getOption('product-id');

        $this->io->title('Parse Product Dimensions from Custom Fields');

        if (file_exists($this->logFile)) {
            file_put_contents($this->logFile, '');
        }

        if ($dryRun) {
            $this->io->note('DRY RUN MODE - No changes will be made');
        }

        $customFieldName = $input->getOption('custom-field');
        $propertyGroupId = $input->getOption('property-group') ?? $input->getOption('property-option');
        
        $customFieldNames = [];
        $propertyGroupIds = [];
        
        if ($customFieldName) {
            $customFieldNames = [$customFieldName];
            $this->io->section('Custom field: ' . $customFieldName);
        }
        
        if ($propertyGroupId) {
            $propertyGroupIds = [$propertyGroupId];
            $this->io->section('Property group ID: ' . $propertyGroupId);
        }
        
        if (empty($customFieldNames) && empty($propertyGroupIds)) {
            $this->io->error('At least one of --custom-field or --property-group must be specified');
            return Command::FAILURE;
        }

        if ($cleanup) {
            $this->io->note('Cleanup mode: custom fields and property options will be removed after processing');
        }

        if ($specificProductId) {
            $this->processSpecificProduct($specificProductId, $customFieldNames, $propertyGroupIds, $dryRun, $cleanup);
            return Command::SUCCESS;
        }

        $offset = 0;
        $totalProcessed = 0;
        $hasMore = true;

        while ($hasMore) {
            $products = $this->findProductsWithDimensions($customFieldNames, $propertyGroupIds, $batchSize, $offset);
            $batchTotal = count($products);

            if ($batchTotal === 0) {
                $hasMore = false;
                break;
            }

            if ($offset === 0) {
                $this->io->section("Found products in batch: {$batchTotal}");
            } else {
                $this->io->section("Processing batch (offset: {$offset}, found: {$batchTotal})");
            }

            $progressBar = $this->io->createProgressBar($batchTotal);
            $progressBar->start();

            foreach ($products as $product) {
                $this->processProduct($product, $customFieldNames, $propertyGroupIds, $dryRun, $cleanup);
                $progressBar->advance();
                $totalProcessed++;
            }

            $progressBar->finish();
            $this->io->newLine(2);

            if ($batchTotal < $batchSize) {
                $hasMore = false;
            } elseif ($totalLimit > 0 && $totalProcessed >= $totalLimit) {
                $hasMore = false;
            } else {
                $offset += $batchSize;
            }
        }

        if ($totalProcessed === 0) {
            $this->io->warning('No products found with dimension custom fields');
            return Command::SUCCESS;
        }

        $this->io->section('Statistics:');
        $this->io->table(
            ['Metric', 'Value'],
            [
                ['Processed', $this->processed],
                ['Success', $this->success],
                ['Skipped (has dimensions)', $this->skipped],
                ['Errors', $this->errors],
            ]
        );

        if ($this->errors > 0) {
            $this->io->note("Errors logged to: {$this->logFile}");
        }

        return Command::SUCCESS;
    }


    /**
     * @return ProductEntity[]
     */
    private function findProductsWithDimensions(array $customFieldNames, array $propertyGroupIds, int $limit, int $offset): array
    {
        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->setOffset($offset);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        
        if (!empty($propertyGroupIds)) {
            $criteria->addAssociation('properties');
        }

        $filters = [];

        if (!empty($customFieldNames)) {
            $customFieldFilters = [];
            foreach ($customFieldNames as $fieldName) {
                $customFieldFilters[] = new NotFilter(
                    NotFilter::CONNECTION_AND,
                    [new EqualsFilter('customFields.' . $fieldName, null)]
                );
            }
            if (count($customFieldFilters) > 1) {
                $filters[] = new OrFilter($customFieldFilters);
            } else {
                $filters = array_merge($filters, $customFieldFilters);
            }
        }

        if (!empty($propertyGroupIds)) {
            $validGroupIds = array_filter($propertyGroupIds, function($id) {
                return \Shopware\Core\Framework\Uuid\Uuid::isValid($id);
            });
            
            if (!empty($validGroupIds)) {
                // Find all property options in these groups
                $binaryGroupIds = array_map(function($id) {
                    return \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes($id);
                }, $validGroupIds);
                
                $groupPlaceholders = implode(',', array_fill(0, count($binaryGroupIds), '?'));
                $optionsSql = "SELECT id FROM property_group_option WHERE property_group_id IN ({$groupPlaceholders})";
                $binaryOptionIds = $this->connection->fetchFirstColumn($optionsSql, $binaryGroupIds);
                
                if (!empty($binaryOptionIds)) {
                    $optionPlaceholders = implode(',', array_fill(0, count($binaryOptionIds), '?'));
                    $sql = "SELECT DISTINCT product_id FROM product_property WHERE property_group_option_id IN ({$optionPlaceholders})";
                    $binaryProductIds = $this->connection->fetchFirstColumn($sql, $binaryOptionIds);
                    
                    if (!empty($binaryProductIds)) {
                        $productIds = array_map(function($binaryId) {
                            return \Shopware\Core\Framework\Uuid\Uuid::fromBytesToHex($binaryId);
                        }, $binaryProductIds);
                        
                        $criteria->setIds($productIds);
                    } else {
                        if (empty($customFieldNames)) {
                            return [];
                        }
                    }
                } else {
                    if (empty($customFieldNames)) {
                        return [];
                    }
                }
            }
        }

        if (empty($filters) && empty($propertyGroupIds)) {
            return [];
        }

        if (!empty($filters)) {
            if (count($filters) > 1) {
                $criteria->addFilter(new OrFilter($filters));
            } else {
                $criteria->addFilter($filters[0]);
            }
        }

        $result = $this->productRepository->search($criteria, $this->context);
        return $result->getEntities()->getElements();
    }

    private function processProduct(ProductEntity $product, array $customFieldNames, array $propertyGroupIds, bool $dryRun, bool $cleanup): void
    {
        $this->processed++;

        $productId = $product->getId();
        $customFields = $product->getCustomFields() ?? [];

        $hasDimensions = $this->dimensionParser->hasDimensions(
            $product->getWidth(),
            $product->getHeight(),
            $product->getLength()
        );

        if ($hasDimensions) {
            if (!$dryRun && $cleanup) {
                $this->removeFieldsFromProduct($productId, $customFieldNames, $propertyGroupIds);
                $this->success++;
            } else {
                $this->skipped++;
            }
            return;
        }

        $dimensionValue = null;
        $foundFieldName = null;
        $foundPropertyOptionId = null;

        foreach ($customFieldNames as $fieldName) {
            if (isset($customFields[$fieldName]) && !empty($customFields[$fieldName])) {
                $dimensionValue = $customFields[$fieldName];
                $foundFieldName = $fieldName;
                break;
            }
        }

        if ($dimensionValue === null && !empty($propertyGroupIds)) {
            $dimensionValue = $this->findPropertyGroupValue($productId, $propertyGroupIds);
        }

        if ($dimensionValue === null) {
            return;
        }

        $dimensions = $this->dimensionParser->parseDimensions((string) $dimensionValue);

        if ($dimensions === null) {
            $fieldIdentifier = $foundFieldName ?? ('property_option_' . $foundPropertyOptionId);
            $this->logError($product, $fieldIdentifier, $dimensionValue, 'Failed to parse dimensions');
            $this->errors++;
            return;
        }

        if (!$dryRun) {
            try {
                $updateData = [
                    'id' => $productId,
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                    'length' => $dimensions['length'],
                ];

                if ($cleanup && !empty($customFieldNames)) {
                    foreach ($customFieldNames as $fieldName) {
                        if (isset($customFields[$fieldName])) {
                            $this->io->writeln("  Removing custom field: {$fieldName} = {$customFields[$fieldName]}");
                            // In Shopware, to remove a custom field, set it to null (don't unset from array)
                            $customFields[$fieldName] = null;
                        }
                    }
                    $updateData['customFields'] = $customFields;
                }

                $this->productRepository->update([$updateData], $this->context);

                if ($cleanup) {
                    if (!empty($propertyGroupIds)) {
                        $this->removePropertyOptions($productId, $propertyGroupIds);
                    }
                }
                
                $this->success++;
            } catch (\Exception $e) {
                $fieldIdentifier = $foundFieldName ?? ('property_option_' . $foundPropertyOptionId);
                $this->logError($product, $fieldIdentifier, $dimensionValue, 'Error saving: ' . $e->getMessage());
                $this->errors++;
                $this->logger->error('ParseDimensions: Error updating product', [
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            $this->success++;
        }
    }

    private function findPropertyGroupValue(string $productId, array $propertyGroupIds): ?string
    {
        if (empty($propertyGroupIds)) {
            return null;
        }

        $binaryProductId = \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes($productId);
        
        foreach ($propertyGroupIds as $groupId) {
            if (!\Shopware\Core\Framework\Uuid\Uuid::isValid($groupId)) {
                continue;
            }
            
            $binaryGroupId = \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes($groupId);
            
            // Find property option assigned to this product from this group
            $sql = "
                SELECT pgot.name
                FROM product_property pp
                INNER JOIN property_group_option pgo ON pp.property_group_option_id = pgo.id
                INNER JOIN property_group_option_translation pgot ON pgo.id = pgot.property_group_option_id
                WHERE pp.product_id = ?
                AND pgo.property_group_id = ?
                LIMIT 1
            ";
            $result = $this->connection->fetchOne($sql, [$binaryProductId, $binaryGroupId]);
            
            if ($result) {
                return $result;
            }
        }

        return null;
    }

    private function removeFieldsFromProduct(string $productId, array $customFieldNames, array $propertyGroupIds): void
    {
        try {
            $criteria = new Criteria([$productId]);
            $product = $this->productRepository->search($criteria, $this->context)->first();
            
            if (!$product) {
                $this->logger->warning('ParseDimensions: Product not found for cleanup', ['product_id' => $productId]);
                return;
            }

            $customFields = $product->getCustomFields() ?? [];
            $hasChanges = false;

            foreach ($customFieldNames as $fieldName) {
                if (isset($customFields[$fieldName])) {
                    $oldValue = $customFields[$fieldName];
                    // In Shopware, to remove a custom field, set it to null (don't unset from array)
                    $customFields[$fieldName] = null;
                    $hasChanges = true;
                    $this->io->writeln("  Removing custom field: {$fieldName} = {$oldValue}");
                    $this->logger->info('ParseDimensions: Removing custom field', [
                        'product_id' => $productId,
                        'field' => $fieldName,
                        'old_value' => $oldValue,
                    ]);
                } else {
                    $this->io->writeln("  Custom field not found: {$fieldName}");
                    $this->logger->debug('ParseDimensions: Custom field not found', [
                        'product_id' => $productId,
                        'field' => $fieldName,
                    ]);
                }
            }

            if ($hasChanges) {
                // Reload product to get fresh data and versionId
                $criteria = new Criteria([$productId]);
                $freshProduct = $this->productRepository->search($criteria, $this->context)->first();
                
                if ($freshProduct) {
                    $updatePayload = [
                        'id' => $productId,
                        'versionId' => $freshProduct->getVersionId(),
                        'customFields' => $customFields,
                    ];
                    
                    $this->productRepository->update([$updatePayload], $this->context);
                    
                    $this->logger->info('ParseDimensions: Custom fields updated', [
                        'product_id' => $productId,
                        'custom_fields_before' => $product->getCustomFields(),
                        'custom_fields_after' => $customFields,
                    ]);
                }
            } else {
                $this->logger->debug('ParseDimensions: No custom field changes', ['product_id' => $productId]);
            }

            $this->removePropertyOptions($productId, $propertyGroupIds);
        } catch (\Exception $e) {
            $this->logger->error('ParseDimensions: Error removing fields from product', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function removePropertyOptions(string $productId, array $propertyGroupIds): void
    {
        if (empty($propertyGroupIds)) {
            return;
        }

        try {
            $binaryProductId = \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes($productId);
            
            foreach ($propertyGroupIds as $groupId) {
                if (!\Shopware\Core\Framework\Uuid\Uuid::isValid($groupId)) {
                    if (isset($this->io)) {
                        $this->io->writeln("  Invalid property group ID: {$groupId}");
                    }
                    $this->logger->warning('ParseDimensions: Invalid property group ID', [
                        'product_id' => $productId,
                        'group_id' => $groupId,
                    ]);
                    continue;
                }
                
                $binaryGroupId = \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes($groupId);
                
                // Find all property options that belong to this property group
                $optionsSql = "
                    SELECT HEX(pgo.id) as option_id
                    FROM property_group_option pgo
                    WHERE pgo.property_group_id = ?
                ";
                $groupOptionIds = $this->connection->fetchFirstColumn($optionsSql, [$binaryGroupId]);
                
                if (empty($groupOptionIds)) {
                    if (isset($this->io)) {
                        $this->io->writeln("  Property group '{$groupId}' has no options");
                    }
                    continue;
                }
                
                if (isset($this->io)) {
                    $this->io->writeln("  Property group '{$groupId}' has " . count($groupOptionIds) . " option(s)");
                }
                
                // Find which of these options are assigned to this product
                $placeholders = implode(',', array_fill(0, count($groupOptionIds), '?'));
                $binaryOptionIds = array_map(function($hexId) {
                    return \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes($hexId);
                }, $groupOptionIds);
                
                $productOptionsSql = "
                    SELECT HEX(property_group_option_id) as option_id
                    FROM product_property
                    WHERE product_id = ?
                    AND property_group_option_id IN ({$placeholders})
                ";
                $productOptionIds = $this->connection->fetchFirstColumn($productOptionsSql, array_merge([$binaryProductId], $binaryOptionIds));
                
                if (empty($productOptionIds)) {
                    if (isset($this->io)) {
                        $this->io->writeln("  No options from this property group are assigned to the product");
                    }
                    continue;
                }
                
                // Delete all property options from this group that are assigned to the product
                $deletePlaceholders = implode(',', array_fill(0, count($productOptionIds), '?'));
                $deleteBinaryIds = array_map(function($hexId) {
                    return \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes($hexId);
                }, $productOptionIds);
                
                $deleteSql = "
                    DELETE FROM product_property
                    WHERE product_id = ?
                    AND property_group_option_id IN ({$deletePlaceholders})
                ";
                $deleted = $this->connection->executeStatement($deleteSql, array_merge([$binaryProductId], $deleteBinaryIds));
                
                if (isset($this->io)) {
                    $this->io->writeln("  Removed {$deleted} property option(s) from property group '{$groupId}'");
                }
                $this->logger->info('ParseDimensions: Property options removed from group', [
                    'product_id' => $productId,
                    'group_id' => $groupId,
                    'deleted_count' => $deleted,
                    'option_ids' => $productOptionIds,
                ]);
            }
        } catch (\Exception $e) {
            if (isset($this->io)) {
                $this->io->error("  Error removing property options: " . $e->getMessage());
            }
            $this->logger->error('ParseDimensions: Error removing property options', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function processSpecificProduct(string $productId, array $customFieldNames, array $propertyGroupIds, bool $dryRun, bool $cleanup): void
    {
        if (!\Shopware\Core\Framework\Uuid\Uuid::isValid($productId)) {
            $this->io->error("Invalid product ID: {$productId}");
            return;
        }

        $criteria = new Criteria([$productId]);
        $product = $this->productRepository->search($criteria, $this->context)->first();

        if (!$product) {
            $this->io->error("Product not found: {$productId}");
            return;
        }

        $this->io->section("Processing product: {$product->getProductNumber()} ({$productId})");
        
        // Verify current state before processing
        if (!$dryRun) {
            $this->verifyProductState($productId, $customFieldNames, $propertyGroupIds);
        }
        
        $this->processProduct($product, $customFieldNames, $propertyGroupIds, $dryRun, $cleanup);
        
        // Verify state after processing
        if (!$dryRun) {
            $this->io->newLine();
            $this->verifyProductState($productId, $customFieldNames, $propertyGroupIds);
        }

        $this->io->section('Result:');
        $this->io->table(
            ['Metric', 'Value'],
            [
                ['Processed', $this->processed],
                ['Success', $this->success],
                ['Skipped', $this->skipped],
                ['Errors', $this->errors],
            ]
        );
    }
    
    private function verifyProductState(string $productId, array $customFieldNames, array $propertyGroupIds): void
    {
        $criteria = new Criteria([$productId]);
        $product = $this->productRepository->search($criteria, $this->context)->first();
        
        if (!$product) {
            return;
        }
        
        $customFields = $product->getCustomFields() ?? [];
        $binaryProductId = \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes($productId);
        
        $this->io->writeln("\n<info>Database verification:</info>");
        foreach ($customFieldNames as $fieldName) {
            if (isset($customFields[$fieldName])) {
                $this->io->writeln("  [X] Custom field '{$fieldName}' EXISTS in DB: " . $customFields[$fieldName]);
            } else {
                $this->io->writeln("  [✓] Custom field '{$fieldName}' NOT FOUND in DB (removed)");
            }
        }
        
        // Check property groups
        foreach ($propertyGroupIds as $groupId) {
            if (!\Shopware\Core\Framework\Uuid\Uuid::isValid($groupId)) {
                $this->io->writeln("  [?] Property group '{$groupId}' - invalid UUID");
                continue;
            }
            
            try {
                $binaryGroupId = \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes($groupId);
                
                // Find all options in this group
                $optionsSql = "SELECT id FROM property_group_option WHERE property_group_id = ?";
                $binaryOptionIds = $this->connection->fetchFirstColumn($optionsSql, [$binaryGroupId]);
                
                if (empty($binaryOptionIds)) {
                    $this->io->writeln("  [?] Property group '{$groupId}' has no options");
                    continue;
                }
                
                // Check if any of these options are assigned to the product
                $placeholders = implode(',', array_fill(0, count($binaryOptionIds), '?'));
                $sql = "SELECT COUNT(*) as cnt FROM product_property WHERE product_id = ? AND property_group_option_id IN ({$placeholders})";
                $exists = $this->connection->fetchOne($sql, array_merge([$binaryProductId], $binaryOptionIds));
                
                if ($exists > 0) {
                    $this->io->writeln("  [X] Property group '{$groupId}' has {$exists} option(s) assigned to product");
                } else {
                    $this->io->writeln("  [✓] Property group '{$groupId}' has no options assigned to product (removed)");
                }
            } catch (\Exception $e) {
                $this->io->writeln("  [?] Property group '{$groupId}' - error checking: " . $e->getMessage());
            }
        }
    }

    private function logError(ProductEntity $product, string $fieldName, string $value, string $reason): void
    {
        $logEntry = sprintf(
            "[%s] Product ID: %s, Number: %s, Field: %s, Value: %s, Reason: %s\n",
            date('Y-m-d H:i:s'),
            $product->getId(),
            $product->getProductNumber() ?? 'N/A',
            $fieldName,
            $value,
            $reason
        );

        @file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }
}
