<?php declare(strict_types=1);

namespace ArtissTools\Controller\Api;

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

            $config['dbOutputDir'] = $this->backupService->getDbOutputDir();
            $config['mediaOutputDir'] = $this->backupService->getMediaOutputDir();

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
            $config = $this->backupService->getConfig();

            $options = [
                'type' => $data['type'] ?? 'smart',
                'outputDir' => $data['outputDir'] ?? null,
                'keep' => $data['keep'] ?? $config['backupRetention'],
                'gzip' => $data['gzip'] ?? true,
                'comment' => $data['comment'] ?? null,
                'ignoredTables' => $data['ignoredTables'] ?? null,
            ];

            $jobId = 'db_' . uniqid('backup_', true);
            $this->startBackupInBackground($jobId, 'db', $options);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'jobId' => $jobId,
                    'message' => 'Database backup started',
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
            $config = $this->backupService->getConfig();

            $options = [
                'scope' => $data['scope'] ?? 'all',
                'outputDir' => $data['outputDir'] ?? null,
                'keep' => $data['keep'] ?? $config['backupRetention'],
                'compress' => $data['compress'] ?? false,
                'excludeThumbnails' => $data['excludeThumbnails'] ?? true,
                'comment' => $data['comment'] ?? null,
            ];

            $jobId = 'media_' . uniqid('backup_', true);
            $this->startBackupInBackground($jobId, 'media', $options);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'jobId' => $jobId,
                    'message' => 'Media backup started',
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

            $jobId = 'restore_db_' . uniqid('', true);
            $this->startRestoreInBackground($jobId, 'db', $options);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'jobId' => $jobId,
                    'message' => 'Database restore started',
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

            $jobId = 'restore_media_' . uniqid('', true);
            $this->startRestoreInBackground($jobId, 'media', $options);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'jobId' => $jobId,
                    'message' => 'Media restore started',
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
        path: '/api/_action/artiss-tools/backup/status/{jobId}',
        name: 'api.action.artiss_tools.backup.status',
        methods: ['GET']
    )]
    public function getBackupStatus(string $jobId, Context $context): JsonResponse
    {
        try {
            $logFile = $this->projectDir . '/var/log/' . $jobId . '.log';

            if (!file_exists($logFile)) {
                return new JsonResponse([
                    'success' => true,
                    'data' => [
                        'status' => 'pending',
                        'message' => 'Backup is starting...',
                    ],
                ]);
            }

            $log = file_get_contents($logFile);

            if (strpos($log, '[ERROR]') !== false || strpos($log, 'Exception') !== false) {
                return new JsonResponse([
                    'success' => true,
                    'data' => [
                        'status' => 'failed',
                        'error' => 'Backup failed. Check logs for details.',
                    ],
                ]);
            }

            if (strpos($log, 'backup created successfully') !== false ||
                strpos($log, 'Backup created successfully') !== false) {

                if (preg_match('/Output:\s+(.+\.(?:sql|sql\.gz|tar|tar\.gz))$/m', $log, $matches)) {
                    $backupFile = trim($matches[1]);

                    if (file_exists($backupFile)) {
                        $fileSize1 = filesize($backupFile);
                        usleep(500000);
                        $fileSize2 = filesize($backupFile);

                        if ($fileSize1 === $fileSize2 && $fileSize1 > 0) {
                            $type = strpos($jobId, 'db_') === 0 ? 'db' : 'media';
                            $lastBackup = $this->backupService->getLastBackupInfo($type);

                            return new JsonResponse([
                                'success' => true,
                                'data' => [
                                    'status' => 'completed',
                                    'message' => 'Backup created successfully',
                                    'lastBackup' => $lastBackup,
                                ],
                            ]);
                        }
                    }
                }
            }

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'status' => 'running',
                    'message' => 'Backup in progress...',
                ],
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
        $phpBinary = $this->findPhpBinary();
        $consolePath = $this->projectDir . '/bin/console';

        $command = match($type) {
            'db' => $this->buildDbBackupCommand($consolePath, $options),
            'media' => $this->buildMediaBackupCommand($consolePath, $options),
            default => throw new \InvalidArgumentException('Invalid backup type'),
        };

        $logFile = $this->projectDir . '/var/log/' . $jobId . '.log';
        $fullCommand = sprintf(
            '%s %s > %s 2>&1 &',
            $phpBinary,
            $command,
            escapeshellarg($logFile)
        );

        shell_exec($fullCommand);
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

    #[Route(
        path: '/api/_action/artiss-tools/restore/status/{jobId}',
        name: 'api.action.artiss_tools.restore.status',
        methods: ['GET']
    )]
    public function getRestoreStatus(string $jobId, Context $context): JsonResponse
    {
        try {
            $logFile = $this->projectDir . '/var/log/' . $jobId . '.log';

            if (!file_exists($logFile)) {
                return new JsonResponse([
                    'success' => true,
                    'data' => [
                        'status' => 'pending',
                        'message' => 'Restore is starting...',
                    ],
                ]);
            }

            $log = file_get_contents($logFile);

            if (strpos($log, '[ERROR]') !== false || strpos($log, 'Exception') !== false) {
                return new JsonResponse([
                    'success' => true,
                    'data' => [
                        'status' => 'failed',
                        'error' => 'Restore failed. Check logs for details.',
                    ],
                ]);
            }

            if (stripos($log, 'restore completed successfully') !== false ||
                stripos($log, 'restored successfully') !== false) {
                return new JsonResponse([
                    'success' => true,
                    'data' => [
                        'status' => 'completed',
                        'message' => 'Restore completed successfully',
                    ],
                ]);
            }

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'status' => 'running',
                    'message' => 'Restore in progress...',
                ],
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function startRestoreInBackground(string $jobId, string $type, array $options): void
    {
        $phpBinary = $this->findPhpBinary();
        $consolePath = $this->projectDir . '/bin/console';

        $command = match($type) {
            'db' => $this->buildDbRestoreCommand($consolePath, $options),
            'media' => $this->buildMediaRestoreCommand($consolePath, $options),
            default => throw new \InvalidArgumentException('Invalid restore type'),
        };

        $logFile = $this->projectDir . '/var/log/' . $jobId . '.log';
        $fullCommand = sprintf(
            '%s %s > %s 2>&1 &',
            $phpBinary,
            $command,
            escapeshellarg($logFile)
        );

        shell_exec($fullCommand);
    }

    private function buildDbRestoreCommand(string $consolePath, array $options): string
    {
        $args = [
            escapeshellarg($consolePath),
            'artiss:restore:db',
            escapeshellarg($options['backupFile']),
            '--force',
        ];

        if ($options['dropTables']) {
            $args[] = '--drop-tables';
        }

        if ($options['noForeignChecks']) {
            $args[] = '--no-foreign-checks';
        }

        return implode(' ', $args);
    }

    private function buildMediaRestoreCommand(string $consolePath, array $options): string
    {
        $args = [
            escapeshellarg($consolePath),
            'artiss:restore:media',
            escapeshellarg($options['backupFile']),
            '--mode=' . escapeshellarg($options['mode']),
            '--force',
        ];

        if ($options['dryRun']) {
            $args[] = '--dry-run';
        }

        return implode(' ', $args);
    }

    private function findPhpBinary(): string
    {
        $candidates = [
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/homebrew/bin/php',
        ];

        $whichResult = trim(shell_exec('which php 2>/dev/null') ?? '');
        if (!empty($whichResult) && is_executable($whichResult)) {
            return $whichResult;
        }

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }
}
