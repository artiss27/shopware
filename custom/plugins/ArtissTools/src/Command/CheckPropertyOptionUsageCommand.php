<?php declare(strict_types=1);

namespace ArtissTools\Command;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Check where a property option is used
 *
 * @example
 *   # Find option by name and check usage
 *   bin/console artiss:check-property-option-usage --group=92e478d01e0e33e70a51e5914ee7cd6d --name="63x50x63"
 *
 * @example
 *   # Check usage by option ID
 *   bin/console artiss:check-property-option-usage --option-id=abc123def456...
 */
#[AsCommand(
    name: 'artiss:check-property-option-usage',
    description: 'Check where a property option is used'
)]
class CheckPropertyOptionUsageCommand extends Command
{
    public function __construct(
        private readonly Connection $connection
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('group', 'g', InputOption::VALUE_OPTIONAL, 'Property group ID (hex)')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'Option name to search (partial match)')
            ->addOption('option-id', null, InputOption::VALUE_OPTIONAL, 'Option ID (hex)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $groupId = $input->getOption('group');
        $optionName = $input->getOption('name');
        $optionId = $input->getOption('option-id');

        if (!$optionId && (!$groupId || !$optionName)) {
            $io->error('Provide either --option-id or both --group and --name');
            return Command::FAILURE;
        }

        $binaryOptionId = null;
        $optionInfo = null;

        if ($optionId) {
            if (!Uuid::isValid($optionId)) {
                $io->error("Invalid option UUID: $optionId");
                return Command::FAILURE;
            }
            $binaryOptionId = Uuid::fromHexToBytes($optionId);
            
            $optionInfo = $this->connection->fetchAssociative("
                SELECT HEX(pgo.id) as option_id, pgot.name as option_name, pgt.name as group_name
                FROM property_group_option pgo
                JOIN property_group_option_translation pgot ON pgo.id = pgot.property_group_option_id
                JOIN property_group pg ON pgo.property_group_id = pg.id
                JOIN property_group_translation pgt ON pg.id = pgt.property_group_id
                WHERE pgo.id = ?
                LIMIT 1
            ", [$binaryOptionId]);
        } else {
            $binaryGroupId = Uuid::fromHexToBytes($groupId);
            
            $optionInfo = $this->connection->fetchAssociative("
                SELECT HEX(pgo.id) as option_id, pgot.name as option_name, pgt.name as group_name
                FROM property_group_option pgo
                JOIN property_group_option_translation pgot ON pgo.id = pgot.property_group_option_id
                JOIN property_group pg ON pgo.property_group_id = pg.id
                JOIN property_group_translation pgt ON pg.id = pgt.property_group_id
                WHERE pgo.property_group_id = ?
                AND pgot.name LIKE ?
                LIMIT 1
            ", [$binaryGroupId, '%' . $optionName . '%']);
            
            if ($optionInfo) {
                $binaryOptionId = Uuid::fromHexToBytes($optionInfo['option_id']);
            }
        }

        if (!$optionInfo) {
            $io->error("Option not found");
            return Command::FAILURE;
        }

        $io->title("Property Option Usage");
        $io->writeln("Group: {$optionInfo['group_name']}");
        $io->writeln("Option: {$optionInfo['option_name']}");
        $io->writeln("Option ID: {$optionInfo['option_id']}");

        // Usage in product_property
        $propertyCount = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM product_property WHERE property_group_option_id = ?",
            [$binaryOptionId]
        );
        $io->writeln("\nUsed in product_property: $propertyCount");

        // Usage in product_configurator_setting
        $configuratorCount = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM product_configurator_setting WHERE property_group_option_id = ?",
            [$binaryOptionId]
        );
        $io->writeln("Used in product_configurator_setting: $configuratorCount");

        // Usage in product_option
        $productOptionCount = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM product_option WHERE property_group_option_id = ?",
            [$binaryOptionId]
        );
        $io->writeln("Used in product_option: $productOptionCount");

        // Sample products
        if ($configuratorCount > 0 || $productOptionCount > 0) {
            $io->section("Products using this option");
            
            $products = $this->connection->fetchAllAssociative("
                SELECT DISTINCT HEX(p.id) as product_id, p.product_number, pt.name as product_name, 
                       HEX(p.parent_id) as parent_id,
                       CASE 
                           WHEN po.property_group_option_id IS NOT NULL THEN 'variant'
                           WHEN pcs.property_group_option_id IS NOT NULL THEN 'parent'
                           ELSE 'property'
                       END as usage_type
                FROM product p
                JOIN product_translation pt ON p.id = pt.product_id
                LEFT JOIN product_option po ON p.id = po.product_id AND po.property_group_option_id = ?
                LEFT JOIN product_configurator_setting pcs ON p.id = pcs.product_id AND pcs.property_group_option_id = ?
                LEFT JOIN product_property pp ON p.id = pp.product_id AND pp.property_group_option_id = ?
                WHERE po.property_group_option_id IS NOT NULL 
                   OR pcs.property_group_option_id IS NOT NULL
                   OR pp.property_group_option_id IS NOT NULL
                LIMIT 20
            ", [$binaryOptionId, $binaryOptionId, $binaryOptionId]);

            $rows = array_map(fn($p) => [
                $p['product_number'],
                mb_substr($p['product_name'] ?? '', 0, 45),
                $p['usage_type'],
                $p['parent_id'] ?: '-'
            ], $products);
            
            $io->table(['Number', 'Name', 'Type', 'Parent ID'], $rows);
        }

        return Command::SUCCESS;
    }
}
