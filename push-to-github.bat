@echo off
rem PDF Regenerator - push the whole project (vendor included) to GitHub.
rem Never pushed: config\users.csv (password hashes) and the files inside storage\ (client data).
setlocal
cd /d "%~dp0"
set REPO=https://github.com/Arafat-plugins/PDF-Regenerator.git

where git >nul 2>&1 || (echo Git is not installed. & pause & exit /b 1)

rem vendor\ (libraries) comes from the deploy zip next to this folder
if not exist "vendor\autoload.php" (
    if not exist "..\pdf-tool-root.zip" (echo ..\pdf-tool-root.zip not found. & pause & exit /b 1)
    echo Extracting vendor\ from ..\pdf-tool-root.zip ...
    tar -xf "..\pdf-tool-root.zip" vendor || (echo Could not extract vendor. & pause & exit /b 1)
)
if not exist "vendor\autoload.php" (echo vendor\autoload.php is missing. & pause & exit /b 1)

if not exist ".git" (
    git init
)

git config user.name >nul 2>&1 || git config user.name "Arafat"
git config user.email >nul 2>&1 || git config user.email "mamunhqpay@gmail.com"
git config core.autocrlf false

git add -A
git status --short | find /c /v "" > "%TEMP%\pdfreg_count.txt"
set /p COUNT=<"%TEMP%\pdfreg_count.txt"
echo.
echo Files to commit: %COUNT%
git ls-files --cached --error-unmatch config/users.csv >nul 2>&1 && (echo STOP: users.csv is staged. & pause & exit /b 1)

git commit -m "PDF Regenerator: full project" || echo (nothing new to commit)
git branch -M main

git remote get-url origin >nul 2>&1 || git remote add origin %REPO%

echo.
echo Pushing to %REPO% ...
echo (If a GitHub sign-in window opens, sign in with your browser.)
git push -u origin main
if errorlevel 1 (
    echo.
    echo Push failed. Copy the message above and send it to Claude.
) else (
    echo.
    echo Done: https://github.com/Arafat-plugins/PDF-Regenerator
)
pause
