<?php
/**
 * Emergency Recovery Script for XIV AI - 500 Server Error Fix
 * Upload this file to hosting root and visit: yoursite.com/emergency-recovery.php
 */

echo "<h1>🚨 XIV AI Emergency Recovery</h1>";
echo "<p>Starting recovery process...</p>";

try {
    // Set unlimited execution time
    set_time_limit(0);
    
    echo "<br>✅ Step 1: Clearing configuration cache...<br>";
    $configPath = __DIR__ . '/bootstrap/cache/config.php';
    if (file_exists($configPath)) {
        unlink($configPath);
        echo "✅ Config cache cleared<br>";
    } else {
        echo "ℹ️ No config cache found<br>";
    }
    
    echo "<br>✅ Step 2: Clearing application cache...<br>";
    $cachePaths = [
        'bootstrap/cache/services.php',
        'bootstrap/cache/packages.php',
        'bootstrap/cache/routes-v7.php',
    ];
    
    foreach ($cachePaths as $path) {
        $fullPath = __DIR__ . '/' . $path;
        if (file_exists($fullPath)) {
            unlink($fullPath);
            echo "✅ Cleared: {$path}<br>";
        }
    }
    
    echo "<br>✅ Step 3: Clearing storage caches...<br>";
    $storagePaths = [
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/framework/views',
    ];
    
    foreach ($storagePaths as $path) {
        $fullPath = __DIR__ . '/' . $path;
        if (is_dir($fullPath)) {
            $files = glob($fullPath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
                if (is_dir($file)) {
                    rmdir($file);
                }
            }
            echo "✅ Cleared: {$path}<br>";
        }
    }
    
    echo "<br>✅ Step 4: Creating .gitkeep files...<br>";
    $gitkeepPaths = [
        'storage/framework/cache/data/.gitkeep',
        'storage/framework/sessions/.gitkeep', 
        'storage/framework/views/.gitkeep',
        'storage/logs/.gitkeep'
    ];
    
    foreach ($gitkeepPaths as $path) {
        $fullPath = __DIR__ . '/' . $path;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($fullPath, '');
        echo "✅ Created: {$path}<br>";
    }
    
    echo "<br><h2>🎉 RECOVERY COMPLETED!</h2>";
    echo "<p><strong>Your site should be working now!</strong></p>";
    echo "<p>✅ All caches cleared<br>";
    echo "✅ Storage directories fixed<br>";
    echo "✅ Laravel ready to run</p>";
    
    echo "<br><p><a href='/'>🚀 Test Your Site</a></p>";
    echo "<p><em>Remember to delete this file after recovery: emergency-recovery.php</em></p>";
    
} catch (Exception $e) {
    echo "<br>❌ Recovery failed: " . $e->getMessage();
    echo "<br><br><strong>Manual Fix:</strong>";
    echo "<br>1. Delete: bootstrap/cache/config.php";  
    echo "<br>2. Delete: bootstrap/cache/services.php";
    echo "<br>3. Clear: storage/framework/cache/* files";
    echo "<br>4. Contact hosting support if problem persists";
}
?>