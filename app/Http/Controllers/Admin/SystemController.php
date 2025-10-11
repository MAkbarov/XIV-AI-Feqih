<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * XIV AI - System Management Controller
 * 
 * Admin paneli üçün sistem idarəetmə əməliyyatları
 * 
 * Version: v1.0.0
 * Release Date: 28.09.2025
 * Author: DeXIV
 */
class SystemController extends Controller
{
    private SystemHealthService $systemHealthService;
    
    public function __construct(SystemHealthService $systemHealthService)
    {
        $this->systemHealthService = $systemHealthService;
    }
    
    /**
     * Cache təmizləmə
     */
    public function clearCache(Request $request): JsonResponse
    {
        try {
            $result = $this->systemHealthService->clearCache();
            
            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'details' => $result['details'] ?? null
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Cache təmizləmə xətası: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Sistem sağlamlığını yoxla
     */
    public function getSystemHealth(Request $request): JsonResponse
    {
        try {
            $systemHealth = $this->systemHealthService->getSystemHealth();
            
            return response()->json([
                'success' => true,
                'data' => $systemHealth
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sistem sağlamlığı yoxlanıla bilmədi: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Sistem məlumatları
     */
    public function getSystemInfo(Request $request): JsonResponse
    {
        try {
            $systemInfo = [
                'server_info' => [
                    'php_version' => PHP_VERSION,
                    'laravel_version' => app()->version(),
                    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time'),
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                ],
                'environment' => [
                    'app_env' => config('app.env'),
                    'app_debug' => config('app.debug'),
                    'app_timezone' => config('app.timezone'),
                    'database_connection' => config('database.default'),
                    'cache_driver' => config('cache.default'),
                    'session_driver' => config('session.driver'),
                ]
            ];
            
            return response()->json([
                'success' => true,
                'data' => $systemInfo
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sistem məlumatları alına bilmədi: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Artisan komandalarını icra et
     */
    public function runArtisanCommand(Request $request): JsonResponse
    {
        $request->validate([
            'command' => 'required|string|in:cache:clear,config:clear,route:clear,view:clear,config:cache,route:cache,view:cache,migrate,migrate:status'
        ]);
        
        try {
            $command = $request->input('command');
            
            // Təhlükəsizlik üçün yalnız icazə verilən komandalar
            $allowedCommands = [
                'cache:clear' => 'Application cache təmizləndi',
                'config:clear' => 'Configuration cache təmizləndi', 
                'route:clear' => 'Route cache təmizləndi',
                'view:clear' => 'View cache təmizləndi',
                'config:cache' => 'Configuration cache yaradıldı',
                'route:cache' => 'Route cache yaradıldı',
                'view:cache' => 'View cache yaradıldı',
                'migrate' => 'Database migration-ları işlədildi',
                'migrate:status' => 'Migration statusu yoxlandı',
            ];
            
            if (!array_key_exists($command, $allowedCommands)) {
                return response()->json([
                    'success' => false,
                    'message' => 'İcazə verilməyən komanda'
                ], 403);
            }
            
            // Migration üçün xüsusi işləm
            if ($command === 'migrate') {
                // Production-da --force bayrağı ilə migration-ları işlət
                \Artisan::call('migrate', ['--force' => true]);
            } else {
                \Artisan::call($command);
            }
            
            $output = \Artisan::output();
            
            return response()->json([
                'success' => true,
                'message' => $allowedCommands[$command],
                'output' => trim($output)
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Komanda icra edilə bilmədi: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Database təmir və migration-ları avtomatik işlət
     */
    public function repairDatabase(Request $request): JsonResponse
    {
        try {
            $results = [];
            $errors = [];
            
            // 1. Cache təmizləmə
            try {
                \Artisan::call('cache:clear');
                \Artisan::call('config:clear');
                \Artisan::call('route:clear');
                $results[] = '✅ Cache-lər təmizləndi';
            } catch (\Exception $e) {
                $errors[] = '❌ Cache təmizləmə xətası: ' . $e->getMessage();
            }
            
            // 2. Migration statusunu yoxla
            try {
                \Artisan::call('migrate:status');
                $migrationStatus = \Artisan::output();
                $results[] = '✅ Migration statusu yoxlanıldı';
            } catch (\Exception $e) {
                $errors[] = '❌ Migration status xətası: ' . $e->getMessage();
            }
            
            // 3. Migration-ları işlət (--force ilə)
            try {
                \Artisan::call('migrate', ['--force' => true]);
                $migrateOutput = \Artisan::output();
                $results[] = '✅ Migration-lar avtomatik işlədildi';
                $results[] = 'Migration çıxışı: ' . trim($migrateOutput);
            } catch (\Exception $e) {
                $errors[] = '❌ Migration xətası: ' . $e->getMessage();
            }
            
            // 4. user_backgrounds cədvəlini yoxla
            try {
                $hasTable = \Schema::hasTable('user_backgrounds');
                if ($hasTable) {
                    $columns = \Schema::getColumnListing('user_backgrounds');
                    $results[] = '✅ user_backgrounds cədvəli mövcuddur';
                    $results[] = 'Sütunlar: ' . implode(', ', $columns);
                } else {
                    $results[] = '⚠️ user_backgrounds cədvəli tapilmadı - manual yaratmaq lazımdır';
                }
            } catch (\Exception $e) {
                $errors[] = '❌ Cədvəl yoxlama xətası: ' . $e->getMessage();
            }
            
            // 5. Son cache təmizləmə
            try {
                \Artisan::call('cache:clear');
                \Artisan::call('config:clear');
                $results[] = '✅ Son cache təmizləmə tamamlandı';
            } catch (\Exception $e) {
                $errors[] = '❌ Son cache təmizləmə xətası: ' . $e->getMessage();
            }
            
            return response()->json([
                'success' => count($errors) === 0,
                'message' => count($errors) === 0 ? 'Database təmir uğurla tamamlandı!' : 'Bəzi xətalar var, lakin əsas əməliyyatlar tamamlandı',
                'results' => $results,
                'errors' => $errors,
                'migration_status' => $migrationStatus ?? null
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Database təmir xətası: ' . $e->getMessage(),
                'results' => $results ?? [],
                'errors' => array_merge($errors ?? [], [$e->getMessage()])
            ], 500);
        }
    }
    
    /**
     * user_backgrounds cədvəlini manual yaratmaq
     */
    public function createUserBackgroundsTable(Request $request): JsonResponse
    {
        try {
            // Əvəl yoxla ki cədvəl var ya yox
            if (\Schema::hasTable('user_backgrounds')) {
                return response()->json([
                    'success' => true,
                    'message' => 'user_backgrounds cədvəli artıq mövcuddur!'
                ]);
            }
            
            // Cədvəli manual yarat
            \Schema::create('user_backgrounds', function ($table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->enum('active_type', ['solid', 'gradient', 'image', 'default'])->default('solid');
                $table->string('solid_color', 7)->nullable();
                $table->text('gradient_value')->nullable();
                $table->string('image_url')->nullable();
                $table->enum('image_size', ['cover', 'contain', 'auto', '100% 100%'])->default('cover');
                $table->string('image_position')->default('center');
                $table->timestamps();
                $table->unique('user_id');
            });
            
            return response()->json([
                'success' => true,
                'message' => 'user_backgrounds cədvəli uğurla yaradıldı!'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Cədvəl yaratma xətası: ' . $e->getMessage()
            ], 500);
        }
    }
}
