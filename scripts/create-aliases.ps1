# XIV AI Deployment Aliases Setup
# Creates convenient aliases for GitHub sync and hosting deployment

Write-Host "🚀 Setting up XIV AI deployment aliases..." -ForegroundColor Cyan

# Function to add aliases to PowerShell profile
function Add-XIVAliases {
    $profilePath = $PROFILE
    
    if (!(Test-Path $profilePath)) {
        New-Item -ItemType File -Path $profilePath -Force
        Write-Host "✅ Created PowerShell profile at: $profilePath" -ForegroundColor Green
    }
    
    $aliasContent = @"

# XIV AI Deployment Aliases (Auto-generated)
function xiv-sync {
    Write-Host "🔄 XIV AI - Syncing to GitHub..." -ForegroundColor Cyan
    git add -A
    `$message = if (`$args) { `$args -join " " } else { "Update XIV AI v$((Get-Content version.json | ConvertFrom-Json).version)" }
    git commit -m "`$message"
    git push origin main
    Write-Host "✅ GitHub sync completed!" -ForegroundColor Green
}

function xiv-host {
    Write-Host "🚀 XIV AI - Deploying to hosting..." -ForegroundColor Cyan
    `$url = "https://ai.dexiv.me/admin/system/update"
    Write-Host "Opening hosting update panel: `$url" -ForegroundColor Yellow
    Start-Process `$url
}

function xiv-build {
    Write-Host "🧱 XIV AI - Building assets..." -ForegroundColor Cyan
    npm run build
    Write-Host "✅ Build completed!" -ForegroundColor Green
}

function xiv-dev {
    Write-Host "🔧 XIV AI - Starting development server..." -ForegroundColor Cyan
    npm run dev
}

function xiv-status {
    Write-Host "📊 XIV AI Status:" -ForegroundColor Cyan
    `$version = (Get-Content version.json | ConvertFrom-Json).version
    Write-Host "Version: `$version" -ForegroundColor Yellow
    Write-Host "Git Status:" -ForegroundColor Yellow
    git status --short
}

Write-Host "✅ XIV AI aliases loaded:" -ForegroundColor Green
Write-Host "  xiv-sync  - GitHub sync" -ForegroundColor White
Write-Host "  xiv-host  - Hosting sync" -ForegroundColor White
Write-Host "  xiv-build - Build assets" -ForegroundColor White
Write-Host "  xiv-dev   - Dev server" -ForegroundColor White
Write-Host "  xiv-status - Show status" -ForegroundColor White

"@
    
    # Check if aliases already exist
    $currentContent = Get-Content $profilePath -Raw -ErrorAction SilentlyContinue
    if ($currentContent -and $currentContent.Contains("XIV AI Deployment Aliases")) {
        Write-Host "⚠️  XIV AI aliases already exist in profile" -ForegroundColor Yellow
        Write-Host "Use 'xiv-status' to test aliases" -ForegroundColor Yellow
    } else {
        Add-Content -Path $profilePath -Value $aliasContent
        Write-Host "✅ XIV AI aliases added to PowerShell profile" -ForegroundColor Green
        Write-Host "📝 Profile location: $profilePath" -ForegroundColor Gray
        
        # Source the profile
        . $profilePath
        Write-Host "✅ Aliases loaded in current session" -ForegroundColor Green
    }
}

# Add aliases
Add-XIVAliases

Write-Host ""
Write-Host "🎉 Setup complete! Available commands:" -ForegroundColor Green
Write-Host "  xiv-sync   - Commit and push to GitHub" -ForegroundColor Cyan
Write-Host "  xiv-host   - Open hosting deployment panel" -ForegroundColor Cyan  
Write-Host "  xiv-build  - Build frontend assets" -ForegroundColor Cyan
Write-Host "  xiv-dev    - Start dev server" -ForegroundColor Cyan
Write-Host "  xiv-status - Show current status" -ForegroundColor Cyan
Write-Host ""
Write-Host "💡 Restart PowerShell or run '. `$PROFILE' to use aliases in new sessions" -ForegroundColor Yellow