# 🔥 Redis Setup for KHub (Windows/Laragon)

## 📦 Step 1: Install Redis on Windows

### Option A: Using Memurai (Redis-compatible for Windows)

**Download and Install:**
1. Go to https://www.memurai.com/get-memurai
2. Download Memurai (free for development)
3. Install and run as a Windows service

### Option B: Using Redis via WSL2 (Recommended)

```bash
# Open WSL2 terminal
wsl

# Install Redis
sudo apt update
sudo apt install redis-server

# Start Redis
sudo service redis-server start

# Verify it's running
redis-cli ping
# Should return: PONG
```

### Option C: Using Docker (Easiest)

```bash
# Run Redis in Docker
docker run -d -p 6379:6379 --name khub-redis redis:alpine

# Verify
docker ps
```

## ⚙️ Step 2: Laravel Configuration

### 1. Update Your `.env` File

Add these lines to your `.env`:

```env
# Cache Driver
CACHE_DRIVER=redis
CACHE_PREFIX=khub_cache

# Session Driver
SESSION_DRIVER=redis
SESSION_LIFETIME=120

# Queue Driver (Optional - can keep 'database' if you prefer)
QUEUE_CONNECTION=redis

# Redis Connection
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0

# Redis Databases (use different DB numbers for separation)
REDIS_CACHE_DB=1
REDIS_QUEUE_DB=2
REDIS_SESSION_DB=3
```

### 2. Verify Configuration

The package `predis/predis` is already installed ✅

Now verify Redis is working:

```bash
php artisan tinker
>>> Redis::connection()->ping();
# Should return: "PONG"

>>> Cache::put('test', 'Hello Redis!', 60);
>>> Cache::get('test');
# Should return: "Hello Redis!"
```

## 🧪 Step 3: Test Redis Integration

### Test Cache

```php
// In tinker or a controller
use Illuminate\Support\Facades\Cache;

// Store data
Cache::put('user_settings_1', ['theme' => 'dark'], 3600);

// Retrieve
$settings = Cache::get('user_settings_1');

// Remember pattern (cache or execute)
$documents = Cache::remember('documents.org.123', 600, function () {
    return Document::where('org_id', 123)->get();
});
```

### Test Sessions

Just log in to your app - sessions will now use Redis automatically.

Check Redis keys:
```bash
redis-cli
> KEYS *
> GET "laravel_cache:user_settings_1"
```

### Test Queue (if using Redis queue)

```bash
# Dispatch a test job
php artisan tinker
>>> \App\Jobs\CreateChunksJob::dispatch(...);

# Check queue
redis-cli
> LLEN queues:default
```

## 🚀 Step 4: Configure for Production

### Update `config/cache.php`

Already configured! Just verify the Redis connection settings:

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'predis'),
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DB', 0),
    ],
    'cache' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_CACHE_DB', 1),
    ],
],
```

### Update `config/queue.php`

```php
'redis' => [
    'driver' => 'redis',
    'connection' => 'default',
    'queue' => env('REDIS_QUEUE', 'default'),
    'retry_after' => 90,
    'block_for' => null,
],
```

## 📊 Step 5: Monitor Redis Usage

### Check Redis Memory Usage

```bash
redis-cli
> INFO memory
> INFO stats
```

### Monitor in Real-Time

```bash
redis-cli --stat
```

### Clear Cache When Needed

```bash
php artisan cache:clear
php artisan config:clear

# Or manually in redis-cli
redis-cli
> FLUSHDB
```

## 🎯 What to Cache in KHub

### High-Value Cache Targets:

```php
// 1. Connector list (changes rarely)
$connectors = Cache::remember("connectors.org.{$orgId}", 600, function () use ($orgId) {
    return Connector::where('org_id', $orgId)->get();
});

// 2. Document counts (expensive aggregate)
$count = Cache::remember("documents.count.org.{$orgId}", 300, function () use ($orgId) {
    return Document::where('org_id', $orgId)->count();
});

// 3. User permissions (hit frequently)
$canUpload = Cache::remember("permissions.{$userId}.upload", 3600, function () use ($user) {
    return UsageLimitService::canAddDocument($user->org_id);
});

// 4. Pricing tiers (almost never change)
$tiers = Cache::remember('pricing.tiers.active', 86400, function () {
    return PricingTier::where('is_active', true)->get();
});
```

### What NOT to Cache:
- ❌ Embeddings (already in Pinecone)
- ❌ Real-time chat messages
- ❌ Job progress (needs to be live)
- ❌ Anything involving money/billing (always fresh)

## ⚡ Performance Benefits You'll See

| Operation | Before (No Redis) | After (Redis) | Improvement |
|-----------|-------------------|---------------|-------------|
| Load connectors list | ~50ms (DB query) | ~2ms (cache) | **25x faster** |
| Session read | ~10ms (DB) | ~1ms (Redis) | **10x faster** |
| Check user permissions | ~30ms (DB) | ~1ms (cache) | **30x faster** |
| Queue job dispatch | ~20ms (DB) | ~2ms (Redis) | **10x faster** |

## 🔧 Troubleshooting

### Can't Connect to Redis

```bash
# Check if Redis is running
redis-cli ping

# If using WSL2, check the service
wsl
sudo service redis-server status
sudo service redis-server start
```

### Clear Everything

```bash
# Laravel side
php artisan cache:clear
php artisan config:clear
php artisan route:clear

# Redis side
redis-cli
> FLUSHALL
```

### Check Connection from Laravel

```bash
php artisan tinker
>>> Redis::connection()->ping();
```

## ✅ Checklist

After setup, verify:

- [ ] Redis is running (`redis-cli ping` returns `PONG`)
- [ ] Laravel can connect (`php artisan tinker` → `Redis::connection()->ping()`)
- [ ] Cache works (`Cache::put('test', 'value')` → `Cache::get('test')`)
- [ ] Sessions work (log in to app, check `redis-cli KEYS *session*`)
- [ ] Queue works (if using Redis queue)

## 🎉 You're Done!

Redis is now configured and ready to dramatically improve your app's performance!

**Next Steps:**
1. Test with the commands above
2. Update your `.env` with the Redis settings
3. Restart your Laravel app
4. Monitor performance improvements

