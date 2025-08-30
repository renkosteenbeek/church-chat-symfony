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
    private const RETRY_DELAY = 1000000;
    
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

    public function createConversation(Member $member, string $initialMessage): array
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
                    'member_id' => $member->getId()
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

    public function sendMessage(
        string $conversationId, 
        string $message, 
        int $churchId, 
        Member $member
    ): array {
        $vectorStore = $this->contentApiClient->getVectorStore($churchId);
        $vectorStoreId = $vectorStore['vector_store_id'] ?? null;
        
        $tools = $this->buildCompleteToolset($vectorStoreId);
        $instructions = $this->getProactiveInstructions($member->getTargetGroup());
        
        $requestData = [
            'model' => $this->model,
            'conversation' => $conversationId,
            'store' => true,
            'instructions' => $instructions,
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
            
            $this->processOpenAIResponse($response, $member, $conversationId);
            
            $member->updateLastActivity();
            $this->memberRepository->save($member, true);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send message to OpenAI', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function sendToolOutput(
        string $conversationId, 
        string $callId, 
        array $output, 
        Member $member,
        int $churchId
    ): array {
        $vectorStore = $this->contentApiClient->getVectorStore($churchId);
        $vectorStoreId = $vectorStore['vector_store_id'] ?? null;
        
        $tools = $this->buildCompleteToolset($vectorStoreId);
        
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
            'tools' => $tools,
            'tool_choice' => 'auto'
        ];

        try {
            $response = $this->makeApiRequest('POST', '/responses', $requestData);
            
            $this->processOpenAIResponse($response, $member, $conversationId);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send tool output to OpenAI', [
                'conversation_id' => $conversationId,
                'call_id' => $callId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function processOpenAIResponse(array $response, Member $member, string $conversationId): void
    {
        if (!isset($response['output']) || !is_array($response['output'])) {
            return;
        }

        foreach ($response['output'] as $output) {
            if ($output['type'] === 'message' && $output['status'] === 'completed') {
                $content = $this->extractMessageContent($output);
                if ($content) {
                    $assistantHistory = ChatHistory::createAssistantMessage(
                        $member,
                        $conversationId,
                        $content,
                        $response['id'] ?? null,
                        null
                    );
                    
                    
                    $this->chatHistoryRepository->save($assistantHistory, true);
                }
            } elseif ($output['type'] === 'function_call' && $output['status'] === 'completed') {
                $toolCall = [
                    'id' => $output['id'] ?? null,
                    'call_id' => $output['call_id'] ?? null,
                    'name' => $output['name'] ?? null,
                    'arguments' => $output['arguments'] ?? null
                ];
                
                $assistantHistory = ChatHistory::createAssistantMessage(
                    $member,
                    $conversationId,
                    'Tool call: ' . ($output['name'] ?? 'unknown'),
                    $response['id'] ?? null,
                    [$toolCall]
                );
                
                $this->chatHistoryRepository->save($assistantHistory, true);
            }
        }
    }

    private function extractMessageContent(array $messageOutput): ?string
    {
        if (!isset($messageOutput['content']) || !is_array($messageOutput['content'])) {
            return null;
        }
        
        foreach ($messageOutput['content'] as $content) {
            if ($content['type'] === 'output_text' && isset($content['text'])) {
                return $content['text'];
            }
        }
        
        return null;
    }

    public function extractToolCalls(array $response): array
    {
        $toolCalls = [];
        
        if (!isset($response['output']) || !is_array($response['output'])) {
            return $toolCalls;
        }
        
        foreach ($response['output'] as $output) {
            if ($output['type'] === 'function_call' && $output['status'] === 'completed') {
                $toolCalls[] = [
                    'id' => $output['id'] ?? null,
                    'call_id' => $output['call_id'] ?? null,
                    'name' => $output['name'] ?? null,
                    'arguments' => $output['arguments'] ?? null
                ];
            }
        }
        
        return $toolCalls;
    }

    public function extractResponseText(array $response): ?string
    {
        if (!isset($response['output']) || !is_array($response['output'])) {
            return null;
        }
        
        foreach ($response['output'] as $output) {
            if ($output['type'] === 'message' && $output['status'] === 'completed') {
                return $this->extractMessageContent($output);
            }
        }
        
        return null;
    }

    private function getProactiveInstructions(?string $targetGroup): string
    {
        $requestDesignPath = __DIR__ . '/../../docs/request_design.json';
        $instructions = '';
        
        if (file_exists($requestDesignPath)) {
            $baseInstructions = file_get_contents($requestDesignPath);
            $requestDesign = json_decode($baseInstructions, true);
            $instructions = $requestDesign['instructions'] ?? '';
        }
        
        if (!$instructions) {
            $instructions = "Je bent de Preek In Je Week assistent. KERNREGEL: Wees PROACTIEF - als je informatie kunt extraheren of een actie kunt ondernemen, gebruik dan DIRECT de relevante tool.\n\n";
            $instructions .= "WERKWIJZE:\n";
            $instructions .= "1. Parse ELKE input voor bruikbare informatie\n";
            $instructions .= "2. Gevonden? → Tool DIRECT gebruiken\n";
            $instructions .= "3. Dan pas natuurlijk reageren\n\n";
            $instructions .= "VOORBEELDEN:\n";
            $instructions .= "• 'Mijn naam is Renko' → manage_user tool → 'Hallo Renko!'\n";
            $instructions .= "• 'Maan naam is Renko' (typo) → manage_user tool → 'Hallo Renko!'\n";
            $instructions .= "• 'Ja graag' (na preek vraag) → handle_sermon tool\n";
            $instructions .= "• 'Wat betekent genade?' → answer_question tool\n";
            $instructions .= "• 'Te veel berichten' → manage_subscription tool\n\n";
            $instructions .= "CONTEXT: Check altijd of er een vraag openstaat zoals 'Was je bij de dienst?'\n\n";
            $instructions .= "TAAL: Persoonlijk (je/jij), warm, bondig.";
        }
        
        $targetPrompts = [
            Member::TARGET_GROUP_VOLWASSEN => " Gebruik een volwassen, respectvolle toon.",
            Member::TARGET_GROUP_VERDIEPING => " Ga dieper in op theologische concepten en gebruik bijbelverwijzingen waar relevant.",
            Member::TARGET_GROUP_JONGEREN => " Gebruik een informele, moderne toon die jongeren aanspreekt."
        ];
        
        return $instructions . ($targetPrompts[$targetGroup] ?? "");
    }

    private function buildCompleteToolset(?string $vectorStoreId): array
    {
        $tools = [];
        
        $tools[] = [
            'type' => 'function',
            'name' => 'handle_sermon',
            'description' => 'GEBRUIK BIJ: ja/nee op samenvattingsvraag, aanwezigheid dienst, samenvatting verzoek, andere kerk bezocht',
            'strict' => false,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['get_summary', 'register_attendance', 'register_absence'],
                        'description' => 'Welke actie uitvoeren'
                    ],
                    'attended' => [
                        'type' => 'boolean',
                        'description' => 'Was aanwezig bij dienst'
                    ],
                    'wants_summary' => [
                        'type' => 'boolean',
                        'description' => 'Wil samenvatting ontvangen'
                    ],
                    'alternative_church' => [
                        'type' => 'string',
                        'description' => 'Naam andere kerk indien bezocht'
                    ],
                    'online_attended' => [
                        'type' => 'boolean',
                        'description' => 'Online gekeken/geluisterd'
                    ]
                ],
                'required' => ['action']
            ]
        ];
        
        $tools[] = [
            'type' => 'function',
            'name' => 'manage_user',
            'description' => 'GEBRUIK BIJ: naam genoemd, leeftijd, kerk wijzigen, doelgroep instellen. OOK BIJ TYPOS zoals \'maan naam\'',
            'strict' => false,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Naam van gebruiker'
                    ],
                    'age' => [
                        'type' => 'integer',
                        'description' => 'Leeftijd'
                    ],
                    'church' => [
                        'type' => 'string',
                        'description' => 'Huidige kerk'
                    ],
                    'target_group' => [
                        'type' => 'string',
                        'enum' => ['jongeren', 'volwassenen', 'verdieping', 'gezinnen'],
                        'description' => 'Content doelgroep'
                    ],
                    'additional_info' => [
                        'type' => 'object',
                        'description' => 'Overige gebruikersinfo'
                    ]
                ],
                'required' => []
            ]
        ];
        
        $tools[] = [
            'type' => 'function',
            'name' => 'manage_subscription',
            'description' => 'GEBRUIK BIJ: notificaties aanpassen, pauzeren, afmelden, te veel/weinig berichten',
            'strict' => false,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['change_frequency', 'pause', 'unsubscribe', 'resume'],
                        'description' => 'Type actie'
                    ],
                    'notification_type' => [
                        'type' => 'string',
                        'enum' => ['all', 'summary', 'reflection', 'weekly'],
                        'description' => 'Welke notificaties'
                    ],
                    'frequency' => [
                        'type' => 'string',
                        'enum' => ['daily', 'weekly', 'biweekly', 'never'],
                        'description' => 'Nieuwe frequentie'
                    ],
                    'pause_until' => [
                        'type' => 'string',
                        'description' => 'Pauzeren tot datum (YYYY-MM-DD)'
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Reden voor wijziging'
                    ]
                ],
                'required' => ['action']
            ]
        ];
        
        $tools[] = [
            'type' => 'function',
            'name' => 'answer_question',
            'description' => 'GEBRUIK BIJ: vragen over geloof, God, Bijbel, preek betekenis, theologische onderwerpen',
            'strict' => false,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'question' => [
                        'type' => 'string',
                        'description' => 'De gestelde vraag'
                    ],
                    'category' => [
                        'type' => 'string',
                        'enum' => ['theology', 'bible', 'sermon', 'faith', 'practical'],
                        'description' => 'Type vraag'
                    ],
                    'needs_search' => [
                        'type' => 'boolean',
                        'description' => 'Vector store doorzoeken voor context'
                    ]
                ],
                'required' => ['question', 'category']
            ]
        ];
        
        $tools[] = [
            'type' => 'function',
            'name' => 'process_feedback',
            'description' => 'GEBRUIK BIJ: feedback, klachten, suggesties, vragen over de app, technische problemen',
            'strict' => false,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'enum' => ['feedback', 'complaint', 'suggestion', 'question', 'technical'],
                        'description' => 'Type feedback'
                    ],
                    'message' => [
                        'type' => 'string',
                        'description' => 'De feedback/vraag'
                    ],
                    'severity' => [
                        'type' => 'string',
                        'enum' => ['low', 'medium', 'high'],
                        'description' => 'Prioriteit'
                    ]
                ],
                'required' => ['type', 'message']
            ]
        ];
        
        if ($vectorStoreId) {
            $tools[] = [
                'type' => 'file_search',
                'vector_store_ids' => [$vectorStoreId]
            ];
        }
        
        return $tools;
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