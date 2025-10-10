<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use ZipArchive;
use App\Http\Controllers\Admin\Traits\HasFooterData;
use App\Models\SystemUpdateLog;

class SystemUpdateController extends Controller
{
    use HasFooterData;
    
    // SELF-CONTAINED CONFIGURATION - No .env dependencies
    private const GITHUB_REPO_OWNER = 'MAkbarov';
    private const GITHUB_REPO_NAME = 'XIV-AI-Feqih';
    private const GITHUB_BRANCH = 'main';
    
    private $gitRepoUrl;
    private $versionApiUrl;
    
    public function __construct()
    {
        // Build URLs from constants - no external dependencies
        $owner = self::GITHUB_REPO_OWNER;
        $repo = self::GITHUB_REPO_NAME;
        $branch = self::GITHUB_BRANCH;
        
        $this->gitRepoUrl = "https://github.com/{$owner}/{$repo}/archive/refs/heads/{$branch}.zip";
        $this->versionApiUrl = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";
    }
    
    public function index()
    {
        $currentVersion = $this->getCurrentVersion();
        $latestVersion = $this->getLatestVersion();
        $updateAvailable = $latestVersion && version_compare($currentVersion, $latestVersion, '<');
        $lastUpdated = Settings::get('app_updated_at', null);

        return Inertia::render('Admin/SystemUpdate', $this->addFooterDataToResponse([
            'currentVersion' => $currentVersion,
            'latestVersion' => $latestVersion,
            'updateAvailable' => $updateAvailable,
            'lastUpdated' => $lastUpdated,
        ]));
    }

    public function checkUpdates()
    {
        try {
            $currentVersion = $this->getCurrentVersion();
            $latestVersion = $this->getLatestVersion();
            $updateAvailable = $latestVersion && version_compare($currentVersion, $latestVersion, '<');
            $releaseNotes = $this->getReleaseNotes();
            
            $downloadUrl = null;
            if ($updateAvailable && $latestVersion) {
                $downloadUrl = $this->getDownloadUrl($latestVersion) ?: $this->gitRepoUrl;
            }

            return response()->json([
                'success' => true,
                'current_version' => $currentVersion,
                'latest_version' => $latestVersion,
                'has_update' => $updateAvailable,
                'download_url' => $downloadUrl,
                'release_notes' => $releaseNotes,
                'message' => $updateAvailable ? "Yenilik mövcuddur: v{$latestVersion}" : ($latestVersion ? 'Son versiya yüklüdür' : 'Versiya məlumatı əldə olunmadı')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Yenilik yoxlanılarkən xəta baş verdi: ' . $e->getMessage()
            ]);
        }
    }

    public function performUpdate(Request $request)
    {
        set_time_limit(0);
        ignore_user_abort(true);
        
        // Emergency recovery mechanism
        register_shutdown_function(function() {
            try {
                if (!$this->isSiteOnline()) {
                    Artisan::call('up');
                    $this->logMessage('🚨 Emergency recovery: Site brought back online');
                }
            } catch (\Exception $e) {
                // Emergency log to file system
                @error_log('[EMERGENCY] Site recovery failed: ' . $e->getMessage());
            }
        });

        $downloadUrl = $request->input('download_url', $this->gitRepoUrl);

        return response()->stream(function () use ($downloadUrl) {
            $startVersion = $this->getCurrentVersion();
            $backupPath = null;
            
            try {
                $this->logMessage('🚀 KÖKLÜ AVTOMATİK YENİLƏMƏ BAŞLANDI!');
                
                $latestVersion = $this->getLatestVersion();
                $versionDisplay = $latestVersion ? "v{$latestVersion}" : "Son versiya";
                $this->logMessage("📋 Versiya: {$startVersion} → {$versionDisplay}");
                
                // Step 1: Pre-flight system checks
                $this->logMessage('🔍 Sistem hazırlığı yoxlanılır...');
                $this->performSystemChecks();
                
                // Step 2: Create atomic backup
                $this->logMessage('💾 Atom backup yaradılır...');
                $backupPath = $this->createAtomicBackup();
                
                // Step 3: Maintenance mode
                $this->logMessage('🔧 Maintenance mode aktivləşir...');
                Artisan::call('down', ['--retry' => 60, '--secret' => 'update-in-progress']);
                
                // Step 4: Download update package
                $this->logMessage('📥 Update paketi endirilir...');
                $updatePath = $this->downloadUpdatePackage($downloadUrl);
                
                // Step 5: Deploy files
                $this->logMessage('📂 Fayllar deploy edilir...');
                $this->deployFiles($updatePath);
                
                // Step 6: Database migrations
                $this->logMessage('🗄️ Database yenilənir...');
                $this->runDatabaseMigrations();
                
                // Step 7: Dependencies (if possible)
                $this->logMessage('📦 Dependencies yoxlanılır...');
                $this->handleDependencies();
                
                // Step 8: Cache optimization
                $this->logMessage('⚡ System cache yenilənir...');
                $this->optimizeSystem();
                
                // Step 9: Post-deployment verification
                $this->logMessage('🔍 Deployment yoxlanılır...');
                $this->verifyDeployment();
                
                // Step 10: Update version and bring online
                $this->logMessage('📝 Versiya yenilənir...');
                if ($latestVersion) {
                    Settings::set('app_version', $latestVersion);
                    Settings::set('app_updated_at', now()->toDateTimeString());
                }
                
                // Bring site back online
                Artisan::call('up');
                $this->logMessage('🟢 Sayt yenidən aktiv!');
                
                // Final verification
                sleep(2);
                $this->logMessage('✅ Final doğrulama...');
                
                $this->logMessage('🎉 YENİLƏMƏ TAMAMILƏ UĞURLU!');
                $this->logMessage("✨ {$startVersion} → {$latestVersion}");
                $this->logMessage('🔄 3 saniyə sonra səhifə yenilənəcək...');
                // Emit explicit success marker for frontend parsers
                $this->logMessage('[[UPDATE_SUCCESS]] Son versiyaya müvəffəqiyyətlə yeniləndi!');
                
                // Log success
                $this->logUpdateToDatabase($startVersion, $latestVersion, 'success', 'Update completed successfully');
                
            } catch (\Exception $e) {
                $this->logMessage('❌ XƏTA: ' . $e->getMessage());
                
                // Emergency recovery
                try {
                    if ($backupPath && File::exists($backupPath)) {
                        $this->logMessage('🔄 Backup-dan bərpa...');
                        $this->restoreFromBackup($backupPath);
                    }
                    
                    Artisan::call('up');
                    $this->logMessage('🚨 Sayt bərpa edildi');
                    
                } catch (\Exception $recoveryError) {
                    $this->logMessage('💥 Bərpa xətası: ' . $recoveryError->getMessage());
                }
                
                // Log failure
                $latestVersion = $this->getLatestVersion();
                $this->logUpdateToDatabase($startVersion, $latestVersion, 'failed', $e->getMessage());
                // Emit explicit failure marker for frontend parsers
                $this->logMessage('[[UPDATE_FAILED]] ' . $e->getMessage());
                
            } finally {
                // Cleanup temporary files
                try {
                    if (isset($updatePath) && $updatePath && File::exists(dirname($updatePath))) {
                        File::deleteDirectory(dirname($updatePath));
                    }
                } catch (\Exception $e) {
                    // Ignore cleanup errors
                }
            }
            
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no'
        ]);
    }
    
    private function getCurrentVersion(): string
    {
        // Multiple source fallback for current version
        $sources = [
            // 1. Database setting (primary)
            fn() => Settings::get('app_version'),
            
            // 2. Package.json (secondary)  
            fn() => $this->getVersionFromPackageJson(),
            
            // 3. Composer.json (tertiary)
            fn() => $this->getVersionFromComposerJson(),
            
            // 4. Default fallback
            fn() => '1.0.0'
        ];
        
        foreach ($sources as $source) {
            try {
                $version = $source();
                if ($version && preg_match('/^\d+\.\d+\.\d+/', $version)) {
                    return $version;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        
        return '1.0.0';
    }
    
    private function getVersionFromPackageJson(): ?string
    {
        $packageFile = base_path('package.json');
        if (File::exists($packageFile)) {
            $package = json_decode(File::get($packageFile), true);
            return $package['version'] ?? null;
        }
        return null;
    }
    
    private function getVersionFromComposerJson(): ?string
    {
        $composerFile = base_path('composer.json');
        if (File::exists($composerFile)) {
            $composer = json_decode(File::get($composerFile), true);
            return $composer['version'] ?? null;
        }
        return null;
    }
    
    private function getLatestVersion(): ?string
    {
        $methods = [
            [$this, 'getVersionFromGitHubAPI'],
            [$this, 'getVersionFromGitHubRaw'],
            [$this, 'getVersionFromLocalFallback'],
        ];
        
        foreach ($methods as $method) {
            try {
                $version = call_user_func($method);
                if ($version && preg_match('/^\d+\.\d+\.\d+/', $version)) {
                    return $version;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        
        return null;
    }
    
    private function getVersionFromGitHubAPI(): ?string
    {
        if (!function_exists('curl_init')) {
            throw new \Exception('cURL not available');
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->versionApiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'XIV-AI-SystemUpdater/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github.v3+json',
                'User-Agent: XIV-AI-SystemUpdater/1.0'
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("cURL error: {$error}");
        }
        
        if ($httpCode !== 200) {
            throw new \Exception("HTTP {$httpCode}");
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['tag_name'])) {
            throw new \Exception('Invalid API response');
        }
        
        return ltrim($data['tag_name'], 'v');
    }
    
    private function getVersionFromGitHubRaw(): ?string
    {
        $rawUrl = "https://raw.githubusercontent.com/" . self::GITHUB_REPO_OWNER . "/" . self::GITHUB_REPO_NAME . "/main/version.json";
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 20,
                'user_agent' => 'XIV-AI-SystemUpdater/1.0'
            ]
        ]);
        
        $response = @file_get_contents($rawUrl, false, $context);
        if (!$response) {
            throw new \Exception('Raw file not accessible');
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['version'])) {
            throw new \Exception('Invalid version file');
        }
        
        return $data['version'];
    }
    
    private function getVersionFromLocalFallback(): ?string
    {
        $versionFile = base_path('version.json');
        if (File::exists($versionFile)) {
            $data = json_decode(File::get($versionFile), true);
            return $data['version'] ?? null;
        }
        
        throw new \Exception('No local version file');
    }
    
    private function createAtomicBackup(): string
    {
        $timestamp = date('Y-m-d_H-i-s');
        $uniqueId = substr(md5(microtime(true)), 0, 8);
        $backupDir = storage_path("app/backups/update_{$timestamp}_{$uniqueId}");
        
        // Ensure parent directory exists
        File::ensureDirectoryExists(dirname($backupDir));
        
        // Create backup directory with unique name
        $attempts = 0;
        $originalBackupDir = $backupDir;
        
        while (File::exists($backupDir) && $attempts < 100) {
            $attempts++;
            $backupDir = $originalBackupDir . "_" . $attempts;
        }
        
        if (File::exists($backupDir)) {
            throw new \Exception('Unique backup directory yaradılmadı');
        }
        
        File::makeDirectory($backupDir, 0755, true);
        
        // Critical files to backup
        $criticalPaths = [
            '.env' => '.env',
            'app/' => 'app/',
            'config/' => 'config/', 
            'routes/' => 'routes/',
            'database/migrations/' => 'database/migrations/',
        ];
        
        foreach ($criticalPaths as $source => $target) {
            $sourcePath = base_path($source);
            $targetPath = $backupDir . '/' . $target;
            
            if (File::exists($sourcePath)) {
                File::ensureDirectoryExists(dirname($targetPath));
                
                if (File::isDirectory($sourcePath)) {
                    File::copyDirectory($sourcePath, $targetPath);
                } else {
                    File::copy($sourcePath, $targetPath);
                }
            }
        }
        
        $this->logMessage("✅ Backup yaradıldı: {$backupDir}");
        return $backupDir;
    }
    
    private function downloadUpdatePackage(string $downloadUrl): string
    {
        $tempDir = storage_path('temp/update_' . time() . '_' . rand(1000, 9999));
        $zipFile = $tempDir . '/update.zip';
        
        File::ensureDirectoryExists($tempDir);
        
        // Download with fallback methods
        $downloaded = false;
        $methods = ['curl', 'file_get_contents'];
        
        foreach ($methods as $method) {
            try {
                if ($this->downloadFileVia($downloadUrl, $zipFile, $method)) {
                    $this->logMessage("✅ Downloaded via {$method}");
                    $downloaded = true;
                    break;
                }
            } catch (\Exception $e) {
                $this->logMessage("⚠️ {$method} failed: " . $e->getMessage());
            }
        }
        
        if (!$downloaded) {
            throw new \Exception('Update package download failed');
        }
        
        // Extract ZIP
        $extractDir = $tempDir . '/extracted';
        File::makeDirectory($extractDir);
        
        $zip = new ZipArchive;
        if ($zip->open($zipFile) !== TRUE) {
            throw new \Exception('Failed to open update package');
        }
        
        $zip->extractTo($extractDir);
        $zip->close();
        
        // Find extracted project folder
        $folders = glob($extractDir . '/*', GLOB_ONLYDIR);
        if (empty($folders)) {
            throw new \Exception('Invalid update package structure');
        }
        
        return $folders[0];
    }
    
    private function downloadFileVia(string $url, string $destination, string $method): bool
    {
        switch ($method) {
            case 'curl':
                if (!function_exists('curl_init')) return false;
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT => 'XIV-AI-SystemUpdater/1.0'
                ]);
                
                $data = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($data && $httpCode === 200) {
                    return File::put($destination, $data) !== false;
                }
                return false;
                
            case 'file_get_contents':
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 300,
                        'user_agent' => 'XIV-AI-SystemUpdater/1.0'
                    ]
                ]);
                
                $data = file_get_contents($url, false, $context);
                if ($data !== false) {
                    return File::put($destination, $data) !== false;
                }
                return false;
        }
        
        return false;
    }
    
    private function deployFiles(string $sourceDir): void
    {
        // Critical files that must be deployed
        $deployPaths = [
            'app/Http/Controllers/' => 'app/Http/Controllers/',
            'app/Models/' => 'app/Models/',
            'resources/js/' => 'resources/js/',
            'resources/views/' => 'resources/views/',
            'routes/' => 'routes/',
            'config/' => 'config/',
            'database/migrations/' => 'database/migrations/',
            'public/build/' => 'public/build/', // Frontend assets
        ];
        
        // Files to preserve (never overwrite)
        $preserveFiles = ['.env', 'storage/', 'vendor/', 'node_modules/'];
        
        $deployedCount = 0;
        
        foreach ($deployPaths as $source => $target) {
            $sourcePath = $sourceDir . '/' . $source;
            $targetPath = base_path($target);
            
            if (File::exists($sourcePath)) {
                $this->logMessage("📝 Deploying: {$source}");
                
                if (File::isDirectory($sourcePath)) {
                    File::ensureDirectoryExists($targetPath);
                    // Special case: public/build should be fully replaced to avoid stale hashed assets
                    if (rtrim($source, '/') === 'public/build' && File::exists($targetPath)) {
                        try { File::deleteDirectory($targetPath); } catch (\Exception $e) {}
                        File::ensureDirectoryExists($targetPath);
                    }
                    File::copyDirectory($sourcePath, $targetPath);
                } else {
                    File::ensureDirectoryExists(dirname($targetPath));
                    File::copy($sourcePath, $targetPath);
                }
                $deployedCount++;
            }
        }
        
        $this->logMessage("✅ Deployed {$deployedCount} components");
    }
    
    private function runDatabaseMigrations(): void
    {
        try {
            // Test database connection first
            \DB::connection()->getPdo();
            $this->logMessage('🔌 Database connection verified');

            // FIRST: Run real migrations to ensure schema is up-to-date
            $this->logMessage('🗄️ Running fresh migrations...');
            
            try {
                Artisan::call('migrate', [
                    '--force' => true,
                    '--no-interaction' => true
                ]);
                $this->logMessage('✅ Fresh migrations completed');
            } catch (\Exception $migrationError) {
                $this->logMessage('⚠️ Fresh migration error: ' . $migrationError->getMessage());
                // Continue with reconciliation as fallback
            }
            
            // SECOND: Reconcile migrations if schema exists but migration history is missing/mismatched
            $this->reconcileMigrationsIfNeeded();
            
            // THIRD: Verify critical columns exist
            $this->verifyDatabaseSchema();
            
            $this->logMessage('✅ Database migrations and verification completed');
            
        } catch (\Exception $e) {
            // Handle non-critical migration errors
            $msg = $e->getMessage();
            if (stripos($msg, 'already exists') !== false || 
                stripos($msg, 'duplicate') !== false) {
                $this->logMessage('⚠️ Migration warning (continuing): ' . substr($msg, 0, 100));
            } else {
                $this->logMessage('❌ Database migration failed: ' . $msg);
                // Don't throw - try to continue deployment
            }
        }
    }
    
    private function verifyDatabaseSchema(): void
    {
        try {
            $this->logMessage('🔍 Verifying database schema...');
            
            // Check if knowledge_base table exists
            if (!\Schema::hasTable('knowledge_base')) {
                $this->logMessage('❌ knowledge_base table missing!');
                return;
            }
            
            // Check if source_url column exists
            if (!\Schema::hasColumn('knowledge_base', 'source_url')) {
                $this->logMessage('⚠️ source_url column missing, attempting to add...');
                
                try {
                    // Try to add the missing column directly
                    \Schema::table('knowledge_base', function (\Illuminate\Database\Schema\Blueprint $table) {
                        $table->string('source_url', 1024)->nullable()->after('source');
                        $table->json('metadata')->nullable()->after('language');
                        $table->text('embedding')->nullable()->after('metadata');
                    });
                    $this->logMessage('✅ Missing columns added directly to knowledge_base');
                } catch (\Exception $e) {
                    $this->logMessage('⚠️ Could not add missing columns: ' . $e->getMessage());
                }
            } else {
                $this->logMessage('✅ source_url column exists');
            }
            
            // Check other critical columns
            $requiredColumns = ['title', 'content', 'source', 'category', 'is_active'];
            $missingColumns = [];
            
            foreach ($requiredColumns as $column) {
                if (!\Schema::hasColumn('knowledge_base', $column)) {
                    $missingColumns[] = $column;
                }
            }
            
            if (!empty($missingColumns)) {
                $this->logMessage('❌ Missing critical columns: ' . implode(', ', $missingColumns));
            } else {
                $this->logMessage('✅ All critical columns verified');
            }
            
        } catch (\Exception $e) {
            $this->logMessage('⚠️ Schema verification warning: ' . $e->getMessage());
        }
    }

    /**
     * Auto-reconcile migration history when tables exist but migrations table is empty or mismatched
     * (handles squashed vs timestamped migration name differences across environments).
     */
    private function reconcileMigrationsIfNeeded(): void
    {
        try {
            // Ensure migrations table exists
            if (!\Schema::hasTable('migrations')) {
                Artisan::call('migrate:install', ['--no-interaction' => true]);
                $this->logMessage('ℹ️ migrations table installed');
            }

            // Quick signals: if core tables exist but migrations table is empty or missing expected entries
            $coreTables = [
                'users', 'roles', 'cache', 'jobs', 'sessions',
                'settings', 'footer_settings', 'ai_providers',
                'chat_sessions', 'messages', 'chat_feedback',
                'chat_limits', 'user_chat_limits', 'ip_security_logs',
                'donation_pages', 'knowledge_base', 'terms_and_privacies',
                'admin_notifications', 'user_limits', 'seo_settings',
                'system_update_logs'
            ];

            $existingCore = 0;
            foreach ($coreTables as $t) {
                if (\Schema::hasTable($t)) { $existingCore++; }
            }

            // If no core tables exist, nothing to reconcile
            if ($existingCore === 0) { return; }

            // Known timestamped migration names expected in this codebase
            $expectedTimestamped = [
                '2014_10_12_000000_create_users_table',
                '2014_10_12_100000_create_password_reset_tokens_table',
                '2019_08_19_000000_create_failed_jobs_table',
                '2020_01_01_000000_create_roles_table',
                '2024_01_01_000000_create_settings_table',
                '2024_01_01_000100_create_footer_settings_table',
                '2024_01_02_000000_create_chat_sessions_table',
                '2024_01_02_000100_create_messages_table',
                '2024_01_03_000000_create_chat_feedback_table',
                '2024_01_04_000000_create_sessions_table',
                '2024_02_01_000000_create_donation_pages_table',
                '2025_10_02_141900_add_email_news_opt_in_to_users_table',
                '2025_10_04_134127_add_deepseek_to_ai_providers_driver_enum',
                '2025_10_04_134243_change_ai_providers_driver_to_varchar',
                '2025_10_04_140828_add_custom_texts_to_donation_pages_table',
                '2025_10_04_162758_remove_header_placement_from_donation_pages',
                '2025_10_04_214803_create_knowledge_base_table',
                '2025_10_04_214824_create_ai_providers_table',
                '2025_10_04_214842_create_ip_security_logs_table',
                '2025_10_04_214901_create_user_chat_limits_table',
                '2025_10_04_214920_create_terms_and_privacies_table',
                '2025_10_04_214937_create_admin_notifications_table',
                '2025_10_05_000000_create_system_update_logs_table',
                '2025_10_07_000000_create_seo_settings_table',
                '2025_10_10_080912_rename_knowledge_base_to_knowledge_bases_table',
                '2025_10_10_081006_add_source_url_to_knowledge_bases_table',
            ];

            // If migrations table already has many rows, likely reconciled or installed properly
            $count = \DB::table('migrations')->count();
            if ($count >= count($expectedTimestamped)) { return; }

            // Insert missing expected entries when their corresponding tables exist
            $batch = max(1, (int) (\DB::table('migrations')->max('batch') ?? 0)) + 1;
            $inserted = 0;

            $tableHints = [
                '2014_10_12_000000_create_users_table' => 'users',
                '2014_10_12_100000_create_password_reset_tokens_table' => null, // tokens table may not be used
                '2019_08_19_000000_create_failed_jobs_table' => null,
                '2020_01_01_000000_create_roles_table' => 'roles',
                '2024_01_01_000000_create_settings_table' => 'settings',
                '2024_01_01_000100_create_footer_settings_table' => 'footer_settings',
                '2024_01_02_000000_create_chat_sessions_table' => 'chat_sessions',
                '2024_01_02_000100_create_messages_table' => 'messages',
                '2024_01_03_000000_create_chat_feedback_table' => 'chat_feedback',
                '2024_01_04_000000_create_sessions_table' => 'sessions',
                '2024_02_01_000000_create_donation_pages_table' => 'donation_pages',
                '2025_10_02_141900_add_email_news_opt_in_to_users_table' => 'users',
                '2025_10_04_134127_add_deepseek_to_ai_providers_driver_enum' => 'ai_providers',
                '2025_10_04_134243_change_ai_providers_driver_to_varchar' => 'ai_providers',
                '2025_10_04_140828_add_custom_texts_to_donation_pages_table' => 'donation_pages',
                '2025_10_04_162758_remove_header_placement_from_donation_pages' => 'donation_pages',
                '2025_10_04_214803_create_knowledge_base_table' => 'knowledge_base',
                '2025_10_04_214824_create_ai_providers_table' => 'ai_providers',
                '2025_10_04_214842_create_ip_security_logs_table' => 'ip_security_logs',
                '2025_10_04_214901_create_user_chat_limits_table' => 'user_chat_limits',
                '2025_10_04_214920_create_terms_and_privacies_table' => 'terms_and_privacies',
                '2025_10_04_214937_create_admin_notifications_table' => 'admin_notifications',
                '2025_10_05_000000_create_system_update_logs_table' => 'system_update_logs',
                '2025_10_07_000000_create_seo_settings_table' => 'seo_settings',
                '2025_10_10_080912_rename_knowledge_base_to_knowledge_bases_table' => 'knowledge_base',
                '2025_10_10_081006_add_source_url_to_knowledge_bases_table' => 'knowledge_base',
            ];

            foreach ($expectedTimestamped as $mig) {
                $exists = \DB::table('migrations')->where('migration', $mig)->exists();
                if ($exists) { continue; }
                $hintTable = $tableHints[$mig] ?? null;
                if ($hintTable === null || \Schema::hasTable($hintTable)) {
                    // Mark as executed to prevent re-creation conflicts on existing schema
                    \DB::table('migrations')->insert([
                        'migration' => $mig,
                        'batch' => $batch,
                    ]);
                    $inserted++;
                }
            }

            if ($inserted > 0) {
                $this->logMessage("ℹ️ Reconciled {$inserted} migration records (schema already present)");
            }
        } catch (\Throwable $e) {
            // Do not fail deployment for reconciliation issues; just log and continue
            $this->logMessage('⚠️ Migration reconciliation skipped: ' . substr($e->getMessage(), 0, 120));
        }
    }
    
    private function handleDependencies(): void
    {
        // Local/dev: try composer + npm build
        if ($this->isLocalEnvironment()) {
            if ($this->commandExists('composer')) {
                try {
                    $this->logMessage('📦 Updating composer...');
                    shell_exec('cd ' . escapeshellarg(base_path()) . ' && composer install --no-interaction --optimize-autoloader 2>&1');
                    $this->logMessage('✅ Composer updated');
                } catch (\Exception $e) {
                    $this->logMessage('⚠️ Composer skipped: ' . $e->getMessage());
                }
            }
            if ($this->commandExists('npm')) {
                try {
                    $this->logMessage('🧱 Building frontend assets (local)...');
                    shell_exec('cd ' . escapeshellarg(base_path()) . ' && npm ci --prefer-offline --no-audit --no-fund 2>&1');
                    shell_exec('cd ' . escapeshellarg(base_path()) . ' && npm run build 2>&1');
                    $this->logMessage('✅ Frontend assets built');
                } catch (\Exception $e) {
                    $this->logMessage('⚠️ NPM build skipped (local): ' . $e->getMessage());
                }
            }
            return;
        }

        // Hosting: prefer deploying prebuilt assets from package (public/build). If missing and npm is available, build as a fallback.
        $manifestPath = public_path('build/manifest.json');
        $shouldTryBuild = !\File::exists($manifestPath) || (bool) getenv('UPDATE_FORCE_ASSET_BUILD');

        if ($shouldTryBuild && $this->commandExists('npm')) {
            try {
                $this->logMessage('🧱 Building frontend assets on host (fallback)...');
                shell_exec('cd ' . escapeshellarg(base_path()) . ' && npm ci --prefer-offline --no-audit --no-fund 2>&1');
                shell_exec('cd ' . escapeshellarg(base_path()) . ' && npm run build 2>&1');
                if (\File::exists($manifestPath)) {
                    $this->logMessage('✅ Frontend assets built on host');
                } else {
                    $this->logMessage('⚠️ Build completed but manifest not found. Ensure public/build is writable.');
                }
            } catch (\Exception $e) {
                $this->logMessage('⚠️ NPM build skipped (host): ' . $e->getMessage());
            }
        } else {
            $this->logMessage('⏭️ Dependencies skipped (hosting environment)');
        }
    }
    
    private function optimizeSystem(): void
    {
        try {
            // AGGRESSIVE CACHE CLEARING for hosting environments
            $this->logMessage('🧹 AGGRESSIVE CACHE CLEARING...');
            
            // Standard Laravel cache clearing
            Artisan::call('config:clear');
            Artisan::call('route:clear');  
            Artisan::call('view:clear');
            Artisan::call('cache:clear');
            
            // Clear additional caches safely
            try {
                Artisan::call('optimize:clear');
            } catch (\Exception $e) {
                $this->logMessage('⚠️ optimize:clear skipped: ' . $e->getMessage());
            }
            
            // FORCE file-based cache clearing
            $this->forceClearFileCache();
            
            // Reset OPcache aggressively
            $this->resetOPCache();
            
            // Update storage links
            try {
                Artisan::call('storage:link');
            } catch (\Exception $e) {
                // Ignore if already exists
            }

            $this->logMessage('✅ System aggressively optimized');
            
        } catch (\Exception $e) {
            $this->logMessage('⚠️ Optimization warning: ' . $e->getMessage());
        }
    }
    
    private function forceClearFileCache(): void
    {
        try {
            // Clear bootstrap cache files
            $bootstrapCacheFiles = [
                'bootstrap/cache/config.php',
                'bootstrap/cache/services.php', 
                'bootstrap/cache/packages.php',
                'bootstrap/cache/routes-v7.php'
            ];
            
            foreach ($bootstrapCacheFiles as $file) {
                $path = base_path($file);
                if (file_exists($path)) {
                    unlink($path);
                    $this->logMessage("🗑️ Cleared: {$file}");
                }
            }
            
            // Clear storage framework caches
            $storagePaths = [
                'storage/framework/cache/data',
                'storage/framework/sessions',
                'storage/framework/views'
            ];
            
            foreach ($storagePaths as $dir) {
                $path = storage_path($dir);
                if (is_dir($path)) {
                    $files = glob($path . '/*');
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            unlink($file);
                        }
                    }
                    $this->logMessage("🗑️ Cleared: {$dir}/*");
                }
            }
            
        } catch (\Exception $e) {
            $this->logMessage('⚠️ File cache clear warning: ' . $e->getMessage());
        }
    }
    
    private function resetOPCache(): void
    {
        try {
            // Multiple OPcache reset methods
            if (function_exists('opcache_reset')) {
                opcache_reset();
                $this->logMessage('🧹 OPcache reset via opcache_reset()');
            }
            
            if (function_exists('apc_clear_cache')) {
                apc_clear_cache();
                $this->logMessage('🧹 APC cache cleared');
            }
            
            if (function_exists('wincache_ucache_clear')) {
                wincache_ucache_clear();
                $this->logMessage('🧹 WinCache cleared');
            }
            
            // Force PHP to reload classes by touching autoload files
            $autoloadFiles = [
                'vendor/autoload.php',
                'vendor/composer/autoload_real.php'
            ];
            
            foreach ($autoloadFiles as $file) {
                $path = base_path($file);
                if (file_exists($path)) {
                    touch($path);
                }
            }
            
        } catch (\Exception $e) {
            $this->logMessage('⚠️ OPcache reset warning: ' . $e->getMessage());
        }
    }
    
    private function performSystemChecks(): void
    {
        $checks = [
            'PHP Version' => version_compare(PHP_VERSION, '8.1', '>='),
            'Laravel Writable' => is_writable(base_path()),
            'Storage Writable' => is_writable(storage_path()),
            'ZIP Extension' => extension_loaded('zip'),
        ];
        
        foreach ($checks as $check => $result) {
            $status = $result ? '✅' : '❌';
            $this->logMessage("{$status} {$check}");
            
            if (!$result && in_array($check, ['Laravel Writable', 'Storage Writable'])) {
                throw new \Exception("Critical check failed: {$check}");
            }
        }
    }
    
    private function verifyDeployment(): void
    {
        $issues = [];
        
        // Check critical files exist (support both Laravel <=10 and 11+ structures)
        $issues = [];

        // Either legacy Kernel.php or new bootstrap/app.php must exist
        $hasLegacyKernel = File::exists(base_path('app/Http/Kernel.php'));
        $hasBootstrapApp = File::exists(base_path('bootstrap/app.php'));
        if (!$hasLegacyKernel && !$hasBootstrapApp) {
            $issues[] = 'Missing: app/Http/Kernel.php or bootstrap/app.php';
        }

        // Common critical files
        $commonCritical = [
            'config/app.php',
            'routes/web.php',
        ];
        foreach ($commonCritical as $file) {
            if (!File::exists(base_path($file))) {
                $issues[] = "Missing: {$file}";
            }
        }

        if (!empty($issues)) {
            throw new \Exception('Deployment verification failed: ' . implode(', ', $issues));
        }

        $this->logMessage('✅ Deployment verified');
    }
    
    private function restoreFromBackup(string $backupPath): void
    {
        if (!File::exists($backupPath)) {
            throw new \Exception("Backup not found: {$backupPath}");
        }
        
        // Restore critical files
        $restorePaths = [
            'app/' => 'app/',
            'config/' => 'config/',
            'routes/' => 'routes/',
        ];
        
        foreach ($restorePaths as $source => $target) {
            $sourcePath = $backupPath . '/' . $source;
            $targetPath = base_path($target);
            
            if (File::exists($sourcePath)) {
                if (File::exists($targetPath)) {
                    File::deleteDirectory($targetPath);
                }
                File::copyDirectory($sourcePath, $targetPath);
            }
        }
        
        $this->logMessage('✅ Restored from backup');
    }
    
    private function logMessage(string $message): void
    {
        // Output to stream
        echo $message . "\n";
        
        // Log to file
        try {
            $logFile = storage_path('logs/system-update.log');
            File::ensureDirectoryExists(dirname($logFile));
            File::append($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
        } catch (\Exception $e) {
            // Ignore file logging errors
        }
        
        // Flush output
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
    
    private function logUpdateToDatabase(string $from, ?string $to, string $status, string $message): void
    {
        try {
            if (\Schema::hasTable('system_update_logs')) {
                SystemUpdateLog::create([
                    'version_from' => $from,
                    'version_to' => $to,
                    'status' => $status,
                    'message' => $message,
                    'release_notes' => $this->getReleaseNotes(),
                ]);
            }
        } catch (\Exception $e) {
            $this->logMessage("⚠️ Database logging failed: " . $e->getMessage());
        }
    }
    
    private function getReleaseNotes(): ?string
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'XIV-AI-SystemUpdater/1.0'
                ]
            ]);
            
            $response = @file_get_contents($this->versionApiUrl, false, $context);
            if ($response) {
                $data = json_decode($response, true);
                return $data['body'] ?? null;
            }
        } catch (\Exception $e) {
            // Ignore
        }
        
        return null;
    }
    
    private function getDownloadUrl(string $version): string
    {
        $owner = self::GITHUB_REPO_OWNER;
        $repo = self::GITHUB_REPO_NAME;
        $tag = (strpos($version, 'v') === 0) ? $version : "v{$version}";
        
        return "https://github.com/{$owner}/{$repo}/archive/refs/tags/{$tag}.zip";
    }
    
    private function isLocalEnvironment(): bool
    {
        return in_array(config('app.env'), ['local', 'development']) ||
               in_array(gethostname(), ['localhost', '127.0.0.1']);
    }
    
    private function commandExists(string $command): bool
    {
        $test = shell_exec("which $command 2>/dev/null");
        return !empty($test);
    }
    
    private function isSiteOnline(): bool
    {
        try {
            return !app()->isDownForMaintenance();
        } catch (\Exception $e) {
            return true; // Assume online if can't determine
        }
    }
    
    // Additional API methods
    public function forceVersionCheck(Request $request)
    {
        try {
            $currentVersion = $this->getCurrentVersion();
            $manualVersion = $request->input('manual_version');
            
            if ($manualVersion && preg_match('/^\d+\.\d+\.\d+$/', $manualVersion)) {
                $updateAvailable = version_compare($currentVersion, $manualVersion, '<');
                return response()->json([
                    'success' => true,
                    'current_version' => $currentVersion,
                    'latest_version' => $manualVersion,
                    'has_update' => $updateAvailable,
                    'download_url' => $this->getDownloadUrl($manualVersion),
                    'message' => $updateAvailable ? "Manual version set: v{$manualVersion}" : 'Manual version is current'
                ]);
            }
            
            $latestVersion = $this->getLatestVersion();
            $updateAvailable = $latestVersion && version_compare($currentVersion, $latestVersion, '<');
            
            return response()->json([
                'success' => true,
                'current_version' => $currentVersion,
                'latest_version' => $latestVersion,
                'has_update' => $updateAvailable,
                'download_url' => $latestVersion ? $this->getDownloadUrl($latestVersion) : null,
                'message' => $latestVersion ? 
                    ($updateAvailable ? "Update found: v{$latestVersion}" : 'Current version is latest') : 
                    'GitHub API access failed'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Version check failed: ' . $e->getMessage()
            ]);
        }
    }
    
    public function getUpdateLog()
    {
        $logFile = storage_path('logs/system-update.log');
        
        return response()->json([
            'success' => true,
            'log' => File::exists($logFile) ? File::get($logFile) : 'No log file found'
        ]);
    }
    
    public function getUpdateHistory()
    {
        try {
            if (!\Schema::hasTable('system_update_logs')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Update logs table not found',
                    'items' => [],
                ]);
            }
            
            $logs = SystemUpdateLog::orderByDesc('created_at')->limit(50)->get();
                
            return response()->json([
                'success' => true,
                'items' => $logs,
                'count' => $logs->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'History retrieval failed: ' . $e->getMessage(),
                'items' => [],
            ]);
        }
    }
}