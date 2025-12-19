<?php declare(strict_types=1);

namespace ArtissTools\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;

class BackupService
{
    private const CONFIG_PREFIX = 'ArtissTools.config.';
    private const DEFAULT_BACKUP_PATH = 'artiss-backups';
    private const DEFAULT_RETENTION = 5;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly string $projectDir,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    /**
     * Get plugin configuration with defaults
     */
    public function getConfig(): array
    {
        return [
            'backupPath' => $this->getConfigValue('backupPath', self::DEFAULT_BACKUP_PATH),
            'backupRetention' => (int) $this->getConfigValue('backupRetention', self::DEFAULT_RETENTION),
            'dbGzipDefault' => (bool) $this->getConfigValue('dbGzipDefault', true),
            'dbTypeDefault' => $this->getConfigValue('dbTypeDefault', 'smart'),
            'mediaScopeDefault' => $this->getConfigValue('mediaScopeDefault', 'all'),
            'mediaExcludeThumbnailsDefault' => (bool) $this->getConfigValue('mediaExcludeThumbnailsDefault', true),
        ];
    }

    /**
     * Get default output directory for DB backups
     */
    public function getDbOutputDir(): string
    {
        $basePath = $this->getConfigValue('backupPath', self::DEFAULT_BACKUP_PATH);
        return rtrim($basePath, '/') . '/db';
    }

    /**
     * Get default output directory for media backups
     */
    public function getMediaOutputDir(): string
    {
        $basePath = $this->getConfigValue('backupPath', self::DEFAULT_BACKUP_PATH);
        return rtrim($basePath, '/') . '/media';
    }

    /**
     * Get project directory path
     */
    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    /**
     * Create database backup
     */
    public function createDbBackup(array $options): array
    {
        $config = $this->getConfig();
        
        $commandArgs = [
            'command' => 'artiss:backup:db',
            '--type' => $options['type'] ?? $config['dbTypeDefault'],
            '--output-dir' => $options['outputDir'] ?? $this->getDbOutputDir(),
            '--keep' => (string) ($options['keep'] ?? $config['backupRetention']),
        ];

        $gzip = $options['gzip'] ?? $config['dbGzipDefault'];
        if ($gzip) {
            $commandArgs['--gzip'] = true;
        } else {
            $commandArgs['--no-gzip'] = true;
        }

        if (!empty($options['comment'])) {
            $commandArgs['--comment'] = $options['comment'];
        }

        if (!empty($options['ignoredTables'])) {
            $commandArgs['--ignored-tables'] = $options['ignoredTables'];
        }

        return $this->runCommand($commandArgs);
    }

    /**
     * Create media backup
     */
    public function createMediaBackup(array $options): array
    {
        $config = $this->getConfig();
        
        $commandArgs = [
            'command' => 'artiss:backup:media',
            '--scope' => $options['scope'] ?? $config['mediaScopeDefault'],
            '--output-dir' => $options['outputDir'] ?? $this->getMediaOutputDir(),
            '--keep' => (string) ($options['keep'] ?? $config['backupRetention']),
        ];

        // Compression disabled by default (media files are already compressed)
        if (!empty($options['compress'])) {
            $commandArgs['--compress'] = true;
        }

        $excludeThumbnails = $options['excludeThumbnails'] ?? $config['mediaExcludeThumbnailsDefault'];
        if ($excludeThumbnails) {
            $commandArgs['--exclude-thumbnails'] = true;
        } else {
            $commandArgs['--no-exclude-thumbnails'] = true;
        }

        if (!empty($options['comment'])) {
            $commandArgs['--comment'] = $options['comment'];
        }

        return $this->runCommand($commandArgs);
    }

    /**
     * Get last backup info for a specific type
     */
    public function getLastBackupInfo(string $backupType): ?array
    {
        $outputDir = $backupType === 'db'
            ? $this->getDbOutputDir()
            : $this->getMediaOutputDir();

        $fullPath = $this->projectDir . '/' . $outputDir;

        if (!is_dir($fullPath)) {
            return null;
        }

        if ($backupType === 'db') {
            $files = glob($fullPath . '/shopware-db-*.sql*') ?: [];
            // Filter out .sha256, .meta.txt, and .tmp files
            $files = array_filter($files, function($file) {
                return !str_ends_with($file, '.sha256')
                    && !str_ends_with($file, '.meta.txt')
                    && !str_ends_with($file, '.tmp');
            });
        } else {
            // Find both .tar and .tar.gz media backups
            $filesTar = glob($fullPath . '/media-backup-*.tar') ?: [];
            $filesTarGz = glob($fullPath . '/media-backup-*.tar.gz') ?: [];
            // Combine and exclude .tar.gz from .tar matches, and filter out auxiliary files
            $files = array_merge(
                array_filter($filesTar, fn($f) => !str_ends_with($f, '.tar.gz') && !str_ends_with($f, '.sha256') && !str_ends_with($f, '.meta.txt') && !str_ends_with($f, '.tmp')),
                array_filter($filesTarGz, fn($f) => !str_ends_with($f, '.sha256') && !str_ends_with($f, '.meta.txt') && !str_ends_with($f, '.tmp'))
            );
        }

        if (empty($files)) {
            return null;
        }

        // Sort by modification time (newest first)
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        
        $latestFile = $files[0];
        $filename = basename($latestFile);
        
        // Parse backup info from filename
        $info = [
            'filename' => $filename,
            'path' => $latestFile,
            'relativePath' => str_replace($this->projectDir . '/', '', $latestFile),
            'size' => filesize($latestFile),
            'sizeFormatted' => $this->formatBytes(filesize($latestFile)),
            'createdAt' => date('Y-m-d H:i:s', filemtime($latestFile)),
            'type' => $this->parseBackupType($filename, $backupType),
        ];

        // Try to get comment from meta file
        $metaFile = $latestFile . '.meta.txt';
        if (file_exists($metaFile)) {
            $metaContent = file_get_contents($metaFile);
            if (preg_match('/Comment:\s*(.+)/i', $metaContent, $matches)) {
                $info['comment'] = trim($matches[1]);
            }
        }

        return $info;
    }

    /**
     * Get list of all backups with detailed info
     */
    public function getBackupsList(string $backupType): array
    {
        $outputDir = $backupType === 'db'
            ? $this->getDbOutputDir()
            : $this->getMediaOutputDir();

        $fullPath = $this->projectDir . '/' . $outputDir;

        if (!is_dir($fullPath)) {
            return [];
        }

        if ($backupType === 'db') {
            $files = glob($fullPath . '/shopware-db-*.sql*') ?: [];
            // Filter out .sha256, .meta.txt, and .tmp files
            $files = array_filter($files, function($file) {
                return !str_ends_with($file, '.sha256')
                    && !str_ends_with($file, '.meta.txt')
                    && !str_ends_with($file, '.tmp');
            });
        } else {
            // Find both .tar and .tar.gz media backups
            $filesTar = glob($fullPath . '/media-backup-*.tar') ?: [];
            $filesTarGz = glob($fullPath . '/media-backup-*.tar.gz') ?: [];
            // Combine and exclude .tar.gz from .tar matches, and filter out auxiliary files
            $files = array_merge(
                array_filter($filesTar, fn($f) => !str_ends_with($f, '.tar.gz') && !str_ends_with($f, '.sha256') && !str_ends_with($f, '.meta.txt') && !str_ends_with($f, '.tmp')),
                array_filter($filesTarGz, fn($f) => !str_ends_with($f, '.sha256') && !str_ends_with($f, '.meta.txt') && !str_ends_with($f, '.tmp'))
            );
        }

        if (empty($files)) {
            return [];
        }

        // Sort by modification time (newest first)
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        $backups = [];
        foreach ($files as $file) {
            $filename = basename($file);
            $relativePath = str_replace($this->projectDir . '/', '', $file);
            $exists = file_exists($file);
            
            $backup = [
                'id' => md5($file),
                'filename' => $filename,
                'path' => $file,
                'relativePath' => $relativePath,
                'size' => $exists ? filesize($file) : 0,
                'sizeFormatted' => $exists ? $this->formatBytes(filesize($file)) : '0 B',
                'createdAt' => $exists ? date('Y-m-d H:i:s', filemtime($file)) : null,
                'type' => $this->parseBackupType($filename, $backupType),
                'exists' => $exists,
                'comment' => null,
            ];

            // Try to get comment
            $backup['comment'] = $this->extractComment($file, $backupType);
            
            $backups[] = $backup;
        }

        return $backups;
    }

    /**
     * Delete a backup file
     */
    public function deleteBackup(string $relativePath): array
    {
        $fullPath = $this->projectDir . '/' . $relativePath;

        if (!file_exists($fullPath)) {
            return [
                'success' => false,
                'error' => 'Backup file not found: ' . $relativePath,
            ];
        }

        // Security check: ensure file is within backup directories
        $allowedDirs = [
            $this->projectDir . '/' . $this->getDbOutputDir(),
            $this->projectDir . '/' . $this->getMediaOutputDir(),
        ];

        $realPath = realpath($fullPath);
        $isAllowed = false;
        foreach ($allowedDirs as $dir) {
            if (is_dir($dir) && str_starts_with($realPath, realpath($dir))) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            return [
                'success' => false,
                'error' => 'Access denied: file is not in backup directory',
            ];
        }

        // Delete the file
        if (!unlink($fullPath)) {
            return [
                'success' => false,
                'error' => 'Failed to delete backup file',
            ];
        }

        // Also delete auxiliary files
        $checksumFile = $fullPath . '.sha256';
        if (file_exists($checksumFile)) {
            unlink($checksumFile);
        }

        $metaFile = $fullPath . '.meta.txt';
        if (file_exists($metaFile)) {
            unlink($metaFile);
        }

        return [
            'success' => true,
        ];
    }

    /**
     * Get backup file for download
     */
    public function getBackupForDownload(string $relativePath): array
    {
        $fullPath = $this->projectDir . '/' . $relativePath;

        if (!file_exists($fullPath)) {
            return [
                'success' => false,
                'error' => 'Backup file not found: ' . $relativePath,
            ];
        }

        // Security check: ensure file is within backup directories
        $allowedDirs = [
            $this->projectDir . '/' . $this->getDbOutputDir(),
            $this->projectDir . '/' . $this->getMediaOutputDir(),
        ];

        $realPath = realpath($fullPath);
        $isAllowed = false;
        foreach ($allowedDirs as $dir) {
            if (is_dir($dir) && str_starts_with($realPath, realpath($dir))) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            return [
                'success' => false,
                'error' => 'Access denied: file is not in backup directory',
            ];
        }

        return [
            'success' => true,
            'fullPath' => $realPath,
            'filename' => basename($realPath),
            'filesize' => filesize($realPath),
        ];
    }

    /**
     * Restore database from backup
     */
    public function restoreDb(array $options): array
    {
        $commandArgs = [
            'command' => 'artiss:restore:db',
            'backup-file' => $options['backupFile'],
            '--force' => true,
        ];

        if (!empty($options['dropTables'])) {
            $commandArgs['--drop-tables'] = true;
        }

        if (!empty($options['noForeignChecks'])) {
            $commandArgs['--no-foreign-checks'] = true;
        }

        return $this->runCommand($commandArgs);
    }

    /**
     * Restore media from backup
     */
    public function restoreMedia(array $options): array
    {
        $commandArgs = [
            'command' => 'artiss:restore:media',
            'backup-file' => $options['backupFile'],
            '--mode' => $options['mode'] ?? 'add',
            '--force' => true,
        ];

        if (!empty($options['dryRun'])) {
            $commandArgs['--dry-run'] = true;
        }

        return $this->runCommand($commandArgs);
    }

    /**
     * Extract comment from backup file
     */
    private function extractComment(string $filePath, string $backupType): ?string
    {
        // Try meta file first
        $metaFile = $filePath . '.meta.txt';
        if (file_exists($metaFile)) {
            $metaContent = file_get_contents($metaFile);
            if (preg_match('/Comment:\s*(.+)/i', $metaContent, $matches)) {
                return trim($matches[1]);
            }
        }

        // For DB backups, try to read comment from SQL file header
        if ($backupType === 'db' && file_exists($filePath)) {
            $isGzipped = str_ends_with($filePath, '.gz');
            
            if ($isGzipped) {
                $handle = gzopen($filePath, 'r');
                if ($handle) {
                    $header = '';
                    $lineCount = 0;
                    while (!gzeof($handle) && $lineCount < 15) {
                        $header .= gzgets($handle, 1024);
                        $lineCount++;
                    }
                    gzclose($handle);
                    
                    if (preg_match('/-- Comment:\s*(.+)/i', $header, $matches)) {
                        return trim($matches[1]);
                    }
                }
            } else {
                $handle = fopen($filePath, 'r');
                if ($handle) {
                    $header = fread($handle, 2048);
                    fclose($handle);
                    
                    if (preg_match('/-- Comment:\s*(.+)/i', $header, $matches)) {
                        return trim($matches[1]);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Run console command
     */
    private function runCommand(array $commandArgs): array
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput($commandArgs);
        $output = new BufferedOutput();

        try {
            $exitCode = $application->run($input, $output);
            $outputContent = $output->fetch();

            return [
                'success' => $exitCode === 0,
                'exitCode' => $exitCode,
                'output' => $outputContent,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'exitCode' => 1,
                'output' => $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    private function getConfigValue(string $key, mixed $default): mixed
    {
        $value = $this->systemConfigService->get(self::CONFIG_PREFIX . $key);
        return $value ?? $default;
    }

    private function parseBackupType(string $filename, string $backupType): string
    {
        if ($backupType === 'db') {
            if (str_contains($filename, '-smart-')) {
                return 'smart';
            }
            if (str_contains($filename, '-full-')) {
                return 'full';
            }
        } else {
            if (str_contains($filename, '-all-')) {
                return 'all';
            }
            if (str_contains($filename, '-product-')) {
                return 'product';
            }
        }
        return 'unknown';
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);

        return sprintf('%.2f %s', $bytes / pow(1024, $factor), $units[$factor]);
    }
}
