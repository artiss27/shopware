<?php declare(strict_types=1);

namespace ArtissTools\Controller\Api;

use ArtissTools\Service\PropertyTransferService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class PropertyTransferController extends AbstractController
{
    public function __construct(
        private readonly PropertyTransferService $transferService
    ) {
    }

    /**
     * Preview transfer operation (dry-run)
     */
    #[Route(
        path: '/api/_action/artiss-tools/transfer/preview',
        name: 'api.action.artiss_tools.transfer.preview',
        methods: ['POST']
    )]
    public function preview(Request $request, Context $context): JsonResponse
    {
        try {
            $params = json_decode($request->getContent(), true);

            $result = $this->transferService->transfer($params, true, $context);

            return new JsonResponse([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute transfer operation
     */
    #[Route(
        path: '/api/_action/artiss-tools/transfer/execute',
        name: 'api.action.artiss_tools.transfer.execute',
        methods: ['POST']
    )]
    public function execute(Request $request, Context $context): JsonResponse
    {
        try {
            $params = json_decode($request->getContent(), true);

            $result = $this->transferService->transfer($params, false, $context);

            return new JsonResponse([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Load property group options
     */
    #[Route(
        path: '/api/_action/artiss-tools/transfer/load-group-options',
        name: 'api.action.artiss_tools.transfer.load_group_options',
        methods: ['POST']
    )]
    public function loadGroupOptions(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $groupId = $data['groupId'] ?? null;

            if (!$groupId) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing required parameter: groupId',
                ], 400);
            }

            // Load options for the group
            $options = $this->getGroupOptions($groupId);

            return new JsonResponse([
                'success' => true,
                'data' => $options,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Load custom fields for a set
     */
    #[Route(
        path: '/api/_action/artiss-tools/transfer/load-custom-fields',
        name: 'api.action.artiss_tools.transfer.load_custom_fields',
        methods: ['POST']
    )]
    public function loadCustomFields(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $setId = $data['setId'] ?? null;

            if (!$setId) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing required parameter: setId',
                ], 400);
            }

            $fields = $this->getCustomFieldsForSet($setId);

            return new JsonResponse([
                'success' => true,
                'data' => $fields,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function getGroupOptions(string $groupId): array
    {
        $connection = $this->container->get('Doctrine\DBAL\Connection');
        $qb = $connection->createQueryBuilder();

        $results = $qb
            ->select('pgo.id, pgo_translation.name')
            ->from('property_group_option', 'pgo')
            ->innerJoin('pgo', 'property_group_option_translation', 'pgo_translation', 'pgo.id = pgo_translation.property_group_option_id')
            ->where('pgo.property_group_id = :groupId')
            ->setParameter('groupId', hex2bin(str_replace('-', '', $groupId)))
            ->executeQuery()
            ->fetchAllAssociative();

        $options = [];
        foreach ($results as $result) {
            $options[] = [
                'id' => bin2hex($result['id']),
                'name' => $result['name']
            ];
        }

        return $options;
    }

    private function getCustomFieldsForSet(string $setId): array
    {
        $connection = $this->container->get('Doctrine\DBAL\Connection');
        $qb = $connection->createQueryBuilder();

        $results = $qb
            ->select('cf.id, cf.name, cf_translation.label')
            ->from('custom_field', 'cf')
            ->leftJoin('cf', 'custom_field_translation', 'cf_translation', 'cf.id = cf_translation.custom_field_id')
            ->where('cf.custom_field_set_id = :setId')
            ->setParameter('setId', hex2bin(str_replace('-', '', $setId)))
            ->executeQuery()
            ->fetchAllAssociative();

        $fields = [];
        foreach ($results as $result) {
            $fields[] = [
                'id' => bin2hex($result['id']),
                'name' => $result['name'],
                'label' => $result['label'] ?? $result['name']
            ];
        }

        return $fields;
    }
}
