<?php

namespace App\Services;

use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;
use DOMDocument;
use DOMXPath;

/**
 * Advanced Training Service - Train nümunələrinə əsasən
 * Bu xidmət URL-lərdən məzmunu mükəmməl şəkildə əldə edir və əzbərləyir
 */
class TrainingService
{
    protected EmbeddingService $embedding;
    protected ?AiService $aiService = null;

    public function __construct(EmbeddingService $embedding, ?AiService $aiService = null)
    {
        $this->embedding = $embedding;
        $this->aiService = $aiService;
    }
    /**
     * URL-dən məzmunu train et və bilik bazasına əlavə et
     */
    public function trainFromUrl(string $url, array $options = [], ?callable $progress = null): array
    {
        try {
            Log::info('🚀 Advanced Training başlanır', [
                'url' => $url,
                'options' => $options
            ]);

            // URL-ə single page ya çoxlu səhifə training
            $single = $options['single'] ?? true;
            $maxDepth = $single ? 1 : ($options['max_depth'] ?? 3);
            
            $results = [];
            
            if ($single) {
                // Tək səhifə training
                $result = $this->trainSinglePage($url, $options);
                if ($result) {
                    $results[] = $result;
                    if ($progress) { $progress(100); }
                }
            } else {
                // Çoxlu səhifə training (saytı tamamilə əzbərlə)
                $results = $this->trainMultiplePages($url, $maxDepth, $options, $progress);
            }
            
            Log::info('✅ Advanced Training tamamlandı', [
                'url' => $url,
                'trained_pages' => count($results)
            ]);
            
            return [
                'success' => true,
                'trained_pages' => count($results),
                'results' => $results
            ];
            
        } catch (Exception $e) {
            Log::error('❌ Training xətası', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Tək səhifə training
     */
    protected function trainSinglePage(string $url, array $options = []): ?KnowledgeBase
    {
        try {
            // 1. URL-dən məzmunu əldə et
            $rawContent = $this->fetchContent($url);
            if (!$rawContent) {
                throw new Exception('URL-dən məzmun əldə edilə bilmədi');
            }
            
            // 2. Məzmunu analiz et və təmizlə
            $processedData = $this->processContent($rawContent, $url);

            // 2.5. Səviyyəyə əsasən xülasə (max_depth parametrlə uyğunlaşdırılıb)
            $level = (int)($options['max_depth'] ?? 5);
            $originalLength = strlen($processedData['content']);
            if ($level < 5) {
                $processedData['content'] = $this->summarizeByLevel($processedData['content'], $level);
                Log::info('📋 Səviyyəyə görə xülasələşdirildi', [
                    'url' => $url,
                    'level' => $level,
                    'original_length' => $originalLength,
                    'summarized_length' => strlen($processedData['content']),
                    'reduction_percent' => round((1 - strlen($processedData['content']) / $originalLength) * 100)
                ]);
            }
            
            // 3. Minimum məzmun yoxla
            if (strlen($processedData['content']) < 50) {
                throw new Exception('Məzmun çox qısadır, əzbərləmək üçün uyğun deyil');
            }
            
            // 4. Mövcud məzmunu yoxla (dublikat qarşısını al)
            $existing = KnowledgeBase::where('source_url', $url)->first();
            
            // Dublikat məntiqi:
            // - Tək səhifə → Tək səhifə: Qadagan (artiq var)
            // - Tək səhifə → Bütün sayt: Icazə (yeniləsin)
            // - Bütün sayt → Tək səhifə: Icazə (yeniləsin)
            // - Bütün sayt → Bütün sayt: Icazə (yeniləsin)
            
            $isSinglePageMode = $options['single'] ?? true;
            
            if ($existing) {
                // Check if previous was also single page mode
                $wasSinglePage = !isset($existing->metadata['training_mode']) || $existing->metadata['training_mode'] === 'single';
                
                // Block only if: was single AND current is also single
                if ($wasSinglePage && $isSinglePageMode) {
                    Log::warning('⚠️ Tək səhifə artıq əzbərlənib - dublikat qadagandır', ['url' => $url]);
                    throw new Exception('Bu URL artıq tək səhifə olaraq əzbərlənib. Bütün sayt rejimini seçmək istəyirsinizsə, "Bütün sayt" seçimi ilə yeniləyin.');
                }
                
                // Update in all other cases
                Log::info('📝 Mövcud məzmun yenilənir', [
                    'url' => $url,
                    'was_single' => $wasSinglePage,
                    'is_single' => $isSinglePageMode
                ]);
                return $this->updateKnowledge($existing, $processedData, $options);
            } else {
                Log::info('🆕 Yeni məzmun əlavə edilir', ['url' => $url]);
                return $this->createKnowledge($processedData, $url, $options);
            }
            
        } catch (Exception $e) {
            Log::error('Single page training xətası', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Çoxlu səhifə training (dərin crawling)
     */
    protected function trainMultiplePages(string $baseUrl, int $maxDepth, array $options = [], ?callable $progress = null): array
    {
        $results = [];
        $processed = [];
        $queue = [['url' => $baseUrl, 'depth' => 0]];
        $maxPages = $options['max_pages'] ?? 2000; // Artırıldı
        $discovered = 1;
        
        // Scope restriction: only crawl within the provided scope URL path
        $scopeUrl = $options['scope_url'] ?? $baseUrl;
        $scopeParts = parse_url($scopeUrl);
        $scopeScheme = $scopeParts['scheme'] ?? '';
        $scopeHost = $scopeParts['host'] ?? '';
        $scopePath = rtrim($scopeParts['path'] ?? '/', '/');
        
        $shouldStop = $options['shouldStop'] ?? null;
        
        Log::info('🌐 Çoxlu səhifə training başlanır', [
            'base_url' => $baseUrl,
            'max_depth' => $maxDepth,
            'max_pages' => $maxPages,
            'scope_host' => $scopeHost,
            'scope_path' => $scopePath
        ]);
        
        while (!empty($queue) && count($results) < $maxPages) {
            $current = array_shift($queue);
            $url = $current['url'];
            $depth = $current['depth'];
            
            // Artıq işlənmişləri keç
            if (in_array($url, $processed)) {
                continue;
            }
            
            $processed[] = $url;
            
            try {
                // Stop requested? - Hər addimda yoxla
                if (is_callable($shouldStop) && $shouldStop()) {
                    Log::info('⏹️ Training user tərəfindən dayandırıldı', ['processed_count' => count($processed)]);
                    // Progress 100% et ki frontend anlasın
                    if ($progress) { $progress(100); }
                    break;
                }
                
                Log::info('📖 Səhifə training edilir', [
                    'url' => $url,
                    'depth' => $depth,
                    'processed_count' => count($processed),
                    'results_count' => count($results)
                ]);
                
                // Bu səhifəni train et - MÖHKƏMməli FULL SITE modunda
                $pageOptions = array_merge($options, [
                    'single' => false,  // ƏSAS DÜZƏLİŞ: Bu full site training-dir!
                    'is_multi_page_context' => true, // Əlavə flag
                    'parent_training_mode' => 'full_site',
                    'shouldStop' => $shouldStop // Stop callback-ni ötür
                ]);
                
                // Progress - Başlamağdan əvvəl
                if ($progress) {
                    $processedCount = count($processed);
                    $totalDiscovered = max($discovered, $processedCount + count($queue));
                    $beforePercent = (int) floor(($processedCount / max($totalDiscovered, 1)) * 100);
                    $progress(min(95, max(1, $beforePercent))); // Max 95% təyin et
                }
                
                $result = $this->trainSinglePageForMultiSite($url, $pageOptions);
                if ($result) {
                    $results[] = $result;
                    Log::info('✅ Səhifə uğurla əlavə edildi', [
                        'url' => $url, 
                        'title' => $result->title,
                        'content_length' => strlen($result->content),
                        'total_results_so_far' => count($results)
                    ]);
                } else {
                    Log::warning('⚠️ Səhifə əlavə edilə bilmədi', [
                        'url' => $url,
                        'processed_count' => count($processed),
                        'results_count' => count($results),
                        'queue_size' => count($queue)
                    ]);
                }
                
                // Progress - Tamamlandıqdan sonra
                if ($progress) {
                    $processedCount = count($processed);
                    $successCount = count($results);
                    $totalDiscovered = max($discovered, $processedCount + count($queue));
                    $percent = (int) floor(($processedCount / max($totalDiscovered, 1)) * 100);
                    $percent = min(95, max(2, $percent));
                    $progress($percent);
                    
                    Log::info('📈 Progress update', [
                        'processed' => $processedCount,
                        'results' => $successCount,
                        'queue_size' => count($queue),
                        'total_discovered' => $totalDiscovered,
                        'percent' => $percent
                    ]);
                }
                
                // Daha dərin get
                if ($depth < $maxDepth) {
                    $links = $this->extractLinks($url, $baseUrl);
                    Log::info('🔗 Linklər tapıldı', [
                        'url' => $url, 
                        'links_count' => count($links),
                        'depth' => $depth,
                        'max_depth' => $maxDepth,
                        'sample_links' => array_slice($links, 0, 5)
                    ]);
                    
                    // Filter links to stay within scope - Geniş scope
                    $filtered = [];
                    $rejected = [];
                    foreach ($links as $link) {
                        if ($this->isLinkInScopeForFullSite($link, $scopeScheme, $scopeHost, $scopePath) && !in_array($link, $processed)) {
                            $filtered[] = $link;
                        } else {
                            $rejected[] = $link;
                        }
                    }
                    
                    Log::info('🔄 Link filtering nəticələri', [
                        'total_links' => count($links),
                        'filtered_count' => count($filtered),
                        'rejected_count' => count($rejected),
                        'sample_filtered' => array_slice($filtered, 0, 3),
                        'sample_rejected' => array_slice($rejected, 0, 3)
                    ]);
                    
                    foreach ($filtered as $link) {
                        $queue[] = ['url' => $link, 'depth' => $depth + 1];
                        $discovered++;
                    }
                }
                
                // Server-ə hörmət et
                usleep(500000); // 0.5 saniyə gözlə
                
            } catch (Exception $e) {
                Log::warning('Səhifə training xətası', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'depth' => $depth
                ]);
                continue;
            }
        }
        
        if ($progress) { $progress(100); }
        
        Log::info('🎯 Çoxlu səhifə training tamamlandı', [
            'total_results' => count($results),
            'processed_urls' => count($processed)
        ]);
        
        return $results;
    }
    
    /**
     * Check if link is within the allowed scope
     */
    protected function isLinkInScope(string $link, string $scopeScheme, string $scopeHost, string $scopePath): bool
    {
        $parts = parse_url($link);
        if (!$parts) return false;
        
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';
        $path = rtrim($path, '/');
        
        // Same host only
        if (strcasecmp($host, $scopeHost) !== 0) return false;
        
        // Same scheme if provided
        if ($scopeScheme && strcasecmp($scheme, $scopeScheme) !== 0) return false;
        
        // Only allow paths within the base scope path
        if ($scopePath === '' || $scopePath === '/') return true; // base root
        if (strpos($path . '/', $scopePath . '/') !== 0) return false; // must start with scopePath
        
        return true;
    }
    
    /**
     * Full site üçün daha geniş link scope - bütün sayt üçün
     */
    protected function isLinkInScopeForFullSite(string $link, string $scopeScheme, string $scopeHost, string $scopePath): bool
    {
        $parts = parse_url($link);
        if (!$parts) return false;
        
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';
        
        // Same host only (əsas məhdudiyyət)
        if (strcasecmp($host, $scopeHost) !== 0) return false;
        
        // Same scheme if provided
        if ($scopeScheme && strcasecmp($scheme, $scopeScheme) !== 0) return false;
        
        // İstənilməyən fayl tipləri
        $unwantedExtensions = ['.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', '.zip', '.rar', '.exe', '.mp3', '.mp4', '.avi', '.jpg', '.jpeg', '.png', '.gif', '.css', '.js', '.json', '.xml'];
        foreach ($unwantedExtensions as $ext) {
            if (substr(strtolower($path), -strlen($ext)) === $ext) {
                return false;
            }
        }
        
        // İstənilməyən path-lar
        $unwantedPaths = ['/admin', '/wp-admin', '/wp-content', '/assets', '/images', '/js', '/css', '/fonts', '/media', '/uploads', '/download'];
        foreach ($unwantedPaths as $unwanted) {
            if (strpos(strtolower($path), strtolower($unwanted)) !== false) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Çoxlu səhifə training üçün xüsusi single page handler
     */
    protected function trainSinglePageForMultiSite(string $url, array $options = []): ?KnowledgeBase
    {
        try {
            // Stop check - ilk öncə yoxla
            $shouldStop = $options['shouldStop'] ?? null;
            if (is_callable($shouldStop) && $shouldStop()) {
                Log::info('⏹️ Stop request - səhifə training atlanıldı', ['url' => $url]);
                return null;
            }
            
            Log::info('🔄 Multi-site context-də səhifə training', [
                'url' => $url,
                'is_multi_page_context' => $options['is_multi_page_context'] ?? false
            ]);
            
            // 1. URL-dən məzmunu əldə et
            $rawContent = $this->fetchContent($url);
            if (!$rawContent) {
                Log::error('❌ URL-dən məzmun əldə edilə bilmədi - səhifə atlanır', [
                    'url' => $url,
                    'curl_available' => function_exists('curl_init'),
                    'file_get_contents_available' => ini_get('allow_url_fopen'),
                    'guzzle_available' => class_exists('GuzzleHttp\\Client')
                ]);
                return null;
            }
            
            Log::info('✅ URL-dən məzmun əldə edildi', [
                'url' => $url,
                'content_size' => strlen($rawContent),
                'content_preview' => mb_substr(strip_tags($rawContent), 0, 150)
            ]);
            
            // 2. Məzmunu analiz et və təmizlə
            $processedData = $this->processContent($rawContent, $url);

            // 2.5. Səviyyəyə əsasən xülasə (max_depth parametrlə uyğunlaşdırılıb)
            $level = (int)($options['max_depth'] ?? 5);
            $originalLength = strlen($processedData['content']);
            if ($level < 5) {
                $processedData['content'] = $this->summarizeByLevel($processedData['content'], $level);
                Log::info('📋 Multi-site: Səviyyəyə görə xülasələşdirildi', [
                    'url' => $url,
                    'level' => $level,
                    'original_length' => $originalLength,
                    'summarized_length' => strlen($processedData['content']),
                    'reduction_percent' => round((1 - strlen($processedData['content']) / $originalLength) * 100)
                ]);
            }
            
            // 3. Minimum məzmun yoxla
            if (strlen($processedData['content']) < 50) {
                Log::warning('⚠️ Məzmun çox qısadır - səhifə atlanır', [
                    'url' => $url, 
                    'content_length' => strlen($processedData['content']),
                    'content_preview' => mb_substr($processedData['content'], 0, 200),
                    'title' => $processedData['title'] ?? 'N/A'
                ]);
                return null;
            }
            
            // 4. Full site training üçün FƏRQLI dublikat məntiq
            $existing = KnowledgeBase::where('source_url', $url)->first();
            
            if ($existing) {
                // Full site training zamanı mövcud səhifələri yenilə
                Log::info('🔄 Full site: mövcud məzmun yenilənir', ['url' => $url]);
                return $this->updateKnowledgeForFullSite($existing, $processedData, $options);
            } else {
                // Yeni məzmun əlavə et
                Log::info('🆕 Full site: yeni məzmun əlavə edilir', ['url' => $url]);
                return $this->createKnowledgeForFullSite($processedData, $url, $options);
            }
            
        } catch (Exception $e) {
            Log::error('❌ Multi-site single page training xətası', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * URL-dən məzmunu güclü metodlarla əldə et
     */
    protected function fetchContent(string $url): ?string
    {
        // 1. cURL ilə cəhd et (ən güclü)
        if (function_exists('curl_init')) {
            $content = $this->fetchWithCurl($url);
            if ($content) return $content;
        }
        
        // 2. file_get_contents ilə cəhd et
        $content = $this->fetchWithFileGetContents($url);
        if ($content) return $content;
        
        // 3. Guzzle ilə cəhd et (əgər mövcuddursa)
        if (class_exists('GuzzleHttp\Client')) {
            $content = $this->fetchWithGuzzle($url);
            if ($content) return $content;
        }
        
        return null;
    }
    
    /**
     * cURL ilə məzmun əldə et
     */
    protected function fetchWithCurl(string $url): ?string
    {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => 120, // Çox artırıldı hosting üçün
                CURLOPT_CONNECTTIMEOUT => 60, // Çox artırıldı
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_ENCODING => '', // Avtomatik gzip/deflate dekoding
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; XIV-AI-Bot/1.0; +https://example.com/bot)',
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language: az,tr,en-US,en;q=0.5',
                    'Accept-Encoding: gzip, deflate, br',
                    'Accept-Charset: UTF-8,Windows-1254,ISO-8859-9,CP1254;q=0.7,*;q=0.7',
                    'DNT: 1',
                    'Connection: keep-alive',
                    'Upgrade-Insecure-Requests: 1',
                    'Cache-Control: no-cache'
                ],
                // Hosting üçün əlavə seçimlər
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => false,
                CURLOPT_VERBOSE => false
            ]);
            
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            
            if ($content && $httpCode >= 200 && $httpCode < 400 && empty($error)) {
                Log::info('✅ cURL ilə məzmun əldə edildi', [
                    'url' => $url,
                    'http_code' => $httpCode,
                    'content_type' => $contentType,
                    'content_length' => strlen($content),
                    'content_preview' => substr(strip_tags($content), 0, 200)
                ]);
                return $content;
            }
            
            Log::warning('⚠️ cURL xətası', [
                'url' => $url,
                'http_code' => $httpCode,
                'error' => $error,
                'curl_info' => [
                    'total_time' => curl_getinfo($ch, CURLINFO_TOTAL_TIME),
                    'connect_time' => curl_getinfo($ch, CURLINFO_CONNECT_TIME),
                    'effective_url' => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)
                ]
            ]);
            
        } catch (Exception $e) {
            Log::warning('cURL exception', ['url' => $url, 'error' => $e->getMessage()]);
        }
        
        return null;
    }
    
    /**
     * file_get_contents ilə məzmun əldə et
     */
    protected function fetchWithFileGetContents(string $url): ?string
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", [
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language: az,en-US,en;q=0.5',
                        'Connection: close'
                    ]),
                'timeout' => 120, // Hosting üçün artırıldı
                'ignore_errors' => true
                ]
            ]);
            
            $content = file_get_contents($url, false, $context);
            
            if ($content) {
                Log::info('✅ file_get_contents ilə məzmun əldə edildi', [
                    'url' => $url,
                    'content_length' => strlen($content)
                ]);
                return $content;
            }
            
        } catch (Exception $e) {
            Log::warning('file_get_contents exception', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }
    
    /**
     * Guzzle ilə məzmun əldə et
     */
    protected function fetchWithGuzzle(string $url): ?string
    {
        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'connect_timeout' => 10,
                'verify' => false
            ]);
            
            $response = $client->get($url, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);
            
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 400) {
                $content = $response->getBody()->getContents();
                Log::info('✅ Guzzle ilə məzmun əldə edildi', [
                    'url' => $url,
                    'status' => $response->getStatusCode(),
                    'content_length' => strlen($content)
                ]);
                return $content;
            }
            
        } catch (Exception $e) {
            Log::warning('Guzzle exception', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }
    
    /**
     * Məzmunu analiz et və təmizlə
     */
    protected function processContent(string $rawContent, string $url): array
    {
        // 1. Encoding problemi həll et
        $content = $this->fixEncoding($rawContent);
        
        // 2. HTML-i təmizlə və mətn çıxar
        $cleanContent = $this->extractCleanText($content);
        
        // 2.5. Təmizlənmə prosesindən sonra yenidən UTF-8 təmizliği
        $cleanContent = $this->ensureValidUTF8($cleanContent);
        
        // 3. Başlıq tap
        $title = $this->extractTitle($content, $url);
        
        // 4. Meta məlumatları çıxar
        $metadata = $this->extractMetadata($content, $url);
        
        return [
            'title' => $title,
            'content' => $cleanContent,
            'metadata' => $metadata,
            'url' => $url
        ];
    }
    
    /**
     * Encoding problemlərini həll et - Azərbaycan hərfləri üçün təkmilləşdirilmiş
     */
    protected function fixEncoding(string $content): string
    {
        // 1. İlk öncə content-in başında olan encoding məlumatlarını yoxla
        if (preg_match('/<meta[^>]+charset=["\']?([^"\'>\s]+)["\']?/i', $content, $matches)) {
            $htmlCharset = strtoupper($matches[1]);
            Log::info('HTML charset təyin edildi', ['charset' => $htmlCharset]);
        }
        
        // 2. Geniş encoding siyahısı - Azərbaycan dili üçün uyğunlaşdırılmış
        $encodings = [
            'UTF-8', 'Windows-1254', 'ISO-8859-9', 'CP1254', 'Windows-1252', 'ISO-8859-1', 'ASCII'
        ];
        
        $detectedEncoding = mb_detect_encoding($content, $encodings, true);
        $isUTF8Valid = mb_check_encoding($content, 'UTF-8');
        
        // 2.5. Azərbaycan hərflərinin mövcudluğunu yoxla
        $hasAzerbaijaniChars = preg_match('/[əçğıöşüÇĞIÖŞÜƏ]/u', $content);
        $hasCorruptedChars = preg_match('/[\xC0-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF7][\x80-\xBF]{3}/', $content);
        
        Log::info('🔤 Encoding analizi', [
            'detected_encoding' => $detectedEncoding,
            'content_length' => strlen($content),
            'is_utf8_valid' => $isUTF8Valid,
            'html_charset' => isset($htmlCharset) ? $htmlCharset : 'none',
            'has_azerbaijani_chars' => $hasAzerbaijaniChars,
            'has_corrupted_chars' => $hasCorruptedChars
        ]);
        
        // 3. Azərbaycan hərfləri üçün xüsusi mojibake düzəldişi 
        if ($hasCorruptedChars || preg_match('/(Ãı|Ã¶|Ã§|Ã¼|Äə|Äğ|Äı|Şə|Ã|Ã)/u', $content)) {
            Log::info('🇦🇿 Azərbaycan hərflərində mojibake anar edildi');
            
            // Azərbaycan hərfləri üçün düzəltmə cədvəli
            $azerbaijaniFixMap = [
                // Çox rəst gələn mojibake nümunələri
                'Ã¶' => 'ö',     // ö
                'Ã§' => 'ç',     // ç
                'Ã¼' => 'ü',     // ü
                'Ã±' => 'ı',     // ı
                'Ãı' => 'ı',     // ı alternative
                'Äə' => 'ə',     // ə
                'Äğ' => 'ğ',     // ğ
                'Äı' => 'ı',     // ı
                'ÅŸ' => 'ş',     // ş
                'Å\x9F' => 'ş',  // ş alternative
                'Ã\x87' => 'Ç',  // Ç
                'Ã\x96' => 'Ö',  // Ö
                'Ã\x9C' => 'Ü',  // Ü
                'Ä\x9E' => 'Ğ',  // Ğ
                'Ä\x9F' => 'ğ',  // ğ
                'Ä±' => 'ı',     // ı
                'yazÄ±lmÄ±' => 'yazılmı',  // common pattern fix
                'ÅŸdÄ±r' => 'şdır',        // common pattern fix  
                'lÄ±' => 'lı',            // common pattern fix
                'Ä±x' => 'ıx',            // common pattern fix
                'ÄžÄ±' => 'ğı',           // common pattern fix
                'hÃ¤rfl' => 'hərfl',      // common pattern fix
                'Ã¤ri' => 'əri',          // common pattern fix
                'lÃ¤z' => 'ləz',          // common pattern fix
                'Ã¤' => 'ə',             // ə alternative
                'Ãœ' => 'Ü',             // Ü
                'mÃ¶tin' => 'mətin',     // specific word fix
                'gÃ¼zÃ¼l' => 'gözəl',     // specific word fix
                'dÃ¼zgÃ¼n' => 'düzgün',   // specific word fix
                // Kvadrat qutu simvolları
                '�' => '',  // replacement character-i sil
                '□' => '',  // white square-i sil
                '■' => '',  // black square-i sil
                '\xEF\xBF\xBD' => '',  // UTF-8 replacement sequence
            ];
            
            $fixed = str_replace(array_keys($azerbaijaniFixMap), array_values($azerbaijaniFixMap), $content);
            
            // Nəticəni yoxla
            $scoreBefore = preg_match_all('/[əçğıöşüÇĞIÖŞÜƏ]/u', $content, $m1);
            $scoreAfter  = preg_match_all('/[əçğıöşüÇĞIÖŞÜƏ]/u', $fixed, $m2);
            
            if ($scoreAfter > $scoreBefore || $hasCorruptedChars) {
                Log::info('✅ Azərbaycan mojibake düzəldildi', [
                    'azerbaijani_chars_before' => $scoreBefore,
                    'azerbaijani_chars_after' => $scoreAfter
                ]);
                $content = $fixed;
            }
        }
        
        // 3a. UTF-8 görünsə də "mojibake" varsa düzəlt
        if ($isUTF8Valid && ($detectedEncoding === 'UTF-8' || !$detectedEncoding)) {
            // Tipik yanlış deşifrə edilmiş UTF-8 nümunələri: Ã, Å, Ä, Â, É, Åş, Äı, Ã¶, Ã§, Ãü, Ä, É™
            if (preg_match('/(Ã|Å|Ä|Â|É|Åş|Äı|Ã¶|Ã§|Ãü|Ä)/u', $content)) {
                $fixed = @iconv('Windows-1252', 'UTF-8//IGNORE', utf8_decode($content));
                if ($fixed !== false && mb_check_encoding($fixed, 'UTF-8')) {
                    // Heuristika: düzəldikdən sonra daha çox az/türk hərfi görünürsə qəbul et
                    $scoreBefore = preg_match_all('/[şğıöçüİıƏə]/u', $content, $m1);
                    $scoreAfter  = preg_match_all('/[şğıöçüİıƏə]/u', $fixed, $m2);
                    if ($scoreAfter >= $scoreBefore) {
                        Log::info('✅ Mojibake düzəldildi (utf8_decode+iconv)');
                        return $fixed;
                    }
                }
            }
            // Mojibake yoxdursa, mövcud mətni saxla
            return $content;
        }
        
        // 4. Müəyyən encoding-dən çevir
        if ($detectedEncoding && $detectedEncoding !== 'UTF-8') {
            $converted = mb_convert_encoding($content, 'UTF-8', $detectedEncoding);
            if (mb_check_encoding($converted, 'UTF-8')) {
                Log::info('✅ Encoding çevrildi', ['from' => $detectedEncoding, 'to' => 'UTF-8']);
                return $converted;
            }
        }
        
        // 5. Türk dili üçün xüsusi çevrimi (əsas problem burada ola bilər)
        $turkishEncodings = ['Windows-1254', 'ISO-8859-9', 'CP1254'];
        foreach ($turkishEncodings as $encoding) {
            try {
                $testContent = mb_convert_encoding($content, 'UTF-8', $encoding);
                
                // Azərbaycan və türk hərflərinə bax
                if (preg_match('/[çğıöşüÇĞIÖŞÜ]/u', $testContent) || 
                    preg_match('/[əÇĞIÖŞÜöşüğç]/u', $testContent)) {
                    Log::info('✅ Türk dili encoding tapıldı', ['encoding' => $encoding]);
                    return $testContent;
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        // 6. İconv ilə son cəhd
        if (function_exists('iconv')) {
            foreach (['Windows-1254', 'ISO-8859-9', 'Windows-1252'] as $fromEncoding) {
                $converted = @iconv($fromEncoding, 'UTF-8//IGNORE', $content);
                if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                    Log::info('✅ iconv ilə çevrildi', ['from' => $fromEncoding]);
                    return $converted;
                }
            }
        }
        
        // 7. Son ehtiyat - bütün səhv byte-ları təmizlə
        $cleaned = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        Log::warning('⚠️ Encoding temizləndi ama mükəmməl olmaya bilər');
        return $cleaned;
    }
    
    /**
     * HTML-dən təmiz mətn çıxar
     */
    protected function extractCleanText(string $html): string
    {
        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            
            // Xətaları söndür
            $oldErrorReporting = libxml_use_internal_errors(true);
            
            // UTF-8 meta əlavə et
            $htmlWithMeta = '<meta charset="UTF-8">' . $html;
            $dom->loadHTML($htmlWithMeta, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            
            // İstənilməyən elementləri sil
            $unwantedTags = ['script', 'style', 'nav', 'header', 'footer', 'aside', 'iframe', 'noscript'];
            foreach ($unwantedTags as $tag) {
                $elements = $dom->getElementsByTagName($tag);
                $toRemove = iterator_to_array($elements);
                foreach ($toRemove as $element) {
                    if ($element->parentNode) {
                        $element->parentNode->removeChild($element);
                    }
                }
            }
            
            // İstənilməyən class və id-ləri sil
            $xpath = new DOMXPath($dom);
            $unwantedSelectors = [
                "//*[contains(@class, 'menu')]",
                "//*[contains(@class, 'navigation')]",
                "//*[contains(@class, 'sidebar')]",
                "//*[contains(@class, 'ads')]",
                "//*[contains(@class, 'advertisement')]",
                "//*[contains(@class, 'cookie')]",
                "//*[contains(@id, 'menu')]",
                "//*[contains(@id, 'nav')]",
            ];
            
            foreach ($unwantedSelectors as $selector) {
                $elements = $xpath->query($selector);
                $toRemove = iterator_to_array($elements);
                foreach ($toRemove as $element) {
                    if ($element->parentNode) {
                        $element->parentNode->removeChild($element);
                    }
                }
            }
            
            // Əsas məzmun sahəsini tap
            $contentSelectors = [
                'main',
                'article',
                "[role='main']",
                ".content",
                ".main-content",
                ".article-body",
                ".post-content",
                "#content",
                "#main"
            ];
            
            $mainContent = '';
            foreach ($contentSelectors as $selector) {
                try {
                    if (in_array($selector, ['main', 'article'])) {
                        $elements = $dom->getElementsByTagName($selector);
                    } else {
                        $elements = $xpath->query("//*[@class='$selector' or @id='$selector']");
                    }
                    
                    if ($elements->length > 0) {
                        $mainContent = $elements->item(0)->textContent;
                        Log::info('🎯 Əsas məzmun tapıldı', ['selector' => $selector]);
                        break;
                    }
                } catch (Exception $e) {
                    continue;
                }
            }
            
            // Əsas məzmun tapılmadısa, bütün body-dən al
            if (empty($mainContent)) {
                $body = $dom->getElementsByTagName('body');
                if ($body->length > 0) {
                    $mainContent = $body->item(0)->textContent;
                } else {
                    $mainContent = $dom->textContent;
                }
            }
            
            // Xətaları geri qür
            libxml_use_internal_errors($oldErrorReporting);
            
            // Mətnı təmizlə
            $mainContent = html_entity_decode($mainContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Mojibake varsa təkrar düzəlt (heç nə itirmədən)
            if (preg_match('/(Ã|Å|Ä|Â|É|ÅŸ|Ä±|Ã¶|Ã§|Ã¼|Ä)/u', $mainContent)) {
                $try = @iconv('Windows-1252', 'UTF-8//IGNORE', utf8_decode($mainContent));
                if ($try !== false && mb_check_encoding($try, 'UTF-8')) {
                    $mainContent = $try;
                }
            }
            $mainContent = preg_replace('/\s+/', ' ', $mainContent); // Artıq boşluqları sil
            $mainContent = preg_replace('/\n\s*\n/', "\n\n", $mainContent); // Çoxlu sətir keçmələrini tənzimle
            $mainContent = trim($mainContent);
            
            return $mainContent;
            
        } catch (Exception $e) {
            Log::warning('DOM processing xətası, regex fallback istifadə edilir', [
                'error' => $e->getMessage()
            ]);
            
            // Fallback: regex istifadə et
            $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
            $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content);
            $content = strip_tags($content);
            $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $content = preg_replace('/\s+/', ' ', $content);
            
            return trim($content);
        }
    }
    
    /**
     * Başlıq çıxar
     */
    protected function extractTitle(string $html, string $url): string
    {
        // 1. <title> tag-dən cəhd et
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $matches)) {
            $title = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (strlen($title) > 5 && strlen($title) <= 200) {
                return $this->cleanTitle($title);
            }
        }
        
        // 2. H1-dən cəhd et
        if (preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $html, $matches)) {
            $title = trim(strip_tags($matches[1]));
            $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (strlen($title) > 5 && strlen($title) <= 200) {
                return $this->cleanTitle($title);
            }
        }
        
        // 3. Meta title-dan cəhd et
        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches) ||
            preg_match('/<meta[^>]+name=["\']title["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            $title = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (strlen($title) > 5 && strlen($title) <= 200) {
                return $this->cleanTitle($title);
            }
        }
        
        // 4. URL-dən yaradılmış başlıq
        $host = parse_url($url, PHP_URL_HOST);
        return "İmport edilmiş məzmun - " . ($host ?: 'bilinməyən mənbə');
    }
    
    /**
     * Başlığı təmizlə
     */
    protected function cleanTitle(string $title): string
    {
        // Artıq boşluqları sil
        $title = preg_replace('/\s+/', ' ', $title);
        
        // Sayt adını və artıq məlumatları sil
        $commonSuffixes = [' - ', ' | ', ' :: ', ' / ', ' — '];
        foreach ($commonSuffixes as $suffix) {
            $pos = strrpos($title, $suffix);
            if ($pos !== false) {
                $beforeSuffix = substr($title, 0, $pos);
                $afterSuffix = substr($title, $pos + strlen($suffix));
                
                // Əgər sonrakı hissə sayt adı kimidir
                if (strlen($beforeSuffix) > strlen($afterSuffix) && strlen($beforeSuffix) > 10) {
                    $title = $beforeSuffix;
                }
            }
        }
        
        return trim($title);
    }
    
    /**
     * Meta məlumatları çıxar
     */
    protected function extractMetadata(string $html, string $url): array
    {
        $metadata = [
            'url' => $url,
            'extracted_at' => now()->toISOString(),
            'host' => parse_url($url, PHP_URL_HOST)
        ];
        
        // Description
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            $metadata['description'] = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        
        // Keywords
        if (preg_match('/<meta[^>]+name=["\']keywords["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            $metadata['keywords'] = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        
        // Author
        if (preg_match('/<meta[^>]+name=["\']author["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            $metadata['author'] = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        
        // Language
        if (preg_match('/<html[^>]+lang=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            $metadata['language'] = trim($matches[1]);
        }
        
        return $metadata;
    }
    
    /**
     * Səviyyəyə görə xülasələşdir - SÜRƏTLİ VE EFFEKTIV
     */
    protected function summarizeByLevel(string $content, int $level): string
    {
        $length = strlen($content);
        $map = [
            4 => min(1500, (int) round($length * 0.75)),
            3 => min(1000, (int) round($length * 0.5)),
            2 => min(600, (int) round($length * 0.4)), 
            1 => min(400, (int) round($length * 0.25)), // Artırıldı ki çox qısa olmasın
        ];
        
        // Əgər məzmun artıq qısadırsa, xülasələşdirməyə ehtiyac yoxdur
        if ($level >= 5 || $length <= 400) {
            Log::info('ℹ️ Xülasələşdirmə atlanıldı', ['level' => $level, 'content_length' => $length]);
            return $content;
        }
        
        $target = $map[$level] ?? 1000;
        
        // FAST MODE: Səviyyə 1-2 üçün daha ağıllı kəsmə
        if ($level <= 2) {
            Log::info('🚀 Sürətli xülasələşdirmə (çoxlu paraf kəsmə)', ['level' => $level, 'target' => $target]);
            // Çox paraflarlı məzmunları daha yoğun hala gətir amma hələ oxunabilir saxla
            $smartReduced = $this->smartContentReduction($content, $target);
            return $smartReduced;
        }
        
        // SMART MODE: Səviyyə 3+ üçün AI istifadə et amma timeout qoy
        try {
            if ($this->aiService && $level >= 3) {
                Log::info('🤖 AI xülasələşdirmə başlanır', ['level' => $level, 'target_length' => $target]);
                
                // 5 saniyə timeout - daha qısa
                $messages = [
                    ['role' => 'system', 'content' => 'Qısaca xülasə et, maksimum ' . $target . ' hərf.'],
                    ['role' => 'user', 'content' => mb_substr($content, 0, 2000)] // Daha da az mətn
                ];
                
                $startTime = microtime(true);
                $resp = $this->aiService->chat($messages, $target);
                $endTime = microtime(true);
                $duration = round(($endTime - $startTime) * 1000); // milliseconds
                
                $summary = $resp['content'] ?? '';
                if (is_string($summary) && strlen($summary) > 50 && $duration < 5000) { // 5 saniyədən az
                    Log::info('✅ AI xülasə hazir', ['duration_ms' => $duration, 'length' => strlen($summary)]);
                    return $summary;
                }
                
                Log::warning('⚠️ AI çox yavaş və ya boş, fallback istifadə edilir', ['duration_ms' => $duration]);
            }
        } catch (\Throwable $e) { 
            Log::warning('❌ AI xətası, fallback istifadə edilir', ['error' => $e->getMessage()]);
        }
        
        // Fallback: ağıllı kəsmə
        return $this->smartTruncate($content, $target);
    }
    
    /**
     * Daha ağıllı məzmun azalması - çox paraflı mətnlər üçün
     */
    protected function smartContentReduction(string $content, int $target): string
    {
        if (strlen($content) <= $target) {
            return $content;
        }
        
        // 1. Çox qısa parafları sil (50 hərfdən az)
        $paragraphs = explode("\n\n", $content);
        $filteredParagraphs = array_filter($paragraphs, function($p) {
            return strlen(trim($p)) >= 50;
        });
        
        $reducedContent = implode("\n\n", $filteredParagraphs);
        
        // 2. Hələ çox uzundursa, ən uzun parafları saxla
        if (strlen($reducedContent) > $target) {
            usort($filteredParagraphs, function($a, $b) {
                return strlen($b) - strlen($a); // Uzundan qısaya doğru sırala
            });
            
            $finalContent = '';
            $currentLength = 0;
            
            foreach ($filteredParagraphs as $paragraph) {
                $paragraphLength = strlen($paragraph);
                if ($currentLength + $paragraphLength <= $target * 0.9) {
                    $finalContent .= ($finalContent ? "\n\n" : '') . $paragraph;
                    $currentLength += $paragraphLength + 2; // + 2 for \n\n
                } else {
                    break;
                }
            }
            
            return $finalContent ?: $this->smartTruncate($content, $target);
        }
        
        return $reducedContent;
    }
    
    /**
     * Ağıllı kəsmə - cümlələri yarımda kəsməz
     */
    protected function smartTruncate(string $content, int $target): string
    {
        if (strlen($content) <= $target) {
            return $content;
        }
        
        // Target length-in 90%-nə kəs ki yer qalsın
        $cutPoint = (int) ($target * 0.9);
        $truncated = mb_substr($content, 0, $cutPoint);
        
        // Son cümlənin sonunu tap
        $lastSentence = mb_strrpos($truncated, '.');
        if ($lastSentence !== false && $lastSentence > ($cutPoint * 0.7)) {
            $truncated = mb_substr($truncated, 0, $lastSentence + 1);
        } else {
            // Cümlə yoxdursa, sətir sonu axtara
            $lastNewline = mb_strrpos($truncated, "\n");
            if ($lastNewline !== false && $lastNewline > ($cutPoint * 0.8)) {
                $truncated = mb_substr($truncated, 0, $lastNewline);
            } else {
                // Son boşluğu tap
                $lastSpace = mb_strrpos($truncated, ' ');
                if ($lastSpace !== false && $lastSpace > ($cutPoint * 0.85)) {
                    $truncated = mb_substr($truncated, 0, $lastSpace);
                }
            }
            $truncated .= '...';
        }
        
        Log::info('✂️ Ağıllı kəsmə tamamlandı', [
            'original_length' => strlen($content),
            'target' => $target,
            'final_length' => strlen($truncated)
        ]);
        
        return trim($truncated);
    }

    /**
     * Link-ləri çıxar (dərin crawling üçün)
     */
    protected function extractLinks(string $url, string $baseUrl): array
    {
        $content = $this->fetchContent($url);
        if (!$content) return [];
        
        $links = [];
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        $baseScheme = parse_url($baseUrl, PHP_URL_SCHEME);
        
        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $link = trim($match[1]);
                $linkText = trim(strip_tags($match[2]));
                
                // İstənilməyən linkləri keç
                if (empty($link) || 
                    strpos($link, '#') === 0 ||
                    strpos($link, 'javascript:') === 0 ||
                    strpos($link, 'mailto:') === 0 ||
                    strpos($link, 'tel:') === 0) {
                    continue;
                }
                
                // Relative URL-ləri absolute-a çevir
                if (strpos($link, 'http') !== 0) {
                    if (strpos($link, '/') === 0) {
                        $link = $baseScheme . '://' . $baseHost . $link;
                    } else {
                        $link = rtrim(dirname($url), '/') . '/' . $link;
                    }
                }
                
                // Yalnız eyni domain-dən linkləri götür
                $linkHost = parse_url($link, PHP_URL_HOST);
                if ($linkHost === $baseHost) {
                    $links[] = $link;
                }
            }
        }
        
        return array_unique($links);
    }
    
    /**
     * Full site training üçün yeni bilik yaradır
     */
    protected function createKnowledgeForFullSite(array $data, string $url, array $options = []): KnowledgeBase
    {
        // UTF-8 encoding təmizliyi
        $cleanTitle = $this->ensureValidUTF8($data['title']);
        $cleanContent = $this->ensureValidUTF8($data['content']);
        
        $kb = KnowledgeBase::create([
            'title' => $cleanTitle,
            'content' => $cleanContent,
            'source_url' => $url,
            'source' => $options['source'] ?? 'Sayt İmport (Avtomatik)',
            'category' => $options['category'] ?? 'full_site',
            'author' => $data['metadata']['author'] ?? null,
            'language' => $data['metadata']['language'] ?? 'az',
            'metadata' => array_merge($data['metadata'], [
                'training_method' => 'full_site_training',
                'training_mode' => 'full_site',
                'encoding_fixed' => true,
                'content_quality' => $this->assessContentQuality($cleanContent),
                'imported_via' => 'TrainingService::FullSite',
                'is_part_of_full_site' => true
            ]),
            'is_active' => true
        ]);
        // Embed and store
        try { 
            $kb->embedding = json_encode($this->embedding->embed($cleanContent)); 
            $kb->save(); 
            Log::info('✅ Full site: Embedding yaradıldı', ['url' => $url]);
        } catch (\Throwable $e) { 
            Log::warning('⚠️ Full site: Embedding xətası', ['url' => $url, 'error' => $e->getMessage()]);
        }
        return $kb;
    }
    
    /**
     * Full site training üçün mövcud bilik yenilənir
     */
    protected function updateKnowledgeForFullSite(KnowledgeBase $existing, array $data, array $options = []): KnowledgeBase
    {
        // UTF-8 encoding təmizliyi
        $cleanTitle = $this->ensureValidUTF8($data['title']);
        $cleanContent = $this->ensureValidUTF8($data['content']);
        
        $existing->update([
            'title' => $cleanTitle,
            'content' => $cleanContent,
            'metadata' => array_merge($existing->metadata ?? [], $data['metadata'], [
                'last_updated_via' => 'TrainingService::FullSite',
                'training_mode' => 'full_site',
                'update_count' => ($existing->metadata['update_count'] ?? 0) + 1,
                'content_quality' => $this->assessContentQuality($cleanContent),
                'is_part_of_full_site' => true,
                'last_full_site_update' => now()->toISOString()
            ])
        ]);
        try { 
            $existing->embedding = json_encode($this->embedding->embed($cleanContent)); 
            $existing->save();
            Log::info('✅ Full site: Embedding yeniləndi', ['url' => $existing->source_url]);
        } catch (\Throwable $e) { 
            Log::warning('⚠️ Full site: Embedding yeniləmə xətası', ['url' => $existing->source_url, 'error' => $e->getMessage()]);
        }
        return $existing->fresh();
    }
    
    /**
     * Yeni bilik yaradır
     */
    protected function createKnowledge(array $data, string $url, array $options = []): KnowledgeBase
    {
        // UTF-8 encoding təmizliyi
        $cleanTitle = $this->ensureValidUTF8($data['title']);
        $cleanContent = $this->ensureValidUTF8($data['content']);
        
        $isSinglePage = $options['single'] ?? true;
        
        $kb = KnowledgeBase::create([
            'title' => $cleanTitle,
            'content' => $cleanContent,
            'source_url' => $url,
            'source' => $options['source'] ?? 'URL Import',
            'category' => $options['category'] ?? 'imported',
            'author' => $data['metadata']['author'] ?? null,
            'language' => $data['metadata']['language'] ?? 'az',
            'metadata' => array_merge($data['metadata'], [
                'training_method' => 'advanced_training_service',
                'training_mode' => $isSinglePage ? 'single' : 'full',
                'encoding_fixed' => true,
                'content_quality' => $this->assessContentQuality($cleanContent),
                'imported_via' => 'TrainingService'
            ]),
            'is_active' => true
        ]);
        // Embed and store
        try { $kb->embedding = json_encode($this->embedding->embed($cleanContent)); $kb->save(); } catch (\Throwable $e) { }
        return $kb;
    }
    
    /**
     * Mövcud bilik yenilənir
     */
    protected function updateKnowledge(KnowledgeBase $existing, array $data, array $options = []): KnowledgeBase
    {
        // UTF-8 encoding təmizliyi
        $cleanTitle = $this->ensureValidUTF8($data['title']);
        $cleanContent = $this->ensureValidUTF8($data['content']);
        
        $isSinglePage = $options['single'] ?? true;
        
        $existing->update([
            'title' => $cleanTitle,
            'content' => $cleanContent,
            'metadata' => array_merge($existing->metadata ?? [], $data['metadata'], [
                'last_updated_via' => 'TrainingService',
                'training_mode' => $isSinglePage ? 'single' : 'full',
                'update_count' => ($existing->metadata['update_count'] ?? 0) + 1,
                'content_quality' => $this->assessContentQuality($cleanContent)
            ])
        ]);
        try { $existing->embedding = json_encode($this->embedding->embed($cleanContent)); $existing->save(); } catch (\Throwable $e) { }
        return $existing->fresh();
    }
    
    /**
     * Məzmunun keyfiyyətini qiymətləndir
     */
    protected function assessContentQuality(string $content): string
    {
        $length = strlen($content);
        
        if ($length < 500) return 'low';
        if ($length < 2000) return 'medium';
        if ($length < 5000) return 'high';
        return 'excellent';
    }
    
    /**
     * Mətn training - text məzmunu train et
     */
    public function trainFromText(string $title, string $content, array $options = []): KnowledgeBase
    {
        try {
            Log::info('📝 Text training başlanır', [
                'title' => $title,
                'content_length' => strlen($content)
            ]);
            
            // Minimum məzmun yoxla
            if (strlen($content) < 20) {
                throw new Exception('Məzmun çox qısadır');
            }
            
            // Müzakərəli başlıq yoxla
            $existing = KnowledgeBase::where('title', $title)
                                   ->whereNull('source_url')
                                   ->first();
                                   
            if ($existing) {
                Log::info('📝 Mövcud mətn yenilənir', ['title' => $title]);
                $existing->update([
                    'content' => $content,
                    'metadata' => array_merge($existing->metadata ?? [], [
                        'last_updated_via' => 'TrainingService::trainFromText',
                        'update_count' => ($existing->metadata['update_count'] ?? 0) + 1,
                        'content_quality' => $this->assessContentQuality($content)
                    ] + $options)
                ]);
                return $existing->fresh();
            } else {
                Log::info('🆕 Yeni mətn əlavə edilir', ['title' => $title]);
            $kb = KnowledgeBase::create([
                'title' => $title,
                'content' => $content,
                'source' => $options['source'] ?? 'Manual Text Entry',
                'category' => $options['category'] ?? 'manual',
                'author' => $options['author'] ?? null,
                'language' => $options['language'] ?? 'az',
                'metadata' => array_merge([
                    'training_method' => 'text_training',
                    'created_via' => 'TrainingService::trainFromText',
                    'content_quality' => $this->assessContentQuality($content)
                ], $options),
                'is_active' => true
            ]);
            try { $kb->embedding = json_encode($this->embedding->embed($content)); $kb->save(); } catch (\Throwable $e) { }
            return $kb;
            }
            
        } catch (Exception $e) {
            Log::error('❌ Text training xətası', [
                'title' => $title,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * UTF-8 encoding təmizliyi təmin et - Azərbaycan hərfləri üçün təkmilləşdirilmiş
     */
    protected function ensureValidUTF8(string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // İlk təmizlik - null və control karakterləri sil
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Kvadrat qutu simvollarını sil (replacement characters)
        $text = str_replace(['�', '□', '■', '\xEF\xBF\xBD'], '', $text);
        
        // Əgər artıq UTF-8 düzgündürsə, geri qaytar
        if (mb_check_encoding($text, 'UTF-8')) {
            // Hələ də problemli byte sequence-lər ola bilər, iconv ilə təmizlə
            $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            return $cleaned !== false ? $cleaned : $text;
        }
        
        // Müxtəlif encoding-lərdən çevriməyə cəhd et - Azərbaycan dili prioriteti
        $encodings = ['Windows-1254', 'CP1254', 'ISO-8859-9', 'Windows-1252', 'ISO-8859-1'];
        
        foreach ($encodings as $fromEncoding) {
            $converted = @mb_convert_encoding($text, 'UTF-8', $fromEncoding);
            if ($converted && mb_check_encoding($converted, 'UTF-8')) {
                // Əlavə təmizlik
                $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $converted);
                if ($converted !== false) {
                    // Azərbaycan hərfləri üçün xüsusi yoxlama
                    $azerbaijaniScore = preg_match_all('/[əçğıöşüÇĞIÖŞÜƏ]/u', $converted);
                    if ($azerbaijaniScore > 0 || $fromEncoding === 'Windows-1254' || $fromEncoding === 'CP1254') {
                        Log::info('✅ Encoding çevrildi', [
                            'from' => $fromEncoding, 
                            'to' => 'UTF-8',
                            'azerbaijani_chars_count' => $azerbaijaniScore
                        ]);
                        return $converted;
                    }
                }
            }
        }
        
        // iconv ilə cəhd et
        if (function_exists('iconv')) {
            foreach ($encodings as $fromEncoding) {
                $converted = @iconv($fromEncoding, 'UTF-8//IGNORE', $text);
                if ($converted && mb_check_encoding($converted, 'UTF-8')) {
                    Log::info('✅ iconv ilə çevrildi', ['from' => $fromEncoding]);
                    return $converted;
                }
            }
        }
        
        // Son ehtiyat təmizliyi
        $cleaned = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        if ($cleaned && mb_check_encoding($cleaned, 'UTF-8')) {
            $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $cleaned);
            if ($cleaned !== false) {
                Log::warning('⚠️ mb_convert_encoding ilə təmizləndi');
                return $cleaned;
            }
        }
        
        // Ən son ehtiyat - yalnız düzgün karakterlər saxla
        $cleaned = '';
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $char = $text[$i];
            $ord = ord($char);
            
            // ASCII və ya extended ASCII range
            if (($ord >= 32 && $ord <= 126) || ($ord >= 160 && $ord <= 255)) {
                $cleaned .= $char;
            } elseif ($ord == 10 || $ord == 13 || $ord == 9) { // newline, carriage return, tab
                $cleaned .= $char;
            }
        }
        
        Log::warning('⚠️ Byte-level təmizlik tətbiq edildi');
        return $cleaned;
    }
    
    /**
     * Q&A training - sual-cavab formatında train et
     */
    public function trainQA(string $question, string $answer, array $options = []): KnowledgeBase
    {
        try {
            
            $content = "**SUAL:** {$question}\n\n**CAVAB:** {$answer}";
            $title = "S&C: " . Str::limit($question, 80);
            
            $kb = KnowledgeBase::create([
                'title' => $title,
                'content' => $content,
                'source' => $options['source'] ?? 'S&C - Baza',
                'category' => $options['category'] ?? 'qa',
                'author' => $options['author'] ?? null,
                'language' => $options['language'] ?? 'az',
                'metadata' => array_merge([
                    'training_method' => 'qa_training',
                    'question' => $question,
                    'answer' => $answer,
                    'content_type' => 'qa_pair',
                    'content_quality' => $this->assessContentQuality($content)
                ], $options),
                'is_active' => true
            ]);
            try { $kb->embedding = json_encode($this->embedding->embed($question . "\n" . $answer)); $kb->save(); } catch (\Throwable $e) { }
            return $kb;
            
        } catch (Exception $e) {
            Log::error('❌ Q&A telimat xətası', [
                'question' => $question,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
