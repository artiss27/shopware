<?php
declare(strict_types=1);

namespace ArtissTools\Controller\Api;

use ArtissTools\Message\UpdateMediaHashesMessage;
use ArtissTools\Service\MediaDuplicatesService;
use ArtissTools\Service\MediaHashService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class MediaDuplicatesController extends AbstractController
{
    public function __construct(
        private readonly MediaHashService $mediaHashService,
        private readonly MediaDuplicatesService $mediaDuplicatesService,
        private readonly MessageBusInterface $messageBus
    ) {
    }

    /**
     * Get hash update status
     */
    #[Route(
        path: '/api/_action/artiss-tools/images/hash-status',
        name: 'api.action.artiss_tools.images.hash_status',
        methods: ['GET']
    )]
    public function getHashStatus(Request $request, Context $context): JsonResponse
    {
        try {
            $lastUpdate = $this->mediaHashService->getLastHashUpdate($context);

            return new JsonResponse([
                'success' => true,
                'data' => $lastUpdate
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start updating media hashes (synchronous processing in batches)
     */
    #[Route(
        path: '/api/_action/artiss-tools/images/update-hashes',
        name: 'api.action.artiss_tools.images.update_hashes',
        methods: ['POST']
    )]
    public function updateHashes(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!is_array($data)) {
                throw new \RuntimeException('Invalid JSON payload');
            }

            $batchSize = (int)($data['batchSize'] ?? 1000);
            $folderEntity = $data['folderEntity'] ?? null;
            $offset = (int)($data['offset'] ?? 0);
            $recalculateAll = (bool)($data['recalculateAll'] ?? false);

            if ($recalculateAll && $offset === 0) {
                $this->mediaHashService->clearAllHashes();
            }

            $forceRecalculate = $recalculateAll === true;
            $onlyMissing = $recalculateAll === false;

            $result = $this->mediaHashService->updateMediaHashesBatch(
                $batchSize,
                $offset,
                $folderEntity,
                $context,
                $forceRecalculate,
                $onlyMissing
            );

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'processed'   => $result['processed'],
                    'hasMore'     => $result['hasMore'],
                    'totalHashed' => $result['totalHashed'] ?? 0,
                    'nextOffset'  => $offset + $result['processed'],
                ],
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Find next duplicate set
     */
    #[Route(
        path: '/api/_action/artiss-tools/images/find-next-duplicate',
        name: 'api.action.artiss_tools.images.find_next_duplicate',
        methods: ['POST']
    )]
    public function findNextDuplicate(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true) ?? [];

            $folderEntity = $data['folderEntity'] ?? null;

            $duplicateSet = $this->mediaDuplicatesService->findNextDuplicateSet($folderEntity, $context);

            if ($duplicateSet === null) {
                return new JsonResponse([
                    'success' => true,
                    'data' => null,
                    'message' => 'No duplicates found'
                ]);
            }

            return new JsonResponse([
                'success' => true,
                'data' => $duplicateSet
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Merge duplicate set
     */
    #[Route(
        path: '/api/_action/artiss-tools/images/merge-duplicates',
        name: 'api.action.artiss_tools.images.merge_duplicates',
        methods: ['POST']
    )]
    public function mergeDuplicates(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $keeperMediaId = $data['keeperMediaId'] ?? null;
            $duplicateMediaIds = $data['duplicateMediaIds'] ?? [];

            if (!$keeperMediaId || empty($duplicateMediaIds)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'keeperMediaId and duplicateMediaIds are required'
                ], 400);
            }

            $result = $this->mediaDuplicatesService->mergeDuplicateSet($keeperMediaId, $duplicateMediaIds, $context);

            return new JsonResponse([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
