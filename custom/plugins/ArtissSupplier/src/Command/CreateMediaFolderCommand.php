<?php declare(strict_types=1);

namespace Artiss\Supplier\Command;

use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderEntity;
use Shopware\Core\Content\Media\Aggregate\MediaFolderConfiguration\MediaFolderConfigurationEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Description:
 *   Creates the "Suppliers Prices" media folder for storing supplier price lists.
 *   Checks if folder already exists before creating.
 *
 * Usage:
 *   bin/console artiss:supplier:create-media-folder
 *
 * Options:
 *   (none)
 *
 * Example:
 *   bin/console artiss:supplier:create-media-folder
 */
#[AsCommand(
    name: 'artiss:supplier:create-media-folder',
    description: 'Creates the Suppliers Prices media folder'
)]
class CreateMediaFolderCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $mediaFolderRepository,
        private readonly EntityRepository $mediaFolderConfigRepository
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        // Check if folder already exists
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'Suppliers Prices'));
        $existing = $this->mediaFolderRepository->search($criteria, $context);

        if ($existing->count() > 0) {
            $io->success('Media folder "Suppliers Prices" already exists.');
            $folder = $existing->first();
            $io->info('Folder ID: ' . $folder->getId());
            return Command::SUCCESS;
        }

        // Create folder configuration
        $configId = Uuid::randomHex();
        $folderId = Uuid::randomHex();

        $this->mediaFolderConfigRepository->create([
            [
                'id' => $configId,
                'createThumbnails' => false,
                'keepAspectRatio' => true,
                'thumbnailQuality' => 80,
            ]
        ], $context);

        // Create media folder
        $this->mediaFolderRepository->create([
            [
                'id' => $folderId,
                'name' => 'Suppliers Prices',
                'configurationId' => $configId,
                'useParentConfiguration' => false,
            ]
        ], $context);

        $io->success('Media folder "Suppliers Prices" created successfully!');
        $io->info('Folder ID: ' . $folderId);

        return Command::SUCCESS;
    }
}
