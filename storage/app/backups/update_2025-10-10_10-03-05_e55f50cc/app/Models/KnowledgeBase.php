<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use App\Helpers\EncodingHelper;

class KnowledgeBase extends Model
{
    use HasFactory;
    
    protected $table = 'knowledge_base';

    protected $fillable = [
        'title',
        'content',
        'source',
        'source_url',
        'category',
        'author',
        'language',
        'metadata',
        'embedding',
        'is_active',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];
    
    /**
     * Automatically fix encoding issues when retrieving from database
     */
    public function getTitleAttribute($value)
    {
        return $this->fixEncoding($value);
    }
    
    public function getContentAttribute($value)
    {
        return $this->fixEncoding($value);
    }
    
    public function getSourceAttribute($value)
    {
        return $this->fixEncoding($value);
    }
    
    /**
     * Fix encoding issues for display using comprehensive helper
     */
    protected function fixEncoding($value)
    {
        return EncodingHelper::fixText($value);
    }

    /**
     * Search for relevant TRAINED knowledge based on query
     * ONLY returns content that has been specifically trained/imported
     */
    public static function searchRelevant(string $query, int $limit = 5): Collection
    {
        try {
            \Log::info('🎆 ADVANCED TRAINED KNOWLEDGE SEARCH', ['query' => $query]);
            
            // Professional keyword mapping for better search
            $islamicTerms = [
                'namaz' => ['səcdə', 'rüku', 'qiblə', 'təşəhhüd', 'vüzu'],
                'oruc' => ['iftar', 'səhər', 'ramazan', 'fidyə', 'ru'], 
                'zəkat' => ['fitri', 'mal', 'qızıl', 'gümüş', 'xums'],
                'həcc' => ['umrə', 'kəbə', 'mədinə', 'ihram', 'tovəf'],
                'nikah' => ['evlilik', 'mehriyyə', 'şəhid', 'qadın'],
                'talaq' => ['boşanma', 'iddət', 'xula', 'ər'],
            ];
            
            $expandedQuery = $query;
            foreach ($islamicTerms as $term => $related) {
                if (stripos($query, $term) !== false) {
                    $expandedQuery .= ' ' . implode(' ', $related);
                }
            }
            
            // PRIORITY 1: URL-based trained content (highest priority)
            $urlTrainedResults = static::where('is_active', true)
                ->whereNotNull('source_url')
                ->where('source_url', '!=', '')
                ->where(function ($q) use ($query, $expandedQuery) {
                    $q->where('title', 'LIKE', "%{$query}%")
                      ->orWhere('content', 'LIKE', "%{$query}%")
                      ->orWhere('category', 'LIKE', "%{$query}%");
                })
                ->orderBy('updated_at', 'desc')
                ->limit(3)
                ->get();
            
            // PRIORITY 2: Text/QA trained content
            $remaining = $limit - $urlTrainedResults->count();
            $textTrainedResults = collect();
            
            if ($remaining > 0) {
                $textTrainedResults = static::where('is_active', true)
                    ->whereNotIn('id', $urlTrainedResults->pluck('id'))
                    ->where(function ($q) use ($query, $expandedQuery) {
                        $q->where('title', 'LIKE', "%{$query}%")
                          ->orWhere('content', 'LIKE', "%{$query}%")
                          ->orWhere('category', 'LIKE', "%{$query}%");
                    })
                    ->orderBy('created_at', 'desc')
                    ->limit($remaining)
                    ->get();
            }
            
            // PRIORITY 3: Category and source based search (if still needed)
            $remaining = $limit - ($urlTrainedResults->count() + $textTrainedResults->count());
            $categoryResults = collect();
            
            if ($remaining > 0) {
                $keywords = preg_split('/[\s,]+/', $expandedQuery, -1, PREG_SPLIT_NO_EMPTY);
                $keywordQuery = static::where('is_active', true)
                    ->whereNotIn('id', $urlTrainedResults->merge($textTrainedResults)->pluck('id'));
                
                foreach ($keywords as $keyword) {
                    if (strlen($keyword) > 2) {
                        $keywordQuery->where(function ($q) use ($keyword) {
                            $q->where('title', 'LIKE', "%{$keyword}%")
                              ->orWhere('content', 'LIKE', "%{$keyword}%")
                              ->orWhere('category', 'LIKE', "%{$keyword}%")
                              ->orWhere('source', 'LIKE', "%{$keyword}%");
                        });
                    }
                }
                
                $categoryResults = $keywordQuery->limit($remaining)->get();
            }
            
            // Combine all results with priority order
            $finalResults = $urlTrainedResults
                ->merge($textTrainedResults)
                ->merge($categoryResults)
                ->take($limit);
            
            // Enhanced logging with training source info - SAFE VERSION
            $sourceBreakdown = $finalResults->groupBy(function ($item) {
                // Safe check for source_url field
                $hasSourceUrl = false;
                try {
                    $hasSourceUrl = !empty($item->source_url);
                } catch (\Exception $e) {
                    // source_url field doesn't exist, check source field instead
                    $hasSourceUrl = $item->source && (str_contains($item->source, 'http') || str_contains($item->source, 'URL'));
                }
                
                if ($hasSourceUrl) return 'URL_TRAINED';
                if (isset($item->metadata['training_method'])) return 'TEXT_QA_TRAINED';
                return 'LEGACY';
            })->map->count();
            
            \Log::info('✨ ADVANCED TRAINED SEARCH COMPLETED', [
                'query' => $query,
                'total_found' => $finalResults->count(),
                'source_breakdown' => $sourceBreakdown->toArray(),
                'categories' => $finalResults->pluck('category')->unique()->values()->toArray(),
                'url_sources' => $finalResults->whereNotNull('source_url')->pluck('source_url')->toArray()
            ]);

            return $finalResults;
            
        } catch (\Exception $e) {
            \Log::error('❌ ADVANCED TRAINED SEARCH ERROR', [
                'query' => $query,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return collect();
        }
    }
}

