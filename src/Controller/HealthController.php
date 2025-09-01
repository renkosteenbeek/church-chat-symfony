<?php
declare(strict_types=1);

namespace App\Controller;

use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class HealthController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {}

    #[Route('/health', methods: ['GET'])]
    #[OA\Get(
        path: '/health',
        summary: 'Health check endpoint',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service is healthy',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'healthy'),
                        new OA\Property(property: 'service', type: 'string', example: 'church-chat-service'),
                        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                        new OA\Property(
                            property: 'checks',
                            properties: [
                                new OA\Property(property: 'database', type: 'boolean'),
                                new OA\Property(property: 'rabbitmq', type: 'boolean')
                            ],
                            type: 'object'
                        )
                    ]
                )
            )
        ]
    )]
    public function health(): JsonResponse
    {
        $checks = [
            'database' => false,
            'rabbitmq' => false
        ];

        try {
            if (!$this->connection->isConnected()) {
                $this->connection->connect();
            }
            $this->connection->executeQuery('SELECT 1');
            $checks['database'] = true;
        } catch (\Exception $e) {
            try {
                $this->connection->close();
                $this->connection->connect();
                $this->connection->executeQuery('SELECT 1');
                $checks['database'] = true;
            } catch (\Exception $retryException) {
                $this->logger->error('Database health check failed after retry', ['error' => $retryException->getMessage()]);
            }
        }

        $checks['rabbitmq'] = extension_loaded('amqp');

        $isHealthy = $checks['database'] && $checks['rabbitmq'];

        return $this->json([
            'status' => $isHealthy ? 'healthy' : 'unhealthy',
            'service' => 'church-chat-service',
            'timestamp' => (new \DateTime())->format('c'),
            'checks' => $checks
        ], $isHealthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}