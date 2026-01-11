<?php declare(strict_types=1);

namespace Artiss\Supplier\Command;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Media\MediaEntity;
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

/**
 * Description:
 *   Cleans up orphaned price list files from Suppliers Prices media folder.
 *   Deletes physical files, database records, and thumbnails older than specified days
 *   that are not linked to any supplier.
 *
 * Usage:
 *   bin/console artiss:supplier:cleanup-orphaned-pricelists [options]
 *
 * Options:
 *   --days=VALUE, -d VALUE    Delete files older than X days that are not linked to any supplier (default: 7)
 *   --dry-run                 Show what would be deleted without actually deleting
 *
 * Example:
 *   bin/console artiss:supplier:cleanup-orphaned-pricelists --days=7 --dry-run
 */
#[AsCommand(
    name: 'artiss:supplier:cleanup-orphaned-pricelists',
    description: 'Cleans up orphaned price list files from Suppliers Prices folder'
)]
class CleanupOrphanedPriceListsCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $mediaFolderRepository,
        private readonly Connection $connection,
        private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_OPTIONAL,
            'Delete files older than X days that are not linked to any supplier',
            7
        );

        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Show what would be deleted without actually deleting'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();
        $days = (int) $input->getOption('days');
        $dryRun = $input->getOption('dry-run');

        // Find Suppliers Prices folder
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'Suppliers Prices'));
        $folder = $this->mediaFolderRepository->search($criteria, $context)->first();

        if (!$folder) {
            $io->error('Media folder "Suppliers Prices" not found.');
            return Command::FAILURE;
        }

        $folderId = $folder->getId();
        $io->info("Checking folder: Suppliers Prices (ID: {$folderId})");

        // Find all media files in this folder
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mediaFolderId', $folderId));
        $allMedia = $this->mediaRepository->search($criteria, $context);

        $io->info(sprintf('Found %d files in Suppliers Prices folder', $allMedia->count()));

        $orphanedFiles = [];
        $cutoffDate = new \DateTime("-{$days} days");

        /** @var MediaEntity $media */
        foreach ($allMedia as $media) {
            // Check if media is linked to any supplier
            $linkedCount = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM art_supplier_media WHERE media_id = :mediaId',
                ['mediaId' => hex2bin($media->getId())]
            );

            if ($linkedCount == 0) {
                // File is not linked to any supplier
                $uploadDate = $media->getUploadedAt() ?? $media->getCreatedAt();

                if ($uploadDate && $uploadDate < $cutoffDate) {
                    $orphanedFiles[] = [
                        'id' => $media->getId(),
                        'fileName' => $media->getFileName(),
                        'uploadedAt' => $uploadDate->format('Y-m-d H:i:s'),
                        'fileSize' => $media->getFileSize(),
                        'path' => $media->getPath(),
                    ];
                }
            }
        }

        if (empty($orphanedFiles)) {
            $io->success(sprintf('No orphaned files found older than %d days.', $days));
            return Command::SUCCESS;
        }

        $io->warning(sprintf('Found %d orphaned files older than %d days:', count($orphanedFiles), $days));

        $io->table(
            ['File Name', 'Uploaded At', 'Size (bytes)'],
            array_map(fn($file) => [$file['fileName'], $file['uploadedAt'], $file['fileSize']], $orphanedFiles)
        );

        if ($dryRun) {
            $io->note('DRY RUN: No files were deleted. Remove --dry-run to actually delete files.');
            return Command::SUCCESS;
        }

        if (!$input->getOption('no-interaction') && !$io->confirm('Do you want to delete these files?', false)) {
            $io->info('Cleanup cancelled.');
            return Command::SUCCESS;
        }

        // Delete files and database records
        $deletedCount = 0;
        $deletedFilesCount = 0;
        $deletedRecordsCount = 0;
        $deletedThumbnailsCount = 0;

        foreach ($orphanedFiles as $file) {
            try {
                // Delete from art_supplier_media table (if exists)
                $deleted = $this->connection->executeStatement(
                    'DELETE FROM art_supplier_media WHERE media_id = :mediaId',
                    ['mediaId' => hex2bin($file['id'])]
                );
                if ($deleted > 0) {
                    $deletedRecordsCount++;
                }

                // Delete thumbnails
                $thumbnailsDeleted = $this->connection->executeStatement(
                    'DELETE FROM media_thumbnail WHERE media_id = :mediaId',
                    ['mediaId' => hex2bin($file['id'])]
                );
                $deletedThumbnailsCount += $thumbnailsDeleted;

                // Delete physical file
                if (!empty($file['path'])) {
                    $filePath = $this->projectDir . '/public/media/' . ltrim($file['path'], '/');
                    if (file_exists($filePath) && is_file($filePath)) {
                        if (@unlink($filePath)) {
                            $deletedFilesCount++;
                        } else {
                            $io->warning(sprintf('Failed to delete physical file: %s', $filePath));
                        }
                    }
                }

                // Delete media record from database (this also triggers Shopware cleanup events)
                $this->mediaRepository->delete([['id' => $file['id']]], $context);
                $deletedCount++;
            } catch (\Exception $e) {
                $io->error(sprintf('Failed to delete %s: %s', $file['fileName'], $e->getMessage()));
            }
        }

        $io->success(sprintf(
            'Successfully deleted %d orphaned files (%d physical files, %d database records, %d thumbnails).',
            $deletedCount,
            $deletedFilesCount,
            $deletedRecordsCount,
            $deletedThumbnailsCount
        ));

        return Command::SUCCESS;
    }
}
