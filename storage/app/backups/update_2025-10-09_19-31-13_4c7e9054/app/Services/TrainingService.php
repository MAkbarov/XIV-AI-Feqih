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
            if ($level < 5) {
                $processedData['content'] = $this->summarizeByLevel($processedData['content'], $level);
            }
            
            // 3. Minimum məzmun yoxla
            if (strlen($processedData['content']) < 100) {
                throw new Exception('Məzmun çox qısadır, əzbərləmək üçün uyğun deyil');
            }
            
            // 4. Mövcud məzmunu yoxla (dublikat qarşısını al)
            $existing = KnowledgeBase::where('source_url', $url)->first();
            
            if ($existing) {
                Log::info('📝 Mövcud məzmun yenilənir', ['url' => $url]);
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
        $maxPages = $options['max_pages'] ?? 1000; // daha yüksək limit
        $discovered = 1;
        
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
                // Bu səhifəni train et
                $result = $this->trainSinglePage($url, $options);
                if ($result) {
                    $results[] = $result;
                }
                // Proqres
                if ($progress) {
                    $totalEstimate = max($discovered, count($results) + count($queue));
                    $percent = (int) floor((count($results) / max($totalEstimate,1)) * 100);
                    $percent = min(99, max(1, $percent));
                    $progress($percent);
                }
                
                // Daha dərin get
                if ($depth < $maxDepth) {
                    $links = $this->extractLinks($url, $baseUrl);
                    foreach ($links as $link) {
                        if (!in_array($link, $processed)) {
                            $queue[] = ['url' => $link, 'depth' => $depth + 1];
                            $discovered++;
                        }
                    }
                }
                
                // Server-ə hörmət et
                usleep(500000); // 0.5 saniyə gözlə
                
            } catch (Exception $e) {
                Log::warning('Səhifə training xətası', [
                    'url' => $url,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }
        
        if ($progress) { $progress(100); }
        return $results;
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
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_ENCODING => '', // Avtomatik gzip/deflate dekoding
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language: az,en-US,en;q=0.5',
                    'Accept-Encoding: gzip, deflate',
                    'Accept-Charset: UTF-8,ISO-8859-1,Windows-1254;q=0.7,*;q=0.7',
                    'DNT: 1',
                    'Connection: keep-alive',
                    'Upgrade-Insecure-Requests: 1'
                ]
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
                    'content_preview' => substr($content, 0, 200)
                ]);
                return $content;
            }
            
            Log::warning('⚠️ cURL xətası', [
                'url' => $url,
                'http_code' => $httpCode,
                'error' => $error
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
                    'timeout' => 30,
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
     * Encoding problemlərini həll et
     */
    protected function fixEncoding(string $content): string
    {
        // 1. İlk öncə content-in başında olan encoding məlumatlarını yoxla
        if (preg_match('/<meta[^>]+charset=["\']?([^"\'>\s]+)["\']?/i', $content, $matches)) {
            $htmlCharset = strtoupper($matches[1]);
            Log::info('HTML charset təyin edildi', ['charset' => $htmlCharset]);
        }
        
        // 2. Geniş encoding siyahısı (yalnız dəstəklənənlər)
        $encodings = [
            'UTF-8', 'Windows-1254', 'Windows-1252', 'ISO-8859-9', 'ISO-8859-1', 'ASCII'
        ];
        
        $detectedEncoding = mb_detect_encoding($content, $encodings, true);
        $isUTF8Valid = mb_check_encoding($content, 'UTF-8');
        
        Log::info('🔤 Encoding analizi', [
            'detected_encoding' => $detectedEncoding,
            'content_length' => strlen($content),
            'is_utf8_valid' => $isUTF8Valid,
            'html_charset' => isset($htmlCharset) ? $htmlCharset : 'none'
        ]);
        
        // 3a. UTF-8 görünsə də "mojibake" varsa düzəlt
        if ($isUTF8Valid && ($detectedEncoding === 'UTF-8' || !$detectedEncoding)) {
            // Tipik yanlış deşifrə edilmiş UTF-8 nümunələri: Ã, Å, Ä, Â, É, ÅŸ, Ä±, Ã¶, Ã§, Ã¼, Ä, É™
            if (preg_match('/(Ã|Å|Ä|Â|É|ÅŸ|Ä±|Ã¶|Ã§|Ã¼|Ä)/u', $content)) {
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
     * Səviyyəyə görə xülasələşdir
     */
    protected function summarizeByLevel(string $content, int $level): string
    {
        $length = strlen($content);
        $map = [
            4 => min(1500, (int) round($length * 0.75)),
            3 => min(1000, (int) round($length * 0.5)),
            2 => min(600, (int) round($length * 0.3)),
            1 => min(350, (int) round($length * 0.15)),
        ];
        if ($level >= 5 || $length <= 400) return $content;
        $target = $map[$level] ?? 1000;
        // AI ilə xülasələşdirmə mümkündürsə istifadə et
        try {
            if ($this->aiService) {
                $messages = [
                    ['role' => 'system', 'content' => 'Aşağıdakı mətni Azərbaycan dilində əsas məzmunu qoruyaraq, maksimum ' . $target . ' simvola sığacaq qısa xülasəyə çevir. Sadə tekst ver.'],
                    ['role' => 'user', 'content' => mb_substr($content, 0, 5000)]
                ];
                $resp = $this->aiService->chat($messages, $target);
                $summary = $resp['content'] ?? '';
                if (is_string($summary) && strlen($summary) > 0) {
                    return $summary;
                }
            }
        } catch (\Throwable $e) { /* fallback */ }
        // Fallback: kəs
        return Str::limit($content, $target, '...');
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
     * Yeni bilik yaradır
     */
    protected function createKnowledge(array $data, string $url, array $options = []): KnowledgeBase
    {
        // UTF-8 encoding təmizliyi
        $cleanTitle = $this->ensureValidUTF8($data['title']);
        $cleanContent = $this->ensureValidUTF8($data['content']);
        
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
        
        $existing->update([
            'title' => $cleanTitle,
            'content' => $cleanContent,
            'metadata' => array_merge($existing->metadata ?? [], $data['metadata'], [
                'last_updated_via' => 'TrainingService',
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
            if (strlen($content) < 50) {
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
     * UTF-8 encoding təmizliyi təmin et
     */
    protected function ensureValidUTF8(string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // İlk təmizlik - null və control karakterləri sil
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Əgər artıq UTF-8 düzgündürsə, geri qaytar
        if (mb_check_encoding($text, 'UTF-8')) {
            // Hələ də problemli byte sequence-lər ola bilər, iconv ilə təmizlə
            $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            return $cleaned !== false ? $cleaned : $text;
        }
        
        // Müxtəlif encoding-lərdən çevirməyə cəhd et
        $encodings = ['Windows-1254', 'ISO-8859-9', 'Windows-1252', 'ISO-8859-1', 'CP1254'];
        
        foreach ($encodings as $fromEncoding) {
            $converted = @mb_convert_encoding($text, 'UTF-8', $fromEncoding);
            if ($converted && mb_check_encoding($converted, 'UTF-8')) {
                // Əlavə təmizlik
                $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $converted);
                if ($converted !== false) {
                    Log::info('✅ Encoding çevrildi', ['from' => $fromEncoding, 'to' => 'UTF-8']);
                    return $converted;
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
