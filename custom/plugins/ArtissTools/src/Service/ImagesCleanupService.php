<?php declare(strict_types=1);

namespace ArtissTools\Service;

use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;

class ImagesCleanupService
{
    public function __construct(
        private readonly KernelInterface $kernel
    ) {
    }

    /**
     * Calculate total size of media folder
     */
    public function calculateMediaSize(Context $context): array
    {
        $projectDir = $this->kernel->getProjectDir();
        $mediaPath = $projectDir . '/public/media';
        
        if (!is_dir($mediaPath)) {
            return [
                'size' => 0,
                'sizeFormatted' => '0 B'
            ];
        }

        $totalSize = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($mediaPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $totalSize += $file->getSize();
            }
        }

        return [
            'size' => $totalSize,
            'sizeFormatted' => $this->formatBytes($totalSize)
        ];
    }

    /**
     * Run media:delete-unused command (dry-run or real deletion)
     */
    public function runCleanupCommand(array $params, Context $context): array
    {
        $folderEntity = $params['folderEntity'] ?? null;
        $gracePeriodDays = (int)($params['gracePeriodDays'] ?? 20);
        $limit = (int)($params['limit'] ?? 100);
        $dryRun = (bool)($params['dryRun'] ?? true);

        try {
            $application = new Application($this->kernel);
            $application->setAutoExit(false);

            $commandInput = [
                'command' => 'media:delete-unused',
                '--grace-period-days' => (string)$gracePeriodDays,
                '--limit' => (string)$limit,
                '--no-interaction' => true,
            ];

            // --report and --dry-run cannot be used together, pick one
            // For preview use --dry-run to get file list (shows what will be deleted)
            // For deletion don't use any flag (actually deletes files)
            if ($dryRun) {
                // For preview, use --dry-run to show list of files to be deleted
                $commandInput['--dry-run'] = true;
            }
            // For actual deletion, don't add --dry-run or --report, just use --no-interaction

            if ($folderEntity) {
                $commandInput['--folder-entity'] = $folderEntity;
            }

            // Build command string for debugging
            $commandParts = ['bin/console media:delete-unused'];
            foreach ($commandInput as $key => $value) {
                if ($key === 'command') {
                    continue;
                }
                if ($value === true) {
                    $commandParts[] = $key;
                } elseif ($value !== false && $value !== null) {
                    $commandParts[] = $key . '=' . $value;
                }
            }
            $commandString = implode(' ', $commandParts);

            $input = new ArrayInput($commandInput);
            
            // Set stream for automatic "yes" answers to confirmation questions
            $stream = null;
            if ($input instanceof StreamableInputInterface) {
                $stream = fopen('php://memory', 'r+', false);
                fwrite($stream, "yes\n"); // Auto-confirm deletion
                rewind($stream);
                $input->setStream($stream);
            }
            
            $output = new BufferedOutput();

            $exitCode = $application->run($input, $output);
            $outputContent = $output->fetch();

            // Check if command was aborted due to user input (confirmation required)
            $wasAborted = stripos($outputContent, 'CAUTION') !== false 
                || stripos($outputContent, 'Aborting due to user input') !== false
                || stripos($outputContent, 'aborted') !== false;

            // If command was aborted, it's a failure even if exit code is 0
            $success = $exitCode === 0 && !$wasAborted;
            
            // Close stream if opened
            if ($stream !== null) {
                fclose($stream);
            }

            return [
                'success' => $success,
                'output' => $outputContent,
                'exitCode' => $exitCode,
                'command' => $commandString
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'output' => 'Error: ' . $e->getMessage(),
                'exitCode' => 1
            ];
        }
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
