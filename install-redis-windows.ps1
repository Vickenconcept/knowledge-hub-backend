# Redis Installation Script for Windows (KHub)
# Run this in PowerShell as Administrator

Write-Host "🔥 Installing Redis for KHub..." -ForegroundColor Cyan

# Option 1: Install via Chocolatey (easiest)
if (Get-Command choco -ErrorAction SilentlyContinue) {
    Write-Host "Installing Redis via Chocolatey..." -ForegroundColor Yellow
    choco install redis-64 -y
    
    # Start Redis service
    redis-server --service-install
    redis-server --service-start
    
    Write-Host "✅ Redis installed and started!" -ForegroundColor Green
} else {
    Write-Host "Chocolatey not found. Installing Chocolatey first..." -ForegroundColor Yellow
    
    # Install Chocolatey
    Set-ExecutionPolicy Bypass -Scope Process -Force
    [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072
    iex ((New-Object System.Net.WebClient).DownloadString('https://community.chocolatey.org/install.ps1'))
    
    # Refresh environment
    refreshenv
    
    # Now install Redis
    choco install redis-64 -y
    redis-server --service-install
    redis-server --service-start
    
    Write-Host "✅ Redis and Chocolatey installed!" -ForegroundColor Green
}

# Test connection
Write-Host "`n🧪 Testing Redis connection..." -ForegroundColor Cyan
redis-cli ping

if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ Redis is running! You can now use it with Laravel." -ForegroundColor Green
    Write-Host "`n📝 Next steps:" -ForegroundColor Yellow
    Write-Host "1. Update your .env file with Redis settings"
    Write-Host "2. Run: php artisan config:clear"
    Write-Host "3. Run: php artisan cache:clear"
    Write-Host "4. Test: php artisan tinker → Redis::connection()->ping()"
} else {
    Write-Host "⚠️ Redis installation completed but not responding. Try restarting your computer." -ForegroundColor Red
}

