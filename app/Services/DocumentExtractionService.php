<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DocumentExtractionService
{
    // File size limits by type (in bytes)
    private const SIZE_LIMITS = [
        'application/pdf' => 50 * 1024 * 1024,      // 50MB
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 25 * 1024 * 1024, // 25MB
        'application/msword' => 10 * 1024 * 1024,   // 10MB
        'text/plain' => 10 * 1024 * 1024,           // 10MB
        'text/html' => 10 * 1024 * 1024,            // 10MB
        'text/csv' => 5 * 1024 * 1024,              // 5MB
        'default' => 5 * 1024 * 1024,               // 5MB default
    ];

    public function extractText($content, $mimeType, $filename = null)
    {
        try {
            // Allow passing either raw bytes or a filesystem path
            if (is_string($content) && file_exists($content)) {
                $content = @file_get_contents($content) ?: '';
            }

            // Validate content size
            $contentSize = strlen($content);
            $maxSize = $this->getSizeLimit($mimeType);
            
            if ($contentSize > $maxSize) {
                throw new \Exception("File too large: {$contentSize} bytes (max: {$maxSize} bytes)");
            }

            // Detect MIME type using finfo if not provided or unreliable
            $detectedMimeType = $this->detectMimeType($content, $filename, $mimeType);
            
            // Check if it's a DOCX file based on filename or content signature
            $isDocx = $this->isDocxFile($content, $filename);

            $result = match (true) {
                str_contains($detectedMimeType, 'text/plain') => $this->extractPlainText($content),
                str_contains($detectedMimeType, 'text/html') => $this->extractHtmlText($content),
                str_contains($detectedMimeType, 'text/csv') => $this->extractCsvText($content),
                str_contains($detectedMimeType, 'application/pdf') => $this->extractPdfText($content),
                str_contains($detectedMimeType, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') => $this->extractDocxText($content),
                str_contains($detectedMimeType, 'application/msword') => $this->extractDocText($content),
                str_contains($detectedMimeType, 'application/vnd.google-apps.') => $this->sanitizeText($content), // Already extracted as text but sanitize it
                // Handle DOCX files misdetected as application/zip
                str_contains($detectedMimeType, 'application/zip') && $isDocx => $this->extractDocxText($content),
                default => $this->extractPlainText($content)
            };

            Log::info("Text extraction completed", [
                'filename' => $filename,
                'original_mime' => $mimeType,
                'detected_mime' => $detectedMimeType,
                'content_size' => $contentSize,
                'extracted_length' => strlen($result)
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error("Error extracting text from {$filename} (type: {$mimeType}): " . $e->getMessage(), [
                'content_size' => strlen($content),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return structured error instead of raw exception message
            return $this->createErrorText($e, $filename, $mimeType);
        }
    }

    /**
     * Detect MIME type using finfo
     */
    private function detectMimeType(string $content, ?string $filename, ?string $providedMimeType): string
    {
        // If we have a reliable MIME type, use it
        if ($providedMimeType && !in_array($providedMimeType, ['application/octet-stream', 'application/zip'])) {
            return $providedMimeType;
        }

        // Use finfo to detect MIME type from content
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            Log::warning("Failed to open finfo, using provided MIME type", ['mime' => $providedMimeType]);
            return $providedMimeType ?? 'application/octet-stream';
        }

        $detectedMime = finfo_buffer($finfo, $content);
        finfo_close($finfo);

        if ($detectedMime === false || $detectedMime === 'application/octet-stream') {
            // Fallback to filename extension
            if ($filename) {
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $extensionMimeMap = [
                    'pdf' => 'application/pdf',
                    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'doc' => 'application/msword',
                    'txt' => 'text/plain',
                    'html' => 'text/html',
                    'htm' => 'text/html',
                    'csv' => 'text/csv',
                ];
                
                if (isset($extensionMimeMap[$extension])) {
                    $detectedMime = $extensionMimeMap[$extension];
                }
            }
        }

        Log::info("MIME type detection", [
            'provided' => $providedMimeType,
            'detected' => $detectedMime,
            'filename' => $filename
        ]);

        return $detectedMime ?: ($providedMimeType ?? 'application/octet-stream');
    }

    /**
     * Check if content is a DOCX file
     */
    private function isDocxFile(string $content, ?string $filename): bool
    {
        // Check filename extension
        if ($filename && (str_ends_with(strtolower($filename), '.docx') || str_ends_with(strtolower($filename), '.DOCX'))) {
            return true;
        }

        // Check ZIP signature and DOCX structure
        if (str_starts_with($content, 'PK')) {
            try {
                $zip = new \ZipArchive();
                $tempFile = tempnam(sys_get_temp_dir(), 'docx_check_');
                file_put_contents($tempFile, $content);
                
                if ($zip->open($tempFile) === true) {
                    $hasDocumentXml = $zip->locateName('word/document.xml') !== false;
                    $zip->close();
                    unlink($tempFile);
                    return $hasDocumentXml;
                }
                unlink($tempFile);
            } catch (\Exception $e) {
                // Ignore errors, not a DOCX
            }
        }

        return false;
    }

    /**
     * Get size limit for MIME type
     */
    private function getSizeLimit(string $mimeType): int
    {
        return self::SIZE_LIMITS[$mimeType] ?? self::SIZE_LIMITS['default'];
    }

    /**
     * Create sanitized error text
     */
    private function createErrorText(\Exception $e, ?string $filename, ?string $mimeType): string
    {
        $errorMessage = "Error extracting text from this file";
        
        if ($filename) {
            $errorMessage .= " ({$filename})";
        }
        
        if ($mimeType) {
            $errorMessage .= " [{$mimeType}]";
        }
        
        $errorMessage .= ": " . $e->getMessage();
        
        return $this->sanitizeText($errorMessage);
    }

    private function extractPlainText($content)
    {
        return $this->sanitizeText(trim($content));
    }

    private function extractHtmlText($content)
    {
        // Remove HTML tags and decode entities
        $text = strip_tags($content);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return $this->sanitizeText(trim($text));
    }

    private function extractCsvText($content)
    {
        // Convert CSV to readable text
        $lines = explode("\n", $content);
        $text = '';

        foreach ($lines as $line) {
            if (trim($line)) {
                $text .= $line . "\n";
            }
        }

        return $this->sanitizeText(trim($text));
    }

    private function extractPdfText($content)
    {
        Log::info('Attempting PDF text extraction', ['content_length' => strlen($content)]);

        $tempPdfPath = null;
        $extractionMethods = [];

        try {
            // Try using Spatie's pdf-to-text (best option - uses pdftotext)
            if (class_exists('\Spatie\PdfToText\Pdf')) {
                try {
                    // Create unique temp file for pdftotext
                    $tempPdfPath = sys_get_temp_dir() . '/' . uniqid('pdf_pdftotext_') . '.pdf';
                    file_put_contents($tempPdfPath, $content);

                    // Set the path to pdftotext executable
                    $pdftotextPath = 'C:\poppler\poppler-24.08.0\Library\bin\pdftotext.exe';

                    $text = \Spatie\PdfToText\Pdf::getText($tempPdfPath, $pdftotextPath);

                    // Clean up temp file immediately
                    if (file_exists($tempPdfPath)) {
                        unlink($tempPdfPath);
                        $tempPdfPath = null;
                    }

                    if (!empty(trim($text))) {
                        Log::info('PDF text extracted successfully with pdftotext', ['text_length' => strlen($text)]);
                        return $this->sanitizeText($text);
                    }
                    
                    $extractionMethods[] = 'pdftotext (empty result)';
                } catch (\Exception $e) {
                    $extractionMethods[] = 'pdftotext (failed: ' . $e->getMessage() . ')';
                    Log::warning('pdftotext extraction failed, trying fallback: ' . $e->getMessage());
                    // Clean up temp file if it exists
                    if ($tempPdfPath && file_exists($tempPdfPath)) {
                        unlink($tempPdfPath);
                        $tempPdfPath = null;
                    }
                }
            } else {
                $extractionMethods[] = 'pdftotext (not available)';
            }

            // Fallback: Try Smalot PDFParser if pdftotext isn't available or failed
            if (class_exists('\Smalot\PdfParser\Parser')) {
                try {
                    $parser = new \Smalot\PdfParser\Parser();
                    // Parse directly from content (no temp file needed - safer!)
                    $pdf = $parser->parseContent($content);
                    $text = $pdf->getText();

                    if (!empty(trim($text))) {
                        Log::info('PDF text extracted successfully with Smalot parser', ['text_length' => strlen($text)]);
                        return $this->sanitizeText($text);
                    }
                    
                    $extractionMethods[] = 'Smalot parser (empty result)';
                } catch (\Exception $e) {
                    $extractionMethods[] = 'Smalot parser (failed: ' . $e->getMessage() . ')';
                    Log::warning('Smalot parser extraction failed: ' . $e->getMessage());
                }
            } else {
                $extractionMethods[] = 'Smalot parser (not available)';
            }

            // If all methods fail, log and return structured error
            Log::error('All PDF extraction methods failed', ['methods' => $extractionMethods]);
            return $this->createErrorText(new \Exception('PDF extraction failed: ' . implode(', ', $extractionMethods)), 'PDF', 'application/pdf');

        } catch (\Exception $e) {
            Log::error('PDF extraction error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->createErrorText($e, 'PDF', 'application/pdf');
        } finally {
            // Final cleanup - ensure temp file is deleted if it exists
            if ($tempPdfPath && file_exists($tempPdfPath)) {
                try {
                    unlink($tempPdfPath);
                } catch (\Exception $e) {
                    Log::warning('Failed to cleanup temp PDF file: ' . $e->getMessage());
                }
            }
        }
    }

    private function extractDocxText($content)
    {
        Log::info('Attempting DOCX text extraction', ['content_length' => strlen($content)]);
        
        try {
            // Save to temp file
            $tmpFile = tempnam(sys_get_temp_dir(), 'docx_');
            file_put_contents($tmpFile, $content);
            Log::info('DOCX saved to temp file', ['tmpFile' => $tmpFile, 'file_exists' => file_exists($tmpFile)]);

            // Try using zip to extract text from DOCX
            $zip = new \ZipArchive();
            $openResult = $zip->open($tmpFile);
            Log::info('ZipArchive open result', ['result' => $openResult, 'is_true' => ($openResult === true)]);
            
            if ($openResult === true) {
                // DOCX files have the main content in word/document.xml
                $xml = $zip->getFromName('word/document.xml');
                Log::info('Extracted document.xml', ['xml_length' => strlen($xml ?: ''), 'has_xml' => !empty($xml)]);
                $zip->close();

                if ($xml) {
                    // Parse XML and extract text - try with error suppression
                    libxml_use_internal_errors(true);
                    $xmlObj = simplexml_load_string($xml);
                    $xmlErrors = libxml_get_errors();
                    libxml_clear_errors();
                    
                    if ($xmlObj === false) {
                        $errorMsg = !empty($xmlErrors) ? $xmlErrors[0]->message : 'Unknown error';
                        Log::warning('simplexml_load_string failed', ['error' => $errorMsg]);
                        
                        // Try alternative: use DOMDocument instead
                        try {
                            $dom = new \DOMDocument();
                            $dom->loadXML($xml);
                            $xpath = new \DOMXPath($dom);
                            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                            $textNodes = $xpath->query('//w:t');
                            
                            $text = '';
                            foreach ($textNodes as $node) {
                                $text .= $node->nodeValue . ' ';
                            }
                            
                            @unlink($tmpFile);
                            $extractedText = $this->sanitizeText(trim($text));
                            Log::info('DOCX text extracted with DOMDocument', ['text_length' => strlen($extractedText), 'node_count' => $textNodes->length]);
                            return $extractedText;
                        } catch (\Exception $e) {
                            Log::error('DOMDocument parsing also failed: ' . $e->getMessage());
                        }
                    } else {
                        // SimpleXML worked
                        $xmlObj->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                        $textNodes = $xmlObj->xpath('//w:t');
                        Log::info('Found text nodes in DOCX', ['node_count' => count($textNodes)]);

                        $text = '';
                        foreach ($textNodes as $textNode) {
                            $text .= (string)$textNode . ' ';
                        }

                        @unlink($tmpFile);
                        $extractedText = $this->sanitizeText(trim($text));
                        Log::info('DOCX text extracted successfully', ['text_length' => strlen($extractedText)]);
                        return $extractedText;
                    }
                } else {
                    Log::warning('No word/document.xml found in DOCX');
                }
            } else {
                Log::error('Failed to open DOCX as ZIP', ['error_code' => $openResult]);
            }

            @unlink($tmpFile);
            return $this->sanitizeText("Unable to extract DOCX content. File size: " . strlen($content) . " bytes");
        } catch (\Exception $e) {
            Log::error("DOCX extraction exception: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->sanitizeText("DOCX extraction error: " . $e->getMessage());
        }
    }

    private function extractDocText($content)
    {
        // For DOC extraction, you would need a specialized library
        return $this->sanitizeText("DOC content extraction not implemented yet. File size: " . strlen($content) . " bytes");
    }

    private function sanitizeText(string $text): string
    {
        // Remove null bytes and other problematic characters
        $text = str_replace("\0", '', $text);

        // Replace Windows-1252 "smart quotes" and special characters with ASCII equivalents
        $replacements = [
            "\xC2\x82" => ',',        // U+201A single low-9 quotation mark
            "\xC2\x84" => ',,',       // U+201E double low-9 quotation mark
            "\xC2\x85" => '...',      // U+2026 horizontal ellipsis
            "\xC2\x88" => '^',        // U+02C6 modifier letter circumflex
            "\xC2\x91" => "'",        // U+2018 left single quotation mark
            "\xC2\x92" => "'",        // U+2019 right single quotation mark
            "\xC2\x93" => '"',        // U+201C left double quotation mark
            "\xC2\x94" => '"',        // U+201D right double quotation mark
            "\xC2\x95" => '*',        // U+2022 bullet
            "\xC2\x96" => '-',        // U+2013 en dash
            "\xC2\x97" => '--',       // U+2014 em dash
            "\xC2\x99" => '(TM)',     // U+2122 trademark
            "\xC2\x9C" => 'oe',       // U+0153 ligature oe
            "\xC2\x9D" => '>',        // U+203A single right-pointing angle quotation
            "\xC2\x9E" => '',         // U+017E latin small letter z with caron
            "\xE2\x80\x93" => '-',    // U+2013 en dash
            "\xE2\x80\x94" => '--',   // U+2014 em dash
            "\xE2\x80\x98" => "'",    // U+2018 left single quotation mark
            "\xE2\x80\x99" => "'",    // U+2019 right single quotation mark
            "\xE2\x80\x9A" => ',',    // U+201A single low-9 quotation mark
            "\xE2\x80\x9C" => '"',    // U+201C left double quotation mark
            "\xE2\x80\x9D" => '"',    // U+201D right double quotation mark
            "\xE2\x80\x9E" => ',,',   // U+201E double low-9 quotation mark
            "\xE2\x80\xA2" => '*',    // U+2022 bullet
            "\xE2\x80\xA6" => '...',  // U+2026 horizontal ellipsis
            "\xE2\x84\xA2" => '(TM)', // U+2122 trademark
        ];
        $text = str_replace(array_keys($replacements), array_values($replacements), $text);

        // Convert to UTF-8 from any encoding
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        }

        // Remove invalid UTF-8 sequences more aggressively
        $text = iconv('UTF-8', 'UTF-8//IGNORE', $text);

        // Remove any remaining control characters except newlines, tabs, and spaces
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', $text);

        // Remove ALL characters outside basic multilingual plane (anything above 3-byte UTF-8)
        // This catches mathematical symbols, emojis, rare symbols, etc.
        $text = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);

        // Remove specific problematic Unicode ranges that cause MySQL utf8mb4 issues
        // Mathematical Alphanumeric Symbols (𝐀-𝟿), Private Use Area, etc.
        $text = preg_replace('/[\x{1D400}-\x{1D7FF}]/u', '', $text); // Math symbols
        $text = preg_replace('/[\x{E000}-\x{F8FF}]/u', '', $text);   // Private use
        $text = preg_replace('/[\x{F0000}-\x{FFFFF}]/u', '', $text); // Supplementary Private Use

        // Replace common mathematical/scientific symbols that appear in PDFs
        $mathReplacements = [
            '≤' => '<=',
            '≥' => '>=',
            '≠' => '!=',
            '±' => '+/-',
            '×' => 'x',
            '÷' => '/',
            '°' => ' degrees ',
            'λ' => 'lambda',
            'μ' => 'mu',
            'σ' => 'sigma',
            'π' => 'pi',
            'Δ' => 'Delta',
            'Σ' => 'Sigma',
            'Ω' => 'Omega',
            'α' => 'alpha',
            'β' => 'beta',
            'γ' => 'gamma',
            'θ' => 'theta',
            '→' => '->',
            '←' => '<-',
            '↑' => '^',
            '↓' => 'v',
            '∞' => 'infinity',
            '∫' => 'integral',
            '√' => 'sqrt',
            '∑' => 'sum',
            '∏' => 'product',
        ];
        $text = str_replace(array_keys($mathReplacements), array_values($mathReplacements), $text);

        // NUCLEAR OPTION: Convert ALL non-ASCII to closest ASCII equivalent or remove
        // This ensures 100% MySQL compatibility (only printable ASCII + space/newline/tab)
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        // Remove any remaining non-printable characters except newlines and tabs
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Ensure we only have ASCII characters (0x20-0x7E plus \r\n\t)
        $text = preg_replace('/[^\x20-\x7E\r\n\t]/', '', $text);

        return trim($text);
    }

    public function chunkText($text, $chunkSize = 2000, $overlap = 200)
    {
        // Text is already sanitized in extractText methods
        // No need to sanitize again here

        if (strlen($text) <= $chunkSize) {
            return [$text];
        }

        $chunks = [];
        $start = 0;
        $textLength = strlen($text);

        while ($start < $textLength) {
            $end = $start + $chunkSize;

            if ($end >= $textLength) {
                $chunks[] = substr($text, $start);
                break;
            }

            // Try to break at sentence boundary
            $chunk = substr($text, $start, $chunkSize);
            $lastSentence = strrpos($chunk, '. ');

            if ($lastSentence !== false && $lastSentence > $chunkSize * 0.7) {
                $end = $start + $lastSentence + 1;
                $chunks[] = trim(substr($text, $start, $lastSentence + 1));
            } else {
                // Fall back to word boundary
                $lastSpace = strrpos($chunk, ' ');
                if ($lastSpace !== false && $lastSpace > $chunkSize * 0.7) {
                    $end = $start + $lastSpace;
                    $chunks[] = trim(substr($text, $start, $lastSpace));
                } else {
                    $chunks[] = trim($chunk);
                }
            }

            $start = $end - $overlap;
            if ($start < 0) $start = 0;
        }

        return array_filter($chunks, function ($chunk) {
            return !empty(trim($chunk));
        });
    }
}
