<?php declare(strict_types=1);

/**
 * Description:
 *   Restores Shopware media files from a backup archive (.tar.gz).
 *   Supports different restore modes: add missing files, overwrite, or clean and restore.
 *
 * Usage:
 *   bin/console artiss:restore:media [options] <backup-file>
 *
 * Options:
 *   --mode=add|overwrite|clean   Restore mode (default: add)
 *                                - add: only add missing files
 *                                - overwrite: overwrite existing files
 *                                - clean: remove all files and restore from backup
 *   --force                      Skip confirmation prompt
 *   --dry-run                    Show what would be done without making changes
 *
 * Example:
 *   bin/console artiss:restore:media artiss-backups/media/media-backup-all-20251218-120000.tar.gz --mode=overwrite --force
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
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'artiss:restore:media',
    description: 'Restore media files from a backup archive'
)]
class RestoreMediaCommand extends Command
{
    private string $projectDir;

    public function __construct(string $projectDir)
    {
        parent::__construct();
        $this->projectDir = $projectDir;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('backup-file', InputArgument::REQUIRED, 'Path to backup archive (.tar.gz)')
            ->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'Restore mode: add, overwrite, or clean', 'add')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $backupFile = $input->getArgument('backup-file');
        $mode = $input->getOption('mode');
        $force = $input->getOption('force');
        $dryRun = $input->getOption('dry-run');

        // Validate mode
        if (!in_array($mode, ['add', 'overwrite', 'clean'], true)) {
            $io->error('Invalid mode. Use "add", "overwrite", or "clean".');
            return Command::FAILURE;
        }

        // Resolve backup file path
        $filePath = $this->resolveFilePath($backupFile);

        if (!file_exists($filePath)) {
            $io->error(sprintf('Backup file not found: %s', $filePath));
            return Command::FAILURE;
        }

        if (!str_ends_with($filePath, '.tar.gz') && !str_ends_with($filePath, '.tgz')) {
            $io->error('Backup file must be a .tar.gz archive.');
            return Command::FAILURE;
        }

        $mediaPath = $this->projectDir . '/public/media';
        if (!is_dir($mediaPath)) {
            mkdir($mediaPath, 0755, true);
        }

        $fileSize = filesize($filePath);

        $io->title('ArtissTools Media Restore');
        $io->text([
            sprintf('Backup file: <info>%s</info>', $filePath),
            sprintf('File size: <info>%s</info>', $this->formatBytes($fileSize)),
            sprintf('Mode: <info>%s</info>', $this->getModeDescription($mode)),
            sprintf('Target: <info>%s</info>', $mediaPath),
        ]);

        if ($dryRun) {
            $io->note('DRY RUN MODE - no changes will be made');
        }

        $io->newLine();

        // Show warning based on mode
        $warnings = ['This operation will modify your media files!'];
        if ($mode === 'clean') {
            $warnings[] = 'ALL existing media files will be DELETED before restore!';
        } elseif ($mode === 'overwrite') {
            $warnings[] = 'Existing files with the same name will be overwritten!';
        }
        $io->warning($warnings);

        // Confirm if not forced and not dry run
        if (!$force && !$dryRun) {
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
        $io->text('Starting media restore...');

        $stats = [
            'added' => 0,
            'overwritten' => 0,
            'skipped' => 0,
            'deleted' => 0,
        ];

        try {
            // Clean mode: remove all existing files first
            if ($mode === 'clean' && !$dryRun) {
                $io->text('  Cleaning existing media files...');
                $stats['deleted'] = $this->cleanMediaDirectory($mediaPath, $io);
            }

            // Extract and restore files
            $io->text('  Extracting archive...');
            $stats = array_merge($stats, $this->restoreFromArchive($filePath, $mediaPath, $mode, $dryRun, $io));

        } catch (\Exception $e) {
            $io->error(sprintf('Restore failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        $io->newLine();
        
        if ($dryRun) {
            $io->success('Dry run completed. No changes were made.');
        } else {
            $io->success('Media restore completed successfully!');
        }

        $io->text('Statistics:');
        $io->listing([
            sprintf('Files added: %d', $stats['added']),
            sprintf('Files overwritten: %d', $stats['overwritten']),
            sprintf('Files skipped: %d', $stats['skipped']),
            sprintf('Files deleted: %d', $stats['deleted']),
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

    private function getModeDescription(string $mode): string
    {
        return match ($mode) {
            'add' => 'Add missing files only',
            'overwrite' => 'Overwrite existing files',
            'clean' => 'Clean and restore (delete all, then restore)',
            default => $mode,
        };
    }

    private function cleanMediaDirectory(string $mediaPath, SymfonyStyle $io): int
    {
        $deleted = 0;
        
        $finder = new Finder();
        $finder->files()->in($mediaPath);

        foreach ($finder as $file) {
            if (unlink($file->getRealPath())) {
                $deleted++;
            }
        }

        // Remove empty directories
        $this->removeEmptyDirectories($mediaPath);

        $io->text(sprintf('    Deleted %d files.', $deleted));
        
        return $deleted;
    }

    private function removeEmptyDirectories(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $dirPath = $item->getRealPath();
                if (count(scandir($dirPath)) === 2) { // Only . and ..
                    rmdir($dirPath);
                }
            }
        }
    }

    private function restoreFromArchive(
        string $archivePath,
        string $mediaPath,
        string $mode,
        bool $dryRun,
        SymfonyStyle $io
    ): array {
        $stats = [
            'added' => 0,
            'overwritten' => 0,
            'skipped' => 0,
        ];

        // Create temp directory for extraction
        $tempDir = sys_get_temp_dir() . '/artiss-media-restore-' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            // Extract archive
            $phar = new \PharData($archivePath);
            $phar->extractTo($tempDir);

            // Process extracted files
            $finder = new Finder();
            $finder->files()->in($tempDir)->ignoreDotFiles(false);

            $progressBar = $io->createProgressBar(iterator_count($finder));
            $progressBar->start();

            // Re-create finder after counting
            $finder = new Finder();
            $finder->files()->in($tempDir)->ignoreDotFiles(false);

            foreach ($finder as $file) {
                $relativePath = $file->getRelativePathname();
                
                // Skip metadata files
                if ($relativePath === '_backup_info.txt') {
                    $progressBar->advance();
                    continue;
                }

                $targetPath = $mediaPath . '/' . $relativePath;
                $targetDir = dirname($targetPath);

                $fileExists = file_exists($targetPath);

                if ($fileExists) {
                    if ($mode === 'add') {
                        $stats['skipped']++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    // Mode is overwrite or clean
                    if (!$dryRun) {
                        if (!is_dir($targetDir)) {
                            mkdir($targetDir, 0755, true);
                        }
                        copy($file->getRealPath(), $targetPath);
                    }
                    $stats['overwritten']++;
                } else {
                    if (!$dryRun) {
                        if (!is_dir($targetDir)) {
                            mkdir($targetDir, 0755, true);
                        }
                        copy($file->getRealPath(), $targetPath);
                    }
                    $stats['added']++;
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $io->newLine();

        } finally {
            // Cleanup temp directory
            $this->removeDirectory($tempDir);
        }

        return $stats;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($path);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);

        return sprintf('%.2f %s', $bytes / pow(1024, $factor), $units[$factor]);
    }
}

