<?php declare(strict_types=1);

namespace ArtissTools\Command;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Check where a property group is used in the system
 *
 * Shows usage statistics and sample products for a property group.
 * Helps determine if a property group can be safely deleted.
 *
 * Output includes:
 * - Property group name and options count
 * - Usage in product_property (regular product properties)
 * - Usage in product_configurator_setting (variant configuration on parent products)
 * - Usage in product_option (variant products)
 * - Sample products using this property group
 *
 * @example
 *   # Check usage of property group by ID
 *   bin/console artiss:check-property-group-usage 92e478d01e0e33e70a51e5914ee7cd6d
 *
 * @example
 *   # Check if property group "Розміри" can be deleted
 *   bin/console artiss:check-property-group-usage f4d5ec3eed5e469644c6de30b41ab275
 */
#[AsCommand(
    name: 'artiss:check-property-group-usage',
    description: 'Check where a property group is used'
)]
class CheckPropertyGroupUsageCommand extends Command
{
    public function __construct(
        private readonly Connection $connection
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('group-id', InputArgument::REQUIRED, 'Property group ID (32-char hex UUID)')
            ->setHelp(<<<'EOF'
Check where a property group is used in the system.

This command shows:
- Property group name and total options count
- Usage in product_property (regular product properties)
- Usage in product_configurator_setting (defines available variant options for parent products)
- Usage in product_option (defines which option a specific variant has)
- Sample parent products with variants
- Sample variant products

If the property group is used in configurator_setting or product_option,
it means it's used for product variants and cannot be deleted without
first removing all variant products or changing their configuration.

Arguments:
  <info>group-id</info>    Property group ID in hex format (32 characters)

Examples:
  <info>bin/console artiss:check-property-group-usage 92e478d01e0e33e70a51e5914ee7cd6d</info>
  <info>bin/console artiss:check-property-group-usage f4d5ec3eed5e469644c6de30b41ab275</info>
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $groupId = $input->getArgument('group-id');

        if (!Uuid::isValid($groupId)) {
            $io->error("Invalid UUID: $groupId");
            return Command::FAILURE;
        }

        $binaryGroupId = Uuid::fromHexToBytes($groupId);

        $io->title("Property Group Usage: $groupId");

        // Group info
        $groupInfo = $this->connection->fetchAssociative("
            SELECT HEX(pg.id) as group_id, pgt.name as group_name
            FROM property_group pg
            JOIN property_group_translation pgt ON pg.id = pgt.property_group_id
            WHERE pg.id = ?
            LIMIT 1
        ", [$binaryGroupId]);

        if (!$groupInfo) {
            $io->error("Property group not found");
            return Command::FAILURE;
        }

        $io->section("Group: {$groupInfo['group_name']}");

        // Options count
        $optionsCount = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM property_group_option WHERE property_group_id = ?",
            [$binaryGroupId]
        );
        $io->writeln("Options in group: $optionsCount");

        // product_property (regular properties)
        $propertyCount = $this->connection->fetchOne("
            SELECT COUNT(*)
            FROM product_property pp
            JOIN property_group_option pgo ON pp.property_group_option_id = pgo.id
            WHERE pgo.property_group_id = ?
        ", [$binaryGroupId]);
        $io->writeln("Used in product_property: $propertyCount");

        // product_configurator_setting (variant configuration)
        $configuratorCount = $this->connection->fetchOne("
            SELECT COUNT(*)
            FROM product_configurator_setting pcs
            JOIN property_group_option pgo ON pcs.property_group_option_id = pgo.id
            WHERE pgo.property_group_id = ?
        ", [$binaryGroupId]);
        $io->writeln("Used in product_configurator_setting: $configuratorCount");

        // product_option (variant options - defines which variant has which option)
        $productOptionCount = $this->connection->fetchOne("
            SELECT COUNT(*)
            FROM product_option po
            JOIN property_group_option pgo ON po.property_group_option_id = pgo.id
            WHERE pgo.property_group_id = ?
        ", [$binaryGroupId]);
        $io->writeln("Used in product_option: $productOptionCount");

        // Sample products with configurator
        if ($configuratorCount > 0) {
            $io->section("Sample parent products with variants (configurator_setting)");
            $products = $this->connection->fetchAllAssociative("
                SELECT DISTINCT HEX(p.id) as product_id, p.product_number, pt.name as product_name
                FROM product p
                JOIN product_translation pt ON p.id = pt.product_id
                JOIN product_configurator_setting pcs ON p.id = pcs.product_id
                JOIN property_group_option pgo ON pcs.property_group_option_id = pgo.id
                WHERE pgo.property_group_id = ?
                LIMIT 10
            ", [$binaryGroupId]);

            $rows = array_map(fn($p) => [$p['product_id'], $p['product_number'], mb_substr($p['product_name'] ?? '', 0, 50)], $products);
            $io->table(['Product ID', 'Number', 'Name'], $rows);
        }

        // Sample variant products
        if ($productOptionCount > 0) {
            $io->section("Sample variant products (product_option)");
            $variants = $this->connection->fetchAllAssociative("
                SELECT DISTINCT HEX(p.id) as product_id, p.product_number, pt.name as product_name, HEX(p.parent_id) as parent_id
                FROM product p
                JOIN product_translation pt ON p.id = pt.product_id
                JOIN product_option po ON p.id = po.product_id
                JOIN property_group_option pgo ON po.property_group_option_id = pgo.id
                WHERE pgo.property_group_id = ?
                LIMIT 10
            ", [$binaryGroupId]);

            $rows = array_map(fn($p) => [$p['product_id'], $p['product_number'], mb_substr($p['product_name'] ?? '', 0, 40), $p['parent_id']], $variants);
            $io->table(['Product ID', 'Number', 'Name', 'Parent ID'], $rows);
        }

        $io->section("Summary");
        $io->note([
            "product_configurator_setting - defines which options are available for parent product variants",
            "product_option - defines which specific option a variant product has",
            "To delete this property group, you need to:",
            "1. Delete all variant products using these options, OR",
            "2. Remove configurator settings from parent products, OR",
            "3. Change variants to use different property group"
        ]);

        return Command::SUCCESS;
    }
}
