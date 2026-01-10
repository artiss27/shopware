<?php declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Health check controller for production monitoring
 */
#[Route(defaults: ['_routeScope' => ['api'], 'auth_required' => false])]
class HealthCheckController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    #[Route(path: '/api/_info/health-check', name: 'api.info.health-check', methods: ['GET'])]
    public function basicHealthCheck(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route(path: '/api/_info/health-check/detailed', name: 'api.info.health-check.detailed', methods: ['GET'])]
    public function detailedHealthCheck(): JsonResponse
    {
        $checks = [
            'status' => 'ok',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'checks' => [],
        ];

        // Database check
        try {
            $this->connection->executeQuery('SELECT 1');
            $dbConnections = $this->connection->fetchOne('SHOW STATUS LIKE "Threads_connected"');
            $checks['checks']['database'] = [
                'status' => 'ok',
                'connections' => (int) ($dbConnections ?? 0),
            ];
        } catch (\Exception $e) {
            $checks['checks']['database'] = [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
            $checks['status'] = 'degraded';
        }

        // OpenSearch check (via HTTP using curl or file_get_contents)
        $opensearchUrl = $_ENV['OPENSEARCH_URL'] ?? $_SERVER['OPENSEARCH_URL'] ?? 'http://opensearch:9200';
        $opensearchPassword = $_ENV['OPENSEARCH_PASSWORD'] ?? $_SERVER['OPENSEARCH_PASSWORD'] ?? '';
        
        try {
            $healthUrl = rtrim($opensearchUrl, '/') . '/_cluster/health';
            
            // Use curl if available (more reliable)
            if (function_exists('curl_init')) {
                $ch = curl_init($healthUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_FOLLOWLOCATION => true,
                ]);
                
                if ($opensearchPassword) {
                    curl_setopt($ch, CURLOPT_USERPWD, 'admin:' . $opensearchPassword);
                }
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                if ($httpCode === 200 && $response) {
                    $health = json_decode($response, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($health['status'])) {
                        $checks['checks']['opensearch'] = [
                            'status' => $health['status'] === 'green' || $health['status'] === 'yellow' ? 'ok' : 'error',
                            'cluster_status' => $health['status'],
                            'number_of_nodes' => $health['number_of_nodes'] ?? 0,
                        ];
                        if ($health['status'] === 'red') {
                            $checks['status'] = 'degraded';
                        }
                    } else {
                        throw new \Exception('Invalid JSON response');
                    }
                } else {
                    throw new \Exception($curlError ?: "HTTP $httpCode");
                }
            } else {
                // Fallback to file_get_contents (less secure, but works)
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 5,
                        'ignore_errors' => true,
                    ],
                ]);
                
                if ($opensearchPassword) {
                    $auth = base64_encode('admin:' . $opensearchPassword);
                    $context = stream_context_create([
                        'http' => [
                            'timeout' => 5,
                            'ignore_errors' => true,
                            'header' => "Authorization: Basic $auth\r\n",
                        ],
                    ]);
                }
                
                $response = @file_get_contents($healthUrl, false, $context);
                if ($response === false) {
                    throw new \Exception('Failed to connect to OpenSearch');
                }
                
                $health = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($health['status'])) {
                    $checks['checks']['opensearch'] = [
                        'status' => $health['status'] === 'green' || $health['status'] === 'yellow' ? 'ok' : 'error',
                        'cluster_status' => $health['status'],
                        'number_of_nodes' => $health['number_of_nodes'] ?? 0,
                    ];
                    if ($health['status'] === 'red') {
                        $checks['status'] = 'degraded';
                    }
                } else {
                    throw new \Exception('Invalid JSON response');
                }
            }
        } catch (\Exception $e) {
            $checks['checks']['opensearch'] = [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
            $checks['status'] = 'degraded';
        }

        // Memory check
        $memInfo = $this->getMemoryInfo();
        $checks['checks']['memory'] = $memInfo;
        if (($memInfo['usage_percent'] ?? 0) > 90) {
            $checks['status'] = 'degraded';
        }

        // Disk check
        $diskInfo = $this->getDiskInfo();
        $checks['checks']['disk'] = $diskInfo;
        if (($diskInfo['usage_percent'] ?? 0) > 90) {
            $checks['status'] = 'degraded';
        }

        $statusCode = $checks['status'] === 'ok' ? 200 : ($checks['status'] === 'degraded' ? 200 : 503);
        return new JsonResponse($checks, $statusCode);
    }

    #[Route(path: '/api/_info/health-check/ready', name: 'api.info.health-check.ready', methods: ['GET'])]
    public function readinessCheck(): JsonResponse
    {
        // Check if application is ready to serve traffic
        $ready = true;
        $checks = [];

        try {
            $this->connection->executeQuery('SELECT 1');
            $checks['database'] = 'ready';
        } catch (\Exception $e) {
            $checks['database'] = 'not_ready';
            $ready = false;
        }

        if (!$ready) {
            return new JsonResponse([
                'status' => 'not_ready',
                'checks' => $checks,
            ], 503);
        }

        return new JsonResponse([
            'status' => 'ready',
            'checks' => $checks,
        ]);
    }

    #[Route(path: '/api/_info/health-check/live', name: 'api.info.health-check.live', methods: ['GET'])]
    public function livenessCheck(): JsonResponse
    {
        // Simple liveness check - application is running
        return new JsonResponse([
            'status' => 'alive',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    private function getMemoryInfo(): array
    {
        $memInfo = @file_get_contents('/proc/meminfo');
        if (!$memInfo) {
            return ['status' => 'unknown'];
        }

        preg_match('/MemTotal:\s+(\d+)\s+kB/', $memInfo, $total);
        preg_match('/MemAvailable:\s+(\d+)\s+kB/', $memInfo, $available);
        preg_match('/MemFree:\s+(\d+)\s+kB/', $memInfo, $free);

        if (!isset($total[1]) || !isset($available[1])) {
            return ['status' => 'unknown'];
        }

        $totalKb = (int) $total[1];
        $availableKb = (int) $available[1];
        $usedKb = $totalKb - $availableKb;
        $usagePercent = ($usedKb / $totalKb) * 100;

        return [
            'status' => $usagePercent > 90 ? 'critical' : ($usagePercent > 80 ? 'warning' : 'ok'),
            'total_mb' => round($totalKb / 1024, 2),
            'used_mb' => round($usedKb / 1024, 2),
            'available_mb' => round($availableKb / 1024, 2),
            'usage_percent' => round($usagePercent, 2),
        ];
    }

    private function getDiskInfo(): array
    {
        $stat = @disk_free_space('/');
        if ($stat === false) {
            return ['status' => 'unknown'];
        }

        $free = $stat;
        $total = @disk_total_space('/') ?: 0;
        $used = $total - $free;
        $usagePercent = $total > 0 ? ($used / $total) * 100 : 0;

        return [
            'status' => $usagePercent > 90 ? 'critical' : ($usagePercent > 80 ? 'warning' : 'ok'),
            'total_gb' => round($total / (1024 ** 3), 2),
            'used_gb' => round($used / (1024 ** 3), 2),
            'free_gb' => round($free / (1024 ** 3), 2),
            'usage_percent' => round($usagePercent, 2),
        ];
    }
}
