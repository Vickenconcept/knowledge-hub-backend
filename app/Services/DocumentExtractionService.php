<?php

namespace App\Services;

class DocumentExtractionService
{
    /**
     * Very basic extraction stub. For PDFs/Docx integrate a real parser later.
     */
    public function extractText(string $localPath, ?string $mimeType = null): string
    {
        if ($mimeType === 'text/plain' || (is_readable($localPath) && preg_match('/\.txt$/i', $localPath))) {
            return (string) file_get_contents($localPath);
        }
        // DOCX
        if ($mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || preg_match('/\.docx$/i', $localPath)) {
            $text = $this->extractFromDocx($localPath);
            return $text ?? '';
        }
        // PDF
        if ($mimeType === 'application/pdf' || preg_match('/\.pdf$/i', $localPath)) {
            $text = $this->extractFromPdf($localPath);
            return $text ?? '';
        }
        // Fallback: unknown types return empty for now
        return '';
    }

    private function extractFromDocx(string $localPath): ?string
    {
        if (!is_readable($localPath)) return null;
        $zip = new \ZipArchive();
        if ($zip->open($localPath) === true) {
            $index = $zip->locateName('word/document.xml');
            if ($index !== false) {
                $xml = $zip->getFromIndex($index);
                $zip->close();
                if ($xml !== false) {
                    // Replace paragraph and break tags with newlines, strip remaining tags
                    $xml = preg_replace('/<w:p[\s\S]*?>/i', "\n", $xml);
                    $xml = preg_replace('/<w:br\s*\/>/i', "\n", $xml);
                    $text = strip_tags($xml);
                    // Normalize whitespace
                    $text = preg_replace("/\r\n|\r|\n/", "\n", $text);
                    $text = preg_replace('/\n{3,}/', "\n\n", $text);
                    return trim($text);
                }
            } else {
                $zip->close();
            }
        }
        return null;
    }

    private function extractFromPdf(string $localPath): ?string
    {
        if (!is_readable($localPath)) return null;
        // If Smalot\PdfParser is installed, use it
        if (class_exists('Smalot\\PdfParser\\Parser')) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($localPath);
                return trim($pdf->getText());
            } catch (\Throwable $e) {
                return null;
            }
        }
        // Try system pdftotext if available
        $tmpTxt = $localPath . '.txt';
        $cmd = 'pdftotext ' . escapeshellarg($localPath) . ' ' . escapeshellarg($tmpTxt);
        @exec($cmd, $out, $code);
        if (is_readable($tmpTxt)) {
            $text = (string) @file_get_contents($tmpTxt);
            @unlink($tmpTxt);
            if (!empty($text)) return trim($text);
        }
        return null;
    }
}


