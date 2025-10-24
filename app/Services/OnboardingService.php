<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Chunk;
use App\Models\Organization;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class OnboardingService
{
    /**
     * Create getting started guide for a new organization
     */
    public static function createGettingStartedGuide(Organization $organization, string $sourceScope = 'organization'): void
    {
        try {
            $guideContent = self::getGuideContent();

            // Create the document
            $document = Document::create([
                'id' => (string) Str::uuid(),
                'org_id' => $organization->id,
                'connector_id' => null, // System document
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
                'created_for' => 'new_user_onboarding',
            ], // Laravel auto-encodes with cast
                'size' => strlen($guideContent),
                'source_scope' => $sourceScope, // Dynamic scope parameter
                'fetched_at' => now(),
            ]);

            // Chunk the content
            $chunks = self::chunkContent($guideContent);
            $embeddingService = new EmbeddingService();

            foreach ($chunks as $index => $chunkText) {
                $chunk = Chunk::create([
                    'id' => (string) Str::uuid(),
                    'document_id' => $document->id,
                    'org_id' => $organization->id,
                    'chunk_index' => $index,
                    'text' => $chunkText,
                    'char_start' => 0,
                    'char_end' => strlen($chunkText),
                    'token_count' => str_word_count($chunkText),
                ]);

                // Generate and store embedding
                try {
                    $embedding = $embeddingService->embed($chunkText, $organization->id);
                    
                    // Store embedding as binary
                    $packed = pack('f*', ...$embedding);
                    $driver = DB::connection()->getDriverName();
                    
                    if ($driver === 'pgsql') {
                        $hexData = '\\x' . bin2hex($packed);
                        DB::update('UPDATE chunks SET embedding = ?::bytea WHERE id = ?', [$hexData, $chunk->id]);
                    } else {
                        DB::table('chunks')->where('id', $chunk->id)->update(['embedding' => $packed]);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to generate embedding for guide chunk', [
                        'chunk_id' => $chunk->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Created getting started guide', [
                'org_id' => $organization->id,
                'document_id' => $document->id,
                'chunks' => count($chunks),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create getting started guide', [
                'org_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the guide content
     */
    private static function getGuideContent(): string
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
- "Summarize the meeting notes from yesterday"

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

**Response Styles** ✨
Customize how the AI responds: comprehensive, bullet points, summaries, or Q&A format.

## Tips for Best Results

1. **Connect Multiple Sources**: The more data you connect, the better KHub can help you
2. **Sync Regularly**: Keep your data up-to-date by syncing your connectors periodically
3. **Use Natural Language**: Ask questions like you would ask a colleague
4. **Be Specific**: Include details like dates, names, or project names for better results
5. **Explore Filters**: Use connector filters to search specific sources
6. **Give Feedback**: Use thumbs up/down on AI responses to help improve accuracy

## Privacy & Security

**Your Data is Safe:**
- All OAuth tokens are encrypted before storage
- Data is stored securely in our database
- We comply with GDPR and CCPA regulations
- You can disconnect sources and delete data anytime
- We never share or sell your data to third parties

**What We Access:**
- Google Drive: Only files in folders you authorize
- Slack: Only channels you select for indexing
- Dropbox: Only folders you choose to sync

**Review our Privacy Policy** at /privacy for complete details.

## Getting Help

**Navigate the App:**
- **Dashboard**: Overview of your knowledge base stats
- **Connectors**: Connect and manage cloud sources
- **Chat**: Ask questions and get AI-powered answers
- **Documents**: View, search, and manage all indexed files
- **Team**: Invite colleagues and manage permissions
- **Billing**: View usage and upgrade your plan

**Keyboard Shortcuts:**
- Enter: Send message
- Shift+Enter: New line in message
- /: Focus search bar
- Esc: Close modals

## Need Support?

If you run into any issues:
1. Check the Documents page to ensure your files are synced
2. Try refreshing the page
3. Disconnect and reconnect your source
4. Contact support at support@khub.com

## Ready to Get Started?

1. Click "Connectors" in the sidebar
2. Connect your first cloud source
3. Click "Sync" and wait for indexing
4. Come back to Chat and ask your first question!

**Example questions to try:**
- "What documents do I have about [topic]?"
- "Summarize the latest reports"
- "Find conversations about [project name]"
- "Show me files created last week"

Welcome to smarter knowledge management! 🎉
EOT;
    }

    /**
     * Chunk the content into logical sections
     */
    private static function chunkContent(string $content): array
    {
        // Split by major sections (##)
        $sections = preg_split('/^## /m', $content, -1, PREG_SPLIT_NO_EMPTY);
        $chunks = [];

        foreach ($sections as $section) {
            $trimmed = trim($section);
            if ($trimmed && strlen($trimmed) > 100) {
                // If section is too long (>2000 chars), split further
                if (strlen($trimmed) > 2000) {
                    // Split by paragraphs
                    $paragraphs = explode("\n\n", $trimmed);
                    $currentChunk = '';
                    
                    foreach ($paragraphs as $para) {
                        if (strlen($currentChunk . $para) > 2000) {
                            if ($currentChunk) {
                                $chunks[] = '## ' . $currentChunk;
                            }
                            $currentChunk = $para;
                        } else {
                            $currentChunk .= ($currentChunk ? "\n\n" : '') . $para;
                        }
                    }
                    
                    if ($currentChunk) {
                        $chunks[] = '## ' . $currentChunk;
                    }
                } else {
                    $chunks[] = '## ' . $trimmed;
                }
            }
        }

        return $chunks;
    }
}

