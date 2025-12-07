<?php declare(strict_types=1);

namespace Artiss\MediaOptimizer\Command;

use Artiss\MediaOptimizer\Service\ConfigService;
use Artiss\MediaOptimizer\Service\ImageFormatConverter;
use Artiss\MediaOptimizer\Service\ImageResizer;
use Artiss\MediaOptimizer\Service\OriginalMediaArchiver;
use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Converts existing image media files to WebP format.
 *
 * This command performs bulk conversion of existing media files in the Shopware media library.
 * It processes files in batches and applies the same optimization rules as the upload interceptor.
 *
 * ## Supported formats:
 * - JPEG (image/jpeg)
 * - PNG (image/png)
 * - GIF (image/gif) - static only, animated GIFs lose animation
 * - BMP (image/bmp)
 * - TIFF (image/tiff) - requires Imagick extension
 * - HEIC/HEIF (image/heic, image/heif) - requires Imagick with libheif
 *
 * ## What this command does:
 *
 * 1. **Finds media to convert**: Searches for all media entities with supported image mimeTypes
 *
 * 2. **Archives originals** (if keep_original=true):
 *    - Copies original file to var/artiss_media/original/{hash}/{mediaId}.{ext}
 *    - Saves relative path in media.customFields['artiss_original_path']
 *    - Skips archiving if already archived (checks customFields)
 *
 * 3. **Resizes** (if enable_resize=true and image exceeds max dimensions):
 *    - Downscales to fit within max_width x max_height
 *    - Preserves aspect ratio (no upscaling, no distortion)
 *
 * 4. **Converts to WebP**:
 *    - Uses configured quality (webp_quality setting, default 85)
 *    - Preserves alpha channel for PNGs
 *
 * 5. **Updates filesystem**:
 *    - Writes new WebP file (e.g., media/aa/bb/image.webp)
 *    - Deletes old JPEG/PNG file
 *    - Deletes old thumbnail files
 *
 * 6. **Updates database** (media table only):
 *    - mime_type = 'image/webp'
 *    - file_extension = 'webp'
 *    - path = new path with .webp extension
 *    - file_size = new file size
 *    - Deletes records from media_thumbnail table
 *
 * ## What this command does NOT change:
 *
 * - **Entity relations are preserved**: The media.id stays the same, so all relations
 *   (product_media, category.media_id, cms_media, etc.) remain intact and valid.
 *   These entities reference media by ID, not by file path.
 *
 * - **URLs update automatically**: Since Shopware generates URLs from media.path,
 *   all URLs will automatically point to the new WebP file.
 *
 * ## After running this command:
 *
 * Run `bin/console media:generate-thumbnails` to regenerate thumbnails in WebP format.
 *
 * ## Usage examples:
 *
 * ```bash
 * # Dry run - see what would be converted without making changes
 * bin/console artiss:media:convert-to-webp --dry-run
 *
 * # Convert all JPEG/PNG files
 * bin/console artiss:media:convert-to-webp
 *
 * # Convert in smaller batches (useful for large media libraries)
 * bin/console artiss:media:convert-to-webp --limit=50
 *
 * # Only convert files that haven't been converted yet
 * bin/console artiss:media:convert-to-webp --only-not-converted
 *
 * # Convert only media from a specific folder (by folder ID)
 * bin/console artiss:media:convert-to-webp --folder=0188b4a2c3e87a5e9d8c2e3f4a5b6c7d
 *
 * # Convert only media from a specific folder (by folder name)
 * bin/console artiss:media:convert-to-webp --folder-name="Product Media"
 * ```
 */
#[AsCommand(
    name: 'artiss:media:convert-to-webp',
    description: 'Convert existing image files (JPEG, PNG, GIF, BMP, TIFF, HEIC) to WebP format'
)]
class ConvertMediaToWebpCommand extends Command
{

    public function __construct(
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $mediaFolderRepository,
        private readonly FilesystemOperator $filesystemPublic,
        private readonly FilesystemOperator $filesystemPrivate,
        private readonly Connection $connection,
        private readonly ConfigService $configService,
        private readonly ImageFormatConverter $imageFormatConverter,
        private readonly ImageResizer $imageResizer,
        private readonly OriginalMediaArchiver $originalMediaArchiver,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Number of media items to process per batch',
                '200'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Only log what would be done without making changes'
            )
            ->addOption(
                'only-not-converted',
                null,
                InputOption::VALUE_NONE,
                'Only process media that is not already WebP'
            )
            ->addOption(
                'folder',
                'f',
                InputOption::VALUE_REQUIRED,
                'Only process media from a specific folder (by folder ID)'
            )
            ->addOption(
                'folder-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Only process media from a specific folder (by folder name)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        $dryRun = (bool) $input->getOption('dry-run');
        $onlyNotConverted = (bool) $input->getOption('only-not-converted');
        $folderId = $input->getOption('folder');
        $folderName = $input->getOption('folder-name');

        $context = Context::createDefaultContext();

        // Resolve folder name to ID if provided
        if ($folderName !== null) {
            $folderId = $this->resolveFolderIdByName($folderName, $context);
            if ($folderId === null) {
                $io->error(sprintf('Media folder with name "%s" not found', $folderName));
                return Command::FAILURE;
            }
            $io->text(sprintf('Resolved folder "%s" to ID: %s', $folderName, $folderId));
        }

        if ($dryRun) {
            $io->warning('DRY RUN MODE - No changes will be made');
        }

        $io->title('Artiss Media Optimizer - WebP Conversion');
        $io->text([
            sprintf('WebP Quality: %d', $this->configService->getWebpQuality()),
            sprintf('Resize enabled: %s', $this->configService->isResizeEnabled() ? 'Yes' : 'No'),
            sprintf('Max dimensions: %dx%d', $this->configService->getMaxWidth(), $this->configService->getMaxHeight()),
            sprintf('Keep originals: %s', $this->configService->shouldKeepOriginal() ? 'Yes' : 'No'),
            sprintf('Folder filter: %s', $folderId ?? 'All folders'),
        ]);

        $totalCount = $this->countMediaToProcess($onlyNotConverted, $folderId, $context);
        $io->text(sprintf('Found %d media items to process', $totalCount));

        if ($totalCount === 0) {
            $io->success('No media items to convert');
            return Command::SUCCESS;
        }

        $io->progressStart($totalCount);

        $processed = 0;
        $converted = 0;
        $errors = 0;
        $offset = 0;

        while ($offset < $totalCount) {
            $mediaItems = $this->fetchMediaBatch($onlyNotConverted, $folderId, $limit, $offset, $context);

            foreach ($mediaItems as $media) {
                $processed++;

                try {
                    if (!$dryRun) {
                        $this->processMediaEntity($media, $context, $io);
                    }
                    $converted++;
                } catch (\Throwable $e) {
                    $errors++;
                    $io->error(sprintf(
                        'Failed to convert media %s: %s',
                        $media->getId(),
                        $e->getMessage()
                    ));
                    $this->logger->error('Media conversion failed', [
                        'mediaId' => $media->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }

                $io->progressAdvance();
            }

            $offset += $limit;
        }

        $io->progressFinish();

        $io->newLine(2);
        $io->table(
            ['Metric', 'Count'],
            [
                ['Processed', $processed],
                ['Converted', $converted],
                ['Errors', $errors],
            ]
        );

        if ($converted > 0 && !$dryRun) {
            $io->note('Consider running "bin/console media:generate-thumbnails" to regenerate thumbnails');
        }

        if ($errors > 0) {
            $io->warning(sprintf('%d media items failed to convert. Check logs for details.', $errors));
            return Command::FAILURE;
        }

        $io->success(sprintf('Successfully processed %d media items', $converted));
        return Command::SUCCESS;
    }

    private function countMediaToProcess(bool $onlyNotConverted, ?string $folderId, Context $context): int
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('mimeType', ImageFormatConverter::SUPPORTED_MIME_TYPES));
        $criteria->setLimit(1);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        if ($onlyNotConverted) {
            $criteria->addFilter(
                new NotFilter(NotFilter::CONNECTION_AND, [
                    new EqualsAnyFilter('mimeType', ['image/webp']),
                ])
            );
        }

        if ($folderId !== null) {
            $criteria->addFilter(new EqualsFilter('mediaFolderId', $folderId));
        }

        return $this->mediaRepository->search($criteria, $context)->getTotal();
    }

    private function fetchMediaBatch(bool $onlyNotConverted, ?string $folderId, int $limit, int $offset, Context $context): iterable
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('mimeType', ImageFormatConverter::SUPPORTED_MIME_TYPES));
        $criteria->setLimit($limit);
        $criteria->setOffset($offset);

        if ($onlyNotConverted) {
            $criteria->addFilter(
                new NotFilter(NotFilter::CONNECTION_AND, [
                    new EqualsAnyFilter('mimeType', ['image/webp']),
                ])
            );
        }

        if ($folderId !== null) {
            $criteria->addFilter(new EqualsFilter('mediaFolderId', $folderId));
        }

        return $this->mediaRepository->search($criteria, $context)->getEntities();
    }

    private function resolveFolderIdByName(string $folderName, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $folderName));
        $criteria->setLimit(1);

        $result = $this->mediaFolderRepository->searchIds($criteria, $context);

        if ($result->getTotal() === 0) {
            return null;
        }

        return $result->firstId();
    }

    private function processMediaEntity(MediaEntity $media, Context $context, SymfonyStyle $io): void
    {
        $mediaPath = $media->getPath();
        if ($mediaPath === null) {
            throw new \RuntimeException('Media has no path');
        }

        $filesystem = $media->isPrivate() ? $this->filesystemPrivate : $this->filesystemPublic;

        if (!$filesystem->fileExists($mediaPath)) {
            throw new \RuntimeException(sprintf('Media file not found: %s', $mediaPath));
        }

        $tempPath = $this->downloadToTemp($filesystem, $mediaPath);
        $tempFilesToCleanup = [$tempPath];

        try {
            $originalExtension = $media->getFileExtension();

            if ($this->configService->shouldKeepOriginal()) {
                $customFields = $media->getCustomFields() ?? [];
                if (!isset($customFields['artiss_original_path'])) {
                    $this->originalMediaArchiver->archive(
                        $tempPath,
                        $media->getId(),
                        $originalExtension,
                        $this->configService->getOriginalStorageDir(),
                        $context
                    );
                }
            }

            $currentPath = $tempPath;

            if ($this->configService->isResizeEnabled()) {
                $resizedPath = $this->imageResizer->resize(
                    $currentPath,
                    $this->configService->getMaxWidth(),
                    $this->configService->getMaxHeight()
                );

                if ($resizedPath !== null) {
                    $tempFilesToCleanup[] = $resizedPath;
                    $currentPath = $resizedPath;
                }
            }

            $webpPath = $this->imageFormatConverter->convertToWebp(
                $currentPath,
                $this->configService->getWebpQuality()
            );
            $tempFilesToCleanup[] = $webpPath;

            $newMediaPath = $this->replaceExtension($mediaPath, 'webp');
            $webpContent = file_get_contents($webpPath);

            $filesystem->write($newMediaPath, $webpContent);

            if ($mediaPath !== $newMediaPath) {
                $filesystem->delete($mediaPath);
            }

            $this->updateMediaRecord($media->getId(), $newMediaPath, filesize($webpPath));

            $this->deleteExistingThumbnails($media, $filesystem);
        } finally {
            foreach ($tempFilesToCleanup as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        }
    }

    private function downloadToTemp(FilesystemOperator $filesystem, string $path): string
    {
        $content = $filesystem->read($path);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $tempPath = sys_get_temp_dir() . '/artiss_conv_' . bin2hex(random_bytes(8)) . '.' . $extension;

        file_put_contents($tempPath, $content);

        return $tempPath;
    }

    private function replaceExtension(string $path, string $newExtension): string
    {
        $pathInfo = pathinfo($path);
        $directory = $pathInfo['dirname'] ?? '';
        $filename = $pathInfo['filename'] ?? '';

        return rtrim($directory, '/') . '/' . $filename . '.' . $newExtension;
    }

    private function updateMediaRecord(string $mediaId, string $newPath, int $fileSize): void
    {
        $this->connection->executeStatement(
            'UPDATE media SET
                mime_type = :mimeType,
                file_extension = :extension,
                path = :path,
                file_size = :fileSize,
                updated_at = :updatedAt
             WHERE id = :id',
            [
                'mimeType' => 'image/webp',
                'extension' => 'webp',
                'path' => $newPath,
                'fileSize' => $fileSize,
                'updatedAt' => (new \DateTime())->format('Y-m-d H:i:s.v'),
                'id' => hex2bin($mediaId),
            ]
        );
    }

    private function deleteExistingThumbnails(MediaEntity $media, FilesystemOperator $filesystem): void
    {
        $thumbnails = $media->getThumbnails();
        if ($thumbnails === null) {
            return;
        }

        foreach ($thumbnails as $thumbnail) {
            $thumbPath = $thumbnail->getPath();
            if ($thumbPath !== null && $filesystem->fileExists($thumbPath)) {
                try {
                    $filesystem->delete($thumbPath);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to delete thumbnail', [
                        'path' => $thumbPath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->connection->executeStatement(
            'DELETE FROM media_thumbnail WHERE media_id = :mediaId',
            ['mediaId' => hex2bin($media->getId())]
        );
    }
}
