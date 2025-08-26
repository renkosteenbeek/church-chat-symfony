<?php
declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SignalServiceClient
{
    private const DEFAULT_SIGNAL_NUMBER = '+31682016353';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(SIGNAL_SERVICE_URL)%')]
        private readonly string $signalServiceUrl,
        #[Autowire('%env(SIGNAL_NUMBER)%')]
        private readonly string $signalNumber = self::DEFAULT_SIGNAL_NUMBER
    ) {}

    public function sendMessage(string $recipient, string $message, array $metadata = []): array
    {
        try {
            $payload = [
                'from' => $this->signalNumber,
                'to' => $this->normalizePhoneNumber($recipient),
                'message' => $message,
                'metadata' => $metadata
            ];

            $response = $this->httpClient->request('POST', $this->signalServiceUrl . '/api/v1/send', [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json'
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('Message sent via Signal Service', [
                    'recipient' => $recipient,
                    'message_length' => strlen($message),
                    'response' => $data
                ]);

                return [
                    'success' => true,
                    'data' => $data
                ];
            } else {
                throw new \Exception('Unexpected status code: ' . $statusCode);
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to send message via Signal Service', [
                'recipient' => $recipient,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getMessageStatus(string $messageId): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->signalServiceUrl . '/api/v1/status/' . $messageId);
            
            $data = $response->toArray();

            return [
                'success' => true,
                'status' => $data['status'] ?? 'unknown',
                'data' => $data
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to get message status', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function registerPhone(string $phoneNumber, string $verificationCode): array
    {
        try {
            $payload = [
                'phone' => $this->normalizePhoneNumber($phoneNumber),
                'verification_code' => $verificationCode
            ];

            $response = $this->httpClient->request('POST', $this->signalServiceUrl . '/api/v1/register', [
                'json' => $payload
            ]);

            $data = $response->toArray();

            $this->logger->info('Phone registered with Signal Service', [
                'phone' => $phoneNumber
            ]);

            return [
                'success' => true,
                'data' => $data
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to register phone', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function normalizePhoneNumber(string $phoneNumber): string
    {
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);
        
        if (!str_starts_with($phoneNumber, '+')) {
            if (str_starts_with($phoneNumber, '0')) {
                $phoneNumber = '+31' . substr($phoneNumber, 1);
            } else {
                $phoneNumber = '+' . $phoneNumber;
            }
        }
        
        return $phoneNumber;
    }

    public function testConnection(): bool
    {
        try {
            $response = $this->httpClient->request('GET', $this->signalServiceUrl . '/health');
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            $this->logger->error('Signal Service connection test failed', [
                'url' => $this->signalServiceUrl,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}