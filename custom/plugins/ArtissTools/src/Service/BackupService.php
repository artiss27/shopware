<?php declare(strict_types=1);

namespace ArtissTools\Service;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;

class BackupService
{
    private const DEFAULT_DB_OUTPUT_DIR = 'var/artiss-backups/db';
    private const DEFAULT_MEDIA_OUTPUT_DIR = 'var/artiss-backups/media';

    private KernelInterface $kernel;
    private string $projectDir;

    public function __construct(KernelInterface $kernel, string $projectDir)
    {
        $this->kernel = $kernel;
        $this->projectDir = $projectDir;
    }

    /**
     * Create database backup
     */
    public function createDbBackup(array $options): array
    {
        $commandArgs = [
            'command' => 'artiss:backup:db',
            '--type' => $options['type'] ?? 'smart',
            '--output-dir' => $options['outputDir'] ?? self::DEFAULT_DB_OUTPUT_DIR,
            '--keep' => (string) ($options['keep'] ?? 3),
        ];

        if (!empty($options['gzip'])) {
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
        $commandArgs = [
            'command' => 'artiss:backup:media',
            '--scope' => $options['scope'] ?? 'all',
            '--output-dir' => $options['outputDir'] ?? self::DEFAULT_MEDIA_OUTPUT_DIR,
            '--keep' => (string) ($options['keep'] ?? 3),
        ];

        if (!empty($options['excludeThumbnails'])) {
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
            ? self::DEFAULT_DB_OUTPUT_DIR 
            : self::DEFAULT_MEDIA_OUTPUT_DIR;
            
        $fullPath = $this->projectDir . '/' . $outputDir;
        
        if (!is_dir($fullPath)) {
            return null;
        }

        $pattern = $backupType === 'db' 
            ? $fullPath . '/shopware-db-*.sql*'
            : $fullPath . '/media-backup-*.tar.gz';
            
        $files = glob($pattern);
        
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
     * Get list of all backups
     */
    public function getBackupsList(string $backupType): array
    {
        $outputDir = $backupType === 'db' 
            ? self::DEFAULT_DB_OUTPUT_DIR 
            : self::DEFAULT_MEDIA_OUTPUT_DIR;
            
        $fullPath = $this->projectDir . '/' . $outputDir;
        
        if (!is_dir($fullPath)) {
            return [];
        }

        $pattern = $backupType === 'db' 
            ? $fullPath . '/shopware-db-*.sql*'
            : $fullPath . '/media-backup-*.tar.gz';
            
        $files = glob($pattern);
        
        if (empty($files)) {
            return [];
        }

        // Sort by modification time (newest first)
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        
        $backups = [];
        foreach ($files as $file) {
            $filename = basename($file);
            $backups[] = [
                'filename' => $filename,
                'path' => $file,
                'size' => filesize($file),
                'sizeFormatted' => $this->formatBytes(filesize($file)),
                'createdAt' => date('Y-m-d H:i:s', filemtime($file)),
                'type' => $this->parseBackupType($filename, $backupType),
            ];
        }

        return $backups;
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

