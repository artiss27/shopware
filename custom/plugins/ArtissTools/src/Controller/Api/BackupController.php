<?php declare(strict_types=1);

namespace ArtissTools\Controller\Api;

use ArtissTools\Service\BackupService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class BackupController extends AbstractController
{
    public function __construct(
        private readonly BackupService $backupService
    ) {
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

            $result = $this->backupService->createDbBackup($options);

            if ($result['success']) {
                $lastBackup = $this->backupService->getLastBackupInfo('db');
                
                return new JsonResponse([
                    'success' => true,
                    'data' => [
                        'output' => $result['output'],
                        'lastBackup' => $lastBackup,
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
                'excludeThumbnails' => $data['excludeThumbnails'] ?? true,
                'comment' => $data['comment'] ?? null,
            ];

            $result = $this->backupService->createMediaBackup($options);

            if ($result['success']) {
                $lastBackup = $this->backupService->getLastBackupInfo('media');
                
                return new JsonResponse([
                    'success' => true,
                    'data' => [
                        'output' => $result['output'],
                        'lastBackup' => $lastBackup,
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
}

