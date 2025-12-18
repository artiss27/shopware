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
 *   --output-dir=PATH        Directory to save backup (default: var/artiss-backups/db)
 *   --keep=INT               Number of backups to keep (default: 3)
 *   --gzip                   Compress output with gzip
 *   --no-gzip                Disable gzip compression
 *   --comment="TEXT"         Comment to include in backup
 *   --ignored-tables=LIST    Comma-separated list of tables to ignore data (smart mode only)
 *
 * Example:
 *   bin/console artiss:backup:db --type=smart --output-dir=var/artiss-backups/db --keep=5 --gzip --comment="Before update"
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
        'customer_wishlist',
        'customer_wishlist_product',
        'dead_message',
        'elasticsearch_index_task',
        'enqueue',
        'increment',
        'log_entry',
        'message_queue_stats',
        'notification',
        'product_keyword_dictionary',
        'product_search_keyword',
        'refresh_token',
        'version',
        'version_commit',
        'version_commit_data',
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
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory', 'var/artiss-backups/db')
            ->addOption('keep', 'k', InputOption::VALUE_REQUIRED, 'Number of backups to keep', '3')
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

        // Prepare output directory
        $outputPath = $this->prepareOutputDirectory($outputDir);
        if ($outputPath === null) {
            $io->error(sprintf('Failed to create output directory: %s', $outputDir));
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
            $ignoredTables = $this->getIgnoredTables($ignoredTablesOption, $dbParams['database']);
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
        $io->newLine();
        $io->success([
            'Backup created successfully!',
            sprintf('File: %s', $filePath),
            sprintf('Size: %s', $this->formatBytes($fileSize)),
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
            if (!mkdir($outputDir, 0755, true)) {
                return null;
            }
        }

        return realpath($outputDir) ?: $outputDir;
    }

    private function getIgnoredTables(?string $ignoredTablesOption, string $database): array
    {
        if ($ignoredTablesOption !== null) {
            $tables = array_map('trim', explode(',', $ignoredTablesOption));
        } else {
            $tables = self::DEFAULT_IGNORED_TABLES;
        }

        // Prefix with database name for mysqldump
        return array_map(fn($table) => $database . '.' . $table, $tables);
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
        // Try common paths
        $paths = [
            'mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            'mariadb-dump',
            '/usr/bin/mariadb-dump',
            '/usr/local/bin/mariadb-dump',
        ];

        foreach ($paths as $path) {
            $result = shell_exec('which ' . escapeshellarg($path) . ' 2>/dev/null');
            if (!empty(trim($result ?? ''))) {
                return trim($result);
            }
        }

        // Default to mysqldump and let it fail with proper error
        return 'mysqldump';
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
        
        if ($files === false || count($files) <= $keep) {
            return 0;
        }

        // Sort by modification time (newest first)
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        $deleted = 0;
        $toDelete = array_slice($files, $keep);
        
        foreach ($toDelete as $file) {
            if (unlink($file)) {
                $deleted++;
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
}

