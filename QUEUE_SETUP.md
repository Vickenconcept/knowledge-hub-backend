# Queue Setup for Background Jobs

## Overview

KHub uses Laravel queues to process background jobs like:
- **Creating getting started guides** during user registration (non-blocking)
- **Document ingestion** from connectors (Google Drive, Slack, etc.)
- **Embedding generation** for chunks
- **Large file processing**

## Queue Configuration

The default queue driver is `database`, which stores jobs in the `jobs` table.

### Environment Variables

In your `.env` file:

```env
QUEUE_CONNECTION=database
```

## Running the Queue Worker

### For Development (Local)

Run the queue worker in your terminal:

```bash
php artisan queue:work
```

Or use `queue:listen` for auto-reloading during development:

```bash
php artisan queue:listen
```

### For Production

Use a process manager like **Supervisor** to keep the queue worker running:

1. Install Supervisor:
```bash
sudo apt-get install supervisor
```

2. Create a configuration file `/etc/supervisor/conf.d/khub-worker.conf`:

```ini
[program:khub-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/khub/Backend/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/khub/Backend/storage/logs/worker.log
stopwaitsecs=3600
```

3. Start Supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start khub-worker:*
```

## Monitoring Queue Jobs

### View Failed Jobs

```bash
php artisan queue:failed
```

### Retry Failed Jobs

```bash
php artisan queue:retry all
```

### Clear Failed Jobs

```bash
php artisan queue:flush
```

## Background Jobs in KHub

### 1. CreateGettingStartedGuideJob
- **When**: Triggered during user registration (email/password or Google OAuth)
- **Purpose**: Creates and embeds the Getting Started Guide document
- **Duration**: ~5-10 seconds
- **Priority**: Low (doesn't block user login)

### 2. IngestConnectorJob
- **When**: Triggered when user syncs a connector (Google Drive, Slack, etc.)
- **Purpose**: Fetches, chunks, and embeds documents from external sources
- **Duration**: Varies (1 minute to 1+ hour depending on document count)
- **Priority**: High

### 3. CreateChunksJob
- **When**: Triggered after manual file upload
- **Purpose**: Chunks text and generates embeddings
- **Duration**: ~10-30 seconds per document
- **Priority**: Medium

## Testing Queue Jobs Locally

### Without Queue Worker (Synchronous - for quick testing)

In `.env`:
```env
QUEUE_CONNECTION=sync
```

Jobs will run immediately (blocking).

### With Queue Worker (Asynchronous - production-like)

1. Set `.env`:
```env
QUEUE_CONNECTION=database
```

2. Run worker:
```bash
php artisan queue:work
```

3. Register a new user and watch the logs:
```bash
tail -f storage/logs/laravel.log
```

You'll see:
```
[timestamp] local.INFO: Creating getting started guide in background {"org_id":"..."}
[timestamp] local.INFO: Getting started guide created successfully in background {"org_id":"..."}
```

## Troubleshooting

### Queue not processing jobs?

1. Check if worker is running:
```bash
ps aux | grep "queue:work"
```

2. Check the `jobs` table in database:
```sql
SELECT * FROM jobs;
```

3. Check failed jobs:
```bash
php artisan queue:failed
```

### Jobs failing silently?

Enable detailed logging in `config/queue.php`:

```php
'failed' => [
    'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
    'database' => env('DB_CONNECTION', 'mysql'),
    'table' => 'failed_jobs',
],
```

### Queue worker memory issues?

Add memory limits:
```bash
php artisan queue:work --memory=512 --timeout=300
```

## Performance Tips

1. **Use multiple workers** for high-load environments:
```bash
php artisan queue:work --queue=high,default &
php artisan queue:work --queue=high,default &
```

2. **Prioritize critical jobs** using queue names:
```php
CreateGettingStartedGuideJob::dispatch($orgId)->onQueue('low');
IngestConnectorJob::dispatch($connector)->onQueue('high');
```

3. **Monitor queue depth** to ensure workers keep up:
```bash
php artisan queue:monitor database
```

---

**Documentation generated:** 2025-10-20

