<?php declare(strict_types=1);

/**
 * Description:
 *   Creates a MySQL database dump of the Shopware database.
 *   Supports "smart" mode (excludes data from cache/log tables) and "full" mode.
 *
 * Usage:
 *   bin/console artiss:backup:db [options]
 *
 * Options:
 *   --type=smart|full        Backup type: smart (default) excludes cache/log data, full includes everything
 *   --output-dir=PATH        Directory to save backup (default: artiss-backups/db)
 *   --keep=INT               Number of backups to keep (default: 5)
 *   --gzip                   Compress output with gzip
 *   --no-gzip                Disable gzip compression
 *   --comment="TEXT"         Comment to include in backup
 *   --ignored-tables=LIST    Comma-separated list of tables to ignore data (smart mode only)
 *
 * Example:
 *   bin/console artiss:backup:db --type=smart --gzip --comment="Before update"
 */

namespace ArtissTools\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'artiss:backup:db',
    description: 'Create a database backup (smart or full mode)'
)]
class BackupDbCommand extends Command
{
    private const DEFAULT_IGNORED_TABLES = [
        'cache',
        'cart',
        'dead_message',
        'elasticsearch_index_task',
        'enqueue',
        'log_entry',
        'message_queue_stats',
        'product_keyword_dictionary',
        'product_search_keyword',
        'refresh_token',
        'webhook_event_log',
    ];

    private string $projectDir;
    private string $databaseUrl;

    public function __construct(string $projectDir, string $databaseUrl)
    {
        parent::__construct();
        $this->projectDir = $projectDir;
        $this->databaseUrl = $databaseUrl;
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Backup type: smart or full', 'smart')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory', 'artiss-backups/db')
            ->addOption('keep', 'k', InputOption::VALUE_REQUIRED, 'Number of backups to keep', '5')
            ->addOption('gzip', null, InputOption::VALUE_NONE, 'Compress with gzip')
            ->addOption('no-gzip', null, InputOption::VALUE_NONE, 'Disable gzip compression')
            ->addOption('comment', 'c', InputOption::VALUE_REQUIRED, 'Comment for this backup')
            ->addOption('ignored-tables', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of tables to ignore data (smart mode)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $type = $input->getOption('type');
        $outputDir = $input->getOption('output-dir');
        $keep = (int) $input->getOption('keep');
        $gzip = $input->getOption('gzip');
        $noGzip = $input->getOption('no-gzip');
        $comment = $input->getOption('comment');
        $ignoredTablesOption = $input->getOption('ignored-tables');

        // Validate type
        if (!in_array($type, ['smart', 'full'], true)) {
            $io->error('Invalid backup type. Use "smart" or "full".');
            return Command::FAILURE;
        }

        // Validate gzip options
        if ($gzip && $noGzip) {
            $io->error('Cannot use both --gzip and --no-gzip options.');
            return Command::FAILURE;
        }

        $useGzip = $gzip && !$noGzip;

        // Parse database URL
        $dbParams = $this->parseDatabaseUrl();
        if ($dbParams === null) {
            $io->error('Failed to parse DATABASE_URL.');
            return Command::FAILURE;
        }

        // Check if mysqldump is available early
        try {
            $mysqldumpPath = $this->findMysqldump();
            $io->text(sprintf('Using: <info>%s</info>', $mysqldumpPath));
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // Prepare output directory
        $outputPath = $this->prepareOutputDirectory($outputDir);
        if ($outputPath === null) {
            $absolutePath = str_starts_with($outputDir, '/') ? $outputDir : $this->projectDir . '/' . $outputDir;
            $io->error([
                sprintf('Failed to create or access output directory: %s', $absolutePath),
                'Please check directory permissions or create it manually.',
            ]);
            return Command::FAILURE;
        }

        // Generate filename
        $timestamp = date('Ymd-His');
        $extension = $useGzip ? 'sql.gz' : 'sql';
        $filename = sprintf('shopware-db-%s-%s.%s', $type, $timestamp, $extension);
        $filePath = $outputPath . '/' . $filename;

        $io->title('ArtissTools Database Backup');
        $io->text([
            sprintf('Type: <info>%s</info>', $type),
            sprintf('Output: <info>%s</info>', $filePath),
            sprintf('Compression: <info>%s</info>', $useGzip ? 'gzip' : 'none'),
        ]);

        // Get ignored tables for smart mode
        $ignoredTables = [];
        if ($type === 'smart') {
            $ignoredTables = $this->getIgnoredTables($ignoredTablesOption, $dbParams);
            $io->text(sprintf('Ignored tables (data only): <comment>%d tables</comment>', count($ignoredTables)));
        }

        $io->newLine();
        $io->text('Creating backup...');

        try {
            if ($type === 'smart') {
                $this->createSmartBackup($dbParams, $filePath, $ignoredTables, $useGzip, $comment, $io);
            } else {
                $this->createFullBackup($dbParams, $filePath, $useGzip, $comment, $io);
            }
        } catch (\Exception $e) {
            $io->error(sprintf('Backup failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        // Check if file was created
        if (!file_exists($filePath)) {
            $io->error('Backup file was not created.');
            return Command::FAILURE;
        }

        $fileSize = filesize($filePath);

        // Save comment to metadata file
        if ($comment) {
            $metaFile = $filePath . '.meta.txt';
            $metaContent = sprintf(
                "ArtissTools Database Backup\n" .
                "===========================\n" .
                "Type: %s\n" .
                "Created: %s\n" .
                "Size: %s\n" .
                "Compression: %s\n" .
                "Comment: %s\n",
                $type,
                date('Y-m-d H:i:s'),
                $this->formatBytes($fileSize),
                $useGzip ? 'gzip' : 'none',
                $comment
            );
            file_put_contents($metaFile, $metaContent);
        }

        // Create checksum file
        $checksumFile = $this->createChecksum($filePath, $io);

        $io->newLine();
        $io->success([
            'Backup created successfully!',
            sprintf('File: %s', $filePath),
            sprintf('Size: %s', $this->formatBytes($fileSize)),
            sprintf('Checksum: %s', $checksumFile ? basename($checksumFile) : 'not created'),
        ]);

        // Cleanup old backups
        $deleted = $this->cleanupOldBackups($outputPath, $keep, $type);
        if ($deleted > 0) {
            $io->text(sprintf('Cleaned up <comment>%d</comment> old backup(s)', $deleted));
        }

        return Command::SUCCESS;
    }

    private function parseDatabaseUrl(): ?array
    {
        $url = $this->databaseUrl;
        
        // Handle custom shopware format or standard URL
        $parsed = parse_url($url);
        
        if ($parsed === false || !isset($parsed['host'])) {
            return null;
        }

        return [
            'host' => $parsed['host'],
            'port' => $parsed['port'] ?? 3306,
            'user' => $parsed['user'] ?? 'root',
            'password' => $parsed['pass'] ?? '',
            'database' => ltrim($parsed['path'] ?? '', '/'),
        ];
    }

    private function prepareOutputDirectory(string $outputDir): ?string
    {
        // Make absolute path if relative
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

    private function getIgnoredTables(?string $ignoredTablesOption, array $dbParams): array
    {
        if ($ignoredTablesOption !== null) {
            $tables = array_map('trim', explode(',', $ignoredTablesOption));
        } else {
            $tables = self::DEFAULT_IGNORED_TABLES;
        }

        // Filter to only existing tables
        $existingTables = $this->getExistingTables($dbParams);
        $tables = array_filter($tables, fn($table) => in_array($table, $existingTables, true));

        // Prefix with database name for mysqldump
        return array_map(fn($table) => $dbParams['database'] . '.' . $table, $tables);
    }

    private function getExistingTables(array $dbParams): array
    {
        $mysqldumpPath = $this->findMysqldump();
        // Use mysql/mariadb client to get table list
        $mysqlPath = str_replace('-dump', '', $mysqldumpPath);
        if (!file_exists($mysqlPath)) {
            $mysqlPath = dirname($mysqldumpPath) . '/mariadb';
            if (!file_exists($mysqlPath)) {
                $mysqlPath = dirname($mysqldumpPath) . '/mysql';
            }
        }

        $cmd = sprintf(
            '%s -h %s -P %d -u %s %s -N -e "SHOW TABLES" %s 2>/dev/null',
            escapeshellarg($mysqlPath),
            escapeshellarg($dbParams['host']),
            $dbParams['port'],
            escapeshellarg($dbParams['user']),
            !empty($dbParams['password']) ? '-p' . escapeshellarg($dbParams['password']) : '',
            escapeshellarg($dbParams['database'])
        );

        $output = [];
        exec($cmd, $output);

        return $output;
    }

    private function createSmartBackup(
        array $dbParams,
        string $filePath,
        array $ignoredTables,
        bool $useGzip,
        ?string $comment,
        SymfonyStyle $io
    ): void {
        $baseArgs = $this->buildMysqldumpArgs($dbParams);
        
        // Create temp file for combining dumps
        $tempFile = $filePath . '.tmp';
        
        // Add header comment
        $header = $this->generateHeader('smart', $comment, $ignoredTables);
        file_put_contents($tempFile, $header);

        // First dump: all tables except ignored (with data)
        $io->text('  Dumping main tables with data...');
        $ignoreArgs = array_map(fn($t) => '--ignore-table=' . $t, $ignoredTables);
        $cmd1 = array_merge($baseArgs, $ignoreArgs, [$dbParams['database']]);
        $this->runMysqldump($cmd1, $tempFile, true);

        // Second dump: ignored tables (structure only)
        $io->text('  Dumping ignored tables (structure only)...');
        $tableNames = array_map(fn($t) => explode('.', $t)[1], $ignoredTables);
        $cmd2 = array_merge($baseArgs, ['--no-data'], [$dbParams['database']], $tableNames);
        $this->runMysqldump($cmd2, $tempFile, true);

        // Compress if needed
        if ($useGzip) {
            $io->text('  Compressing...');
            $this->compressFile($tempFile, $filePath);
            unlink($tempFile);
        } else {
            rename($tempFile, $filePath);
        }
    }

    private function createFullBackup(
        array $dbParams,
        string $filePath,
        bool $useGzip,
        ?string $comment,
        SymfonyStyle $io
    ): void {
        $baseArgs = $this->buildMysqldumpArgs($dbParams);
        
        // Create temp file
        $tempFile = $useGzip ? $filePath . '.tmp' : $filePath;
        
        // Add header comment
        $header = $this->generateHeader('full', $comment);
        file_put_contents($tempFile, $header);

        // Full dump
        $io->text('  Dumping all tables with data...');
        $cmd = array_merge($baseArgs, [$dbParams['database']]);
        $this->runMysqldump($cmd, $tempFile, true);

        // Compress if needed
        if ($useGzip) {
            $io->text('  Compressing...');
            $this->compressFile($tempFile, $filePath);
            unlink($tempFile);
        }
    }

    private function buildMysqldumpArgs(array $dbParams): array
    {
        $args = [
            'mysqldump',
            '-h', $dbParams['host'],
            '-P', (string) $dbParams['port'],
            '-u', $dbParams['user'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
        ];

        if (!empty($dbParams['password'])) {
            $args[] = '-p' . $dbParams['password'];
        }

        return $args;
    }

    private function runMysqldump(array $cmd, string $outputFile, bool $append = false): void
    {
        // Try mysqldump first, then mariadb-dump
        $mysqldumpPath = $this->findMysqldump();
        $cmd[0] = $mysqldumpPath;

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $outputFile, $append ? 'a' : 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);
        
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start mysqldump process');
        }

        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        
        $returnCode = proc_close($process);

        if ($returnCode !== 0) {
            throw new \RuntimeException('mysqldump failed: ' . $stderr);
        }
    }

    private function findMysqldump(): string
    {
        // Prefer mariadb-dump over mysqldump (mysqldump is deprecated in MariaDB)
        $paths = [
            '/usr/bin/mariadb-dump',
            '/usr/local/bin/mariadb-dump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
        ];

        // First check direct paths
        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try using 'which' command - prefer mariadb-dump
        $commands = ['mariadb-dump', 'mysqldump'];
        foreach ($commands as $cmd) {
            $result = shell_exec('which ' . escapeshellarg($cmd) . ' 2>/dev/null');
            if (!empty(trim($result ?? ''))) {
                $foundPath = trim($result);
                if (file_exists($foundPath) && is_executable($foundPath)) {
                    return $foundPath;
                }
            }
        }

        throw new \RuntimeException(
            'mysqldump or mariadb-dump not found. Please ensure MySQL/MariaDB client tools are installed.'
        );
    }

    private function generateHeader(string $type, ?string $comment, array $ignoredTables = []): string
    {
        $lines = [
            '-- ========================================',
            '-- ArtissTools Database Backup',
            '-- ========================================',
            sprintf('-- Type: %s', $type),
            sprintf('-- Created: %s', date('Y-m-d H:i:s')),
        ];

        if ($comment) {
            $lines[] = sprintf('-- Comment: %s', $comment);
        }

        if (!empty($ignoredTables)) {
            $tableNames = array_map(fn($t) => explode('.', $t)[1], $ignoredTables);
            $lines[] = '-- Ignored tables (structure only): ' . implode(', ', $tableNames);
        }

        $lines[] = '-- ========================================';
        $lines[] = '';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function compressFile(string $source, string $destination): void
    {
        $handle = fopen($source, 'rb');
        $gzHandle = gzopen($destination, 'wb9');

        if (!$handle || !$gzHandle) {
            throw new \RuntimeException('Failed to open files for compression');
        }

        while (!feof($handle)) {
            gzwrite($gzHandle, fread($handle, 8192));
        }

        fclose($handle);
        gzclose($gzHandle);
    }

    private function cleanupOldBackups(string $outputPath, int $keep, string $type): int
    {
        $pattern = sprintf('%s/shopware-db-%s-*.sql*', $outputPath, $type);
        $files = glob($pattern);

        if ($files === false) {
            return 0;
        }

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

