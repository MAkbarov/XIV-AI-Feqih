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
            'command' => 'required|string|in:cache:clear,config:clear,route:clear,view:clear,config:cache,route:cache,view:cache'
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
            ];
            
            if (!array_key_exists($command, $allowedCommands)) {
                return response()->json([
                    'success' => false,
                    'message' => 'İcazə verilməyən komanda'
                ], 403);
            }
            
            \Artisan::call($command);
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
}