<?php
declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class ContentApiClient
{
    private const CACHE_TTL = 300; // 5 minutes
    
    private FilesystemAdapter $cache;
    private string $contentServiceUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        string $contentServiceUrl = null
    ) {
        $this->contentServiceUrl = $contentServiceUrl ?? $_ENV['CONTENT_SERVICE_URL'] ?? 'http://church-content-service:8101';
        $this->cache = new FilesystemAdapter('content_api', self::CACHE_TTL);
    }

    public function getVectorStore(int $churchId): array
    {
        $cacheKey = "vector_store_{$churchId}";
        
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($churchId) {
                $item->expiresAfter(self::CACHE_TTL);
                
                $response = $this->httpClient->request('GET', "{$this->contentServiceUrl}/api/v1/vector-store/{$churchId}");
                
                if ($response->getStatusCode() !== 200) {
                    $this->logger->error('Failed to fetch vector store', [
                        'church_id' => $churchId,
                        'status_code' => $response->getStatusCode()
                    ]);
                    return [
                        'vector_store_id' => null,
                        'file_ids' => []
                    ];
                }
                
                return $response->toArray();
            });
        } catch (ExceptionInterface $e) {
            $this->logger->error('Error fetching vector store from Content Service', [
                'church_id' => $churchId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'vector_store_id' => null,
                'file_ids' => []
            ];
        }
    }

    public function getChurchInfo(int $churchId): ?array
    {
        $cacheKey = "church_info_{$churchId}";
        
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($churchId) {
                $item->expiresAfter(self::CACHE_TTL);
                
                $response = $this->httpClient->request('GET', "{$this->contentServiceUrl}/api/v1/churches/{$churchId}");
                
                if ($response->getStatusCode() === 404) {
                    return null;
                }
                
                if ($response->getStatusCode() !== 200) {
                    $this->logger->error('Failed to fetch church info', [
                        'church_id' => $churchId,
                        'status_code' => $response->getStatusCode()
                    ]);
                    return null;
                }
                
                return $response->toArray();
            });
        } catch (ExceptionInterface $e) {
            $this->logger->error('Error fetching church info from Content Service', [
                'church_id' => $churchId,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    public function getContentDetails(string $contentId, ?string $audience = null): ?array
    {
        $cacheKey = $audience 
            ? "content_details_{$contentId}_{$audience}" 
            : "content_details_{$contentId}";
        
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($contentId, $audience) {
                $item->expiresAfter(self::CACHE_TTL);
                
                $query = $audience ? ['audience' => $audience] : [];
                $response = $this->httpClient->request('GET', "{$this->contentServiceUrl}/api/v1/content/{$contentId}", [
                    'query' => $query
                ]);
                
                if ($response->getStatusCode() === 404) {
                    return null;
                }
                
                if ($response->getStatusCode() !== 200) {
                    $this->logger->error('Failed to fetch content details', [
                        'content_id' => $contentId,
                        'audience' => $audience,
                        'status_code' => $response->getStatusCode()
                    ]);
                    return null;
                }
                
                return $response->toArray();
            });
        } catch (ExceptionInterface $e) {
            $this->logger->error('Error fetching content details from Content Service', [
                'content_id' => $contentId,
                'audience' => $audience,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    public function getActiveSermon(int $churchId): ?array
    {
        $cacheKey = "active_sermon_{$churchId}";
        
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($churchId) {
                $item->expiresAfter(60); // Shorter TTL for active sermon
                
                $response = $this->httpClient->request('GET', "{$this->contentServiceUrl}/api/v1/sermons/active", [
                    'query' => ['church_id' => $churchId]
                ]);
                
                if ($response->getStatusCode() === 404) {
                    return null;
                }
                
                if ($response->getStatusCode() !== 200) {
                    $this->logger->error('Failed to fetch active sermon', [
                        'church_id' => $churchId,
                        'status_code' => $response->getStatusCode()
                    ]);
                    return null;
                }
                
                return $response->toArray();
            });
        } catch (ExceptionInterface $e) {
            $this->logger->error('Error fetching active sermon from Content Service', [
                'church_id' => $churchId,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    public function getAllChurches(): array
    {
        $cacheKey = "all_churches";
        
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) {
                $item->expiresAfter(self::CACHE_TTL * 2); // Longer cache for church list
                
                $response = $this->httpClient->request('GET', "{$this->contentServiceUrl}/api/v1/churches");
                
                if ($response->getStatusCode() !== 200) {
                    $this->logger->error('Failed to fetch churches', [
                        'status_code' => $response->getStatusCode()
                    ]);
                    return [];
                }
                
                return $response->toArray();
            });
        } catch (ExceptionInterface $e) {
            $this->logger->error('Error fetching churches from Content Service', [
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }

    public function getSermonContent(string $sermonId): ?array
    {
        $cacheKey = "sermon_content_{$sermonId}";
        
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($sermonId) {
                $item->expiresAfter(self::CACHE_TTL * 4); // Longer cache for sermon content
                
                $response = $this->httpClient->request('GET', "{$this->contentServiceUrl}/api/v1/sermons/{$sermonId}/content");
                
                if ($response->getStatusCode() === 404) {
                    return null;
                }
                
                if ($response->getStatusCode() !== 200) {
                    $this->logger->error('Failed to fetch sermon content', [
                        'sermon_id' => $sermonId,
                        'status_code' => $response->getStatusCode()
                    ]);
                    return null;
                }
                
                return $response->toArray();
            });
        } catch (ExceptionInterface $e) {
            $this->logger->error('Error fetching sermon content from Content Service', [
                'sermon_id' => $sermonId,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    public function clearCache(?string $prefix = null): void
    {
        if ($prefix) {
            $this->cache->deleteItems([$prefix]);
        } else {
            $this->cache->clear();
        }
        
        $this->logger->info('Content API cache cleared', ['prefix' => $prefix]);
    }

    public function getSermonSummary(string $sermonId, string $targetGroup = 'volwassen'): ?array
    {
        $cacheKey = "sermon_summary_{$sermonId}_{$targetGroup}";
        
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($sermonId, $targetGroup) {
                $item->expiresAfter(self::CACHE_TTL * 2);
                
                $response = $this->httpClient->request('GET', "{$this->contentServiceUrl}/api/v1/sermons/{$sermonId}/summary", [
                    'query' => ['audience' => $targetGroup]
                ]);
                
                if ($response->getStatusCode() === 404) {
                    return null;
                }
                
                if ($response->getStatusCode() !== 200) {
                    $this->logger->error('Failed to fetch sermon summary', [
                        'sermon_id' => $sermonId,
                        'target_group' => $targetGroup,
                        'status_code' => $response->getStatusCode()
                    ]);
                    return null;
                }
                
                return $response->toArray();
            });
        } catch (ExceptionInterface $e) {
            $this->logger->error('Error fetching sermon summary from Content Service', [
                'sermon_id' => $sermonId,
                'target_group' => $targetGroup,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    public function getChurchByName(string $churchName): ?array
    {
        $cacheKey = "church_by_name_" . md5(strtolower($churchName));
        
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($churchName) {
                $item->expiresAfter(self::CACHE_TTL);
                
                $response = $this->httpClient->request('GET', "{$this->contentServiceUrl}/api/v1/churches/search", [
                    'query' => ['name' => $churchName]
                ]);
                
                if ($response->getStatusCode() === 404) {
                    return null;
                }
                
                if ($response->getStatusCode() !== 200) {
                    $this->logger->error('Failed to search church by name', [
                        'church_name' => $churchName,
                        'status_code' => $response->getStatusCode()
                    ]);
                    return null;
                }
                
                $churches = $response->toArray();
                
                if (empty($churches['data'])) {
                    return null;
                }
                
                foreach ($churches['data'] as $church) {
                    if (stripos($church['name'], $churchName) !== false) {
                        return $church;
                    }
                }
                
                return $churches['data'][0] ?? null;
            });
        } catch (ExceptionInterface $e) {
            $this->logger->error('Error searching church by name from Content Service', [
                'church_name' => $churchName,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    public function getReflectionQuestions(string $sermonId, string $targetGroup = 'volwassen'): ?array
    {
        $cacheKey = "reflection_questions_{$sermonId}_{$targetGroup}";
        
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($sermonId, $targetGroup) {
                $item->expiresAfter(self::CACHE_TTL * 4);
                
                $response = $this->httpClient->request('GET', "{$this->contentServiceUrl}/api/v1/sermons/{$sermonId}/reflections", [
                    'query' => ['audience' => $targetGroup]
                ]);
                
                if ($response->getStatusCode() === 404) {
                    return null;
                }
                
                if ($response->getStatusCode() !== 200) {
                    $this->logger->error('Failed to fetch reflection questions', [
                        'sermon_id' => $sermonId,
                        'target_group' => $targetGroup,
                        'status_code' => $response->getStatusCode()
                    ]);
                    return null;
                }
                
                return $response->toArray();
            });
        } catch (ExceptionInterface $e) {
            $this->logger->error('Error fetching reflection questions from Content Service', [
                'sermon_id' => $sermonId,
                'target_group' => $targetGroup,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    public function submitFeedback(array $feedbackData): bool
    {
        try {
            $response = $this->httpClient->request('POST', "{$this->contentServiceUrl}/api/v1/feedback", [
                'json' => $feedbackData
            ]);
            
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $this->logger->info('Feedback submitted successfully', [
                    'feedback_id' => $feedbackData['id'] ?? 'unknown'
                ]);
                return true;
            }
            
            $this->logger->error('Failed to submit feedback', [
                'status_code' => $response->getStatusCode(),
                'feedback_data' => $feedbackData
            ]);
            
            return false;
        } catch (ExceptionInterface $e) {
            $this->logger->error('Error submitting feedback to Content Service', [
                'feedback_data' => $feedbackData,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    public function invalidateChurchCache(int $churchId): void
    {
        $keysToInvalidate = [
            "vector_store_{$churchId}",
            "church_info_{$churchId}",
            "active_sermon_{$churchId}"
        ];
        
        foreach ($keysToInvalidate as $key) {
            $this->cache->deleteItem($key);
        }
        
        $this->logger->info('Church cache invalidated', ['church_id' => $churchId]);
    }

    public function testConnection(): bool
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->contentServiceUrl}/health");
            return $response->getStatusCode() === 200;
        } catch (ExceptionInterface $e) {
            $this->logger->error('Content Service connection test failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}