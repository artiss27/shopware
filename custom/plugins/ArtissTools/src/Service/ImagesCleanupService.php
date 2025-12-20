<?php declare(strict_types=1);

namespace ArtissTools\Service;

use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
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
        $offset = (int)($params['offset'] ?? 0);
        $dryRun = (bool)($params['dryRun'] ?? true);
        $report = (bool)($params['report'] ?? true);

        try {
            $application = new Application($this->kernel);
            $application->setAutoExit(false);

            $commandInput = [
                'command' => 'media:delete-unused',
                '--grace-period-days' => $gracePeriodDays,
                '--limit' => $limit,
                '--offset' => $offset,
                '--no-interaction' => true,
            ];

            // --report and --dry-run cannot be used together, pick one
            // For preview use --report, for deletion use neither (or --dry-run if needed)
            if ($dryRun) {
                // For preview, use --report to get file list
                $commandInput['--report'] = true;
            }
            // For actual deletion, don't use --dry-run or --report

            if ($folderEntity) {
                $commandInput['--folder-entity'] = $folderEntity;
            }

            $input = new ArrayInput($commandInput);
            $output = new BufferedOutput();

            $exitCode = $application->run($input, $output);
            $outputContent = $output->fetch();

            return [
                'success' => $exitCode === 0,
                'output' => $outputContent,
                'exitCode' => $exitCode
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
