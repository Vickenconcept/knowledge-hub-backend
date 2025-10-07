<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DocumentExtractionService
{
    public function extractText($content, $mimeType, $filename = null)
    {
        try {
            return match (true) {
                str_contains($mimeType, 'text/plain') => $this->extractPlainText($content),
                str_contains($mimeType, 'text/html') => $this->extractHtmlText($content),
                str_contains($mimeType, 'text/csv') => $this->extractCsvText($content),
                str_contains($mimeType, 'application/pdf') => $this->extractPdfText($content),
                str_contains($mimeType, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') => $this->extractDocxText($content),
                str_contains($mimeType, 'application/msword') => $this->extractDocText($content),
                str_contains($mimeType, 'application/vnd.google-apps.') => $content, // Already extracted as text
                default => $this->extractPlainText($content)
            };
        } catch (\Exception $e) {
            Log::error("Error extracting text from {$filename} (type: {$mimeType}): " . $e->getMessage());
            return "Error extracting text from this file: " . $e->getMessage();
        }
    }

    private function extractPlainText($content)
    {
        return trim($content);
    }

    private function extractHtmlText($content)
    {
        // Remove HTML tags and decode entities
        $text = strip_tags($content);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return trim($text);
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
        
        return trim($text);
    }

    private function extractPdfText($content)
    {
        \Log::info('Attempting PDF text extraction', ['content_length' => strlen($content)]);
        
        try {
            // Save content to temporary file (required by pdftotext)
            $tempPdfPath = sys_get_temp_dir() . '/' . uniqid('pdf_') . '.pdf';
            file_put_contents($tempPdfPath, $content);
            
            // Try using Spatie's pdf-to-text (best option - uses pdftotext)
            if (class_exists('\Spatie\PdfToText\Pdf')) {
                try {
                    $text = \Spatie\PdfToText\Pdf::getText($tempPdfPath);
                    unlink($tempPdfPath);
                    
                    if (!empty(trim($text))) {
                        \Log::info('PDF text extracted successfully with pdftotext', ['text_length' => strlen($text)]);
                        return trim($text);
                    }
                } catch (\Exception $e) {
                    \Log::warning('pdftotext extraction failed, trying fallback: ' . $e->getMessage());
                }
            }
            
            // Fallback: Try Smalot PDFParser if pdftotext isn't available
            if (class_exists('\Smalot\PdfParser\Parser')) {
                try {
                    $parser = new \Smalot\PdfParser\Parser();
                    $pdf = $parser->parseContent($content);
                    $text = $pdf->getText();
                    unlink($tempPdfPath);
                    
                    if (!empty(trim($text))) {
                        \Log::info('PDF text extracted successfully with Smalot parser', ['text_length' => strlen($text)]);
                        // Clean up problematic characters
                        $text = $this->sanitizeText($text);
                        return trim($text);
                    }
                } catch (\Exception $e) {
                    \Log::warning('Smalot parser extraction failed: ' . $e->getMessage());
                }
            }
            
            // Clean up temp file if still exists
            if (file_exists($tempPdfPath)) {
                unlink($tempPdfPath);
            }
            
            // If all methods fail, log and return empty
            \Log::error('All PDF extraction methods failed');
            return '';
            
        } catch (\Exception $e) {
            \Log::error('PDF extraction error: ' . $e->getMessage());
            return '';
        }
    }

    private function extractDocxText($content)
    {
        // For DOCX extraction, you would typically use PhpOffice\PhpWord
        // For now, return a placeholder
        return "DOCX content extraction not implemented yet. File size: " . strlen($content) . " bytes";
    }

    private function extractDocText($content)
    {
        // For DOC extraction, you would need a specialized library
        return "DOC content extraction not implemented yet. File size: " . strlen($content) . " bytes";
    }

    private function sanitizeText(string $text): string
    {
        // Remove null bytes and other problematic characters
        $text = str_replace("\0", '', $text);
        
        // Fix UTF-8 encoding issues
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        
        // Remove invalid UTF-8 sequences
        $text = iconv('UTF-8', 'UTF-8//IGNORE', $text);
        
        // Remove 4-byte UTF-8 characters (emojis, special symbols) that might cause issues
        $text = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);
        
        return $text;
    }

    public function chunkText($text, $chunkSize = 2000, $overlap = 200)
    {
        // Sanitize text before chunking
        $text = $this->sanitizeText($text);
        
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

        return array_filter($chunks, function($chunk) {
            return !empty(trim($chunk));
        });
    }
}