# 🚀 KHub Production Deployment Checklist

## Pre-Deployment (Do This First)

### 1. Database Optimization
```bash
# Run the new production indexes migration
php artisan migrate --force

# Verify indexes are created
php artisan tinker
>>> DB::select("SHOW INDEX FROM chunks");
>>> DB::select("SHOW INDEX FROM documents");
```

### 2. Environment Configuration

Update your production `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# Database - Use dedicated user
DB_CONNECTION=mysql
DB_HOST=your-db-host
DB_PORT=3306
DB_DATABASE=knowledgehub_prod
DB_USERNAME=khub_user
DB_PASSWORD=STRONG_PASSWORD_HERE

# Queue - REQUIRED for embeddings and ingestion
QUEUE_CONNECTION=database

# Cache - Use Redis in production
CACHE_DRIVER=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# OpenAI - For embeddings
OPENAI_API_KEY=your-key-here

# Pinecone - For vector storage
PINECONE_API_KEY=your-key-here
PINECONE_ENVIRONMENT=your-env
PINECONE_INDEX_NAME=your-index

# Cloudinary - For file storage
CLOUDINARY_CLOUD_NAME=your-cloud
CLOUDINARY_API_KEY=your-key
CLOUDINARY_API_SECRET=your-secret

# Mail
MAIL_MAILER=smtp
MAIL_HOST=mailhog
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS=noreply@your-domain.com
```

### 3. Optimize Laravel

```bash
# Clear all caches first
php artisan optimize:clear

# Then cache everything for production
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Run migrations
php artisan migrate --force
```

### 4. Set Up Queue Workers (CRITICAL!)

**Install Supervisor:**
```bash
sudo apt-get install supervisor
```

**Copy the supervisor config:**
```bash
sudo cp queue-worker-supervisor.conf /etc/supervisor/conf.d/khub-worker.conf
# Edit the paths in the file
sudo nano /etc/supervisor/conf.d/khub-worker.conf

# Start workers
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start khub-worker:*
```

**Check workers are running:**
```bash
sudo supervisorctl status
```

### 5. Database User Security

Create dedicated MySQL user:

```sql
-- On your MySQL server
CREATE USER 'khub_user'@'%' IDENTIFIED BY 'StrongPassword123!';

-- Grant only needed permissions
GRANT SELECT, INSERT, UPDATE, DELETE ON knowledgehub_prod.* TO 'khub_user'@'%';

-- For migrations (optional - can use root for deployment only)
-- GRANT CREATE, DROP, INDEX, ALTER ON knowledgehub_prod.* TO 'khub_user'@'%';

FLUSH PRIVILEGES;
```

### 6. File Permissions

```bash
# Set proper permissions
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Protect sensitive files
chmod 600 .env
```

### 7. Scheduled Tasks (Cron)

Add to crontab:
```bash
* * * * * cd /path/to/khub/Backend && php artisan schedule:run >> /dev/null 2>&1
```

## Post-Deployment Checks

### 1. Test Critical Flows

- [ ] Upload a DOCX file via Manual Upload
- [ ] Verify text extraction (no binary data)
- [ ] Send a chat message
- [ ] Check embeddings are generated
- [ ] Test Google Drive sync
- [ ] Verify queue workers are processing jobs

### 2. Monitor Logs

```bash
# Check Laravel logs
tail -f storage/logs/laravel.log

# Check queue worker logs
tail -f storage/logs/worker.log

# Check MySQL slow queries
tail -f /var/log/mysql-slow.log
```

### 3. Performance Monitoring

```bash
# Install Laravel Telescope (dev/staging only)
composer require laravel/telescope --dev
php artisan telescope:install
php artisan migrate

# For production, use New Relic or similar
```

### 4. Database Backup

```bash
# Install backup package
composer require spatie/laravel-backup

# Publish config
php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"

# Configure in config/backup.php
# - Set S3/Wasabi/DO Spaces as destination
# - Schedule daily backups

# Add to app/Console/Kernel.php:
$schedule->command('backup:run --only-db')->daily()->at('02:00');
$schedule->command('backup:clean')->daily()->at('03:00');
```

## KHub-Specific Considerations

### Vector Storage (Pinecone)
- [ ] Verify Pinecone index exists and is properly configured
- [ ] Test vector similarity searches
- [ ] Monitor Pinecone usage/limits

### OpenAI API
- [ ] Set up billing alerts in OpenAI dashboard
- [ ] Monitor token usage via cost_tracking table
- [ ] Implement rate limiting if needed

### Cloudinary Storage
- [ ] Verify all uploaded files are accessible
- [ ] Set up auto-backup if possible
- [ ] Monitor storage usage

### Queue Jobs
Your app has CRITICAL background jobs:
- `IngestConnectorJob` - Processes documents
- `EmbedChunksBatchJob` - Generates embeddings
- `CreateChunksJob` - Text chunking
- `ProcessLargeFileJob` - Large file handling

**These MUST run via queue workers - they cannot fail!**

## MySQL Tuning for KHub

Your `my.cnf` should have (adjust based on server RAM):

```ini
[mysqld]
# InnoDB for transactions
default_storage_engine = InnoDB

# Memory allocation (for 8GB RAM server)
innodb_buffer_pool_size = 4G
innodb_log_file_size = 512M

# CRITICAL for embeddings and large text
max_allowed_packet = 256M

# Connection limits
max_connections = 200

# Character set (you already fixed this)
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci

# Performance
innodb_flush_log_at_trx_commit = 2
innodb_file_per_table = 1

# Slow query logging
slow_query_log = 1
long_query_time = 2
```

## Security Hardening

### 1. Rate Limiting
Add to `app/Http/Kernel.php`:
```php
'api' => [
    \Illuminate\Routing\Middleware\ThrottleRequests::class.':60,1',
],
```

### 2. CORS
Your `config/cors.php` should restrict origins in production.

### 3. API Keys
- Rotate OpenAI API keys periodically
- Use Laravel Vault for sensitive credentials
- Never log API keys

## Monitoring Dashboard

Set up a simple health check endpoint:

```php
// routes/api.php
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'database' => DB::connection()->getPdo() ? 'connected' : 'disconnected',
        'queue' => Queue::size() < 1000 ? 'healthy' : 'overloaded',
        'cache' => Cache::has('health-check') ? 'working' : 'down',
    ]);
});
```

## 🎯 **My Verdict on the Guide**

The guide is **excellent** but here's what YOU specifically need:

### ✅ **Do This NOW:**
1. Run the production indexes migration I just created
2. Set up Supervisor for queue workers (CRITICAL!)
3. Configure proper `.env` for production
4. Test DOCX upload → extraction → embedding flow

### ⚠️ **Do This Before Launch:**
1. Set up database backups (spatie/laravel-backup)
2. Add MySQL slow query logging
3. Create dedicated DB user with limited permissions
4. Set up health check endpoint

### 🔮 **Do This After Launch:**
1. Add Redis caching
2. Monitor with Telescope/New Relic
3. Tune MySQL based on actual usage patterns
4. Consider read replicas if you get 10k+ users

## 💡 **Bottom Line**

The guide is **solid**, but don't over-engineer. For KHub:
- Your indexes are already good ✅
- **Queue workers are your #1 priority** 🔥
- Backups are #2 priority 💾
- Everything else can wait until you have real traffic

**Would you like me to:**
1. Create a production-ready `.env.production` template?
2. Write a deployment script that does all optimizations?
3. Create a health monitoring dashboard?
