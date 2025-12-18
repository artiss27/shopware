<?php declare(strict_types=1);

/**
 * Description:
 *   Creates a tar archive of Shopware media files.
 *   By default creates uncompressed .tar (media files are already compressed).
 *   Supports full media backup or product-only scope with optional thumbnail exclusion.
 *
 * Usage:
 *   bin/console artiss:backup:media [options]
 *
 * Options:
 *   --scope=all|product       Backup scope: all media or product media only (default: all)
 *   --output-dir=PATH         Directory to save backup (default: artiss-backups/media)
 *   --keep=INT                Number of backups to keep (default: 5)
 *   --compress                Compress archive with gzip (slower, minimal size reduction for media)
 *   --exclude-thumbnails      Exclude thumbnail files from backup
 *   --no-exclude-thumbnails   Include all thumbnails in backup
 *   --comment="TEXT"          Comment to include in backup metadata
 *
 * Example:
 *   bin/console artiss:backup:media --scope=product --exclude-thumbnails --comment="Before update"
 */

namespace ArtissTools\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'artiss:backup:media',
    description: 'Create a media files backup (all or product media)'
)]
class BackupMediaCommand extends Command
{
    private const THUMBNAIL_PATTERNS = [
        '*_thumbnail_*',
        '*_thumb_*',
        'thumbnail/*',
        'thumbnails/*',
    ];

    private string $projectDir;
    private Connection $connection;

    public function __construct(string $projectDir, Connection $connection)
    {
        parent::__construct();
        $this->projectDir = $projectDir;
        $this->connection = $connection;
    }

    protected function configure(): void
    {
        $this
            ->addOption('scope', 's', InputOption::VALUE_REQUIRED, 'Backup scope: all or product', 'all')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory', 'artiss-backups/media')
            ->addOption('keep', 'k', InputOption::VALUE_REQUIRED, 'Number of backups to keep', '5')
            ->addOption('compress', null, InputOption::VALUE_NONE, 'Compress with gzip (slower, minimal benefit for media)')
            ->addOption('exclude-thumbnails', null, InputOption::VALUE_NONE, 'Exclude thumbnails from backup')
            ->addOption('no-exclude-thumbnails', null, InputOption::VALUE_NONE, 'Include thumbnails in backup')
            ->addOption('comment', 'c', InputOption::VALUE_REQUIRED, 'Comment for this backup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $scope = $input->getOption('scope');
        $outputDir = $input->getOption('output-dir');
        $keep = (int) $input->getOption('keep');
        $compress = $input->getOption('compress');
        $excludeThumbnails = $input->getOption('exclude-thumbnails');
        $noExcludeThumbnails = $input->getOption('no-exclude-thumbnails');
        $comment = $input->getOption('comment');

        // Validate scope
        if (!in_array($scope, ['all', 'product'], true)) {
            $io->error('Invalid scope. Use "all" or "product".');
            return Command::FAILURE;
        }

        // Validate thumbnail options
        if ($excludeThumbnails && $noExcludeThumbnails) {
            $io->error('Cannot use both --exclude-thumbnails and --no-exclude-thumbnails options.');
            return Command::FAILURE;
        }

        $skipThumbnails = $excludeThumbnails && !$noExcludeThumbnails;

        // Prepare paths
        $mediaPath = $this->projectDir . '/public/media';
        if (!is_dir($mediaPath)) {
            $io->error(sprintf('Media directory not found: %s', $mediaPath));
            return Command::FAILURE;
        }

        // Prepare output directory
        $outputPath = $this->prepareOutputDirectory($outputDir);
        if ($outputPath === null) {
            $io->error(sprintf('Failed to create output directory: %s', $outputDir));
            return Command::FAILURE;
        }

        // Generate filename (no compression by default for media)
        $timestamp = date('Ymd-His');
        $extension = $compress ? 'tar.gz' : 'tar';
        $filename = sprintf('media-backup-%s-%s.%s', $scope, $timestamp, $extension);
        $filePath = $outputPath . '/' . $filename;

        $io->title('ArtissTools Media Backup');
        $io->text([
            sprintf('Scope: <info>%s</info>', $scope),
            sprintf('Output: <info>%s</info>', $filePath),
            sprintf('Compression: <info>%s</info>', $compress ? 'gzip' : 'none (faster)'),
            sprintf('Exclude thumbnails: <info>%s</info>', $skipThumbnails ? 'yes' : 'no'),
        ]);

        $io->newLine();
        $io->text('Preparing file list...');

        try {
            // Get files to backup
            $files = $this->collectFiles($mediaPath, $scope, $skipThumbnails, $io);
            
            if (empty($files)) {
                $io->warning('No files found to backup.');
                return Command::SUCCESS;
            }

            $io->text(sprintf('Found <info>%d</info> files to backup', count($files)));
            $io->newLine();
            $io->text('Creating archive...');

            // Create archive
            $this->createArchive($mediaPath, $files, $filePath, $compress, $comment, $io);

        } catch (\Exception $e) {
            $io->error(sprintf('Backup failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        // Check if file was created
        if (!file_exists($filePath)) {
            $io->error('Backup archive was not created.');
            return Command::FAILURE;
        }

        $fileSize = filesize($filePath);
        $io->newLine();
        $io->success([
            'Media backup created successfully!',
            sprintf('File: %s', $filePath),
            sprintf('Size: %s', $this->formatBytes($fileSize)),
            sprintf('Files: %d', count($files)),
        ]);

        // Save comment to metadata file
        if ($comment) {
            $metaFile = $filePath . '.meta.txt';
            $metaContent = sprintf(
                "ArtissTools Media Backup\n" .
                "========================\n" .
                "Scope: %s\n" .
                "Created: %s\n" .
                "Files: %d\n" .
                "Exclude thumbnails: %s\n" .
                "Comment: %s\n",
                $scope,
                date('Y-m-d H:i:s'),
                count($files),
                $skipThumbnails ? 'yes' : 'no',
                $comment
            );
            file_put_contents($metaFile, $metaContent);
        }

        // Create checksum file
        $this->createChecksum($filePath, $io);

        // Cleanup old backups
        $deleted = $this->cleanupOldBackups($outputPath, $keep, $scope);
        if ($deleted > 0) {
            $io->text(sprintf('Cleaned up <comment>%d</comment> old backup(s)', $deleted));
        }

        return Command::SUCCESS;
    }

    private function prepareOutputDirectory(string $outputDir): ?string
    {
        if (!str_starts_with($outputDir, '/')) {
            $outputDir = $this->projectDir . '/' . $outputDir;
        }

        if (!is_dir($outputDir)) {
            // Create directory recursively with proper permissions
            if (!@mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
                return null;
            }
            // Ensure permissions are set correctly
            @chmod($outputDir, 0755);
        }

        // Verify directory is writable
        if (!is_writable($outputDir)) {
            return null;
        }

        return realpath($outputDir) ?: $outputDir;
    }

    private function collectFiles(string $mediaPath, string $scope, bool $skipThumbnails, SymfonyStyle $io): array
    {
        $files = [];

        if ($scope === 'product') {
            // Get product media paths from database
            $files = $this->getProductMediaFiles($mediaPath, $io);
        } else {
            // Get all files from media directory
            $finder = new Finder();
            $finder->files()->in($mediaPath);

            foreach ($finder as $file) {
                $files[] = $file->getRelativePathname();
            }
        }

        // Filter thumbnails if needed
        if ($skipThumbnails) {
            $files = $this->filterThumbnails($files);
        }

        return $files;
    }

    private function getProductMediaFiles(string $mediaPath, SymfonyStyle $io): array
    {
        $io->text('  Querying product media from database...');

        // Get media paths associated with products
        $sql = "
            SELECT DISTINCT m.path
            FROM media m
            INNER JOIN product_media pm ON pm.media_id = m.id
            WHERE m.path IS NOT NULL AND m.path != ''
        ";

        $paths = $this->connection->fetchAllAssociative($sql);
        
        $files = [];
        foreach ($paths as $row) {
            $relativePath = $row['path'];
            $fullPath = $mediaPath . '/' . $relativePath;
            
            if (file_exists($fullPath)) {
                $files[] = $relativePath;
                
                // Also include thumbnails for this media
                $thumbnailDir = dirname($fullPath) . '/thumbnail';
                if (is_dir($thumbnailDir)) {
                    $basename = pathinfo($relativePath, PATHINFO_FILENAME);
                    $finder = new Finder();
                    $finder->files()->in($thumbnailDir)->name($basename . '*');
                    
                    foreach ($finder as $thumbFile) {
                        $files[] = dirname($relativePath) . '/thumbnail/' . $thumbFile->getFilename();
                    }
                }
            }
        }

        return $files;
    }

    private function filterThumbnails(array $files): array
    {
        return array_filter($files, function ($file) {
            $filename = strtolower($file);
            
            // Skip files in thumbnail directories
            if (str_contains($filename, '/thumbnail/') || str_contains($filename, '/thumbnails/')) {
                return false;
            }
            
            // Skip files with thumbnail patterns in name
            foreach (self::THUMBNAIL_PATTERNS as $pattern) {
                if (fnmatch($pattern, basename($filename))) {
                    return false;
                }
            }
            
            return true;
        });
    }

    private function createArchive(string $mediaPath, array $files, string $outputFile, bool $compress, ?string $comment, SymfonyStyle $io): void
    {
        // Create a temporary file list for tar command
        $fileListPath = sys_get_temp_dir() . '/artiss-media-backup-' . uniqid() . '.txt';
        
        try {
            // Write file list to temp file
            $io->text('  Writing file list...');
            file_put_contents($fileListPath, implode("\n", $files));

            // Add metadata file if comment provided
            $metaFile = null;
            if ($comment) {
                $metaFile = $mediaPath . '/_backup_info.txt';
                $metaContent = sprintf(
                    "Backup Comment: %s\nCreated: %s\nFiles: %d\n",
                    $comment,
                    date('Y-m-d H:i:s'),
                    count($files)
                );
                file_put_contents($metaFile, $metaContent);
                // Add to file list
                file_put_contents($fileListPath, "\n_backup_info.txt", FILE_APPEND);
            }

            $io->text(sprintf('  Creating archive with %d files...', count($files)));

            // Use system tar command with POSIX extended format for long filenames
            // -c = create, -z = gzip (optional), -f = file
            $tarOptions = $compress ? '-czf' : '-cf';
            $cmd = sprintf(
                'cd %s && tar --format=posix %s %s -T %s 2>&1',
                escapeshellarg($mediaPath),
                $tarOptions,
                escapeshellarg($outputFile),
                escapeshellarg($fileListPath)
            );

            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);

            // Cleanup temp metadata file
            if ($metaFile && file_exists($metaFile)) {
                unlink($metaFile);
            }

            if ($returnCode !== 0) {
                throw new \RuntimeException('tar command failed: ' . implode("\n", $output));
            }

        } finally {
            // Cleanup file list
            if (file_exists($fileListPath)) {
                unlink($fileListPath);
            }
        }
    }

    private function cleanupOldBackups(string $outputPath, int $keep, string $scope): int
    {
        // Find both .tar and .tar.gz files
        $patternTar = sprintf('%s/media-backup-%s-*.tar', $outputPath, $scope);
        $patternTarGz = sprintf('%s/media-backup-%s-*.tar.gz', $outputPath, $scope);
        
        $filesTar = glob($patternTar) ?: [];
        $filesTarGz = glob($patternTarGz) ?: [];
        
        // Combine and deduplicate (exclude .tar.gz from .tar matches)
        $files = array_merge(
            array_filter($filesTar, fn($f) => !str_ends_with($f, '.tar.gz')),
            $filesTarGz
        );

        // Filter out auxiliary files (.sha256 and .meta.txt)
        $files = array_filter($files, function($file) {
            return !str_ends_with($file, '.sha256') && !str_ends_with($file, '.meta.txt');
        });

        if (count($files) <= $keep) {
            return 0;
        }

        // Sort by modification time (newest first)
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        $deleted = 0;
        $toDelete = array_slice($files, $keep);

        foreach ($toDelete as $file) {
            if (unlink($file)) {
                $deleted++;
                // Also remove auxiliary files
                $checksumFile = $file . '.sha256';
                if (file_exists($checksumFile)) {
                    unlink($checksumFile);
                }
                $metaFile = $file . '.meta.txt';
                if (file_exists($metaFile)) {
                    unlink($metaFile);
                }
            }
        }

        return $deleted;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);

        return sprintf('%.2f %s', $bytes / pow(1024, $factor), $units[$factor]);
    }

    private function createChecksum(string $filePath, SymfonyStyle $io): ?string
    {
        try {
            $io->text('  Creating checksum...');
            $hash = hash_file('sha256', $filePath);

            if ($hash === false) {
                $io->warning('Failed to create checksum');
                return null;
            }

            $checksumFile = $filePath . '.sha256';
            $checksumContent = sprintf("%s  %s\n", $hash, basename($filePath));

            if (file_put_contents($checksumFile, $checksumContent) === false) {
                $io->warning('Failed to write checksum file');
                return null;
            }

            return $checksumFile;
        } catch (\Exception $e) {
            $io->warning('Checksum creation failed: ' . $e->getMessage());
            return null;
        }
    }
}

