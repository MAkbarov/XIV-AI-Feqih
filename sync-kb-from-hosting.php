<?php
/**
 * Knowledge Base Sync from Hosting to Localhost
 * Bu skript hosting serverindəki knowledge base məlumatlarını localhost-a köçürür
 */

// Hosting database credentials
$hostingDB = [
    'host' => 'hosting-db-host', // Hosting DB host
    'database' => 'hosting-db-name', // Hosting DB name  
    'username' => 'hosting-db-user', // Hosting DB user
    'password' => 'hosting-db-pass', // Hosting DB password
];

// Localhost database credentials
$localhostDB = [
    'host' => 'localhost',
    'database' => 'chatbot', // Localhost DB name
    'username' => 'root',
    'password' => '', // XAMPP default (boş)
];

try {
    echo "🔄 Knowledge Base Sync başlayır...\n";
    
    // Connect to hosting database
    $hostingPDO = new PDO(
        "mysql:host={$hostingDB['host']};dbname={$hostingDB['database']};charset=utf8mb4",
        $hostingDB['username'],
        $hostingDB['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Connect to localhost database  
    $localhostPDO = new PDO(
        "mysql:host={$localhostDB['host']};dbname={$localhostDB['database']};charset=utf8mb4",
        $localhostDB['username'],
        $localhostDB['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "✅ Database bağlantıları quruldu\n";
    
    // Get knowledge base data from hosting
    $stmt = $hostingPDO->query("SELECT * FROM knowledge_base WHERE is_active = 1");
    $knowledgeItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "📊 Hosting-dən " . count($knowledgeItems) . " məlumat tapıldı\n";
    
    if (empty($knowledgeItems)) {
        echo "⚠️ Hosting-də aktiv knowledge base məlumatı yoxdur\n";
        return;
    }
    
    // Clear existing localhost knowledge base
    $localhostPDO->exec("DELETE FROM knowledge_base");
    echo "🗑️ Localhost knowledge base təmizləndi\n";
    
    // Prepare insert statement
    $insertSQL = "INSERT INTO knowledge_base (title, content, source, source_url, category, author, language, metadata, embedding, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $insertStmt = $localhostPDO->prepare($insertSQL);
    
    $imported = 0;
    foreach ($knowledgeItems as $item) {
        $insertStmt->execute([
            $item['title'],
            $item['content'], 
            $item['source'],
            $item['source_url'],
            $item['category'],
            $item['author'],
            $item['language'],
            $item['metadata'],
            $item['embedding'],
            $item['is_active'],
            $item['created_at'],
            $item['updated_at']
        ]);
        $imported++;
    }
    
    echo "✅ {$imported} məlumat localhost-a köçürüldü\n";
    echo "🎉 Knowledge Base Sync tamamlandı!\n";
    
} catch (PDOException $e) {
    echo "❌ Database xətası: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "❌ Ümumi xəta: " . $e->getMessage() . "\n";
}
?>