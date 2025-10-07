# 📊 Large File Progress Tracking - How It Works

## Problem: Frontend Only Showed Main Queue Progress

### ❌ **Old Behavior:**
1. Main sync processes small files (< 10MB) → **progress shows** ✅
2. Large files (10-100MB) dispatched to `large-files` queue → **progress MISSING** ❌
3. Frontend shows "Sync completed" when main job finishes
4. **But large files still processing in background (invisible to user!)** ⚠️

**User thinks sync is done, but 3 large files (60MB total) are still processing!**

---

## ✅ **New Behavior: Unified Progress Tracking**

### **How It Works:**

1. **Main Queue Job (`IngestConnectorJob`):**
   - Processes small files (< 10MB)
   - Dispatches large files (10-100MB) to `large-files` queue
   - **Tracks how many large files are pending**
   - Sets status to `processing_large_files` instead of `completed`
   - Updates `IngestJob.stats.pending_large_files` count

2. **Large Files Queue Job (`ProcessLargeFileJob`):**
   - Processes each large file individually
   - **Updates the parent `IngestJob` when complete**
   - Decrements `pending_large_files` counter
   - When `pending_large_files` reaches 0, marks `IngestJob` as `completed`

3. **Frontend Polling:**
   - Continues polling until `IngestJob.status === 'completed'`
   - Shows: "Processing 2 large file(s) in background..."
   - Progress bar stays visible
   - User sees full picture!

---

## 📊 **Frontend Display:**

### **Phase 1: Main Sync (Small Files)**
```
Syncing... 50/77 files (65%)
Current: document.pdf
Docs: 45 | Chunks: 234 | Files: 50
```

### **Phase 2: Large Files Processing**
```
Syncing... 77/77 files (100%)
Current: Processing 3 large file(s) in background...
Docs: 50 | Chunks: 234 | Files: 77
```

**Button:** Still disabled, shows "Syncing..."

### **Phase 3: Large File Completes**
```
Syncing... 77/77 files (100%)
Current: Processing 2 large file(s) in background...
Docs: 51 | Chunks: 289 | Files: 77  ← Updated!
```

### **Phase 4: All Complete**
```
Last synced: Just now
Docs: 53 | Chunks: 412 | Files: 77
```

**Button:** Re-enabled, shows "Sync Now"

---

## 🔧 **Technical Flow:**

### **IngestJob Stats Schema:**
```json
{
  "docs": 50,
  "chunks": 234,
  "errors": 0,
  "total_files": 77,
  "processed_files": 77,
  "skipped_files": 24,
  "pending_large_files": 3,  ← NEW!
  "large_files": [            ← NEW!
    "new doc.pdf",
    "Chemistry-LR.pdf",
    "Standards of Excellence.pdf"
  ],
  "current_file": "Processing 3 large file(s) in background..."
}
```

### **Status Transitions:**
```
queued
  ↓
running (processing small files)
  ↓
processing_large_files (if large files exist)
  ↓
completed (when all large files done)
```

---

## 💡 **Key Implementation Details:**

### **1. Pass IngestJob ID to Large File Jobs**
```php
ProcessLargeFileJob::dispatch(
    $connector->id,
    $this->orgId,
    $job->id,  // ← Pass IngestJob ID
    $fileData,
    $tokens
)->onQueue('large-files');
```

### **2. Update Parent Job When Large File Completes**
```php
// In ProcessLargeFileJob->handle()
$ingestJob = IngestJob::find($this->ingestJobId);
$stats = $ingestJob->stats;
$stats['docs'] += 1;
$stats['chunks'] += count($textChunks);
$stats['pending_large_files'] -= 1;

if ($stats['pending_large_files'] === 0) {
    $ingestJob->status = 'completed';
    $ingestJob->finished_at = now();
}
$ingestJob->save();
```

### **3. Frontend Continues Polling**
```typescript
// useConnectors.ts
if (jobData.status === 'completed' || jobData.status === 'failed') {
    clearInterval(pollInterval);  // Stop polling
}

// If status is 'processing_large_files', keep polling!
```

---

## 🎯 **User Experience:**

### **Scenario 1: Only Small Files**
- Sync starts → Progress: 0%
- Main job processes → Progress: 100%
- Status: `completed`
- Frontend: Stops polling, shows "Last synced: just now"

### **Scenario 2: Mixed Small + Large Files**
- Sync starts → Progress: 0%
- Main job processes small files → Progress: 95% (50/77 files)
- Large files dispatched → Status: `processing_large_files`
- Frontend: **Keeps polling**, shows "Processing 3 large file(s)..."
- Large file 1 completes → Docs count increases
- Large file 2 completes → Chunks count increases
- Large file 3 completes → Status changes to `completed`
- Frontend: Stops polling, shows final stats

### **Scenario 3: User Refreshes During Large File Processing**
- User refreshes page
- Frontend checks job status: `processing_large_files`
- **Auto-resumes polling** ✅
- Shows: "Processing 2 large file(s) in background..."
- Progress continues seamlessly

---

## ✅ **Benefits:**

1. ✅ **Accurate progress** - User sees when sync is TRULY complete
2. ✅ **Transparent** - User knows large files are processing
3. ✅ **Real-time updates** - Stats update as large files complete
4. ✅ **Refresh-safe** - Works across page refreshes
5. ✅ **Professional UX** - No surprises, no confusion

---

## 📝 **Testing:**

### **To verify it's working:**

1. Start sync with large files (> 10MB in your Google Drive)
2. Watch frontend progress
3. When main sync completes, should show:
   ```
   Current: Processing 2 large file(s) in background...
   ```
4. Refresh the page → Progress should resume
5. Wait for large files to complete
6. Status should change to "Last synced: just now"

### **Logs to look for:**
```
📦 Deferring large file to separate job: new doc.pdf (28.14 MB)
=== ProcessLargeFileJob STARTED === {"file_name":"new doc.pdf"}
✅ Large file processed successfully {"chunks_created":123}
IngestJob stats updated from large file job {"pending_large_files":2}
IngestJob stats updated from large file job {"pending_large_files":1}
IngestJob stats updated from large file job {"pending_large_files":0,"status":"completed"}
```

---

## 🎉 **Summary:**

**Frontend now tracks BOTH queues!**
- ✅ Main queue (small files) - real-time progress
- ✅ Large files queue - background processing with updates
- ✅ Unified status in `IngestJob`
- ✅ Seamless user experience

**No more invisible background processing!** 🚀

