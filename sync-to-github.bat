@echo off
echo 🚀 XIV AI GitHub Sync başladı...

set SOURCE_DIR=C:\xampp\htdocs\chatbot
set TARGET_DIR=C:\Users\ekber\OneDrive\Sənədlər\GitHub\XIV-AI-Feqih

echo 📂 Source: %SOURCE_DIR%
echo 📁 Target: %TARGET_DIR%

echo 🗑️ Köhnə fayllar təmizlənir...
for /d %%i in ("%TARGET_DIR%\*") do (
    if not "%%~ni"==".git" rmdir /s /q "%%i" 2>nul
)
for %%i in ("%TARGET_DIR%\*") do (
    if not "%%~ni"==".git" del /q "%%i" 2>nul
)

echo 📋 Yeni fayllar kopyalanır...
xcopy "%SOURCE_DIR%\*" "%TARGET_DIR%\" /E /H /C /I /Y

echo ❌ Təhlükəsizlik faylları silinir...
if exist "%TARGET_DIR%\.env" del "%TARGET_DIR%\.env"
if exist "%TARGET_DIR%\storage\installed.lock" del "%TARGET_DIR%\storage\installed.lock"
if exist "%TARGET_DIR%\sync-to-github.bat" del "%TARGET_DIR%\sync-to-github.bat"
if exist "%TARGET_DIR%\sync-to-github.ps1" del "%TARGET_DIR%\sync-to-github.ps1"

echo ✅ Sync tamamlandı!
echo.
echo 🎯 Növbəti addımlar:
echo 1. GitHub Desktop-da dəyişiklikləri commit edin
echo 2. Push to GitHub edin
echo.
pause