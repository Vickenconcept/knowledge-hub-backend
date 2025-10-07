# 🏢 Enterprise Large File Processing Strategy

## Problem Statement
For enterprise organizations, large files (contracts, presentations, videos, datasets) are common and **cannot be skipped**. The current 10MB limit is not acceptable for production.

---

## ✅ Implemented Solution: Multi-Tier Processing

### **Tier 1: Standard Processing (< 10MB)**
- ✅ Process immediately in main ingestion job
- ✅ Fast, real-time progress updates
- ✅ Completes in seconds

**Files included:** Most documents, PDFs, spreadsheets, text files

---

### **Tier 2: Deferred Processing (10MB - 100MB)** ⭐ NEW!
- ✅ Dispatched to separate `ProcessLargeFileJob`
- ✅ Dedicated queue: `large-files`
- ✅ Longer timeout: 2 hours
- ✅ 2 retry attempts
- ✅ Better error isolation (won't crash main sync)

**Files included:** Large PDFs, presentations, spreadsheets, media files

**Benefits:**
- Main sync completes quickly
- Large files process in background
- Can process multiple large files in parallel
- Better error handling and retries

---

### **Tier 3: Extremely Large Files (> 100MB)**
- ⚠️ Currently skipped with helpful log message
- 🚀 **Future:** Use cloud processing services

**Recommended for future:**
- AWS Textract (PDFs, images)
- Google Document AI (enterprise documents)
- Azure Form Recognizer (structured docs)
- Custom OCR pipeline for scanned documents

---

## 📊 File Size Thresholds

| Size Range | Action | Queue | Timeout | Status |
|------------|--------|-------|---------|--------|
| **0 - 10MB** | Process immediately | `default` | 30 min | ✅ Active |
| **10MB - 100MB** | Defer to dedicated job | `large-files` | 2 hours | ✅ Active |
| **> 100MB** | Skip (log message) | N/A | N/A | ⚠️ Manual handling |

---

## 🚀 How to Run

### **1. Start Default Queue Worker**
Processes standard files (< 10MB):
```bash
php artisan queue:work --queue=default --timeout=1800
```

### **2. Start Large Files Queue Worker**
Processes large files (10MB - 100MB):
```bash
php artisan queue:work --queue=large-files --timeout=7200 --tries=2
```

### **3. Run Both Workers in Parallel** (Recommended)
For best performance, run 2 separate terminal windows:

**Terminal 1 (Standard Files):**
```bash
php artisan queue:work --queue=default --timeout=1800 --sleep=3
```

**Terminal 2 (Large Files):**
```bash
php artisan queue:work --queue=large-files --timeout=7200 --tries=2 --sleep=5
```

---

## 📈 Expected Performance

### **Before (Skipping Large Files):**
- ❌ 10MB+ files skipped
- ❌ Enterprise customers frustrated
- ❌ Incomplete knowledge base

### **After (Multi-Tier Processing):**
- ✅ Files up to 100MB processed successfully
- ✅ Main sync completes in minutes
- ✅ Large files process in background
- ✅ Better error isolation and recovery
- ✅ Parallel processing for better throughput

### **Real-World Example:**
**Organization with 200 files:**
- 150 files < 10MB → Process in ~5 minutes (main sync)
- 40 files 10-50MB → Process in ~30-60 minutes (background)
- 10 files > 100MB → Skip with log (manual review)

**Total time:** Main sync done in 5 min, full indexing in ~1 hour

---

## 🔧 Configuration & Tuning

### **Adjust Size Thresholds**

Edit `IngestConnectorJob.php` line ~225:

```php
// Current settings:
if ($fileSize > 10 * 1024 * 1024 && $fileSize <= 100 * 1024 * 1024) { // 10MB - 100MB
    // Defer to large file job
}

// For more aggressive processing:
if ($fileSize > 20 * 1024 * 1024 && $fileSize <= 200 * 1024 * 1024) { // 20MB - 200MB
    // Defer to large file job
}
```

### **Adjust Timeouts**

Edit `ProcessLargeFileJob.php` line ~19:

```php
// Current: 2 hours
public $timeout = 7200;

// For very large files: 4 hours
public $timeout = 14400;
```

### **Adjust Retry Attempts**

Edit `ProcessLargeFileJob.php` line ~22:

```php
// Current: 2 attempts
public $tries = 2;

// For unreliable connections: 3 attempts
public $tries = 3;
```

---

## 📊 Monitoring Large File Processing

### **Check Queue Status:**
```bash
php artisan queue:monitor large-files
```

### **View Failed Jobs:**
```bash
php artisan queue:failed
```

### **Retry Failed Jobs:**
```bash
php artisan queue:retry all
```

### **Check Laravel Logs:**
```bash
tail -f storage/logs/laravel.log | grep "ProcessLargeFileJob"
```

**Look for:**
- `📦 Deferring large file to separate job` - File sent to background
- `✅ Large file processed successfully` - Success!
- `❌ Error processing large file` - Failed (check error message)

---

## 🎯 Future Enhancements (Tier 3: > 100MB)

### **Option 1: Cloud Processing Services**

**AWS Textract:**
- Best for PDFs, forms, invoices
- OCR for scanned documents
- $1.50 per 1000 pages

**Google Document AI:**
- Best for enterprise documents
- Superior accuracy
- $0.03 per page

**Implementation:**
```php
if ($fileSize > 100 * 1024 * 1024) {
    // Upload to S3
    $s3Path = $this->uploadToS3($content, $file->getName());
    
    // Trigger cloud processing
    CloudProcessingJob::dispatch($s3Path, $document->id, 'textract');
    
    // Mark as pending processing
    $document->status = 'processing';
}
```

### **Option 2: Chunked Download + Processing**

For files too large to download at once:
```php
// Stream file in chunks
$handle = $driveService->getFileStream($fileId);
while (!feof($handle)) {
    $chunk = fread($handle, 8192);
    // Process chunk incrementally
}
```

### **Option 3: Prioritize Text Extraction Only**

For videos, audio, large media:
```php
if ($mimeType === 'video/mp4') {
    // Use AWS Transcribe or similar
    TranscribeVideoJob::dispatch($s3Path, $document->id);
}
```

---

## ✅ Production Checklist

Before deploying to production:

- [ ] **Run 2 queue workers** (default + large-files)
- [ ] **Set up Supervisor** to auto-restart workers
- [ ] **Configure monitoring** (Horizon, Sentry, CloudWatch)
- [ ] **Test with real large files** (50MB PDFs, 80MB presentations)
- [ ] **Set up alerts** for failed large file jobs
- [ ] **Document which files > 100MB** need manual processing
- [ ] **Train support team** on large file handling

---

## 🛠️ Supervisor Configuration

Create `/etc/supervisor/conf.d/laravel-worker-large-files.conf`:

```ini
[program:laravel-worker-large-files]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work --queue=large-files --timeout=7200 --tries=2 --sleep=5
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/storage/logs/worker-large-files.log
stopwaitsecs=7200
```

**Reload Supervisor:**
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-worker-large-files:*
```

---

## 📞 Support & Troubleshooting

### **Issue: Large files still timing out**
- Increase `$timeout` in `ProcessLargeFileJob.php`
- Check server PHP `max_execution_time`
- Consider cloud processing for files > 100MB

### **Issue: PDF extraction fails**
- Install `pdftotext`: `sudo apt-get install poppler-utils`
- Check PDF isn't password-protected or corrupted
- Use fallback parser (Smalot) or cloud service

### **Issue: Queue worker crashes**
- Check memory limit: `php artisan queue:work --memory=512`
- Use Supervisor for auto-restart
- Check Laravel logs for errors

---

## 🎉 Summary

With this implementation:
- ✅ **Standard files (< 10MB):** Fast, immediate processing
- ✅ **Large files (10-100MB):** Background processing with 2-hour timeout
- ⚠️ **Very large files (> 100MB):** Logged for manual review (future: cloud processing)

**Result:** Production-ready for enterprise organizations! 🚀

