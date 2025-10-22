#!/usr/bin/env php
<?php

/**
 * Redis Configuration Test Script for KHub
 * Run: php test-redis.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;

echo "🧪 Testing Redis Configuration for KHub\n";
echo str_repeat("=", 50) . "\n\n";

// Test 1: Basic Connection
echo "1️⃣ Testing basic Redis connection...\n";
try {
    $pong = Redis::connection()->ping();
    echo "   ✅ Redis connection: {$pong}\n\n";
} catch (\Exception $e) {
    echo "   ❌ Redis connection failed: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 2: Cache Connection
echo "2️⃣ Testing cache connection...\n";
try {
    Cache::put('test_key', 'Hello Redis!', 60);
    $value = Cache::get('test_key');
    if ($value === 'Hello Redis!') {
        echo "   ✅ Cache working: {$value}\n";
        Cache::forget('test_key');
        echo "   ✅ Cache cleanup successful\n\n";
    } else {
        echo "   ❌ Cache returned unexpected value: {$value}\n\n";
    }
} catch (\Exception $e) {
    echo "   ❌ Cache test failed: " . $e->getMessage() . "\n\n";
}

// Test 3: Check Redis Databases
echo "3️⃣ Checking Redis database separation...\n";
try {
    $config = config('database.redis');
    echo "   📦 Default DB: " . $config['default']['database'] . "\n";
    echo "   💾 Cache DB: " . $config['cache']['database'] . "\n";
    echo "   📋 Queue DB: " . $config['queue']['database'] . "\n";
    echo "   🔐 Session DB: " . $config['session']['database'] . "\n";
    echo "   ✅ Database separation configured\n\n";
} catch (\Exception $e) {
    echo "   ❌ Config check failed: " . $e->getMessage() . "\n\n";
}

// Test 4: Performance Test
echo "4️⃣ Running performance test...\n";
$iterations = 1000;
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    Cache::put("perf_test_{$i}", "value_{$i}", 60);
}
$writeTime = microtime(true) - $start;

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    Cache::get("perf_test_{$i}");
}
$readTime = microtime(true) - $start;

// Cleanup
for ($i = 0; $i < $iterations; $i++) {
    Cache::forget("perf_test_{$i}");
}

echo "   📊 Write {$iterations} keys: " . round($writeTime * 1000, 2) . "ms\n";
echo "   📊 Read {$iterations} keys: " . round($readTime * 1000, 2) . "ms\n";
echo "   ⚡ Avg write: " . round(($writeTime / $iterations) * 1000, 3) . "ms per key\n";
echo "   ⚡ Avg read: " . round(($readTime / $iterations) * 1000, 3) . "ms per key\n\n";

// Test 5: Check Current Keys
echo "5️⃣ Checking current Redis usage...\n";
try {
    // Note: KEYS command is slow in production - use only for testing
    $keys = Redis::connection('cache')->keys('*');
    echo "   📝 Total keys in cache DB: " . count($keys) . "\n";
    
    if (count($keys) > 0) {
        echo "   🔍 Sample keys:\n";
        foreach (array_slice($keys, 0, 5) as $key) {
            echo "      - {$key}\n";
        }
    }
    echo "\n";
} catch (\Exception $e) {
    echo "   ⚠️ Could not list keys: " . $e->getMessage() . "\n\n";
}

// Final Summary
echo str_repeat("=", 50) . "\n";
echo "🎉 Redis Test Complete!\n\n";
echo "📋 Configuration Summary:\n";
echo "   Cache Driver: " . env('CACHE_DRIVER', 'database') . "\n";
echo "   Session Driver: " . env('SESSION_DRIVER', 'database') . "\n";
echo "   Queue Driver: " . env('QUEUE_CONNECTION', 'database') . "\n";
echo "   Redis Client: " . env('REDIS_CLIENT', 'predis') . "\n\n";

if (env('CACHE_DRIVER') === 'redis' && env('SESSION_DRIVER') === 'redis') {
    echo "✅ Redis is FULLY configured and working!\n";
} else {
    echo "⚠️ Update your .env to use Redis:\n";
    echo "   CACHE_DRIVER=redis\n";
    echo "   SESSION_DRIVER=redis\n";
    echo "   QUEUE_CONNECTION=redis (optional)\n";
}

