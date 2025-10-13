<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Slack API Service
 * Handles OAuth and data fetching from Slack workspaces
 */
class SlackService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    
    public function __construct()
    {
        $this->clientId = config('services.slack.client_id');
        $this->clientSecret = config('services.slack.client_secret');
        $this->redirectUri = config('services.slack.redirect');
    }
    
    /**
     * Generate OAuth authorization URL
     */
    public function getAuthUrl(string $state): string
    {
        $scopes = implode(',', [
            'channels:history',     // Read messages from public channels
            'channels:read',        // View basic channel info
            'channels:join',        // Join public channels (AUTO-JOIN!)
            'files:read',           // Read file attachments
            'groups:history',       // Read messages from private channels
            'groups:read',          // View basic private channel info
            'im:history',           // Read DM history
            'im:read',              // View DM info
            'mpim:history',         // Read group DM history
            'mpim:read',            // View group DM info
            'users:read',           // View users
            'team:read',            // View workspace info
        ]);
        
        return "https://slack.com/oauth/v2/authorize?" . http_build_query([
            'client_id' => $this->clientId,
            'scope' => $scopes,
            'state' => $state,
            'redirect_uri' => $this->redirectUri,
        ]);
    }
    
    /**
     * Exchange authorization code for access token
     */
    public function exchangeCode(string $code): array
    {
        try {
            $response = Http::asForm()->post('https://slack.com/api/oauth.v2.access', [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $code,
                'redirect_uri' => $this->redirectUri,
            ]);
            
            $data = $response->json();
            
            if (!$data['ok']) {
                throw new \Exception($data['error'] ?? 'OAuth exchange failed');
            }
            
            return [
                'access_token' => $data['access_token'], // Bot token (xoxb-)
                'user_token' => $data['authed_user']['access_token'] ?? null, // User token (xoxp-) - needed for files!
                'team_id' => $data['team']['id'],
                'team_name' => $data['team']['name'],
                'user_id' => $data['authed_user']['id'] ?? null,
                'bot_user_id' => $data['bot_user_id'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Slack OAuth exchange failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    
    /**
     * Get workspace info
     */
    public function getTeamInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(60)
            ->connectTimeout(30)
            ->retry(3, 5000)
            ->get('https://slack.com/api/team.info');
        
        $data = $response->json();
        
        if (!$data['ok']) {
            throw new \Exception($data['error'] ?? 'Failed to get team info');
        }
        
        return $data['team'];
    }
    
    /**
     * List all channels (public and private)
     */
    public function listChannels(string $accessToken, ?string $cursor = null): array
    {
        $params = [
            'exclude_archived' => true,
            'types' => 'public_channel,private_channel',
            'limit' => 200,
        ];
        
        if ($cursor) {
            $params['cursor'] = $cursor;
        }
        
        $response = Http::withToken($accessToken)
            ->timeout(60)
            ->connectTimeout(30)
            ->retry(3, 5000)
            ->get('https://slack.com/api/conversations.list', $params);
        
        $data = $response->json();
        
        if (!$data['ok']) {
            throw new \Exception($data['error'] ?? 'Failed to list channels');
        }
        
        return [
            'channels' => $data['channels'] ?? [],
            'next_cursor' => $data['response_metadata']['next_cursor'] ?? null,
        ];
    }
    
    /**
     * Get channel history
     */
    public function getChannelHistory(
        string $accessToken,
        string $channelId,
        ?string $oldest = null,
        ?string $latest = null,
        ?string $cursor = null
    ): array {
        $params = [
            'channel' => $channelId,
            'limit' => 200,
        ];
        
        if ($oldest) $params['oldest'] = $oldest;
        if ($latest) $params['latest'] = $latest;
        if ($cursor) $params['cursor'] = $cursor;
        
        $response = Http::withToken($accessToken)
            ->timeout(60) // 60 second timeout for slow connections
            ->connectTimeout(30) // 30 second SSL handshake timeout
            ->retry(3, 5000) // Retry 3 times with 5 second delay on failure
            ->get('https://slack.com/api/conversations.history', $params);
        
        $data = $response->json();
        
        if (!$data['ok']) {
            throw new \Exception($data['error'] ?? 'Failed to get channel history');
        }
        
        return [
            'messages' => $data['messages'] ?? [],
            'has_more' => $data['has_more'] ?? false,
            'next_cursor' => $data['response_metadata']['next_cursor'] ?? null,
        ];
    }
    
    /**
     * Get thread replies
     */
    public function getThreadReplies(
        string $accessToken,
        string $channelId,
        string $threadTs
    ): array {
        $response = Http::withToken($accessToken)
            ->timeout(60)
            ->connectTimeout(30)
            ->retry(3, 5000)
            ->get('https://slack.com/api/conversations.replies', [
                'channel' => $channelId,
                'ts' => $threadTs,
            ]);
        
        $data = $response->json();
        
        if (!$data['ok']) {
            throw new \Exception($data['error'] ?? 'Failed to get thread replies');
        }
        
        return $data['messages'] ?? [];
    }
    
    /**
     * List workspace users
     */
    public function listUsers(string $accessToken, ?string $cursor = null): array
    {
        $params = ['limit' => 200];
        
        if ($cursor) {
            $params['cursor'] = $cursor;
        }
        
        $response = Http::withToken($accessToken)
            ->timeout(60)
            ->connectTimeout(30)
            ->retry(3, 5000)
            ->get('https://slack.com/api/users.list', $params);
        
        $data = $response->json();
        
        if (!$data['ok']) {
            throw new \Exception($data['error'] ?? 'Failed to list users');
        }
        
        return [
            'members' => $data['members'] ?? [],
            'next_cursor' => $data['response_metadata']['next_cursor'] ?? null,
        ];
    }
    
    /**
     * Get user info
     */
    public function getUserInfo(string $accessToken, string $userId): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(60)
            ->connectTimeout(30)
            ->retry(3, 5000)
            ->get('https://slack.com/api/users.info', [
                'user' => $userId,
            ]);
        
        $data = $response->json();
        
        if (!$data['ok']) {
            throw new \Exception($data['error'] ?? 'Failed to get user info');
        }
        
        return $data['user'] ?? [];
    }
    
    /**
     * Test if access token is still valid
     */
    public function testAuth(string $accessToken): bool
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(60)
                ->connectTimeout(30)
                ->retry(3, 5000)
                ->get('https://slack.com/api/auth.test');
            
            $data = $response->json();
            return $data['ok'] ?? false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Join a public channel
     */
    public function joinChannel(string $accessToken, string $channelId): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(60)
                ->connectTimeout(30)
                ->retry(3, 5000)
                ->asForm()
                ->post('https://slack.com/api/conversations.join', [
                    'channel' => $channelId,
                ]);
            
            $data = $response->json();
            
            if (!$data['ok']) {
                // If already in channel, that's fine
                if ($data['error'] === 'already_in_channel') {
                    return ['success' => true, 'already_in' => true];
                }
                throw new \Exception($data['error'] ?? 'Failed to join channel');
            }
            
            return ['success' => true, 'already_in' => false];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}

