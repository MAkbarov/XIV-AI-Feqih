# XIV AI - Smart Sync Script v1.0.7
# Yalnız dəyişdirilən faylları GitHub repo-ya köçürür

Write-Host "🔄 XIV AI Smart Sync - Yalnız dəyişikliklər..." -ForegroundColor Green

$sourceDir = "C:\xampp\htdocs\chatbot"
$targetDir = "C:\Users\ekber\OneDrive\Sənədlər\GitHub\XIV-AI-Feqih"

Write-Host "📂 Source: $sourceDir" -ForegroundColor Cyan
Write-Host "📁 Target: $targetDir" -ForegroundColor Cyan

# v1.0.7 üçün dəyişdirilən fayllar
$filesToSync = @(
    "app\Services\AiService.php",
    "version.php",
    "version.json",
    "sync-changes-only.ps1",
    "RELEASE-v1.0.7.md"
)

try {
    # Target directory mövcudluğunu yoxla
    if (!(Test-Path $targetDir)) {
        throw "Target directory tapılmadı: $targetDir"
    }
    
    Write-Host "📋 v1.0.7 dəyişikliklərini köçürür..." -ForegroundColor Yellow
    
    $copiedCount = 0
    $skippedCount = 0
    
    foreach ($relativePath in $filesToSync) {
        $sourcePath = Join-Path $sourceDir $relativePath
        $targetPath = Join-Path $targetDir $relativePath
        
        if (Test-Path $sourcePath) {
            # Target qovluğunu yarat
            $targetFolder = Split-Path $targetPath -Parent
            if (!(Test-Path $targetFolder)) {
                New-Item -ItemType Directory -Path $targetFolder -Force | Out-Null
                Write-Host "  📁 Qovluq yaradıldı: $(Split-Path $relativePath -Parent)" -ForegroundColor Blue
            }
            
            # Faylı köçür
            Copy-Item $sourcePath -Destination $targetPath -Force
            Write-Host "  ✅ Köçürüldü: $relativePath" -ForegroundColor Green
            $copiedCount++
            
            # Fayl ölçüsünü göstər
            $fileSize = [math]::Round((Get-Item $sourcePath).Length / 1KB, 2)
            Write-Host "    📊 Ölçü: $fileSize KB" -ForegroundColor Gray
        } else {
            Write-Host "  ❌ Tapılmadı: $relativePath" -ForegroundColor Red
            $skippedCount++
        }
    }
    
    Write-Host ""
    Write-Host "✅ Smart Sync tamamlandı!" -ForegroundColor Green
    Write-Host "  📤 Köçürülən: $copiedCount fayl" -ForegroundColor Green
    Write-Host "  ⏭️ Atlanılan: $skippedCount fayl" -ForegroundColor Yellow
    
    # Versiya məlumatını göstər
    if (Test-Path "$targetDir\version.json") {
        $versionContent = Get-Content "$targetDir\version.json" -Raw | ConvertFrom-Json
        Write-Host "  🏷️ Versiya: $($versionContent.version)" -ForegroundColor Magenta
        Write-Host "  📅 Tarix: $(Get-Date -Format 'yyyy-MM-dd HH:mm')" -ForegroundColor Magenta
    }
    
    Write-Host ""
    Write-Host "🎯 Növbəti addımlar:" -ForegroundColor Yellow
    Write-Host "1. GitHub Desktop-da repo açın" -ForegroundColor White
    Write-Host "2. Commit message:" -ForegroundColor White
    Write-Host "   'v1.0.5 - CRITICAL FIX: Migration execution order corrected'" -ForegroundColor Cyan
    Write-Host "3. Commit və push edin" -ForegroundColor White
    Write-Host "4. GitHub-da v1.0.5 release yaradın" -ForegroundColor White
    
    # GitHub Desktop-ı aç
    $githubDesktopPath = "$env:LOCALAPPDATA\GitHubDesktop\GitHubDesktop.exe"
    if (Test-Path $githubDesktopPath) {
        Write-Host ""
        Write-Host "🖥️ GitHub Desktop açılır..." -ForegroundColor Green
        Start-Process $githubDesktopPath
        Start-Sleep 1
    }
    
} catch {
    Write-Host "❌ Xəta: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""
Write-Host "🎉 XIV AI v1.0.7 Smart Sync bitdi!" -ForegroundColor Green
Read-Host "Enter basın..."