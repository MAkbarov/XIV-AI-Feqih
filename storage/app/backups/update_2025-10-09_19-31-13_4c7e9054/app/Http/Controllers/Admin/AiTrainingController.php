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
     * Display AI training page
     */
    public function index()
    {
        $perPage = (int) request()->get('per_page', 10);
        $systemPrompt = Settings::get('ai_system_prompt', '');

        $knowledgeItems = KnowledgeBase::orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->through(function ($item) {
                $item->title = mb_convert_encoding($item->title, 'UTF-8', 'UTF-8');
                $item->content = mb_convert_encoding($item->content, 'UTF-8', 'UTF-8');
                return $item;
            });
        
        return Inertia::render('Admin/AiTraining', $this->addFooterDataToResponse([
            'knowledgeItems' => $knowledgeItems,
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
            \Log::info('🚀 ADVANCED URL TRAINING: ' . $validated['url'], [
                'single' => $single,
                'max_depth' => $maxDepth,
                'category' => $validated['category'] ?? 'imported'
            ]);
            
            // 🔥 YENİ ADVANCED TRAINING SERVICE İSTİFADA ET!
            // Real-time progress (polling) – cache key per user
            $progressKey = 'url_train:' . (auth()->id() ?: 'guest');
            $cache = Cache::store('file');
            $cache->put($progressKey, 0, 600);
            @file_put_contents(storage_path('app/'.$progressKey.'.txt'), '0');

            $result = $this->trainingService->trainFromUrl($validated['url'], [
                'single' => $single,
                'max_depth' => $maxDepth, // həm dərinlik, həm də səviyyə üçün istifadə olunur (5=full, 4..1=xülasə)
                'category' => $validated['category'] ?? 'imported',
                'source' => $validated['source'] ?? ($single ? 'Advanced URL Import' : 'Deep Site Training'),
                'language' => 'az',
                'max_pages' => $maxDepth >= 5 ? 2000 : 1000,
            ], function (int $percent) use ($progressKey, $cache) {
                $val = max(0, min(100, $percent));
                $cache->put($progressKey, $val, 600);
                @file_put_contents(storage_path('app/'.$progressKey.'.txt'), (string)$val);
            });

            $cache->put($progressKey, 100, 600);
            @file_put_contents(storage_path('app/'.$progressKey.'.txt'), '100');

            if ($result['success']) {
                $pagesCount = $result['trained_pages'];
                $message = $single ? 
                    "✅ Link uğurla əzbərləndi! Bütün məzmunu AI indi bilir." : 
                    "✅ Sayt tamamılə əzbərləndi! {$pagesCount} səhifə AI-yə öyrədildi.";
                
                \Log::info('✨ Training möhtəşəm uğurla tamamlandı!', [
                    'trained_pages' => $pagesCount,
                    'url' => $validated['url']
                ]);
                    
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'trained_pages' => $pagesCount,
                    'results' => $result['results'],
                    'mode' => $single ? 'single_page' : 'multi_page'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'URL training uğursuz oldu'
                ], 422);
            }
        } catch (\Exception $e) {
            \Log::error('❌ ADVANCED URL TRAINING XƏTASI: ' . $e->getMessage(), [
                'url' => $validated['url'],
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Training xətası: ' . $e->getMessage()
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
        $progressKey = 'url_train:' . (auth()->id() ?: 'guest');
        $cache = Cache::store('file');
        $val = (int) ($cache->get($progressKey, 0));
        if ($val === 0) {
            $p = @file_get_contents(storage_path('app/'.$progressKey.'.txt'));
            if (is_string($p) && is_numeric(trim($p))) {
                $val = (int) trim($p);
            }
        }
        return response()->json(['progress' => $val]);
    }
}
