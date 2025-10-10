<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBase;
use App\Models\Settings;
use App\Services\TrainingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use App\Http\Controllers\Admin\Traits\HasFooterData;

class AiTrainingController extends Controller
{
    use HasFooterData;
    protected $trainingService;
    
    public function __construct(TrainingService $trainingService)
    {
        $this->trainingService = $trainingService;
    }
    /**
     * Display AI training page with pagination
     */
    public function index(\Illuminate\Http\Request $request)
    {
        // Pagination parametrləri
        $page = max(1, (int) $request->get('page', 1));
        $perPage = 10;
        
        // Search parametri
        $search = $request->get('search', '');
        
        // Query builder
        $query = KnowledgeBase::query()
            ->orderBy('created_at', 'desc');
            
        // Search filter
        if (!empty($search)) {
            $query->where(function($q) use ($search) {
                $q->where('title', 'LIKE', '%' . $search . '%')
                  ->orWhere('content', 'LIKE', '%' . $search . '%')
                  ->orWhere('category', 'LIKE', '%' . $search . '%')
                  ->orWhere('source', 'LIKE', '%' . $search . '%');
            });
        }
        
        // Pagination
        $knowledgeItemsPaginated = $query->paginate($perPage, ['*'], 'page', $page);
        $systemPrompt = Settings::get('ai_system_prompt', '');
        
        // Calculate global counts (independent of search/pagination)
        $totalKnowledgeCount = KnowledgeBase::count(); // Bütün məlumatların sayı
        $totalActiveCount = KnowledgeBase::where('is_active', true)->count();
        
        // Calculate trained URLs counts (independent of search/pagination)
        $totalTrainedUrls = KnowledgeBase::whereNotNull('source_url')
            ->where('source_url', 'LIKE', 'http%')
            ->count();
        $totalActiveTrainedUrls = KnowledgeBase::whereNotNull('source_url')
            ->where('source_url', 'LIKE', 'http%')
            ->where('is_active', true)
            ->count();

        // Ensure proper UTF-8 encoding for all text content
        $knowledgeItems = $knowledgeItemsPaginated->getCollection()->map(function ($item) {
            $item->title = mb_convert_encoding($item->title, 'UTF-8', 'UTF-8');
            $item->content = mb_convert_encoding($item->content, 'UTF-8', 'UTF-8');
            return $item;
        });
        
        // Set processed items back to paginator
        $knowledgeItemsPaginated->setCollection($knowledgeItems);
        
        \Log::info('📄 Bilik bazası səhifələndi', [
            'total_items' => $knowledgeItemsPaginated->total(),
            'current_page' => $page,
            'per_page' => $perPage,
            'search' => $search
        ]);
        
        return Inertia::render('Admin/AiTraining', $this->addFooterDataToResponse([
            'knowledgeItems' => $knowledgeItems,
            'pagination' => [
                'current_page' => $knowledgeItemsPaginated->currentPage(),
                'last_page' => $knowledgeItemsPaginated->lastPage(),
                'per_page' => $knowledgeItemsPaginated->perPage(),
                'total' => $totalKnowledgeCount, // Bütün məlumatların sayı (axtarışdan asılı olmayaraq)
                'search_total' => $knowledgeItemsPaginated->total(), // Axtarış nəticəsinin sayı
                'total_active' => $totalActiveCount,
                'total_trained_urls' => $totalTrainedUrls,
                'total_active_trained_urls' => $totalActiveTrainedUrls,
                'from' => $knowledgeItemsPaginated->firstItem(),
                'to' => $knowledgeItemsPaginated->lastItem(),
                'has_pages' => $knowledgeItemsPaginated->hasPages(),
                'links' => $knowledgeItemsPaginated->links()->elements[0] ?? []
            ],
            'search' => $search,
            'systemPrompt' => $systemPrompt,
        ]));
    }

    /**
     * Store new knowledge item (TEXT TRAINING)
     */
    public function storeKnowledge(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:512',
            'content' => 'required|string',
            'source' => 'nullable|string|max:255',
            'category' => 'required|string|max:255',
            'author' => 'nullable|string|max:255',
            'language' => 'required|string|max:8',
        ]);

        try {
            // Yeni TrainingService istifadə et
            $result = $this->trainingService->trainFromText(
                $validated['title'], 
                $validated['content'], 
                [
                    'source' => $validated['source'] ?? 'Baza Daxiletmə',
                    'category' => $validated['category'],
                    'author' => $validated['author'],
                    'language' => $validated['language']
                ]
            );
            
            return redirect()->back()->with('success', '📝 Mətn uğurla əzbərləndi!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Təlim xətası: ' . $e->getMessage());
        }
    }

    /**
     * Update system prompt
     */
    public function updateSystemPrompt(Request $request)
    {
        $validated = $request->validate([
            'system_prompt' => 'required|string',
        ]);

        Settings::set('ai_system_prompt', $validated['system_prompt']);

        return redirect()->back()->with('success', 'System prompt updated successfully');
    }
    
    /**
     * Train Q&A (Question & Answer pairs)
     */
    public function trainQA(Request $request)
    {
        $validated = $request->validate([
            'question' => 'required|string|min:10|max:1000',
            'answer' => 'required|string|min:10|max:5000',
            'source' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:50',
            'author' => 'nullable|string|max:100',
        ]);

        try {
            $result = $this->trainingService->trainQA(
                $validated['question'],
                $validated['answer'],
                [
                    'source' => $validated['source'] ?? 'Q&A Training',
                    'category' => $validated['category'] ?? 'qa',
                    'author' => $validated['author'],
                    'language' => 'az'
                ]
            );
            
            return redirect()->back()->with('success', '❓ Sual-Cavab uğurla əzbərləndi!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Q&A Training xətası: ' . $e->getMessage());
        }
    }

    /**
     * Import content from URL (ADVANCED URL TRAINING)
     */
    public function importFromUrl(Request $request)
    {
        $validated = $request->validate([
            'url' => 'required|url',
            'single' => 'boolean',
            'max_depth' => 'integer|min:1|max:5',
            'category' => 'nullable|string|max:50',
            'source' => 'nullable|string|max:100',
        ]);

        $single = $validated['single'] ?? true;
        $maxDepth = $validated['max_depth'] ?? 1;

        try {
            @set_time_limit(0);
            @ignore_user_abort(true);
            @ini_set('memory_limit', '512M');
            @ini_set('max_execution_time', 0);
            
            \Log::info('🚀 ADVANCED URL TRAINING: ' . $validated['url'], [
                'single' => $single,
                'max_depth' => $maxDepth,
                'category' => $validated['category'] ?? 'imported',
                'server' => request()->server('SERVER_NAME'),
                'user_agent' => request()->server('HTTP_USER_AGENT'),
                'php_version' => PHP_VERSION,
                'curl_available' => function_exists('curl_init'),
                'file_get_contents_available' => ini_get('allow_url_fopen')
            ]);
            
            // 🔥 TOKEN-BASED PROGRESS SYSTEM 🔥
            $token = (string) $request->input('progress_token');
            $token = is_string($token) ? trim($token) : '';
            if ($token && preg_match('/^[A-Za-z0-9._\-]{8,}$/', $token)) {
                $progressKey = 'url_train:' . $token;
            } else {
                $progressKey = 'url_train:' . (auth()->id() ?: 'guest');
            }
            
            $cache = Cache::store('file');
            $cache->put($progressKey, 0, 600);
            @file_put_contents(storage_path('app/'.$progressKey.'.txt'), '0');

            // Stop flag key
            $stopKey = 'url_train:stop:' . (isset($token) && $token ? $token : (auth()->id() ?: 'guest'));
            
            $result = $this->trainingService->trainFromUrl($validated['url'], [
                'single' => $single,
                'max_depth' => $maxDepth,
                'category' => $validated['category'] ?? 'imported',
                'source' => $validated['source'] ?? ($single ? 'Advanced URL Import' : 'Deep Site Training'),
                'language' => 'az',
                'max_pages' => $maxDepth >= 5 ? 2000 : 1000,
                // URL scope restriction - only crawl within the given URL path
                'scope_url' => $validated['url'],
                // Stop checker
                'shouldStop' => function() use ($stopKey) {
                    try {
                        $cache = \Cache::store('file');
                        $flag = (int) ($cache->get($stopKey, 0));
                        if ($flag === 1) { return true; }
                        $p = @file_get_contents(storage_path('app/'.$stopKey.'.txt'));
                        if (is_string($p) && trim($p) === '1') { return true; }
                    } catch (\Throwable $e) {}
                    return false;
                },
            ], function (int $percent) use ($progressKey, $cache) {
                $val = max(0, min(100, $percent));
                $cache->put($progressKey, $val, 600);
                @file_put_contents(storage_path('app/'.$progressKey.'.txt'), (string)$val);
            });

            $cache->put($progressKey, 100, 600);
            @file_put_contents(storage_path('app/'.$progressKey.'.txt'), '100');

            if ($result['success']) {
                $pagesCount = $result['trained_pages'];
                
                // Düz mesaj - səviyyəyə görə fərqli mesajlar
                if ($single) {
                    $message = "✅ 1 səhifə uğurla əzbərləndi! Sayt tamamılə AI-yə öyrədildi.";
                } else {
                    // Bütün sayt üçün real səhifə sayını göstər
                    if ($pagesCount === 0) {
                        $message = "⚠️ Heç bir səhifə tapılmadı və ya əzbərlənmədi. URL-i yoxlayın.";
                    } elseif ($pagesCount === 1) {
                        $message = "✅ 1 səhifə uğurla əzbərləndi! Sayt tamamılə AI-yə öyrədildi.";
                    } else {
                        $message = "✅ {$pagesCount} səhifə uğurla əzbərləndi! Sayt tamamılə AI-yə öyrədildi.";
                    }
                }
                
                \Log::info('✨ Training möhtəşəm uğurla tamamlandı!', [
                    'trained_pages' => $pagesCount,
                    'url' => $validated['url'],
                    'single_mode' => $single,
                    'results_details' => $result['results'] ? count($result['results']) : 0,
                    'final_message' => $message
                ]);
                    
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'trained_pages' => $pagesCount,
                    'results' => $result['results'],
                    'mode' => $single ? 'single_page' : 'multi_page'
                ]);
            } else {
                \Log::error('❌ URL Training uğursuz - result false', [
                    'url' => $validated['url'],
                    'result' => $result
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'URL training uğursuz oldu - Backend xətası'
                ], 422);
            }
        } catch (\Exception $e) {
            \Log::error('❌ ADVANCED URL TRAINING XƏTASI: ' . $e->getMessage(), [
                'url' => $validated['url'],
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $errorMessage = 'Training xətası: ' . $e->getMessage();
            if (strpos($e->getMessage(), 'cURL') !== false) {
                $errorMessage .= ' (Hostingdə network məhdudiyyəti ola bilər)';
            }
            
            return response()->json([
                'success' => false,
                'message' => $errorMessage,
                'debug' => [
                    'error_type' => get_class($e),
                    'php_version' => PHP_VERSION,
                    'curl_available' => function_exists('curl_init'),
                    'url_fopen' => ini_get('allow_url_fopen')
                ]
            ], 500);
        }
    }

    /**
     * Toggle knowledge item active state
     */
    public function toggleKnowledge($id)
    {
        $knowledge = KnowledgeBase::findOrFail($id);
        $knowledge->is_active = !$knowledge->is_active;
        $knowledge->save();

        return redirect()->back()->with('success', 'Knowledge item status updated');
    }

    /**
     * Show edit form for knowledge item
     */
    public function editKnowledge($id)
    {
        $knowledge = KnowledgeBase::findOrFail($id);
        return response()->json($knowledge);
    }

    /**
     * Update knowledge item
     */
    public function updateKnowledge(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:512',
            'content' => 'required|string',
            'source' => 'nullable|string|max:255',
            'source_url' => 'nullable|url|max:1024',
            'category' => 'required|string|max:255',
            'author' => 'nullable|string|max:255',
            'language' => 'required|string|max:8',
            'is_active' => 'boolean',
        ]);

        $knowledge = KnowledgeBase::findOrFail($id);
        $knowledge->update($validated);

        return redirect()->back()->with('success', 'Məlumat uğurla yeniləndi!');
    }

    /**
     * Delete knowledge item
     */
    public function deleteKnowledge($id)
    {
        $knowledge = KnowledgeBase::findOrFail($id);
        $knowledge->delete();

        return redirect()->back()->with('success', 'Məlumat uğurla silindi!');
    }

    /**
     * Get active knowledge for AI context
     */
    public static function getActiveKnowledge()
    {
        return KnowledgeBase::where('is_active', true)
            ->select('title', 'content', 'source', 'category')
            ->get()
            ->map(function ($item) {
                return sprintf(
                    "[%s - %s] %s: %s",
                    $item->category,
                    $item->source ?? 'N/A',
                    $item->title,
                    $item->content
                );
            })
            ->implode("\n\n");
    }

    /**
     * Search knowledge base
     */
    public function searchKnowledge(Request $request)
    {
        $query = $request->get('query', '');
        $limit = $request->get('limit', 5);

        $results = KnowledgeBase::searchRelevant($query, $limit);

        return response()->json([
            'results' => $results,
            'count' => $results->count(),
        ]);
    }

    /**
     * Export knowledge base
     */
    public function exportKnowledge()
    {
        $knowledge = KnowledgeBase::all();
        $systemPrompt = Settings::get('ai_system_prompt', '');

        $export = [
            'system_prompt' => $systemPrompt,
            'knowledge_items' => $knowledge,
            'exported_at' => now()->toIso8601String(),
        ];

        return response()->json($export)
            ->header('Content-Disposition', 'attachment; filename="ai-knowledge-' . date('Y-m-d') . '.json"');
    }

    /**
     * Import knowledge base
     */
    public function importKnowledge(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:json',
        ]);

        $content = file_get_contents($request->file('file')->getRealPath());
        $data = json_decode($content, true);

        if (!$data || !isset($data['knowledge_items'])) {
            return redirect()->back()->with('error', 'Invalid import file format');
        }

        // Import system prompt if provided
        if (isset($data['system_prompt'])) {
            Settings::set('ai_system_prompt', $data['system_prompt']);
        }

        // Import knowledge items
        foreach ($data['knowledge_items'] as $item) {
            KnowledgeBase::updateOrCreate(
                ['title' => $item['title'], 'category' => $item['category']],
                $item
            );
        }

        return redirect()->back()->with('success', 'Knowledge base imported successfully');
    }

    public function importProgress()
    {
        try {
            // Token-based key with fallback
            $token = (string) request()->query('token');
            $token = is_string($token) ? trim($token) : '';
            if ($token && preg_match('/^[A-Za-z0-9._\-]{8,}$/', $token)) {
                $progressKey = 'url_train:' . $token;
            } else {
                $progressKey = 'url_train:' . (auth()->id() ?: 'guest');
            }
            
            $val = 0;
            
            // Try cache first
            try {
                $cache = Cache::store('file');
                $val = (int) ($cache->get($progressKey, 0));
            } catch (\Exception $cacheErr) {
                // Cache failed, try file fallback
            }
            
            // File fallback
            if ($val === 0) {
                $filePath = storage_path('app/'.$progressKey.'.txt');
                if (file_exists($filePath)) {
                    $p = @file_get_contents($filePath);
                    if (is_string($p) && is_numeric(trim($p))) {
                        $val = (int) trim($p);
                    }
                }
            }
            
            return response()->json([
                'progress' => $val,
                'timestamp' => time()
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'progress' => 0,
                'timestamp' => time()
            ]);
        }
    }

    public function importStop(Request $request)
    {
        try {
            $token = (string) ($request->input('token') ?? $request->query('token'));
            $token = is_string($token) ? trim($token) : '';
            if ($token && preg_match('/^[A-Za-z0-9._\-]{8,}$/', $token)) {
                $stopKey = 'url_train:stop:' . $token;
            } else {
                $stopKey = 'url_train:stop:' . (auth()->id() ?: 'guest');
            }
            
            // Ensure storage directory exists
            $storageDir = storage_path('app');
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0755, true);
            }
            
            // Set stop flag in both cache and file
            try {
                $cache = Cache::store('file');
                $cache->put($stopKey, 1, 600);
            } catch (\Exception $cacheErr) {
                // Cache failed, file will still work
            }
            
            @file_put_contents(storage_path('app/'.$stopKey.'.txt'), '1');
            
            return response()->json([
                'success' => true, 
                'message' => 'Import dayandırıldı',
                'key' => $stopKey
            ]);
            
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false, 
                'message' => 'Dayandırma alınmadı: ' . $e->getMessage()
            ], 500);
        }
    }
}
