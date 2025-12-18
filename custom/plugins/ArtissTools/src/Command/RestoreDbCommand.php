<?php declare(strict_types=1);

/**
 * Description:
 *   Restores a Shopware database from a backup file (.sql or .sql.gz).
 *   Supports both plain SQL and gzip-compressed dumps.
 *
 * Usage:
 *   bin/console artiss:restore:db [options] <backup-file>
 *
 * Options:
 *   --force              Skip confirmation prompt
 *   --drop-tables        Drop all existing tables before restore (default: false)
 *   --no-foreign-checks  Disable foreign key checks during restore (default: enabled)
 *
 * Example:
 *   bin/console artiss:restore:db artiss-backups/db/shopware-db-smart-20251218-120000.sql.gz --force --drop-tables
 */

namespace ArtissTools\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'artiss:restore:db',
    description: 'Restore database from a backup file'
)]
class RestoreDbCommand extends Command
{
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
            ->addArgument('backup-file', InputArgument::REQUIRED, 'Path to backup file (.sql or .sql.gz)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt')
            ->addOption('drop-tables', null, InputOption::VALUE_NONE, 'Drop all existing tables before restore')
            ->addOption('no-foreign-checks', null, InputOption::VALUE_NONE, 'Disable foreign key checks during restore');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $backupFile = $input->getArgument('backup-file');
        $force = $input->getOption('force');
        $dropTables = $input->getOption('drop-tables');
        $noForeignChecks = $input->getOption('no-foreign-checks');

        // Resolve backup file path
        $filePath = $this->resolveFilePath($backupFile);
        
        if (!file_exists($filePath)) {
            $io->error(sprintf('Backup file not found: %s', $filePath));
            return Command::FAILURE;
        }

        // Parse database URL
        $dbParams = $this->parseDatabaseUrl();
        if ($dbParams === null) {
            $io->error('Failed to parse DATABASE_URL.');
            return Command::FAILURE;
        }

        $fileSize = filesize($filePath);
        $isGzipped = str_ends_with($filePath, '.gz');

        // Validate checksum if .sha256 file exists
        $checksumFile = $filePath . '.sha256';
        $checksumValid = null;
        if (file_exists($checksumFile)) {
            $checksumValid = $this->validateChecksum($filePath, $checksumFile);
            if ($checksumValid === false) {
                $io->error([
                    'Checksum validation FAILED!',
                    'The backup file may be corrupted or tampered with.',
                    'Restore aborted for safety.',
                ]);
                return Command::FAILURE;
            }
        }

        // Check available disk space (estimate: need 2-3x file size for uncompressed restore)
        $requiredSpace = $isGzipped ? $fileSize * 10 : $fileSize * 3;
        $availableSpace = disk_free_space(sys_get_temp_dir());
        if ($availableSpace < $requiredSpace) {
            $io->error([
                'Insufficient disk space!',
                sprintf('Required: %s (estimated)', $this->formatBytes($requiredSpace)),
                sprintf('Available: %s', $this->formatBytes($availableSpace)),
            ]);
            return Command::FAILURE;
        }

        $io->title('ArtissTools Database Restore');
        $io->text([
            sprintf('Backup file: <info>%s</info>', $filePath),
            sprintf('File size: <info>%s</info>', $this->formatBytes($fileSize)),
            sprintf('Compressed: <info>%s</info>', $isGzipped ? 'yes (gzip)' : 'no'),
            sprintf('Checksum: <info>%s</info>', $checksumValid === true ? 'valid' : ($checksumValid === false ? 'INVALID' : 'not checked')),
            sprintf('Target database: <info>%s</info>', $dbParams['database']),
        ]);

        $io->newLine();
        $io->warning([
            'WARNING: This operation will modify your database!',
            $dropTables ? 'All existing tables will be DROPPED before restore!' : 'Existing data may be overwritten.',
        ]);

        // Confirm if not forced
        if (!$force) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                'Are you sure you want to restore this backup? (yes/no) [no]: ',
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                $io->text('Restore cancelled.');
                return Command::SUCCESS;
            }
        }

        $io->newLine();
        $io->text('Starting database restore...');

        try {
            // Drop tables if requested
            if ($dropTables) {
                $io->text('  Dropping existing tables...');
                $this->dropAllTables($dbParams, $io);
            }

            // Restore database
            $io->text('  Importing SQL dump...');
            $this->restoreDatabase($dbParams, $filePath, $isGzipped, $noForeignChecks, $io);

        } catch (\Exception $e) {
            $io->error(sprintf('Restore failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        $io->newLine();
        $io->success([
            'Database restored successfully!',
            sprintf('Source: %s', basename($filePath)),
        ]);

        return Command::SUCCESS;
    }

    private function resolveFilePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return $this->projectDir . '/' . $path;
    }

    private function parseDatabaseUrl(): ?array
    {
        $url = $this->databaseUrl;
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

    private function dropAllTables(array $dbParams, SymfonyStyle $io): void
    {
        $mysqlPath = $this->findMysqlClient();
        
        // Get list of all tables
        $cmd = sprintf(
            '%s -h %s -P %d -u %s %s -N -e "SHOW TABLES" %s',
            escapeshellarg($mysqlPath),
            escapeshellarg($dbParams['host']),
            $dbParams['port'],
            escapeshellarg($dbParams['user']),
            !empty($dbParams['password']) ? '-p' . escapeshellarg($dbParams['password']) : '',
            escapeshellarg($dbParams['database'])
        );

        $tables = [];
        exec($cmd, $tables, $returnCode);

        if ($returnCode !== 0 || empty($tables)) {
            $io->text('    No tables to drop or failed to get table list.');
            return;
        }

        // Drop all tables
        $dropStatements = [
            'SET FOREIGN_KEY_CHECKS = 0;',
        ];
        
        foreach ($tables as $table) {
            $dropStatements[] = sprintf('DROP TABLE IF EXISTS `%s`;', $table);
        }
        
        $dropStatements[] = 'SET FOREIGN_KEY_CHECKS = 1;';

        $sql = implode("\n", $dropStatements);
        
        $cmd = sprintf(
            'echo %s | %s -h %s -P %d -u %s %s %s',
            escapeshellarg($sql),
            escapeshellarg($mysqlPath),
            escapeshellarg($dbParams['host']),
            $dbParams['port'],
            escapeshellarg($dbParams['user']),
            !empty($dbParams['password']) ? '-p' . escapeshellarg($dbParams['password']) : '',
            escapeshellarg($dbParams['database'])
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('Failed to drop tables');
        }

        $io->text(sprintf('    Dropped %d tables.', count($tables)));
    }

    private function restoreDatabase(
        array $dbParams,
        string $filePath,
        bool $isGzipped,
        bool $noForeignChecks,
        SymfonyStyle $io
    ): void {
        $mysqlPath = $this->findMysqlClient();

        // Build mysql command
        $mysqlCmd = sprintf(
            '%s -h %s -P %d -u %s %s %s',
            escapeshellarg($mysqlPath),
            escapeshellarg($dbParams['host']),
            $dbParams['port'],
            escapeshellarg($dbParams['user']),
            !empty($dbParams['password']) ? '-p' . escapeshellarg($dbParams['password']) : '',
            escapeshellarg($dbParams['database'])
        );

        // Prepare import command
        if ($isGzipped) {
            $cmd = sprintf('gunzip -c %s | %s', escapeshellarg($filePath), $mysqlCmd);
        } else {
            $cmd = sprintf('%s < %s', $mysqlCmd, escapeshellarg($filePath));
        }

        // Add foreign key disable if requested
        if ($noForeignChecks) {
            $prefix = 'echo "SET FOREIGN_KEY_CHECKS=0;" | ' . $mysqlCmd . ' && ';
            $suffix = ' && echo "SET FOREIGN_KEY_CHECKS=1;" | ' . $mysqlCmd;
            $cmd = $prefix . $cmd . $suffix;
        }

        // Execute
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes, null, null, ['bypass_shell' => false]);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start mysql process');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        if ($returnCode !== 0) {
            throw new \RuntimeException('MySQL restore failed: ' . $stderr);
        }
    }

    private function findMysqlClient(): string
    {
        $paths = [
            'mysql',
            '/usr/bin/mysql',
            '/usr/local/bin/mysql',
            'mariadb',
            '/usr/bin/mariadb',
            '/usr/local/bin/mariadb',
        ];

        foreach ($paths as $path) {
            $result = shell_exec('which ' . escapeshellarg($path) . ' 2>/dev/null');
            if (!empty(trim($result ?? ''))) {
                return trim($result);
            }
        }

        return 'mysql';
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);

        return sprintf('%.2f %s', $bytes / pow(1024, $factor), $units[$factor]);
    }

    private function validateChecksum(string $filePath, string $checksumFile): bool
    {
        try {
            $actualHash = hash_file('sha256', $filePath);
            if ($actualHash === false) {
                return false;
            }

            $checksumContent = file_get_contents($checksumFile);
            if ($checksumContent === false) {
                return false;
            }

            // Parse checksum file (format: "hash  filename")
            $parts = preg_split('/\s+/', trim($checksumContent), 2);
            if (count($parts) < 1) {
                return false;
            }

            $expectedHash = $parts[0];

            return hash_equals($expectedHash, $actualHash);
        } catch (\Exception $e) {
            return false;
        }
    }
}

