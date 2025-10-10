<?php
/**
 * XIV AI - Setup Wizard
 * Versiya 1.0 • Müəllif: DeXIV
 */

// Check if system is already installed
function checkIfInstalled() {
    $basePath = dirname(__DIR__);
    $lockFile = $basePath . '/storage/installed.lock';
    
    // First check: lock file exists
    if (file_exists($lockFile)) {
        return ['installed' => true, 'method' => 'lock_file', 'lock_content' => file_get_contents($lockFile)];
    }
    
    // Second check: try to connect to database and check admin user
    $envPath = $basePath . '/.env';
    if (file_exists($envPath)) {
        $envContent = file_get_contents($envPath);
        if (preg_match('/DB_HOST\s*=\s*(.+)/m', $envContent, $hostMatch) &&
            preg_match('/DB_DATABASE\s*=\s*(.+)/m', $envContent, $dbMatch) &&
            preg_match('/DB_USERNAME\s*=\s*(.+)/m', $envContent, $userMatch)) {
            
            $host = trim($hostMatch[1]);
            $dbname = trim($dbMatch[1]);
            $username = trim($userMatch[1]);
            $password = '';
            
            if (preg_match('/DB_PASSWORD\s*=\s*"?(.*)"?/m', $envContent, $passMatch)) {
                $password = trim($passMatch[1], '"');
            }
            
            try {
                $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $username, $password);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Check if admin user exists
                $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = 'admin@dexiv.me' OR role_id = 1 LIMIT 1");
                $stmt->execute();
                $adminUser = $stmt->fetch();
                
                if ($adminUser) {
                    return ['installed' => true, 'method' => 'admin_user_exists', 'admin_user' => $adminUser];
                }
            } catch (Exception $e) {
                // Database connection failed, probably not installed
            }
        }
    }
    
    return ['installed' => false];
}

// Check installation status for GET requests only
$installStatus = checkIfInstalled();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $installStatus['installed']) {
    // System is already installed, show message and redirect
    if (!isset($_GET['force'])) {
        echo '<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XIV AI - Artıq Quraşdırılmış</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-50 via-white to-emerald-50 flex items-center justify-center">
    <div class="bg-white/90 backdrop-blur rounded-2xl shadow-2xl p-8 max-w-md w-full border border-gray-200 text-center">
        <div class="text-6xl mb-4">🎉</div>
        <h1 class="text-2xl font-bold text-gray-800 mb-4">XIV AI artıq quraşdırılmış!</h1>
        <p class="text-gray-600 mb-6">Sistem artıq quraşdırılmış və istifadəyə hazırdır.</p>
        <div class="space-y-3">
            <a href="../" class="block w-full bg-emerald-600 hover:bg-emerald-700 text-white py-3 px-4 rounded-xl transition-colors">
                Ana səhifəyə keç
            </a>
            <a href="../admin" class="block w-full bg-blue-600 hover:bg-blue-700 text-white py-3 px-4 rounded-xl transition-colors">
                Admin panelinə keç
            </a>
        </div>
        <div class="mt-6 text-xs text-gray-500">
            Quraşdırılma tarixi: ' . (isset($installStatus['lock_content']) ? $installStatus['lock_content'] : 'Naməlum') . '<br>
            Metodla: ' . $installStatus['method'] . '
        </div>
        <p class="text-xs text-gray-400 mt-4">
            Yenidən quraşdırmaq istəyirsinizsə? <a href="?force=1" class="text-red-500 hover:underline">Məcburi quraşdırma</a>
        </p>
    </div>
</body>
</html>';
        exit;
    } elseif ($_GET['force'] === '1') {
        // Force install requested, show warning
        echo '<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XIV AI - Məcburi Quraşdırma</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-red-50 via-white to-orange-50 flex items-center justify-center">
    <div class="bg-white/90 backdrop-blur rounded-2xl shadow-2xl p-8 max-w-lg w-full border border-red-200 text-center">
        <div class="text-6xl mb-4">⚠️</div>
        <h1 class="text-2xl font-bold text-red-800 mb-4">Xəbərdarlıq: Məcburi Yenidən Quraşdırma</h1>
        <p class="text-red-600 mb-6">Bu sistem artıq quraşdırılmışdır. Yenidən quraşdırmaq bütün məlumatları silə bilər!</p>
        <div class="space-y-3">
            <a href="../" class="block w-full bg-gray-600 hover:bg-gray-700 text-white py-3 px-4 rounded-xl transition-colors">
                Təhlükəsiz yol: Ana səhifəyə qayıt
            </a>
            <a href="?force=confirm" class="block w-full bg-red-600 hover:bg-red-700 text-white py-3 px-4 rounded-xl transition-colors">
                Yenə də davam et (Təhlükəli)
            </a>
        </div>
    </div>
</body>
</html>';
        exit;
    }
    // If force=confirm, continue with installation (no exit)
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'check_requirements':
            echo json_encode(checkRequirements());
            break;
            
        case 'test_database':
            echo json_encode(testDatabase($_POST));
            break;
            
        case 'install':
            echo json_encode(performInstallation($_POST));
            break;
        
        case 'post_install_fix':
            echo json_encode(performPostInstallFix());
            break;
    }
    exit;
}

function checkRequirements() {
    $requirements = [
        'php_version' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'openssl' => extension_loaded('openssl'),
        'pdo' => extension_loaded('pdo'),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'mbstring' => extension_loaded('mbstring'),
        'tokenizer' => extension_loaded('tokenizer'),
        'xml' => extension_loaded('xml'),
        'ctype' => extension_loaded('ctype'),
        'json' => extension_loaded('json'),
        'curl' => extension_loaded('curl'),
        'storage_writable' => is_writable(dirname(__DIR__) . '/storage'),
        'bootstrap_writable' => is_writable(dirname(__DIR__) . '/bootstrap/cache'),
        'env_writable' => is_writable(dirname(__DIR__)) || !file_exists(dirname(__DIR__) . '/.env'),
    ];
    
    $allPassed = true;
    foreach ($requirements as $req) {
        if (!$req) {
            $allPassed = false;
            break;
        }
    }
    
    return ['success' => $allPassed, 'requirements' => $requirements];
}

function normalizeDbCreds(array $data): array {
    $host = $data['db_host'] ?? '127.0.0.1';
    $port = $data['db_port'] ?? '3306';
    $name = $data['db_database'] ?? ($data['db_name'] ?? '');
    // Username: default to root if empty/missing
    $user = isset($data['db_username']) && $data['db_username'] !== '' ? $data['db_username'] : 'root';
    // Password: FORCE empty string when missing/empty (important for XAMPP)
    $pass = isset($data['db_password']) && $data['db_password'] !== null ? (string)$data['db_password'] : '';
    return [$host, $port, $name, $user, $pass];
}

function testDatabase($data) {
    try {
        list($host, $port, $dbname, $user, $password) = normalizeDbCreds($data);
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function performInstallation($data) {
    try {
        $basePath = dirname(__DIR__);
        
        // Check if this is a forced installation
        $isForceInstall = isset($_GET['force']) && $_GET['force'] === 'confirm';
        
        // If not forced install, check if already installed
        if (!$isForceInstall) {
            $installStatus = checkIfInstalled();
            if ($installStatus['installed']) {
                throw new Exception('Sistem artıq quraşdırılmışdır. Məcburi quraşdırma üçün ?force=confirm parametrini istifadə edin.');
            }
        }
        
        // 1. Create .env file
        $envContent = generateEnvContent($data);
        if (!file_put_contents($basePath . '/.env', $envContent)) {
            throw new Exception('.env faylı yaradıla bilmədi');
        }
        
        // DB bağlantısı (XAMPP üçün şifrəsiz istifadəyə icazə ver)
        list($host, $port, $dbname, $user, $password) = normalizeDbCreds($data);
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // 3. Run all migrations
        runAllMigrations($pdo);
        
        // 4. Create admin user
        $adminResult = createAdminUser($pdo, $data);
        
        // 5. Seed default data
        seedDefaultData($pdo);
        
        // 6. Set permissions
        setDirectoryPermissions($basePath);
        
        // 7. Create install lock
        $lockContent = date('Y-m-d H:i:s');
        if (!file_put_contents($basePath . '/storage/installed.lock', $lockContent)) {
            throw new Exception('Quraşdırma kilid faylı yaradıla bilmədi');
        }
        
        // 8. Post-install fix inline (APP_KEY, cache cleanup)
        $postFixResult = performPostInstallFix();
        if (!$postFixResult['success']) {
            // Don't fail installation for post-fix issues, just log
            error_log('Post-install fix warning: ' . $postFixResult['error']);
        }
        
        return [
            'success' => true, 
            'message' => 'Quraşdırma uğurla tamamlandı!',
            'admin_status' => $adminResult,
            'lock_file' => $lockContent
        ];
        
    } catch (Exception $e) {
        // Detailed error logging
        $errorDetails = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
        
        // Log to file for debugging
        error_log('Installation Error: ' . json_encode($errorDetails));
        
        return [
            'success' => false, 
            'error' => 'Quraşdırma xətası: ' . $e->getMessage(),
            'debug_info' => $errorDetails
        ];
    }
}

function generateEnvContent($data) {
    $appKey = 'base64:' . base64_encode(random_bytes(32));
    
    return "APP_NAME=\"{$data['app_name']}\"
APP_ENV=production
APP_KEY={$appKey}
APP_DEBUG=false
APP_TIMEZONE=UTC
APP_URL={$data['app_url']}

APP_LOCALE=az
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=single
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST={$data['db_host']}
DB_PORT={$data['db_port']}
DB_DATABASE={$data['db_database']}
DB_USERNAME=" . ((isset($data['db_username']) && $data['db_username'] !== '') ? $data['db_username'] : 'root') . "
DB_PASSWORD=\"" . ((isset($data['db_password']) && $data['db_password'] !== null) ? (string)$data['db_password'] : '') . "\"

SESSION_DRIVER=database
SESSION_LIFETIME=120
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=smtp
MAIL_HOST={$data['mail_host']}
MAIL_PORT={$data['mail_port']}
MAIL_USERNAME={$data['mail_username']}
MAIL_PASSWORD=\"{$data['mail_password']}\"
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=\"{$data['mail_username']}\"
MAIL_FROM_NAME=\"{$data['app_name']}\"

VITE_APP_NAME=\"{$data['app_name']}\"
";
}

function runAllMigrations($pdo) {
    // Create migrations table
    $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
        id int unsigned AUTO_INCREMENT PRIMARY KEY,
        migration varchar(255) NOT NULL,
        batch int NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $migrations = [
        // Core tables
        '0001_01_01_000000_create_users_table' => "
            CREATE TABLE IF NOT EXISTS users (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                name varchar(255) NOT NULL,
                email varchar(255) NOT NULL UNIQUE,
                email_verified_at timestamp NULL,
                password varchar(255) NOT NULL,
                remember_token varchar(100) NULL,
                role_id bigint unsigned NULL,
                registration_ip varchar(45) NULL,
                created_at timestamp NULL,
                updated_at timestamp NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        '0001_01_01_000001_create_roles_table' => "
            CREATE TABLE IF NOT EXISTS roles (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                name varchar(255) NOT NULL UNIQUE,
                created_at timestamp NULL,
                updated_at timestamp NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        '0001_01_01_000002_create_cache_table' => "
            CREATE TABLE IF NOT EXISTS cache (
                `key` varchar(255) PRIMARY KEY,
                value mediumtext NOT NULL,
                expiration int NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        '0001_01_01_000003_create_jobs_table' => "
            CREATE TABLE IF NOT EXISTS jobs (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                queue varchar(255) NOT NULL,
                payload longtext NOT NULL,
                attempts tinyint unsigned NOT NULL,
                reserved_at int unsigned NULL,
                available_at int unsigned NOT NULL,
                created_at int unsigned NOT NULL,
                INDEX jobs_queue_index (queue)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        '0001_01_01_000004_create_sessions_table' => "
            CREATE TABLE IF NOT EXISTS sessions (
                id varchar(255) PRIMARY KEY,
                user_id bigint unsigned NULL,
                ip_address varchar(45) NULL,
                user_agent text NULL,
                payload longtext NOT NULL,
                last_activity int NOT NULL,
                INDEX sessions_user_id_index (user_id),
                INDEX sessions_last_activity_index (last_activity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Settings tables (PK = key)
        '0002_01_01_000000_create_settings_table' => "
            CREATE TABLE IF NOT EXISTS settings (
                `key` varchar(255) NOT NULL PRIMARY KEY,
                value longtext NULL,
                created_at timestamp NULL,
                updated_at timestamp NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        '0002_01_01_000001_create_footer_settings_table' => "
            CREATE TABLE IF NOT EXISTS footer_settings (
                `key` varchar(255) NOT NULL PRIMARY KEY,
                value longtext NULL,
                created_at timestamp NULL,
                updated_at timestamp NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // AI providers
        '0003_01_01_000000_create_ai_providers_table' => "
            CREATE TABLE IF NOT EXISTS ai_providers (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                name varchar(255) NOT NULL,
                driver varchar(50) NOT NULL,
                model varchar(255) NULL,
                api_key longtext NULL,
                base_url varchar(255) NULL,
                is_active tinyint(1) DEFAULT 0,
                created_at timestamp NULL,
                updated_at timestamp NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Chat sessions and messages
        '0004_01_01_000000_create_chat_sessions_table' => "
            CREATE TABLE IF NOT EXISTS chat_sessions (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                session_id varchar(255) NOT NULL UNIQUE,
                user_id bigint unsigned NULL,
                ip_address varchar(45) NULL,
                title varchar(255) NULL,
                is_active tinyint(1) DEFAULT 1,
                created_at timestamp NULL,
                updated_at timestamp NULL,
                INDEX chat_sessions_user_id_index (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        '0004_01_01_000001_create_messages_table' => "
            CREATE TABLE IF NOT EXISTS messages (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                chat_session_id bigint unsigned NOT NULL,
                role varchar(50) NOT NULL,
                content longtext NOT NULL,
                tokens_used int DEFAULT 0,
                created_at timestamp NULL,
                updated_at timestamp NULL,
                INDEX messages_session_id_index (chat_session_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Feedback
        '0005_01_01_000000_create_chat_feedback_table' => "
            CREATE TABLE IF NOT EXISTS chat_feedback (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                session_id varchar(255) NULL,
                message_id bigint unsigned NULL,
                user_type varchar(20) NULL,
                user_name varchar(255) NULL,
                user_email varchar(255) NULL,
                feedback_type varchar(20) NULL,
                message_content longtext NULL,
                user_comment text NULL,
                ip_address varchar(45) NULL,
                user_agent text NULL,
                created_at timestamp NULL,
                updated_at timestamp NULL,
                INDEX chat_feedback_message_id_index (message_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Limits
        '0006_01_01_000000_create_chat_limits_table' => "
            CREATE TABLE IF NOT EXISTS chat_limits (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                identifier_type varchar(50) NOT NULL,
                identifier_value varchar(255) NOT NULL,
                message_count int DEFAULT 0,
                daily_limit int DEFAULT 0,
                monthly_limit int DEFAULT 0,
                last_reset_date date NULL,
                last_monthly_reset date NULL,
                created_at timestamp NULL,
                updated_at timestamp NULL,
                INDEX chat_limits_identifier_index (identifier_type, identifier_value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        '0006_01_01_000001_create_user_chat_limits_table' => "
            CREATE TABLE IF NOT EXISTS user_chat_limits (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                user_id bigint unsigned NOT NULL,
                daily_limit int DEFAULT 100,
                monthly_limit int DEFAULT 1000,
                unlimited_access tinyint(1) DEFAULT 0,
                created_at timestamp NULL,
                updated_at timestamp NULL,
                INDEX user_chat_limits_user_id_index (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // IP security logs
        '0007_01_01_000000_create_ip_security_logs_table' => "
            CREATE TABLE IF NOT EXISTS ip_security_logs (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                ip_address varchar(45) NOT NULL,
                existing_user_id bigint unsigned NULL,
                existing_user_email varchar(255) NULL,
                attempted_email varchar(255) NULL,
                attempted_name varchar(255) NULL,
                action_type varchar(50) NOT NULL,
                additional_data longtext NULL,
                is_resolved tinyint(1) DEFAULT 0,
                admin_notes longtext NULL,
                created_at timestamp NULL,
                updated_at timestamp NULL,
                INDEX ip_security_logs_ip_index (ip_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Donation pages
        '0008_01_01_000000_create_donation_pages_table' => "
            CREATE TABLE IF NOT EXISTS donation_pages (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                is_enabled tinyint(1) DEFAULT 0,
                title varchar(255) NULL,
                content longtext NULL,
                placement varchar(50) NULL,
                display_settings longtext NULL,
                payment_methods longtext NULL,
                custom_texts longtext NULL,
                created_at timestamp NULL,
                updated_at timestamp NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Knowledge base
        '0009_01_01_000000_create_knowledge_base_table' => "
            CREATE TABLE IF NOT EXISTS knowledge_base (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                title varchar(512) NOT NULL,
                content longtext NOT NULL,
                source varchar(255) NULL,
                source_url varchar(1024) NULL,
                category varchar(255) NULL,
                author varchar(255) NULL,
                language varchar(8) NULL,
                metadata longtext NULL,
                embedding longtext NULL,
                is_active tinyint(1) DEFAULT 1,
                created_at timestamp NULL,
                updated_at timestamp NULL,
                INDEX idx_kb_source_url (source_url(255))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Knowledge categories
        '0009_01_01_000001_create_knowledge_categories_table' => "
            CREATE TABLE IF NOT EXISTS knowledge_categories (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                name varchar(255) NOT NULL UNIQUE,
                description text NULL,
                color varchar(7) DEFAULT '#3B82F6',
                is_active tinyint(1) DEFAULT 1,
                sort_order int DEFAULT 0,
                created_at timestamp NULL,
                updated_at timestamp NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Terms & Privacy
        '0010_01_01_000000_create_terms_privacy_table' => "
            CREATE TABLE IF NOT EXISTS terms_and_privacies (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                type varchar(20) NOT NULL,
                title varchar(255) NOT NULL,
                content longtext NOT NULL,
                is_active tinyint(1) DEFAULT 1,
                created_at timestamp NULL,
                updated_at timestamp NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Admin notifications
        '0011_01_01_000000_create_admin_notifications_table' => "
            CREATE TABLE IF NOT EXISTS admin_notifications (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                type varchar(50) NULL,
                title varchar(255) NOT NULL,
                message longtext NULL,
                priority varchar(20) DEFAULT 'low',
                data longtext NULL,
                icon varchar(50) NULL,
                color varchar(20) NULL,
                is_read tinyint(1) DEFAULT 0,
                is_important tinyint(1) DEFAULT 0,
                expires_at timestamp NULL,
                created_at timestamp NULL,
                updated_at timestamp NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Usage tracking (UserLimit)
        '0012_01_01_000000_create_user_limits_table' => "
            CREATE TABLE IF NOT EXISTS user_limits (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                identifier varchar(255) NOT NULL,
                user_type varchar(20) NOT NULL,
                limit_type varchar(20) NOT NULL,
                message_count int DEFAULT 0,
                character_count int DEFAULT 0,
                period_date date NOT NULL,
                created_at timestamp NULL,
                updated_at timestamp NULL,
                INDEX user_limits_idx (identifier, user_type, limit_type, period_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // SEO Settings (v1.2.0)
        '0013_01_01_000000_create_seo_settings_table' => "
            CREATE TABLE IF NOT EXISTS seo_settings (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                page varchar(255) NOT NULL UNIQUE,
                title varchar(255) NULL,
                description text NULL,
                keywords text NULL,
                canonical_url varchar(500) NULL,
                og_title text NULL,
                og_description text NULL,
                og_image varchar(500) NULL,
                og_type varchar(50) DEFAULT 'website',
                twitter_title text NULL,
                twitter_description text NULL,
                twitter_image varchar(500) NULL,
                twitter_card varchar(50) DEFAULT 'summary_large_image',
                custom_meta text NULL,
                schema_markup text NULL,
                noindex tinyint(1) DEFAULT 0,
                nofollow tinyint(1) DEFAULT 0,
                created_at timestamp NULL,
                updated_at timestamp NULL,
                INDEX seo_settings_page_index (page)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // System Update Logs (v1.2.0)
        '0014_01_01_000000_create_system_update_logs_table' => "
            CREATE TABLE IF NOT EXISTS system_update_logs (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                version_from varchar(20) NULL,
                version_to varchar(20) NULL,
                status varchar(20) NOT NULL,
                message text NULL,
                release_notes text NULL,
                log_excerpt text NULL,
                created_at timestamp NULL,
                updated_at timestamp NULL,
                INDEX system_update_logs_status_index (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    $batch = 1;
    foreach ($migrations as $migration => $sql) {
        // Check if already run
        $stmt = $pdo->prepare("SELECT id FROM migrations WHERE migration = ?");
        $stmt->execute([$migration]);
        if (!$stmt->fetch()) {
            $pdo->exec($sql);
            $stmt = $pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
            $stmt->execute([$migration, $batch]);
        }
    }
}

function createAdminUser($pdo, $data) {
    // Create admin role
    $stmt = $pdo->prepare("INSERT INTO roles (name, created_at, updated_at) VALUES (?, NOW(), NOW()) ON DUPLICATE KEY UPDATE name = name");
    $stmt->execute(['admin']);
    
    $stmt = $pdo->prepare("INSERT INTO roles (name, created_at, updated_at) VALUES (?, NOW(), NOW()) ON DUPLICATE KEY UPDATE name = name");
    $stmt->execute(['user']);
    
    // Get admin role ID
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'admin'");
    $stmt->execute();
    $adminRoleId = $stmt->fetchColumn();
    
    // Check if admin user already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$data['admin_email']]);
    $existingAdmin = $stmt->fetch();
    
    if ($existingAdmin) {
        // Admin user already exists, update password if requested
        $hashedPassword = password_hash($data['admin_password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET name = ?, password = ?, role_id = ?, updated_at = NOW() WHERE email = ?");
        $stmt->execute([$data['admin_name'], $hashedPassword, $adminRoleId, $data['admin_email']]);
        return ['action' => 'updated', 'message' => 'Admin istifadəçi yeniləndi'];
    } else {
        // Create new admin user
        try {
            $hashedPassword = password_hash($data['admin_password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role_id, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([$data['admin_name'], $data['admin_email'], $hashedPassword, $adminRoleId]);
            return ['action' => 'created', 'message' => 'Admin istifadəçi yaradıldı'];
        } catch (PDOException $e) {
            // Handle duplicate key error (just in case)
            if ($e->getCode() == '23000') {
                // Try to update instead
                $stmt = $pdo->prepare("UPDATE users SET name = ?, password = ?, role_id = ?, updated_at = NOW() WHERE email = ?");
                $stmt->execute([$data['admin_name'], $hashedPassword, $adminRoleId, $data['admin_email']]);
                return ['action' => 'recovered', 'message' => 'Admin istifadəçi bərpa edildi'];
            }
            throw $e; // Re-throw if it's not a duplicate key error
        }
    }
}

function seedDefaultData($pdo) {
    // Default site settings (align with App\Models\Settings::getDefaults)
    $settings = [
        // Application Settings
        'app_version' => '1.0.0',
        'site_name' => 'XIV AI Chatbot Platform',
        'chatbot_name' => 'XIV AI',
        
        // Brand Settings
        'brand_mode' => 'icon',
        'brand_icon_name' => 'nav_chat',
        'brand_logo_url' => '',
        'favicon_url' => '',
        
        // Theme Settings
        'primary_color' => '#10b981',
        'secondary_color' => '#97a5a1',
        'accent_color' => '#fbbf24',
        'background_gradient' => 'linear-gradient(135deg, #10b981 0%, #065f46 100%)',
        'text_color' => '#1f2937',
        
        // Chat Settings
        'message_input_limit' => '500',
        'ai_output_limit' => '1000',
        'enter_sends_message' => '1',
        'ai_typing_speed' => '50',
        'ai_thinking_time' => '1000',
        'ai_response_type' => 'typewriter',
        'ai_use_knowledge_base' => '1',
        'ai_strict_mode' => '1',
        'ai_topic_restrictions' => '',
        'ai_internet_blocked' => '1',
        'ai_external_learning_blocked' => '1',
        'ai_super_strict_mode' => '0',
        'chat_disclaimer_text' => 'Çatbotun cavablarını yoxlayın, səhv edə bilər!',
        
        // Chat Background Settings
        'chat_background_type' => 'default',
        'chat_background_color' => '#f3f4f6',
        'chat_background_gradient' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
        'chat_background_image' => '',
        
        // Footer Settings
        'footer_enabled' => '1',
        'footer_text' => '© 2025 XIV AI. Bütün hüquqlar qorunur.',
        'footer_text_color' => '#6B7280',
        'footer_author_text' => 'Developed by DeXIV',
        'footer_author_color' => '#6B7280',
        
        // AI System Prompt
        'ai_system_prompt' => 'Sen XIV AI adlı Azərbaycan dilində cavab verən köməkçi süni zəka sistemisiniz. İstifadəçilərə faydalı, dəqiq və təhlükəsiz cavablar ver.',
    ];

    foreach ($settings as $key => $value) {
        $stmt = $pdo->prepare("INSERT INTO settings (`key`, value, created_at, updated_at) VALUES (?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE value = ?");
        $stmt->execute([$key, $value, $value]);
    }
    
    // Default knowledge categories
    $categories = [
        ['name' => 'İslam', 'description' => 'İslam dini ilə bağlı məsələlər', 'color' => '#10b981'],
        ['name' => 'Fiqh', 'description' => 'Fiqh məsələləri və hökümlər', 'color' => '#3b82f6'],
        ['name' => 'Əxlaq', 'description' => 'İslam əxlaqı və davranışlar', 'color' => '#8b5cf6'],
        ['name' => 'İbadet', 'description' => 'Namaz, oruc, hac və digər ibadətlər', 'color' => '#f59e0b'],
        ['name' => 'Həyat', 'description' => 'Gündəlik həyatda İslam', 'color' => '#ef4444'],
    ];
    
    foreach ($categories as $index => $category) {
        $stmt = $pdo->prepare("INSERT INTO knowledge_categories (name, description, color, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE name = name");
        $stmt->execute([$category['name'], $category['description'], $category['color'], $index]);
    }
}

function setDirectoryPermissions($basePath) {
    $dirs = [
        '/storage',
        '/storage/framework',
        '/storage/framework/cache',
        '/storage/framework/views',
        '/storage/logs',
        '/bootstrap/cache',
        '/public/storage'
    ];

    foreach ($dirs as $dir) {
        $fullPath = $basePath . $dir;
        if (!is_dir($fullPath)) {
            @mkdir($fullPath, 0755, true);
        }
        // attempt chmod if allowed
        @chmod($fullPath, 0755);
    }
}

function performPostInstallFix() {
    try {
        $base = dirname(__DIR__);
        $envPath = $base . '/.env';
        if (!file_exists($envPath)) {
            throw new Exception('.env tapılmadı');
        }
        $env = file_get_contents($envPath);
        if (!preg_match('/^APP_KEY\s*=\s*(.+)$/m', $env, $m) || trim($m[1]) === '') {
            $key = 'base64:' . base64_encode(random_bytes(32));
            if (preg_match('/^APP_KEY\s*=.*$/m', $env)) {
                $env = preg_replace('/^APP_KEY\s*=.*$/m', 'APP_KEY=' . $key, $env);
            } else {
                $env .= "\nAPP_KEY={$key}\n";
            }
            if (file_put_contents($envPath, $env) === false) {
                throw new Exception('APP_KEY yazıla bilmədi');
            }
        }
        // Ensure directories
        setDirectoryPermissions($base);
        // Remove cached config/routes if any
        @unlink($base . '/bootstrap/cache/config.php');
        @unlink($base . '/bootstrap/cache/routes.php');
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XIV AI - Quraşdırma</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .step { display: none; }
        .step.active { display: block; }
        .requirement-ok { color: #10B981; }
        .requirement-fail { color: #EF4444; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-50 via-white to-emerald-50">
    <div class="min-h-screen flex items-center justify-center">
        <div class="bg-white/90 backdrop-blur rounded-2xl shadow-2xl p-8 max-w-2xl w-full border border-gray-200">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-800 tracking-tight">XIV AI</h1>
                <p class="text-gray-600 mt-1">Quraşdırma sihirbazı • Versiya 1.0 • Müəllif: DeXIV</p>
                <div class="mt-4 flex justify-center space-x-2">
                    <div id="step-indicator-1" class="w-3 h-3 bg-blue-500 rounded-full"></div>
                    <div id="step-indicator-2" class="w-3 h-3 bg-gray-300 rounded-full"></div>
                    <div id="step-indicator-3" class="w-3 h-3 bg-gray-300 rounded-full"></div>
                    <div id="step-indicator-4" class="w-3 h-3 bg-gray-300 rounded-full"></div>
                </div>
            </div>

            <!-- Step 1: Requirements Check -->
            <div id="step-1" class="step active">
                <h2 class="text-xl font-semibold mb-4">1. Sistem Tələbləri</h2>
                <div id="requirements-list">
                    <p class="text-gray-600">Sistem tələbləri yoxlanılır...</p>
                </div>
                <button id="check-requirements" class="mt-6 w-full bg-emerald-600 hover:bg-emerald-700 transition-all duration-200 text-white py-2.5 rounded-xl shadow">
                    Tələbləri Yoxla
                </button>
            </div>

            <!-- Step 2: Database Configuration -->
            <div id="step-2" class="step">
                <h2 class="text-xl font-semibold mb-4">2. Verilənlər Bazası</h2>
                <form id="db-form" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Host</label>
                        <input type="text" name="db_host" value="localhost" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Port</label>
                        <input type="text" name="db_port" value="3306" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Verilənlər Bazası Adı</label>
                        <input type="text" name="db_database" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">İstifadəçi Adı</label>
                        <input type="text" name="db_username" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Şifrə</label>
                        <input type="password" name="db_password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <button type="button" id="test-db" class="w-full bg-emerald-600 hover:bg-emerald-700 transition-all duration-200 text-white py-2.5 rounded-xl shadow">
                        Bağlantını Test Et
                    </button>
                </form>
                <div id="db-result" class="mt-4"></div>
            </div>

            <!-- Step 3: Admin User & Site Settings -->
            <div id="step-3" class="step">
                <h2 class="text-xl font-semibold mb-4">3. Admin və Sayt Parametrləri</h2>
                <form id="admin-form" class="space-y-4">
                    <h3 class="text-lg font-medium">Sayt Məlumatları</h3>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Sayt Adı</label>
                        <input type="text" name="app_name" value="XIV AI" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Sayt URL</label>
                        <input type="url" name="app_url" id="app_url" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                    </div>
                    
                    <h3 class="text-lg font-medium mt-6">Admin İstifadəçi</h3>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Admin Adı</label>
                        <input type="text" name="admin_name" value="Admin" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Admin Email</label>
                        <input type="email" name="admin_email" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Admin Şifrə</label>
                        <input type="password" name="admin_password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                    </div>
                    
                    <h3 class="text-lg font-medium mt-6">E-poçt Parametrləri (İstəyə bağlı)</h3>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">SMTP Host</label>
                        <input type="text" name="mail_host" value="smtp.gmail.com" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">SMTP Port</label>
                        <input type="text" name="mail_port" value="587" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Email İstifadəçi</label>
                        <input type="email" name="mail_username" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Email Şifrə</label>
                        <input type="password" name="mail_password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                </form>
            </div>

            <!-- Step 4: Installation -->
            <div id="step-4" class="step">
                <h2 class="text-xl font-semibold mb-4">4. Quraşdırma</h2>
                <div id="install-progress">
                    <div class="bg-gray-200 rounded-full h-2 mb-4">
                        <div id="progress-bar" class="bg-blue-500 h-2 rounded-full" style="width: 0%"></div>
                    </div>
                    <div id="install-status">Quraşdırma başlamaq üçün hazırdır...</div>
                </div>
                <button id="start-install" class="mt-6 w-full bg-emerald-600 hover:bg-emerald-700 transition-all duration-200 text-white py-2.5 rounded-xl shadow">
                    Quraşdırmanı Başlat
                </button>
                <div id="install-result" class="mt-4"></div>
            </div>

            <!-- Navigation buttons -->
            <div class="flex justify-between mt-8">
                <button id="prev-btn" class="px-4 py-2 bg-gray-300 text-gray-700 rounded hover:bg-gray-400" style="display: none;">
                    Əvvəlki
                </button>
                <button id="next-btn" class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600" style="display: none;">
                    Növbəti
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentStep = 1;
        let maxStep = 1;
        let requirementsPassed = false;
        let dbTested = false;

        // Set app URL automatically
        document.getElementById('app_url').value = window.location.origin.replace('/install', '');

        function showStep(step) {
            document.querySelectorAll('.step').forEach(el => el.classList.remove('active'));
            document.getElementById(`step-${step}`).classList.add('active');
            
            // Update indicators
            document.querySelectorAll('[id^="step-indicator-"]').forEach((el, index) => {
                if (index + 1 <= step) {
                    el.className = 'w-3 h-3 bg-blue-500 rounded-full';
                } else {
                    el.className = 'w-3 h-3 bg-gray-300 rounded-full';
                }
            });

            // Show/hide navigation buttons
            const prevBtn = document.getElementById('prev-btn');
            const nextBtn = document.getElementById('next-btn');
            
            prevBtn.style.display = step > 1 ? 'block' : 'none';
            nextBtn.style.display = step < 4 && step <= maxStep ? 'block' : 'none';
        }

        // Check requirements
        document.getElementById('check-requirements').addEventListener('click', async () => {
            const response = await fetch('setup.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=check_requirements'
            });
            const result = await response.json();
            
            let html = '<ul class="space-y-2">';
            const requirements = {
                'php_version': 'PHP 8.1+',
                'openssl': 'OpenSSL Extension',
                'pdo': 'PDO Extension',
                'pdo_mysql': 'PDO MySQL Extension',
                'mbstring': 'Mbstring Extension',
                'tokenizer': 'Tokenizer Extension',
                'xml': 'XML Extension',
                'ctype': 'Ctype Extension',
                'json': 'JSON Extension',
                'curl': 'cURL Extension',
                'storage_writable': 'Storage Writable',
                'bootstrap_writable': 'Bootstrap Cache Writable',
                'env_writable': 'Environment Writable'
            };
            
            for (const [key, label] of Object.entries(requirements)) {
                const status = result.requirements[key];
                html += `<li class="flex items-center ${status ? 'requirement-ok' : 'requirement-fail'}">
                    <span class="mr-2">${status ? '✓' : '✗'}</span> ${label}
                </li>`;
            }
            html += '</ul>';
            
            document.getElementById('requirements-list').innerHTML = html;
            
            if (result.success) {
                requirementsPassed = true;
                maxStep = 2;
                setTimeout(() => {
                    currentStep = 2;
                    showStep(2);
                }, 1500);
            }
        });

        // Test database connection
        document.getElementById('test-db').addEventListener('click', async () => {
            const formData = new FormData(document.getElementById('db-form'));
            formData.append('action', 'test_database');
            
            const response = await fetch('setup.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            const resultDiv = document.getElementById('db-result');
            if (result.success) {
                resultDiv.innerHTML = '<div class="p-3 bg-green-100 text-green-700 rounded">✓ Verilənlər bazası bağlantısı uğurludur!</div>';
                dbTested = true;
                // Enable proceeding to Step 4 (Installation)
                maxStep = 4;
                setTimeout(() => {
                    currentStep = 3;
                    showStep(3);
                }, 1500);
            } else {
                resultDiv.innerHTML = `<div class=\"p-3 bg-red-100 text-red-700 rounded\">✗ Xəta: ${result.error}</div>`;
            }
        });

        // Start installation
        document.getElementById('start-install').addEventListener('click', async () => {
            document.getElementById('start-install').disabled = true;
            document.getElementById('install-status').textContent = 'Quraşdırma başladı...';
            
            const dbFormData = new FormData(document.getElementById('db-form'));
            const adminFormData = new FormData(document.getElementById('admin-form'));
            
            const installData = new FormData();
            installData.append('action', 'install');
            
            // Merge all form data
            for (const [key, value] of dbFormData) {
                installData.append(key, value);
            }
            for (const [key, value] of adminFormData) {
                installData.append(key, value);
            }
            
            const response = await fetch('setup.php', {
                method: 'POST',
                body: installData
            });
            const result = await response.json();
            
            const progressBar = document.getElementById('progress-bar');
            const statusDiv = document.getElementById('install-status');
            const resultDiv = document.getElementById('install-result');
            
            if (result.success) {
                progressBar.style.width = '100%';
                statusDiv.textContent = 'Quraşdırma tamamlandı!';
                resultDiv.innerHTML = `
                    <div class="p-4 bg-green-100 text-green-700 rounded">
                        <h3 class="font-semibold">🎉 Uğurla quraşdırıldı!</h3>
                        <p class="mt-2">XIV AI platformanız hazırdır. İndi admin panelə daxil ola bilərsiniz.</p>
                        <div class="flex flex-wrap gap-2 mt-4">
                          <a href="../" class="inline-block px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">Sayta keç</a>
                          <button id="run-post-fix" class="inline-block px-4 py-2 bg-emerald-500 text-white rounded hover:bg-emerald-600">Yekun düzəliş (Post-Install Fix)</button>
                        </div>
                        <div id="post-fix-status" class="text-xs text-gray-600 mt-2"></div>
                    </div>
                `;
                
                // Delete install directory after successful installation (istəyə bağlı gecikdirilə bilər)
                setTimeout(() => {
                    fetch('cleanup.php', { method: 'POST' });
                }, 2000);
            } else {
                statusDiv.textContent = 'Quraşdırma xətası!';
                resultDiv.innerHTML = `<div class="p-3 bg-red-100 text-red-700 rounded">Xəta: ${result.error}</div>`;
                document.getElementById('start-install').disabled = false;
            }
        });

        // Run Post-Install Fix
        document.addEventListener('click', async (e) => {
          if (e.target && e.target.id === 'run-post-fix') {
            const statusDiv = document.getElementById('post-fix-status');
            statusDiv.textContent = 'İcra edilir...';
            const formData = new FormData();
            formData.append('action', 'post_install_fix');
            try {
              const response = await fetch('setup.php', { method: 'POST', body: formData });
              const result = await response.json();
              if (result.success) {
                statusDiv.textContent = 'Tamamlandı! Installer qovluğu silinir...';
                // Try to cleanup installer
                try { await fetch('cleanup.php', { method: 'POST' }); } catch (e) {}
                setTimeout(() => { window.location.href = '../'; }, 1200);
              } else {
                statusDiv.textContent = 'Xəta: ' + (result.error || 'Naməlum xəta');
              }
            } catch (err) {
              statusDiv.textContent = 'Xəta: ' + err.message;
            }
          }
        });

        // Navigation
        document.getElementById('prev-btn').addEventListener('click', () => {
            if (currentStep > 1) {
                currentStep--;
                showStep(currentStep);
            }
        });

        document.getElementById('next-btn').addEventListener('click', () => {
            // If we are on Step 3 and DB test passed, allow moving to Step 4
            if (currentStep === 3 && dbTested) {
                maxStep = Math.max(maxStep, 4);
            }
            if (currentStep < 4 && currentStep < maxStep) {
                currentStep++;
                showStep(currentStep);
            }
        });

        // Initialize
        showStep(currentStep);
    </script>
</body>
</html>
