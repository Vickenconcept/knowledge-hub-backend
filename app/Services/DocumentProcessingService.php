<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Chunk;
use App\Services\DocumentExtractionService;
use App\Services\DocumentClassificationService;
use App\Services\EmbeddingService;
use App\Services\VectorStoreService;
use App\Services\ChunkingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Unified Document Processing Service
 * 
 * Single source of truth for processing documents from any connector type.
 * Handles: text extraction, classification, chunking, embedding, and storage.
 * 
 * This eliminates code duplication across Google Drive, Dropbox, Manual Upload, etc.
 */
class DocumentProcessingService
{
    private DocumentExtractionService $extractor;
    private DocumentClassificationService $classifier;
    private EmbeddingService $embeddingService;
    private VectorStoreService $vectorStore;
    private ChunkingService $chunkingService;

    public function __construct()
    {
        $this->extractor = new DocumentExtractionService();
        $this->classifier = new DocumentClassificationService();
        $this->embeddingService = new EmbeddingService();
        $this->vectorStore = new VectorStoreService();
        $this->chunkingService = new ChunkingService();
    }

    /**
     * Process a single document from any connector type
     * 
     * @param array $documentData Document data from connector
     * @param string $orgId Organization ID
     * @param string $connectorId Connector ID
     * @param string $connectorType Type of connector (google_drive, dropbox, manual_upload, etc.)
     * @param array $options Processing options
     * @return array Processing result
     */
    public function processDocument(array $documentData, string $orgId, string $connectorId, string $connectorType, array $options = [], $job = null): array
    {
        $startTime = microtime(true);
        
        Log::info('Processing document', [
            'title' => $documentData['title'] ?? 'Unknown',
            'connector_type' => $connectorType,
            'org_id' => $orgId
        ]);

        // Update job progress - starting document
        if ($job) {
            $job->stats = array_merge($job->stats, [
                'current_file' => $documentData['title'] ?? 'Unknown',
            ]);
            $job->save();
        }

        try {
            // 1. Extract text content
            $text = $this->extractText($documentData, $connectorType);
            
            if (empty(trim($text))) {
                return [
                    'success' => false,
                    'error' => 'No text content extracted',
                    'document_id' => null
                ];
            }

            // 2. Classify document and extract metadata
            $classification = $this->classifyDocument($text, $documentData, $connectorType);

            // 3. Create or update document record
            $document = $this->createDocumentRecord($documentData, $orgId, $connectorId, $classification, $text);

            // 4. Process chunks and embeddings
            $chunkResult = $this->processChunks($document, $text, $orgId, $options);

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('Document processing completed', [
                'document_id' => $document->id,
                'title' => $document->title,
                'doc_type' => $document->doc_type,
                'chunks_created' => $chunkResult['chunks_created'],
                'processing_time_ms' => $processingTime
            ]);

            // Update job progress - document completed
            if ($job) {
                $currentStats = $job->stats ?? [];
                $job->stats = array_merge($currentStats, [
                    'docs' => ($currentStats['docs'] ?? 0) + 1,
                    'chunks' => ($currentStats['chunks'] ?? 0) + $chunkResult['chunks_created'],
                    'processed_files' => ($currentStats['processed_files'] ?? 0) + 1,
                ]);
                $job->save();
            }

            return [
                'success' => true,
                'document_id' => $document->id,
                'chunks_created' => $chunkResult['chunks_created'],
                'processing_time_ms' => $processingTime,
                'classification' => $classification
            ];

        } catch (\Exception $e) {
            Log::error('Document processing failed', [
                'title' => $documentData['title'] ?? 'Unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update job progress - error occurred
            if ($job) {
                $currentStats = $job->stats ?? [];
                $job->stats = array_merge($currentStats, [
                    'errors' => ($currentStats['errors'] ?? 0) + 1,
                ]);
                $job->save();
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'document_id' => null
            ];
        }
    }

    /**
     * Extract text content from document data
     */
    private function extractText(array $documentData, string $connectorType): string
    {
        // Check if text is already extracted (e.g., from manual upload)
        if (!empty($documentData['extracted_text'])) {
            Log::info('Using pre-extracted text');
            return $documentData['extracted_text'];
        }

        // Extract based on connector type
        switch ($connectorType) {
            case 'manual_upload':
                return $this->extractFromManualUpload($documentData);
            
            case 'google_drive':
                return $this->extractFromGoogleDrive($documentData);
            
            case 'dropbox':
                return $this->extractFromDropbox($documentData);
            
            case 'notion':
                return $this->extractFromNotion($documentData);
            
            case 'slack':
                return $this->extractFromSlack($documentData);
            
            default:
                throw new \InvalidArgumentException("Unsupported connector type: {$connectorType}");
        }
    }

    /**
     * Extract text from manual upload
     */
    private function extractFromManualUpload(array $documentData): string
    {
        // Check if we have pre-extracted text
        if (!empty($documentData['extracted_text'])) {
            return $documentData['extracted_text'];
        }
        
        // Check if we have content directly
        if (!empty($documentData['content'])) {
            return $documentData['content'];
        }
        
        $tmpPath = $documentData['tmp_path'] ?? null;
        $mimeType = $documentData['mime_type'] ?? 'application/octet-stream';
        
        if ($tmpPath && file_exists($tmpPath)) {
            return $this->extractor->extractText($tmpPath, $mimeType);
        }
        
        // Fallback to cloud URL
        $source = $documentData['s3_path'] ?? $documentData['source_url'] ?? null;
        if ($source && filter_var($source, FILTER_VALIDATE_URL)) {
            $downloadService = new HttpDownloadService();
            $result = $downloadService->download($source);
            return $result['success'] ? $this->extractor->extractText($result['content'], $mimeType) : '';
        }
        
        if ($source) {
            return $this->extractor->extractText($source, $mimeType);
        }
        
        return '';
    }

    /**
     * Extract text from Google Drive
     */
    private function extractFromGoogleDrive(array $documentData): string
    {
        $content = $documentData['content'] ?? '';
        $mimeType = $documentData['mime_type'] ?? 'application/octet-stream';
        
        if (empty($content)) {
            return '';
        }
        
        return $this->extractor->extractText($content, $mimeType);
    }

    /**
     * Extract text from Dropbox
     */
    private function extractFromDropbox(array $documentData): string
    {
        $content = $documentData['content'] ?? '';
        $mimeType = $documentData['mime_type'] ?? 'application/octet-stream';
        
        if (empty($content)) {
            return '';
        }
        
        return $this->extractor->extractText($content, $mimeType);
    }

    /**
     * Extract text from Notion
     */
    private function extractFromNotion(array $documentData): string
    {
        return $documentData['content'] ?? '';
    }

    /**
     * Extract text from Slack
     */
    private function extractFromSlack(array $documentData): string
    {
        return $documentData['content'] ?? '';
    }

    /**
     * Classify document and extract metadata
     */
    private function classifyDocument(string $text, array $documentData, string $connectorType): array
    {
        $filename = $documentData['title'] ?? $documentData['name'] ?? 'unknown';
        $mimeType = $documentData['mime_type'] ?? 'application/octet-stream';
        
        return $this->classifier->classifyDocument($text, $filename, $mimeType);
    }

    /**
     * Create or update document record
     */
    private function createDocumentRecord(array $documentData, string $orgId, string $connectorId, array $classification, string $text): Document
    {
        // Check if document already exists (for updates)
        $existingDocument = null;
        if (!empty($documentData['id'])) {
            $existingDocument = Document::find($documentData['id']);
        }

        // Upload content to Cloudinary if not already uploaded
        $s3Path = $documentData['s3_path'] ?? null;
        if (!$s3Path && !empty($text)) {
            $s3Path = $this->uploadContentToCloudinary($text, $documentData['title'] ?? 'document');
        }

        $documentData = array_merge([
            'org_id' => $orgId,
            'connector_id' => $connectorId,
            'doc_type' => $classification['doc_type'],
            'tags' => $classification['tags'],
            'size' => $documentData['size'] ?? strlen($text), // Set size from original data or text length
            's3_path' => $s3Path,
            'metadata' => array_merge($documentData['metadata'] ?? [], $classification['metadata'], [
                'extracted_text' => $text,
                'processed_at' => now()->toISOString(),
            ])
        ], $documentData);

        if ($existingDocument) {
            $existingDocument->update($documentData);
            return $existingDocument;
        }

        return Document::create($documentData);
    }

    /**
     * Upload content to Cloudinary
     */
    private function uploadContentToCloudinary(string $content, string $filename): ?string
    {
        try {
            // Use Cloudinary directly to upload raw content
            $cloudinary = new \Cloudinary\Cloudinary();
            
            // Create a data URI for the content
            $dataUri = 'data:text/plain;base64,' . base64_encode($content);
            
            // Upload to Cloudinary
            $upload = $cloudinary->uploadApi()->upload($dataUri, [
                'resource_type' => 'raw',
                'folder' => 'knowledgehub/slack',
                'public_id' => 'slack_' . uniqid(),
                'use_filename' => true,
                'unique_filename' => true,
            ]);
            
            Log::info('Content uploaded to Cloudinary', [
                'filename' => $filename,
                'content_length' => strlen($content),
                'cloudinary_url' => $upload['secure_url'] ?? 'null'
            ]);
            
            return $upload['secure_url'] ?? null;
            
        } catch (\Exception $e) {
            Log::error('Failed to upload content to Cloudinary', [
                'filename' => $filename,
                'content_length' => strlen($content),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Process chunks and embeddings
     */
    private function processChunks(Document $document, string $text, string $orgId, array $options = []): array
    {
        // Delete existing chunks for this document
        Chunk::where('document_id', $document->id)->delete();

        // Create new chunks
        $chunkData = $this->chunkingService->splitWithOverlap($text);
        
        // Create chunk records in database
        $chunks = [];
        foreach ($chunkData as $index => $chunk) {
            $chunkRecord = Chunk::create([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'document_id' => $document->id,
                'chunk_index' => $index,
                'text' => $chunk['text'],
                'char_start' => $chunk['char_start'],
                'char_end' => $chunk['char_end'],
                'token_count' => str_word_count($chunk['text']),
            ]);
            $chunks[] = $chunkRecord;
        }
        
        if (empty($chunks)) {
            return ['chunks_created' => 0];
        }

        // Generate embeddings
        $embeddings = $this->embeddingService->embedBatch(
            array_column($chunks, 'text'),
            $orgId
        );

        // Store in vector database
        $vectors = [];
        foreach ($chunks as $index => $chunk) {
            $vectors[] = [
                'id' => $chunk['id'],
                'values' => $embeddings[$index],
                'metadata' => [
                    'chunk_id' => $chunk['id'],
                    'document_id' => $document->id,
                    'org_id' => $orgId,
                ]
            ];
        }

        $this->vectorStore->upsert($vectors, $orgId, $orgId, $document->id);

        return ['chunks_created' => count($chunks)];
    }

    /**
     * Process multiple documents in batch
     */
    public function processDocuments(array $documents, string $orgId, string $connectorId, string $connectorType, array $options = []): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'total_chunks' => 0,
            'errors' => []
        ];

        foreach ($documents as $documentData) {
            $result = $this->processDocument($documentData, $orgId, $connectorId, $connectorType, $options);
            
            if ($result['success']) {
                $results['success']++;
                $results['total_chunks'] += $result['chunks_created'];
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'title' => $documentData['title'] ?? 'Unknown',
                    'error' => $result['error']
                ];
            }
        }

        return $results;
    }
}
