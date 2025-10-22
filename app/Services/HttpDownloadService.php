<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class HttpDownloadService
{
    private Client $client;
    private array $config;

    public function __construct()
    {
        $this->config = [
            'timeout' => 30, // 30 seconds total timeout
            'connect_timeout' => 10, // 10 seconds to establish connection
            'read_timeout' => 20, // 20 seconds to read response
            'max_retries' => 3,
            'retry_delay' => 1000, // 1 second base delay
            'max_retry_delay' => 10000, // 10 seconds max delay
            'backoff_multiplier' => 2,
            'max_file_size' => 50 * 1024 * 1024, // 50MB max file size
            'user_agent' => 'KHub/1.0 (+https://khub.app)',
        ];

        $this->client = new Client([
            'timeout' => $this->config['timeout'],
            'connect_timeout' => $this->config['connect_timeout'],
            'read_timeout' => $this->config['read_timeout'],
            'headers' => [
                'User-Agent' => $this->config['user_agent'],
                'Accept' => '*/*',
            ],
            'allow_redirects' => [
                'max' => 5,
                'strict' => false,
                'referer' => true,
                'protocols' => ['http', 'https'],
            ],
            'verify' => true, // Verify SSL certificates
        ]);
    }

    /**
     * Download content from URL with retries and backoff
     */
    public function download(string $url, array $options = []): array
    {
        $maxRetries = $options['max_retries'] ?? $this->config['max_retries'];
        $retryDelay = $options['retry_delay'] ?? $this->config['retry_delay'];
        $maxFileSize = $options['max_file_size'] ?? $this->config['max_file_size'];

        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                Log::info("Download attempt {$attempt} for URL", [
                    'url' => $url,
                    'attempt' => $attempt + 1,
                    'max_retries' => $maxRetries + 1
                ]);

                $response = $this->client->get($url, [
                    'stream' => true, // Stream to handle large files
                    'on_headers' => function ($response) use ($maxFileSize) {
                        $contentLength = $response->getHeaderLine('Content-Length');
                        if ($contentLength && (int)$contentLength > $maxFileSize) {
                            throw new \Exception("File too large: {$contentLength} bytes (max: {$maxFileSize})");
                        }
                    }
                ]);

                $statusCode = $response->getStatusCode();
                $contentType = $response->getHeaderLine('Content-Type');
                $contentLength = $response->getHeaderLine('Content-Length');

                // Check for successful response
                if ($statusCode >= 200 && $statusCode < 300) {
                    $body = $response->getBody();
                    $content = $body->getContents();
                    $actualLength = strlen($content);

                    // Verify actual content length
                    if ($actualLength > $maxFileSize) {
                        throw new \Exception("Downloaded content too large: {$actualLength} bytes (max: {$maxFileSize})");
                    }

                    Log::info("Download successful", [
                        'url' => $url,
                        'status_code' => $statusCode,
                        'content_type' => $contentType,
                        'content_length' => $actualLength,
                        'attempt' => $attempt + 1
                    ]);

                    return [
                        'success' => true,
                        'content' => $content,
                        'content_type' => $contentType,
                        'content_length' => $actualLength,
                        'status_code' => $statusCode,
                        'headers' => $response->getHeaders(),
                    ];
                }

                // Handle client errors (4xx) - don't retry
                if ($statusCode >= 400 && $statusCode < 500) {
                    throw new \Exception("Client error: HTTP {$statusCode}");
                }

                // Handle server errors (5xx) - retry
                if ($statusCode >= 500) {
                    throw new \Exception("Server error: HTTP {$statusCode}");
                }

            } catch (RequestException $e) {
                $lastException = $e;
                $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;

                // Handle rate limiting (429) with exponential backoff
                if ($statusCode === 429) {
                    $retryAfter = $this->getRetryAfterHeader($e);
                    $delay = $retryAfter ?: $this->calculateBackoffDelay($attempt, $retryDelay);
                    
                    Log::warning("Rate limited, waiting before retry", [
                        'url' => $url,
                        'retry_after' => $retryAfter,
                        'calculated_delay' => $delay,
                        'attempt' => $attempt + 1
                    ]);

                    if ($attempt < $maxRetries) {
                        usleep($delay * 1000); // Convert to microseconds
                        $attempt++;
                        continue;
                    }
                }

                // Handle connection errors - retry
                if ($e instanceof ConnectException || $e instanceof TooManyRedirectsException) {
                    Log::warning("Connection error, retrying", [
                        'url' => $url,
                        'error' => $e->getMessage(),
                        'attempt' => $attempt + 1
                    ]);

                    if ($attempt < $maxRetries) {
                        $delay = $this->calculateBackoffDelay($attempt, $retryDelay);
                        usleep($delay * 1000);
                        $attempt++;
                        continue;
                    }
                }

                // Handle other request exceptions
                Log::error("Request failed", [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'status_code' => $statusCode,
                    'attempt' => $attempt + 1
                ]);

                if ($attempt < $maxRetries) {
                    $delay = $this->calculateBackoffDelay($attempt, $retryDelay);
                    usleep($delay * 1000);
                    $attempt++;
                    continue;
                }

            } catch (\Exception $e) {
                $lastException = $e;
                Log::error("Download failed", [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt + 1
                ]);

                if ($attempt < $maxRetries) {
                    $delay = $this->calculateBackoffDelay($attempt, $retryDelay);
                    usleep($delay * 1000);
                    $attempt++;
                    continue;
                }
            }

            break;
        }

        // All retries exhausted
        Log::error("Download failed after all retries", [
            'url' => $url,
            'max_retries' => $maxRetries,
            'last_error' => $lastException ? $lastException->getMessage() : 'Unknown error'
        ]);

        return [
            'success' => false,
            'error' => $lastException ? $lastException->getMessage() : 'Download failed after all retries',
            'attempts' => $attempt + 1,
        ];
    }

    /**
     * Download and save to temporary file
     */
    public function downloadToTempFile(string $url, array $options = []): array
    {
        $result = $this->download($url, $options);
        
        if (!$result['success']) {
            return $result;
        }

        try {
            $tempFile = tempnam(sys_get_temp_dir(), 'khub_download_');
            if ($tempFile === false) {
                throw new \Exception('Failed to create temporary file');
            }

            $bytesWritten = file_put_contents($tempFile, $result['content']);
            if ($bytesWritten === false) {
                throw new \Exception('Failed to write to temporary file');
            }

            Log::info("Downloaded to temporary file", [
                'url' => $url,
                'temp_file' => $tempFile,
                'size' => $bytesWritten
            ]);

            return [
                'success' => true,
                'temp_file' => $tempFile,
                'content_type' => $result['content_type'],
                'content_length' => $result['content_length'],
                'status_code' => $result['status_code'],
            ];

        } catch (\Exception $e) {
            Log::error("Failed to save download to temp file", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if URL is accessible without downloading content
     */
    public function checkUrl(string $url): array
    {
        try {
            $response = $this->client->head($url);
            $statusCode = $response->getStatusCode();
            $contentType = $response->getHeaderLine('Content-Type');
            $contentLength = $response->getHeaderLine('Content-Length');

            return [
                'success' => $statusCode >= 200 && $statusCode < 300,
                'status_code' => $statusCode,
                'content_type' => $contentType,
                'content_length' => $contentLength ? (int)$contentLength : null,
                'headers' => $response->getHeaders(),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Calculate exponential backoff delay
     */
    private function calculateBackoffDelay(int $attempt, int $baseDelay): int
    {
        $delay = $baseDelay * pow($this->config['backoff_multiplier'], $attempt);
        return min($delay, $this->config['max_retry_delay']);
    }

    /**
     * Extract Retry-After header value
     */
    private function getRetryAfterHeader(RequestException $e): ?int
    {
        $response = $e->getResponse();
        if (!$response) {
            return null;
        }

        $retryAfter = $response->getHeaderLine('Retry-After');
        if (empty($retryAfter)) {
            return null;
        }

        // Handle both seconds and HTTP date formats
        if (is_numeric($retryAfter)) {
            return (int)$retryAfter;
        }

        // Try to parse HTTP date
        $timestamp = strtotime($retryAfter);
        if ($timestamp !== false) {
            return max(0, $timestamp - time());
        }

        return null;
    }

    /**
     * Clean up temporary file
     */
    public function cleanupTempFile(string $tempFile): bool
    {
        if (file_exists($tempFile)) {
            return unlink($tempFile);
        }
        return true;
    }
}
