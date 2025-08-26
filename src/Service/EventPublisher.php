<?php
declare(strict_types=1);

namespace App\Service;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

class EventPublisher
{
    private ?AMQPStreamConnection $connection = null;
    private ?\PhpAmqpLib\Channel\AMQPChannel $channel = null;
    private const EXCHANGE_NAME = 'church.events';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $rabbitmqHost = 'church-rabbitmq',
        private readonly int $rabbitmqPort = 5672,
        private readonly string $rabbitmqUser = 'guest',
        private readonly string $rabbitmqPassword = 'guest',
        private readonly string $rabbitmqVhost = 'church'
    ) {}

    public function publish(string $routingKey, array $data): void
    {
        try {
            $this->ensureConnection();
            
            $message = new AMQPMessage(
                json_encode($data),
                [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'timestamp' => time()
                ]
            );
            
            $this->channel->basic_publish(
                $message,
                self::EXCHANGE_NAME,
                $routingKey
            );
            
            $this->logger->info('Event published', [
                'routing_key' => $routingKey,
                'exchange' => self::EXCHANGE_NAME,
                'data' => $data
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to publish event', [
                'routing_key' => $routingKey,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function ensureConnection(): void
    {
        if ($this->connection === null || !$this->connection->isConnected()) {
            $this->connection = new AMQPStreamConnection(
                $this->rabbitmqHost,
                $this->rabbitmqPort,
                $this->rabbitmqUser,
                $this->rabbitmqPassword,
                $this->rabbitmqVhost
            );
            $this->channel = $this->connection->channel();
            
            $this->channel->exchange_declare(
                self::EXCHANGE_NAME,
                'topic',
                false,
                true,
                false
            );
            
            $this->logger->info('RabbitMQ connection established');
        }
    }

    public function __destruct()
    {
        if ($this->channel) {
            $this->channel->close();
        }
        if ($this->connection && $this->connection->isConnected()) {
            $this->connection->close();
        }
    }
}