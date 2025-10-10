<?php

namespace App\Services;

use App\Models\AiProvider;
use App\Models\KnowledgeBase;
use App\Models\Settings;
use OpenAI;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class AiService
{
    protected $provider;
    protected $client;
    protected $trainingService;
    protected $embeddingService;

    public function __construct(TrainingService $trainingService, EmbeddingService $embeddingService)
    {
        $this->trainingService = $trainingService;
        $this->embeddingService = $embeddingService;
        $this->provider = AiProvider::getActive();
        
        if ($this->provider) {
            $this->initializeClient();
        }
    }

    protected function initializeClient(): void
    {
        switch ($this->provider->driver) {
            case 'openai':
                $this->client = OpenAI::client($this->provider->api_key);
                break;
            case 'deepseek':
                // DeepSeek uses OpenAI-compatible API
                $this->client = OpenAI::factory()
                    ->withApiKey($this->provider->api_key)
                    ->withBaseUri($this->provider->base_url ?: 'https://api.deepseek.com')
                    ->make();
                break;
            case 'anthropic':
                // Anthropic needs custom implementation
                $this->client = null; // Will use HTTP client directly
                break;
            case 'custom':
                // For custom OpenAI-compatible APIs
                $this->client = OpenAI::factory()
                    ->withApiKey($this->provider->api_key)
                    ->withBaseUri($this->provider->base_url)
                    ->make();
                break;
        }
    }

    public function chat(array $messages, ?int $maxTokens = 1000): array
    {
        if (!$this->provider) {
            throw new Exception('AI provayder konfiqurasiya edilməyib.');
        }

        // Add knowledge base context if available
        $messages = $this->enhanceWithKnowledge($messages);

        switch ($this->provider->driver) {
            case 'openai':
            case 'deepseek':
            case 'custom':
                return $this->chatWithOpenAICompatible($messages, $maxTokens);
            case 'anthropic':
                return $this->chatWithAnthropic($messages, $maxTokens);
            default:
                throw new Exception('Dəstəklənməyən AI driver: ' . $this->provider->driver);
        }
    }

    protected function chatWithOpenAICompatible(array $messages, int $maxTokens): array
    {
        try {
            // Set timeout for the request
            set_time_limit(120); // 2 minutes timeout
            
            $params = [
                'model' => $this->provider->model ?: 'gpt-3.5-turbo',
                'messages' => $messages,
                'max_tokens' => $maxTokens,
                'temperature' => floatval($this->provider->temperature ?? 0.7),
            ];

            // Add custom parameters if defined
            if ($this->provider->custom_params) {
                $customParams = json_decode($this->provider->custom_params, true);
                if ($customParams) {
                    $params = array_merge($params, $customParams);
                }
            }

            Log::info('Sending AI request', [
                'provider' => $this->provider->name,
                'model' => $params['model'],
                'message_count' => count($messages)
            ]);

            $response = $this->client->chat()->create($params);

            return [
                'content' => $response->choices[0]->message->content,
                'tokens' => $response->usage->total_tokens ?? 0,
            ];
        } catch (Exception $e) {
            Log::error('AI request failed', [
                'provider' => $this->provider->name,
                'error' => $e->getMessage()
            ]);
            throw new Exception('AI sorğusu uğursuz oldu: ' . $e->getMessage());
        }
    }

    protected function chatWithAnthropic(array $messages, int $maxTokens): array
    {
        try {
            // Set timeout for the request
            set_time_limit(120); // 2 minutes timeout
            
            // Convert messages to Anthropic format
            $systemMessage = '';
            $anthropicMessages = [];
            
            foreach ($messages as $msg) {
                if ($msg['role'] === 'system') {
                    $systemMessage = $msg['content'];
                } else {
                    $anthropicMessages[] = [
                        'role' => $msg['role'] === 'user' ? 'user' : 'assistant',
                        'content' => $msg['content']
                    ];
                }
            }
            
            Log::info('Sending Anthropic request', [
                'model' => $this->provider->model ?: 'claude-3-sonnet-20240229',
                'message_count' => count($anthropicMessages)
            ]);

            $response = Http::timeout(90)->withHeaders([
                'x-api-key' => $this->provider->api_key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post($this->provider->base_url ?: 'https://api.anthropic.com/v1/messages', [
                'model' => $this->provider->model ?: 'claude-3-sonnet-20240229',
                'system' => $systemMessage,
                'messages' => $anthropicMessages,
                'max_tokens' => $maxTokens,
            ]);

            if (!$response->successful()) {
                throw new Exception($response->json()['error']['message'] ?? 'Anthropic API error');
            }

            $data = $response->json();
            
            return [
                'content' => $data['content'][0]['text'],
                'tokens' => $data['usage']['input_tokens'] + $data['usage']['output_tokens'],
            ];
        } catch (Exception $e) {
            Log::error('Anthropic request failed', [
                'error' => $e->getMessage()
            ]);
            throw new Exception('Anthropic sorğusu uğursuz oldu: ' . $e->getMessage());
        }
    }

    protected function enhanceWithKnowledge(array $messages): array
    {
        // Check if knowledge base is enabled
        $useKnowledgeBase = (bool) Settings::get('ai_use_knowledge_base', true);
        
        // Get the last user message
        $lastUserMessage = null;
        foreach (array_reverse($messages) as $msg) {
            if ($msg['role'] === 'user') {
                $lastUserMessage = $msg['content'];
                break;
            }
        }

        if (!$lastUserMessage) {
            return $messages;
        }

        Log::info('AI ENHANCEMENT ACTIVATED', [
            'query' => $lastUserMessage,
            'provider' => $this->provider?->name,
            'model' => $this->provider?->model,
            'use_knowledge_base' => $useKnowledgeBase
        ]);

        // Initialize content variables
        $urlContent = '';
        $qaContent = '';
        $generalContent = '';
        
        // Only search knowledge base if enabled
        if ($useKnowledgeBase) {
            // Debug: Database query
            // Safe database stats - check if source_url column exists
            $urlItemsCount = 0;
            try {
                $urlItemsCount = KnowledgeBase::whereNotNull('source_url')->where('source_url', '!=', '')->count();
            } catch (\Exception $e) {
                // source_url column doesn't exist, count by source field containing URLs
                $urlItemsCount = KnowledgeBase::where('source', 'LIKE', 'http%')->count();
            }
            
            Log::info('KNOWLEDGE BASE SEARCH ACTIVE', [
                'user_query' => $lastUserMessage,
                'total_knowledge_items' => KnowledgeBase::count(),
                'active_items' => KnowledgeBase::where('is_active', true)->count(),
                'url_items' => $urlItemsCount,
                'qa_items' => KnowledgeBase::where('category', 'qa')->count()
            ]);
            
            // THREE-TIER PRIORITY SYSTEM with improved search
            $urlContent = $this->getUrlTrainedContent($lastUserMessage);  // PRIORITY 1
            $qaContent = $this->getQATrainedContent($lastUserMessage);    // PRIORITY 2
            $generalContent = $this->getGeneralKnowledgeContent($lastUserMessage); // PRIORITY 3
            
            // Fallback: Try broad search if nothing found
            if (empty($urlContent) && empty($qaContent) && empty($generalContent)) {
                Log::info('NO CONTENT FOUND - Trying broad search');
                $broadContent = $this->getBroadSearchContent($lastUserMessage);
                if (!empty($broadContent)) {
                    $generalContent = $broadContent;
                }
            }
        } else {
            Log::info('KNOWLEDGE BASE DISABLED - Using admin prompt only');
        }
        
        // Build Universal System Prompt with configurable controls
        $universalSystemPrompt = $this->buildAdvancedSystemPrompt($urlContent, $qaContent, $generalContent, $lastUserMessage);

        // If external learning is blocked and there is no KB content at all,
        // force a controlled response telling user that KB has no info.
        $blockExternalLearning = (bool) Settings::get('ai_external_learning_blocked', true);
        $hasAnyKbContent = !empty($urlContent) || !empty($qaContent) || !empty($generalContent);
        if ($blockExternalLearning && !$hasAnyKbContent) {
            $universalSystemPrompt .= "\n\nYALNIZ BU CÜMLƏ İLƏ CAVAB VER VƏ BAŞQA HEÇ NƏ YAZMA: 'Bu mövzu haqqında məlumat bazamda məlumat yoxdur.'";
        }

        // Apply system prompt to messages
        $systemMessageIndex = null;
        foreach ($messages as $index => $msg) {
            if ($msg['role'] === 'system') {
                $systemMessageIndex = $index;
                break;
            }
        }

        if ($systemMessageIndex !== null) {
            $messages[$systemMessageIndex]['content'] = $universalSystemPrompt;
        } else {
            array_unshift($messages, [
                'role' => 'system',
                'content' => $universalSystemPrompt
            ]);
        }

        return $messages;
    }
    
    /**
     * PRIORITY 1: Get URL-based trained content (HIGHEST PRIORITY)
     */
    protected function getUrlTrainedContent(string $query): string
    {
        try {
            // Split query into words for better search
            $words = explode(' ', strtolower($query));
            $words = array_filter($words, function($word) { return strlen($word) > 2; });
            
            Log::info('URL SEARCH DEBUG', [
                'original_query' => $query,
                'search_words' => $words
            ]);
            
            // Safe query - check if source_url column exists
            $urlKnowledge = collect();
            
            try {
                // Try using source_url column if it exists
                $urlKnowledge = KnowledgeBase::where('is_active', true)
                    ->whereNotNull('source_url')
                    ->where('source_url', '!=', '')
                    ->where(function ($q) use ($query, $words) {
                        // Exact phrase search
                        $q->where('title', 'LIKE', "%{$query}%")
                          ->orWhere('content', 'LIKE', "%{$query}%");
                        
                        // Word-by-word search
                        foreach ($words as $word) {
                            $q->orWhere('title', 'LIKE', "%{$word}%")
                              ->orWhere('content', 'LIKE', "%{$word}%");
                        }
                    })
                    ->orderBy('updated_at', 'desc')
                    ->limit(3)
                    ->get();
            } catch (\Exception $e) {
                // Fallback: search by source field containing URLs
                $urlKnowledge = KnowledgeBase::where('is_active', true)
                    ->where(function ($q) {
                        $q->where('source', 'LIKE', 'http%')
                          ->orWhere('source', 'LIKE', 'https%')
                          ->orWhere('source', 'LIKE', '%URL%');
                    })
                    ->where(function ($q) use ($query, $words) {
                        // Exact phrase search
                        $q->where('title', 'LIKE', "%{$query}%")
                          ->orWhere('content', 'LIKE', "%{$query}%");
                        
                        // Word-by-word search
                        foreach ($words as $word) {
                            $q->orWhere('title', 'LIKE', "%{$word}%")
                              ->orWhere('content', 'LIKE', "%{$word}%");
                        }
                    })
                    ->orderBy('updated_at', 'desc')
                    ->limit(3)
                    ->get();
                
                Log::warning('source_url column not found, using source field fallback');
            }
                
            Log::info('URL SEARCH RESULT', [
                'found_items' => $urlKnowledge->count(),
                'titles' => $urlKnowledge->pluck('title')->toArray()
            ]);
                
            if ($urlKnowledge->isEmpty()) {
                return '';
            }
            
            $context = "URL MƏLUMAT MƏNBƏLƏR (ƏN YÜKSƏK PRİORİTET):\n\n";
            foreach ($urlKnowledge as $item) {
                $context .= "BAŞLIQ: {$item->title}\n";
                $context .= "MƏZMUN: {$item->content}\n";
                
                // Safe access to source_url
                try {
                    $sourceUrl = $item->source_url ?? $item->source ?? 'N/A';
                } catch (\Exception $e) {
                    $sourceUrl = $item->source ?? 'N/A';
                }
                $context .= "MƏNBƏ LINK: {$sourceUrl}\n";
                $context .= "KATEQORİYA: {$item->category}\n\n";
            }
            
            // Safe logging
            $urlArray = [];
            try {
                $urlArray = $urlKnowledge->pluck('source_url')->toArray();
            } catch (\Exception $e) {
                $urlArray = $urlKnowledge->pluck('source')->toArray();
            }
            
            Log::info('URL CONTENT PROVIDED (PRIORITY 1)', [
                'items_count' => $urlKnowledge->count(),
                'urls' => $urlArray
            ]);
            
            return $context;
            
        } catch (Exception $e) {
            Log::error('Error getting URL content', ['error' => $e->getMessage()]);
            return '';
        }
    }
    
    /**
     * PRIORITY 2: Get Q&A trained content
     */
    protected function getQATrainedContent(string $query): string
    {
        try {
            // 1) Try semantic best match using embeddings
            $best = $this->findBestQAMatch($query);
            if ($best) {
                $context = "SUAL-CAVAB MƏLUMATLARı (Q&A OVERRIDE):\n\n";
                $context .= "BAŞLIQ: {$best->title}\n";
                $context .= "MƏZMUN: {$best->content}\n";
                $context .= "MƏNBƏ: Q&A Training\n";
                $context .= "KATEQORİYA: {$best->category}\n\n";
                return $context;
            }

            // 2) Fallback to keyword search
            $words = explode(' ', strtolower($query));
            $words = array_filter($words, function($word) { return strlen($word) > 2; });
            $qaKnowledge = KnowledgeBase::where('is_active', true)
                ->where('category', 'qa')
                ->where(function ($subQ) use ($query, $words) {
                    $subQ->where('title', 'LIKE', "%{$query}%")
                         ->orWhere('content', 'LIKE', "%{$query}%");
                    foreach ($words as $word) {
                        $subQ->orWhere('title', 'LIKE', "%{$word}%")
                             ->orWhere('content', 'LIKE', "%{$word}%");
                    }
                })
                ->orderBy('created_at', 'desc')
                ->limit(2)
                ->get();

            if ($qaKnowledge->isEmpty()) { return ''; }

            $context = "SUAL-CAVAB MƏLUMATLARı (2-Cİ PRİORİTET):\n\n";
            foreach ($qaKnowledge as $item) {
                $context .= "BAŞLIQ: {$item->title}\n";
                $context .= "MƏZMUN: {$item->content}\n";
                $context .= "MƏNBƏ: Q&A Training\n";
                $context .= "KATEQORİYA: {$item->category}\n\n";
            }
            return $context;
            
        } catch (Exception $e) {
            Log::error('Error getting Q&A content', ['error' => $e->getMessage()]);
            return '';
        }
    }
    
    /**
     * PRIORITY 3: Get general knowledge content (fallback)
     */
    protected function getGeneralKnowledgeContent(string $query): string
    {
        try {
            // Safe query for general knowledge - avoid source_url if column doesn't exist
            $generalKnowledge = collect();
            
            try {
                // Try with source_url column
                $generalKnowledge = KnowledgeBase::where('is_active', true)
                    ->whereNull('source_url')
                    ->where('category', '!=', 'qa')
                    ->where(function ($q) use ($query) {
                        $q->where('title', 'LIKE', "%{$query}%")
                          ->orWhere('content', 'LIKE', "%{$query}%");
                    })
                    ->orderBy('created_at', 'desc')
                    ->limit(2)
                    ->get();
            } catch (\Exception $e) {
                // Fallback: don't use source_url column
                $generalKnowledge = KnowledgeBase::where('is_active', true)
                    ->where('category', '!=', 'qa')
                    ->where(function ($q) use ($query) {
                        $q->where('title', 'LIKE', "%{$query}%")
                          ->orWhere('content', 'LIKE', "%{$query}%");
                    })
                    ->orderBy('created_at', 'desc')
                    ->limit(2)
                    ->get();
            }
                
            if ($generalKnowledge->isEmpty()) {
                return '';
            }
            
            $context = "ÜMUMI BİLİK BAZASI (3-CÜ PRİORİTET):\n\n";
            foreach ($generalKnowledge as $item) {
                $context .= "BAŞLIQ: {$item->title}\n";
                $context .= "MƏZMUN: {$item->content}\n";
                $context .= "MƏNBƏ: {$item->source}\n";
                $context .= "KATEQORİYA: {$item->category}\n\n";
            }
            
            Log::info('GENERAL KNOWLEDGE PROVIDED (PRIORITY 3)', [
                'items_count' => $generalKnowledge->count()
            ]);
            
            return $context;
            
        } catch (Exception $e) {
            Log::error('Error getting general knowledge', ['error' => $e->getMessage()]);
            return '';
        }
    }
    
    /**
     * Broad search for any content when specific searches fail
     */
    protected function getBroadSearchContent(string $query): string
    {
        try {
            $words = explode(' ', strtolower($query));
            $words = array_filter($words, function($word) { return strlen($word) > 2; });
            
            Log::info('BROAD SEARCH DEBUG', [
                'original_query' => $query,
                'search_words' => $words
            ]);
            
            $broadKnowledge = KnowledgeBase::where('is_active', true)
                ->where(function ($q) use ($query, $words) {
                    $q->where('title', 'LIKE', "%{$query}%")
                      ->orWhere('content', 'LIKE', "%{$query}%");
                    foreach ($words as $word) {
                        $q->orWhere('title', 'LIKE', "%{$word}%")
                          ->orWhere('content', 'LIKE', "%{$word}%");
                    }
                })
                ->orderBy('updated_at', 'desc')
                ->limit(5)
                ->get();
                
            if ($broadKnowledge->isEmpty()) {
                Log::info('BROAD SEARCH: No results found');
                return '';
            }
            
            $context = "GENIŞ AXTARİŞ NƏTİCƏLƏRİ:\n\n";
            foreach ($broadKnowledge as $item) {
                $source = $item->source_url ? "URL: {$item->source_url}" : "Mənbə: {$item->source}";
                $context .= "BAŞLIQ: {$item->title}\n";
                $context .= "MƏZMUN: {$item->content}\n";
                $context .= "MƏNBƏ: {$source}\n";
                $context .= "KATEQORİYA: {$item->category}\n\n";
            }
            
            Log::info('BROAD SEARCH RESULTS', [
                'found_items' => $broadKnowledge->count(),
                'titles' => $broadKnowledge->pluck('title')->toArray()
            ]);
            
            return $context;
            
        } catch (Exception $e) {
            Log::error('Error in broad search', ['error' => $e->getMessage()]);
            return '';
        }
    }
    protected function findBestQAMatch(string $query): ?\App\Models\KnowledgeBase
    {
        try {
            $queryVec = $this->embeddingService->embed($query);
            if (!$queryVec) return null;

            $candidates = \App\Models\KnowledgeBase::where('is_active', true)
                ->where('category', 'qa')
                ->orderBy('updated_at', 'desc')
                ->limit(500)
                ->get(['id','title','content','embedding','updated_at']);

            $bestScore = 0.0; $best = null;
            foreach ($candidates as $item) {
                $vec = $item->embedding ? json_decode($item->embedding, true) : null;
                if (!is_array($vec)) { continue; }
                $score = EmbeddingService::cosine($queryVec, $vec);
                if ($score > $bestScore) { $bestScore = $score; $best = $item; }
            }

            // Use a reasonable threshold (tuneable)
            if ($best && $bestScore >= 0.82) {
                return $best;
            }
            return null;
        } catch (\Throwable $e) {
            \Log::warning('findBestQAMatch failed: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Build advanced system prompt with configurable admin controls
     */
    protected function buildAdvancedSystemPrompt(string $urlContent, string $qaContent, string $generalContent, string $userQuery): string
    {
        // Get admin settings
        $adminPrompt = Settings::get('ai_system_prompt', '');
        $useKnowledgeBase = (bool) Settings::get('ai_use_knowledge_base', true);
        $strictMode = (bool) Settings::get('ai_strict_mode', true);
        $topicRestrictions = Settings::get('ai_topic_restrictions', '');
        $blockInternet = (bool) Settings::get('ai_internet_blocked', true);
        $blockExternalLearning = (bool) Settings::get('ai_external_learning_blocked', true);
        $superStrictMode = (bool) Settings::get('ai_super_strict_mode', false);
        
        // Build configurable prompt
        $prompt = "";
        
        // Basic identity (always present but configurable)
        if ($strictMode) {
            $prompt .= "Sən İslami köməkçi AI assistantsan və dini məsələlərdə yardım edirsən.\n";
        } else {
            $prompt .= "Sən köməkçi AI assistantsan və istifadəçilərə yardım edirsən.\n";
        }
        
        // Knowledge base controls
        if ($useKnowledgeBase && ($urlContent || $qaContent || $generalContent)) {
            if ($blockExternalLearning) {
                $prompt .= "MƏLUMAT MƏNBƏLƏRİ: Yalnız aşağıda verilən məlumatları istifadə et, öz biliklərini deyil.\n";
            } else {
                $prompt .= "MƏLUMAT MƏNBƏLƏRİ: Əsasən aşağıdakı məlumatları istifadə et, lazım gəldikdə ümumi biliklərinlə tamamla.\n";
            }
        } else if (!$useKnowledgeBase) {
            if (!$blockExternalLearning) {
                $prompt .= "Sərbəst cavab ver, ümumi biliklərinə əsaslanaraq yardım et.\n";
            } else {
                $prompt .= "Yalnız admin tərəfindən verilən təlimatları izlə.\n";
            }
        }
        
        // Internet access control
        if ($blockInternet) {
            $prompt .= "İnternet məlumatlarına müraciət etmə, yalnız mövcud məlumatları istifadə et.\n";
        }
        
        // Topic restrictions
        if ($strictMode && !empty($topicRestrictions)) {
            $prompt .= "MÖVZU MƏHDUDİYYƏTLƏRİ:\n";
            $prompt .= $topicRestrictions . "\n";
        }
        
        // Super strict mode
        if ($superStrictMode) {
            $prompt .= "SUPER STRİCT MODE: Təlimatdan kənara MÜTLƏQ çıxma! Yalnız admin təlimatlarını icra et.\n";
        }
        
        $prompt .= "Azərbaycan dilində cavab ver.\n\n";
        
        // Add admin custom prompt
        if (!empty($adminPrompt)) {
            $prompt .= "ADMIN TƏLİMATLARI:\n";
            $prompt .= $adminPrompt . "\n\n";
        }
        
        // Add knowledge content only if enabled
        if ($useKnowledgeBase) {
            $hasContent = false;
            
            // PRIORITY 1: URL Content (Highest Priority)
            if (!empty($urlContent)) {
                $prompt .= "=== PRİORİTET 1: URL MƏLUMAT MƏNBƏLƏRİ ===\n";
                $prompt .= $urlContent . "\n";
                $hasContent = true;
            }
            
            // PRIORITY 2: Q&A Content
            if (!empty($qaContent)) {
                $prompt .= "=== PRİORİTET 2: SUAL-CAVAB MƏLUMATLARI ===\n";
                $prompt .= $qaContent . "\n";
                $hasContent = true;
            }
            
            // PRIORITY 3: General Knowledge (Fallback)
            if (!empty($generalContent)) {
                $prompt .= "=== PRİORİTET 3: ÜMUMI BİLİK BAZASI ===\n";
                $prompt .= $generalContent . "\n";
                $hasContent = true;
            }
            
            // Response guidelines only if using knowledge base
            if ($hasContent) {
                $prompt .= "=== CAVAB VERMƏ QAYDALARI ===\n";
                if ($blockExternalLearning) {
                    $prompt .= "- YALNIZ yuxarıdakı məlumatlara əsaslanaraq cavab ver\n";
                    $prompt .= "- MƏNBƏ HİSSƏSİNDƏ YALNIZ YUXARIDA VERİLƏN MƏTNLƏRƏ İSTİNAD ET; BAŞQA KİTAB, MÜƏLLİF VƏ ÜMUMİ MƏNBƏ ADLARI YAZMA\n";
                } else {
                    $prompt .= "- Əsasən yuxarıdakı məlumatları istifadə et, lazım gəldikdə tamamla\n";
                }
                $prompt .= "- Həmişə mənbəni qeyd et\n";
                if ($strictMode) {
                    $prompt .= "- Dini məsələlərdə ehtiyatlı ol\n";
                }
                $prompt .= "- Səliqəli və anlaşılan şəkildə yaz\n\n";
            } else if ($blockExternalLearning) {
                $prompt .= "Bu mövzu haqqında məlumat bazamda məlumat yoxdur.\n\n";
                $prompt .= "YUXARIDAKI CÜMLƏ İLƏ CAVAB VER VƏ BAŞQA HEÇ NƏ YAZMA.\n\n";
            }
        }
        
        Log::info('CONFIGURABLE PROMPT BUILT', [
            'use_knowledge_base' => $useKnowledgeBase,
            'strict_mode' => $strictMode,
            'block_internet' => $blockInternet,
            'block_external_learning' => $blockExternalLearning,
            'super_strict_mode' => $superStrictMode,
            'has_url_content' => !empty($urlContent),
            'has_qa_content' => !empty($qaContent),
            'has_general_content' => !empty($generalContent),
            'has_admin_prompt' => !empty($adminPrompt),
            'total_prompt_length' => strlen($prompt)
        ]);
        
        return $prompt;
    }
    
    /**
     * Build knowledge context from search results
     */
    protected function buildKnowledgeContext($knowledgeItems): string
    {
        $context = "";
        
        foreach ($knowledgeItems as $item) {
            $context .= "TITLE: {$item->title}\n";
            $context .= "CONTENT: {$item->content}\n";
            $context .= "SOURCE: {$item->source}\n";
            $context .= "CATEGORY: {$item->category}\n\n";
        }
        
        return $context;
    }

    /**
     * Get formatted chatbot response
     */
    public function getChatbotResponse(string $message, array $conversation = []): string
    {
        try {
            // Build conversation messages
            $messages = [];
            
            // Add conversation history
            foreach ($conversation as $msg) {
                $messages[] = [
                    'role' => $msg['role'] ?? 'user',
                    'content' => $msg['content']
                ];
            }
            
            // Add current user message
            $messages[] = [
                'role' => 'user',
                'content' => $message
            ];
            
            // Get AI response
            $response = $this->chat($messages);
            $rawContent = $response['content'] ?? '';
            
            // Format the response
            $formattedResponse = $this->formatChatbotResponse($rawContent);
            
            Log::info('Chatbot response generated', [
                'user_message' => $message,
                'raw_length' => strlen($rawContent),
                'formatted_length' => strlen($formattedResponse)
            ]);
            
            return $formattedResponse;
            
        } catch (Exception $e) {
            Log::error('Chatbot response error', [
                'message' => $message,
                'error' => $e->getMessage()
            ]);
            
            return 'Üzr istəyirəm, hazırda texniki problem var. Zəhmət olmasa bir az sonra yenidən cəhd edin.';
        }
    }
    
    /**
     * Format chatbot response for better presentation
     */
    protected function formatChatbotResponse(string $rawResponse): string
    {
        if (empty($rawResponse)) {
            return 'Bu mövzu haqqında əzbərlədiyim məlumat yoxdur.';
        }
        
        // Clean excessive whitespace and line breaks
        $response = $this->cleanWhitespace($rawResponse);
        
        // Format source references
        $response = $this->formatSourceReferences($response);
        
        // Add proper formatting for headings and emphasis
        $response = $this->addTextFormatting($response);
        
        return $response;
    }
    
    /**
     * Clean excessive whitespace and line breaks
     */
    protected function cleanWhitespace(string $text): string
    {
        // Remove excessive line breaks (more than 2 consecutive)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        
        // Remove excessive spaces
        $text = preg_replace('/[ \t]{2,}/', ' ', $text);
        
        // Clean up spaces around line breaks
        $text = preg_replace('/[ \t]*\n[ \t]*/', "\n", $text);
        
        // Ensure proper spacing after periods
        $text = preg_replace('/\.([A-ZÇƏĞIİÖŞÜ])/', '. $1', $text);
        
        return trim($text);
    }
    
    /**
     * Format source references according to requirements
     */
    protected function formatSourceReferences(string $text): string
    {
        $blockExternal = (bool) Settings::get('ai_external_learning_blocked', true);

        // Normalize Q&A markers
        $text = preg_replace(
            '/Mənbə:\s*Q&A Training[^\n]*/',
            '**Mənbə:** S&C Təlimat Bazası',
            $text
        );
        
        // URL sources -> keep, format nicely
        $text = preg_replace(
            '/Mənbə:\s*(https?:\/\/[^\s\n]+)/',
            '**Mənbə:** $1',
            $text
        );

        if ($blockExternal) {
            // If external learning is blocked, prevent generic named sources.
            // Replace any non-URL sources with unified label.
            $text = preg_replace_callback(
                '/Mənbə:\s*([^\n]+?)(?=\n|$)/',
                function($m) {
                    if (preg_match('/https?:\/\//', $m[1])) return $m[0];
                    return '**Mənbə:** S&C Təlimat Bazası';
                },
                $text
            );
            // Remove common bullet lists of book names under a heading "Mənbələr" if present
            $text = preg_replace('/(?mi)^\s*(Mənbələr|Əsas mənbələr|Rəvayət mənbələri):?\s*(\n\s*[-•].*)+/u', '**Mənbə:** S&C Təlimat Bazası', $text);
        } else {
            // For non-blocked mode, ensure generic sources at least are formatted
            $text = preg_replace(
                '/(?<!\*)Mənbə:\s*([^\n]+?)(?=\n|$)/',
                '**Mənbə:** $1',
                $text
            );
        }
        
        return $text;
    }
    
    /**
     * Add proper text formatting (bold, italic)
     */
    protected function addTextFormatting(string $text): string
    {
        // Format important Islamic terms with bold
        $islamicTerms = [
            'dəstəmaz', 'namaz', 'oruc', 'hac', 'zəkat',
            'qiblə', 'imam', 'ayə', 'hadis', 'sünnet',
            'fərz', 'vacib', 'məkruh', 'haram', 'halal',
            'Allah', 'Peyğəmbər', 'İslam', 'Quran'
        ];
        
        foreach ($islamicTerms as $term) {
            // Make terms bold (case insensitive)
            $text = preg_replace(
                '/\b(' . preg_quote($term) . ')\b/iu',
                '**$1**',
                $text
            );
        }
        
        // Format numbered lists
        $text = preg_replace('/^(\d+[.)]) /m', '**$1** ', $text);
        
        // Format bullet points
        $text = preg_replace('/^[•\-\*] /m', '• ', $text);
        
        // Ensure proper paragraph spacing
        $text = preg_replace('/\n([A-ZÇƏĞIİÖŞÜ])/', "\n\n$1", $text);
        
        return $text;
    }

    public function testConnection(): bool
    {
        try {
            $this->chat([
                ['role' => 'user', 'content' => 'Salam']
            ], 10);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

