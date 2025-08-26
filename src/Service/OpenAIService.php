<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\ChatHistory;
use App\Entity\Member;
use App\Repository\ChatHistoryRepository;
use App\Repository\MemberRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class OpenAIService  
{
    private const API_BASE_URL = 'https://api.openai.com/v1';
    private const DEFAULT_MODEL = 'gpt-5-nano';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 1000000; // 1 second in microseconds
    
    private string $apiKey;
    private string $model;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ContentApiClient $contentApiClient,
        private readonly ChatHistoryRepository $chatHistoryRepository,
        private readonly MemberRepository $memberRepository,
        private readonly LoggerInterface $logger,
        ?string $apiKey = null,
        ?string $model = null
    ) {
        $this->apiKey = $apiKey ?? $_ENV['OPENAI_API_KEY'] ?? '';
        $this->model = $model ?? $_ENV['OPENAI_MODEL'] ?? self::DEFAULT_MODEL;
        
        if (empty($this->apiKey)) {
            throw new \RuntimeException('OpenAI API key is not configured');
        }
    }

    public function createConversation(Member $member, string $initialMessage, int $churchId): array
    {
        $metadata = [
            'topic' => $member->getPhoneNumber()
        ];

        $requestData = [
            'metadata' => $metadata,
            'items' => [
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => $initialMessage
                ]
            ]
        ];

        try {
            $response = $this->makeApiRequest('POST', '/conversations', $requestData);
            
            if (isset($response['id'])) {
                $member->setOpenaiConversationId($response['id']);
                $this->memberRepository->save($member, true);
                
                $chatHistory = ChatHistory::createAssistantMessage(
                    $member,
                    $response['id'],
                    $initialMessage
                );
                $this->chatHistoryRepository->save($chatHistory, true);
                
                $this->logger->info('Created new OpenAI conversation', [
                    'conversation_id' => $response['id'],
                    'member_id' => $member->getId(),
                    'church_id' => $churchId
                ]);
            }
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create OpenAI conversation', [
                'member_id' => $member->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function sendMessage(string $conversationId, string $message, int $churchId, Member $member): array
    {
        $vectorStore = $this->contentApiClient->getVectorStore($churchId);
        $vectorStoreId = $vectorStore['vector_store_id'] ?? null;
        
        $tools = $this->buildTools($vectorStoreId);
        $systemPrompt = $this->getSystemPrompt($member->getTargetGroup());
        
        $requestData = [
            'model' => $this->model,
            'conversation' => $conversationId,
            'store' => true,
            'instructions' => $systemPrompt,
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $message
                        ]
                    ]
                ]
            ],
            'tools' => $tools,
            'tool_choice' => 'auto'
        ];

        try {
            $chatHistory = ChatHistory::createUserMessage($member, $conversationId, $message);
            $this->chatHistoryRepository->save($chatHistory, true);
            
            $response = $this->makeApiRequest('POST', '/responses', $requestData);
            
            if (isset($response['output'])) {
                $responseContent = $this->extractResponseContent($response);
                $toolCalls = $this->extractToolCalls($response);
                
                if ($responseContent) {
                    $assistantHistory = ChatHistory::createAssistantMessage(
                        $member,
                        $conversationId,
                        $responseContent,
                        $response['id'] ?? null,
                        $toolCalls
                    );
                    
                    if (isset($response['usage'])) {
                        $assistantHistory->addMetadata('usage', $response['usage']);
                    }
                    
                    $this->chatHistoryRepository->save($assistantHistory, true);
                }
                
                $member->updateLastActivity();
                $this->memberRepository->save($member, true);
                
                $this->logger->info('Sent message to OpenAI', [
                    'conversation_id' => $conversationId,
                    'response_id' => $response['id'] ?? 'unknown',
                    'has_tool_calls' => !empty($toolCalls)
                ]);
            }
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send message to OpenAI', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function handleToolCall(string $conversationId, string $callId, array $output, Member $member): array
    {
        $requestData = [
            'model' => $this->model,
            'conversation' => $conversationId,
            'store' => true,
            'input' => [
                [
                    'type' => 'function_call_output',
                    'call_id' => $callId,
                    'output' => json_encode($output)
                ]
            ],
            'tools' => $this->buildTools(null),
            'tool_choice' => 'auto'
        ];

        try {
            $response = $this->makeApiRequest('POST', '/responses', $requestData);
            
            if (isset($response['output'])) {
                $responseContent = $this->extractResponseContent($response);
                
                if ($responseContent) {
                    $assistantHistory = ChatHistory::createAssistantMessage(
                        $member,
                        $conversationId,
                        $responseContent,
                        $response['id'] ?? null,
                        null
                    );
                    
                    if (isset($response['usage'])) {
                        $assistantHistory->addMetadata('usage', $response['usage']);
                    }
                    
                    $this->chatHistoryRepository->save($assistantHistory, true);
                }
                
                $this->logger->info('Handled tool call response', [
                    'conversation_id' => $conversationId,
                    'call_id' => $callId
                ]);
            }
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Failed to handle tool call', [
                'conversation_id' => $conversationId,
                'call_id' => $callId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function getSystemPrompt(?string $targetGroup): string
    {
        $basePrompt = "Je bent een assistent die preken samenvat en vragen beantwoordt over de kerk. ";
        $basePrompt .= "Als de vraag mogelijk over de preek van deze week gaat, gebruik dan file_search op de meegegeven vector store. ";
        $basePrompt .= "Wees vriendelijk en behulpzaam. Je wordt gebruikt op Signal. ";
        $basePrompt .= "BELANGRIJK: Als de gebruiker leeftijd of naam deelt, roep ALTIJD de tool 'update_user' aan en bevestig dat het is opgeslagen.";
        
        $targetPrompts = [
            Member::TARGET_GROUP_VOLWASSEN => " Gebruik een volwassen, respectvolle toon.",
            Member::TARGET_GROUP_VERDIEPING => " Ga dieper in op theologische concepten en gebruik bijbelverwijzingen waar relevant.",
            Member::TARGET_GROUP_JONGEREN => " Gebruik een informele, moderne toon die jongeren aanspreekt. Gebruik voorbeelden uit het dagelijks leven."
        ];
        
        return $basePrompt . ($targetPrompts[$targetGroup] ?? "");
    }

    private function buildTools(?string $vectorStoreId): array
    {
        $tools = [];
        
        if ($vectorStoreId) {
            $tools[] = [
                'type' => 'file_search',
                'vector_store_ids' => [$vectorStoreId]
            ];
        }
        
        $tools[] = [
            'type' => 'function',
            'name' => 'update_user',
            'description' => 'Sla gedeelde persoonlijke gegevens (leeftijd en/of naam) van de gebruiker op.',
            'strict' => false,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'age' => [
                        'type' => 'integer',
                        'min' => 5,
                        'max' => 100,
                        'description' => 'The age of the user'
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'The first name of the user'
                    ]
                ],
                'required' => ['name'],
                'additionalProperties' => false
            ]
        ];
        
        return $tools;
    }

    private function extractResponseContent(array $response): ?string
    {
        if (!isset($response['output']) || !is_array($response['output'])) {
            return null;
        }
        
        foreach ($response['output'] as $output) {
            if ($output['type'] === 'message' && isset($output['content'])) {
                foreach ($output['content'] as $content) {
                    if ($content['type'] === 'output_text' && isset($content['text'])) {
                        return $content['text'];
                    }
                }
            }
        }
        
        return null;
    }

    private function extractToolCalls(array $response): ?array
    {
        if (!isset($response['output']) || !is_array($response['output'])) {
            return null;
        }
        
        $toolCalls = [];
        
        foreach ($response['output'] as $output) {
            if ($output['type'] === 'function_call') {
                $toolCalls[] = [
                    'id' => $output['id'] ?? null,
                    'call_id' => $output['call_id'] ?? null,
                    'name' => $output['name'] ?? null,
                    'arguments' => $output['arguments'] ?? null
                ];
            }
        }
        
        return empty($toolCalls) ? null : $toolCalls;
    }

    private function makeApiRequest(string $method, string $endpoint, array $data = []): array
    {
        $url = self::API_BASE_URL . $endpoint;
        $retries = 0;
        
        while ($retries < self::MAX_RETRIES) {
            try {
                $options = [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                    ]
                ];
                
                if (!empty($data)) {
                    $options['json'] = $data;
                }
                
                $response = $this->httpClient->request($method, $url, $options);
                $statusCode = $response->getStatusCode();
                
                if ($statusCode >= 200 && $statusCode < 300) {
                    return $response->toArray();
                }
                
                if ($statusCode === 429 || $statusCode >= 500) {
                    $retries++;
                    if ($retries < self::MAX_RETRIES) {
                        usleep(self::RETRY_DELAY * $retries);
                        continue;
                    }
                }
                
                throw new \RuntimeException("OpenAI API request failed with status {$statusCode}");
                
            } catch (ExceptionInterface $e) {
                $retries++;
                if ($retries >= self::MAX_RETRIES) {
                    $this->logger->error('OpenAI API request failed after retries', [
                        'endpoint' => $endpoint,
                        'error' => $e->getMessage(),
                        'retries' => $retries
                    ]);
                    throw new \RuntimeException('OpenAI API request failed: ' . $e->getMessage(), 0, $e);
                }
                
                usleep(self::RETRY_DELAY * $retries);
            }
        }
        
        throw new \RuntimeException('OpenAI API request failed after maximum retries');
    }

    public function testConnection(): bool
    {
        try {
            $response = $this->makeApiRequest('GET', '/models');
            return !empty($response['data']);
        } catch (\Exception $e) {
            $this->logger->error('OpenAI connection test failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}