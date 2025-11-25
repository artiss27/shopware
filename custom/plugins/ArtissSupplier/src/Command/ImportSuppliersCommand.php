<?php declare(strict_types=1);

namespace Artiss\Supplier\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'artiss:supplier:import',
    description: 'Import suppliers from Bitrix export data'
)]
class ImportSuppliersCommand extends Command
{
    private const DATA_FILE = '/var/www/html/bitrix-export/suppliers/suppliers_full_v3.json';

    public function __construct(
        private readonly EntityRepository $supplierRepository,
        private readonly EntityRepository $manufacturerRepository,
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $propertyGroupOptionRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_OPTIONAL,
            'Limit number of suppliers to import (for testing)',
            null
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();
        $limit = $input->getOption('limit') ? (int)$input->getOption('limit') : null;

        $io->title('Supplier Import from Bitrix');

        // Load data file
        $io->section('Loading data file...');
        $suppliers = $this->loadJsonFile(self::DATA_FILE);

        if (!$suppliers) {
            $io->error('Failed to load data file: ' . self::DATA_FILE);
            return Command::FAILURE;
        }

        $io->success(sprintf('Loaded %d suppliers', count($suppliers)));

        // Load manufacturer and property group mappings
        $io->section('Loading Shopware mappings...');
        $manufacturerMap = $this->loadManufacturerMap($context);
        $propertyGroupMap = $this->loadPropertyGroupMap($context);
        $io->info(sprintf('Loaded %d manufacturers, %d property groups',
            count($manufacturerMap), count($propertyGroupMap)));

        // Process suppliers
        $io->section('Importing suppliers...');
        $imported = 0;
        $skipped = 0;
        $errors = 0;

        $progressBar = $io->createProgressBar($limit ?? count($suppliers));
        $progressBar->start();

        foreach ($suppliers as $supplierData) {
            if ($limit && $imported >= $limit) {
                break;
            }

            $progressBar->advance();

            try {
                $bitrixId = (int)$supplierData['supplier_id'];
                $name = trim($supplierData['supplier_name'] ?? '');

                if (empty($name)) {
                    $skipped++;
                    continue;
                }

                // Prepare supplier data
                $supplierPayload = $this->buildSupplierPayload(
                    $bitrixId,
                    $supplierData,
                    $manufacturerMap,
                    $propertyGroupMap
                );

                $this->supplierRepository->upsert([$supplierPayload], $context);
                $imported++;

            } catch (\Exception $e) {
                $errors++;
                if ($limit === 1) {
                    // For debugging - show full error
                    $io->error(sprintf('Error importing supplier %s (ID %s): %s',
                        $supplierData['supplier_name'] ?? 'unknown',
                        $supplierData['supplier_id'] ?? 'unknown',
                        $e->getMessage() . "\n" . $e->getTraceAsString()));
                }
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        // Summary
        $io->section('Import Summary');
        $io->success(sprintf('Successfully imported: %d', $imported));
        if ($skipped > 0) {
            $io->warning(sprintf('Skipped: %d', $skipped));
        }
        if ($errors > 0) {
            $io->error(sprintf('Errors: %d', $errors));
        }

        return Command::SUCCESS;
    }

    private function loadJsonFile(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        return json_decode($content, true);
    }

    private function loadManufacturerMap(Context $context): array
    {
        $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
        $criteria->addFilter(
            new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter(
                \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter::CONNECTION_AND,
                [new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('customFields.bitrix_element_id', null)]
            )
        );

        $manufacturers = $this->manufacturerRepository->search($criteria, $context);
        $map = [];

        foreach ($manufacturers as $manufacturer) {
            $bitrixElementId = $manufacturer->getCustomFields()['bitrix_element_id'] ?? null;
            if ($bitrixElementId) {
                $map[(int)$bitrixElementId] = $manufacturer->getId();
            }
        }

        return $map;
    }

    private function loadPropertyGroupMap(Context $context): array
    {
        $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
        $criteria->addFilter(
            new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter(
                \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter::CONNECTION_AND,
                [new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('customFields.bitrix_value', null)]
            )
        );
        $criteria->setLimit(10000);

        $propertyGroupOptions = $this->propertyGroupOptionRepository->search($criteria, $context);
        $map = [];

        foreach ($propertyGroupOptions as $option) {
            $bitrixValue = $option->getCustomFields()['bitrix_value'] ?? null;
            if ($bitrixValue) {
                // Map bitrix_value (which is equipment type ID) to property group option ID
                $map[(int)$bitrixValue] = $option->getId();
            }
        }

        return $map;
    }

    /**
     * Parse JSON array from SQL export
     * Data comes as proper JSON array from JSON_ARRAYAGG or already decoded by json_decode
     */
    private function parseJsonArray($data): array
    {
        if (empty($data)) {
            return [];
        }

        // Data might be already an array (decoded by JSON file parsing)
        // or still a string (needs decoding)
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                return [];
            }
        }

        if (!is_array($data)) {
            return [];
        }

        // Filter empty values
        return array_values(array_filter($data, function($v) {
            return $v !== null && trim((string)$v) !== '';
        }));
    }

    /**
     * Parse PHP serialized array and extract VALUE array
     */
    private function parsePhpSerializedValue(?string $data): array
    {
        if (empty($data)) {
            return [];
        }

        // Try to unserialize
        $unserialized = @unserialize($data);
        if ($unserialized === false) {
            return [];
        }

        // Extract VALUE array if exists
        if (is_array($unserialized) && isset($unserialized['VALUE']) && is_array($unserialized['VALUE'])) {
            // Filter empty values
            return array_values(array_filter($unserialized['VALUE'], function($v) {
                return $v !== null && $v !== '';
            }));
        }

        return [];
    }

    /**
     * Split comma-separated IDs and convert to array
     */
    private function splitIds(?string $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return array_map('intval', explode(',', $ids));
    }

    /**
     * Map Bitrix IDs to Shopware IDs
     */
    private function mapIds(array $bitrixIds, array $map): array
    {
        $shopwareIds = [];
        foreach ($bitrixIds as $bitrixId) {
            if (isset($map[$bitrixId])) {
                $shopwareIds[] = $map[$bitrixId];
            }
        }
        return $shopwareIds;
    }

    private function buildSupplierPayload(
        int $bitrixId,
        array $supplierData,
        array $manufacturerMap,
        array $propertyGroupMap
    ): array {
        // Generate deterministic UUID from bitrix ID
        $id = Uuid::fromBytesToHex(md5('supplier_' . $bitrixId, true));

        // Map trade marks to manufacturer IDs
        $tradeMarkBitrixIds = $this->splitIds($supplierData['trade_mark_ids'] ?? null);
        $manufacturerIds = $this->mapIds($tradeMarkBitrixIds, $manufacturerMap);

        // Map alternative trade marks to alternative manufacturer IDs
        $alternativeTmBitrixIds = $this->splitIds($supplierData['alternative_tm_ids'] ?? null);
        $alternativeManufacturerIds = $this->mapIds($alternativeTmBitrixIds, $manufacturerMap);

        // Map equipment types to property group IDs
        $equipmentTypeBitrixIds = $this->splitIds($supplierData['equipment_type_ids'] ?? null);
        $equipmentTypeIds = $this->mapIds($equipmentTypeBitrixIds, $propertyGroupMap);

        // Build custom fields
        $customFields = [
            'bitrix_id' => $bitrixId,
        ];

        // Parse contact information (now comes as JSON arrays from SQL)
        $cityValues = $this->parseJsonArray($supplierData['city'] ?? null);
        if (!empty($cityValues)) {
            $customFields['supplier_contacts_city'] = $cityValues;
        }

        $contactValues = $this->parseJsonArray($supplierData['contacts'] ?? null);
        if (!empty($contactValues)) {
            $customFields['supplier_contacts_phone'] = $contactValues;
        }

        $emailValues = $this->parseJsonArray($supplierData['email'] ?? null);
        if (!empty($emailValues)) {
            $customFields['supplier_contacts_email'] = $emailValues;
        }

        $websiteValues = $this->parseJsonArray($supplierData['website'] ?? null);
        if (!empty($websiteValues)) {
            $customFields['supplier_contacts_website'] = $websiteValues;
        }

        // Commercial terms
        $zakupkaValues = $this->parseJsonArray($supplierData['zakupka'] ?? null);
        if (!empty($zakupkaValues)) {
            $customFields['supplier_commercial_purchase'] = $zakupkaValues;
        }

        $marginValues = $this->parseJsonArray($supplierData['margin'] ?? null);
        if (!empty($marginValues)) {
            $customFields['supplier_commercial_margin'] = $marginValues;
        }

        $discountOnlineValues = $this->parseJsonArray($supplierData['discount_online'] ?? null);
        if (!empty($discountOnlineValues)) {
            $customFields['supplier_commercial_discount_online'] = $discountOnlineValues;
        }

        // Additional information (HTML text fields - join arrays)
        $noteValues = $this->parseJsonArray($supplierData['note'] ?? null);
        if (!empty($noteValues)) {
            $customFields['supplier_additional_note'] = implode("\n\n", $noteValues);
        }

        $commentContentValues = $this->parseJsonArray($supplierData['comment_content'] ?? null);
        if (!empty($commentContentValues)) {
            $customFields['supplier_additional_comment_content'] = implode("\n\n", $commentContentValues);
        }

        // Details and potencial_tm still come as PHP serialized (from prop_s32)
        $detailsValues = $this->parsePhpSerializedValue($supplierData['details'] ?? null);
        if (!empty($detailsValues)) {
            $customFields['supplier_additional_details'] = implode("\n\n", $detailsValues);
        }

        $potencialTmValues = $this->parsePhpSerializedValue($supplierData['potencial_tm'] ?? null);
        if (!empty($potencialTmValues)) {
            $customFields['supplier_additional_potencial_tm'] = $potencialTmValues;
        }

        return [
            'id' => $id,
            'name' => trim($supplierData['supplier_name']),
            'manufacturerIds' => !empty($manufacturerIds) ? $manufacturerIds : null,
            'alternativeManufacturerIds' => !empty($alternativeManufacturerIds) ? $alternativeManufacturerIds : null,
            'equipmentTypeIds' => !empty($equipmentTypeIds) ? $equipmentTypeIds : null,
            'customFields' => $customFields,
        ];
    }
}
