# ⚡ Redis Quick Start for KHub

## 🚀 Installation (Choose ONE Option)

### Option 1: Using Docker (Easiest - Recommended)

```bash
# Start Redis in Docker
docker run -d -p 6379:6379 --name khub-redis --restart always redis:alpine

# Verify it's running
docker ps | findstr khub-redis

# Test connection
docker exec -it khub-redis redis-cli ping
# Should return: PONG
```

### Option 2: Using PowerShell Script

```powershell
# Run as Administrator
cd Backend
.\install-redis-windows.ps1
```

### Option 3: Manual Installation (Memurai)

1. Download Memurai from https://www.memurai.com/get-memurai
2. Install and start the service
3. Verify: Open Command Prompt and run `redis-cli ping`

## ⚙️ Configuration

### 1. Update Your `.env` File

Open `Backend/.env` and add/update these lines:

```env
# Redis Configuration
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# Redis Connection Details
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_QUEUE_DB=2
REDIS_SESSION_DB=3
```

### 2. Clear Laravel Caches

```bash
cd Backend
php artisan config:clear
php artisan cache:clear
php artisan config:cache
```

### 3. Test Redis Integration

```bash
php test-redis.php
```

You should see:
```
✅ Redis connection: +PONG
✅ Cache working: Hello Redis!
✅ Database separation configured
🎉 Redis is FULLY configured and working!
```

## 🎯 What Redis Does for KHub

### 1. **Faster Cache** (25x speed improvement)
```php
// Example: Cache connector list
$connectors = Cache::remember("connectors.org.{$orgId}", 600, function () use ($orgId) {
    return Connector::where('org_id', $orgId)
        ->with('documents')
        ->get();
});
```

### 2. **Better Sessions** (10x faster)
- User sessions stored in Redis instead of database
- Faster login/logout
- Better concurrent user handling

### 3. **Faster Queue** (Optional)
- Jobs dispatched/processed faster
- Better for high-volume operations
- Can still use `database` queue if you prefer

## 📊 Monitor Redis

### Check What's Cached

```bash
redis-cli

# List all keys
KEYS *

# Get specific value
GET "khub_cache:connectors.org.b588fd83-1f11-4398-b6ff-a404c735a603"

# Check memory usage
INFO memory

# Monitor commands in real-time
MONITOR
```

### Laravel Commands

```bash
# Clear all cache
php artisan cache:clear

# Clear specific tags (if using tagged cache)
Cache::tags(['documents'])->flush();
```

## 🧪 Verify It's Working

### Test 1: Cache Performance

```bash
php artisan tinker
```

```php
// Without cache
$start = microtime(true);
$docs = \App\Models\Document::all();
$time1 = microtime(true) - $start;
echo "Database: " . round($time1 * 1000, 2) . "ms\n";

// With cache
$start = microtime(true);
$docs = Cache::remember('all_docs', 60, fn() => \App\Models\Document::all());
$time2 = microtime(true) - $start;
echo "First (cache miss): " . round($time2 * 1000, 2) . "ms\n";

// From cache
$start = microtime(true);
$docs = Cache::get('all_docs');
$time3 = microtime(true) - $start;
echo "Second (cache hit): " . round($time3 * 1000, 2) . "ms\n";

// You should see cache hit is 10-50x faster!
```

### Test 2: Session

1. Log in to http://localhost:8000
2. In Redis CLI: `KEYS *session*`
3. You should see session keys

### Test 3: Queue (if using Redis queue)

```bash
php artisan tinker
>>> dispatch(new \App\Jobs\CreateChunksJob(...));
>>> exit

# In redis-cli
redis-cli
> LLEN queues:default
# Should show 1 job
```

## 🔧 Troubleshooting

### Redis Not Starting (Docker)

```bash
# Check logs
docker logs khub-redis

# Restart
docker restart khub-redis
```

### Connection Refused

```bash
# Check Redis is listening
netstat -an | findstr 6379

# Test direct connection
redis-cli ping
```

### Laravel Can't Connect

```bash
# Clear config cache
php artisan config:clear

# Check .env has REDIS_CLIENT=predis
# Check Redis is actually running

# Test in tinker
php artisan tinker
>>> Redis::connection()->ping()
```

## 🎉 You're Done!

Redis is now installed and configured. Your KHub app will be:
- ✅ 10-50x faster for cached operations
- ✅ More scalable
- ✅ Production-ready

## 📈 Next: Add Smart Caching to Your App

See the examples in `PRODUCTION_DEPLOYMENT.md` for which data to cache in KHub.

**Pro tip:** Start with caching:
1. Connector lists (rarely change)
2. Pricing tiers (almost never change)
3. User permissions (checked frequently)
4. Document counts (expensive aggregates)

