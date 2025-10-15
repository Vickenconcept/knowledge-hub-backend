<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Installs pgvector extension for native vector operations in PostgreSQL
     * This gives 10-100x faster vector similarity search!
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'pgsql') {
            try {
                // Try to install pgvector extension
                DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
                
                // Add native vector column (1536 dimensions for OpenAI embeddings)
                DB::statement('ALTER TABLE chunks ADD COLUMN embedding_vector vector(1536)');
                
                // Create HNSW index for fast similarity search
                // HNSW (Hierarchical Navigable Small World) is the fastest indexing method
                DB::statement('
                    CREATE INDEX chunks_embedding_vector_idx 
                    ON chunks 
                    USING hnsw (embedding_vector vector_cosine_ops)
                ');
                
                Log::info('✅ pgvector extension installed successfully!');
                Log::info('✅ Native vector column and HNSW index created!');
                Log::info('🚀 Vector search will now be 10-100x faster!');
                
            } catch (\Exception $e) {
                // pgvector not available - skip this migration gracefully
                Log::warning('⚠️ pgvector extension not available on this PostgreSQL installation');
                Log::warning('⚠️ Falling back to BYTEA storage (still works, but slower)');
                Log::info('📖 To install pgvector, see: https://github.com/pgvector/pgvector#installation');
                
                // Don't throw - let the migration succeed
                // The BYTEA column (embedding) still works fine
            }
        } else {
            // For MySQL, this migration does nothing
            Log::info('⚠️ Skipping pgvector installation (only for PostgreSQL)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'pgsql') {
            // Drop the index first
            DB::statement('DROP INDEX IF EXISTS chunks_embedding_vector_idx');
            
            // Drop the vector column
            DB::statement('ALTER TABLE chunks DROP COLUMN IF EXISTS embedding_vector');
            
            // Drop the extension (only if nothing else uses it)
            // Commented out for safety - other tables might use it
            // DB::statement('DROP EXTENSION IF EXISTS vector');
            
            Log::info('✅ Native vector column and index removed');
        }
    }
};
