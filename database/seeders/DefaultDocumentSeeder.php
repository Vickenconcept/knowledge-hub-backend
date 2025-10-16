<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Document;
use App\Models\Chunk;
use App\Models\Organization;
use App\Services\EmbeddingService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class DefaultDocumentSeeder extends Seeder
{
    /**
     * Seed default getting started guide for all organizations
     */
    public function run(): void
    {
        $organizations = Organization::all();
        
        if ($organizations->isEmpty()) {
            $this->command->info('No organizations found. Skipping default document seeding.');
            return;
        }

        $embeddingService = new EmbeddingService();

        foreach ($organizations as $org) {
            // Check if org already has documents
            $existingDocs = Document::where('org_id', $org->id)->count();
            
            if ($existingDocs > 0) {
                $this->command->info("Organization '{$org->name}' already has documents. Skipping...");
                continue;
            }

            $this->createGettingStartedGuide($org, $embeddingService);
            
            $this->command->info("✅ Created Getting Started Guide for '{$org->name}'");
        }
    }

    /**
     * Create the getting started guide document
     */
    private function createGettingStartedGuide(Organization $org, EmbeddingService $embeddingService): void
    {
        $guideContent = $this->getGuideContent();

        // Create the document
        $document = Document::create([
            'id' => (string) Str::uuid(),
            'org_id' => $org->id,
            'connector_id' => null, // System document, not from connector
            'title' => '🚀 Getting Started with KHub - Your Knowledge Management Guide',
            'source_url' => null,
            'mime_type' => 'text/plain',
            'doc_type' => 'guide',
            'summary' => 'Complete guide to connecting cloud sources, syncing data, and using KHub effectively.',
            'tags' => ['getting-started', 'guide', 'tutorial', 'setup'], // Laravel auto-encodes with cast
            'metadata' => [
                'is_system_document' => true,
                'category' => 'onboarding',
                'version' => '1.0',
            ], // Laravel auto-encodes with cast
            'size' => strlen($guideContent),
            'fetched_at' => now(),
        ]);

        // Chunk the content
        $chunks = $this->chunkContent($guideContent);

        foreach ($chunks as $index => $chunkText) {
            $chunk = Chunk::create([
                'id' => (string) Str::uuid(),
                'document_id' => $document->id,
                'org_id' => $org->id,
                'chunk_index' => $index,
                'text' => $chunkText,
                'char_start' => 0,
                'char_end' => strlen($chunkText),
                'token_count' => str_word_count($chunkText),
            ]);

            // Generate and store embedding
            try {
                $embedding = $embeddingService->embed($chunkText, $org->id);
                
                // Store embedding as binary
                $packed = pack('f*', ...$embedding);
                $driver = \DB::connection()->getDriverName();
                
                if ($driver === 'pgsql') {
                    $hexData = '\\x' . bin2hex($packed);
                    \DB::update('UPDATE chunks SET embedding = ?::bytea WHERE id = ?', [$hexData, $chunk->id]);
                } else {
                    \DB::table('chunks')->where('id', $chunk->id)->update(['embedding' => $packed]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to generate embedding for guide chunk', [
                    'chunk_id' => $chunk->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Created getting started guide', [
            'org_id' => $org->id,
            'document_id' => $document->id,
            'chunks' => count($chunks),
        ]);
    }

    /**
     * Get the guide content
     */
    private function getGuideContent(): string
    {
        return <<<EOT
# Welcome to KHub - Your AI-Powered Knowledge Management System! 🎉

Congratulations on setting up your account! KHub helps you search, organize, and interact with all your documents and conversations from one place using AI.

## Quick Start: Connect Your Cloud Sources

To get the most out of KHub, you'll want to connect your cloud storage and communication platforms. Here's how:

### Step 1: Navigate to Connectors
Click on "Connectors" in the left sidebar to see all available integrations.

### Step 2: Connect Your Sources

**Google Drive** 📁
- Click "Connect" on the Google Drive card
- Authorize KHub to access your files
- We only read files you give us permission to access
- Your data stays secure and encrypted

**Slack** 💬
- Connect your Slack workspace
- Choose which channels to index
- Search through your team's conversations
- Find important discussions instantly

**Dropbox** 📦
- Link your Dropbox account
- Select folders to sync
- Access all your files from one place

### Step 3: Sync Your Data
After connecting a source:
1. Click the "Sync" button on the connector card
2. Watch the progress bar as your data is indexed
3. Once complete, you can search through all your content!

## How to Use KHub

### Search Your Documents
Use the chat interface to ask questions:
- "Show me all reports from last month"
- "Find emails about the project proposal"
- "What did we discuss about the budget?"

### AI-Powered Answers
KHub uses advanced AI to:
- Understand your questions in natural language
- Search across all your connected sources
- Provide accurate answers with source citations
- Remember conversation context for follow-up questions

### Upload Documents Directly
Don't have cloud storage? No problem!
- Go to "Documents" page
- Click "Upload Document"
- Drag and drop files (PDF, DOCX, TXT, CSV, HTML)
- Instantly searchable!

## Features You'll Love

**Multi-Source Search** 🔍
Search across Google Drive, Slack, Dropbox, and uploaded files simultaneously.

**Conversation History** 💭
All your chats are saved. Come back anytime and continue where you left off.

**Source Citations** 📚
Every answer includes links to the source documents, so you can verify and dig deeper.

**Team Collaboration** 👥
Invite team members from the Team page. Everyone gets access to the same knowledge base.

**Smart Organization** 🗂️
Documents are automatically categorized and tagged for easy filtering.

## Tips for Best Results

1. **Connect Multiple Sources**: The more data you connect, the better KHub can help you
2. **Sync Regularly**: Keep your data up-to-date by syncing your connectors periodically
3. **Use Natural Language**: Ask questions like you would ask a colleague
4. **Be Specific**: Include details like dates, names, or project names for better results
5. **Explore Filters**: Use connector filters to search specific sources

## Need Help?

**Getting Started Checklist:**
- [ ] Connect at least one cloud source (Google Drive, Slack, or Dropbox)
- [ ] Sync your data using the "Sync" button
- [ ] Try your first search in the Chat page
- [ ] Invite team members (optional)
- [ ] Upload additional documents if needed

**Common Questions:**

**Q: How long does syncing take?**
A: Usually 1-5 minutes depending on how many files you have. You'll see a progress bar showing the status.

**Q: Is my data secure?**
A: Absolutely! All data is encrypted in transit and at rest. OAuth tokens are encrypted. We never share your data.

**Q: Can I disconnect a source?**
A: Yes! Just click "Disconnect" on any connector card. Your data will be removed from KHub.

**Q: How much does it cost?**
A: Check the Billing page to see your current plan and usage. We have Free, Starter, Pro, and Enterprise tiers.

## What's Next?

1. **Connect Your First Source**: Head to Connectors and link Google Drive, Slack, or Dropbox
2. **Sync Your Data**: Click the Sync button and wait for indexing to complete
3. **Start Searching**: Come back to Chat and ask anything about your documents!

That's it! You're ready to unlock the power of AI-driven knowledge management. If you have questions, just ask me - I'm here to help! 🚀

---

**Remember**: This guide will always be here. You can search for setup instructions anytime by asking "How do I connect Google Drive?" or "How do I sync my data?"

Welcome aboard! Let's get started! 🎉
EOT;
    }

    /**
     * Chunk the content into manageable pieces
     */
    private function chunkContent(string $content): array
    {
        // Split by major sections (##)
        $sections = preg_split('/^## /m', $content);
        $chunks = [];

        // First chunk: Welcome and Quick Start
        if (isset($sections[0]) && isset($sections[1])) {
            $chunks[] = $sections[0] . '## ' . $sections[1];
        }

        // Subsequent sections as individual chunks
        for ($i = 2; $i < count($sections); $i++) {
            if (trim($sections[$i])) {
                $chunks[] = '## ' . $sections[$i];
            }
        }

        return $chunks;
    }
}

