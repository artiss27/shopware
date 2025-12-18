<?php declare(strict_types=1);

namespace ArtissTools\Controller\Api;

use ArtissTools\Service\BackupJobService;
use ArtissTools\Service\BackupService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class BackupController extends AbstractController
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly BackupJobService $backupJobService,
        private readonly string $projectDir
    ) {
    }

    #[Route(
        path: '/api/_action/artiss-tools/backup/config',
        name: 'api.action.artiss_tools.backup.config',
        methods: ['GET']
    )]
    public function getConfig(Context $context): JsonResponse
    {
        try {
            $config = $this->backupService->getConfig();
            
            // Add computed paths (relative)
            $config['dbOutputDir'] = $this->backupService->getDbOutputDir();
            $config['mediaOutputDir'] = $this->backupService->getMediaOutputDir();
            
            // Add full paths for display
            $config['projectDir'] = $this->backupService->getProjectDir();
            $config['dbOutputDirFull'] = $this->backupService->getProjectDir() . '/' . $config['dbOutputDir'];
            $config['mediaOutputDirFull'] = $this->backupService->getProjectDir() . '/' . $config['mediaOutputDir'];

            return new JsonResponse([
                'success' => true,
                'data' => $config,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route(
        path: '/api/_action/artiss-tools/backup/db',
        name: 'api.action.artiss_tools.backup.db',
        methods: ['POST']
    )]
    public function createDbBackup(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true) ?? [];

            $options = [
                'type' => $data['type'] ?? 'smart',
                'outputDir' => $data['outputDir'] ?? null,
                'keep' => $data['keep'] ?? 3,
                'gzip' => $data['gzip'] ?? true,
                'comment' => $data['comment'] ?? null,
                'ignoredTables' => $data['ignoredTables'] ?? null,
            ];

            // Create background job
            $jobId = $this->backupJobService->createJob('db', $options);

            // Start backup in background
            $this->startBackupInBackground($jobId, 'db', $options);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'jobId' => $jobId,
                    'message' => 'Backup started in background',
                ],
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route(
        path: '/api/_action/artiss-tools/backup/media',
        name: 'api.action.artiss_tools.backup.media',
        methods: ['POST']
    )]
    public function createMediaBackup(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true) ?? [];

            $options = [
                'scope' => $data['scope'] ?? 'all',
                'outputDir' => $data['outputDir'] ?? null,
                'keep' => $data['keep'] ?? 3,
                'compress' => $data['compress'] ?? false,
                'excludeThumbnails' => $data['excludeThumbnails'] ?? true,
                'comment' => $data['comment'] ?? null,
            ];

            // Create background job
            $jobId = $this->backupJobService->createJob('media', $options);

            // Start backup in background
            $this->startBackupInBackground($jobId, 'media', $options);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'jobId' => $jobId,
                    'message' => 'Backup started in background',
                ],
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route(
        path: '/api/_action/artiss-tools/backup/last/{type}',
        name: 'api.action.artiss_tools.backup.last',
        methods: ['GET']
    )]
    public function getLastBackup(string $type, Context $context): JsonResponse
    {
        try {
            if (!in_array($type, ['db', 'media'], true)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid backup type. Use "db" or "media".',
                ], 400);
            }

            $lastBackup = $this->backupService->getLastBackupInfo($type);

            return new JsonResponse([
                'success' => true,
                'data' => $lastBackup,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route(
        path: '/api/_action/artiss-tools/backup/list/{type}',
        name: 'api.action.artiss_tools.backup.list',
        methods: ['GET']
    )]
    public function getBackupsList(string $type, Context $context): JsonResponse
    {
        try {
            if (!in_array($type, ['db', 'media'], true)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid backup type. Use "db" or "media".',
                ], 400);
            }

            $backups = $this->backupService->getBackupsList($type);

            return new JsonResponse([
                'success' => true,
                'data' => $backups,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route(
        path: '/api/_action/artiss-tools/restore/db',
        name: 'api.action.artiss_tools.restore.db',
        methods: ['POST']
    )]
    public function restoreDb(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true) ?? [];

            if (empty($data['backupFile'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Backup file path is required.',
                ], 400);
            }

            $options = [
                'backupFile' => $data['backupFile'],
                'dropTables' => $data['dropTables'] ?? false,
                'noForeignChecks' => $data['noForeignChecks'] ?? true,
            ];

            $result = $this->backupService->restoreDb($options);

            if ($result['success']) {
                return new JsonResponse([
                    'success' => true,
                    'data' => [
                        'output' => $result['output'],
                        'message' => 'Database restored successfully',
                    ],
                ]);
            }

            return new JsonResponse([
                'success' => false,
                'error' => $result['error'] ?? $result['output'],
            ], 500);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route(
        path: '/api/_action/artiss-tools/restore/media',
        name: 'api.action.artiss_tools.restore.media',
        methods: ['POST']
    )]
    public function restoreMedia(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true) ?? [];

            if (empty($data['backupFile'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Backup file path is required.',
                ], 400);
            }

            $options = [
                'backupFile' => $data['backupFile'],
                'mode' => $data['mode'] ?? 'add',
                'dryRun' => $data['dryRun'] ?? false,
            ];

            $result = $this->backupService->restoreMedia($options);

            if ($result['success']) {
                return new JsonResponse([
                    'success' => true,
                    'data' => [
                        'output' => $result['output'],
                        'message' => 'Media restored successfully',
                    ],
                ]);
            }

            return new JsonResponse([
                'success' => false,
                'error' => $result['error'] ?? $result['output'],
            ], 500);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route(
        path: '/api/_action/artiss-tools/backup/delete',
        name: 'api.action.artiss_tools.backup.delete',
        methods: ['POST']
    )]
    public function deleteBackup(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true) ?? [];

            if (empty($data['filePath'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'File path is required.',
                ], 400);
            }

            $result = $this->backupService->deleteBackup($data['filePath']);

            if ($result['success']) {
                return new JsonResponse([
                    'success' => true,
                    'data' => [
                        'message' => 'Backup deleted successfully',
                    ],
                ]);
            }

            return new JsonResponse([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to delete backup',
            ], 500);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route(
        path: '/api/_action/artiss-tools/backup/download',
        name: 'api.action.artiss_tools.backup.download',
        methods: ['POST']
    )]
    public function downloadBackup(Request $request, Context $context): Response
    {
        try {
            $data = json_decode($request->getContent(), true) ?? [];

            if (empty($data['filePath'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'File path is required.',
                ], 400);
            }

            $result = $this->backupService->getBackupForDownload($data['filePath']);

            if (!$result['success']) {
                return new JsonResponse([
                    'success' => false,
                    'error' => $result['error'] ?? 'File not found',
                ], 404);
            }

            $response = new BinaryFileResponse($result['fullPath']);
            $response->headers->set('Content-Type', 'application/octet-stream');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $result['filename'] . '"');
            $response->headers->set('Content-Length', (string) $result['filesize']);

            return $response;

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route(
        path: '/api/_action/artiss-tools/backup/job/{jobId}',
        name: 'api.action.artiss_tools.backup.job_status',
        methods: ['GET']
    )]
    public function getJobStatus(string $jobId, Context $context): JsonResponse
    {
        try {
            $job = $this->backupJobService->getJob($jobId);

            if (!$job) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Job not found',
                ], 404);
            }

            // If completed, get last backup info
            if ($job['status'] === 'completed' && isset($job['result']['type'])) {
                $job['lastBackup'] = $this->backupService->getLastBackupInfo($job['result']['type']);
            }

            return new JsonResponse([
                'success' => true,
                'data' => $job,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function startBackupInBackground(string $jobId, string $type, array $options): void
    {
        // Find correct PHP CLI binary (not php-fpm)
        $phpBinary = $this->findPhpBinary();
        $consolePath = $this->projectDir . '/bin/console';

        $command = match($type) {
            'db' => $this->buildDbBackupCommand($consolePath, $options),
            'media' => $this->buildMediaBackupCommand($consolePath, $options),
            default => throw new \InvalidArgumentException('Invalid backup type'),
        };

        // Update job with command info
        $this->backupJobService->updateJob($jobId, [
            'status' => 'running',
            'progress' => 10,
            'message' => 'Backup in progress...',
        ]);

        // Start process in background and redirect output
        $logFile = $this->projectDir . '/var/log/backup-' . $jobId . '.log';
        $fullCommand = sprintf(
            '%s %s > %s 2>&1 & echo $!',
            $phpBinary,
            $command,
            escapeshellarg($logFile)
        );

        $pid = shell_exec($fullCommand);

        if ($pid) {
            $this->backupJobService->updateJob($jobId, ['pid' => (int) trim($pid)]);
        }

        // Start monitoring process
        $this->startJobMonitoring($jobId, $type, $logFile);
    }

    private function buildDbBackupCommand(string $consolePath, array $options): string
    {
        $args = [
            escapeshellarg($consolePath),
            'artiss:backup:db',
            '--type=' . escapeshellarg($options['type']),
            '--keep=' . escapeshellarg((string) $options['keep']),
        ];

        if (!empty($options['outputDir'])) {
            $args[] = '--output-dir=' . escapeshellarg($options['outputDir']);
        }

        if ($options['gzip']) {
            $args[] = '--gzip';
        } else {
            $args[] = '--no-gzip';
        }

        if (!empty($options['comment'])) {
            $args[] = '--comment=' . escapeshellarg($options['comment']);
        }

        if (!empty($options['ignoredTables'])) {
            $args[] = '--ignored-tables=' . escapeshellarg($options['ignoredTables']);
        }

        return implode(' ', $args);
    }

    private function buildMediaBackupCommand(string $consolePath, array $options): string
    {
        $args = [
            escapeshellarg($consolePath),
            'artiss:backup:media',
            '--scope=' . escapeshellarg($options['scope']),
            '--keep=' . escapeshellarg((string) $options['keep']),
        ];

        if (!empty($options['outputDir'])) {
            $args[] = '--output-dir=' . escapeshellarg($options['outputDir']);
        }

        if ($options['compress']) {
            $args[] = '--compress';
        }

        if ($options['excludeThumbnails']) {
            $args[] = '--exclude-thumbnails';
        } else {
            $args[] = '--no-exclude-thumbnails';
        }

        if (!empty($options['comment'])) {
            $args[] = '--comment=' . escapeshellarg($options['comment']);
        }

        return implode(' ', $args);
    }

    private function startJobMonitoring(string $jobId, string $type, string $logFile): void
    {
        // Start a separate monitoring process
        $phpBinary = $this->findPhpBinary();
        $jobFile = $this->projectDir . '/var/artiss-backup-jobs/' . $jobId . '.json';

        $monitorScript = <<<PHP
<?php
\$jobId = '{$jobId}';
\$type = '{$type}';
\$logFile = '{$logFile}';
\$jobFile = '{$jobFile}';

// Wait for job to complete (max 30 minutes)
\$maxTime = time() + (30 * 60);
\$startTime = time();

while (time() < \$maxTime) {
    sleep(3);

    if (!file_exists(\$jobFile)) {
        break;
    }

    \$job = json_decode(file_get_contents(\$jobFile), true);
    if (!\$job) {
        continue;
    }

    // Update progress based on time elapsed
    \$elapsed = time() - \$startTime;
    \$progress = min(90, 10 + (\$elapsed * 3));

    if (\$progress != \$job['progress']) {
        \$job['progress'] = \$progress;
        \$job['updated_at'] = time();
        file_put_contents(\$jobFile, json_encode(\$job, JSON_PRETTY_PRINT));
    }

    // Check if log file indicates completion
    if (file_exists(\$logFile)) {
        \$log = file_get_contents(\$logFile);

        // Check for success markers
        if (strpos(\$log, 'Backup created successfully') !== false ||
            strpos(\$log, '[OK] Backup created successfully') !== false) {
            // Success - found success message
            \$job['status'] = 'completed';
            \$job['progress'] = 100;
            \$job['message'] = 'Backup completed successfully';
            \$job['result'] = ['type' => \$type];
            \$job['updated_at'] = time();
            file_put_contents(\$jobFile, json_encode(\$job, JSON_PRETTY_PRINT));
            break;
        }
    }

    // Check if process is still running by PID
    if (isset(\$job['pid']) && \$job['pid'] > 0) {
        \$result = shell_exec('ps -p ' . \$job['pid'] . ' 2>&1');
        if (empty(\$result) || strpos(\$result, (string)\$job['pid']) === false) {
            // Process finished - check log for result
            if (file_exists(\$logFile)) {
                \$log = file_get_contents(\$logFile);
                if (strpos(\$log, 'Backup created successfully') !== false ||
                    strpos(\$log, '[OK] Backup created successfully') !== false) {
                    // Success
                    \$job['status'] = 'completed';
                    \$job['progress'] = 100;
                    \$job['message'] = 'Backup completed successfully';
                    \$job['result'] = ['type' => \$type];
                } else if (strpos(\$log, '[ERROR]') !== false ||
                           strpos(\$log, 'Exception') !== false ||
                           strpos(\$log, 'Fatal error') !== false) {
                    // Failed with error
                    \$job['status'] = 'failed';
                    \$job['message'] = 'Backup failed';
                    \$job['error'] = substr(\$log, -1000);
                } else {
                    // Process ended but unclear status - check if backup file was created
                    \$job['status'] = 'completed';
                    \$job['progress'] = 100;
                    \$job['message'] = 'Backup process finished';
                    \$job['result'] = ['type' => \$type];
                }
                \$job['updated_at'] = time();
                file_put_contents(\$jobFile, json_encode(\$job, JSON_PRETTY_PRINT));
            } else {
                // No log file - assume failed
                \$job['status'] = 'failed';
                \$job['message'] = 'Backup failed - no log file';
                \$job['updated_at'] = time();
                file_put_contents(\$jobFile, json_encode(\$job, JSON_PRETTY_PRINT));
            }
            break;
        }
    }
}
PHP;

        $monitorFile = $this->projectDir . '/var/log/monitor-' . $jobId . '.php';
        file_put_contents($monitorFile, $monitorScript);

        $command = sprintf(
            '%s %s > /dev/null 2>&1 &',
            $phpBinary,
            escapeshellarg($monitorFile)
        );

        shell_exec($command);
    }

    private function findPhpBinary(): string
    {
        // Try to find PHP CLI binary (not php-fpm)
        $candidates = [
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/homebrew/bin/php',
        ];

        // Try which command first
        $whichResult = trim(shell_exec('which php 2>/dev/null') ?? '');
        if (!empty($whichResult) && is_executable($whichResult)) {
            return $whichResult;
        }

        // Try candidates
        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        // Fallback to just 'php' and hope it's in PATH
        return 'php';
    }
}

